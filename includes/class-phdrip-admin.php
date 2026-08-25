<?php
/**
 * Admin screen: menu registration, status card, manual action buttons, notices.
 *
 * @package PressHangar Draft Pacer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHDRIP_Admin
 */
class PHDRIP_Admin {

	/**
	 * Nonce action for the "run now" button.
	 */
	const NONCE_RUN_NOW = 'phdrip_run_now';

	/**
	 * Nonce action for the "unschedule all" button.
	 */
	const NONCE_UNSCHEDULE_ALL = 'phdrip_unschedule_all';

	/**
	 * Nonce action for the "adopt existing scheduled posts" button.
	 */
	const NONCE_ADOPT = 'phdrip_adopt_existing';

	/**
	 * Maximum number of posts adopted per request. Adoption redirects back
	 * into its own admin-post handler to process the next batch when more
	 * remain, so very large backlogs are handled without a request timeout.
	 */
	const ADOPT_BATCH_SIZE = 100;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_phdrip_run_now', array( __CLASS__, 'handle_run_now' ) );
		add_action( 'admin_post_phdrip_unschedule_all', array( __CLASS__, 'handle_unschedule_all' ) );
		add_action( 'admin_post_phdrip_adopt_existing', array( __CLASS__, 'handle_adopt_existing' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	/**
	 * Register the "Settings > PressHangar Draft Pacer" submenu page.
	 */
	public static function add_menu() {
		add_options_page(
			__( 'PressHangar Draft Pacer', 'presshangar-draft-pacer' ),
			__( 'PressHangar Draft Pacer', 'presshangar-draft-pacer' ),
			'manage_options',
			PHDRIP_Settings::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Whether the current admin screen is this plugin's settings page.
	 *
	 * @return bool
	 */
	private static function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && false !== strpos( (string) $screen->id, PHDRIP_Settings::PAGE_SLUG );
	}

	/**
	 * Render admin notices.
	 *
	 * Run-now/unschedule feedback is only shown on this plugin's own screen
	 * (it's only ever reached via a redirect from there). The cron-unhealthy
	 * warning is shown on every admin screen, since it's actionable
	 * site-wide information; the full external-cron guide with copy-paste
	 * lines stays on the plugin's settings page only (see render_page()).
	 */
	public static function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::is_plugin_screen() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, used to select which notice to display.
			if ( isset( $_GET['phdrip_notice'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$notice = sanitize_key( wp_unslash( $_GET['phdrip_notice'] ) );

				if ( 'run_now' === $notice ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$count = isset( $_GET['phdrip_count'] ) ? absint( wp_unslash( $_GET['phdrip_count'] ) ) : 0;
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$failed = isset( $_GET['phdrip_failed'] ) ? absint( wp_unslash( $_GET['phdrip_failed'] ) ) : 0;

					$text = sprintf(
						/* translators: %d: number of posts scheduled. */
						_n( 'PressHangar Draft Pacer scheduled %d draft.', 'PressHangar Draft Pacer scheduled %d drafts.', $count, 'presshangar-draft-pacer' ),
						$count
					);

					if ( $failed > 0 ) {
						$text .= ' ' . sprintf(
							/* translators: %d: number of posts that failed to schedule. */
							_n( '%d failed.', '%d failed.', $failed, 'presshangar-draft-pacer' ),
							$failed
						);
					}

					printf(
						'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
						esc_html( $text )
					);
				} elseif ( 'unscheduled' === $notice ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$count = isset( $_GET['phdrip_count'] ) ? absint( wp_unslash( $_GET['phdrip_count'] ) ) : 0;
					printf(
						'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: %d: number of posts reverted to draft. */
								_n( 'PressHangar Draft Pacer reverted %d scheduled post back to draft.', 'PressHangar Draft Pacer reverted %d scheduled posts back to draft.', $count, 'presshangar-draft-pacer' ),
								$count
							)
						)
					);
				} elseif ( 'adopted' === $notice ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$count = isset( $_GET['phdrip_count'] ) ? absint( wp_unslash( $_GET['phdrip_count'] ) ) : 0;
					printf(
						'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: %d: number of existing scheduled posts adopted into PressHangar Draft Pacer management. */
								_n( 'PressHangar Draft Pacer adopted %d existing scheduled post into management.', 'PressHangar Draft Pacer adopted %d existing scheduled posts into management.', $count, 'presshangar-draft-pacer' ),
								$count
							)
						)
					);
				}
			}
		}

		if ( ! PHDRIP_Watchdog::is_cron_healthy() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'PressHangar Draft Pacer: WP-Cron does not appear to be running (no watchdog activity in over 2 hours). Scheduled posts may not publish on time. Consider setting up an external cron trigger under Settings > PressHangar Draft Pacer.', 'presshangar-draft-pacer' )
			);
		}
	}

	/**
	 * Handle the "run now" admin-post action.
	 */
	public static function handle_run_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'presshangar-draft-pacer' ) );
		}

		check_admin_referer( self::NONCE_RUN_NOW );

		$result = phdrip_schedule_drafts();

		$redirect = add_query_arg(
			array(
				'page'       => PHDRIP_Settings::PAGE_SLUG,
				'phdrip_notice' => 'run_now',
				'phdrip_count'  => (int) $result['scheduled'],
				'phdrip_failed' => (int) ( isset( $result['failed'] ) ? $result['failed'] : 0 ),
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the "unschedule all" admin-post action.
	 */
	public static function handle_unschedule_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'presshangar-draft-pacer' ) );
		}

		check_admin_referer( self::NONCE_UNSCHEDULE_ALL );

		$count = PHDRIP_Scheduler::unschedule_all();

		$redirect = add_query_arg(
			array(
				'page'       => PHDRIP_Settings::PAGE_SLUG,
				'phdrip_notice' => 'unscheduled',
				'phdrip_count'  => (int) $count,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the "adopt existing scheduled posts" admin-post action.
	 *
	 * Tags every `future` post of the configured post types that doesn't
	 * already carry the `_phdrip_scheduled` meta (e.g. posts scheduled directly
	 * via the REST API, another tool, or before PressHangar Draft Pacer was installed) so
	 * they're included in the status card counts and protected by the
	 * watchdog. Post dates and status are never touched — this only adds
	 * metadata.
	 *
	 * Processes at most ADOPT_BATCH_SIZE posts per request; if more remain,
	 * it redirects back into this same handler (carrying a running total in
	 * `phdrip_adopt_total`) to process the next batch, so sites with very large
	 * backlogs don't hit a request timeout.
	 */
	public static function handle_adopt_existing() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'presshangar-draft-pacer' ) );
		}

		check_admin_referer( self::NONCE_ADOPT );

		$settings   = PHDRIP_Settings::get_settings();
		$post_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified above via check_admin_referer(); this only carries a running total across the redirect-continue batches used for large backlogs.
		$total_so_far  = isset( $_REQUEST['phdrip_adopt_total'] ) ? absint( wp_unslash( $_REQUEST['phdrip_adopt_total'] ) ) : 0;
		$total_so_far += self::adopt_unmanaged_future_posts( $post_types, self::ADOPT_BATCH_SIZE );

		if ( self::count_unmanaged_future_posts( $post_types ) > 0 ) {
			$continue_url = add_query_arg(
				array(
					'action'          => 'phdrip_adopt_existing',
					'_wpnonce'        => wp_create_nonce( self::NONCE_ADOPT ),
					'phdrip_adopt_total' => $total_so_far,
				),
				admin_url( 'admin-post.php' )
			);

			wp_safe_redirect( $continue_url );
			exit;
		}

		$redirect = add_query_arg(
			array(
				'page'       => PHDRIP_Settings::PAGE_SLUG,
				'phdrip_notice' => 'adopted',
				'phdrip_count'  => (int) $total_so_far,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Count draft posts matching the given post types and (optional)
	 * categories, using an id-only query.
	 *
	 * Mirrors PHDRIP_Scheduler's category handling: `category__in` only
	 * applies meaningfully to the "post" post type, so when categories are
	 * set and other post types are also selected, two separate id-only
	 * queries are run and merged instead of applying the filter globally.
	 *
	 * @param array $post_types Post types to count drafts for.
	 * @param array $categories Category term IDs to filter "post" by (empty = no filter).
	 * @return int
	 */
	private static function count_draft_posts( $post_types, $categories ) {
		$base_args = array(
			'post_status' => 'draft',
			'numberposts' => -1,
			'fields'      => 'ids',
		);

		if ( empty( $categories ) ) {
			$args              = $base_args;
			$args['post_type'] = $post_types;

			return count( get_posts( $args ) );
		}

		$other_types = array_values( array_diff( $post_types, array( 'post' ) ) );
		$ids         = array();

		if ( in_array( 'post', $post_types, true ) ) {
			$post_args                 = $base_args;
			$post_args['post_type']    = 'post';
			$post_args['category__in'] = $categories;
			$ids                       = array_merge( $ids, get_posts( $post_args ) );
		}

		if ( ! empty( $other_types ) ) {
			$other_args              = $base_args;
			$other_args['post_type'] = $other_types;
			$ids                     = array_merge( $ids, get_posts( $other_args ) );
		}

		return count( array_unique( $ids ) );
	}

	/**
	 * Fetch the IDs of "future" posts scheduled by this plugin, matching the
	 * given post types and (optional) categories, using an id-only query.
	 *
	 * Mirrors count_draft_posts()'s category handling: `category__in` only
	 * applies meaningfully to the "post" post type, so when categories are
	 * set and other post types are also selected, two separate id-only
	 * queries are run and merged instead of applying the filter globally.
	 *
	 * @param array $post_types Post types to look at.
	 * @param array $categories Category term IDs to filter "post" by (empty = no filter).
	 * @return int[] Post IDs.
	 */
	private static function get_future_post_ids( $post_types, $categories ) {
		$base_args = array(
			'post_status' => 'future',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => PHDRIP_META_SCHEDULED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Querying the plugin's own scheduling meta is core to its function.
		);

		if ( empty( $categories ) ) {
			$args              = $base_args;
			$args['post_type'] = $post_types;

			return get_posts( $args );
		}

		$other_types = array_values( array_diff( $post_types, array( 'post' ) ) );
		$ids         = array();

		if ( in_array( 'post', $post_types, true ) ) {
			$post_args                 = $base_args;
			$post_args['post_type']    = 'post';
			$post_args['category__in'] = $categories;
			$ids                       = array_merge( $ids, get_posts( $post_args ) );
		}

		if ( ! empty( $other_types ) ) {
			$other_args              = $base_args;
			$other_args['post_type'] = $other_types;
			$ids                     = array_merge( $ids, get_posts( $other_args ) );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Count every `future` post of the given post types, site-wide — no
	 * `_phdrip_scheduled` meta filter and no category filter. This is the
	 * "whole-site truth" used to detect scheduled posts that exist outside
	 * PressHangar Draft Pacer's management (e.g. created directly via another tool),
	 * regardless of which category filter is currently configured.
	 *
	 * @param array $post_types Post types to count.
	 * @return int
	 */
	private static function count_all_future_posts( $post_types ) {
		return count(
			get_posts(
				array(
					'post_status' => 'future',
					'post_type'   => $post_types,
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * Count `future` posts of the given post types that lack the
	 * `_phdrip_scheduled` meta (i.e. not yet adopted into PressHangar Draft Pacer
	 * management). No category filter, matching count_all_future_posts().
	 *
	 * @param array $post_types Post types to count.
	 * @return int
	 */
	private static function count_unmanaged_future_posts( $post_types ) {
		return count(
			get_posts(
				array(
					'post_status' => 'future',
					'post_type'   => $post_types,
					'numberposts' => -1,
					'fields'      => 'ids',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- deliberate NOT EXISTS lookup to find posts PressHangar Draft Pacer hasn't tagged yet; there is no meta-less alternative.
						array(
							'key'     => PHDRIP_META_SCHEDULED,
							'compare' => 'NOT EXISTS',
						),
					),
				)
			)
		);
	}

	/**
	 * Adopt up to `$limit` unmanaged `future` posts (see
	 * count_unmanaged_future_posts()) into PressHangar Draft Pacer management by tagging
	 * them with `_phdrip_scheduled` and `_phdrip_orig_date`, exactly like a post
	 * scheduled by the normal drip flow. Post date and status are never
	 * modified — this only adds the metadata the status card and watchdog
	 * rely on.
	 *
	 * @param array $post_types Post types to adopt.
	 * @param int   $limit      Maximum number of posts to adopt in this call.
	 * @return int Number of posts adopted.
	 */
	private static function adopt_unmanaged_future_posts( $post_types, $limit ) {
		$post_ids = get_posts(
			array(
				'post_status' => 'future',
				'post_type'   => $post_types,
				'numberposts' => $limit,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- deliberate NOT EXISTS lookup to find posts PressHangar Draft Pacer hasn't tagged yet; there is no meta-less alternative.
					array(
						'key'     => PHDRIP_META_SCHEDULED,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			update_post_meta( $post_id, PHDRIP_META_SCHEDULED, 1 );

			if ( $post ) {
				update_post_meta( $post_id, PHDRIP_META_ORIG_DATE, $post->post_date );
			}
		}

		return count( $post_ids );
	}

	/**
	 * Gather the data displayed in the status card.
	 *
	 * @return array
	 */
	private static function get_status() {
		$settings   = PHDRIP_Settings::get_settings();
		$post_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );
		$categories = ! empty( $settings['categories'] ) ? array_map( 'absint', (array) $settings['categories'] ) : array();

		$draft_count = self::count_draft_posts( $post_types, $categories );

		$future_ids   = self::get_future_post_ids( $post_types, $categories );
		$future_count = count( $future_ids );

		$future_count_sitewide = self::count_all_future_posts( $post_types );
		$unmanaged_count       = max( 0, $future_count_sitewide - $future_count );

		$next_publish = '';

		if ( $future_count > 0 ) {
			// IDs are already scoped to the configured post types and
			// categories (see get_future_post_ids()); post_type => 'any'
			// here simply avoids get_posts()'s implicit "post"-only default
			// so posts of any type among $future_ids can still match.
			$next_posts = get_posts(
				array(
					'post_status'    => 'future',
					'post_type'      => 'any',
					'post__in'       => $future_ids,
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'ASC',
					'meta_key'       => PHDRIP_META_SCHEDULED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Querying the plugin's own scheduling meta is core to its function.
				)
			);

			if ( ! empty( $next_posts ) ) {
				$next_publish = get_the_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_posts[0] );
			}
		}

		$state = get_option( PHDRIP_OPTION_STATE, array() );

		return array(
			'enabled'               => ! empty( $settings['enabled'] ),
			'draft_count'           => $draft_count,
			'future_count'          => $future_count,
			'future_count_sitewide' => $future_count_sitewide,
			'unmanaged_count'       => $unmanaged_count,
			'next_publish'          => $next_publish,
			'cron_healthy'          => PHDRIP_Watchdog::is_cron_healthy(),
			'last_cron_run'         => PHDRIP_Watchdog::get_last_cron_run(),
			'recovered_count'       => isset( $state['recovered_count'] ) ? (int) $state['recovered_count'] : 0,
		);
	}

	/**
	 * Render the state-aware "Getting started" panel shown at the top of the
	 * settings page. Each step reflects whether it is already done, so the
	 * panel doubles as a live checklist for first-time setup.
	 *
	 * @param array $status Status data from get_status().
	 */
	private static function render_getting_started( $status ) {
		$enabled       = ! empty( $status['enabled'] );
		$has_scheduled = $status['future_count'] > 0;
		$cron_ok       = ! empty( $status['cron_healthy'] );

		$steps = array(
			array(
				'done'  => $enabled,
				'title' => __( 'Turn on automatic scheduling', 'presshangar-draft-pacer' ),
				'body'  => __( 'In the settings below, choose which post types and categories to pace and how fast to publish, tick "Enable automatic scheduling", then Save.', 'presshangar-draft-pacer' ),
			),
			array(
				'done'  => $has_scheduled,
				'title' => __( 'Schedule your current drafts', 'presshangar-draft-pacer' ),
				'body'  => __( 'Press "Run Assignment Now" above to space out the drafts you already have. After that, new drafts are paced automatically every day.', 'presshangar-draft-pacer' ),
			),
			array(
				'done'  => $cron_ok,
				'title' => __( 'Make sure posts publish on time', 'presshangar-draft-pacer' ),
				'body'  => __( 'Drip publishing relies on WordPress cron. If "Cron health" above shows a warning, follow the External Cron Setup Guide on this page — the built-in watchdog still rescues any missed post in the meantime.', 'presshangar-draft-pacer' ),
			),
		);
		?>
		<div class="card" style="max-width:640px;border-left:4px solid #2271b1;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Getting started', 'presshangar-draft-pacer' ); ?></h2>
			<p><?php esc_html_e( 'PressHangar Draft Pacer publishes your backlog of drafts automatically, spaced out at natural, human-like intervals — with a watchdog that makes sure nothing is missed. It never generates content. New here? These few steps get you going.', 'presshangar-draft-pacer' ); ?></p>
			<ol style="list-style:none;margin:0;padding:0;">
				<?php foreach ( $steps as $i => $step ) : ?>
					<li style="display:flex;align-items:flex-start;gap:.6em;margin:.8em 0;">
						<?php if ( $step['done'] ) : ?>
							<span aria-hidden="true" style="flex:0 0 auto;width:1.5em;height:1.5em;border-radius:50%;background:#008a20;color:#fff;text-align:center;line-height:1.5em;font-weight:600;">&#10003;</span>
						<?php else : ?>
							<span aria-hidden="true" style="flex:0 0 auto;width:1.5em;height:1.5em;border-radius:50%;background:#2271b1;color:#fff;text-align:center;line-height:1.5em;font-weight:600;"><?php echo esc_html( number_format_i18n( $i + 1 ) ); ?></span>
						<?php endif; ?>
						<span>
							<strong><?php echo esc_html( $step['title'] ); ?></strong><br />
							<?php echo esc_html( $step['body'] ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render the settings page: status card, action buttons, settings form,
	 * and (when relevant) the external cron guide.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = self::get_status();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PressHangar Draft Pacer', 'presshangar-draft-pacer' ); ?></h1>

			<?php self::render_getting_started( $status ); ?>

			<div class="card" style="max-width:640px;">
				<h2><?php esc_html_e( 'Status', 'presshangar-draft-pacer' ); ?></h2>
				<table class="widefat striped" style="max-width:600px;">
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Automatic scheduling', 'presshangar-draft-pacer' ); ?></td>
							<td>
								<?php if ( $status['enabled'] ) : ?>
									<strong style="color:#008a20;"><?php esc_html_e( 'Enabled', 'presshangar-draft-pacer' ); ?></strong>
								<?php else : ?>
									<strong style="color:#a00;"><?php esc_html_e( 'Disabled', 'presshangar-draft-pacer' ); ?></strong>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Remaining drafts', 'presshangar-draft-pacer' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $status['draft_count'] ) ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Scheduled (PressHangar Draft Pacer-managed)', 'presshangar-draft-pacer' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $status['future_count'] ) ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Scheduled (site-wide)', 'presshangar-draft-pacer' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $status['future_count_sitewide'] ) ); ?></td>
						</tr>
						<?php if ( $status['unmanaged_count'] > 0 ) : ?>
							<?php
							$unmanaged_notice = sprintf(
								/* translators: %d: number of scheduled posts outside PressHangar Draft Pacer's management. */
								_n(
									'There is %d scheduled post outside PressHangar Draft Pacer\'s management. Use the button below to adopt it so it\'s included in the counts and protected by failure recovery (the watchdog).',
									'There are %d scheduled posts outside PressHangar Draft Pacer\'s management. Use the button below to adopt them so they\'re included in the counts and protected by failure recovery (the watchdog).',
									$status['unmanaged_count'],
									'presshangar-draft-pacer'
								),
								$status['unmanaged_count']
							);
							?>
							<tr>
								<td colspan="2">
									<span style="color:#a00;"><?php echo esc_html( $unmanaged_notice ); ?></span>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<td><?php esc_html_e( 'Next publish', 'presshangar-draft-pacer' ); ?></td>
							<td><?php echo $status['next_publish'] ? esc_html( $status['next_publish'] ) : esc_html__( 'None scheduled', 'presshangar-draft-pacer' ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Cron health', 'presshangar-draft-pacer' ); ?></td>
							<td>
								<?php if ( $status['cron_healthy'] ) : ?>
									<strong style="color:#008a20;"><?php esc_html_e( 'OK', 'presshangar-draft-pacer' ); ?></strong>
								<?php else : ?>
									<strong style="color:#a00;"><?php esc_html_e( 'Warning', 'presshangar-draft-pacer' ); ?></strong>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Total recovered posts', 'presshangar-draft-pacer' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $status['recovered_count'] ) ); ?></td>
						</tr>
					</tbody>
				</table>

				<div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5em;">
						<input type="hidden" name="action" value="phdrip_run_now" />
						<?php wp_nonce_field( self::NONCE_RUN_NOW ); ?>
						<?php submit_button( __( 'Run Assignment Now', 'presshangar-draft-pacer' ), 'primary', 'submit', false ); ?>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to revert all PressHangar Draft Pacer scheduled posts back to draft? This cannot be undone.', 'presshangar-draft-pacer' ) ); ?>');">
						<input type="hidden" name="action" value="phdrip_unschedule_all" />
						<?php wp_nonce_field( self::NONCE_UNSCHEDULE_ALL ); ?>
						<?php submit_button( __( 'Unschedule All', 'presshangar-draft-pacer' ), 'secondary', 'submit', false ); ?>
					</form>

					<?php if ( $status['unmanaged_count'] > 0 ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Import existing scheduled posts into PressHangar Draft Pacer management? Post dates and status will not be changed.', 'presshangar-draft-pacer' ) ); ?>');">
							<input type="hidden" name="action" value="phdrip_adopt_existing" />
							<?php wp_nonce_field( self::NONCE_ADOPT ); ?>
							<?php submit_button( __( 'Adopt Existing Scheduled Posts', 'presshangar-draft-pacer' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( PHDRIP_Settings::OPTION_GROUP );
				do_settings_sections( PHDRIP_Settings::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'presshangar-draft-pacer' ) );
				?>
			</form>

			<?php if ( ! $status['cron_healthy'] || PHDRIP_Watchdog::is_wp_cron_disabled() ) : ?>
				<div class="card" style="max-width:640px;">
					<h2><?php esc_html_e( 'External Cron Setup Guide', 'presshangar-draft-pacer' ); ?></h2>
					<?php if ( PHDRIP_Watchdog::is_wp_cron_disabled() ) : ?>
						<p><strong><?php esc_html_e( 'DISABLE_WP_CRON is defined on this site.', 'presshangar-draft-pacer' ); ?></strong> <?php esc_html_e( 'WordPress will not trigger cron automatically; an external trigger is required.', 'presshangar-draft-pacer' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'WP-Cron relies on site visits to run scheduled tasks, which can be unreliable on low-traffic sites. Setting up a real server cron job is recommended.', 'presshangar-draft-pacer' ); ?></p>
					<?php endif; ?>
					<p><?php esc_html_e( 'Add one of the following lines to your server crontab (runs every 15 minutes):', 'presshangar-draft-pacer' ); ?></p>
					<?php $lines = PHDRIP_Watchdog::get_external_cron_lines(); ?>
					<p><label><strong>wget</strong></label><br />
					<input type="text" readonly="readonly" onclick="this.select();" style="width:100%;" value="<?php echo esc_attr( $lines['wget'] ); ?>" /></p>
					<p><label><strong>curl</strong></label><br />
					<input type="text" readonly="readonly" onclick="this.select();" style="width:100%;" value="<?php echo esc_attr( $lines['curl'] ); ?>" /></p>

					<h3><?php esc_html_e( 'Where to paste it', 'presshangar-draft-pacer' ); ?></h3>

					<p><strong><?php esc_html_e( 'Shared hosting', 'presshangar-draft-pacer' ); ?></strong></p>
					<p><?php esc_html_e( "Most hosting control panels (cPanel, Plesk, and most rental servers) have a 'Cron jobs' section. Paste the line above there. If the panel asks for command and schedule separately, set the schedule to every 15 minutes.", 'presshangar-draft-pacer' ); ?></p>

					<p><strong><?php esc_html_e( 'VPS / dedicated server', 'presshangar-draft-pacer' ); ?></strong></p>
					<p><?php esc_html_e( 'Run `crontab -e` and paste the line above on a new line.', 'presshangar-draft-pacer' ); ?></p>

					<p><strong><?php esc_html_e( 'Home server', 'presshangar-draft-pacer' ); ?></strong></p>
					<p><?php esc_html_e( 'If WordPress runs on your own machine, use crontab on Linux/macOS, or Task Scheduler on Windows to run the command every 15 minutes.', 'presshangar-draft-pacer' ); ?></p>

					<p><?php esc_html_e( "No cron settings available? Some hosts don't offer cron at all. You can register the URL above with a free external ping service such as cron-job.org — or simply skip this step. External cron is only an extra safety net: the plugin works with the normal WordPress cron, and the built-in watchdog rescues any missed posts.", 'presshangar-draft-pacer' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
