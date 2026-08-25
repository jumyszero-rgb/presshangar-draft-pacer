<?php
/**
 * Settings API integration (option storage, sanitization, field rendering).
 *
 * @package PressHangar Draft Pacer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHDRIP_Settings
 */
class PHDRIP_Settings {

	/**
	 * Settings page slug (also used as the Settings API "page" argument).
	 */
	const PAGE_SLUG = 'presshangar-draft-pacer';

	/**
	 * Settings API option group.
	 */
	const OPTION_GROUP = 'phdrip_settings_group';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'update_option_' . PHDRIP_OPTION_SETTINGS, array( __CLASS__, 'maybe_reschedule_daily_event' ), 10, 2 );
	}

	/**
	 * Default settings values.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'enabled'           => false,
			'min_per_day'       => 3,
			'max_per_day'       => 5,
			'time_start'        => '09:00',
			'time_end'          => '21:00',
			'start_date'        => self::default_start_date(),
			'post_types'        => array( 'post' ),
			'categories'        => array(),
			'order'             => 'random',
			'recovery_enabled'  => true,
			'notify_email'      => get_option( 'admin_email' ),
		);
	}

	/**
	 * Tomorrow's date (site timezone) as Y-m-d, used as the default
	 * start_date.
	 *
	 * @return string
	 */
	private static function default_start_date() {
		$now = new DateTimeImmutable( 'now', wp_timezone() );

		return $now->modify( '+1 day' )->format( 'Y-m-d' );
	}

	/**
	 * Get current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( PHDRIP_OPTION_SETTINGS, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, self::get_defaults() );
	}

	/**
	 * Fired on `update_option_phdrip_settings`. When time_start changed, clears
	 * the existing `phdrip_daily_schedule` cron event and re-registers it for
	 * the new fire time (tomorrow at the new time_start), keeping the daily
	 * cron aligned with the configured window without waiting for the next
	 * deactivate/activate cycle.
	 *
	 * @param array $old_value Previous settings array.
	 * @param array $new_value New settings array.
	 */
	public static function maybe_reschedule_daily_event( $old_value, $new_value ) {
		$old_time = isset( $old_value['time_start'] ) ? $old_value['time_start'] : '';
		$new_time = isset( $new_value['time_start'] ) ? $new_value['time_start'] : '';

		if ( $old_time === $new_time ) {
			return;
		}

		wp_clear_scheduled_hook( PHDRIP_CRON_SCHEDULE );

		$tz    = wp_timezone();
		$parts = explode( ':', $new_time );

		$hour   = isset( $parts[0] ) ? (int) $parts[0] : 9;
		$minute = isset( $parts[1] ) ? (int) $parts[1] : 0;

		$now       = new DateTimeImmutable( 'now', $tz );
		$first_run = $now->modify( '+1 day' )->setTime( $hour, $minute, 0 );

		wp_schedule_event( $first_run->getTimestamp(), 'daily', PHDRIP_CRON_SCHEDULE );
	}

	/**
	 * Register the setting, section, and fields with the Settings API.
	 */
	public static function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			PHDRIP_OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::get_defaults(),
			)
		);

		add_settings_section(
			'phdrip_main_section',
			__( 'Drip Publishing Settings', 'presshangar-draft-pacer' ),
			'__return_false',
			self::PAGE_SLUG
		);

		$fields = array(
			'enabled'          => __( 'Enable automatic scheduling', 'presshangar-draft-pacer' ),
			'per_day'          => __( 'Posts per day', 'presshangar-draft-pacer' ),
			'time_window'      => __( 'Publishing time window', 'presshangar-draft-pacer' ),
			'start_date'       => __( 'Start date', 'presshangar-draft-pacer' ),
			'post_types'       => __( 'Post types', 'presshangar-draft-pacer' ),
			'categories'       => __( 'Categories', 'presshangar-draft-pacer' ),
			'order'            => __( 'Assignment order', 'presshangar-draft-pacer' ),
			'recovery_enabled' => __( 'Failure recovery', 'presshangar-draft-pacer' ),
			'notify_email'     => __( 'Notification email', 'presshangar-draft-pacer' ),
		);

		foreach ( $fields as $id => $label ) {
			add_settings_field(
				'phdrip_field_' . $id,
				$label,
				array( __CLASS__, 'render_field_' . $id ),
				self::PAGE_SLUG,
				'phdrip_main_section'
			);
		}
	}

	/**
	 * Sanitize/validate the whole settings array on save.
	 *
	 * @param array $input Raw POSTed settings.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::get_defaults();
		$existing = self::get_settings();
		$output   = array();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$output['enabled'] = ! empty( $input['enabled'] );

		$min = isset( $input['min_per_day'] ) ? (int) $input['min_per_day'] : $defaults['min_per_day'];
		$max = isset( $input['max_per_day'] ) ? (int) $input['max_per_day'] : $defaults['max_per_day'];
		$min = max( 1, min( 50, $min ) );
		$max = max( 1, min( 50, $max ) );

		if ( $min > $max ) {
			add_settings_error(
				PHDRIP_OPTION_SETTINGS,
				'phdrip_min_max',
				__( 'Minimum posts per day cannot exceed the maximum. The maximum was raised to match the minimum.', 'presshangar-draft-pacer' )
			);
			$max = $min;
		}

		$output['min_per_day'] = $min;
		$output['max_per_day'] = $max;

		$time_start = isset( $input['time_start'] ) ? sanitize_text_field( wp_unslash( $input['time_start'] ) ) : $defaults['time_start'];
		$time_end   = isset( $input['time_end'] ) ? sanitize_text_field( wp_unslash( $input['time_end'] ) ) : $defaults['time_end'];

		if ( ! self::is_valid_time( $time_start ) ) {
			$time_start = $defaults['time_start'];
		}
		if ( ! self::is_valid_time( $time_end ) ) {
			$time_end = $defaults['time_end'];
		}

		if ( self::time_to_minutes( $time_start ) >= self::time_to_minutes( $time_end ) ) {
			add_settings_error(
				PHDRIP_OPTION_SETTINGS,
				'phdrip_time_range',
				__( 'The end time must be later than the start time. The previous time window was kept.', 'presshangar-draft-pacer' )
			);
			$time_start = $existing['time_start'];
			$time_end   = $existing['time_end'];
		}

		$output['time_start'] = $time_start;
		$output['time_end']   = $time_end;

		$start_date = isset( $input['start_date'] ) ? sanitize_text_field( wp_unslash( $input['start_date'] ) ) : '';
		if ( ! self::is_valid_date( $start_date ) ) {
			$start_date = $defaults['start_date'];
		}
		$output['start_date'] = $start_date;

		$post_types = array();
		if ( ! empty( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $post_type ) {
				$post_type = sanitize_key( $post_type );
				if ( post_type_exists( $post_type ) ) {
					$post_types[] = $post_type;
				}
			}
		}
		if ( empty( $post_types ) ) {
			$post_types = $defaults['post_types'];
		}
		$output['post_types'] = array_values( array_unique( $post_types ) );

		$categories = array();
		if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
			foreach ( $input['categories'] as $category_id ) {
				$category_id = absint( $category_id );
				if ( $category_id > 0 ) {
					$categories[] = $category_id;
				}
			}
		}
		$output['categories'] = array_values( array_unique( $categories ) );

		$order = isset( $input['order'] ) ? sanitize_key( $input['order'] ) : $defaults['order'];
		$output['order'] = in_array( $order, array( 'random', 'oldest_first' ), true ) ? $order : $defaults['order'];

		$output['recovery_enabled'] = ! empty( $input['recovery_enabled'] );

		$notify_email = isset( $input['notify_email'] ) ? sanitize_text_field( wp_unslash( $input['notify_email'] ) ) : '';
		if ( '' !== $notify_email && ! is_email( $notify_email ) ) {
			add_settings_error(
				PHDRIP_OPTION_SETTINGS,
				'phdrip_notify_email',
				__( 'The notification email address is not valid. The previous value was kept.', 'presshangar-draft-pacer' )
			);
			$notify_email = $existing['notify_email'];
		}
		$output['notify_email'] = $notify_email;

		return $output;
	}

	/**
	 * Validate an HH:MM time string.
	 *
	 * @param string $time Time string.
	 * @return bool
	 */
	private static function is_valid_time( $time ) {
		return is_string( $time ) && (bool) preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time );
	}

	/**
	 * Validate a Y-m-d date string.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private static function is_valid_date( $date ) {
		if ( ! is_string( $date ) || '' === $date ) {
			return false;
		}

		$dt = DateTime::createFromFormat( 'Y-m-d', $date );

		return $dt instanceof DateTime && $dt->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Convert an HH:MM string to minutes-since-midnight.
	 *
	 * @param string $time Time string, assumed valid.
	 * @return int
	 */
	private static function time_to_minutes( $time ) {
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );

		return ( $hour * 60 ) + $minute;
	}

	/**
	 * Render the "enabled" checkbox field.
	 */
	public static function render_field_enabled() {
		$settings = self::get_settings();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( PHDRIP_OPTION_SETTINGS ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> />
			<?php esc_html_e( 'Automatically schedule drafts on a daily basis.', 'presshangar-draft-pacer' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the min/max posts-per-day fields.
	 */
	public static function render_field_per_day() {
		$settings = self::get_settings();
		?>
		<label>
			<?php esc_html_e( 'Min', 'presshangar-draft-pacer' ); ?>
			<input type="number" min="1" max="50" name="<?php echo esc_attr( PHDRIP_OPTION_SETTINGS ); ?>[min_per_day]" value="<?php echo esc_attr( $settings['min_per_day'] ); ?>" style="width:5em;" />
		</label>
		&nbsp;
		<label>
			<?php esc_html_e( 'Max', 'presshangar-draft-pacer' ); ?>
			<input type="number" min="1" max="50" name="<?php echo esc_attr( PHDRIP_OPTION_SETTINGS ); ?>[max_per_day]" value="<?php echo esc_attr( $settings['max_per_day'] ); ?>" style="width:5em;" />
		</label>
		<p class="description"><?php esc_html_e( 'A random number of posts within this range will be scheduled each day (1-50).', 'presshangar-draft-pacer' ); ?></p>
		<?php
	}

	/**
	 * Render the time window fields.
	 */
	public static function render_field_time_window() {
		$settings = self::get_settings();
		?>
		<label>
			<?php esc_html_e( 'From', 'presshangar-draft-pacer' ); ?>
			<input type="time" name="<?php echo esc_attr( PHDRIP_OPTION_SETTINGS ); ?>[time_start]" value="<?php echo esc_attr( $settings['time_start'] ); ?>" />
		</label>
		&nbsp;
		<label>
			<?php esc_html_e( 'To', 'presshangar-draft-pacer' ); ?>
			<input type="time" name="<?php echo esc_attr( PHDRIP_OPTION_SETTINGS ); ?>[time_end]" value="<?php echo esc_attr( $settings['time_end'] ); ?>" />
		</label>
		<p class="description"><?php esc_html_e( 'Posts will only be scheduled within this daily time window (site time). At least 15 minutes apart.', 'presshangar-draft-pacer' ); ?></p>
		<?php
	}

	/**
	 * Render the start_date field.
	 */
	public static function render_field_start_date() {
		$settings = self::get_settings();
		?>
		<input type="date" name="<?php echo esc_attr( PHDRIP_OPTION_SETTINGS ); ?>[start_date]" value="<?php echo esc_attr( $settings['start_date'] ); ?>" />
		<p class="description"><?php esc_html_e( 'The earliest date new posts may be assigned to. Existing scheduled posts are never overwritten.', 'presshangar-draft-pacer' ); ?></p>
		<?php
	}

	/**
	 * Render the post_types checkboxes.
	 */
	public static function render_field_post_types() {
		$settings       = self::get_settings();
		$selected_types = (array) $settings['post_types'];
		$post_types     = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<fieldset>
			<?php foreach ( $post_types as $post_type ) : ?>
				<?php if ( 'attachment' === $post_type->name ) { continue; } ?>
				<label style="margin-right:1em;">
					<input type="checkbox" name="<?php echo esc_attr( PHDRIP_OPTION_SETTINGS ); ?>[post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $selected_types, true ) ); ?> />
					<?php echo esc_html( $post_type->labels->singular_name ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Render the categories multi-select (applies to the "post" post type only).
	 */
	public static function render_field_categories() {
		$settings           = self::get_settings();
		$selected_categories = array_map( 'absint', (array) $settings['categories'] );
		$categories         = get_categories( array( 'hide_empty' => false ) );
		?>
		<select multiple="multiple" name="<?php echo esc_attr( PHDRIP_OPTION_SETTINGS ); ?>[categories][]" style="min-width:20em;height:8em;">
			<?php foreach ( $categories as $category ) : ?>
				<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( in_array( (int) $category->term_id, $selected_categories, true ) ); ?>>
					<?php echo esc_html( $category->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Applies to the "Post" post type only. Leave empty to include all categories.', 'presshangar-draft-pacer' ); ?></p>
		<?php
	}

	/**
	 * Render the assignment order select field.
	 */
	public static function render_field_order() {
		$settings = self::get_settings();
		?>
		<select name="<?php echo esc_attr( PHDRIP_OPTION_SETTINGS ); ?>[order]">
			<option value="random" <?php selected( $settings['order'], 'random' ); ?>><?php esc_html_e( 'Random', 'presshangar-draft-pacer' ); ?></option>
			<option value="oldest_first" <?php selected( $settings['order'], 'oldest_first' ); ?>><?php esc_html_e( 'Oldest first', 'presshangar-draft-pacer' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Render the recovery_enabled checkbox field.
	 */
	public static function render_field_recovery_enabled() {
		$settings = self::get_settings();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( PHDRIP_OPTION_SETTINGS ); ?>[recovery_enabled]" value="1" <?php checked( $settings['recovery_enabled'] ); ?> />
			<?php esc_html_e( 'Automatically republish posts that missed their scheduled publish time.', 'presshangar-draft-pacer' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Adopted posts (see "Scheduled (site-wide)" above) are covered by failure recovery once imported.', 'presshangar-draft-pacer' ); ?></p>
		<?php
	}

	/**
	 * Render the notify_email field.
	 */
	public static function render_field_notify_email() {
		$settings = self::get_settings();
		?>
		<input type="email" name="<?php echo esc_attr( PHDRIP_OPTION_SETTINGS ); ?>[notify_email]" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" style="min-width:20em;" />
		<p class="description"><?php esc_html_e( 'Email address to notify when posts are recovered. Leave empty to disable notifications.', 'presshangar-draft-pacer' ); ?></p>
		<?php
	}
}
