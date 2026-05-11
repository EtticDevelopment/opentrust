<?php
/**
 * "Questions" admin screen — visitor chat log viewer + the three
 * admin-post handlers that drive its toolbar (CSV export, full clear,
 * logging toggle).
 *
 * Lives on its own submenu under the OpenTrust top-level menu. Visibility
 * of that submenu is gated in OpenTrust_Admin::register_menu() on the
 * `ai_enabled` setting. Once the submenu is registered, this class owns
 * the page render and all handler endpoints.
 *
 * Identifiers in the underlying log table are pre-hashed by
 * OpenTrust_Chat_Log; nothing in this screen surfaces raw IPs/sessions.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Admin_Questions {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('admin_post_opentrust_ai_questions_export', [$this, 'handle_export']);
        add_action('admin_post_opentrust_ai_questions_clear',  [$this, 'handle_clear']);
        add_action('admin_post_opentrust_ai_toggle_logging',   [$this, 'handle_toggle_logging']);
    }

    // ──────────────────────────────────────────────
    // Page render
    // ──────────────────────────────────────────────

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = OpenTrust::get_settings();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter params on admin display page.
        $filters = [
            'search'    => isset($_GET['search'])    ? sanitize_text_field((string) wp_unslash($_GET['search']))    : '',
            'model'     => isset($_GET['model'])     ? sanitize_text_field((string) wp_unslash($_GET['model']))     : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field((string) wp_unslash($_GET['date_from'])) : '',
            'date_to'   => isset($_GET['date_to'])   ? sanitize_text_field((string) wp_unslash($_GET['date_to']))   : '',
            'page'      => isset($_GET['paged'])     ? max(1, (int) $_GET['paged'])                                 : 1,
            'per_page'  => 25,
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $result   = OpenTrust_Chat_Log::query($filters);
        $total    = $result['total'];
        $rows     = $result['rows'];
        $pages    = max(1, (int) ceil($total / $filters['per_page']));
        $models   = OpenTrust_Chat_Log::distinct_models();
        $counts   = OpenTrust_Chat_Log::total_count();
        $tc_url   = home_url('/' . ($settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG) . '/');
        $back_url = admin_url('admin.php?page=opentrust&tab=ai');

        $log_filter_params = array_filter([
            'search'    => $filters['search'],
            'model'     => $filters['model'],
            'date_from' => $filters['date_from'],
            'date_to'   => $filters['date_to'],
        ]);
        $export_url = wp_nonce_url(
            add_query_arg(
                ['action' => 'opentrust_ai_questions_export'] + $log_filter_params,
                admin_url('admin-post.php')
            ),
            'opentrust_ai_questions_export'
        );
        $clear_url  = wp_nonce_url(
            admin_url('admin-post.php?action=opentrust_ai_questions_clear'),
            'opentrust_ai_questions_clear'
        );
        $toggle_url = wp_nonce_url(
            admin_url('admin-post.php?action=opentrust_ai_toggle_logging'),
            'opentrust_ai_toggle_logging'
        );

        $logging_on = !empty($settings['ai_logging_enabled']);

        ?>
        <div class="wrap opentrust-admin">

            <div class="opentrust-topbar__bar" role="banner">
                <div class="opentrust-topbar__brand">
                    <svg class="opentrust-topbar__mark" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="OpenTrust">
                        <rect width="26" height="26" rx="6" fill="#0F5CFA"/>
                        <path transform="translate(4 4) scale(0.75)" d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-1 16l-4-4 1.41-1.41L11 14.17l6.59-6.59L19 9l-8 8z" fill="white"/>
                    </svg>
                    <span class="opentrust-topbar__name"><?php esc_html_e('OpenTrust', 'opentrust'); ?></span>
                    <span class="opentrust-topbar__version">v<?php echo esc_html(OPENTRUST_VERSION); ?></span>
                </div>
                <div class="opentrust-topbar__right">
                    <div class="opentrust-topbar__actions">
                        <a href="<?php echo esc_url($back_url); ?>" class="opentrust-btn opentrust-btn--ghost-dark opentrust-btn--sm">
                            &larr; <?php esc_html_e('AI settings', 'opentrust'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <div class="opentrust-topbar__head">
                <div class="opentrust-topbar__head-text">
                    <h1><?php esc_html_e('AI Questions', 'opentrust'); ?></h1>
                    <p><?php esc_html_e('Questions visitors have asked your trust center chat. Identifiers are hashed; rows auto-purge after 90 days.', 'opentrust'); ?></p>
                </div>
                <a href="<?php echo esc_url($tc_url); ?>" target="_blank" class="opentrust-btn opentrust-btn--ghost-dark opentrust-btn--sm opentrust-topbar__head-action">
                    <?php esc_html_e('View Trust Center', 'opentrust'); ?> &rarr;
                </a>
            </div>

            <div class="opentrust-stack">

                <?php
                $notice = get_transient('opentrust_ai_notice_' . get_current_user_id());
                if (is_array($notice)) {
                    delete_transient('opentrust_ai_notice_' . get_current_user_id());
                    $variant = $notice['type'] === 'error' ? 'error' : 'success';
                    printf(
                        '<div class="opentrust-notice opentrust-notice--%s" role="status"><div class="opentrust-notice__body"><p>%s</p></div></div>',
                        esc_attr($variant),
                        esc_html((string) ($notice['message'] ?? ''))
                    );
                }
                ?>

                <div class="opentrust-notice opentrust-notice--<?php echo $logging_on ? 'success' : 'warn'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded ?>">
                    <div class="opentrust-notice__body">
                        <strong>
                            <?php echo $logging_on
                                ? esc_html__('Logging is ON', 'opentrust')
                                : esc_html__('Logging is OFF', 'opentrust');
                            ?>
                        </strong>
                        <p>
                            <?php
                            /* translators: %d: number of questions */
                            printf(esc_html(_n('%d question logged in the last 90 days.', '%d questions logged in the last 90 days.', (int) $counts, 'opentrust')), (int) $counts);
                            ?>
                        </p>
                        <div class="opentrust-notice__actions">
                            <a href="<?php echo esc_url($toggle_url); ?>" class="opentrust-btn opentrust-btn--ghost opentrust-btn--sm"
                               onclick="return confirm('<?php echo esc_js(__('Toggle visitor question logging?', 'opentrust')); ?>')">
                                <?php echo $logging_on ? esc_html__('Disable logging', 'opentrust') : esc_html__('Enable logging', 'opentrust'); ?>
                            </a>
                        </div>
                    </div>
                </div>

                <section class="opentrust-block">
                    <header class="opentrust-block__head">
                        <h2><?php esc_html_e('Filter &amp; export', 'opentrust'); ?></h2>
                    </header>
                    <div class="opentrust-card">
                        <form method="get" action="" class="opentrust-filterbar">
                            <input type="hidden" name="page" value="opentrust-questions">
                            <div class="opentrust-filterbar__field">
                                <label for="opentrust-q-search"><?php esc_html_e('Search', 'opentrust'); ?></label>
                                <input type="text" id="opentrust-q-search" name="search" value="<?php echo esc_attr($filters['search']); ?>" class="opentrust-input opentrust-input--md" placeholder="<?php esc_attr_e('Search questions…', 'opentrust'); ?>">
                            </div>
                            <div class="opentrust-filterbar__field">
                                <label for="opentrust-q-model"><?php esc_html_e('Model', 'opentrust'); ?></label>
                                <div class="opentrust-select">
                                    <select id="opentrust-q-model" name="model">
                                        <option value=""><?php esc_html_e('Any', 'opentrust'); ?></option>
                                        <?php foreach ($models as $m): ?>
                                            <option value="<?php echo esc_attr($m); ?>" <?php selected($filters['model'], $m); ?>><?php echo esc_html($m); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="opentrust-filterbar__field">
                                <label for="opentrust-q-from"><?php esc_html_e('From', 'opentrust'); ?></label>
                                <input type="date" id="opentrust-q-from" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" class="opentrust-input">
                            </div>
                            <div class="opentrust-filterbar__field">
                                <label for="opentrust-q-to"><?php esc_html_e('To', 'opentrust'); ?></label>
                                <input type="date" id="opentrust-q-to" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" class="opentrust-input">
                            </div>
                            <div class="opentrust-filterbar__actions">
                                <button type="submit" class="opentrust-btn opentrust-btn--primary opentrust-btn--sm"><?php esc_html_e('Apply', 'opentrust'); ?></button>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=opentrust-questions')); ?>" class="opentrust-btn opentrust-btn--ghost opentrust-btn--sm"><?php esc_html_e('Reset', 'opentrust'); ?></a>
                                <a href="<?php echo esc_url($export_url); ?>" class="opentrust-btn opentrust-btn--ghost opentrust-btn--sm opentrust-filterbar__export"><?php esc_html_e('Download CSV', 'opentrust'); ?></a>
                            </div>
                        </form>
                    </div>
                </section>

                <section class="opentrust-block">
                    <header class="opentrust-block__head">
                        <h2><?php esc_html_e('Log', 'opentrust'); ?></h2>
                        <p>
                            <?php
                            /* translators: %d: total rows */
                            printf(esc_html__('%d total', 'opentrust'), (int) $total);
                            ?>
                            <?php if ($total > 0): ?>
                                · <?php esc_html_e('most recent first; refused answers are highlighted', 'opentrust'); ?>
                            <?php endif; ?>
                        </p>
                    </header>
                    <div class="opentrust-card opentrust-card--flush">
                        <table class="opentrust-log-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="opentrust-log-table__date"><?php esc_html_e('Date', 'opentrust'); ?></th>
                                    <th scope="col"><?php esc_html_e('Question', 'opentrust'); ?></th>
                                    <th scope="col"><?php esc_html_e('Model', 'opentrust'); ?></th>
                                    <th scope="col" class="opentrust-log-table__num"><?php esc_html_e('Cites', 'opentrust'); ?></th>
                                    <th scope="col"><?php esc_html_e('Tokens', 'opentrust'); ?></th>
                                    <th scope="col"><?php esc_html_e('Latency', 'opentrust'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr class="opentrust-log-table__empty">
                                        <td colspan="6"><?php esc_html_e('No questions logged yet.', 'opentrust'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr class="<?php echo $row->refused ? 'is-refused' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded ?>">
                                            <td class="opentrust-log-table__date"><?php echo esc_html(wp_date('M j, Y H:i', strtotime($row->created_at . ' UTC'))); ?></td>
                                            <td>
                                                <?php if ($row->refused): ?>
                                                    <span class="opentrust-log-table__refused"><?php esc_html_e('Refused', 'opentrust'); ?></span>
                                                <?php endif; ?>
                                                <?php echo esc_html($row->question); ?>
                                            </td>
                                            <td><code class="opentrust-log-table__model"><?php echo esc_html($row->model); ?></code></td>
                                            <td class="opentrust-log-table__num"><?php echo (int) $row->citation_count; ?></td>
                                            <td class="opentrust-log-table__meta">&darr;<?php echo (int) $row->tokens_in; ?> / &uarr;<?php echo (int) $row->tokens_out; ?></td>
                                            <td class="opentrust-log-table__meta"><?php echo (int) $row->response_ms; ?>ms</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if ($pages > 1):
                            $base = add_query_arg(
                                ['page' => 'opentrust-questions'] + $log_filter_params,
                                admin_url('admin.php')
                            );
                            ?>
                            <div class="opentrust-log-table__pagination">
                                <?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links returns safe HTML
                                echo paginate_links([
                                    'base'      => add_query_arg('paged', '%#%', $base),
                                    'format'    => '',
                                    'current'   => $filters['page'],
                                    'total'     => $pages,
                                    'prev_text' => '&lsaquo;',
                                    'next_text' => '&rsaquo;',
                                ]);
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="opentrust-block">
                    <header class="opentrust-block__head">
                        <h2><?php esc_html_e('Danger zone', 'opentrust'); ?></h2>
                    </header>
                    <div class="opentrust-card">
                        <div class="opentrust-action-row">
                            <div class="opentrust-action-row__main">
                                <h3><?php esc_html_e('Clear entire question log', 'opentrust'); ?></h3>
                                <p><?php esc_html_e('Permanently deletes every logged question. Cannot be undone.', 'opentrust'); ?></p>
                            </div>
                            <a href="<?php echo esc_url($clear_url); ?>" class="opentrust-btn opentrust-btn--danger opentrust-btn--sm"
                               onclick="return confirm('<?php echo esc_js(__('Permanently delete all logged questions? This cannot be undone.', 'opentrust')); ?>')">
                                <?php esc_html_e('Clear log', 'opentrust'); ?>
                            </a>
                        </div>
                    </div>
                </section>
            </div>

            <?php \OpenTrust\Admin\Footer::render(); ?>
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    // admin-post handlers
    // ──────────────────────────────────────────────

    public function handle_export(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_questions_export');

        $filters = [
            'search'    => isset($_GET['search'])    ? sanitize_text_field((string) wp_unslash($_GET['search']))    : '',
            'model'     => isset($_GET['model'])     ? sanitize_text_field((string) wp_unslash($_GET['model']))     : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field((string) wp_unslash($_GET['date_from'])) : '',
            'date_to'   => isset($_GET['date_to'])   ? sanitize_text_field((string) wp_unslash($_GET['date_to']))   : '',
            'page'      => 1,
            'per_page'  => 10000, // hard cap — nobody exports >10k rows per page
        ];

        $result = OpenTrust_Chat_Log::query($filters);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=opentrust-questions-' . gmdate('Y-m-d') . '.csv');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing to php://output, not filesystem
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date (UTC)', 'Question', 'Model', 'Provider', 'Citations', 'Tokens In', 'Tokens Out', 'Response ms', 'Refused']);
        foreach ($result['rows'] as $row) {
            fputcsv($out, [
                $row->created_at,
                $row->question,
                $row->model,
                $row->provider,
                $row->citation_count,
                $row->tokens_in,
                $row->tokens_out,
                $row->response_ms,
                $row->refused ? 'yes' : 'no',
            ]);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Writing to php://output stream
        fclose($out);
        exit;
    }

    public function handle_clear(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_questions_clear');

        OpenTrust_Chat_Log::clear_all();

        set_transient(
            'opentrust_ai_notice_' . get_current_user_id(),
            ['type' => 'success', 'message' => __('Question log cleared.', 'opentrust')],
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect(admin_url('admin.php?page=opentrust-questions'));
        exit;
    }

    public function handle_toggle_logging(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_toggle_logging');

        $settings = OpenTrust::get_settings();
        $settings['ai_logging_enabled'] = empty($settings['ai_logging_enabled']);
        OpenTrust_Admin_Settings::instance()->save_settings_raw($settings);

        set_transient(
            'opentrust_ai_notice_' . get_current_user_id(),
            ['type' => 'success', 'message' => $settings['ai_logging_enabled'] ? __('Logging enabled.', 'opentrust') : __('Logging disabled.', 'opentrust')],
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect(admin_url('admin.php?page=opentrust-questions'));
        exit;
    }
}
