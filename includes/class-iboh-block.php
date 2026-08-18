<?php
/**
 * Server-rendered "Live Opening Hours" block.
 *
 * Registered entirely in PHP (no build step). The editor script is hand-written
 * vanilla JS that relies on the WordPress packages already bundled with core
 * (wp.blocks, wp.element, wp.serverSideRender, wp.blockEditor, wp.components).
 * The block delegates rendering to the same code path as the shortcode.
 *
 * @package ITBoffins_Live_Opening_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block registration.
 */
class IBOH_Block {

	/**
	 * Register the block, its editor script, and shared preview styles.
	 */
	public function init() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; // WordPress < 5.0.
		}

		wp_register_style(
			'iboh-frontend',
			IBOH_URL . 'assets/frontend.css',
			array(),
			IBOH_VERSION
		);

		wp_register_script(
			'iboh-editor',
			IBOH_URL . 'assets/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			IBOH_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'iboh-editor', 'itboffins-live-opening-hours', IBOH_DIR . 'languages' );
		}

		register_block_type(
			'iboh/opening-hours',
			array(
				'api_version'     => 3,
				'editor_script'   => 'iboh-editor',
				'style'           => 'iboh-frontend',
				'editor_style'    => 'iboh-frontend',
				'attributes'      => array(
					'show' => array(
						'type'    => 'string',
						'default' => 'table',
					),
				),
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);
	}

	/**
	 * Render callback — reuse the shortcode renderer inside the standard block wrapper.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$show    = isset( $attributes['show'] ) ? $attributes['show'] : 'table';
		$content = IBOH_Shortcode::render( array( 'show' => $show ) );

		return sprintf(
			'<div %1$s>%2$s</div>',
			get_block_wrapper_attributes(),
			$content
		);
	}
}
