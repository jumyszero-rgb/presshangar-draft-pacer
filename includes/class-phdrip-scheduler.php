<?php
/**
 * Drip assignment scheduler.
 *
 * @package PressHangar Draft Pacer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHDRIP_Scheduler
 *
 * Assigns draft posts a "future" publish date, spread out over multiple
 * days with a natural, human-like pacing.
 */
class PHDRIP_Scheduler {

	/**
	 * Minimum allowed gap, in minutes, between two posts scheduled on the
	 * same day.
	 */
	const MIN_GAP_MINUTES = 15;

	/**
	 * Run the drip scheduling routine.
	 *
	 * 1. Fetches draft posts matching the configured post types (and, when
	 *    set, categories).
	 * 2. Orders them randomly or oldest-first.
	 * 3. Determines the first day to assign, guaranteeing idempotency
	 *    (re-running never collides with already scheduled posts).
	 * 4. Assigns a random number of posts per day, at random minute-level
	 *    times within the configured window, at least 15 minutes apart
	 *    (with an automatic gap shrink if the window is too small), plus a
	 *    random second to avoid exact collisions.
	 * 5. Updates each post to `future` status with the computed date.
	 * 6. Records the result in `phdrip_state`.
	 *
	 * @return array {
	 *     Result summary.
	 *
	 *     @type int    $scheduled Number of posts successfully scheduled.
	 *     @type int    $failed    Number of posts that failed to update and were left as drafts.
	 *     @type string $last_date Local date/time string of the last scheduled post, or ''.
	 *     @type string $message   Human readable summary message.
	 * }
	 */
	public static function schedule_drafts() {
		$settings   = PHDRIP_Settings::get_settings();
		$post_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );
		$categories = ! empty( $settings['categories'] ) ? array_map( 'absint', (array) $settings['categories'] ) : array();

		$drafts = self::get_draft_posts( $post_types, $categories );

		if ( empty( $drafts ) ) {
			self::update_state_after_run( 0, '' );

			return array(
				'scheduled' => 0,
				'failed'    => 0,
				'last_date' => '',
				'message'   => __( 'No draft posts were found to schedule.', 'presshangar-draft-pacer' ),
			);
		}

		if ( 'random' === $settings['order'] ) {
			shuffle( $drafts );
		}
		// 'oldest_first' keeps the ASC post_date ordering already applied by the query.

		$timezone   = wp_timezone();
		$start_day  = self::determine_start_day( $settings, $post_types, $timezone );
		$min_per_day = max( 1, (int) $settings['min_per_day'] );
		$max_per_day = max( $min_per_day, (int) $settings['max_per_day'] );

		$total     = count( $drafts );
		$index     = 0;
		$current   = $start_day;
		$last_date = '';
		$scheduled = 0;
		$failed    = 0;

		while ( $index < $total ) {
			$count_today = wp_rand( $min_per_day, $max_per_day );
			$times       = self::generate_times_for_day( $current, $settings['time_start'], $settings['time_end'], $count_today );

			foreach ( $times as $dt ) {
				if ( $index >= $total ) {
					break;
				}

				$post  = $drafts[ $index ];
				$local = $dt->format( 'Y-m-d H:i:s' );
				$gmt   = get_gmt_from_date( $local );

				$update_result = wp_update_post(
					array(
						'ID'            => $post->ID,
						'post_status'   => 'future',
						'post_date'     => $local,
						'post_date_gmt' => $gmt,
						'edit_date'     => true,
					),
					true
				);

				++$index;

				if ( is_wp_error( $update_result ) || ! $update_result ) {
					++$failed;
					continue;
				}

				update_post_meta( $post->ID, PHDRIP_META_SCHEDULED, 1 );
				update_post_meta( $post->ID, PHDRIP_META_ORIG_DATE, $post->post_date );

				$last_date = $local;
				++$scheduled;
			}

			$current = $current->modify( '+1 day' );
		}

		self::update_state_after_run( $scheduled, $last_date );

		if ( $failed > 0 ) {
			$message = sprintf(
				/* translators: 1: number of posts scheduled, 2: date/time of the last scheduled post, 3: number of posts that failed. */
				__( 'Scheduled %1$d draft(s). Last post is set for %2$s. %3$d failed and were left as drafts.', 'presshangar-draft-pacer' ),
				$scheduled,
				$last_date,
				$failed
			);
		} else {
			$message = sprintf(
				/* translators: 1: number of posts scheduled, 2: date/time of the last scheduled post. */
				__( 'Scheduled %1$d draft(s). Last post is set for %2$s.', 'presshangar-draft-pacer' ),
				$scheduled,
				$last_date
			);
		}

		return array(
			'scheduled' => $scheduled,
			'failed'    => $failed,
			'last_date' => $last_date,
			'message'   => $message,
		);
	}

	/**
	 * Fetch draft posts matching the configured post types and (optional)
	 * categories.
	 *
	 * `category__in` only applies meaningfully to the "post" post type (the
	 * built-in `category` taxonomy isn't registered for arbitrary post
	 * types/pages). When categories are set and post_types includes types
	 * other than "post", two separate queries are run and merged: "post"
	 * with the category filter applied, and the other types without it.
	 *
	 * @param array $post_types Post types to fetch drafts for.
	 * @param array $categories Category term IDs to filter "post" by (empty = no filter).
	 * @return WP_Post[] Draft posts, ordered by post_date ascending.
	 */
	private static function get_draft_posts( $post_types, $categories ) {
		$base_args = array(
			'post_status' => 'draft',
			'numberposts' => -1,
			'orderby'     => 'date',
			'order'       => 'ASC',
		);

		if ( empty( $categories ) ) {
			$args              = $base_args;
			$args['post_type'] = $post_types;

			return get_posts( $args );
		}

		$other_types = array_values( array_diff( $post_types, array( 'post' ) ) );
		$drafts      = array();

		if ( in_array( 'post', $post_types, true ) ) {
			$post_args                 = $base_args;
			$post_args['post_type']    = 'post';
			$post_args['category__in'] = $categories;
			$drafts                    = array_merge( $drafts, get_posts( $post_args ) );
		}

		if ( ! empty( $other_types ) ) {
			$other_args              = $base_args;
			$other_args['post_type'] = $other_types;
			$drafts                  = array_merge( $drafts, get_posts( $other_args ) );
		}

		// The two queries are independently ordered; re-sort the merged set.
		usort(
			$drafts,
			function ( $a, $b ) {
				return strcmp( $a->post_date, $b->post_date );
			}
		);

		return $drafts;
	}

	/**
	 * Unschedule every post previously scheduled by this plugin, reverting
	 * them back to draft status and restoring their original post_date
	 * (when available).
	 *
	 * @return int Number of posts reverted.
	 */
	public static function unschedule_all() {
		$post_ids = get_posts(
			array(
				'post_status' => 'future',
				'post_type'   => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => PHDRIP_META_SCHEDULED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Querying the plugin's own scheduling meta is core to its function.
			)
		);

		$count = 0;

		foreach ( $post_ids as $post_id ) {
			$orig_date = get_post_meta( $post_id, PHDRIP_META_ORIG_DATE, true );

			$update_args = array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			);

			if ( $orig_date ) {
				$update_args['post_date']     = $orig_date;
				$update_args['post_date_gmt'] = get_gmt_from_date( $orig_date );
				$update_args['edit_date']     = true;
			}

			wp_update_post( $update_args );
			delete_post_meta( $post_id, PHDRIP_META_SCHEDULED );
			delete_post_meta( $post_id, PHDRIP_META_ORIG_DATE );
			++$count;
		}

		return $count;
	}

	/**
	 * Determine the first day new posts should be assigned to.
	 *
	 * This is the later of (a) max(start_date, tomorrow) and (b) the day
	 * after the last already-scheduled future post, which guarantees that
	 * re-running the scheduler never collides with existing bookings.
	 *
	 * @param array            $settings   Plugin settings.
	 * @param array            $post_types Post types being scheduled.
	 * @param DateTimeZone     $timezone   Site timezone.
	 * @return DateTimeImmutable Midnight of the first day to assign.
	 */
	private static function determine_start_day( $settings, $post_types, $timezone ) {
		$now      = new DateTimeImmutable( 'now', $timezone );
		$tomorrow = $now->modify( '+1 day' )->setTime( 0, 0, 0 );

		$configured_start = ! empty( $settings['start_date'] ) ? $settings['start_date'] : '';

		try {
			if ( '' !== $configured_start ) {
				$start_day = ( new DateTimeImmutable( $configured_start, $timezone ) )->setTime( 0, 0, 0 );
			} else {
				$start_day = $tomorrow;
			}
		} catch ( Exception $e ) {
			$start_day = $tomorrow;
		}

		if ( $start_day < $tomorrow ) {
			$start_day = $tomorrow;
		}

		$last_scheduled = self::get_last_scheduled_date( $post_types, $timezone );

		if ( null !== $last_scheduled ) {
			$day_after_last = $last_scheduled->setTime( 0, 0, 0 )->modify( '+1 day' );

			if ( $day_after_last > $start_day ) {
				$start_day = $day_after_last;
			}
		}

		return $start_day;
	}

	/**
	 * Find the post_date of the furthest-out already-scheduled future post.
	 *
	 * @param array        $post_types Post types to look at.
	 * @param DateTimeZone $timezone   Site timezone.
	 * @return DateTimeImmutable|null
	 */
	private static function get_last_scheduled_date( $post_types, $timezone ) {
		$posts = get_posts(
			array(
				'post_status' => 'future',
				'post_type'   => $post_types,
				'numberposts' => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'meta_key'    => PHDRIP_META_SCHEDULED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Querying the plugin's own scheduling meta is core to its function.
			)
		);

		if ( empty( $posts ) ) {
			return null;
		}

		$post = $posts[0];

		try {
			return new DateTimeImmutable( $post->post_date, $timezone );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Generate `$count` distinct DateTimeImmutable instants on `$day`,
	 * within [$time_start, $time_end], at least MIN_GAP_MINUTES apart
	 * (auto-shrunk if the window can't fit the requested count), each with
	 * a random second.
	 *
	 * @param DateTimeImmutable $day        Midnight of the target day (site timezone).
	 * @param string            $time_start HH:MM.
	 * @param string            $time_end   HH:MM.
	 * @param int               $count      Number of instants to generate.
	 * @return DateTimeImmutable[] Sorted list of instants.
	 */
	private static function generate_times_for_day( DateTimeImmutable $day, $time_start, $time_end, $count ) {
		$count = max( 1, (int) $count );

		$start_minutes = self::time_to_minutes( $time_start, 9 * 60 );
		$end_minutes   = self::time_to_minutes( $time_end, 21 * 60 );

		if ( $end_minutes <= $start_minutes ) {
			$end_minutes = $start_minutes + 1;
		}

		$window  = $end_minutes - $start_minutes;
		$min_gap = self::MIN_GAP_MINUTES;

		if ( $count > 1 ) {
			$max_possible_gap = (int) floor( $window / ( $count - 1 ) );
			if ( $max_possible_gap < $min_gap ) {
				$min_gap = max( 1, $max_possible_gap );
			}
		}

		$chosen       = array();
		$attempts     = 0;
		$max_attempts = 500;

		while ( count( $chosen ) < $count && $attempts < $max_attempts ) {
			++$attempts;
			$candidate = wp_rand( $start_minutes, $end_minutes );

			$ok = true;
			foreach ( $chosen as $existing_minute ) {
				if ( abs( $existing_minute - $candidate ) < $min_gap ) {
					$ok = false;
					break;
				}
			}

			if ( $ok ) {
				$chosen[] = $candidate;
			}
		}

		// Fallback: jittered evenly-spaced slots if random attempts couldn't
		// fill the slate (e.g. a tight window with a high count).
		if ( count( $chosen ) < $count ) {
			$chosen = self::fallback_times_for_day( $start_minutes, $end_minutes, $count );
		}

		sort( $chosen );

		$result = array();

		foreach ( $chosen as $minutes ) {
			$minutes = max( 0, min( 23 * 60 + 59, $minutes ) );
			$hour    = (int) floor( $minutes / 60 );
			$minute  = $minutes % 60;
			$second  = wp_rand( 0, 59 );

			$result[] = $day->setTime( $hour, $minute, $second );
		}

		return $result;
	}

	/**
	 * Fallback slot generator used when the rejection sampler in
	 * generate_times_for_day() can't fill the requested count within its
	 * attempt budget.
	 *
	 * Produces `$count` evenly spaced base slots across the window, each
	 * jittered by up to ±40% of the slot width (clamped inside the
	 * window), then enforces strictly increasing, distinct minute values
	 * by bumping any duplicate/out-of-order slot forward by at least one
	 * minute (never past `$end_minutes`). If the window genuinely cannot
	 * fit `$count` slots with at least a 1-minute gap between each, the
	 * count is reduced to what the window can hold.
	 *
	 * @param int $start_minutes Window start, minutes since midnight.
	 * @param int $end_minutes   Window end, minutes since midnight.
	 * @param int $count         Requested number of slots.
	 * @return int[] Strictly increasing, distinct minute values.
	 */
	private static function fallback_times_for_day( $start_minutes, $end_minutes, $count ) {
		$window = $end_minutes - $start_minutes;

		// Minutes are integers: the window holds at most (window + 1)
		// distinct values with >=1-minute gaps between them.
		$max_count_for_window = $window + 1;
		if ( $count > $max_count_for_window ) {
			$count = max( 1, $max_count_for_window );
		}

		$slot_width = $count > 0 ? $window / $count : $window;
		$minutes    = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$base_minute = $start_minutes + ( ( $i + 0.5 ) * $slot_width );
			$jitter_max  = $slot_width * 0.4;
			$jitter      = $jitter_max > 0 ? wp_rand( (int) round( -$jitter_max ), (int) round( $jitter_max ) ) : 0;

			$candidate = (int) round( $base_minute + $jitter );
			$candidate = max( $start_minutes, min( $end_minutes, $candidate ) );

			$minutes[] = $candidate;
		}

		sort( $minutes );

		// Enforce strict, distinct, increasing order by bumping any
		// duplicate/non-increasing slot forward by at least 1 minute.
		for ( $i = 1, $len = count( $minutes ); $i < $len; $i++ ) {
			if ( $minutes[ $i ] <= $minutes[ $i - 1 ] ) {
				$minutes[ $i ] = $minutes[ $i - 1 ] + 1;
			}
		}

		// If bumping pushed the tail past the window, clamp back down from
		// the end while preserving strictly increasing, distinct values,
		// then drop anything that got pushed below the window start.
		$len = count( $minutes );
		if ( $len > 0 && $minutes[ $len - 1 ] > $end_minutes ) {
			$minutes[ $len - 1 ] = $end_minutes;

			for ( $i = $len - 2; $i >= 0; $i-- ) {
				if ( $minutes[ $i ] >= $minutes[ $i + 1 ] ) {
					$minutes[ $i ] = $minutes[ $i + 1 ] - 1;
				}
			}

			$minutes = array_values(
				array_filter(
					$minutes,
					function ( $minute ) use ( $start_minutes ) {
						return $minute >= $start_minutes;
					}
				)
			);
		}

		return $minutes;
	}

	/**
	 * Convert an HH:MM string to minutes-since-midnight.
	 *
	 * @param string $time    HH:MM formatted time.
	 * @param int    $default Fallback value (minutes) if parsing fails.
	 * @return int
	 */
	private static function time_to_minutes( $time, $default ) {
		if ( ! is_string( $time ) || ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m ) ) {
			return $default;
		}

		return ( (int) $m[1] * 60 ) + (int) $m[2];
	}

	/**
	 * Persist the outcome of a scheduling run into `phdrip_state`.
	 *
	 * @param int    $count     Number of posts scheduled.
	 * @param string $last_date Local date/time string of the last scheduled post.
	 */
	private static function update_state_after_run( $count, $last_date ) {
		$state                         = get_option( PHDRIP_OPTION_STATE, array() );
		$state['last_schedule_run']    = time();
		$state['last_schedule_count']  = $count;
		$state['last_schedule_date']   = $last_date;
		update_option( PHDRIP_OPTION_STATE, $state );
	}
}
