<?php
/**
 * "Get in touch" section partial.
 *
 * Variables available from parent: $ot_data, $ot_settings, $ot_base_url
 *
 * Renders a dark accent-colored section containing:
 *   1. Section title + optional intro + optional company description
 *   2. A vertical stack of contact rows (icon, label, stacked value lines)
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_company_description = trim((string) ($ot_settings['company_description'] ?? ''));
$ot_dpo_name            = trim((string) ($ot_settings['dpo_name']            ?? ''));
$ot_dpo_email           = trim((string) ($ot_settings['dpo_email']           ?? ''));
$ot_security_email      = trim((string) ($ot_settings['security_email']      ?? ''));
$ot_contact_form_url    = trim((string) ($ot_settings['contact_form_url']    ?? ''));
$ot_contact_address     = trim((string) ($ot_settings['contact_address']     ?? ''));
$ot_pgp_key_url         = trim((string) ($ot_settings['pgp_key_url']         ?? ''));
$ot_company_reg         = trim((string) ($ot_settings['company_registration'] ?? ''));
$ot_vat_number          = trim((string) ($ot_settings['vat_number']          ?? ''));

// Build the rows we will render. Each row is a definition-list entry: a
// label on the left column, a stack of value lines on the right.
$ot_rows = [];

if ($ot_dpo_name || $ot_dpo_email) {
    $lines = [];
    if ($ot_dpo_name) {
        $lines[] = '<span class="ot-get-row__strong">' . esc_html($ot_dpo_name) . '</span>';
    }
    if ($ot_dpo_email) {
        $lines[] = '<a href="' . esc_url('mailto:' . $ot_dpo_email) . '">' . esc_html($ot_dpo_email) . '</a>';
    }
    $ot_rows[] = [
        'label' => __('Data Protection Officer', 'open-trust-center-by-ettic'),
        'lines' => $lines,
    ];
}

if ($ot_security_email) {
    $ot_rows[] = [
        'label' => __('Security Team', 'open-trust-center-by-ettic'),
        'lines' => [
            '<a href="' . esc_url('mailto:' . $ot_security_email) . '">' . esc_html($ot_security_email) . '</a>',
        ],
    ];
}

if ($ot_contact_form_url) {
    $ot_rows[] = [
        'label' => __('Contact Form', 'open-trust-center-by-ettic'),
        'lines' => [
            '<a href="' . esc_url($ot_contact_form_url) . '" target="_blank" rel="noopener">' . esc_html__('Open the contact form', 'open-trust-center-by-ettic') . ' &rarr;</a>',
        ],
    ];
}

if ($ot_pgp_key_url) {
    $ot_rows[] = [
        'label' => __('PGP Public Key', 'open-trust-center-by-ettic'),
        'lines' => [
            '<a href="' . esc_url($ot_pgp_key_url) . '" target="_blank" rel="noopener">' . esc_html__('Download public key', 'open-trust-center-by-ettic') . '</a>',
        ],
    ];
}

if ($ot_contact_address) {
    // Preserve line breaks as stacked lines.
    $addr_lines = array_filter(array_map('trim', preg_split('/\r?\n/', $ot_contact_address) ?: []));
    $lines = [];
    foreach ($addr_lines as $line) {
        $lines[] = '<span>' . esc_html($line) . '</span>';
    }
    if (empty($lines)) {
        $lines[] = '<span>' . esc_html($ot_contact_address) . '</span>';
    }
    $ot_rows[] = [
        'label' => __('Mailing Address', 'open-trust-center-by-ettic'),
        'lines' => $lines,
    ];
}

if ($ot_company_reg) {
    $ot_rows[] = [
        'label' => __('Company Registration', 'open-trust-center-by-ettic'),
        'lines' => [
            '<span class="ot-get-row__strong">' . esc_html($ot_company_reg) . '</span>',
        ],
    ];
}

if ($ot_vat_number) {
    $ot_rows[] = [
        'label' => __('VAT / Tax ID', 'open-trust-center-by-ettic'),
        'lines' => [
            '<span class="ot-get-row__strong">' . esc_html($ot_vat_number) . '</span>',
        ],
    ];
}
?>
<section id="ot-contact" class="ot-section ot-section--getintouch">
    <div class="ot-container">
        <div class="ot-get-inner">
            <div class="ot-get-header">
                <h2 class="ot-get-header__title"><?php esc_html_e('Get in touch', 'open-trust-center-by-ettic'); ?></h2>
                <?php if ($ot_company_description): ?>
                    <p class="ot-get-header__description"><?php echo esc_html($ot_company_description); ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($ot_rows)):
                // Allow-list of element shapes the $ot_rows build code can produce.
                // wp_kses at the echo IS the late escape; per-element esc_html/esc_url
                // upstream remains as defense-in-depth.
                $ot_line_allowed = [
                    'span' => ['class' => true],
                    'a'    => ['href' => true, 'target' => true, 'rel' => true],
                ];
                ?>
                <dl class="ot-get-list">
                    <?php foreach ($ot_rows as $ot_row): ?>
                        <div class="ot-get-row">
                            <dt class="ot-get-row__label"><?php echo esc_html($ot_row['label']); ?></dt>
                            <dd class="ot-get-row__lines">
                                <?php foreach ($ot_row['lines'] as $ot_line): ?>
                                    <?php echo wp_kses($ot_line, $ot_line_allowed); ?>
                                <?php endforeach; ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </div>
    </div>
</section>
