<?php
/**
 * Import & Export — rendered as a tab inside the OpenTrust settings page.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Admin_Tools {

    public const TAB_SLUG       = 'io';
    public const UPLOAD_MAX_MB  = 50;

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('admin_post_opentrust_export',         [$this, 'handle_export']);
        add_action('admin_post_opentrust_import_preview', [$this, 'handle_import_preview']);
        add_action('admin_post_opentrust_import_apply',   [$this, 'handle_import_apply']);
    }

    // ──────────────────────────────────────────────
    // Tab render — called from OpenTrust_Admin_Settings::render_settings_page
    // ──────────────────────────────────────────────

    public function render_tab(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $stash_key = 'opentrust_io_preview_' . get_current_user_id();
        $preview = get_transient($stash_key);

        $notice = get_transient('opentrust_io_notice_' . get_current_user_id());
        if (is_array($notice)) {
            delete_transient('opentrust_io_notice_' . get_current_user_id());
            $variant = $notice['type'] === 'error' ? 'error' : 'success';
            printf(
                '<div class="opentrust-notice opentrust-notice--%s" role="status"><div class="opentrust-notice__body"><p>%s</p></div></div>',
                esc_attr($variant),
                wp_kses_post((string) ($notice['message'] ?? ''))
            );
        }

        $exportable = OpenTrust_IO::exportable_summary();
        ?>
        <section class="opentrust-block">
            <header class="opentrust-block__head">
                <h2><?php esc_html_e('Import &amp; Export', 'opentrust'); ?></h2>
                <p><?php esc_html_e('Move trust center content and settings between sites, or seed a fresh install from another. API keys and the Turnstile secret are never included — re-enter them on the destination.', 'opentrust'); ?></p>
            </header>
        </section>

        <?php if ($preview && is_array($preview)): ?>
            <?php $this->render_preview_screen($preview); ?>
        <?php else: ?>
            <?php $this->render_export_panel($exportable); ?>
            <?php $this->render_import_panel(); ?>
        <?php endif; ?>
        <?php
    }

    private function render_export_panel(array $exportable): void {
        $action_url = admin_url('admin-post.php');
        ?>
        <section class="opentrust-block">
            <header class="opentrust-block__head">
                <h2><?php esc_html_e('Export', 'opentrust'); ?></h2>
                <p><?php esc_html_e('Download a portable archive of your trust center. Use it to seed another site, archive a snapshot, or migrate between installs.', 'opentrust'); ?></p>
            </header>
            <div class="opentrust-card">
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <input type="hidden" name="action" value="opentrust_export">
                    <?php wp_nonce_field('opentrust_export'); ?>

                    <div class="opentrust-row">
                        <div class="opentrust-row__main">
                            <span class="opentrust-row__label"><?php esc_html_e('What to export', 'opentrust'); ?></span>
                            <p class="opentrust-row__help"><?php esc_html_e('Settings include theme/branding/AI/contact options. Content is the five OpenTrust CPTs with their bundled PDFs and images.', 'opentrust'); ?></p>
                        </div>
                        <div class="opentrust-row__control">
                            <div class="opentrust-seg" role="radiogroup" aria-label="<?php esc_attr_e('What to export', 'opentrust'); ?>">
                                <label class="opentrust-seg__btn">
                                    <input class="opentrust-seg__input" type="radio" name="ot_export_kind" value="content" checked>
                                    <?php esc_html_e('Content', 'opentrust'); ?>
                                </label>
                                <label class="opentrust-seg__btn">
                                    <input class="opentrust-seg__input" type="radio" name="ot_export_kind" value="settings">
                                    <?php esc_html_e('Settings only', 'opentrust'); ?>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="opentrust-row opentrust-row--stacked" id="ot-export-content-options">
                        <div class="opentrust-row__main">
                            <span class="opentrust-row__label"><?php esc_html_e('Content selection', 'opentrust'); ?></span>
                            <p class="opentrust-row__help"><?php esc_html_e('Everything is selected by default. Expand a group to fine-tune.', 'opentrust'); ?></p>
                        </div>
                        <div class="opentrust-row__control opentrust-row__control--stack">
                            <div class="opentrust-io-cpt-list">
                                <?php foreach ($exportable as $cpt => $items):
                                    $label = $this->cpt_label($cpt);
                                    $count = count($items);
                                ?>
                                    <details class="opentrust-io-cpt">
                                        <summary>
                                            <label class="opentrust-io-cpt__head" onclick="event.stopPropagation();">
                                                <input type="checkbox" name="ot_export_cpt[<?php echo esc_attr($cpt); ?>]" value="all" checked>
                                                <span class="opentrust-io-cpt__name"><?php echo esc_html($label); ?></span>
                                                <span class="opentrust-io-cpt__count">(<?php echo (int) $count; ?>)</span>
                                            </label>
                                        </summary>
                                        <ul class="opentrust-io-cpt__items">
                                            <?php foreach ($items as $item): ?>
                                                <li>
                                                    <label>
                                                        <input type="checkbox" name="ot_export_ids[<?php echo esc_attr($cpt); ?>][]" value="<?php echo (int) $item['id']; ?>" checked>
                                                        <span><?php echo esc_html($item['title']); ?></span>
                                                        <?php if ($item['status'] !== 'publish'): ?>
                                                            <em class="opentrust-io-cpt__status">(<?php echo esc_html($item['status']); ?>)</em>
                                                        <?php endif; ?>
                                                    </label>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="opentrust-row">
                        <div class="opentrust-row__main">
                            <span class="opentrust-row__label"><?php esc_html_e('Bundle media', 'opentrust'); ?></span>
                            <p class="opentrust-row__help"><?php esc_html_e('Include attached PDFs and images in the archive. Without this, the export references attachment IDs that must already exist on the destination.', 'opentrust'); ?></p>
                        </div>
                        <div class="opentrust-row__control">
                            <input type="hidden" name="ot_include_media" value="0">
                            <label class="opentrust-toggle">
                                <input class="opentrust-toggle__input" type="checkbox" name="ot_include_media" value="1" checked>
                                <span class="opentrust-toggle__thumb"></span>
                            </label>
                        </div>
                    </div>

                    <div class="opentrust-action-row">
                        <div class="opentrust-action-row__main">
                            <h3><?php esc_html_e('Download archive', 'opentrust'); ?></h3>
                            <p><?php esc_html_e('Generates a ZIP with manifest.json plus any bundled media. Encrypted secrets and the per-site salt are excluded.', 'opentrust'); ?></p>
                        </div>
                        <button type="submit" class="opentrust-btn opentrust-btn--primary"><?php esc_html_e('Download export', 'opentrust'); ?></button>
                    </div>
                </form>
            </div>
        </section>
        <?php
    }

    private function render_import_panel(): void {
        $action_url = admin_url('admin-post.php');
        ?>
        <section class="opentrust-block">
            <header class="opentrust-block__head">
                <h2><?php esc_html_e('Import', 'opentrust'); ?></h2>
                <p><?php esc_html_e('Upload a previously exported archive. You will see a preview of every record before anything is written.', 'opentrust'); ?></p>
            </header>

            <div class="opentrust-notice opentrust-notice--warn" role="note">
                <div class="opentrust-notice__body">
                    <strong><?php esc_html_e('Only upload your own exports.', 'opentrust'); ?></strong>
                    <p><?php esc_html_e('Export files contain your trust-center content and may include sensitive material. Never import a file you received from someone else.', 'opentrust'); ?></p>
                </div>
            </div>

            <div class="opentrust-card">
                <form method="post" action="<?php echo esc_url($action_url); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="opentrust_import_preview">
                    <?php wp_nonce_field('opentrust_import_preview'); ?>

                    <div class="opentrust-row opentrust-row--stacked">
                        <div class="opentrust-row__main">
                            <span class="opentrust-row__label"><?php esc_html_e('Export file', 'opentrust'); ?></span>
                            <p class="opentrust-row__help">
                                <?php
                                /* translators: %d: max upload size in MB */
                                printf(esc_html__('ZIP archive produced by another OpenTrust install. Max %d MB.', 'opentrust'), (int) self::UPLOAD_MAX_MB);
                                ?>
                            </p>
                        </div>
                        <div class="opentrust-row__control opentrust-row__control--stack">
                            <input type="file" id="ot_import_file" name="ot_import_file" accept=".zip" required class="opentrust-input opentrust-input--file">
                        </div>
                    </div>

                    <div class="opentrust-row">
                        <div class="opentrust-row__main">
                            <span class="opentrust-row__label"><?php esc_html_e('On conflict', 'opentrust'); ?></span>
                            <p class="opentrust-row__help"><?php esc_html_e('How to handle records whose UUID or slug already exists locally.', 'opentrust'); ?></p>
                        </div>
                        <div class="opentrust-row__control">
                            <div class="opentrust-seg" role="radiogroup" aria-label="<?php esc_attr_e('Conflict strategy', 'opentrust'); ?>">
                                <label class="opentrust-seg__btn">
                                    <input class="opentrust-seg__input" type="radio" name="ot_strategy" value="skip" checked>
                                    <?php esc_html_e('Skip', 'opentrust'); ?>
                                </label>
                                <label class="opentrust-seg__btn">
                                    <input class="opentrust-seg__input" type="radio" name="ot_strategy" value="overwrite">
                                    <?php esc_html_e('Overwrite', 'opentrust'); ?>
                                </label>
                                <label class="opentrust-seg__btn">
                                    <input class="opentrust-seg__input" type="radio" name="ot_strategy" value="create_new">
                                    <?php esc_html_e('Create new', 'opentrust'); ?>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="opentrust-action-row">
                        <div class="opentrust-action-row__main">
                            <h3><?php esc_html_e('Preview import', 'opentrust'); ?></h3>
                            <p><?php esc_html_e('Inspect what will be created, updated, or skipped before applying changes.', 'opentrust'); ?></p>
                        </div>
                        <button type="submit" class="opentrust-btn opentrust-btn--primary"><?php esc_html_e('Preview import', 'opentrust'); ?></button>
                    </div>
                </form>
            </div>
        </section>
        <?php
    }

    private function render_preview_screen(array $preview): void {
        $action_url = admin_url('admin-post.php');
        $manifest_format = (string) ($preview['manifest']['format'] ?? '');
        $is_settings = $manifest_format === OpenTrust_IO::FORMAT_SETTINGS;
        $totals = ['create' => 0, 'update' => 0, 'skip' => 0];
        foreach ($preview['summary'] ?? [] as $row) {
            foreach ($totals as $k => $_) {
                $totals[$k] += (int) ($row[$k] ?? 0);
            }
        }
        $subtitle = $is_settings
            ? __('Settings export — current values will be merged with imported values. Excluded keys (encrypted secrets, salt, server-controlled flags) are kept as-is.', 'opentrust')
            : sprintf(
                /* translators: %1$d: create count, %2$d: update count, %3$d: skip count */
                __('Will create %1$d, update %2$d, skip %3$d.', 'opentrust'),
                (int) $totals['create'],
                (int) $totals['update'],
                (int) $totals['skip']
            );
        ?>
        <?php if (!empty($preview['errors'])): ?>
            <div class="opentrust-notice opentrust-notice--error" role="alert">
                <div class="opentrust-notice__body">
                    <strong><?php esc_html_e('Import blocked', 'opentrust'); ?></strong>
                    <ul class="opentrust-notice__list">
                        <?php foreach ($preview['errors'] as $err): ?>
                            <li><?php echo esc_html((string) $err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($preview['warnings'])): ?>
            <div class="opentrust-notice opentrust-notice--warn" role="status">
                <div class="opentrust-notice__body">
                    <strong><?php esc_html_e('Warnings', 'opentrust'); ?></strong>
                    <ul class="opentrust-notice__list">
                        <?php foreach ($preview['warnings'] as $warn): ?>
                            <li><?php echo esc_html((string) $warn); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <section class="opentrust-block">
            <header class="opentrust-block__head">
                <h2><?php esc_html_e('Import preview', 'opentrust'); ?></h2>
                <p><?php echo esc_html($subtitle); ?></p>
            </header>

            <?php if (!$is_settings): ?>
                <?php foreach ($preview['records'] ?? [] as $cpt => $rows): ?>
                    <?php if (empty($rows)) continue; ?>
                    <div class="opentrust-card opentrust-card--flush">
                        <div class="opentrust-card__header">
                            <h3 class="opentrust-card__title"><?php echo esc_html($this->cpt_label($cpt)); ?></h3>
                        </div>
                        <table class="opentrust-log-table opentrust-io-preview-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Title', 'opentrust'); ?></th>
                                    <th scope="col" class="opentrust-io-preview-table__action"><?php esc_html_e('Action', 'opentrust'); ?></th>
                                    <th scope="col" class="opentrust-io-preview-table__uuid"><?php esc_html_e('UUID', 'opentrust'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?php echo esc_html((string) $r['title']); ?></td>
                                        <td><span class="opentrust-io-preview-table__pill opentrust-io-preview-table__pill--<?php echo esc_attr((string) $r['action']); ?>"><?php echo esc_html(ucfirst((string) $r['action'])); ?></span></td>
                                        <td class="opentrust-io-preview-table__uuid"><code><?php echo esc_html((string) ($r['uuid'] ?? '—')); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="opentrust-card">
                <form method="post" action="<?php echo esc_url($action_url); ?>" class="opentrust-io-confirm">
                    <input type="hidden" name="action" value="opentrust_import_apply">
                    <?php wp_nonce_field('opentrust_import_apply'); ?>
                    <button type="submit" name="ot_cancel" value="1" class="opentrust-btn opentrust-btn--ghost"><?php esc_html_e('Cancel', 'opentrust'); ?></button>
                    <?php if (empty($preview['errors'])): ?>
                        <button type="submit" class="opentrust-btn opentrust-btn--primary"><?php esc_html_e('Confirm and import', 'opentrust'); ?></button>
                    <?php endif; ?>
                </form>
            </div>
        </section>
        <?php
    }

    // ──────────────────────────────────────────────
    // Handlers
    // ──────────────────────────────────────────────

    public function handle_export(): void {
        $this->guard('opentrust_export');

        $kind = isset($_POST['ot_export_kind']) ? sanitize_key((string) wp_unslash($_POST['ot_export_kind'])) : 'content';
        $include_media = !empty($_POST['ot_include_media']);

        try {
            if ($kind === 'settings') {
                $manifest = OpenTrust_IO::build_settings_manifest($include_media);
                $prefix = 'opentrust-settings';
            } else {
                $selection = $this->parse_selection($_POST);
                if (empty($selection)) {
                    $this->bounce_error(__('Pick at least one record to export.', 'opentrust'));
                    return;
                }
                $manifest = OpenTrust_IO::build_content_manifest($selection, $include_media);
                $prefix = 'opentrust-content';
            }

            $zip_path = OpenTrust_IO::write_zip($manifest, $prefix);
        } catch (\Throwable $e) {
            $this->bounce_error($e->getMessage());
            return;
        }

        $name = $prefix . '-' . gmdate('Y-m-d-His') . '.zip';

        // Sensitive content; do not let proxies cache it.
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($zip_path));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming download. WP_Filesystem has no streaming-to-output equivalent; file_get_contents would buffer the whole archive in memory.
        readfile($zip_path);
        wp_delete_file($zip_path);
        exit;
    }

    public function handle_import_preview(): void {
        $this->guard('opentrust_import_preview');

        if (empty($_FILES['ot_import_file']['tmp_name']) || !is_uploaded_file((string) $_FILES['ot_import_file']['tmp_name'])) {
            $this->bounce_error(__('No file uploaded.', 'opentrust'));
            return;
        }

        $size = (int) ($_FILES['ot_import_file']['size'] ?? 0);
        if ($size > self::UPLOAD_MAX_MB * 1024 * 1024) {
            $this->bounce_error(__('Upload exceeds size limit.', 'opentrust'));
            return;
        }

        // Park the upload between preview and apply. Path goes in a per-user transient.
        $upload_dir = wp_upload_dir();
        $stash_dir  = rtrim((string) $upload_dir['basedir'], '/') . '/opentrust-tmp';
        wp_mkdir_p($stash_dir);
        if (!file_exists($stash_dir . '/.htaccess')) {
            @file_put_contents($stash_dir . '/.htaccess', "Require all denied\n");
        }
        $stash_path = $stash_dir . '/import-' . wp_generate_password(12, false) . '.zip';

        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Required for $_FILES handling. Higher-level WP wrappers impose a MIME allowlist that rejects file types we deliberately accept (admin-only, nonce + size cap + is_uploaded_file already gate this path).
        if (!move_uploaded_file((string) $_FILES['ot_import_file']['tmp_name'], $stash_path)) {
            $this->bounce_error(__('Could not store uploaded file.', 'opentrust'));
            return;
        }

        try {
            $read = OpenTrust_IO::read_zip($stash_path);
            $manifest = $read['manifest'];
            $check = OpenTrust_IO::validate_manifest($manifest);

            $strategy = isset($_POST['ot_strategy']) ? sanitize_key((string) wp_unslash($_POST['ot_strategy'])) : OpenTrust_IO::STRATEGY_SKIP;

            $preview = [
                'manifest' => $manifest,
                'errors'   => $check['errors'],
                'warnings' => $check['warnings'],
                'strategy' => $strategy,
                'zip_path' => $stash_path,
                'summary'  => [],
                'records'  => [],
            ];

            if (($manifest['format'] ?? '') === OpenTrust_IO::FORMAT_CONTENT && empty($check['errors'])) {
                $diff = OpenTrust_IO::preview_import($manifest, $strategy);
                $preview['summary'] = $diff['summary'];
                $preview['records'] = $diff['records'];
            }

            set_transient('opentrust_io_preview_' . get_current_user_id(), $preview, 30 * MINUTE_IN_SECONDS);
        } catch (\Throwable $e) {
            wp_delete_file($stash_path);
            $this->bounce_error($e->getMessage());
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=opentrust&tab=' . self::TAB_SLUG));
        exit;
    }

    public function handle_import_apply(): void {
        $this->guard('opentrust_import_apply');

        $stash_key = 'opentrust_io_preview_' . get_current_user_id();
        $preview = get_transient($stash_key);

        if (!empty($_POST['ot_cancel']) || !is_array($preview)) {
            if (is_array($preview) && !empty($preview['zip_path'])) {
                wp_delete_file((string) $preview['zip_path']);
            }
            delete_transient($stash_key);
            $this->bounce_notice(__('Import cancelled.', 'opentrust'), 'success');
            return;
        }

        if (!empty($preview['errors'])) {
            $this->bounce_error(__('Import has unresolved errors.', 'opentrust'));
            return;
        }

        $zip_path = (string) ($preview['zip_path'] ?? '');
        $manifest = (array) ($preview['manifest'] ?? []);
        $strategy = (string) ($preview['strategy'] ?? OpenTrust_IO::STRATEGY_SKIP);

        try {
            if (($manifest['format'] ?? '') === OpenTrust_IO::FORMAT_SETTINGS) {
                $result = OpenTrust_IO::apply_settings_import($manifest, $zip_path);
                $msg = sprintf(
                    /* translators: %d: count */
                    _n('%d setting imported.', '%d settings imported.', (int) ($result['updated'] ?? 0), 'opentrust'),
                    (int) ($result['updated'] ?? 0)
                );
            } else {
                $result = OpenTrust_IO::apply_content_import($manifest, $zip_path, $strategy);
                $msg = sprintf(
                    /* translators: %1$d: created, %2$d: updated, %3$d: skipped */
                    __('Imported: %1$d created, %2$d updated, %3$d skipped.', 'opentrust'),
                    (int) ($result['created'] ?? 0),
                    (int) ($result['updated'] ?? 0),
                    (int) ($result['skipped'] ?? 0)
                );
            }

            if (!empty($result['errors'])) {
                $msg .= '<br><strong>' . esc_html__('Errors:', 'opentrust') . '</strong><br>' . esc_html(implode('<br>', (array) $result['errors']));
            }

            $this->bounce_notice($msg, !empty($result['errors']) ? 'error' : 'success');
        } catch (\Throwable $e) {
            $this->bounce_error($e->getMessage());
        } finally {
            wp_delete_file($zip_path);
            delete_transient($stash_key);
        }
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function guard(string $nonce_action): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer($nonce_action);
    }

    private function parse_selection(array $post): array {
        $cpts = isset($post['ot_export_cpt']) && is_array($post['ot_export_cpt']) ? $post['ot_export_cpt'] : [];
        $ids  = isset($post['ot_export_ids']) && is_array($post['ot_export_ids']) ? $post['ot_export_ids'] : [];

        $out = [];
        foreach ($cpts as $cpt => $_) {
            $cpt = sanitize_key((string) $cpt);
            if (!in_array($cpt, OpenTrust_CPT::ALL, true)) continue;

            $picked = isset($ids[$cpt]) && is_array($ids[$cpt])
                ? array_values(array_filter(array_map('intval', $ids[$cpt])))
                : [];

            if (!empty($picked)) {
                $out[$cpt] = $picked;
            }
        }
        return $out;
    }

    private function cpt_label(string $cpt): string {
        return match ($cpt) {
            'ot_policy'        => __('Policies', 'opentrust'),
            'ot_certification' => __('Certifications', 'opentrust'),
            'ot_subprocessor'  => __('Subprocessors', 'opentrust'),
            'ot_data_practice' => __('Data Practices', 'opentrust'),
            'ot_faq'           => __('FAQs', 'opentrust'),
            default            => $cpt,
        };
    }

    private function bounce_error(string $msg): void {
        $this->bounce_notice($msg, 'error');
    }

    private function bounce_notice(string $msg, string $type): void {
        set_transient(
            'opentrust_io_notice_' . get_current_user_id(),
            ['type' => $type, 'message' => $msg],
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect(admin_url('admin.php?page=opentrust&tab=' . self::TAB_SLUG));
        exit;
    }
}
