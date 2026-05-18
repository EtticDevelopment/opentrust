<?php
/**
 * Admin bootstrap.
 *
 * Slim orchestrator that wires up the Ettic_OTC top-level admin menu,
 * the per-screen asset enqueue, the "Plain permalinks" warning, and the
 * three sub-admin classes that own the actual screens:
 *
 *   - Ettic_OTC_Admin_Settings  — Settings API + General/Contact/AI tabs page render
 *   - Ettic_OTC_Admin_AI         — AI tab body + key save/forget/refresh handlers
 *   - Ettic_OTC_Admin_Questions  — Questions log screen + export/clear/toggle
 *
 * Each sub-admin owns its own hook subscriptions; this class only owns
 * menu/asset/notice scaffolding that is shared across all of them.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ettic_OTC_Admin {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('submenu_file', [$this, 'fix_submenu_highlight']);

        // Warn admins on every Ettic_OTC admin page when the site is on Plain
        // permalinks — the plugin's pretty URLs all 404 in that mode.
        add_action('admin_notices', [$this, 'render_plain_permalinks_notice']);

        // Sub-admin systems own their own hooks.
        Ettic_OTC_Admin_Settings::instance();
        Ettic_OTC_Admin_Questions::instance();
        Ettic_OTC_Admin_AI::instance();
        Ettic_OTC_Admin_Review::instance();
        Ettic_OTC_Admin_Tools::instance();
    }

    // ──────────────────────────────────────────────
    // Menu
    // ──────────────────────────────────────────────

    public function register_menu(): void {
        $settings_page = [Ettic_OTC_Admin_Settings::instance(), 'render_settings_page'];

        add_menu_page(
            __('Open Trust Center by Ettic', 'open-trust-center-by-ettic'),
            __('Trust Center', 'open-trust-center-by-ettic'),
            'manage_options',
            'ettic-otc',
            $settings_page,
            'dashicons-shield',
            30
        );

        add_submenu_page(
            'ettic-otc',
            __('Settings', 'open-trust-center-by-ettic'),
            __('Settings', 'open-trust-center-by-ettic'),
            'manage_options',
            'ettic-otc',
            $settings_page
        );

        // AI Questions — only visible once AI is enabled.
        $settings = Ettic_OTC::get_settings();
        if (!empty($settings['ai_enabled'])) {
            add_submenu_page(
                'ettic-otc',
                __('Questions', 'open-trust-center-by-ettic'),
                __('Questions', 'open-trust-center-by-ettic'),
                'manage_options',
                'ettic-otc-questions',
                [Ettic_OTC_Admin_Questions::instance(), 'render_page']
            );
        }
    }

    /**
     * On "Add New" screens for our CPTs, highlight the correct submenu item.
     *
     * WP core's _add_post_type_submenus() and our register_menu() both hook
     * admin_menu at priority 10. Core runs first, calling add_submenu_page()
     * before add_menu_page('ettic-otc') has populated $admin_page_hooks, so
     * the CPT submenus end up in $_registered_pages under admin_page_* keys
     * instead of ettic-otc_page_*. post-new.php looks for the ettic-otc_page_*
     * key to fall back to highlighting edit.php?post_type=X; the lookup
     * misses, $submenu_file collapses to the parent slug 'ettic-otc', and
     * the Settings submenu (which uses the same slug) steals the highlight.
     */
    public function fix_submenu_highlight(?string $submenu_file): ?string {
        global $pagenow, $post_type;

        if ($pagenow !== 'post-new.php') {
            return $submenu_file;
        }

        if (in_array($post_type, Ettic_OTC_CPT::ALL, true)) {
            return "edit.php?post_type={$post_type}";
        }

        return $submenu_file;
    }

    // ──────────────────────────────────────────────
    // Assets
    // ──────────────────────────────────────────────

    public function enqueue_assets(string $hook): void {
        // Only load on our settings pages and CPT edit screens.
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $is_ettic_otc_screen = str_starts_with($screen->id, 'toplevel_page_ettic-otc')
            || str_starts_with($screen->id, 'ettic-otc_page_')
            || in_array($screen->post_type, Ettic_OTC_CPT::CORPUS, true);

        if (!$is_ettic_otc_screen) {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_media();

        wp_enqueue_style(
            'ettic-otc-admin',
            ETTIC_OTC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ETTIC_OTC_VERSION
        );

        wp_enqueue_script(
            'ettic-otc-admin',
            ETTIC_OTC_PLUGIN_URL . 'assets/js/admin.js',
            ['wp-color-picker', 'jquery'],
            ETTIC_OTC_VERSION,
            true
        );

        // Localize the handful of admin strings that admin.js renders directly
        // (e.g. wp.media modal titles). Catalog-screen strings are shipped
        // separately below via window.EtticOTCCatalog.
        wp_add_inline_script(
            'ettic-otc-admin',
            'window.EtticOTCAdmin = ' . wp_json_encode([
                'i18n' => [
                    'selectBadgeImage' => __('Select Badge Image', 'open-trust-center-by-ettic'),
                    'useAsBadge'       => __('Use as Badge', 'open-trust-center-by-ettic'),
                    'selectArtifact'   => __('Select Proof Artifact', 'open-trust-center-by-ettic'),
                    'useAsArtifact'    => __('Use This File', 'open-trust-center-by-ettic'),
                    'uploadArtifact'   => __('Upload File', 'open-trust-center-by-ettic'),
                    'replaceArtifact'  => __('Replace File', 'open-trust-center-by-ettic'),
                ],
            ]) . ';',
            'before'
        );

        // Catalog autofill: ship the bundled vendor / practice catalog only on
        // the new-post screen for the two CPTs that support it. Edit screens
        // are deliberately excluded so we never stomp existing values.
        $screen = get_current_screen();
        if ($hook === 'post-new.php' && $screen && in_array($screen->post_type, [Ettic_OTC_CPT::SUBPROCESSOR, Ettic_OTC_CPT::DATA_PRACTICE, Ettic_OTC_CPT::CERTIFICATION], true)) {
            $payload = [
                'postType' => $screen->post_type,
                'catalog'  => Ettic_OTC_Catalog::for_js($screen->post_type),
                'i18n'     => [
                    'noMatchHint' => __('No match in catalog, just keep typing to add manually.', 'open-trust-center-by-ettic'),
                    'helpFact'    => __('Auto-filled from catalog, you may want to verify this.', 'open-trust-center-by-ettic'),
                    'helpReview'  => __('Auto-filled template, please verify this matches how you use this service.', 'open-trust-center-by-ettic'),
                    'optionHint'  => __('click to autofill', 'open-trust-center-by-ettic'),
                    'suggestions' => __('Catalog suggestions', 'open-trust-center-by-ettic'),
                ],
            ];
            wp_add_inline_script(
                'ettic-otc-admin',
                'window.EtticOTCCatalog = ' . wp_json_encode($payload) . ';',
                'before'
            );
        }
    }

    // ──────────────────────────────────────────────
    // Plain permalinks notice
    // ──────────────────────────────────────────────

    /**
     * Show a persistent warning on every Ettic_OTC admin screen when the
     * WordPress permalink structure is "Plain" (i.e. empty). In that mode
     * all of the plugin's pretty URLs (/trust-center/, /trust-center/policy/...,
     * /trust-center/ask/) return 404.
     */
    public function render_plain_permalinks_notice(): void {
        if ((string) get_option('permalink_structure', '') !== '') {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Limit the noise to Ettic_OTC-owned screens: top-level plugin pages,
        // subpages, and the four content CPTs. Bail on every other admin screen.
        $is_ettic_otc_screen =
            str_contains((string) $screen->id, 'ettic-otc') ||
            in_array($screen->post_type, Ettic_OTC_CPT::CORPUS, true);

        if (!$is_ettic_otc_screen) {
            return;
        }

        $permalinks_url = admin_url('options-permalink.php');
        $home_url       = home_url('/');
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('Ettic_OTC requires pretty permalinks.', 'open-trust-center-by-ettic'); ?></strong>
                <?php
                printf(
                    /* translators: %s: link to Settings → Permalinks */
                    esc_html__('Your site is using "Plain" permalinks. Please go to %s and choose any other option (Post name is the WordPress default).', 'open-trust-center-by-ettic'),
                    '<a href="' . esc_url($permalinks_url) . '">' . esc_html__('Settings → Permalinks', 'open-trust-center-by-ettic') . '</a>'
                );
                ?>
            </p>
            <p style="font-size:12px;color:#50575e">
                <?php esc_html_e('Without pretty permalinks, every link Ettic_OTC generates returns 404 — including the trust center page itself. Visitors will not be able to reach your policies, certifications, or chat.', 'open-trust-center-by-ettic'); ?>
            </p>
            <details style="margin-top:8px">
                <summary style="cursor:pointer;font-size:12px;color:#50575e">
                    <?php esc_html_e('Read-only fallback if you cannot change permalinks', 'open-trust-center-by-ettic'); ?>
                </summary>
                <div style="margin-top:8px;padding:10px 14px;background:#f6f7f7;border-left:3px solid #dcdcde;font-size:12px;color:#50575e">
                    <p style="margin:0 0 6px">
                        <?php esc_html_e('You can preview the trust center via raw query-string URLs:', 'open-trust-center-by-ettic'); ?>
                    </p>
                    <ul style="margin:0 0 0 18px;list-style:disc">
                        <li><code><?php echo esc_html($home_url); ?>?ettic_otc=main</code></li>
                        <li><code><?php echo esc_html($home_url); ?>?ettic_otc=policy&amp;ettic_otc_policy_slug=YOUR-POLICY-SLUG</code></li>
                        <li><code><?php echo esc_html($home_url); ?>?ettic_otc=ask</code></li>
                    </ul>
                    <p style="margin:6px 0 0">
                        <strong><?php esc_html_e('This is for testing only.', 'open-trust-center-by-ettic'); ?></strong>
                        <?php esc_html_e('Switching to pretty permalinks is the only supported configuration.', 'open-trust-center-by-ettic'); ?>
                    </p>
                </div>
            </details>
        </div>
        <?php
    }
}
