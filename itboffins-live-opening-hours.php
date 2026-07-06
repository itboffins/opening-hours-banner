<?php
/**
 * Plugin Name:       ITBoffins Live Opening Hours Banner & Table
 * Plugin URI:        https://itboffins.com/plugins/itboffins-live-opening-hours/
 * Description:       A live open/closed banner and opening-hours table powered by one private WordPress schedule.
 * Version:           1.1.12
 * Requires at least: 6.3
 * Requires PHP:      7.2
 * Author:            IT Boffins
 * Author URI:        https://itboffins.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       itboffins-live-opening-hours
 * Domain Path:       /languages
 *
 * @package ITBoffins_Live_Opening_Hours
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IBOH_VERSION', '1.1.12' );
define( 'IBOH_FILE', __FILE__ );
define( 'IBOH_DIR', plugin_dir_path( __FILE__ ) );
define( 'IBOH_URL', plugin_dir_url( __FILE__ ) );
define( 'IBOH_BASENAME', plugin_basename( __FILE__ ) );

// Single option key in wp_options.
define( 'IBOH_OPTION', 'iboh_settings' );

require_once IBOH_DIR . 'includes/class-iboh-settings.php';
require_once IBOH_DIR . 'includes/class-iboh-timezone.php';
require_once IBOH_DIR . 'includes/class-iboh-config.php';
require_once IBOH_DIR . 'includes/class-iboh-evaluator.php';
require_once IBOH_DIR . 'includes/class-iboh-frontend.php';
require_once IBOH_DIR . 'includes/class-iboh-shortcode.php';
require_once IBOH_DIR . 'includes/class-iboh-block.php';
require_once IBOH_DIR . 'includes/class-iboh-admin.php';

/**
 * Main plugin bootstrap.
 */
final class IBOH_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var IBOH_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return IBOH_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up the plugin.
	 */
	private function __construct() {
		// Front-end banner + asset delivery.
		( new IBOH_Frontend() )->init();

		// Shortcode and block (both safe to register everywhere).
		add_action( 'init', array( new IBOH_Shortcode(), 'init' ) );
		add_action( 'init', array( new IBOH_Block(), 'init' ) );

		// Admin UI.
		if ( is_admin() ) {
			( new IBOH_Admin() )->init();
		}


	}
}

/**
 * Set sensible defaults on activation.
 */
function iboh_activate() {
	if ( false === get_option( IBOH_OPTION ) ) {
		add_option( IBOH_OPTION, IBOH_Settings::defaults() );
	}
}
register_activation_hook( __FILE__, 'iboh_activate' );

// Go.
add_action( 'plugins_loaded', array( 'IBOH_Plugin', 'instance' ) );
