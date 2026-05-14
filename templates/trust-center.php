<?php
/**
 * Main trust center template.
 *
 * Outputs a complete, standalone HTML document.
 * Variables available: $ot_data (array with settings, hsl, logo_url, base_url, view, certifications, policies, etc.)
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_settings = $ot_data['settings'];
$ot_hsl      = $ot_data['hsl'];
$ot_view     = $ot_data['view'] ?? 'main';

$ot_page_title   = (string) (($ot_settings['page_title'] ?? '') ?: __('Trust Center', 'opentrust'));
$ot_company_name = (string) ($ot_settings['company_name'] ?? '');
$ot_tagline      = (string) (($ot_settings['tagline'] ?? '') ?: __('Transparency and security you can trust.', 'opentrust'));
$ot_logo_url     = $ot_data['logo_url'] ?? '';
$ot_base_url     = $ot_data['base_url'] ?? '/';

$ot_visible = $ot_settings['sections_visible'] ?? [];

// Count stats for the hero.
$ot_cert_count   = count($ot_data['certifications'] ?? []);
$ot_policy_count = count($ot_data['policies'] ?? []);
$ot_sub_count    = count($ot_data['subprocessors'] ?? []);
$ot_faq_count    = count($ot_data['faqs'] ?? []);

// Contact block is present only when the admin filled in at least one
// field. Matches the "don't render empty sections" rule used elsewhere.
$ot_contact_has_content = (bool) (
    trim((string) ($ot_settings['company_description'] ?? ''))
    || trim((string) ($ot_settings['dpo_name']         ?? ''))
    || trim((string) ($ot_settings['dpo_email']        ?? ''))
    || trim((string) ($ot_settings['security_email']   ?? ''))
    || trim((string) ($ot_settings['contact_form_url'] ?? ''))
    || trim((string) ($ot_settings['contact_address']  ?? ''))
    || trim((string) ($ot_settings['pgp_key_url']      ?? ''))
    || trim((string) ($ot_settings['company_registration'] ?? ''))
    || trim((string) ($ot_settings['vat_number']       ?? ''))
);

// AI chat availability + contrast-safe text color against the user's accent.
$ot_ai_enabled = !empty($ot_settings['ai_enabled'])
    && !empty($ot_settings['ai_provider'])
    && !empty($ot_settings['ai_model'])
    && class_exists('OpenTrust_Chat_Secrets')
    && OpenTrust_Chat_Secrets::get((string) $ot_settings['ai_provider']) !== null;

$ot_accent_contrast = ((int) $ot_hsl['l'] < 55) ? '#ffffff' : '#111827';

// Lightness used anywhere the accent sits on a white/light background
// (buttons, links, borders). Darkened in HSL space just far enough to hit
// 4.5:1 WCAG contrast against white; identical to the raw L when the user's
// pick is already readable. The hero/nav keep the raw L separately.
//
// When `accent_force_exact` is set, the user has explicitly opted out of the
// WCAG adjustment — keep their exact colour everywhere, contrast be damned.
$ot_accent_l_safe = !empty($ot_settings['accent_force_exact'])
    ? (int) $ot_hsl['l']
    : OpenTrust::accent_safe_lightness((string) ($ot_settings['accent_color'] ?? '#2563EB'));

// Navigation items — shared by the main page and the single policy view so
// the menu stays put when a visitor drills into a policy.
$ot_nav_items = [];
if (!empty($ot_visible['policies']) && $ot_policy_count)                                $ot_nav_items['policies']       = __('Policies', 'opentrust');
if (!empty($ot_visible['certifications']) && $ot_cert_count)                             $ot_nav_items['certifications'] = __('Certifications', 'opentrust');
if (!empty($ot_visible['subprocessors']) && $ot_sub_count)                               $ot_nav_items['subprocessors']  = __('Subprocessors', 'opentrust');
if (!empty($ot_visible['data_practices']) && count($ot_data['data_practices'] ?? []))    $ot_nav_items['data-practices'] = __('Data Practices', 'opentrust');
if (!empty($ot_visible['contact']) && $ot_contact_has_content)                           $ot_nav_items['contact']        = __('Contact', 'opentrust');
if (!empty($ot_visible['faqs']) && $ot_faq_count)                                        $ot_nav_items['faqs']           = __('FAQ', 'opentrust');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php
        if ($ot_view === 'policy_single' && !empty($ot_data['current_policy'])) {
            echo esc_html($ot_data['current_policy']->post_title) . ' — ';
        }
        echo esc_html($ot_page_title);
        if ($ot_company_name) {
            echo ' | ' . esc_html($ot_company_name);
        }
    ?></title>
    <meta name="description" content="<?php echo esc_attr($ot_tagline); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo esc_url($ot_base_url); ?>">
    <?php
    $ot_root_vars = sprintf(
        ':root{--ot-accent-h:%d;--ot-accent-s:%d%%;--ot-accent-l:%d%%;--ot-accent-l-safe:%d%%;--ot-accent-contrast:%s;}',
        (int) $ot_hsl['h'],
        (int) $ot_hsl['s'],
        (int) $ot_hsl['l'],
        (int) $ot_accent_l_safe,
        $ot_accent_contrast === '#ffffff' ? '#ffffff' : '#111827'
    );
    wp_register_style('opentrust-frontend', plugins_url('assets/css/frontend.css', OPENTRUST_PLUGIN_FILE), [], OPENTRUST_VERSION);
    wp_enqueue_style('opentrust-frontend');
    wp_add_inline_style('opentrust-frontend', $ot_root_vars);
    wp_print_styles(['opentrust-frontend']);
    ?>
</head>
<body class="ot-body">

    <a class="ot-skip-link" href="#ot-main"><?php esc_html_e('Skip to content', 'opentrust'); ?></a>

    <?php
    if ($ot_view === 'main') {
        // ── Navigation (above hero) ──
        $ot_nav_anchor_base = '';
        $ot_nav_scrollspy   = true;
        include OPENTRUST_PLUGIN_DIR . 'templates/partials/nav.php';

        // ── Hero ──
        include OPENTRUST_PLUGIN_DIR . 'templates/partials/hero.php';
        ?>

        <main id="ot-main" class="ot-main">
                <?php
                // ── Policies ──
                if (!empty($ot_visible['policies']) && $ot_policy_count) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/policies.php';
                }

                // ── Certifications ──
                if (!empty($ot_visible['certifications']) && $ot_cert_count) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/certifications.php';
                }

                // ── Subprocessors ──
                if (!empty($ot_visible['subprocessors']) && $ot_sub_count) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/subprocessors.php';
                }

                // ── Data Practices ──
                if (!empty($ot_visible['data_practices']) && count($ot_data['data_practices'] ?? [])) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/data-practices.php';
                }

                // ── Get in touch ──
                if (!empty($ot_visible['contact']) && $ot_contact_has_content) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/contact.php';
                }

                // ── FAQ ──
                if (!empty($ot_visible['faqs']) && $ot_faq_count) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/faq.php';
                }

                // Show empty state if nothing is published yet.
                if (!$ot_cert_count && !$ot_policy_count && !$ot_sub_count && empty($ot_data['data_practices']) && !$ot_faq_count && !$ot_contact_has_content):
                ?>
                    <div class="ot-empty">
                        <div class="ot-empty__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
                            </svg>
                        </div>
                        <p class="ot-empty__text"><?php esc_html_e('Trust center content is being prepared. Check back soon.', 'opentrust'); ?></p>
                    </div>
                <?php endif; ?>
        </main>

    <?php } elseif ($ot_view === 'policy_single') {
        // ── Single Policy View ──
        include OPENTRUST_PLUGIN_DIR . 'templates/partials/policy-single.php';
    } ?>

    <footer class="ot-footer">
        <div class="ot-container">
            <p>
                <?php
                printf(
                    /* translators: %s: company name */
                    esc_html__('© %1$s %2$s. All rights reserved.', 'opentrust'),
                    esc_html(wp_date('Y')),
                    esc_html($ot_company_name ?: get_bloginfo('name'))
                );
                ?>
                <?php if (!empty($ot_settings['show_powered_by'])): ?>
                    &nbsp;·&nbsp;
                    <a href="https://plugins.ettic.nl/opentrust" target="_blank" rel="noopener">
                        <?php esc_html_e('Powered by OpenTrust', 'opentrust'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
    </footer>

    <?php
    wp_register_script(
        'opentrust-frontend',
        plugins_url('assets/js/frontend.js', OPENTRUST_PLUGIN_FILE),
        [],
        OPENTRUST_VERSION,
        ['in_footer' => true, 'strategy' => 'defer']
    );
    wp_enqueue_script('opentrust-frontend');
    wp_print_scripts(['opentrust-frontend']);
    ?>

</body>
</html>
