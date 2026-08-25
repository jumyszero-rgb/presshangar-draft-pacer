<?php
/**
 * Failure recovery and cron health watchdog.
 *
 * @package PressHangar Draft Pacer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHDRIP_Watchdog
 *
 * Runs every 15 minutes via the `phdrip_watchdog` cron hook. Recovers posts
 * that missed their scheduled publish time (WP-Cron failure) and tracks
 * cron health so the admin screen can surface a warning.
 */
class PHDRIP_Watchdog {

	/**
	 * How late (in seconds) a `future` post's post_date_gmt must be before
	 * it is considered missed and force-published.
	 */
	const RECOVERY_THRESHOLD = 5 * MINUTE_IN_SECONDS;

	/**
	 * Cron run is considered unhealthy once it hasn't fired for this long.
	 */
	const HEALTH_THRESHOLD = 2 * HOUR_IN_SECONDS;

	/**
	 * Maximum number of overdue posts recovered in a single watchdog run.
	 * The rest are picked up on the next run.
	 */
	const RECOVERY_BATCH_LIMIT = 20;

	/**
	 * Register the custom `phdrip_15min` cron recurrence.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function register_cron_schedule( $schedules ) {
		$schedules['phdrip_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 Minutes (PressHangar Draft Pacer)', 'presshangar-draft-pacer' ),
		);

		return $schedules;
	}

	/**
	 * Main watchdog routine: record cron health first (so a timeout further
	 * down never masquerades as a dead cron), then recover missed posts (if
	 * enabled) and notify.
	 */
	public static function run() {
		// Persist last_cron_run immediately, before the recovery loop runs,
		// so a slow/timed-out recovery pass still counts as a live cron.
		$state                  = get_option( PHDRIP_OPTION_STATE, array() );
		$state['last_cron_run'] = time();
		update_option( PHDRIP_OPTION_STATE, $state );

		$settings = PHDRIP_Settings::get_settings();

		if ( ! empty( $settings['recovery_enabled'] ) ) {
			$recovered = self::recover_missed_posts();

			if ( $recovered > 0 ) {
				$state['recovered_count'] = isset( $state['recovered_count'] ) ? $state['recovered_count'] + $recovered : $recovered;

				if ( ! empty( $settings['notify_email'] ) ) {
					self::maybe_notify( $settings['notify_email'], $recovered, $state );
				}

				update_option( PHDRIP_OPTION_STATE, $state );
			}
		}
	}

	/**
	 * Force-publish `future` posts whose post_date_gmt is more than
	 * RECOVERY_THRESHOLD in the past (missed by a stalled WP-Cron).
	 *
	 * Restricted to posts carrying the `_phdrip_scheduled` meta by default;
	 * the `phdrip_recovery_meta_query` filter allows advanced users to widen
	 * (or otherwise adjust) the query args. Capped at
	 * RECOVERY_BATCH_LIMIT posts per run, oldest first, so a very large
	 * backlog doesn't hazard a single run; the remainder is picked up on
	 * the next 15-minute cycle.
	 *
	 * @return int Number of posts recovered in this run.
	 */
	private static function recover_missed_posts() {
		$threshold = gmdate( 'Y-m-d H:i:s', time() - self::RECOVERY_THRESHOLD );

		$query_args = array(
			'post_status' => 'future',
			'post_type'   => 'any',
			'numberposts' => self::RECOVERY_BATCH_LIMIT,
			'orderby'     => 'date',
			'order'       => 'ASC',
			'fields'      => 'ids',
			'meta_key'    => PHDRIP_META_SCHEDULED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Querying the plugin's own scheduling meta is core to its function.
			'date_query'  => array(
				array(
					'column' => 'post_date_gmt',
					'before' => $threshold,
				),
			),
		);

		/**
		 * Filter the get_posts() args used to find overdue "future" posts to
		 * recover. By default this is restricted to posts carrying the
		 * `_phdrip_scheduled` meta key; use this filter to widen (e.g. remove
		 * the meta_key restriction) or otherwise adjust the query.
		 *
		 * @param array $query_args get_posts() arguments.
		 */
		$query_args = apply_filters( 'phdrip_recovery_meta_query', $query_args );

		$post_ids = get_posts( $query_args );

		$count = 0;

		foreach ( $post_ids as $post_id ) {
			wp_publish_post( $post_id );
			++$count;
		}

		return $count;
	}

	/**
	 * Send a recovery notification email, throttled to at most one per day.
	 *
	 * @param string $email     Destination email address.
	 * @param int    $recovered Number of posts recovered in this run.
	 * @param array  $state     Reference to the in-memory state array, updated with the notify date.
	 */
	private static function maybe_notify( $email, $recovered, &$state ) {
		$today = current_time( 'Y-m-d' );

		if ( isset( $state['last_notify_date'] ) && $today === $state['last_notify_date'] ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: number of recovered posts. */
			__( '[%1$s] PressHangar Draft Pacer recovered %2$d missed post(s)', 'presshangar-draft-pacer' ),
			get_bloginfo( 'name' ),
			$recovered
		);

		$message = sprintf(
			/* translators: %d: number of recovered posts. */
			__( 'PressHangar Draft Pacer detected %d post(s) that failed to publish on their scheduled time (likely a WP-Cron issue) and republished them immediately. You may want to check that WP-Cron is running reliably on your site.', 'presshangar-draft-pacer' ),
			$recovered
		);

		wp_mail( $email, $subject, $message );

		$state['last_notify_date'] = $today;
	}

	/**
	 * Whether WP-Cron appears to be running (based on the last recorded
	 * watchdog run).
	 *
	 * Falls back to the plugin's activation time when the watchdog has
	 * never run yet, so a stuck cron right after activation is still
	 * reported as unhealthy once 2 hours have passed rather than forever
	 * reading as OK.
	 *
	 * @return bool True when healthy, false when overdue.
	 */
	public static function is_cron_healthy() {
		$state = get_option( PHDRIP_OPTION_STATE, array() );

		if ( ! empty( $state['last_cron_run'] ) ) {
			return ( time() - (int) $state['last_cron_run'] ) <= self::HEALTH_THRESHOLD;
		}

		if ( ! empty( $state['activated_at'] ) ) {
			return ( time() - (int) $state['activated_at'] ) <= self::HEALTH_THRESHOLD;
		}

		// Neither recorded (e.g. upgrade from an older version): seed
		// activated_at now and report healthy for this one check.
		$state['activated_at'] = time();
		update_option( PHDRIP_OPTION_STATE, $state );

		return true;
	}

	/**
	 * Timestamp of the last watchdog run, or 0 if it has never run.
	 *
	 * @return int
	 */
	public static function get_last_cron_run() {
		$state = get_option( PHDRIP_OPTION_STATE, array() );

		return isset( $state['last_cron_run'] ) ? (int) $state['last_cron_run'] : 0;
	}

	/**
	 * Whether the DISABLE_WP_CRON constant is defined and truthy.
	 *
	 * @return bool
	 */
	public static function is_wp_cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Build ready-to-copy external cron command lines (wget and curl
	 * variants) pointing at this site's wp-cron.php.
	 *
	 * @return array {
	 *     @type string $wget Crontab line using wget.
	 *     @type string $curl Crontab line using curl.
	 * }
	 */
	public static function get_external_cron_lines() {
		$url = site_url( 'wp-cron.php?doing_wp_cron' );

		return array(
			'wget' => sprintf( '*/15 * * * * wget -q -O /dev/null "%s" >/dev/null 2>&1', esc_url_raw( $url ) ),
			'curl' => sprintf( '*/15 * * * * curl -s "%s" >/dev/null 2>&1', esc_url_raw( $url ) ),
		);
	}
}
