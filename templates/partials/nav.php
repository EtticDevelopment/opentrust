<?php
/**
 * Shared trust center navigation.
 *
 * Variables expected from parent:
 *   $ot_base_url, $ot_logo_url, $ot_company_name, $ot_ai_enabled, $ot_nav_items
 *   $ot_nav_anchor_base — prefix for section links ('' on the main page so they
 *                         stay same-page anchors; the trust center URL on the
 *                         single policy page so they jump back home).
 *   $ot_nav_scrollspy   — whether to wire links into the JS scroll-spy.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_nav_anchor_base = $ot_nav_anchor_base ?? '';
$ot_nav_scrollspy   = $ot_nav_scrollspy ?? false;
?>
<nav class="ot-nav" aria-label="<?php esc_attr_e('Trust center navigation', 'opentrust'); ?>">
    <div class="ot-container ot-nav__inner">
        <a href="<?php echo esc_url($ot_base_url); ?>" class="ot-nav__brand">
            <?php if ($ot_logo_url): ?>
                <img class="ot-nav__brand-logo"
                     src="<?php echo esc_url($ot_logo_url); ?>"
                     alt="<?php echo esc_attr($ot_company_name ?: get_bloginfo('name')); ?>">
            <?php else: ?>
                <span class="ot-nav__brand-name"><?php echo esc_html($ot_company_name ?: get_bloginfo('name')); ?></span>
            <?php endif; ?>
        </a>
        <?php if (count($ot_nav_items) > 1): ?>
            <div class="ot-nav__links">
                <?php foreach ($ot_nav_items as $ot_id => $ot_label): ?>
                    <a href="<?php echo esc_url($ot_nav_anchor_base . '#ot-' . $ot_id); ?>"
                       class="ot-nav__link"<?php echo $ot_nav_scrollspy ? ' data-ot-nav' : ''; ?>>
                        <?php echo esc_html($ot_label); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($ot_ai_enabled): ?>
            <div class="ot-nav__cta">
                <a href="<?php echo esc_url(trailingslashit($ot_base_url) . 'ask/'); ?>" class="ot-nav__ask">
                    <svg class="ot-nav__ask-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>
                        <path d="M20 3v4"/>
                        <path d="M22 5h-4"/>
                        <path d="M4 17v2"/>
                        <path d="M5 18H3"/>
                    </svg>
                    <span class="ot-nav__ask-label"><?php esc_html_e('Ask AI', 'opentrust'); ?></span>
                </a>
            </div>
        <?php endif; ?>
    </div>
</nav>
