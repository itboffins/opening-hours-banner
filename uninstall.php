<?php
/**
 * Uninstall cleanup: remove the plugin's single option.
 *
 * @package ITBoffins_Live_Opening_Hours
 */

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the per-site option for the current site.
 */
function iboh_uninstall_site() {
	delete_option( 'iboh_settings' );
}

if ( ! is_multisite() ) {
	iboh_uninstall_site();
	return;
}

// Network activation can leave an option on every site. Work in bounded
// batches so uninstall remains safe on large networks without direct SQL.
$iboh_offset = 0;
$iboh_limit  = 100;

do {
	$iboh_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => $iboh_limit,
			'offset' => $iboh_offset,
		)
	);

	foreach ( $iboh_site_ids as $iboh_site_id ) {
		switch_to_blog( (int) $iboh_site_id );
		iboh_uninstall_site();
		restore_current_blog();
	}

	$iboh_offset += $iboh_limit;
} while ( count( $iboh_site_ids ) === $iboh_limit );
