<?php
/**
 * Settings API registration, field rendering, sanitization, and the
 * settings-page wrapper that hosts the General / Contact / AI tabs.
 *
 * Owns the entire WordPress Settings API surface for Ettic_OTC:
 * register_setting() with a sanitize_callback, every add_settings_section
 * and add_settings_field call, the eight per-type field renderers, and
 * the schema-driven sanitize cascade that keeps cross-tab saves shape-
 * stable.
 *
 * Bootstrapped by Ettic_OTC_Admin's constructor; subscribes its own
 * admin_init hook for register_settings(). The settings menu page in
 * Ettic_OTC_Admin::register_menu() points its callback directly at this
 * class's render_settings_page().
 *
 * Also owns save_settings_raw() — the skip-sanitize writer used by the
 * AI key-save handlers and the Questions toggle-logging handler. It
 * lives here because it needs the same `[$this, 'sanitize_settings']`
 * callable to remove/re-add the filter as the registration site.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ettic_OTC_Admin_Settings {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
    }

    // ──────────────────────────────────────────────
    // Settings API
    // ──────────────────────────────────────────────

    public function register_settings(): void {
        register_setting('ettic_otc_settings_group', 'ettic_otc_settings', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default'           => Ettic_OTC::defaults(),
        ]);

        // ── General tab ──────────────────────────────────────────────
        add_settings_section(
            'ettic_otc_general',
            __('General Settings', 'open-trust-center-by-ettic'),
            fn() => null,
            'ettic-otc-settings-general'
        );

        $this->add_field('endpoint_slug', __('Endpoint Slug', 'open-trust-center-by-ettic'), 'render_text_field', 'ettic_otc_general', 'ettic-otc-settings-general', [
            'description' => __('The URL path for your trust center (e.g., "trust-center" = yoursite.com/trust-center/).', 'open-trust-center-by-ettic'),
        ]);

        $this->add_field('page_title', __('Page Title', 'open-trust-center-by-ettic'), 'render_text_field', 'ettic_otc_general', 'ettic-otc-settings-general');

        $this->add_field('company_name', __('Company Name', 'open-trust-center-by-ettic'), 'render_text_field', 'ettic_otc_general', 'ettic-otc-settings-general');

        $this->add_field('tagline', __('Tagline', 'open-trust-center-by-ettic'), 'render_textarea_field', 'ettic_otc_general', 'ettic-otc-settings-general', [
            'description' => __('A short description displayed below the company name in the hero section.', 'open-trust-center-by-ettic'),
        ]);

        // Branding section (General tab).
        add_settings_section(
            'ettic_otc_branding',
            __('Branding', 'open-trust-center-by-ettic'),
            fn() => null,
            'ettic-otc-settings-general'
        );

        $this->add_field('logo_id', __('Logo', 'open-trust-center-by-ettic'), 'render_logo_field', 'ettic_otc_branding', 'ettic-otc-settings-general');
        $this->add_field('avatar_id', __('AI Avatar', 'open-trust-center-by-ettic'), 'render_avatar_field', 'ettic_otc_branding', 'ettic-otc-settings-general');

        $this->add_field('accent_color', __('Accent Color', 'open-trust-center-by-ettic'), 'render_color_field', 'ettic_otc_branding', 'ettic-otc-settings-general', [
            'description' => __('Used for buttons, links, and highlights. Choose a color that matches your brand.', 'open-trust-center-by-ettic'),
        ]);

        $this->add_field('show_powered_by', __('Credit Link', 'open-trust-center-by-ettic'), 'render_show_powered_by_field', 'ettic_otc_branding', 'ettic-otc-settings-general');

        // Sections visibility (General tab).
        add_settings_section(
            'ettic_otc_sections',
            __('Visible Sections', 'open-trust-center-by-ettic'),
            fn() => print('<p>' . esc_html__('Choose which sections to display on the trust center.', 'open-trust-center-by-ettic') . '</p>'),
            'ettic-otc-settings-general'
        );

        $this->add_field('sections_visible', __('Sections', 'open-trust-center-by-ettic'), 'render_sections_field', 'ettic_otc_sections', 'ettic-otc-settings-general');

        // ── Contact tab ──────────────────────────────────────────────
        // Fields are optional — the frontend block renders only when at least one field below is populated.
        add_settings_section(
            'ettic_otc_contact',
            __('Get in touch', 'open-trust-center-by-ettic'),
            fn() => print('<p>' . esc_html__('Publish a dark-accent "Get in touch" block on the trust center. Every field is optional — the block only appears if at least one is filled in.', 'open-trust-center-by-ettic') . '</p>'),
            'ettic-otc-settings-contact'
        );

        $this->add_field('company_description', __('Company Description', 'open-trust-center-by-ettic'), 'render_textarea_field', 'ettic_otc_contact', 'ettic-otc-settings-contact', [
            'description' => __('Two or three sentences describing what the company does. Rendered under the "Get in touch" section title.', 'open-trust-center-by-ettic'),
        ]);

        $this->add_field('dpo_name', __('DPO Name', 'open-trust-center-by-ettic'), 'render_text_field', 'ettic_otc_contact', 'ettic-otc-settings-contact', [
            'description' => __('Data Protection Officer name. Required under GDPR for many organisations.', 'open-trust-center-by-ettic'),
        ]);

        $this->add_field('dpo_email', __('DPO Email', 'open-trust-center-by-ettic'), 'render_email_field', 'ettic_otc_contact', 'ettic-otc-settings-contact', [
            'description' => __('Dedicated DPO mailbox. Rendered as a mailto link.', 'open-trust-center-by-ettic'),
        ]);

        $this->add_field('security_email', __('Security Contact Email', 'open-trust-center-by-ettic'), 'render_email_field', 'ettic_otc_contact', 'ettic-otc-settings-contact', [
            'description' => __('For vulnerability reports and security questions. Often separate from the DPO.', 'open-trust-center-by-ettic'),
        ]);

        $this->add_field('contact_form_url', __('Contact Form URL', 'open-trust-center-by-ettic'), 'render_url_field', 'ettic_otc_contact', 'ettic-otc-settings-contact', [
            'description' => __('Optional link to a gated contact form.', 'open-trust-center-by-ettic'),
        ]);

        $this->add_field('contact_address', __('Mailing Address', 'open-trust-center-by-ettic'), 'render_textarea_field', 'ettic_otc_contact', 'ettic-otc-settings-contact', [
            'description' => __('Postal address for formal GDPR / legal notices.', 'open-trust-center-by-ettic'),
        ]);

        $this->add_field('pgp_key_url', __('PGP Public Key URL', 'open-trust-center-by-ettic'), 'render_url_field', 'ettic_otc_contact', 'ettic-otc-settings-contact', [
            'description' => __('Optional link to your security team\'s PGP public key.', 'open-trust-center-by-ettic'),
        ]);

        $this->add_field('company_registration', __('Company Registration Number', 'open-trust-center-by-ettic'), 'render_text_field', 'ettic_otc_contact', 'ettic-otc-settings-contact', [
            'description' => __('KvK (NL), Companies House (UK), Handelsregister (DE), EIN (US), or equivalent business registration.', 'open-trust-center-by-ettic'),
        ]);

        $this->add_field('vat_number', __('VAT / Tax ID', 'open-trust-center-by-ettic'), 'render_text_field', 'ettic_otc_contact', 'ettic-otc-settings-contact', [
            'description' => __('VAT number, sales-tax ID, or equivalent international tax identifier.', 'open-trust-center-by-ettic'),
        ]);

    }

    private function add_field(string $key, string $title, string $callback, string $section, string $page = 'ettic-otc-settings-general', array $extra = []): void {
        add_settings_field(
            'ettic_otc_' . $key,
            $title,
            [$this, $callback],
            $page,
            $section,
            array_merge(['key' => $key], $extra)
        );
    }

    // ──────────────────────────────────────────────
    // Field renderers
    // ──────────────────────────────────────────────

    public function render_text_field(array $args): void {
        $this->render_input_field('text', $args);
    }

    public function render_email_field(array $args): void {
        $this->render_input_field('email', $args, ['autocomplete' => 'off']);
    }

    public function render_url_field(array $args): void {
        $this->render_input_field('url', $args, ['placeholder' => 'https://', 'autocomplete' => 'off']);
    }

    public function render_textarea_field(array $args): void {
        $this->render_input_field('textarea', $args);
    }

    /**
     * Shared renderer for the Settings API string-input field family.
     * One unified path so escaping rules and id/name conventions can't drift
     * between text/email/url/textarea variants.
     *
     * @param 'text'|'email'|'url'|'textarea' $type
     * @param array{key:string, description?:string} $args
     * @param array<string,string> $extra_attrs
     */
    private function render_input_field(string $type, array $args, array $extra_attrs = []): void {
        $settings = Ettic_OTC::get_settings();
        $key      = $args['key'];
        $value    = $settings[$key] ?? '';

        if ($type === 'textarea') {
            echo '<textarea id="ettic_otc_' . esc_attr($key) . '" name="ettic_otc_settings[' . esc_attr($key) . ']" rows="3" class="large-text"';
        } else {
            echo '<input type="' . esc_attr($type) . '" id="ettic_otc_' . esc_attr($key) . '" name="ettic_otc_settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" class="regular-text"';
        }
        foreach ($extra_attrs as $attr_name => $attr_val) {
            echo ' ' . esc_attr($attr_name) . '="' . esc_attr($attr_val) . '"';
        }
        if ($type === 'textarea') {
            echo '>' . esc_textarea($value) . '</textarea>';
        } else {
            echo '>';
        }

        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function render_color_field(array $args): void {
        $settings     = Ettic_OTC::get_settings();
        $value        = $settings['accent_color'] ?? '#2563EB';
        $force_exact  = !empty($settings['accent_force_exact']);
        printf(
            '<input type="text" id="ettic_otc_accent_color" name="ettic_otc_settings[accent_color]" value="%s" class="ettic-otc-color-picker" data-default-color="#2563EB">',
            esc_attr($value)
        );
        ?>
        <div id="ettic-otc-accent-warning" class="ettic-otc-accent-warning<?php echo esc_attr($force_exact ? ' ettic-otc-accent-warning--override' : ''); ?>" hidden>
            <svg class="ettic-otc-accent-warning__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div class="ettic-otc-accent-warning__body">
                <strong class="ettic-otc-accent-warning__heading ettic-otc-accent-warning__heading--auto"><?php esc_html_e('Low contrast on white backgrounds', 'open-trust-center-by-ettic'); ?></strong>
                <strong class="ettic-otc-accent-warning__heading ettic-otc-accent-warning__heading--override"><?php esc_html_e('Using your exact color on white backgrounds', 'open-trust-center-by-ettic'); ?></strong>

                <p class="ettic-otc-accent-warning__copy ettic-otc-accent-warning__copy--auto">
                    <?php esc_html_e('Your chosen color is too light for buttons, links, and borders on white sections. On those surfaces Ettic_OTC will use a darker, on-brand variant:', 'open-trust-center-by-ettic'); ?>
                </p>
                <p class="ettic-otc-accent-warning__copy ettic-otc-accent-warning__copy--override">
                    <?php esc_html_e("You've chosen to keep your exact color on white backgrounds. Buttons, links, and borders in those sections may be hard to read.", 'open-trust-center-by-ettic'); ?>
                </p>

                <div class="ettic-otc-accent-warning__preview">
                    <span class="ettic-otc-accent-warning__swatch ettic-otc-accent-warning__swatch--chosen" aria-hidden="true"></span>
                    <code class="ettic-otc-accent-warning__hex ettic-otc-accent-warning__hex--chosen"></code>
                    <span class="ettic-otc-accent-warning__arrow" aria-hidden="true">→</span>
                    <span class="ettic-otc-accent-warning__swatch ettic-otc-accent-warning__swatch--adjusted" aria-hidden="true"></span>
                    <code class="ettic-otc-accent-warning__hex ettic-otc-accent-warning__hex--adjusted"></code>
                </div>

                <p class="ettic-otc-accent-warning__note ettic-otc-accent-warning__note--auto">
                    <?php esc_html_e('The hero and navigation still use your exact color.', 'open-trust-center-by-ettic'); ?>
                </p>

                <label class="ettic-otc-accent-warning__override">
                    <input
                        type="checkbox"
                        id="ettic_otc_accent_force_exact"
                        name="ettic_otc_settings[accent_force_exact]"
                        value="1"
                        <?php checked($force_exact); ?>
                    >
                    <span><?php esc_html_e('Use my exact color anyway — skip the contrast adjustment.', 'open-trust-center-by-ettic'); ?></span>
                </label>
            </div>
        </div>
        <?php
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function render_logo_field(array $args): void {
        $this->render_media_field(
            'logo_id',
            __('Select Logo', 'open-trust-center-by-ettic'),
            __('Used in the hero and sticky nav. A white version is recommended — it sits on a dark background.', 'open-trust-center-by-ettic')
        );
    }

    public function render_avatar_field(array $args): void {
        $this->render_media_field(
            'avatar_id',
            __('Select Avatar', 'open-trust-center-by-ettic'),
            __('Square image used as the avatar on AI chat responses. Use a colored background with a light or dark favicon or logo on top.', 'open-trust-center-by-ettic')
        );
    }

    private function render_media_field(string $key, string $button_label, string $description): void {
        $settings  = Ettic_OTC::get_settings();
        $media_id  = (int) ($settings[$key] ?? 0);
        $media_url = $media_id ? wp_get_attachment_image_url($media_id, 'medium') : '';
        ?>
        <div class="ettic-otc-logo-upload" data-ettic-otc-media-field>
            <div class="ettic-otc-logo-preview<?php echo esc_attr($media_url ? '' : ' ettic-otc-hidden'); ?>">
                <img src="<?php echo esc_url($media_url); ?>" alt="" style="max-width:200px;max-height:80px">
            </div>
            <input type="hidden" name="ettic_otc_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $media_id); ?>" data-ettic-otc-media-input>
            <button type="button" class="button" data-ettic-otc-media-upload><?php echo esc_html($button_label); ?></button>
            <button type="button" class="button<?php echo esc_attr($media_id ? '' : ' ettic-otc-hidden'); ?>" data-ettic-otc-media-remove><?php esc_html_e('Remove', 'open-trust-center-by-ettic'); ?></button>
            <p class="description"><?php echo esc_html($description); ?></p>
        </div>
        <?php
    }

    public function render_show_powered_by_field(array $args): void {
        $settings = Ettic_OTC::get_settings();
        $checked  = !empty($settings['show_powered_by']);
        printf(
            '<label><input type="checkbox" id="ettic_otc_show_powered_by" name="ettic_otc_settings[show_powered_by]" value="1" %s> %s</label>',
            checked($checked, true, false),
            esc_html__('Show a "Powered by Open Trust Center by Ettic" credit in the trust center footer.', 'open-trust-center-by-ettic')
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('Off by default. Public credits are opt-in.', 'open-trust-center-by-ettic')
        );
    }

    public function render_sections_field(array $args): void {
        $settings = Ettic_OTC::get_settings();
        $visible  = $settings['sections_visible'] ?? [];

        $sections = [
            'certifications' => __('Certifications & Compliance', 'open-trust-center-by-ettic'),
            'policies'       => __('Policies', 'open-trust-center-by-ettic'),
            'subprocessors'  => __('Subprocessors', 'open-trust-center-by-ettic'),
            'data_practices' => __('Data Practices', 'open-trust-center-by-ettic'),
            'faqs'           => __('FAQs', 'open-trust-center-by-ettic'),
            'contact'        => __('Contact & DPO', 'open-trust-center-by-ettic'),
        ];

        foreach ($sections as $key => $label) {
            $checked = !empty($visible[$key]);
            printf(
                '<label style="display:block;margin-bottom:8px"><input type="checkbox" name="ettic_otc_settings[sections_visible][%1$s]" value="1" %2$s> %3$s</label>',
                esc_attr($key),
                checked($checked, true, false),
                esc_html($label)
            );
        }
    }

    // ──────────────────────────────────────────────
    // Sanitization
    // ──────────────────────────────────────────────

    public function sanitize_settings(mixed $input): array {
        if (!is_array($input)) {
            return Ettic_OTC::defaults();
        }

        $old = Ettic_OTC::get_settings();
        $sanitized = [];

        // Schema-driven dispatch. Each tab's form carries a save sentinel
        // (`__<tab>_tab_save`); fields belonging to the active tab come from
        // the form and run through their sanitize callback, while other tabs'
        // fields are pulled from $old. Either way the same sanitize closure
        // runs as a type-coercion guard, so saving one tab never clobbers
        // another and the produced array is always shape-stable.
        foreach (self::settings_schema() as $key => $spec) {
            $sentinel  = '__' . $spec['tab'] . '_tab_save';
            $from_form = !empty($input[$sentinel]);
            $value     = $from_form ? ($input[$key] ?? null) : ($old[$key] ?? $spec['default']);
            $sanitized[$key] = ($spec['sanitize'])($value, $old[$key] ?? $spec['default']);
        }

        // Server-controlled fields — set by the key-save / model-refresh
        // handlers, never sourced from a settings form.
        $sanitized['ai_enabled']              = !empty($old['ai_enabled']);
        $sanitized['ai_provider']             = sanitize_key($old['ai_provider'] ?? '');
        $sanitized['ai_model_list_cached_at'] = (int) ($old['ai_model_list_cached_at'] ?? 0);

        // Active-model snapshot — server-controlled; carried from $old so a
        // form save can't spoof the label. When the AI tab changes ai_model,
        // re-snapshot from the live model cache so the new selection's label
        // follows the id.
        $sanitized['ai_model_display_name'] = (string) ($old['ai_model_display_name'] ?? '');
        $sanitized['ai_model_recommended']  = !empty($old['ai_model_recommended']);
        if (($sanitized['ai_model'] ?? '') !== ($old['ai_model'] ?? '')) {
            $snap = Ettic_OTC_Admin_AI::instance()->snapshot_for_provider(
                $sanitized['ai_provider'],
                (string) ($sanitized['ai_model'] ?? '')
            );
            if ($snap !== null) {
                $sanitized['ai_model_display_name'] = $snap['display_name'];
                $sanitized['ai_model_recommended']  = $snap['recommended'];
            }
        }

        // Per-site salt — written out-of-band by Ettic_OTC_Chat_Budget::site_salt().
        // Carry forward byte-for-byte so saving settings doesn't force a
        // regeneration (which would invalidate all in-flight rate-limit
        // hashes and Turnstile bypass transients).
        if (isset($old['ettic_otc_site_salt']) && is_string($old['ettic_otc_site_salt'])) {
            $sanitized['ettic_otc_site_salt'] = $old['ettic_otc_site_salt'];
        }

        // Flag rewrite flush if slug changed.
        if ($sanitized['endpoint_slug'] !== ($old['endpoint_slug'] ?? '')) {
            set_transient('ettic_otc_flush_rewrite', true);
        }

        return $sanitized;
    }

    /**
     * Settings schema: per-key {tab, default, sanitize}. The sanitize
     * callback receives `($value, $old_value)` and must be idempotent on
     * its own output — it runs both on form input (active tab) and on
     * already-stored values (inactive tab) without intermediate type drift.
     *
     * Adding a new setting is a single entry here — no edits to the
     * tab-dispatch loop, no tracking which else-branch needs updating.
     *
     * Three settings are deliberately excluded:
     *   - `ai_enabled`, `ai_provider`, `ai_model_list_cached_at` — server-
     *     controlled by the key-save handler.
     *   - `ettic_otc_site_salt` — written out-of-band by Chat_Budget.
     *
     * @return array<string, array{tab:string, default:mixed, sanitize:callable}>
     */
    private static function settings_schema(): array {
        // Shared sanitizers. All idempotent on already-sanitized data so the
        // inactive-tab path (which feeds previously-stored values back through
        // the same callback) doesn't drift on type or shape.
        $string   = static fn($v): string => sanitize_text_field((string) ($v ?? ''));
        $textarea = static fn($v): string => sanitize_textarea_field((string) ($v ?? ''));
        $email    = static fn($v): string => sanitize_email((string) ($v ?? ''));
        $url      = static fn($v): string => esc_url_raw((string) ($v ?? ''));
        $bool     = static fn($v): bool   => !empty($v);
        $abs_int  = static fn($v): int    => absint($v ?? 0);

        // Bounded-int factory: clamps to [$min, $max], defaulting missing
        // values to $default before clamping.
        $bounded_int = static fn(int $min, int $max, int $default): callable =>
            static fn($v): int => max($min, min($max, absint($v ?? $default)));

        // Section visibility — the form sends nested array keys; the inactive
        // path may receive an already-flat associative array of bools. Either
        // shape collapses to the same structured set of bools.
        $sections_default = [
            'certifications' => true,
            'policies'       => true,
            'subprocessors'  => true,
            'data_practices' => true,
            'faqs'           => true,
            'contact'        => true,
        ];

        return [
            // ── General tab ──
            'endpoint_slug' => [
                'tab' => 'general',
                'default' => Ettic_OTC::DEFAULT_ENDPOINT_SLUG,
                'sanitize' => static fn($v) => sanitize_title((string) ($v ?? '')) ?: Ettic_OTC::DEFAULT_ENDPOINT_SLUG,
            ],
            'page_title'         => ['tab' => 'general', 'default' => '',        'sanitize' => $string],
            'company_name'       => ['tab' => 'general', 'default' => '',        'sanitize' => $string],
            'tagline'            => ['tab' => 'general', 'default' => '',        'sanitize' => $textarea],
            'logo_id'            => ['tab' => 'general', 'default' => 0,         'sanitize' => $abs_int],
            'avatar_id'          => ['tab' => 'general', 'default' => 0,         'sanitize' => $abs_int],
            'accent_color'       => [
                'tab' => 'general',
                'default' => '#2563EB',
                'sanitize' => static fn($v) => sanitize_hex_color((string) ($v ?? '#2563EB')) ?: '#2563EB',
            ],
            'accent_force_exact' => ['tab' => 'general', 'default' => false,     'sanitize' => $bool],
            'show_powered_by'    => ['tab' => 'general', 'default' => false,     'sanitize' => $bool],
            'sections_visible' => [
                'tab' => 'general',
                'default' => $sections_default,
                'sanitize' => static fn($v) => is_array($v) ? [
                    'certifications' => !empty($v['certifications']),
                    'policies'       => !empty($v['policies']),
                    'subprocessors'  => !empty($v['subprocessors']),
                    'data_practices' => !empty($v['data_practices']),
                    'faqs'           => !empty($v['faqs']),
                    'contact'        => !empty($v['contact']),
                ] : $sections_default,
            ],

            // ── Contact tab ──
            'company_description'  => ['tab' => 'contact', 'default' => '', 'sanitize' => $textarea],
            'dpo_name'             => ['tab' => 'contact', 'default' => '', 'sanitize' => $string],
            'dpo_email'            => ['tab' => 'contact', 'default' => '', 'sanitize' => $email],
            'security_email'       => ['tab' => 'contact', 'default' => '', 'sanitize' => $email],
            'contact_form_url'     => ['tab' => 'contact', 'default' => '', 'sanitize' => $url],
            'contact_address'      => ['tab' => 'contact', 'default' => '', 'sanitize' => $textarea],
            'pgp_key_url'          => ['tab' => 'contact', 'default' => '', 'sanitize' => $url],
            'company_registration' => ['tab' => 'contact', 'default' => '', 'sanitize' => $string],
            'vat_number'           => ['tab' => 'contact', 'default' => '', 'sanitize' => $string],

            // ── AI tab ──
            'ai_model' => ['tab' => 'ai', 'default' => '', 'sanitize' => $string],
            'ai_daily_token_budget' => [
                'tab' => 'ai',
                'default' => Ettic_OTC_Chat_Budget::DEFAULT_DAILY_TOKEN_BUDGET,
                'sanitize' => static fn($v): int => max(0, absint($v ?? Ettic_OTC_Chat_Budget::DEFAULT_DAILY_TOKEN_BUDGET)),
            ],
            'ai_monthly_token_budget' => [
                'tab' => 'ai',
                'default' => Ettic_OTC_Chat_Budget::DEFAULT_MONTHLY_TOKEN_BUDGET,
                'sanitize' => static fn($v): int => max(0, absint($v ?? Ettic_OTC_Chat_Budget::DEFAULT_MONTHLY_TOKEN_BUDGET)),
            ],
            'ai_rate_limit_per_ip' => [
                'tab' => 'ai',
                'default' => Ettic_OTC_Chat_Budget::DEFAULT_RATE_LIMIT_PER_IP,
                'sanitize' => $bounded_int(0, 1000, Ettic_OTC_Chat_Budget::DEFAULT_RATE_LIMIT_PER_IP),
            ],
            'ai_rate_limit_per_session' => [
                'tab' => 'ai',
                'default' => Ettic_OTC_Chat_Budget::DEFAULT_RATE_LIMIT_PER_SESSION,
                'sanitize' => $bounded_int(0, 10000, Ettic_OTC_Chat_Budget::DEFAULT_RATE_LIMIT_PER_SESSION),
            ],
            'ai_max_message_length' => [
                'tab' => 'ai',
                'default' => Ettic_OTC_Chat::DEFAULT_MAX_MESSAGE_LENGTH,
                'sanitize' => $bounded_int(100, 4000, Ettic_OTC_Chat::DEFAULT_MAX_MESSAGE_LENGTH),
            ],
            'ai_contact_url'            => ['tab' => 'ai', 'default' => '',    'sanitize' => $url],
            'ai_show_model_attribution' => ['tab' => 'ai', 'default' => false, 'sanitize' => $bool],
            'ai_logging_enabled'        => ['tab' => 'ai', 'default' => false, 'sanitize' => $bool],
            'ai_turnstile_enabled'      => ['tab' => 'ai', 'default' => false, 'sanitize' => $bool],
            'ai_auto_summarize'         => ['tab' => 'ai', 'default' => true,  'sanitize' => $bool],
            'turnstile_site_key'        => ['tab' => 'ai', 'default' => '',    'sanitize' => $string],
            'turnstile_secret_key'      => [
                'tab' => 'ai',
                'default' => '',
                // sanitize_secret_field is the only callback that needs $old:
                // a real new plaintext gets encrypted; the masked-bullet
                // placeholder + already-encrypted ciphertext both pass through
                // unchanged (the latter via the ot_enc_v1: idempotency guard).
                'sanitize' => static fn($v, $old) => self::sanitize_secret_field((string) ($v ?? ''), (string) ($old ?? '')),
            ],
        ];
    }

    /**
     * Persist a form-submitted secret as libsodium ciphertext.
     *
     * Three input shapes are passed through unchanged:
     *  - empty / masked-bullet placeholder (user didn't change the field) →
     *    return the existing stored ciphertext.
     *  - already-encrypted `ot_enc_v1:` blob (the schema-driven sanitize
     *    feeds the OLD value back through this callback on the inactive-tab
     *    path; without this guard re-sanitization would clobber the
     *    ciphertext) → return as-is.
     *
     * Anything else is text-sanitized and then encrypted via
     * Ettic_OTC_Chat_Secrets, so the option never carries the plaintext.
     */
    private static function sanitize_secret_field(string $new_value, string $old_value): string {
        // Idempotency: already-encrypted ciphertext passes through. Stored
        // values created by Ettic_OTC_Chat_Secrets::encrypt() always carry
        // this prefix, so trusting it here costs nothing real-world.
        if (str_starts_with($new_value, 'ot_enc_v1:')) {
            return $new_value;
        }
        // Masked placeholder — user didn't change it.
        if ($new_value === '' || $new_value === str_repeat('•', 20) || str_starts_with($new_value, '••••')) {
            return $old_value;
        }
        // Secrets pass through byte-for-byte; only strip non-printable characters
        // (which never appear in real Cloudflare Turnstile keys anyway).
        $clean = trim((string) preg_replace('/[^\x20-\x7E]/', '', $new_value));
        if ($clean === '') {
            return $old_value;
        }
        return Ettic_OTC_Chat_Secrets::encrypt($clean);
    }

    // ──────────────────────────────────────────────
    // Settings page
    // ──────────────────────────────────────────────

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = Ettic_OTC::get_settings();
        $tc_url   = home_url('/' . ($settings['endpoint_slug'] ?? Ettic_OTC::DEFAULT_ENDPOINT_SLUG) . '/');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch on admin settings page.
        $tab      = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'general';
        if (!in_array($tab, ['general', 'contact', 'ai', 'io'], true)) {
            $tab = 'general';
        }
        $base_url = admin_url('admin.php?page=ettic-otc');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <p>
                <a href="<?php echo esc_url($tc_url); ?>" target="_blank" class="button button-secondary">
                    <?php esc_html_e('View Trust Center', 'open-trust-center-by-ettic'); ?> &rarr;
                </a>
            </p>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url($base_url); ?>"
                   class="nav-tab <?php echo esc_attr($tab === 'general' ? 'nav-tab-active' : ''); ?>">
                    <?php esc_html_e('General', 'open-trust-center-by-ettic'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'contact', $base_url)); ?>"
                   class="nav-tab <?php echo esc_attr($tab === 'contact' ? 'nav-tab-active' : ''); ?>">
                    <?php esc_html_e('Contact', 'open-trust-center-by-ettic'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'ai', $base_url)); ?>"
                   class="nav-tab <?php echo esc_attr($tab === 'ai' ? 'nav-tab-active' : ''); ?>">
                    <?php esc_html_e('AI Chat', 'open-trust-center-by-ettic'); ?>
                    <?php if (!empty($settings['ai_enabled'])): ?>
                        <span class="ettic-otc-pill ettic-otc-pill--live" style="margin-left:6px;padding:2px 8px;background:#dcfce7;color:#166534;border-radius:10px;font-size:11px;font-weight:600;vertical-align:middle">
                            <?php esc_html_e('Live', 'open-trust-center-by-ettic'); ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'io', $base_url)); ?>"
                   class="nav-tab <?php echo esc_attr($tab === 'io' ? 'nav-tab-active' : ''); ?>">
                    <?php esc_html_e('Import & Export', 'open-trust-center-by-ettic'); ?>
                </a>
            </h2>

            <?php if ($tab === 'io'): ?>
                <?php Ettic_OTC_Admin_Tools::instance()->render_tab(); ?>
            <?php elseif ($tab === 'ai'): ?>
                <?php Ettic_OTC_Admin_AI::instance()->render_ai_tab($settings); ?>
            <?php elseif ($tab === 'contact'): ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('ettic_otc_settings_group');
                    echo '<input type="hidden" name="ettic_otc_settings[__contact_tab_save]" value="1">';
                    do_settings_sections('ettic-otc-settings-contact');
                    submit_button();
                    ?>
                </form>
            <?php else: ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('ettic_otc_settings_group');
                    echo '<input type="hidden" name="ettic_otc_settings[__general_tab_save]" value="1">';
                    do_settings_sections('ettic-otc-settings-general');
                    submit_button();
                    ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Skip-sanitize write of the settings option. Public so out-of-class
     * subsystems (Admin_AI key handlers, Admin_Questions toggle-logging)
     * can flip a single setting without round-tripping through the full
     * sanitize_settings cascade. Detaches the filter for one update_option
     * call and reattaches immediately — every form-submission path still
     * runs through sanitize_settings.
     *
     * The sanitize callback intentionally treats `ai_enabled`, `ai_provider`,
     * and `ai_model_list_cached_at` as server-controlled — it always carries
     * them forward from $old so a settings form cannot spoof them. The AI
     * key-save handler IS the authoritative server path for those fields,
     * so it must write them without the callback clobbering the change.
     */
    public function save_settings_raw(array $settings): void {
        remove_filter('sanitize_option_ettic_otc_settings', [$this, 'sanitize_settings']);
        update_option('ettic_otc_settings', $settings, false);
        add_filter('sanitize_option_ettic_otc_settings', [$this, 'sanitize_settings']);
    }
}
