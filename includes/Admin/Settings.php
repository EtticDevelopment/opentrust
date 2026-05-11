<?php
/**
 * Ettic admin design system — Settings.php scaffold.
 *
 * BEFORE USING: replace `opentrust` / `OpenTrust` / `OPENTRUST` with your
 * plugin's identifiers, then prune or extend the example field renderers.
 *
 * Pattern: single serialized option (Settings API), single sanitize callback,
 * manual rendering (bypasses do_settings_sections) so we control the markup.
 *
 * Required constants from your main plugin file:
 *   OPENTRUST_FILE     absolute path to your main plugin file (__FILE__)
 *   OPENTRUST_URL      plugins_url() of your plugin (trailing slash)
 *   OPENTRUST_VERSION  version string used for asset cache-busting
 *
 * @package OpenTrust
 */

declare( strict_types=1 );

namespace OpenTrust\Admin;

defined( 'ABSPATH' ) || exit;

final class Settings {

	private const OPTION_GROUP = 'opentrust';
	private const OPTION_NAME  = 'opentrust_settings';
	private const PAGE_SLUG    = 'opentrust';

	public static function setup(): void {
		add_action( 'admin_init', [ self::class, 'register' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'current_screen', [ self::class, 'suppress_foreign_notices' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( OPENTRUST_FILE ), [ self::class, 'plugin_action_links' ] );
	}

	/**
	 * Scrub third-party admin notices on our settings screen — they wreck the
	 * dark hero layout. Scoped strictly to our screen ID. WP's own "Settings
	 * saved" pipeline runs through options-head.php and is unaffected.
	 */
	public static function suppress_foreign_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'settings_page_' . self::PAGE_SLUG !== $screen->id ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Prepend "Settings" link on the plugin row.
	 *
	 * @param array<int|string,string> $links
	 * @return array<int|string,string>
	 */
	public static function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'opentrust' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	public static function register(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'show_in_rest'      => false,
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);
	}

	public static function menu(): void {
		add_options_page(
			__( 'OpenTrust', 'opentrust' ),
			__( 'OpenTrust', 'opentrust' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	public static function enqueue( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_media(); // required for the media picker control
		wp_enqueue_style( 'opentrust-admin', OPENTRUST_URL . 'assets/css/opentrust-admin.css', [], OPENTRUST_VERSION );
		wp_enqueue_script( 'opentrust-admin', OPENTRUST_URL . 'assets/js/opentrust-admin.js', [], OPENTRUST_VERSION, true );
	}

	/**
	 * Default settings shape. Mirror this in your sanitize callback — anything
	 * you don't merge from $input falls through to the previous stored value.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'enabled'      => true,
			'mode'         => 'auto',
			'frequency'    => 5,
			'display_name' => '',
			'brand_color'  => '#0F5CFA',
			'logo_id'      => 0,
		];
	}

	/**
	 * Read a single setting with a fallback. Avoids leaking the option key all
	 * over the field renderers. Replace with your project's existing helper if
	 * you already have one.
	 *
	 * @param mixed $default
	 * @return mixed
	 */
	private static function get( string $key, $default = null ) {
		$settings = get_option( self::OPTION_NAME, self::defaults() );
		if ( ! is_array( $settings ) ) {
			return $default;
		}
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap opentrust-admin">
			<form method="post" action="options.php" class="opentrust-form">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<div class="opentrust-topbar__bar" role="banner">
					<div class="opentrust-topbar__brand">
						<svg class="opentrust-topbar__mark" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="OpenTrust">
							<rect width="26" height="26" rx="6" fill="#0F5CFA"/>
							<path transform="translate(4 4) scale(0.75)" d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-1 16l-4-4 1.41-1.41L11 14.17l6.59-6.59L19 9l-8 8z" fill="white"/>
						</svg>
						<span class="opentrust-topbar__name"><?php esc_html_e( 'OpenTrust', 'opentrust' ); ?></span>
						<span class="opentrust-topbar__version">v<?php echo esc_html( OPENTRUST_VERSION ); ?></span>
					</div>

					<div class="opentrust-topbar__right">
						<div class="opentrust-topbar__dirty is-clean" aria-live="polite" data-dirty>
							<span class="opentrust-topbar__dirty-dot" aria-hidden="true"></span>
							<span><span class="opentrust-topbar__dirty-num" data-dirty-num>0</span><span data-dirty-label></span></span>
						</div>

						<div class="opentrust-topbar__actions">
							<button type="button" class="opentrust-btn opentrust-btn--ghost-dark" data-discard disabled><?php esc_html_e( 'Discard', 'opentrust' ); ?></button>
							<button type="submit" class="opentrust-btn opentrust-btn--primary" data-save name="submit" disabled><?php esc_html_e( 'Save changes', 'opentrust' ); ?></button>
						</div>
					</div>
				</div>

				<div class="opentrust-topbar__head">
					<h1><?php esc_html_e( 'OpenTrust', 'opentrust' ); ?></h1>
					<p><?php esc_html_e( 'Self-hosted, open-source trust center for security policies, subprocessors, certifications, and data practices.', 'opentrust' ); ?></p>
				</div>

				<div class="opentrust-stack">
					<?php self::render_section_examples(); ?>
				</div>
			</form>
			<?php Footer::render(); ?>
		</div>
		<?php
	}

	/**
	 * Reference section — one row per control type. Copy what you need into
	 * your own sections, delete what you don't.
	 */
	private static function render_section_examples(): void {
		?>
		<section class="opentrust-block">
			<header class="opentrust-block__head">
				<h2><?php esc_html_e( 'Example controls', 'opentrust' ); ?></h2>
				<p><?php esc_html_e( 'One row per control type. Each demonstrates the markup, naming, and JS hooks expected by the design system.', 'opentrust' ); ?></p>
			</header>
			<div class="opentrust-card">
				<?php self::field_toggle(); ?>
				<?php self::field_segmented(); ?>
				<?php self::field_number(); ?>
				<?php self::field_text_with_counter(); ?>
				<?php self::field_select(); ?>
				<?php self::field_color(); ?>
				<?php self::field_media(); ?>
				<?php self::action_row_example(); ?>
			</div>
		</section>
		<?php
	}

	private static function field_toggle(): void {
		$value = (bool) self::get( 'enabled', true );
		$name  = sprintf( '%s[enabled]', self::OPTION_NAME );
		?>
		<div class="opentrust-row">
			<div class="opentrust-row__main">
				<span class="opentrust-row__label"><?php esc_html_e( 'Enabled', 'opentrust' ); ?></span>
				<p class="opentrust-row__help"><?php esc_html_e( 'Master on/off switch. Toggles render brand-blue when on.', 'opentrust' ); ?></p>
			</div>
			<div class="opentrust-row__control">
				<!-- Hidden 0 + checkbox 1 — guarantees an unchecked toggle still submits. -->
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<label class="opentrust-toggle">
					<input class="opentrust-toggle__input" type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $value ); ?>>
					<span class="opentrust-toggle__thumb"></span>
				</label>
			</div>
		</div>
		<?php
	}

	private static function field_segmented(): void {
		$value   = (int) self::get( 'frequency', 5 );
		$name    = sprintf( '%s[frequency]', self::OPTION_NAME );
		$presets = [ 1, 2, 3, 5, 10 ];
		?>
		<div class="opentrust-row">
			<div class="opentrust-row__main">
				<span class="opentrust-row__label"><?php esc_html_e( 'Frequency', 'opentrust' ); ?></span>
				<p class="opentrust-row__help"><?php esc_html_e( 'Use a segmented control for small enumerable choices. Radio inputs under the hood.', 'opentrust' ); ?></p>
			</div>
			<div class="opentrust-row__control">
				<div class="opentrust-seg" role="radiogroup" aria-label="<?php esc_attr_e( 'Frequency', 'opentrust' ); ?>">
					<?php foreach ( $presets as $opt ) : ?>
						<label class="opentrust-seg__btn">
							<input class="opentrust-seg__input" type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $opt ); ?>" <?php checked( $value, $opt ); ?>>
							<?php echo esc_html( (string) $opt ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private static function field_number(): void {
		$value = (int) self::get( 'frequency', 5 );
		$name  = sprintf( '%s[frequency_n]', self::OPTION_NAME );
		?>
		<div class="opentrust-row">
			<div class="opentrust-row__main">
				<span class="opentrust-row__label"><?php esc_html_e( 'Number input', 'opentrust' ); ?></span>
				<p class="opentrust-row__help"><?php esc_html_e( 'Use --num modifier to clamp width. Range constraints in HTML, validated again in sanitize.', 'opentrust' ); ?></p>
			</div>
			<div class="opentrust-row__control">
				<input type="number" class="opentrust-input opentrust-input--num" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" min="1" max="30">
				<span style="font-size:13px;color:var(--tx-muted);"><?php esc_html_e( 'minutes', 'opentrust' ); ?></span>
			</div>
		</div>
		<?php
	}

	private static function field_text_with_counter(): void {
		$value = (string) self::get( 'display_name', '' );
		$name  = sprintf( '%s[display_name]', self::OPTION_NAME );
		?>
		<div class="opentrust-row">
			<div class="opentrust-row__main">
				<span class="opentrust-row__label"><?php esc_html_e( 'Display name', 'opentrust' ); ?></span>
				<p class="opentrust-row__help"><?php esc_html_e( 'data-counter + maxlength auto-renders a "n / max" counter below the input.', 'opentrust' ); ?></p>
			</div>
			<div class="opentrust-row__control opentrust-row__control--stack">
				<input type="text" class="opentrust-input opentrust-input--md" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" maxlength="60" data-counter>
			</div>
		</div>
		<?php
	}

	private static function field_select(): void {
		$value   = (string) self::get( 'mode', 'auto' );
		$name    = sprintf( '%s[mode]', self::OPTION_NAME );
		$options = [
			'auto'   => __( 'Auto', 'opentrust' ),
			'manual' => __( 'Manual', 'opentrust' ),
			'off'    => __( 'Off', 'opentrust' ),
		];
		?>
		<div class="opentrust-row">
			<div class="opentrust-row__main">
				<span class="opentrust-row__label"><?php esc_html_e( 'Mode', 'opentrust' ); ?></span>
				<p class="opentrust-row__help"><?php esc_html_e( 'Native <select> wrapped in .opentrust-select for the chevron and focus ring.', 'opentrust' ); ?></p>
			</div>
			<div class="opentrust-row__control">
				<div class="opentrust-select">
					<select name="<?php echo esc_attr( $name ); ?>">
						<?php foreach ( $options as $opt_value => $label ) : ?>
							<option value="<?php echo esc_attr( $opt_value ); ?>" <?php selected( $value, $opt_value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
		</div>
		<?php
	}

	private static function field_color(): void {
		$value = (string) self::get( 'brand_color', '#0F5CFA' );
		$name  = sprintf( '%s[brand_color]', self::OPTION_NAME );
		?>
		<div class="opentrust-row">
			<div class="opentrust-row__main">
				<span class="opentrust-row__label"><?php esc_html_e( 'Brand color', 'opentrust' ); ?></span>
				<p class="opentrust-row__help"><?php esc_html_e( 'Native color swatch fused with a hex input. JS keeps them in sync and live-validates.', 'opentrust' ); ?></p>
			</div>
			<div class="opentrust-row__control opentrust-row__control--stack">
				<div class="opentrust-color">
					<input type="color" value="<?php echo esc_attr( $value ); ?>" aria-label="<?php esc_attr_e( 'Color picker', 'opentrust' ); ?>">
					<input type="text" class="opentrust-input opentrust-input--mono" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" maxlength="7" data-validate-hex>
				</div>
			</div>
		</div>
		<?php
	}

	private static function field_media(): void {
		$attachment_id = (int) self::get( 'logo_id', 0 );
		$src           = $attachment_id ? wp_get_attachment_image_src( $attachment_id, 'medium' ) : false;
		$url           = is_array( $src ) ? (string) $src[0] : '';
		$name          = sprintf( '%s[logo_id]', self::OPTION_NAME );
		?>
		<div class="opentrust-row">
			<div class="opentrust-row__main">
				<span class="opentrust-row__label"><?php esc_html_e( 'Logo', 'opentrust' ); ?></span>
				<p class="opentrust-row__help"><?php esc_html_e( 'Stores the attachment ID. JS binds wp.media to any [data-opentrust-media-picker].', 'opentrust' ); ?></p>
			</div>
			<div class="opentrust-row__control">
				<div class="opentrust-media" data-opentrust-media-picker="logo_id">
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $attachment_id ); ?>" data-opentrust-media-id>
					<div class="opentrust-media__preview <?php echo $url ? 'opentrust-media__preview--filled' : ''; ?>" data-opentrust-media-preview>
						<?php if ( $url ) : ?>
							<img src="<?php echo esc_url( $url ); ?>" alt="">
						<?php endif; ?>
					</div>
					<div class="opentrust-media__controls">
						<button type="button" class="opentrust-btn opentrust-btn--ghost opentrust-btn--sm" data-opentrust-media-pick><?php esc_html_e( 'Replace', 'opentrust' ); ?></button>
						<button type="button" class="opentrust-btn opentrust-btn--text" data-opentrust-media-clear style="<?php echo $attachment_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'opentrust' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Action-row example — utility actions inside a card (not bound to a
	 * setting). Wire the button to a wp_ajax handler and surface results
	 * through showToast(). See the comment block at the end of the JS file.
	 */
	private static function action_row_example(): void {
		?>
		<div class="opentrust-action-row">
			<div class="opentrust-action-row__main">
				<h3><?php esc_html_e( 'Run a diagnostic', 'opentrust' ); ?></h3>
				<p><?php esc_html_e( 'Use action rows for one-shot operations. Confirmation dialogs are optional — required for destructive actions.', 'opentrust' ); ?></p>
			</div>
			<button type="button" class="opentrust-btn opentrust-btn--ghost opentrust-btn--sm" data-opentrust-action="run_diagnostic">
				<?php esc_html_e( 'Run now', 'opentrust' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Sanitize callback — security-critical. Always merge into the existing
	 * stored array; never replace wholesale, since partial form submissions
	 * (e.g. via AJAX from a sub-panel) would otherwise wipe other fields.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$current = get_option( self::OPTION_NAME, self::defaults() );
		if ( ! is_array( $current ) ) {
			$current = self::defaults();
		}
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$out = $current;

		// Toggle: hidden 0 + checkbox 1 means $input['enabled'] is always set.
		$out['enabled'] = ! empty( $input['enabled'] );

		if ( isset( $input['mode'] ) ) {
			$allowed     = [ 'auto', 'manual', 'off' ];
			$candidate   = (string) $input['mode'];
			$out['mode'] = in_array( $candidate, $allowed, true ) ? $candidate : 'auto';
		}

		if ( isset( $input['frequency'] ) ) {
			$out['frequency'] = max( 1, min( 30, absint( $input['frequency'] ) ) );
		}

		if ( isset( $input['display_name'] ) ) {
			$clean                = sanitize_text_field( (string) $input['display_name'] );
			$out['display_name']  = function_exists( 'mb_substr' ) ? mb_substr( $clean, 0, 60 ) : substr( $clean, 0, 60 );
		}

		if ( isset( $input['brand_color'] ) ) {
			$sanitized          = sanitize_hex_color( (string) $input['brand_color'] );
			$out['brand_color'] = $sanitized ? $sanitized : '#0F5CFA';
		}

		if ( isset( $input['logo_id'] ) ) {
			$id              = absint( $input['logo_id'] );
			$out['logo_id']  = ( $id > 0 && wp_attachment_is_image( $id ) ) ? $id : 0;
		}

		return $out;
	}
}
