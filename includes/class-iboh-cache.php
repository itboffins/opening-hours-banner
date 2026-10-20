<?php
/**
 * Ask common page caches to drop their copies when the schedule changes.
 *
 * The schedule definition is inlined into every page so the browser can keep
 * the live status fresh under full-page caching. The flip side is that a
 * schedule *edit* only reaches visitors once the cached HTML is regenerated,
 * so a save asks the caches the plugin knows about to purge.
 *
 * @package ITBoffins_Live_Opening_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache purge helper.
 */
class IBOH_Cache {

	/**
	 * Hook the option changes.
	 */
	public static function init() {
		add_action( 'update_option_' . IBOH_OPTION, array( __CLASS__, 'purge' ) );
		add_action( 'add_option_' . IBOH_OPTION, array( __CLASS__, 'purge' ) );
	}

	/**
	 * Purge the page caches this plugin knows how to talk to. Every call is
	 * guarded, so an absent plugin is simply skipped.
	 */
	public static function purge() {
		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		// WP Fastest Cache.
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
		}
		// SiteGround Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		// Autoptimize.
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
		}
		// Cache Enabler.
		if ( class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
			Cache_Enabler::clear_complete_cache();
		}
		// Action-based purges: LiteSpeed Cache, Breeze, Hummingbird, Nginx Helper.
		// These are the documented purge hooks of those plugins, so the hook
		// names are theirs, not this plugin's.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking third-party cache plugins' own purge hooks.
		do_action( 'litespeed_purge_all' );
		do_action( 'breeze_clear_all_cache' );
		do_action( 'wphb_clear_page_cache' );
		do_action( 'rt_nginx_helper_purge_all' );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		/**
		 * Fires after the schedule changed, so a host-specific or custom cache
		 * can be purged too.
		 */
		do_action( 'iboh_schedule_updated' );
	}
}
