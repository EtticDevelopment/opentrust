<?php
/**
 * Settings API registration, design-system field renderers, sanitization,
 * and the settings-page wrapper that hosts the General / Contact / AI / IO
 * tabs.
 *
 * Owns the WordPress Settings API surface for OpenTrust: register_setting()
 * with a sanitize_callback, plus the schema-driven sanitize cascade that
 * keeps cross-tab saves shape-stable. Page rendering is manual (no
 * do_settings_sections); every tab calls a ds_render_section_* method that
 * emits .opentrust-* row markup from the shared Ettic admin design system.
 *
 * Bootstrapped by OpenTrust_Admin's constructor; subscribes its own
 * admin_init hook for register_settings(). The settings menu page in
 * OpenTrust_Admin::register_menu() points its callback directly at this
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

final class OpenTrust_Admin_Settings {

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
        // Single serialized option; sanitize_settings is the only writer. The
        // page is rendered manually (see render_settings_page) so we no
        // longer register add_settings_section / add_settings_field — every
        // tab uses ds_render_section_* with the design-system row markup.
        register_setting('opentrust_settings_group', 'opentrust_settings', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default'           => OpenTrust::defaults(),
        ]);
    }

    // ──────────────────────────────────────────────
    // Design-system field renderers
    // ──────────────────────────────────────────────
    //
    // These emit the shared Ettic admin design system row markup directly,
    // bypassing do_settings_sections(). The Settings API registration in
    // register_settings() (above) is what `settings_fields()` reads for the
    // nonce + option_page hidden inputs — that wiring stays unchanged.

    private function ds_render_section_general(): void {
        $settings = OpenTrust::get_settings();
        ?>
        <section class="opentrust-block">
            <header class="opentrust-block__head">
                <h2><?php esc_html_e('General', 'opentrust'); ?></h2>
                <p><?php esc_html_e('Endpoint, page title, and company identity.', 'opentrust'); ?></p>
            </header>
            <div class="opentrust-card">
                <?php $this->ds_row_text('endpoint_slug', __('Endpoint Slug', 'opentrust'), (string) ($settings['endpoint_slug'] ?? ''), __('The URL path for your trust center (e.g. "trust-center" = yoursite.com/trust-center/).', 'opentrust')); ?>
                <?php $this->ds_row_text('page_title', __('Page Title', 'opentrust'), (string) ($settings['page_title'] ?? '')); ?>
                <?php $this->ds_row_text('company_name', __('Company Name', 'opentrust'), (string) ($settings['company_name'] ?? '')); ?>
                <?php $this->ds_row_textarea('tagline', __('Tagline', 'opentrust'), (string) ($settings['tagline'] ?? ''), __('A short description displayed below the company name in the hero.', 'opentrust')); ?>
            </div>
        </section>

        <section class="opentrust-block">
            <header class="opentrust-block__head">
                <h2><?php esc_html_e('Branding', 'opentrust'); ?></h2>
                <p><?php esc_html_e('Logo, AI avatar, accent color, and credit link.', 'opentrust'); ?></p>
            </header>
            <div class="opentrust-card">
                <?php $this->ds_row_media('logo_id', __('Logo', 'opentrust'), (int) ($settings['logo_id'] ?? 0), __('Used in the hero and sticky nav. A white version is recommended — it sits on a dark background.', 'opentrust')); ?>
                <?php $this->ds_row_media('avatar_id', __('AI Avatar', 'opentrust'), (int) ($settings['avatar_id'] ?? 0), __('Square image used as the avatar on AI chat responses. A colored background with a light/dark mark on top works well.', 'opentrust')); ?>
                <?php $this->ds_row_accent_color($settings); ?>
                <?php $this->ds_row_toggle('show_powered_by', __('Credit Link', 'opentrust'), !empty($settings['show_powered_by']), __('Show a "Powered by OpenTrust" credit in the trust center footer. Off by default — public credits are opt-in.', 'opentrust')); ?>
            </div>
        </section>

        <section class="opentrust-block">
            <header class="opentrust-block__head">
                <h2><?php esc_html_e('Visible Sections', 'opentrust'); ?></h2>
                <p><?php esc_html_e('Choose which sections appear on the trust center.', 'opentrust'); ?></p>
            </header>
            <div class="opentrust-card">
                <?php $this->ds_row_sections((array) ($settings['sections_visible'] ?? [])); ?>
            </div>
        </section>
        <?php
    }

    private function ds_render_section_contact(): void {
        $settings = OpenTrust::get_settings();
        ?>
        <section class="opentrust-block">
            <header class="opentrust-block__head">
                <h2><?php esc_html_e('Get in touch', 'opentrust'); ?></h2>
                <p><?php esc_html_e('Publish a dark-accent "Get in touch" block on the trust center. Every field is optional — the block only appears if at least one is filled in.', 'opentrust'); ?></p>
            </header>
            <div class="opentrust-card">
                <?php $this->ds_row_textarea('company_description', __('Company Description', 'opentrust'), (string) ($settings['company_description'] ?? ''), __('Two or three sentences describing what the company does. Rendered under the "Get in touch" section title.', 'opentrust')); ?>
                <?php $this->ds_row_text('dpo_name', __('DPO Name', 'opentrust'), (string) ($settings['dpo_name'] ?? ''), __('Data Protection Officer name. Required under GDPR for many organisations.', 'opentrust')); ?>
                <?php $this->ds_row_text_typed('dpo_email', __('DPO Email', 'opentrust'), (string) ($settings['dpo_email'] ?? ''), 'email', __('Dedicated DPO mailbox. Rendered as a mailto link.', 'opentrust')); ?>
                <?php $this->ds_row_text_typed('security_email', __('Security Contact Email', 'opentrust'), (string) ($settings['security_email'] ?? ''), 'email', __('For vulnerability reports and security questions. Often separate from the DPO.', 'opentrust')); ?>
                <?php $this->ds_row_text_typed('contact_form_url', __('Contact Form URL', 'opentrust'), (string) ($settings['contact_form_url'] ?? ''), 'url', __('Optional link to a gated contact form.', 'opentrust')); ?>
                <?php $this->ds_row_textarea('contact_address', __('Mailing Address', 'opentrust'), (string) ($settings['contact_address'] ?? ''), __('Postal address for formal GDPR / legal notices.', 'opentrust')); ?>
                <?php $this->ds_row_text_typed('pgp_key_url', __('PGP Public Key URL', 'opentrust'), (string) ($settings['pgp_key_url'] ?? ''), 'url', __("Optional link to your security team's PGP public key.", 'opentrust')); ?>
                <?php $this->ds_row_text('company_registration', __('Company Registration Number', 'opentrust'), (string) ($settings['company_registration'] ?? ''), __('KvK (NL), Companies House (UK), Handelsregister (DE), EIN (US), or equivalent business registration.', 'opentrust')); ?>
                <?php $this->ds_row_text('vat_number', __('VAT / Tax ID', 'opentrust'), (string) ($settings['vat_number'] ?? ''), __('VAT number, sales-tax ID, or equivalent international tax identifier.', 'opentrust')); ?>
            </div>
        </section>
        <?php
    }

    /**
     * Typed text input variant — for input types email/url/number that need
     * the native HTML type for mobile keyboards, validation, autofill.
     */
    private function ds_row_text_typed(string $key, string $label, string $value, string $type, string $help = ''): void {
        $name = sprintf('opentrust_settings[%s]', $key);
        $extra = match ($type) {
            'url'   => ' placeholder="https://" inputmode="url" autocomplete="off"',
            'email' => ' autocomplete="off"',
            default => '',
        };
        ?>
        <div class="opentrust-row">
            <div class="opentrust-row__main">
                <span class="opentrust-row__label"><?php echo esc_html($label); ?></span>
                <?php if ($help !== ''): ?>
                    <p class="opentrust-row__help"><?php echo esc_html($help); ?></p>
                <?php endif; ?>
            </div>
            <div class="opentrust-row__control">
                <input type="<?php echo esc_attr($type); ?>" class="opentrust-input opentrust-input--md" id="<?php echo esc_attr('opentrust_' . $key); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>"<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded attribute fragment with escaped values ?>>
            </div>
        </div>
        <?php
    }

    private function ds_row_text(string $key, string $label, string $value, string $help = ''): void {
        $name = sprintf('opentrust_settings[%s]', $key);
        ?>
        <div class="opentrust-row">
            <div class="opentrust-row__main">
                <span class="opentrust-row__label"><?php echo esc_html($label); ?></span>
                <?php if ($help !== ''): ?>
                    <p class="opentrust-row__help"><?php echo esc_html($help); ?></p>
                <?php endif; ?>
            </div>
            <div class="opentrust-row__control">
                <input type="text" class="opentrust-input opentrust-input--md" id="<?php echo esc_attr('opentrust_' . $key); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            </div>
        </div>
        <?php
    }

    private function ds_row_textarea(string $key, string $label, string $value, string $help = ''): void {
        $name = sprintf('opentrust_settings[%s]', $key);
        ?>
        <div class="opentrust-row opentrust-row--stacked">
            <div class="opentrust-row__main">
                <span class="opentrust-row__label"><?php echo esc_html($label); ?></span>
                <?php if ($help !== ''): ?>
                    <p class="opentrust-row__help"><?php echo esc_html($help); ?></p>
                <?php endif; ?>
            </div>
            <div class="opentrust-row__control opentrust-row__control--stack">
                <textarea class="opentrust-input" rows="3" id="<?php echo esc_attr('opentrust_' . $key); ?>" name="<?php echo esc_attr($name); ?>"><?php echo esc_textarea($value); ?></textarea>
            </div>
        </div>
        <?php
    }

    private function ds_row_toggle(string $key, string $label, bool $checked, string $help = ''): void {
        $name = sprintf('opentrust_settings[%s]', $key);
        ?>
        <div class="opentrust-row">
            <div class="opentrust-row__main">
                <span class="opentrust-row__label"><?php echo esc_html($label); ?></span>
                <?php if ($help !== ''): ?>
                    <p class="opentrust-row__help"><?php echo esc_html($help); ?></p>
                <?php endif; ?>
            </div>
            <div class="opentrust-row__control">
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="0">
                <label class="opentrust-toggle">
                    <input class="opentrust-toggle__input" type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($checked); ?>>
                    <span class="opentrust-toggle__thumb"></span>
                </label>
            </div>
        </div>
        <?php
    }

    private function ds_row_media(string $key, string $label, int $attachment_id, string $help = ''): void {
        $src  = $attachment_id ? wp_get_attachment_image_src($attachment_id, 'medium') : false;
        $url  = is_array($src) ? (string) $src[0] : '';
        $name = sprintf('opentrust_settings[%s]', $key);
        ?>
        <div class="opentrust-row">
            <div class="opentrust-row__main">
                <span class="opentrust-row__label"><?php echo esc_html($label); ?></span>
                <?php if ($help !== ''): ?>
                    <p class="opentrust-row__help"><?php echo esc_html($help); ?></p>
                <?php endif; ?>
            </div>
            <div class="opentrust-row__control">
                <div class="opentrust-media" data-opentrust-media-picker="<?php echo esc_attr($key); ?>">
                    <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $attachment_id); ?>" data-opentrust-media-id>
                    <div class="opentrust-media__preview <?php echo $url ? 'opentrust-media__preview--filled' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>" data-opentrust-media-preview>
                        <?php if ($url): ?>
                            <img src="<?php echo esc_url($url); ?>" alt="">
                        <?php endif; ?>
                    </div>
                    <div class="opentrust-media__controls">
                        <button type="button" class="opentrust-btn opentrust-btn--ghost opentrust-btn--sm" data-opentrust-media-pick><?php esc_html_e('Replace', 'opentrust'); ?></button>
                        <button type="button" class="opentrust-btn opentrust-btn--text" data-opentrust-media-clear style="<?php echo $attachment_id ? '' : 'display:none;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>"><?php esc_html_e('Remove', 'opentrust'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Accent color row — fuses the design system's native swatch + hex pair
     * with the existing contrast-warning widget. The hex text input keeps the
     * id `opentrust_accent_color` and name `opentrust_settings[accent_color]`
     * so the existing admin.js accent-warning code can locate it.
     */
    private function ds_row_accent_color(array $settings): void {
        $value       = (string) ($settings['accent_color'] ?? '#2563EB');
        $force_exact = !empty($settings['accent_force_exact']);
        ?>
        <div class="opentrust-row opentrust-row--stacked">
            <div class="opentrust-row__main">
                <span class="opentrust-row__label"><?php esc_html_e('Accent Color', 'opentrust'); ?></span>
                <p class="opentrust-row__help"><?php esc_html_e('Used for buttons, links, and highlights. Choose a color that matches your brand.', 'opentrust'); ?></p>
            </div>
            <div class="opentrust-row__control opentrust-row__control--stack">
                <div class="opentrust-color">
                    <input type="color" value="<?php echo esc_attr($value); ?>" aria-label="<?php esc_attr_e('Color picker', 'opentrust'); ?>">
                    <input type="text" class="opentrust-input opentrust-input--mono" id="opentrust_accent_color" name="opentrust_settings[accent_color]" value="<?php echo esc_attr($value); ?>" maxlength="7" data-validate-hex>
                </div>
                <div id="opentrust-accent-warning" class="ot-accent-warning<?php echo $force_exact ? ' ot-accent-warning--override' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded class ?>" hidden>
                    <svg class="ot-accent-warning__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <div class="ot-accent-warning__body">
                        <strong class="ot-accent-warning__heading ot-accent-warning__heading--auto"><?php esc_html_e('Low contrast on white backgrounds', 'opentrust'); ?></strong>
                        <strong class="ot-accent-warning__heading ot-accent-warning__heading--override"><?php esc_html_e('Using your exact color on white backgrounds', 'opentrust'); ?></strong>

                        <p class="ot-accent-warning__copy ot-accent-warning__copy--auto">
                            <?php esc_html_e('Your chosen color is too light for buttons, links, and borders on white sections. On those surfaces OpenTrust will use a darker, on-brand variant:', 'opentrust'); ?>
                        </p>
                        <p class="ot-accent-warning__copy ot-accent-warning__copy--override">
                            <?php esc_html_e("You've chosen to keep your exact color on white backgrounds. Buttons, links, and borders in those sections may be hard to read.", 'opentrust'); ?>
                        </p>

                        <div class="ot-accent-warning__preview">
                            <span class="ot-accent-warning__swatch ot-accent-warning__swatch--chosen" aria-hidden="true"></span>
                            <code class="ot-accent-warning__hex ot-accent-warning__hex--chosen"></code>
                            <span class="ot-accent-warning__arrow" aria-hidden="true">&rarr;</span>
                            <span class="ot-accent-warning__swatch ot-accent-warning__swatch--adjusted" aria-hidden="true"></span>
                            <code class="ot-accent-warning__hex ot-accent-warning__hex--adjusted"></code>
                        </div>

                        <p class="ot-accent-warning__note ot-accent-warning__note--auto">
                            <?php esc_html_e('The hero and navigation still use your exact color.', 'opentrust'); ?>
                        </p>

                        <label class="ot-accent-warning__override">
                            <input type="checkbox" id="opentrust_accent_force_exact" name="opentrust_settings[accent_force_exact]" value="1" <?php checked($force_exact); ?>>
                            <span><?php esc_html_e('Use my exact color anyway — skip the contrast adjustment.', 'opentrust'); ?></span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function ds_row_sections(array $visible): void {
        $sections = [
            'certifications' => __('Certifications & Compliance', 'opentrust'),
            'policies'       => __('Policies', 'opentrust'),
            'subprocessors'  => __('Subprocessors', 'opentrust'),
            'data_practices' => __('Data Practices', 'opentrust'),
            'faqs'           => __('FAQs', 'opentrust'),
            'contact'        => __('Contact & DPO', 'opentrust'),
        ];
        ?>
        <div class="opentrust-row opentrust-row--stacked">
            <div class="opentrust-row__main">
                <span class="opentrust-row__label"><?php esc_html_e('Sections', 'opentrust'); ?></span>
                <p class="opentrust-row__help"><?php esc_html_e('Click a section to toggle its visibility. Hidden sections still preserve their content; only the public page changes.', 'opentrust'); ?></p>
            </div>
            <div class="opentrust-row__control opentrust-row__control--stack">
                <div class="opentrust-chips">
                    <?php foreach ($sections as $key => $label): ?>
                        <?php $checked = !empty($visible[$key]); ?>
                        <label class="opentrust-chip">
                            <input class="opentrust-chip__input" type="checkbox" name="<?php echo esc_attr(sprintf('opentrust_settings[sections_visible][%s]', $key)); ?>" value="1" <?php checked($checked); ?>>
                            <span class="opentrust-chip__check" aria-hidden="true"><svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="2.5 6 5 8.5 9.5 4"/></svg></span>
                            <span><?php echo esc_html($label); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    // Sanitization
    // ──────────────────────────────────────────────

    public function sanitize_settings(mixed $input): array {
        if (!is_array($input)) {
            return OpenTrust::defaults();
        }

        $old = OpenTrust::get_settings();
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

        // Per-site salt — written out-of-band by OpenTrust_Chat_Budget::site_salt().
        // Carry forward byte-for-byte so saving settings doesn't force a
        // regeneration (which would invalidate all in-flight rate-limit
        // hashes and Turnstile bypass transients).
        if (isset($old['opentrust_site_salt']) && is_string($old['opentrust_site_salt'])) {
            $sanitized['opentrust_site_salt'] = $old['opentrust_site_salt'];
        }

        // Flag rewrite flush if slug changed.
        if ($sanitized['endpoint_slug'] !== ($old['endpoint_slug'] ?? '')) {
            set_transient('opentrust_flush_rewrite', true);
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
     *   - `opentrust_site_salt` — written out-of-band by Chat_Budget.
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
                'default' => OpenTrust::DEFAULT_ENDPOINT_SLUG,
                'sanitize' => static fn($v) => sanitize_title((string) ($v ?? '')) ?: OpenTrust::DEFAULT_ENDPOINT_SLUG,
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
                'default' => OpenTrust_Chat_Budget::DEFAULT_DAILY_TOKEN_BUDGET,
                'sanitize' => static fn($v): int => max(0, absint($v ?? OpenTrust_Chat_Budget::DEFAULT_DAILY_TOKEN_BUDGET)),
            ],
            'ai_monthly_token_budget' => [
                'tab' => 'ai',
                'default' => OpenTrust_Chat_Budget::DEFAULT_MONTHLY_TOKEN_BUDGET,
                'sanitize' => static fn($v): int => max(0, absint($v ?? OpenTrust_Chat_Budget::DEFAULT_MONTHLY_TOKEN_BUDGET)),
            ],
            'ai_rate_limit_per_ip' => [
                'tab' => 'ai',
                'default' => OpenTrust_Chat_Budget::DEFAULT_RATE_LIMIT_PER_IP,
                'sanitize' => $bounded_int(0, 1000, OpenTrust_Chat_Budget::DEFAULT_RATE_LIMIT_PER_IP),
            ],
            'ai_rate_limit_per_session' => [
                'tab' => 'ai',
                'default' => OpenTrust_Chat_Budget::DEFAULT_RATE_LIMIT_PER_SESSION,
                'sanitize' => $bounded_int(0, 10000, OpenTrust_Chat_Budget::DEFAULT_RATE_LIMIT_PER_SESSION),
            ],
            'ai_max_message_length' => [
                'tab' => 'ai',
                'default' => OpenTrust_Chat::DEFAULT_MAX_MESSAGE_LENGTH,
                'sanitize' => $bounded_int(100, 4000, OpenTrust_Chat::DEFAULT_MAX_MESSAGE_LENGTH),
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
     * OpenTrust_Chat_Secrets, so the option never carries the plaintext.
     */
    private static function sanitize_secret_field(string $new_value, string $old_value): string {
        // Idempotency: already-encrypted ciphertext passes through. Stored
        // values created by OpenTrust_Chat_Secrets::encrypt() always carry
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
        return OpenTrust_Chat_Secrets::encrypt($clean);
    }

    // ──────────────────────────────────────────────
    // Settings page
    // ──────────────────────────────────────────────

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = OpenTrust::get_settings();
        $tc_url   = home_url('/' . ($settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG) . '/');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch on admin settings page.
        $tab      = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'general';
        if (!in_array($tab, ['general', 'contact', 'ai', 'io'], true)) {
            $tab = 'general';
        }
        $base_url = admin_url('admin.php?page=opentrust');

        // General, Contact, and the AI tab's main settings form are Settings-API-
        // saveable and wire into the topbar Save. IO is purely action-based
        // (admin-post handlers) and never grows a Save button.
        $has_settings_form = in_array($tab, ['general', 'contact', 'ai'], true);
        $tabs = [
            'general' => ['label' => __('General', 'opentrust'),         'url' => $base_url],
            'contact' => ['label' => __('Contact', 'opentrust'),         'url' => add_query_arg('tab', 'contact', $base_url)],
            'ai'      => ['label' => __('AI Chat', 'opentrust'),         'url' => add_query_arg('tab', 'ai', $base_url)],
            'io'      => ['label' => __('Import & Export', 'opentrust'), 'url' => add_query_arg('tab', 'io', $base_url)],
        ];
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
                    <?php if ($has_settings_form): ?>
                        <div class="opentrust-topbar__dirty is-clean" aria-live="polite" data-dirty>
                            <span class="opentrust-topbar__dirty-dot" aria-hidden="true"></span>
                            <span><span class="opentrust-topbar__dirty-num" data-dirty-num>0</span><span data-dirty-label></span></span>
                        </div>
                        <div class="opentrust-topbar__actions">
                            <a href="<?php echo esc_url($tc_url); ?>" target="_blank" class="opentrust-btn opentrust-btn--ghost-dark opentrust-btn--sm">
                                <?php esc_html_e('View Trust Center', 'opentrust'); ?> &rarr;
                            </a>
                            <button type="button" class="opentrust-btn opentrust-btn--ghost-dark" data-discard disabled><?php esc_html_e('Discard', 'opentrust'); ?></button>
                            <button type="submit" form="opentrust-settings-form" class="opentrust-btn opentrust-btn--primary" data-save name="submit" disabled><?php esc_html_e('Save changes', 'opentrust'); ?></button>
                        </div>
                    <?php else: ?>
                        <div class="opentrust-topbar__actions">
                            <a href="<?php echo esc_url($tc_url); ?>" target="_blank" class="opentrust-btn opentrust-btn--ghost-dark opentrust-btn--sm">
                                <?php esc_html_e('View Trust Center', 'opentrust'); ?> &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="opentrust-topbar__head">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <p><?php esc_html_e('Self-hosted, open-source trust center for security policies, subprocessors, certifications, and data practices.', 'opentrust'); ?></p>
            </div>

            <nav class="opentrust-tabbar" role="tablist" aria-label="<?php esc_attr_e('OpenTrust settings sections', 'opentrust'); ?>">
                <?php foreach ($tabs as $tab_key => $info): ?>
                    <a href="<?php echo esc_url($info['url']); ?>"
                       class="opentrust-tabbar__tab<?php echo $tab === $tab_key ? ' is-active' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>"
                       role="tab"
                       aria-selected="<?php echo $tab === $tab_key ? 'true' : 'false'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>">
                        <?php echo esc_html($info['label']); ?>
                        <?php if ($tab_key === 'ai' && !empty($settings['ai_enabled'])): ?>
                            <span class="opentrust-tabbar__badge"><?php esc_html_e('Live', 'opentrust'); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="opentrust-stack">
                <?php if ($tab === 'io'): ?>
                    <?php OpenTrust_Admin_Tools::instance()->render_tab(); ?>
                <?php elseif ($tab === 'ai'): ?>
                    <?php OpenTrust_Admin_AI::instance()->render_ai_tab($settings); ?>
                <?php elseif ($tab === 'contact'): ?>
                    <form id="opentrust-settings-form" method="post" action="options.php">
                        <?php
                        settings_fields('opentrust_settings_group');
                        echo '<input type="hidden" name="opentrust_settings[__contact_tab_save]" value="1">';
                        $this->ds_render_section_contact();
                        ?>
                    </form>
                <?php else: ?>
                    <form id="opentrust-settings-form" method="post" action="options.php">
                        <?php
                        settings_fields('opentrust_settings_group');
                        echo '<input type="hidden" name="opentrust_settings[__general_tab_save]" value="1">';
                        $this->ds_render_section_general();
                        ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php \OpenTrust\Admin\Footer::render(); ?>
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
        remove_filter('sanitize_option_opentrust_settings', [$this, 'sanitize_settings']);
        update_option('opentrust_settings', $settings, false);
        add_filter('sanitize_option_opentrust_settings', [$this, 'sanitize_settings']);
    }
}
