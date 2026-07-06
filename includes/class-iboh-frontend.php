<?php
/**
 * Front-end asset delivery and the live status banner.
 *
 * @package ITBoffins_Live_Opening_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers assets, localises the schedule config, and injects the banner.
 */
class IBOH_Frontend {

	/**
	 * Whether the banner has already been output this request.
	 *
	 * @var bool
	 */
	private $banner_rendered = false;

	/**
	 * Register front-end hooks.
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		$banner = IBOH_Settings::get( 'banner' );
		if ( empty( $banner['enabled'] ) ) {
			return;
		}

		if ( 'bottom' === $banner['position'] ) {
			add_action( 'wp_footer', array( $this, 'render_banner' ), 5 );
		} else {
			// Prefer wp_body_open, but also hook wp_footer for themes that do
			// not call wp_body_open. render_banner() is guarded to output once.
			add_action( 'wp_body_open', array( $this, 'render_banner' ) );
			add_action( 'wp_footer', array( $this, 'render_banner' ), 5 );
		}
	}

	/**
	 * Register (and conditionally enqueue) the front-end assets.
	 *
	 * The schedule config is localised onto the registered handle so it is
	 * available whether the script is loaded for the banner or on demand by the
	 * shortcode / block.
	 */
	public function register_assets() {
		wp_register_style( 'iboh-frontend', IBOH_URL . 'assets/frontend.css', array(), IBOH_VERSION );

		// Head marker for JS-capable browsers. The stylesheet gates the banner's
		// loading skeleton on html.iboh-js, so without JavaScript the
		// server-rendered fallback text stays visible instead of the skeleton.
		wp_register_script( 'iboh-boot', false, array(), IBOH_VERSION, false );
		wp_add_inline_script( 'iboh-boot', 'document.documentElement.classList.add("iboh-js");' );

		wp_register_script(
			'iboh-frontend',
			IBOH_URL . 'assets/frontend.js',
			array(),
			IBOH_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script( 'iboh-frontend', 'IBOH_DATA', IBOH_Config::data() );

		if ( ! empty( IBOH_Settings::get( 'banner' )['enabled'] ) ) {
			self::enqueue();
		}
	}

	/**
	 * Enqueue the registered front-end assets (safe to call repeatedly).
	 */
	public static function enqueue() {
		wp_enqueue_style( 'iboh-frontend' );
		wp_enqueue_script( 'iboh-boot' );
		wp_enqueue_script( 'iboh-frontend' );
	}

	/**
	 * Output the status banner with a server-rendered fallback inside.
	 */
	public function render_banner() {
		if ( $this->banner_rendered ) {
			return;
		}

		$banner = IBOH_Settings::get( 'banner' );
		if ( empty( $banner['enabled'] ) ) {
			return;
		}

		$this->banner_rendered = true;
		self::enqueue();

		$state = IBOH_Evaluator::state();
		$pos   = ( 'bottom' === $banner['position'] ) ? 'bottom' : 'top';
		$layout_class = empty( $banner['sticky'] ) ? 'iboh-not-sticky' : 'iboh-sticky';
		if ( 'top' === $pos ) {
			$layout_class .= empty( $banner['push_top'] ) ? ' iboh-overlay-top' : ' iboh-push-top';
		}
		$state_class     = $state['open'] ? 'iboh-open' : 'iboh-closed';
		$bg              = $state['open'] ? $banner['colour_open'] : $banner['colour_closed'];
		$upcoming_markup = ( isset( $state['upcoming_html'] ) && '' !== $state['upcoming_html'] )
			? $state['upcoming_html']
			: esc_html( $state['upcoming'] );

		// The element is always emitted (hidden when there is nothing to show)
		// so a cached page can still reveal a later upcoming-date announcement
		// once the browser recomputes the live state.
		$style = sprintf( 'background:%s;color:%s;', $bg, $banner['colour_text'] );
		if ( empty( $state['show'] ) ) {
			$style .= 'display:none;';
		}

		// A signature so a dismissed banner re-appears when schedule, wording,
		// styling, or the current fallback message changes.
		$sig = substr(
			md5(
				wp_json_encode(
					array(
						'banner'   => $banner,
						'labels'   => IBOH_Settings::get( 'labels' ),
						'options'  => IBOH_Settings::get( 'options' ),
						'schedule' => IBOH_Settings::get( 'schedule' ),
						'holidays' => IBOH_Settings::get( 'holidays' ),
						'state'    => $state,
					)
				)
			),
			0,
			8
		);

		printf(
			'<div class="iboh-banner iboh-loading iboh-pos-%1$s %2$s %3$s" data-iboh-banner data-iboh-dismissible="%4$s" data-iboh-sig="%5$s" aria-busy="true" style="%6$s">',
			esc_attr( $pos ),
			esc_attr( $layout_class ),
			esc_attr( $state_class ),
			esc_attr( empty( $banner['dismissible'] ) ? '0' : '1' ),
			esc_attr( $sig ),
			esc_attr( safecss_filter_attr( $style ) )
		);

		echo '<div class="iboh-banner-inner">';
		echo '<span class="iboh-banner-skeleton" aria-hidden="true"><span class="iboh-skeleton-main"></span><span class="iboh-skeleton-sub"></span></span>';
		echo '<span class="iboh-dot" aria-hidden="true"></span>';
		echo '<span class="iboh-banner-main" data-iboh-main>' . esc_html( $state['main'] ) . '</span>';
		echo ' <span class="iboh-banner-sub" data-iboh-sub>' . esc_html( $state['sub'] ) . '</span>';
		echo ' <span class="iboh-banner-upcoming" data-iboh-upcoming>' . wp_kses_post( $upcoming_markup ) . '</span>';
		echo '</div>';

		if ( ! empty( $banner['dismissible'] ) ) {
			printf(
				'<button type="button" class="iboh-banner-close" data-iboh-close aria-label="%s">&times;</button>',
				esc_attr__( 'Dismiss', 'itboffins-live-opening-hours' )
			);
		}

		echo '</div>';
	}
}
