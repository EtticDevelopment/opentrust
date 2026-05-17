<?php
/**
 * Certifications section partial.
 *
 * Variables available from parent: $ot_data
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_certifications = $ot_data['certifications'] ?? [];
$ot_audited_labels = Ettic_OTC_Render::cert_status_labels();
$ot_aligned_labels = Ettic_OTC_Render::cert_aligned_status_labels();
?>
<section id="ettic-otc-certifications" class="ettic-otc-section">
    <div class="ettic-otc-container">
        <div class="ettic-otc-section__header">
            <?php Ettic_OTC_Render::updated_pill('certifications', $ot_data); ?>
            <h2 class="ettic-otc-section__title"><?php esc_html_e('Certifications & Compliance', 'open-trust-center-by-ettic'); ?></h2>
            <p class="ettic-otc-section__description"><?php esc_html_e('Our active certifications and compliance frameworks demonstrate our commitment to protecting your data.', 'open-trust-center-by-ettic'); ?></p>
        </div>

        <div class="ettic-otc-cert-grid">
            <?php foreach ($ot_certifications as $ot_cert):
                $ot_cert_type   = $ot_cert['type'] ?? 'certified';
                $ot_is_audited  = $ot_cert_type === 'certified';
                $ot_status      = $ot_cert['status'] ?? 'active';
                $ot_status_text = $ot_is_audited
                    ? ($ot_audited_labels[$ot_status] ?? $ot_status)
                    : ($ot_aligned_labels[$ot_status] ?? $ot_status);
                // Tier lives in the pill wording ("Certified" vs "Compliant"),
                // not a separate marker. Audited-active uses the green token,
                // everything else uses gray/amber — same palette as the rest
                // of the plugin, no new colors.
                $ot_pill_state = match (true) {
                    $ot_is_audited && $ot_status === 'active'      => 'active',
                    $ot_is_audited && $ot_status === 'in_progress' => 'in_progress',
                    $ot_is_audited && $ot_status === 'expired'     => 'expired',
                    !$ot_is_audited && $ot_status === 'in_progress' => 'in_progress',
                    default                                         => 'neutral',
                };
                $ot_badge_url    = $ot_cert['badge_url'] ?? '';
                $ot_description  = trim((string) ($ot_cert['description'] ?? ''));
                $ot_artifact_url = $ot_cert['artifact_url'] ?? '';
                $ot_issue_date   = $ot_cert['issue_date']
                    ? wp_date('M Y', strtotime($ot_cert['issue_date']))
                    : '';
                $ot_expiry_date  = $ot_cert['expiry_date']
                    ? wp_date('M Y', strtotime($ot_cert['expiry_date']))
                    : '';
                // For self-attested cards the issuer slot is repurposed so
                // the card never renders empty and the tier signal lives in
                // the same typographic slot audited cards use for the auditor.
                $ot_subline = $ot_is_audited
                    ? ($ot_cert['issuing_body'] ?: '')
                    : __('Self-attested framework', 'open-trust-center-by-ettic');
            ?>
            <div class="ettic-otc-cert-tile">
                <div class="ettic-otc-cert-tile__badge">
                    <?php if ($ot_badge_url): ?>
                        <img src="<?php echo esc_url($ot_badge_url); ?>"
                             alt=""
                             loading="lazy"
                             width="44"
                             height="44">
                    <?php else: ?>
                        <div class="ettic-otc-cert-tile__badge-placeholder">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-1 16l-4-4 1.41-1.41L11 14.17l6.59-6.59L19 9l-8 8z"/></svg>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="ettic-otc-cert-tile__body">
                    <div class="ettic-otc-cert-tile__header">
                        <h3 class="ettic-otc-cert-tile__name"><?php echo esc_html($ot_cert['title']); ?></h3>
                        <span class="ettic-otc-status-indicator ettic-otc-status-indicator--<?php echo esc_attr($ot_pill_state); ?>">
                            <span class="ettic-otc-status-indicator__dot"></span>
                            <?php echo esc_html($ot_status_text); ?>
                        </span>
                    </div>

                    <?php if ($ot_subline): ?>
                        <p class="ettic-otc-cert-tile__issuer"><?php echo esc_html($ot_subline); ?></p>
                    <?php endif; ?>

                    <?php if ($ot_description): ?>
                        <p class="ettic-otc-cert-tile__description"><?php echo esc_html($ot_description); ?></p>
                    <?php endif; ?>

                    <?php if ($ot_is_audited && ($ot_issue_date || $ot_expiry_date)): ?>
                        <p class="ettic-otc-cert-tile__dates">
                            <?php
                            $ot_date_parts = [];
                            if ($ot_issue_date) {
                                /* translators: %s: certification issue date */
                                $ot_date_parts[] = sprintf(esc_html__('Issued %s', 'open-trust-center-by-ettic'), esc_html($ot_issue_date));
                            }
                            if ($ot_expiry_date) {
                                /* translators: %s: certification expiry date */
                                $ot_date_parts[] = sprintf(esc_html__('Expires %s', 'open-trust-center-by-ettic'), esc_html($ot_expiry_date));
                            }
                            // Parts are esc_html'd above; empty allow-list is the visible late escape.
                            echo wp_kses(implode(' &middot; ', $ot_date_parts), []);
                            ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($ot_artifact_url): ?>
                        <a class="ettic-otc-cert-tile__artifact" href="<?php echo esc_url($ot_artifact_url); ?>" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M5 20h14v-2H5v2zm7-18l-5.5 5.5 1.41 1.41L11 5.83V16h2V5.83l3.09 3.08 1.41-1.41L12 2z" transform="rotate(180 12 12)"/></svg>
                            <?php echo $ot_is_audited
                                ? esc_html__('Download report', 'open-trust-center-by-ettic')
                                : esc_html__('View documentation', 'open-trust-center-by-ettic'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
