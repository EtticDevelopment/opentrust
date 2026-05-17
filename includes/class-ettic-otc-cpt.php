<?php
/**
 * Custom Post Type registration and meta box management.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ettic_OTC_CPT {

    /**
     * CPT slug constants. Use these everywhere instead of bare strings so a
     * rename is one edit and a typo can't sneak through a `match` default arm.
     */
    public const POLICY        = 'eotc_policy';
    public const CERTIFICATION = 'eotc_certification';
    public const SUBPROCESSOR  = 'eotc_subprocessor';
    public const DATA_PRACTICE = 'eotc_data_practice';
    public const FAQ           = 'eotc_faq';

    /**
     * Legacy slugs from v1.0.x. Kept solely for the v3→v4 migration and the
     * import/export back-compat remap. Do not introduce new references.
     *
     * @deprecated 1.1.0 Drop in 2.0.0 once v1.0.x upgrades are no longer supported.
     *             The major-version mismatch check in Ettic_OTC_IO::validate_manifest()
     *             already hard-rejects 1.x archives on a 2.x destination, so the
     *             import remap becomes redundant at the same cutoff.
     */
    public const LEGACY_POLICY        = 'ot_policy';
    public const LEGACY_CERTIFICATION = 'ot_certification';
    public const LEGACY_SUBPROCESSOR  = 'ot_subprocessor';
    public const LEGACY_DATA_PRACTICE = 'ot_data_practice';
    public const LEGACY_FAQ           = 'ot_faq';

    /** @deprecated 1.1.0 Drop in 2.0.0 alongside the LEGACY_* constants above. */
    public const LEGACY_MAP = [
        self::LEGACY_POLICY        => self::POLICY,
        self::LEGACY_CERTIFICATION => self::CERTIFICATION,
        self::LEGACY_SUBPROCESSOR  => self::SUBPROCESSOR,
        self::LEGACY_DATA_PRACTICE => self::DATA_PRACTICE,
        self::LEGACY_FAQ           => self::FAQ,
    ];

    /**
     * Legacy postmeta keys from v1.0–v1.1, mapped old `_ot_*` → new
     * `_ettic_otc_*`. Retained for import back-compat: legacy archives
     * (exported by v1.0.x/v1.1.x) still carry these keys, and the importer
     * remaps them on read via Ettic_OTC_IO::remap_legacy_meta_keys().
     * Phase 8 extends the chain through `_ettic_otc_*`.
     */
    public const LEGACY_META_MAP = [
        // Shared identity.
        '_ot_uuid'                            => '_ettic_otc_uuid',
        // Certifications.
        '_ot_cert_type'                       => '_ettic_otc_cert_type',
        '_ot_cert_issuing_body'               => '_ettic_otc_cert_issuing_body',
        '_ot_cert_status'                     => '_ettic_otc_cert_status',
        '_ot_cert_issue_date'                 => '_ettic_otc_cert_issue_date',
        '_ot_cert_expiry_date'                => '_ettic_otc_cert_expiry_date',
        '_ot_cert_badge_id'                   => '_ettic_otc_cert_badge_id',
        '_ot_cert_artifact_id'                => '_ettic_otc_cert_artifact_id',
        '_ot_cert_description'                => '_ettic_otc_cert_description',
        // Policies.
        '_ot_policy_ref_id'                   => '_ettic_otc_policy_ref_id',
        '_ot_policy_category'                 => '_ettic_otc_policy_category',
        '_ot_policy_effective_date'           => '_ettic_otc_policy_effective_date',
        '_ot_policy_review_date'              => '_ettic_otc_policy_review_date',
        '_ot_policy_sort_order'               => '_ettic_otc_policy_sort_order',
        '_ot_policy_citations'                => '_ettic_otc_policy_citations',
        '_ot_policy_attachment_id'            => '_ettic_otc_policy_attachment_id',
        '_ot_version'                         => '_ettic_otc_version',
        '_ot_version_summary'                 => '_ettic_otc_version_summary',
        '_ot_policy_chat_summary'             => '_ettic_otc_policy_chat_summary',
        '_ot_policy_chat_summary_updated_at'  => '_ettic_otc_policy_chat_summary_updated_at',
        '_ot_policy_chat_summary_origin'      => '_ettic_otc_policy_chat_summary_origin',
        // Subprocessors.
        '_ot_sub_purpose'                     => '_ettic_otc_sub_purpose',
        '_ot_sub_data_processed'              => '_ettic_otc_sub_data_processed',
        '_ot_sub_country'                     => '_ettic_otc_sub_country',
        '_ot_sub_website'                     => '_ettic_otc_sub_website',
        '_ot_sub_dpa_signed'                  => '_ettic_otc_sub_dpa_signed',
        // Data practices.
        '_ot_dp_data_items'                   => '_ettic_otc_dp_data_items',
        '_ot_dp_purpose'                      => '_ettic_otc_dp_purpose',
        '_ot_dp_legal_basis'                  => '_ettic_otc_dp_legal_basis',
        '_ot_dp_retention_period'             => '_ettic_otc_dp_retention_period',
        '_ot_dp_shared_with'                  => '_ettic_otc_dp_shared_with',
        '_ot_dp_sort_order'                   => '_ettic_otc_dp_sort_order',
        '_ot_dp_collected'                    => '_ettic_otc_dp_collected',
        '_ot_dp_stored'                       => '_ettic_otc_dp_stored',
        '_ot_dp_shared'                       => '_ettic_otc_dp_shared',
        '_ot_dp_sold'                         => '_ettic_otc_dp_sold',
        '_ot_dp_encrypted'                    => '_ettic_otc_dp_encrypted',
        '_ot_dp_data_type'                    => '_ettic_otc_dp_data_type',
        '_ot_dp_collection_method'            => '_ettic_otc_dp_collection_method',
        '_ot_dp_is_sensitive'                 => '_ettic_otc_dp_is_sensitive',
        // FAQs.
        '_ot_faq_related_policy'              => '_ettic_otc_faq_related_policy',
        // Catalog seeding + import dedupe.
        '_ot_seed_slug'                       => '_ettic_otc_seed_slug',
        '_ot_seeded'                          => '_ettic_otc_seeded',
        '_ot_import_sha256'                   => '_ettic_otc_import_sha256',
    ];

    /**
     * Every Ettic_OTC CPT slug, in the order they appear in the trust center
     * page. Drives the render cache invalidator and the admin submenu fixer.
     */
    public const ALL = [
        self::POLICY,
        self::CERTIFICATION,
        self::SUBPROCESSOR,
        self::DATA_PRACTICE,
        self::FAQ,
    ];

    /**
     * The four CPTs whose content is indexed in the AI chat corpus. FAQs are
     * intentionally excluded — they're not part of the retrieval surface.
     */
    public const CORPUS = [
        self::POLICY,
        self::CERTIFICATION,
        self::SUBPROCESSOR,
        self::DATA_PRACTICE,
    ];

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('init', [self::class, 'register_post_types']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'ensure_uuid'], 5, 2);
        add_action('save_post', [$this, 'save_meta'], 10, 2);

        // Admin columns.
        add_filter('manage_' . self::CERTIFICATION . '_posts_columns', [$this, 'cert_columns']);
        add_action('manage_' . self::CERTIFICATION . '_posts_custom_column', [$this, 'cert_column_content'], 10, 2);
        add_filter('manage_' . self::POLICY . '_posts_columns', [$this, 'policy_columns']);
        add_action('manage_' . self::POLICY . '_posts_custom_column', [$this, 'policy_column_content'], 10, 2);
        add_filter('manage_' . self::SUBPROCESSOR . '_posts_columns', [$this, 'sub_columns']);
        add_action('manage_' . self::SUBPROCESSOR . '_posts_custom_column', [$this, 'sub_column_content'], 10, 2);
        add_filter('manage_' . self::DATA_PRACTICE . '_posts_columns', [$this, 'dp_columns']);
        add_action('manage_' . self::DATA_PRACTICE . '_posts_custom_column', [$this, 'dp_column_content'], 10, 2);
        add_filter('manage_' . self::FAQ . '_posts_columns', [$this, 'faq_columns']);
        add_action('manage_' . self::FAQ . '_posts_custom_column', [$this, 'faq_column_content'], 10, 2);

        // Catalog-autofill title-field prompt for subprocessor / data-practice CPTs.
        add_filter('enter_title_here', [$this, 'filter_enter_title_here'], 10, 2);

        // Policies get a curated block palette — focused writing surface,
        // no marketing blocks, no layout chaos.
        add_filter('allowed_block_types_all', [self::class, 'filter_policy_allowed_blocks'], 10, 2);
    }

    /**
     * Wire a flush callback to every event that can change the rendered or
     * indexed output of one of the listed CPTs. Single registration point so
     * the render cache and the chat corpus stay aligned on what counts as a
     * cache-busting event:
     *
     *   - save_post_{cpt}                  for inserts and updates
     *   - deleted_post / trashed_post /    for content removal
     *     untrashed_post                   (filtered by post_type)
     *   - transition_post_status           for publish ↔ draft / private
     *                                      transitions (filtered by CPT)
     *
     * The $callback may take any arity — extra args are silently ignored, so
     * the same callable can be registered with both single-arg hooks (delete)
     * and three-arg hooks (transition_post_status).
     *
     * @param array<int, string> $cpts CPT slugs (e.g. self::ALL or self::CORPUS).
     */
    public static function register_invalidator(array $cpts, callable $callback): void {
        foreach ($cpts as $cpt) {
            add_action("save_post_{$cpt}", $callback);
        }

        $on_post_event = static function ($post_id) use ($cpts, $callback): void {
            if (in_array(get_post_type((int) $post_id), $cpts, true)) {
                $callback($post_id);
            }
        };
        add_action('deleted_post',   $on_post_event);
        add_action('trashed_post',   $on_post_event);
        add_action('untrashed_post', $on_post_event);

        add_action(
            'transition_post_status',
            static function (string $new, string $old, \WP_Post $post) use ($cpts, $callback): void {
                if (!in_array($post->post_type, $cpts, true)) {
                    return;
                }
                if ($new === 'publish' || $old === 'publish') {
                    $callback($post->ID);
                }
            },
            10,
            3
        );
    }

    /**
     * Replace the "Add title" prompt on subprocessor and data-practice new-post
     * screens so users know the title field is also a catalog lookup.
     */
    public function filter_enter_title_here(string $text, \WP_Post $post): string {
        if ($post->post_type === self::SUBPROCESSOR) {
            return __('Pick from the catalog or type your own subprocessor name', 'open-trust-center-by-ettic');
        }
        if ($post->post_type === self::DATA_PRACTICE) {
            return __('Pick from the catalog or type your own, e.g. Analytics or Transactional Email', 'open-trust-center-by-ettic');
        }
        if ($post->post_type === self::CERTIFICATION) {
            return __('Pick from the catalog or type your own, e.g. SOC 2 Type II or ISO 27001', 'open-trust-center-by-ettic');
        }
        return $text;
    }

    // ──────────────────────────────────────────────
    // CPT Registration
    // ──────────────────────────────────────────────

    public static function register_post_types(): void {
        // ── Policies ──
        register_post_type(self::POLICY, [
            'labels' => [
                'name'               => __('Policies', 'open-trust-center-by-ettic'),
                'singular_name'      => __('Policy', 'open-trust-center-by-ettic'),
                'add_new'            => __('Add Policy', 'open-trust-center-by-ettic'),
                'add_new_item'       => __('Add New Policy', 'open-trust-center-by-ettic'),
                'edit_item'          => __('Edit Policy', 'open-trust-center-by-ettic'),
                'new_item'           => __('New Policy', 'open-trust-center-by-ettic'),
                'view_item'          => __('View Policy', 'open-trust-center-by-ettic'),
                'search_items'       => __('Search Policies', 'open-trust-center-by-ettic'),
                'not_found'          => __('No policies found.', 'open-trust-center-by-ettic'),
                'not_found_in_trash' => __('No policies in trash.', 'open-trust-center-by-ettic'),
                'all_items'          => __('Policies', 'open-trust-center-by-ettic'),
            ],
            // public=>true is required so WPML and Polylang detect the post type
            // as translatable content and offer per-language variants in their UIs.
            // publicly_queryable + exclude_from_search + has_archive + rewrite are
            // all disabled so the CPTs never leak to the frontend — the trust
            // center stays the single rendering surface via template_redirect.
            'public'              => true,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_ui'             => true,
            'show_in_menu'        => 'ettic-otc',
            'show_in_rest'        => true,
            'supports'      => ['title', 'editor', 'revisions', 'excerpt'],
            'has_archive'   => false,
            'rewrite'       => false,
            'menu_icon'     => 'dashicons-media-document',
            'menu_position' => 31,
        ]);

        // ── Certifications ──
        register_post_type(self::CERTIFICATION, [
            'labels' => [
                'name'               => __('Certifications', 'open-trust-center-by-ettic'),
                'singular_name'      => __('Certification', 'open-trust-center-by-ettic'),
                'add_new'            => __('Add Certification', 'open-trust-center-by-ettic'),
                'add_new_item'       => __('Add New Certification', 'open-trust-center-by-ettic'),
                'edit_item'          => __('Edit Certification', 'open-trust-center-by-ettic'),
                'new_item'           => __('New Certification', 'open-trust-center-by-ettic'),
                'search_items'       => __('Search Certifications', 'open-trust-center-by-ettic'),
                'not_found'          => __('No certifications found.', 'open-trust-center-by-ettic'),
                'not_found_in_trash' => __('No certifications in trash.', 'open-trust-center-by-ettic'),
                'all_items'          => __('Certifications', 'open-trust-center-by-ettic'),
            ],
            // public=>true is required so WPML and Polylang detect the post type
            // as translatable content and offer per-language variants in their UIs.
            // publicly_queryable + exclude_from_search + has_archive + rewrite are
            // all disabled so the CPTs never leak to the frontend — the trust
            // center stays the single rendering surface via template_redirect.
            'public'              => true,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_ui'             => true,
            'show_in_menu'        => 'ettic-otc',
            'show_in_rest'        => true,
            'supports'      => ['title'],
            'has_archive'   => false,
            'rewrite'       => false,
            'menu_icon'     => 'dashicons-awards',
            'menu_position' => 32,
        ]);

        // ── Subprocessors ──
        register_post_type(self::SUBPROCESSOR, [
            'labels' => [
                'name'               => __('Subprocessors', 'open-trust-center-by-ettic'),
                'singular_name'      => __('Subprocessor', 'open-trust-center-by-ettic'),
                'add_new'            => __('Add Subprocessor', 'open-trust-center-by-ettic'),
                'add_new_item'       => __('Add New Subprocessor', 'open-trust-center-by-ettic'),
                'edit_item'          => __('Edit Subprocessor', 'open-trust-center-by-ettic'),
                'new_item'           => __('New Subprocessor', 'open-trust-center-by-ettic'),
                'search_items'       => __('Search Subprocessors', 'open-trust-center-by-ettic'),
                'not_found'          => __('No subprocessors found.', 'open-trust-center-by-ettic'),
                'not_found_in_trash' => __('No subprocessors in trash.', 'open-trust-center-by-ettic'),
                'all_items'          => __('Subprocessors', 'open-trust-center-by-ettic'),
            ],
            // public=>true is required so WPML and Polylang detect the post type
            // as translatable content and offer per-language variants in their UIs.
            // publicly_queryable + exclude_from_search + has_archive + rewrite are
            // all disabled so the CPTs never leak to the frontend — the trust
            // center stays the single rendering surface via template_redirect.
            'public'              => true,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_ui'             => true,
            'show_in_menu'        => 'ettic-otc',
            'show_in_rest'        => true,
            'supports'      => ['title'],
            'has_archive'   => false,
            'rewrite'       => false,
            'menu_icon'     => 'dashicons-networking',
            'menu_position' => 33,
        ]);

        // ── Data Practices ──
        register_post_type(self::DATA_PRACTICE, [
            'labels' => [
                'name'               => __('Data Practices', 'open-trust-center-by-ettic'),
                'singular_name'      => __('Data Practice', 'open-trust-center-by-ettic'),
                'add_new'            => __('Add Data Practice', 'open-trust-center-by-ettic'),
                'add_new_item'       => __('Add New Data Practice', 'open-trust-center-by-ettic'),
                'edit_item'          => __('Edit Data Practice', 'open-trust-center-by-ettic'),
                'new_item'           => __('New Data Practice', 'open-trust-center-by-ettic'),
                'search_items'       => __('Search Data Practices', 'open-trust-center-by-ettic'),
                'not_found'          => __('No data practices found.', 'open-trust-center-by-ettic'),
                'not_found_in_trash' => __('No data practices in trash.', 'open-trust-center-by-ettic'),
                'all_items'          => __('Data Practices', 'open-trust-center-by-ettic'),
            ],
            // public=>true is required so WPML and Polylang detect the post type
            // as translatable content and offer per-language variants in their UIs.
            // publicly_queryable + exclude_from_search + has_archive + rewrite are
            // all disabled so the CPTs never leak to the frontend — the trust
            // center stays the single rendering surface via template_redirect.
            'public'              => true,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_ui'             => true,
            'show_in_menu'        => 'ettic-otc',
            'show_in_rest'        => true,
            'supports'      => ['title'],
            'has_archive'   => false,
            'rewrite'       => false,
            'menu_icon'     => 'dashicons-database',
            'menu_position' => 34,
        ]);

        // ── FAQs ──
        register_post_type(self::FAQ, [
            'labels' => [
                'name'               => __('FAQs', 'open-trust-center-by-ettic'),
                'singular_name'      => __('FAQ', 'open-trust-center-by-ettic'),
                'add_new'            => __('Add FAQ', 'open-trust-center-by-ettic'),
                'add_new_item'       => __('Add New FAQ', 'open-trust-center-by-ettic'),
                'edit_item'          => __('Edit FAQ', 'open-trust-center-by-ettic'),
                'new_item'           => __('New FAQ', 'open-trust-center-by-ettic'),
                'view_item'          => __('View FAQ', 'open-trust-center-by-ettic'),
                'search_items'       => __('Search FAQs', 'open-trust-center-by-ettic'),
                'not_found'          => __('No FAQs found.', 'open-trust-center-by-ettic'),
                'not_found_in_trash' => __('No FAQs in trash.', 'open-trust-center-by-ettic'),
                'all_items'          => __('FAQs', 'open-trust-center-by-ettic'),
            ],
            'public'              => true,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_ui'             => true,
            'show_in_menu'        => 'ettic-otc',
            'show_in_rest'        => true,
            'supports'      => ['title', 'editor', 'page-attributes'],
            'has_archive'   => false,
            'rewrite'       => false,
            'menu_icon'     => 'dashicons-format-chat',
            'menu_position' => 35,
        ]);
    }

    // ──────────────────────────────────────────────
    // Meta Boxes
    // ──────────────────────────────────────────────

    public function add_meta_boxes(): void {
        add_meta_box('ettic_otc_cert_details', __('Certification Details', 'open-trust-center-by-ettic'), [$this, 'render_cert_meta_box'], self::CERTIFICATION, 'normal', 'high');
        add_meta_box('ettic_otc_policy_details', __('Policy Details', 'open-trust-center-by-ettic'), [$this, 'render_policy_meta_box'], self::POLICY, 'side', 'high');
        add_meta_box('ettic_otc_sub_details', __('Subprocessor Details', 'open-trust-center-by-ettic'), [$this, 'render_sub_meta_box'], self::SUBPROCESSOR, 'normal', 'high');
        add_meta_box('ettic_otc_dp_details', __('Data Practice Details', 'open-trust-center-by-ettic'), [$this, 'render_dp_meta_box'], self::DATA_PRACTICE, 'normal', 'high');
        add_meta_box('ettic_otc_faq_details', __('FAQ Details', 'open-trust-center-by-ettic'), [$this, 'render_faq_meta_box'], self::FAQ, 'side', 'high');
    }

    // ── Certification meta box ──

    public function render_cert_meta_box(\WP_Post $post): void {
        wp_nonce_field('ettic_otc_save_cert', 'ettic_otc_cert_nonce');

        $type         = get_post_meta($post->ID, '_ettic_otc_cert_type', true) ?: 'compliant';
        $issuing_body = get_post_meta($post->ID, '_ettic_otc_cert_issuing_body', true) ?: '';
        $status       = get_post_meta($post->ID, '_ettic_otc_cert_status', true) ?: 'active';
        $issue_date   = get_post_meta($post->ID, '_ettic_otc_cert_issue_date', true) ?: '';
        $expiry_date  = get_post_meta($post->ID, '_ettic_otc_cert_expiry_date', true) ?: '';
        $badge_id     = (int) get_post_meta($post->ID, '_ettic_otc_cert_badge_id', true);
        $badge_url    = $badge_id ? wp_get_attachment_image_url($badge_id, 'thumbnail') : '';
        $description  = get_post_meta($post->ID, '_ettic_otc_cert_description', true) ?: '';
        $artifact_id  = (int) get_post_meta($post->ID, '_ettic_otc_cert_artifact_id', true);
        $artifact_url = $artifact_id ? wp_get_attachment_url($artifact_id) : '';
        $artifact_name = $artifact_id ? get_the_title($artifact_id) : '';

        $types = [
            'certified' => __('Audited certification (issued by a third party)', 'open-trust-center-by-ettic'),
            'compliant' => __('Self-attested alignment (no external audit)', 'open-trust-center-by-ettic'),
        ];

        $statuses = [
            'active'      => __('Active / currently met', 'open-trust-center-by-ettic'),
            'in_progress' => __('In progress', 'open-trust-center-by-ettic'),
            'expired'     => __('Expired / lapsed', 'open-trust-center-by-ettic'),
        ];
        ?>
        <div class="ot-meta-field">
            <label for="ettic_otc_cert_type"><?php esc_html_e('Certification Type', 'open-trust-center-by-ettic'); ?></label>
            <select id="ettic_otc_cert_type" name="ettic_otc_cert_type" data-ot-cert-type>
                <?php foreach ($types as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($type, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Audited means a third-party issued a formal certificate with dates (SOC 2, ISO 27001, PCI DSS). Self-attested means you adhere to the framework without an external audit — the honest framing for GDPR, CCPA, and most HIPAA posture claims.', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <div class="ot-meta-field">
            <label for="ettic_otc_cert_status"><?php esc_html_e('Status', 'open-trust-center-by-ettic'); ?></label>
            <select id="ettic_otc_cert_status" name="ettic_otc_cert_status">
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('"Active" for audited means you hold a current certificate. "Active" for self-attested means you currently meet the framework. Use "In progress" while working toward either.', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <div class="ot-meta-field" data-ot-cert-certified-only>
            <label for="ettic_otc_cert_issuing_body"><?php esc_html_e('Issuing Body', 'open-trust-center-by-ettic'); ?></label>
            <input type="text" id="ettic_otc_cert_issuing_body" name="ettic_otc_cert_issuing_body" value="<?php echo esc_attr($issuing_body); ?>" placeholder="<?php esc_attr_e('e.g., AICPA, BSI Group, Schellman', 'open-trust-center-by-ettic'); ?>">
        </div>

        <div class="ot-meta-field" data-ot-cert-certified-only>
            <label for="ettic_otc_cert_issue_date"><?php esc_html_e('Issue Date', 'open-trust-center-by-ettic'); ?></label>
            <input type="date" id="ettic_otc_cert_issue_date" name="ettic_otc_cert_issue_date" value="<?php echo esc_attr($issue_date); ?>">
        </div>

        <div class="ot-meta-field" data-ot-cert-certified-only>
            <label for="ettic_otc_cert_expiry_date"><?php esc_html_e('Expiry Date', 'open-trust-center-by-ettic'); ?></label>
            <input type="date" id="ettic_otc_cert_expiry_date" name="ettic_otc_cert_expiry_date" value="<?php echo esc_attr($expiry_date); ?>">
        </div>

        <div class="ot-meta-field">
            <label><?php esc_html_e('Framework Logo', 'open-trust-center-by-ettic'); ?></label>
            <img class="ot-badge-preview<?php echo esc_attr($badge_url ? '' : ' ot-hidden'); ?>" src="<?php echo esc_url($badge_url); ?>" alt="">
            <input type="hidden" class="ot-badge-input" name="ettic_otc_cert_badge_id" value="<?php echo esc_attr((string) $badge_id); ?>">
            <button type="button" class="button ot-upload-badge"><?php esc_html_e('Select Logo', 'open-trust-center-by-ettic'); ?></button>
            <button type="button" class="button ot-remove-badge<?php echo esc_attr($badge_id ? '' : ' ot-hidden'); ?>"><?php esc_html_e('Remove', 'open-trust-center-by-ettic'); ?></button>
            <p class="description"><?php esc_html_e('Use the official framework mark where licensing allows (SOC 2, ISO, GDPR shield). Square images work best at 44×44.', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <div class="ot-meta-field" data-ot-cert-artifact>
            <label><?php esc_html_e('Proof Artifact', 'open-trust-center-by-ettic'); ?></label>
            <div class="ot-artifact-preview<?php echo esc_attr($artifact_id ? '' : ' ot-hidden'); ?>">
                <span class="ot-artifact-preview__icon" aria-hidden="true">📄</span>
                <a class="ot-artifact-preview__link" href="<?php echo esc_url($artifact_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($artifact_name ?: __('View file', 'open-trust-center-by-ettic')); ?></a>
            </div>
            <input type="hidden" class="ot-artifact-input" name="ettic_otc_cert_artifact_id" value="<?php echo esc_attr((string) $artifact_id); ?>">
            <button type="button" class="button ot-upload-artifact"><?php echo $artifact_id ? esc_html__('Replace File', 'open-trust-center-by-ettic') : esc_html__('Upload File', 'open-trust-center-by-ettic'); ?></button>
            <button type="button" class="button ot-remove-artifact<?php echo esc_attr($artifact_id ? '' : ' ot-hidden'); ?>"><?php esc_html_e('Remove', 'open-trust-center-by-ettic'); ?></button>
            <p class="description"><?php esc_html_e('Optional PDF the trust center can link to — e.g. the audit report, certificate, or policy mapping document. Shown as a download button on the card.', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <div class="ot-meta-field">
            <label for="ettic_otc_cert_description"><?php esc_html_e('Scope & Notes', 'open-trust-center-by-ettic'); ?></label>
            <textarea id="ettic_otc_cert_description" name="ettic_otc_cert_description" rows="3" placeholder="<?php esc_attr_e('e.g., We process EU personal data under GDPR. Our DPA covers customer data, and we support DSARs within 30 days.', 'open-trust-center-by-ettic'); ?>"><?php echo esc_textarea($description); ?></textarea>
            <p class="description"><?php esc_html_e('Required for self-attested frameworks so the card has meaningful content. One or two sentences on scope, how you meet the framework, or what prospects should know.', 'open-trust-center-by-ettic'); ?></p>
        </div>
        <?php
    }

    /**
     * Restrict the policy editor to a curated set of blocks. Authors keep the
     * modern editor's authoring ergonomics without the noise of marketing,
     * embed, or widget blocks that don't belong in a compliance document.
     */
    public static function filter_policy_allowed_blocks(array|bool $allowed, \WP_Block_Editor_Context $context): array|bool {
        if (empty($context->post) || $context->post->post_type !== self::POLICY) {
            return $allowed;
        }
        return [
            'core/paragraph',
            'core/heading',
            'core/list',
            'core/list-item',
            'core/table',
            'core/quote',
            'core/separator',
            'core/image',
            'core/code',
            'core/details',
        ];
    }

    // ── Policy meta box (sidebar) ──

    public function render_policy_meta_box(\WP_Post $post): void {
        wp_nonce_field('ettic_otc_save_policy', 'ettic_otc_policy_nonce');

        $ref_id          = get_post_meta($post->ID, '_ettic_otc_policy_ref_id', true) ?: '';
        $category        = get_post_meta($post->ID, '_ettic_otc_policy_category', true) ?: 'other';
        $effective_date  = get_post_meta($post->ID, '_ettic_otc_policy_effective_date', true) ?: '';
        $review_date     = get_post_meta($post->ID, '_ettic_otc_policy_review_date', true) ?: '';
        $sort_order      = metadata_exists('post', $post->ID, '_ettic_otc_policy_sort_order')
            ? (int) get_post_meta($post->ID, '_ettic_otc_policy_sort_order', true)
            : 10;
        $version         = (int) get_post_meta($post->ID, '_ettic_otc_version', true) ?: 1;

        $citations       = get_post_meta($post->ID, '_ettic_otc_policy_citations', true);
        $citations       = is_array($citations) ? $citations : [];

        $attachment_id   = (int) get_post_meta($post->ID, '_ettic_otc_policy_attachment_id', true);
        $attachment_url  = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
        $attachment_name = $attachment_id ? get_the_title($attachment_id) : '';

        $categories = Ettic_OTC_Render::policy_category_labels();
        ?>
        <div class="ot-meta-field" style="background:#f0f4ff;padding:12px;border-radius:6px;margin-bottom:16px;">
            <p style="font-size:20px;font-weight:700;margin:0 0 4px;color:#2563eb;">
                <?php
                /* translators: %s: policy version number */
                printf(esc_html__('Version %s', 'open-trust-center-by-ettic'), esc_html((string) $version)); ?>
            </p>
            <p class="description" style="margin:0;color:#6b7280;">
                <?php esc_html_e('Regular saves update the current version. Use the checkbox below to formally publish a new version.', 'open-trust-center-by-ettic'); ?>
            </p>
        </div>

        <?php if ('publish' === $post->post_status): ?>
        <div class="ot-meta-field ot-version-bump" style="border:2px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:16px;">
            <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                <input type="checkbox" name="ettic_otc_publish_new_version" value="1" id="ettic_otc_publish_new_version" style="margin-top:2px;">
                <span>
                    <strong><?php esc_html_e('Publish as new version', 'open-trust-center-by-ettic'); ?></strong><br>
                    <span class="description" style="font-size:12px;">
                        <?php
                        printf(
                            /* translators: %1$d: current version number, %2$d: next version number */
                            esc_html__('This will save the current content as v%1$d and create v%2$d. Only check this for formal, published changes — not minor edits.', 'open-trust-center-by-ettic'),
                            intval( $version ),
                            intval( $version + 1 )
                        ); ?>
                    </span>
                </span>
            </label>
            <div id="ot-version-summary-wrap" style="margin-top:10px;display:none;">
                <label for="ettic_otc_version_summary" style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">
                    <?php esc_html_e('What changed?', 'open-trust-center-by-ettic'); ?>
                </label>
                <input type="text" id="ettic_otc_version_summary" name="ettic_otc_version_summary" value="" style="width:100%;"
                    placeholder="<?php esc_attr_e('e.g., Updated data retention from 90 to 60 days', 'open-trust-center-by-ettic'); ?>">
                <p class="description" style="margin-top:2px;font-size:11px;"><?php esc_html_e('Shown in the public version history.', 'open-trust-center-by-ettic'); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <div class="ot-meta-field">
            <label for="ettic_otc_policy_ref_id"><?php esc_html_e('Policy ID', 'open-trust-center-by-ettic'); ?></label>
            <input type="text" id="ettic_otc_policy_ref_id" name="ettic_otc_policy_ref_id" value="<?php echo esc_attr($ref_id); ?>" style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace" placeholder="<?php esc_attr_e('e.g., POL-012', 'open-trust-center-by-ettic'); ?>" maxlength="40">
            <p class="description"><?php esc_html_e('Optional short reference (e.g., POL-012). Shown on the public listing and in security questionnaires.', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <div class="ot-meta-field">
            <label for="ettic_otc_policy_category"><?php esc_html_e('Category', 'open-trust-center-by-ettic'); ?></label>
            <select id="ettic_otc_policy_category" name="ettic_otc_policy_category" style="width:100%">
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($category, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ot-meta-field">
            <label for="ettic_otc_policy_effective_date"><?php esc_html_e('Effective Date', 'open-trust-center-by-ettic'); ?></label>
            <input type="date" id="ettic_otc_policy_effective_date" name="ettic_otc_policy_effective_date" value="<?php echo esc_attr($effective_date); ?>" style="width:100%">
        </div>

        <div class="ot-meta-field">
            <label for="ettic_otc_policy_review_date"><?php esc_html_e('Next Review Date', 'open-trust-center-by-ettic'); ?></label>
            <input type="date" id="ettic_otc_policy_review_date" name="ettic_otc_policy_review_date" value="<?php echo esc_attr($review_date); ?>" style="width:100%">
        </div>

        <div class="ot-meta-field">
            <label><?php esc_html_e('Framework Citations', 'open-trust-center-by-ettic'); ?></label>
            <div class="ot-tags" data-ot-tags="ettic_otc_policy_citations">
                <?php foreach ($citations as $ot_i => $ot_citation):
                    $ot_citation_name = is_array($ot_citation) ? ($ot_citation['name'] ?? '') : (string) $ot_citation;
                ?>
                <span class="ot-tag">
                    <span class="ot-tag__text"><?php echo esc_html($ot_citation_name); ?></span>
                    <input type="hidden" name="ettic_otc_policy_citations[<?php echo (int) $ot_i; ?>][name]" value="<?php echo esc_attr($ot_citation_name); ?>">
                    <button type="button" class="ot-tag__remove" aria-label="<?php esc_attr_e('Remove', 'open-trust-center-by-ettic'); ?>">&times;</button>
                </span>
                <?php endforeach; ?>
                <input type="text" class="ot-tags__input" placeholder="<?php esc_attr_e('e.g., SOC 2 CC6.1, ISO 27001 A.9.2…', 'open-trust-center-by-ettic'); ?>" />
            </div>
            <p class="description"><?php esc_html_e('Framework or control references this policy satisfies. Appears as pill badges on the public page.', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <div class="ot-meta-field" data-ot-policy-attachment>
            <label><?php esc_html_e('PDF Attachment', 'open-trust-center-by-ettic'); ?></label>
            <div class="ot-artifact-preview<?php echo esc_attr($attachment_id ? '' : ' ot-hidden'); ?>">
                <span class="ot-artifact-preview__icon" aria-hidden="true">📄</span>
                <a class="ot-artifact-preview__link" href="<?php echo esc_url($attachment_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($attachment_name ?: __('View file', 'open-trust-center-by-ettic')); ?></a>
            </div>
            <input type="hidden" class="ot-policy-attachment-input" name="ettic_otc_policy_attachment_id" value="<?php echo esc_attr((string) $attachment_id); ?>">
            <button type="button" class="button ot-upload-policy-attachment"><?php echo $attachment_id ? esc_html__('Replace PDF', 'open-trust-center-by-ettic') : esc_html__('Upload PDF', 'open-trust-center-by-ettic'); ?></button>
            <button type="button" class="button ot-remove-policy-attachment<?php echo esc_attr($attachment_id ? '' : ' ot-hidden'); ?>"><?php esc_html_e('Remove', 'open-trust-center-by-ettic'); ?></button>
            <p class="description"><?php esc_html_e('Upload the signed PDF. Visitors see a "Download PDF" button only when a file is attached.', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <div class="ot-meta-field">
            <label for="ettic_otc_policy_sort_order"><?php esc_html_e('Sort Order', 'open-trust-center-by-ettic'); ?></label>
            <input type="number" id="ettic_otc_policy_sort_order" name="ettic_otc_policy_sort_order" value="<?php echo esc_attr((string) $sort_order); ?>" min="0" step="1" style="width:100%">
            <p class="description"><?php esc_html_e('Lower numbers appear first.', 'open-trust-center-by-ettic'); ?></p>
        </div>
        <?php
    }

    // ── Subprocessor meta box ──

    public function render_sub_meta_box(\WP_Post $post): void {
        wp_nonce_field('ettic_otc_save_sub', 'ettic_otc_sub_nonce');

        $purpose        = get_post_meta($post->ID, '_ettic_otc_sub_purpose', true) ?: '';
        $data_processed = get_post_meta($post->ID, '_ettic_otc_sub_data_processed', true) ?: '';
        $country        = get_post_meta($post->ID, '_ettic_otc_sub_country', true) ?: '';
        $website        = get_post_meta($post->ID, '_ettic_otc_sub_website', true) ?: '';
        $dpa_signed     = (bool) get_post_meta($post->ID, '_ettic_otc_sub_dpa_signed', true);
        ?>
        <div class="ot-meta-field">
            <label for="ettic_otc_sub_purpose"><?php esc_html_e('Purpose', 'open-trust-center-by-ettic'); ?></label>
            <textarea id="ettic_otc_sub_purpose" name="ettic_otc_sub_purpose" rows="2"><?php echo esc_textarea($purpose); ?></textarea>
            <p class="description"><?php esc_html_e('What does this subprocessor do for your company?', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <div class="ot-meta-field">
            <label for="ettic_otc_sub_data_processed"><?php esc_html_e('Data Processed', 'open-trust-center-by-ettic'); ?></label>
            <textarea id="ettic_otc_sub_data_processed" name="ettic_otc_sub_data_processed" rows="2"><?php echo esc_textarea($data_processed); ?></textarea>
            <p class="description"><?php esc_html_e('What types of data does this subprocessor handle?', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <div class="ot-meta-field">
            <label for="ettic_otc_sub_country"><?php esc_html_e('Country / Location', 'open-trust-center-by-ettic'); ?></label>
            <input type="text" id="ettic_otc_sub_country" name="ettic_otc_sub_country" value="<?php echo esc_attr($country); ?>" placeholder="<?php esc_attr_e('e.g., United States', 'open-trust-center-by-ettic'); ?>">
        </div>

        <div class="ot-meta-field">
            <label for="ettic_otc_sub_website"><?php esc_html_e('Website', 'open-trust-center-by-ettic'); ?></label>
            <input type="url" id="ettic_otc_sub_website" name="ettic_otc_sub_website" value="<?php echo esc_attr($website); ?>" placeholder="https://">
        </div>

        <div class="ot-meta-field">
            <label>
                <input type="checkbox" name="ettic_otc_sub_dpa_signed" value="1" <?php checked($dpa_signed); ?>>
                <?php esc_html_e('DPA Signed', 'open-trust-center-by-ettic'); ?>
            </label>
            <p class="description"><?php esc_html_e('A Data Processing Agreement (DPA) is a contract between you and the subprocessor covering how they handle personal data on your behalf. Check this box once your organization has signed one with this vendor.', 'open-trust-center-by-ettic'); ?></p>
        </div>
        <?php
    }

    // ── Data Practice meta box ──

    public function render_dp_meta_box(\WP_Post $post): void {
        wp_nonce_field('ettic_otc_save_dp', 'ettic_otc_dp_nonce');

        $data_items       = get_post_meta($post->ID, '_ettic_otc_dp_data_items', true);
        $data_items       = is_array($data_items) ? $data_items : [];
        $purpose          = get_post_meta($post->ID, '_ettic_otc_dp_purpose', true) ?: '';
        $legal_basis      = get_post_meta($post->ID, '_ettic_otc_dp_legal_basis', true) ?: '';
        $retention_period = get_post_meta($post->ID, '_ettic_otc_dp_retention_period', true) ?: '';
        $shared_with      = get_post_meta($post->ID, '_ettic_otc_dp_shared_with', true);
        $shared_with      = is_array($shared_with) ? $shared_with : [];
        $sort_order       = (int) get_post_meta($post->ID, '_ettic_otc_dp_sort_order', true);

        $prop_collected   = (bool) get_post_meta($post->ID, '_ettic_otc_dp_collected', true);
        $prop_stored      = (bool) get_post_meta($post->ID, '_ettic_otc_dp_stored', true);
        $prop_shared      = (bool) get_post_meta($post->ID, '_ettic_otc_dp_shared', true);
        $prop_sold        = (bool) get_post_meta($post->ID, '_ettic_otc_dp_sold', true);
        $prop_encrypted   = (bool) get_post_meta($post->ID, '_ettic_otc_dp_encrypted', true);

        $basis_options    = Ettic_OTC_Render::legal_basis_labels();
        ?>

        <!-- Data Items — tag input -->
        <div class="ot-meta-field">
            <label><?php esc_html_e('Data Items Collected', 'open-trust-center-by-ettic'); ?></label>
            <div class="ot-tags" data-ot-tags="ettic_otc_dp_data_items">
                <?php foreach ($data_items as $i => $item): ?>
                <span class="ot-tag">
                    <span class="ot-tag__text"><?php echo esc_html($item['name'] ?? ''); ?></span>
                    <input type="hidden" name="ettic_otc_dp_data_items[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr($item['name'] ?? ''); ?>">
                    <button type="button" class="ot-tag__remove" aria-label="<?php esc_attr_e('Remove', 'open-trust-center-by-ettic'); ?>">&times;</button>
                </span>
                <?php endforeach; ?>
                <input type="text" class="ot-tags__input" placeholder="<?php esc_attr_e('Type and press Enter...', 'open-trust-center-by-ettic'); ?>" />
            </div>
        </div>

        <!-- Purpose -->
        <div class="ot-meta-field">
            <label for="ettic_otc_dp_purpose"><?php esc_html_e('Purpose', 'open-trust-center-by-ettic'); ?></label>
            <textarea id="ettic_otc_dp_purpose" name="ettic_otc_dp_purpose" rows="2"><?php echo esc_textarea($purpose); ?></textarea>
        </div>

        <!-- Legal Basis & Retention row -->
        <div class="ot-meta-row">
            <div class="ot-meta-field">
                <label for="ettic_otc_dp_legal_basis"><?php esc_html_e('Legal Basis', 'open-trust-center-by-ettic'); ?></label>
                <select id="ettic_otc_dp_legal_basis" name="ettic_otc_dp_legal_basis">
                    <option value=""><?php esc_html_e('— Select —', 'open-trust-center-by-ettic'); ?></option>
                    <?php foreach ($basis_options as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($legal_basis, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ot-meta-field">
                <label for="ettic_otc_dp_retention_period"><?php esc_html_e('Retention Period', 'open-trust-center-by-ettic'); ?></label>
                <input type="text" id="ettic_otc_dp_retention_period" name="ettic_otc_dp_retention_period" value="<?php echo esc_attr($retention_period); ?>" placeholder="<?php esc_attr_e('e.g., 30 days', 'open-trust-center-by-ettic'); ?>">
            </div>
        </div>

        <!-- Shared With — tag input -->
        <div class="ot-meta-field">
            <label><?php esc_html_e('Shared With', 'open-trust-center-by-ettic'); ?></label>
            <div class="ot-tags" data-ot-tags="ettic_otc_dp_shared_with">
                <?php foreach ($shared_with as $i => $entry): ?>
                <span class="ot-tag">
                    <span class="ot-tag__text"><?php echo esc_html($entry['name'] ?? ''); ?></span>
                    <input type="hidden" name="ettic_otc_dp_shared_with[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr($entry['name'] ?? ''); ?>">
                    <button type="button" class="ot-tag__remove" aria-label="<?php esc_attr_e('Remove', 'open-trust-center-by-ettic'); ?>">&times;</button>
                </span>
                <?php endforeach; ?>
                <input type="text" class="ot-tags__input" placeholder="<?php esc_attr_e('Type and press Enter...', 'open-trust-center-by-ettic'); ?>" />
            </div>
        </div>

        <!-- Properties — binary flags the AI assistant reports verbatim -->
        <div class="ot-meta-field">
            <label><?php esc_html_e('Properties', 'open-trust-center-by-ettic'); ?></label>
            <div class="ot-dp-props">
                <label class="ot-dp-props__item">
                    <input type="checkbox" name="ettic_otc_dp_collected" value="1" <?php checked($prop_collected); ?>>
                    <span><?php esc_html_e('Collected', 'open-trust-center-by-ettic'); ?></span>
                </label>
                <label class="ot-dp-props__item">
                    <input type="checkbox" name="ettic_otc_dp_stored" value="1" <?php checked($prop_stored); ?>>
                    <span><?php esc_html_e('Stored', 'open-trust-center-by-ettic'); ?></span>
                </label>
                <label class="ot-dp-props__item">
                    <input type="checkbox" name="ettic_otc_dp_shared" value="1" <?php checked($prop_shared); ?>>
                    <span><?php esc_html_e('Shared with third parties', 'open-trust-center-by-ettic'); ?></span>
                </label>
                <label class="ot-dp-props__item">
                    <input type="checkbox" name="ettic_otc_dp_sold" value="1" <?php checked($prop_sold); ?>>
                    <span><?php esc_html_e('Sold to third parties', 'open-trust-center-by-ettic'); ?></span>
                </label>
                <label class="ot-dp-props__item">
                    <input type="checkbox" name="ettic_otc_dp_encrypted" value="1" <?php checked($prop_encrypted); ?>>
                    <span><?php esc_html_e('Encrypted', 'open-trust-center-by-ettic'); ?></span>
                </label>
            </div>
            <p class="description"><?php esc_html_e('Unchecked means an explicit "No". The AI assistant reports these values verbatim to visitors asking questions like "Do you sell customer data?".', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <!-- Sort order -->
        <div class="ot-meta-field">
            <label for="ettic_otc_dp_sort_order"><?php esc_html_e('Sort Order', 'open-trust-center-by-ettic'); ?></label>
            <input type="number" id="ettic_otc_dp_sort_order" name="ettic_otc_dp_sort_order" value="<?php echo esc_attr((string) $sort_order); ?>" min="0" step="1">
            <p class="description"><?php esc_html_e('Lower numbers appear first.', 'open-trust-center-by-ettic'); ?></p>
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    // UUID stamp
    // ──────────────────────────────────────────────

    // Stable cross-site identity for the IO layer. Slugs and post IDs both drift.
    public function ensure_uuid(int $post_id, \WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!in_array($post->post_type, self::ALL, true)) {
            return;
        }
        if (get_post_meta($post_id, '_ettic_otc_uuid', true)) {
            return;
        }
        update_post_meta($post_id, '_ettic_otc_uuid', wp_generate_uuid4());
    }

    // ──────────────────────────────────────────────
    // Save Meta
    // ──────────────────────────────────────────────

    public function save_meta(int $post_id, \WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        match ($post->post_type) {
            self::CERTIFICATION => $this->save_cert_meta($post_id),
            self::POLICY        => $this->save_policy_meta($post_id),
            self::SUBPROCESSOR  => $this->save_sub_meta($post_id),
            self::DATA_PRACTICE => $this->save_dp_meta($post_id),
            self::FAQ           => $this->save_faq_meta($post_id),
            default            => null,
        };
    }

    private function save_cert_meta(int $post_id): void {
        if (!isset($_POST['ettic_otc_cert_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ettic_otc_cert_nonce'] ) ), 'ettic_otc_save_cert')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $valid_types = ['certified', 'compliant'];
        $type = sanitize_text_field( wp_unslash( $_POST['ettic_otc_cert_type'] ?? 'compliant' ) );
        update_post_meta($post_id, '_ettic_otc_cert_type', in_array($type, $valid_types, true) ? $type : 'compliant');

        update_post_meta($post_id, '_ettic_otc_cert_issuing_body', sanitize_text_field( wp_unslash( $_POST['ettic_otc_cert_issuing_body'] ?? '' ) ));

        $valid_statuses = ['active', 'in_progress', 'expired'];
        $status = sanitize_text_field( wp_unslash( $_POST['ettic_otc_cert_status'] ?? 'active' ) );
        update_post_meta($post_id, '_ettic_otc_cert_status', in_array($status, $valid_statuses, true) ? $status : 'active');

        update_post_meta($post_id, '_ettic_otc_cert_issue_date', sanitize_text_field( wp_unslash( $_POST['ettic_otc_cert_issue_date'] ?? '' ) ));
        update_post_meta($post_id, '_ettic_otc_cert_expiry_date', sanitize_text_field( wp_unslash( $_POST['ettic_otc_cert_expiry_date'] ?? '' ) ));
        update_post_meta($post_id, '_ettic_otc_cert_badge_id', absint( wp_unslash( $_POST['ettic_otc_cert_badge_id'] ?? 0 ) ));
        update_post_meta($post_id, '_ettic_otc_cert_artifact_id', absint( wp_unslash( $_POST['ettic_otc_cert_artifact_id'] ?? 0 ) ));
        update_post_meta($post_id, '_ettic_otc_cert_description', sanitize_textarea_field( wp_unslash( $_POST['ettic_otc_cert_description'] ?? '' ) ));
    }

    private function save_policy_meta(int $post_id): void {
        if (!isset($_POST['ettic_otc_policy_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ettic_otc_policy_nonce'] ) ), 'ettic_otc_save_policy')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Version bump — only when explicitly requested by the user.
        if (!empty($_POST['ettic_otc_publish_new_version'])) {
            $post = get_post($post_id);
            if ($post && 'publish' === $post->post_status) {
                $summary = sanitize_text_field( wp_unslash( $_POST['ettic_otc_version_summary'] ?? '' ) );
                Ettic_OTC_Version::bump_version($post_id, $summary);
            }
        }

        // Ensure first-publish posts get v1.
        Ettic_OTC_Version::ensure_initial_version($post_id);

        $ref_id = sanitize_text_field( wp_unslash( $_POST['ettic_otc_policy_ref_id'] ?? '' ) );
        // Collapse internal whitespace runs so "POL  012" becomes "POL 012" on save.
        $ref_id = trim((string) preg_replace('/\s+/u', ' ', $ref_id));
        if ($ref_id !== '') {
            update_post_meta($post_id, '_ettic_otc_policy_ref_id', $ref_id);
        } else {
            delete_post_meta($post_id, '_ettic_otc_policy_ref_id');
        }

        $valid_categories = ['security', 'privacy', 'compliance', 'operational', 'other'];
        $category = sanitize_text_field( wp_unslash( $_POST['ettic_otc_policy_category'] ?? 'other' ) );
        update_post_meta($post_id, '_ettic_otc_policy_category', in_array($category, $valid_categories, true) ? $category : 'other');

        update_post_meta($post_id, '_ettic_otc_policy_effective_date', sanitize_text_field( wp_unslash( $_POST['ettic_otc_policy_effective_date'] ?? '' ) ));
        update_post_meta($post_id, '_ettic_otc_policy_review_date', sanitize_text_field( wp_unslash( $_POST['ettic_otc_policy_review_date'] ?? '' ) ));
        update_post_meta($post_id, '_ettic_otc_policy_sort_order', absint( wp_unslash( $_POST['ettic_otc_policy_sort_order'] ?? 0 ) ));

        // Framework citations — repeater array, shape mirrors ettic_otc_dp_data_items.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is individually sanitized below.
        $raw_citations = wp_unslash( $_POST['ettic_otc_policy_citations'] ?? [] );
        $citations = [];
        if (is_array($raw_citations)) {
            foreach ($raw_citations as $entry) {
                $name = sanitize_text_field(is_array($entry) ? ($entry['name'] ?? '') : (string) $entry);
                $name = trim((string) preg_replace('/\s+/u', ' ', $name));
                if ($name !== '') {
                    $citations[] = ['name' => $name];
                }
            }
        }
        if (!empty($citations)) {
            update_post_meta($post_id, '_ettic_otc_policy_citations', $citations);
        } else {
            delete_post_meta($post_id, '_ettic_otc_policy_citations');
        }

        // PDF attachment — only accept a real attachment the user can read.
        $attachment_id = absint( wp_unslash( $_POST['ettic_otc_policy_attachment_id'] ?? 0 ) );
        if ($attachment_id > 0 && get_post_type($attachment_id) === 'attachment') {
            update_post_meta($post_id, '_ettic_otc_policy_attachment_id', $attachment_id);
        } else {
            delete_post_meta($post_id, '_ettic_otc_policy_attachment_id');
        }
    }

    private function save_sub_meta(int $post_id): void {
        if (!isset($_POST['ettic_otc_sub_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ettic_otc_sub_nonce'] ) ), 'ettic_otc_save_sub')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_ettic_otc_sub_purpose', sanitize_textarea_field( wp_unslash( $_POST['ettic_otc_sub_purpose'] ?? '' ) ));
        update_post_meta($post_id, '_ettic_otc_sub_data_processed', sanitize_textarea_field( wp_unslash( $_POST['ettic_otc_sub_data_processed'] ?? '' ) ));
        update_post_meta($post_id, '_ettic_otc_sub_country', sanitize_text_field( wp_unslash( $_POST['ettic_otc_sub_country'] ?? '' ) ));
        update_post_meta($post_id, '_ettic_otc_sub_website', esc_url_raw( wp_unslash( $_POST['ettic_otc_sub_website'] ?? '' ) ));
        update_post_meta($post_id, '_ettic_otc_sub_dpa_signed', !empty($_POST['ettic_otc_sub_dpa_signed']));
    }

    private function save_dp_meta(int $post_id): void {
        if (!isset($_POST['ettic_otc_dp_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ettic_otc_dp_nonce'] ) ), 'ettic_otc_save_dp')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Data Items (repeater array).
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is individually sanitized below.
        $raw_items = wp_unslash( $_POST['ettic_otc_dp_data_items'] ?? [] );
        $data_items = [];
        if (is_array($raw_items)) {
            foreach ($raw_items as $item) {
                $name = sanitize_text_field($item['name'] ?? '');
                if ($name !== '') {
                    $data_items[] = ['name' => $name];
                }
            }
        }
        update_post_meta($post_id, '_ettic_otc_dp_data_items', $data_items);

        // Purpose.
        update_post_meta($post_id, '_ettic_otc_dp_purpose', sanitize_textarea_field( wp_unslash( $_POST['ettic_otc_dp_purpose'] ?? '' ) ));

        // Legal Basis.
        $valid_bases = ['consent', 'contract', 'legitimate_interest', 'legal_obligation', 'vital_interest', 'public_interest'];
        $basis = sanitize_text_field( wp_unslash( $_POST['ettic_otc_dp_legal_basis'] ?? '' ) );
        update_post_meta($post_id, '_ettic_otc_dp_legal_basis', in_array($basis, $valid_bases, true) ? $basis : '');

        // Retention Period.
        update_post_meta($post_id, '_ettic_otc_dp_retention_period', sanitize_text_field( wp_unslash( $_POST['ettic_otc_dp_retention_period'] ?? '' ) ));

        // Shared With (repeater array).
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is individually sanitized below.
        $raw_shared = wp_unslash( $_POST['ettic_otc_dp_shared_with'] ?? [] );
        $shared_items = [];
        if (is_array($raw_shared)) {
            foreach ($raw_shared as $entry) {
                $name = sanitize_text_field($entry['name'] ?? '');
                if ($name !== '') {
                    $shared_items[] = ['name' => $name];
                }
            }
        }
        update_post_meta($post_id, '_ettic_otc_dp_shared_with', $shared_items);

        // Sort order.
        update_post_meta($post_id, '_ettic_otc_dp_sort_order', absint( wp_unslash( $_POST['ettic_otc_dp_sort_order'] ?? 0 ) ));

        // Property flags — the AI assistant reports these verbatim. Unchecked
        // means explicit "No", not "unknown", so we always write the value.
        update_post_meta($post_id, '_ettic_otc_dp_collected', !empty($_POST['ettic_otc_dp_collected']));
        update_post_meta($post_id, '_ettic_otc_dp_stored',    !empty($_POST['ettic_otc_dp_stored']));
        update_post_meta($post_id, '_ettic_otc_dp_shared',    !empty($_POST['ettic_otc_dp_shared']));
        update_post_meta($post_id, '_ettic_otc_dp_sold',      !empty($_POST['ettic_otc_dp_sold']));
        update_post_meta($post_id, '_ettic_otc_dp_encrypted', !empty($_POST['ettic_otc_dp_encrypted']));

        // Clean up legacy meta keys.
        delete_post_meta($post_id, '_ettic_otc_dp_data_type');
        delete_post_meta($post_id, '_ettic_otc_dp_collection_method');
        delete_post_meta($post_id, '_ettic_otc_dp_is_sensitive');
    }

    // ──────────────────────────────────────────────
    // Admin Columns
    // ──────────────────────────────────────────────

    // Certifications
    public function cert_columns(array $columns): array {
        $new = [];
        $new['cb']    = $columns['cb'];
        $new['title'] = $columns['title'];
        $new['ettic_otc_issuing_body'] = __('Issuing Body', 'open-trust-center-by-ettic');
        $new['ettic_otc_status']       = __('Status', 'open-trust-center-by-ettic');
        $new['ettic_otc_expiry']       = __('Expiry Date', 'open-trust-center-by-ettic');
        $new['date']  = $columns['date'];
        return $new;
    }

    public function cert_column_content(string $column, int $post_id): void {
        match ($column) {
            'ettic_otc_issuing_body' => print(esc_html(get_post_meta($post_id, '_ettic_otc_cert_issuing_body', true) ?: '—')),
            'ettic_otc_status'       => (function () use ($post_id): void {
                $status = get_post_meta($post_id, '_ettic_otc_cert_status', true) ?: 'active';
                $type   = get_post_meta($post_id, '_ettic_otc_cert_type', true) ?: 'compliant';
                $labels = $type === 'compliant'
                    ? Ettic_OTC_Render::cert_aligned_status_labels()
                    : Ettic_OTC_Render::cert_status_labels();
                $swatch = match ($status) {
                    'active'      => 'background:#dcfce7;color:#166534',
                    'in_progress' => 'background:#fef9c3;color:#854d0e',
                    'expired'     => 'background:#f3f4f6;color:#6b7280',
                    default       => '',
                };
                printf(
                    '<span class="ot-pill ot-pill--%1$s" style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;%2$s">%3$s</span>',
                    esc_attr($status),
                    esc_attr($swatch),
                    esc_html($labels[$status] ?? '')
                );
            })(),
            'ettic_otc_expiry'       => print(esc_html(get_post_meta($post_id, '_ettic_otc_cert_expiry_date', true) ?: '—')),
            default           => null,
        };
    }

    // Policies
    public function policy_columns(array $columns): array {
        $new = [];
        $new['cb']    = $columns['cb'];
        $new['title'] = $columns['title'];
        $new['ettic_otc_ref_id']   = __('ID', 'open-trust-center-by-ettic');
        $new['ettic_otc_category'] = __('Category', 'open-trust-center-by-ettic');
        $new['ettic_otc_version']  = __('Version', 'open-trust-center-by-ettic');
        $new['ettic_otc_pdf']      = __('PDF', 'open-trust-center-by-ettic');
        $new['date']  = $columns['date'];
        return $new;
    }

    public function policy_column_content(string $column, int $post_id): void {
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Match arms emit either hard-coded HTML or values already passed through esc_html(); PHPCS misreads the IIFE/match-expression syntax.
        match ($column) {
            'ettic_otc_ref_id'   => (function () use ($post_id): void {
                $ref = (string) get_post_meta($post_id, '_ettic_otc_policy_ref_id', true);
                if ($ref === '') {
                    print '<span style="color:#9ca3af">—</span>';
                    return;
                }
                printf('<code style="font-size:11px;background:#f3f4f6;padding:2px 6px;border-radius:3px">%s</code>', esc_html($ref));
            })(),
            'ettic_otc_category' => print(esc_html(Ettic_OTC_Render::policy_category_labels()[get_post_meta($post_id, '_ettic_otc_policy_category', true) ?: 'other'] ?? '')),
            'ettic_otc_version'  => printf('<span class="ot-version-badge">v%s</span>', esc_html((string) ((int) get_post_meta($post_id, '_ettic_otc_version', true) ?: 1))),
            'ettic_otc_pdf'      => print(((int) get_post_meta($post_id, '_ettic_otc_policy_attachment_id', true)) > 0 ? '<span title="PDF attached" style="color:#16a34a">&#10003;</span>' : '<span style="color:#d1d5db">—</span>'),
            default       => null,
        };
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    // Subprocessors
    public function sub_columns(array $columns): array {
        $new = [];
        $new['cb']    = $columns['cb'];
        $new['title'] = $columns['title'];
        $new['ettic_otc_purpose'] = __('Purpose', 'open-trust-center-by-ettic');
        $new['ettic_otc_country'] = __('Location', 'open-trust-center-by-ettic');
        $new['ettic_otc_dpa']     = __('DPA', 'open-trust-center-by-ettic');
        $new['date']  = $columns['date'];
        return $new;
    }

    public function sub_column_content(string $column, int $post_id): void {
        match ($column) {
            'ettic_otc_purpose' => print(esc_html(wp_trim_words(get_post_meta($post_id, '_ettic_otc_sub_purpose', true) ?: '', 10))),
            'ettic_otc_country' => print(esc_html(get_post_meta($post_id, '_ettic_otc_sub_country', true) ?: '—')),
            'ettic_otc_dpa'     => print((bool) get_post_meta($post_id, '_ettic_otc_sub_dpa_signed', true) ? '<span style="color:#16a34a">&#10003;</span>' : '—'),
            default      => null,
        };
    }

    // Data Practices
    public function dp_columns(array $columns): array {
        $new = [];
        $new['cb']          = $columns['cb'];
        $new['title']       = $columns['title'];
        $new['ettic_otc_dp_items'] = __('Data Items', 'open-trust-center-by-ettic');
        $new['ettic_otc_dp_sort']  = __('Order', 'open-trust-center-by-ettic');
        $new['date']        = $columns['date'];
        return $new;
    }

    public function dp_column_content(string $column, int $post_id): void {
        match ($column) {
            'ettic_otc_dp_items' => print(esc_html((string) count((array) (get_post_meta($post_id, '_ettic_otc_dp_data_items', true) ?: [])))),
            'ettic_otc_dp_sort'  => print(esc_html((string) ((int) get_post_meta($post_id, '_ettic_otc_dp_sort_order', true)))),
            default       => null,
        };
    }

    // ── FAQ meta box ──

    public function render_faq_meta_box(\WP_Post $post): void {
        wp_nonce_field('ettic_otc_save_faq', 'ettic_otc_faq_nonce');

        $policy_id = (int) get_post_meta($post->ID, '_ettic_otc_faq_related_policy', true);

        $policies = get_posts([
            'post_type'      => self::POLICY,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        ?>
        <div class="ot-meta-field">
            <label for="ettic_otc_faq_related_policy"><?php esc_html_e('Related Policy', 'open-trust-center-by-ettic'); ?></label>
            <select id="ettic_otc_faq_related_policy" name="ettic_otc_faq_related_policy" style="width:100%">
                <option value="0"><?php esc_html_e('— None —', 'open-trust-center-by-ettic'); ?></option>
                <?php foreach ($policies as $policy): ?>
                    <option value="<?php echo esc_attr((string) $policy->ID); ?>" <?php selected($policy_id, $policy->ID); ?>><?php echo esc_html($policy->post_title); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Optional — link this answer to a published policy for deeper context.', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <div class="ot-meta-field">
            <p class="description">
                <strong><?php esc_html_e('Sort order:', 'open-trust-center-by-ettic'); ?></strong>
                <?php esc_html_e('Use the Page Attributes box below (Order field) to control FAQ order. Lower numbers appear first.', 'open-trust-center-by-ettic'); ?>
            </p>
        </div>
        <?php
    }

    private function save_faq_meta(int $post_id): void {
        if (!isset($_POST['ettic_otc_faq_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ettic_otc_faq_nonce'] ) ), 'ettic_otc_save_faq')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $related = absint( wp_unslash( $_POST['ettic_otc_faq_related_policy'] ?? 0 ) );
        if ($related > 0 && get_post_type($related) === self::POLICY) {
            update_post_meta($post_id, '_ettic_otc_faq_related_policy', $related);
        } else {
            delete_post_meta($post_id, '_ettic_otc_faq_related_policy');
        }
    }

    // FAQs
    public function faq_columns(array $columns): array {
        $new = [];
        $new['cb']           = $columns['cb'];
        $new['title']        = $columns['title'];
        $new['ettic_otc_faq_order'] = __('Order', 'open-trust-center-by-ettic');
        $new['date']         = $columns['date'];
        return $new;
    }

    public function faq_column_content(string $column, int $post_id): void {
        match ($column) {
            'ettic_otc_faq_order' => print(esc_html((string) ((int) (get_post($post_id)->menu_order ?? 0)))),
            default        => null,
        };
    }
}
