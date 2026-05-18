<?php
/**
 * Hero section partial.
 *
 * Variables available from parent: $ot_data, $ot_settings, $ot_logo_url, $ot_company_name, $ot_tagline,
 * $ot_cert_count, $ot_policy_count, $ot_sub_count
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_active_certs = 0;
foreach ($ot_data['certifications'] as $ot_cert) {
    if (($ot_cert['status'] ?? '') === 'active') {
        $ot_active_certs++;
    }
}
?>
<header class="ettic-otc-hero">
    <div class="ettic-otc-container ettic-otc-hero__inner">
        <?php if ($ot_active_certs): ?>
            <div class="ettic-otc-hero__badge">
                <span class="ettic-otc-hero__badge-dot"></span>
                <?php
                printf(
                    esc_html(
                        /* translators: %d: number of active certifications */
                        _n('%d Active Certification', '%d Active Certifications', $ot_active_certs, 'open-trust-center-by-ettic')
                    ),
                    intval( $ot_active_certs )
                ); ?>
            </div>
        <?php endif; ?>

        <h1 class="ettic-otc-hero__title"><?php echo esc_html($ot_settings['page_title'] ?: __('Trust Center', 'open-trust-center-by-ettic')); ?></h1>

        <?php if ($ot_tagline): ?>
            <p class="ettic-otc-hero__tagline"><?php echo esc_html($ot_tagline); ?></p>
        <?php endif; ?>

        <?php if ($ot_active_certs || $ot_policy_count || $ot_sub_count): ?>
        <div class="ettic-otc-hero__stats">
            <?php if ($ot_active_certs): ?>
            <div class="ettic-otc-hero__stat">
                <span class="ettic-otc-hero__stat-value"><?php echo (int) $ot_active_certs; ?></span>
                <span class="ettic-otc-hero__stat-label"><?php esc_html_e('Certifications', 'open-trust-center-by-ettic'); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($ot_policy_count): ?>
            <div class="ettic-otc-hero__stat">
                <span class="ettic-otc-hero__stat-value"><?php echo (int) $ot_policy_count; ?></span>
                <span class="ettic-otc-hero__stat-label"><?php esc_html_e('Policies', 'open-trust-center-by-ettic'); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($ot_sub_count): ?>
            <div class="ettic-otc-hero__stat">
                <span class="ettic-otc-hero__stat-value"><?php echo (int) $ot_sub_count; ?></span>
                <span class="ettic-otc-hero__stat-label"><?php esc_html_e('Subprocessors', 'open-trust-center-by-ettic'); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</header>
