<?php
/**
 * Ettic admin design system — Footer.php scaffold.
 *
 * BEFORE USING: replace `opentrust` / `OpenTrust` / `OPENTRUST` and fill
 * in the URL constants with your plugin's docs, repo, support, and review
 * links. The footer renders inside .opentrust-admin so the design tokens
 * resolve — caller is responsible for that wrapping.
 *
 * @package OpenTrust
 */

declare( strict_types=1 );

namespace OpenTrust\Admin;

defined( 'ABSPATH' ) || exit;

final class Footer {

	// Fill these in. Leaving a URL empty will still render the link — set the
	// final href before shipping.
	private const URL_DOCS     = 'https://plugins.ettic.nl/opentrust';
	private const URL_GITHUB   = 'https://github.com/EtticDevelopment/opentrust';
	private const URL_SUPPORT  = 'https://wordpress.org/support/plugin/opentrust/';
	private const URL_REVIEW   = 'https://wordpress.org/support/plugin/opentrust/reviews/';
	private const URL_SECURITY = 'https://docs.ettic.nl/docs/security';

	/** Emit the footer block. Caller must place it inside .opentrust-admin. */
	public static function render(): void {
		?>
		<footer class="opentrust-footer" role="contentinfo">
			<div class="opentrust-footer__inner">
				<div class="opentrust-footer__top">
					<div class="opentrust-footer__lead">
						<strong class="opentrust-footer__brand"><?php esc_html_e( 'OpenTrust by Ettic.', 'opentrust' ); ?></strong>
						<span class="opentrust-footer__tagline"><?php esc_html_e( 'Focused, open-source WordPress plugins.', 'opentrust' ); ?></span>
					</div>
					<span class="opentrust-footer__version">OpenTrust <span class="opentrust-footer__version-num">v<?php echo esc_html( OPENTRUST_VERSION ); ?></span></span>
				</div>
				<nav class="opentrust-footer__nav" aria-label="<?php esc_attr_e( 'OpenTrust resources', 'opentrust' ); ?>">
					<a href="<?php echo esc_url( self::URL_DOCS ); ?>"><?php esc_html_e( 'Docs', 'opentrust' ); ?></a>
					<span class="opentrust-footer__sep" aria-hidden="true">&middot;</span>
					<a href="<?php echo esc_url( self::URL_GITHUB ); ?>"><?php esc_html_e( 'GitHub', 'opentrust' ); ?></a>
					<span class="opentrust-footer__sep" aria-hidden="true">&middot;</span>
					<a href="<?php echo esc_url( self::URL_SUPPORT ); ?>"><?php esc_html_e( 'Support', 'opentrust' ); ?></a>
					<span class="opentrust-footer__sep" aria-hidden="true">&middot;</span>
					<a href="<?php echo esc_url( self::URL_SECURITY ); ?>"><?php esc_html_e( 'Report a vulnerability', 'opentrust' ); ?></a>
					<span class="opentrust-footer__sep" aria-hidden="true">&middot;</span>
					<a href="<?php echo esc_url( self::URL_REVIEW ); ?>" title="<?php esc_attr_e( 'Like OpenTrust? Leave a review and help us keep building open source.', 'opentrust' ); ?>"><?php esc_html_e( 'Leave a review', 'opentrust' ); ?></a>
				</nav>
			</div>
		</footer>
		<?php
	}
}
