<?php
/**
 * Plugin Name:       PressHangar Draft Pacer
 * Plugin URI:        https://presshangar.com/presshangar-draft-pacer
 * Description:       Source-agnostic natural pacing publishing layer for WordPress drafts, by PressHangar (a brand of Musubiemu LLC). Schedules drafts as "future" posts with human-like intervals and guarantees zero missed publications via a self-healing watchdog. Does not generate any content.
 * Version:           0.2.10
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Musubiemu LLC
 * Author URI:        https://presshangar.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       presshangar-draft-pacer
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'PHDRIP_VERSION', '0.2.10' );

/** Absolute path to the main plugin file. */
define( 'PHDRIP_PLUGIN_FILE', __FILE__ );

/** Absolute path to the plugin directory, with trailing slash. */
define( 'PHDRIP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** URL to the plugin directory, with trailing slash. */
define( 'PHDRIP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Plugin basename, used for activation/deactivation hooks. */
define( 'PHDRIP_BASENAME', plugin_basename( __FILE__ ) );

/** Option name holding the single settings array. */
define( 'PHDRIP_OPTION_SETTINGS', 'phdrip_settings' );

/** Option name holding internal runtime state. */
define( 'PHDRIP_OPTION_STATE', 'phdrip_state' );

/** Post meta key marking posts scheduled by this plugin. */
define( 'PHDRIP_META_SCHEDULED', '_phdrip_scheduled' );

/** Post meta key storing a scheduled post's original post_date, for restoring on unschedule. */
define( 'PHDRIP_META_ORIG_DATE', '_phdrip_orig_date' );

/** Cron hook: daily drip scheduling. */
define( 'PHDRIP_CRON_SCHEDULE', 'phdrip_daily_schedule' );

/** Cron hook: 15-minute watchdog. */
define( 'PHDRIP_CRON_WATCHDOG', 'phdrip_watchdog' );

/** Custom cron recurrence used by the watchdog. */
define( 'PHDRIP_CRON_INTERVAL', 'phdrip_15min' );

require_once PHDRIP_PLUGIN_DIR . 'includes/class-phdrip-scheduler.php';
require_once PHDRIP_PLUGIN_DIR . 'includes/class-phdrip-watchdog.php';
require_once PHDRIP_PLUGIN_DIR . 'includes/class-phdrip-settings.php';
require_once PHDRIP_PLUGIN_DIR . 'includes/class-phdrip-admin.php';

/**
 * Load the plugin text domain for translations.
 */
function phdrip_load_textdomain() {
	load_plugin_textdomain(
		'presshangar-draft-pacer',
		false,
		dirname( plugin_basename( PHDRIP_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'phdrip_load_textdomain' );

/**
 * Register the custom "every 15 minutes" cron schedule.
 *
 * @param array $schedules Existing cron schedules.
 * @return array Modified cron schedules.
 */
function phdrip_register_cron_schedules( $schedules ) {
	return PHDRIP_Watchdog::register_cron_schedule( $schedules );
}
add_filter( 'cron_schedules', 'phdrip_register_cron_schedules' );

/**
 * Run the drip scheduling routine.
 *
 * Wraps PHDRIP_Scheduler::schedule_drafts() so it can be used both as a cron
 * callback and called directly from the admin "run now" action. This
 * function always runs regardless of the `enabled` setting; it is the
 * explicit, unconditional entry point used by the manual admin button.
 *
 * @return array Result summary. See PHDRIP_Scheduler::schedule_drafts().
 */
function phdrip_schedule_drafts() {
	return PHDRIP_Scheduler::schedule_drafts();
}

/**
 * Cron callback for PHDRIP_CRON_SCHEDULE.
 *
 * Unlike phdrip_schedule_drafts(), this respects the `enabled` setting: the
 * daily cron event is always registered, but it is a no-op while automatic
 * scheduling is disabled.
 */
function phdrip_cron_schedule_drafts() {
	$settings = PHDRIP_Settings::get_settings();

	if ( empty( $settings['enabled'] ) ) {
		return;
	}

	phdrip_schedule_drafts();
}
add_action( PHDRIP_CRON_SCHEDULE, 'phdrip_cron_schedule_drafts' );

/**
 * Run the watchdog routine (recovery + cron health bookkeeping).
 */
function phdrip_run_watchdog() {
	PHDRIP_Watchdog::run();
}
add_action( PHDRIP_CRON_WATCHDOG, 'phdrip_run_watchdog' );

PHDRIP_Settings::init();
PHDRIP_Admin::init();

/**
 * Ensure both cron events are scheduled (idempotent).
 *
 * The daily schedule event fires roughly at the configured time_start on
 * the following day; the watchdog fires immediately and then every 15
 * minutes.
 */
function phdrip_schedule_cron_events() {
	if ( ! wp_next_scheduled( PHDRIP_CRON_SCHEDULE ) ) {
		$settings = PHDRIP_Settings::get_settings();
		$tz       = wp_timezone();

		$parts  = explode( ':', $settings['time_start'] );
		$hour   = isset( $parts[0] ) ? (int) $parts[0] : 9;
		$minute = isset( $parts[1] ) ? (int) $parts[1] : 0;

		$now       = new DateTimeImmutable( 'now', $tz );
		$first_run = $now->modify( '+1 day' )->setTime( $hour, $minute, 0 );

		wp_schedule_event( $first_run->getTimestamp(), 'daily', PHDRIP_CRON_SCHEDULE );
	}

	if ( ! wp_next_scheduled( PHDRIP_CRON_WATCHDOG ) ) {
		wp_schedule_event( time(), PHDRIP_CRON_INTERVAL, PHDRIP_CRON_WATCHDOG );
	}
}
// Also re-check on every request (idempotent) in case a cron event was
// unregistered externally (e.g. by another plugin or a server hiccup)
// without going through our deactivation hook.
add_action( 'init', 'phdrip_schedule_cron_events' );

/**
 * Plugin activation callback.
 *
 * Saves default settings (without overwriting any existing settings) and
 * schedules the two cron events.
 */
function phdrip_activate() {
	if ( false === get_option( PHDRIP_OPTION_SETTINGS ) ) {
		update_option( PHDRIP_OPTION_SETTINGS, PHDRIP_Settings::get_defaults() );
	}

	if ( false === get_option( PHDRIP_OPTION_STATE ) ) {
		update_option(
			PHDRIP_OPTION_STATE,
			array(
				'last_cron_run'         => 0,
				'last_schedule_run'     => 0,
				'last_schedule_count'   => 0,
				'last_schedule_date'    => '',
				'recovered_count'       => 0,
				'last_notify_date'      => '',
				'activated_at'          => time(),
			)
		);
	}

	phdrip_schedule_cron_events();
}
register_activation_hook( PHDRIP_PLUGIN_FILE, 'phdrip_activate' );

/**
 * Plugin deactivation callback.
 *
 * Clears both scheduled cron hooks. Settings/state options are left intact
 * (they are removed on uninstall instead).
 */
function phdrip_deactivate() {
	wp_clear_scheduled_hook( PHDRIP_CRON_SCHEDULE );
	wp_clear_scheduled_hook( PHDRIP_CRON_WATCHDOG );
}
register_deactivation_hook( PHDRIP_PLUGIN_FILE, 'phdrip_deactivate' );
