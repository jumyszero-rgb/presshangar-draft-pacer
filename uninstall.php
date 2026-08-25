<?php
/**
 * Uninstall routine: removes all plugin options and post meta.
 *
 * @package PressHangar Draft Pacer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress defines WP_UNINSTALL_PLUGIN when this file is loaded as part of
// a proper plugin uninstall; bail if accessed any other way.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'phdrip_settings' );
delete_option( 'phdrip_state' );

delete_post_meta_by_key( '_phdrip_scheduled' );
delete_post_meta_by_key( '_phdrip_orig_date' );
