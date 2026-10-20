<?php
/**
 * Admin settings screen: timezone, weekly schedule, special dates, notice bar,
 * and wording.
 *
 * @package ITBoffins_Live_Opening_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI.
 */
class IBOH_Admin {

	/**
	 * Admin page hook suffix.
	 */
	const PAGE = 'itboffins-live-opening-hours';

	/**
	 * Register admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . IBOH_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'itboffins-live-opening-hours' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Register the top-level admin menu.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Live Opening Hours', 'itboffins-live-opening-hours' ),
			__( 'Live Opening Hours', 'itboffins-live-opening-hours' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_settings_page' ),
			'dashicons-clock',
			81
		);
	}

	/**
	 * Register settings with the Settings API.
	 */
	public function register_settings() {
		register_setting(
			'iboh_settings_group',
			IBOH_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'IBOH_Settings', 'sanitize' ),
				'default'           => IBOH_Settings::defaults(),
			)
		);
	}

	/**
	 * Enqueue admin assets on our screen only.
	 *
	 * frontend.js is loaded too so the customer preview can reuse the very same
	 * evaluator (window.IBOHEval) the front end runs; it stays inert here
	 * because window.IBOH_DATA is not defined in admin.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'iboh-admin', IBOH_URL . 'assets/admin.css', array(), IBOH_VERSION );
		wp_enqueue_script(
			'iboh-frontend',
			IBOH_URL . 'assets/frontend.js',
			array(),
			IBOH_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_enqueue_script(
			'iboh-admin',
			IBOH_URL . 'assets/admin.js',
			array( 'iboh-frontend' ),
			IBOH_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config         = IBOH_Config::data();
		$default_labels = IBOH_Settings::defaults()['labels'];
		$site_zone      = IBOH_Timezone::site_timezone();

		wp_localize_script(
			'iboh-admin',
			'IBOH',
			array(
				'tz'              => $config['tz'],
				'offset'          => $config['offset'],
				'offsets'         => $config['offsets'],
				'siteTz'          => IBOH_Timezone::iana( $site_zone ),
				'siteOffset'      => IBOH_Timezone::offset_minutes( $site_zone ),
				'siteOffsets'     => IBOH_Timezone::offset_table( $site_zone ),
				'siteTzLabel'     => IBOH_Timezone::label( $site_zone ),
				'timezoneSetting' => $config['options']['timezone'],
				'timeFormat'      => $config['timeFormat'],
				'meridiem'        => $config['meridiem'],
				'list'            => $config['list'],
				'formats'         => $config['formats'],
				'lang'            => $config['lang'],
				'dateNames'       => $config['dateNames'],
				'dayNames'        => IBOH_Config::day_names(),
				'closedText'      => __( 'Closed', 'itboffins-live-opening-hours' ),
				'defaultLabels'   => $default_labels,
				'optionName'      => IBOH_OPTION,
				'maxRanges'       => IBOH_Settings::MAX_RANGES,
				'maxHolidays'     => IBOH_Settings::MAX_HOLIDAYS,
				'i18n'            => array(
					'remove'              => __( 'Remove', 'itboffins-live-opening-hours' ),
					'hidden'              => __( 'Nothing to show today', 'itboffins-live-opening-hours' ),
					'bannerOff'           => __( 'The notice bar is turned off. Customers only see your hours where you add the block or shortcode.', 'itboffins-live-opening-hours' ),
					'missingDate'         => __( 'Pick a date, or remove this row.', 'itboffins-live-opening-hours' ),
					'pastDate'            => __( 'That date has already passed. Pick a later date, or remove this row.', 'itboffins-live-opening-hours' ),
					'duplicateDate'       => __( 'This date is already in the list. Edit that entry or remove this one.', 'itboffins-live-opening-hours' ),
					'missingTime'         => __( 'Enter both an opening and a closing time, or remove this time.', 'itboffins-live-opening-hours' ),
					'addHours'            => __( 'Add opening hours', 'itboffins-live-opening-hours' ),
					'addAnotherTime'      => __( '+ Add another time', 'itboffins-live-opening-hours' ),
					/* translators: %s: closing time. */
					'overnightHint'       => __( 'closes %s the next day', 'itboffins-live-opening-hours' ),
					'midnightHint'        => __( 'until midnight', 'itboffins-live-opening-hours' ),
					/* translators: %d: maximum number of time ranges. */
					'rangeLimit'          => sprintf( __( 'You can add up to %d times per day.', 'itboffins-live-opening-hours' ), IBOH_Settings::MAX_RANGES ),
					/* translators: %1$d: range number, %2$s: weekday or special date. */
					'rangeGroupLabel'     => __( 'Time %1$d for %2$s', 'itboffins-live-opening-hours' ),
					/* translators: %1$s: weekday or special date, %2$d: range number. */
					'rangeOpenLabel'      => __( 'Opening time for %1$s, time %2$d', 'itboffins-live-opening-hours' ),
					/* translators: %1$s: weekday or special date, %2$d: range number. */
					'rangeCloseLabel'     => __( 'Closing time for %1$s, time %2$d', 'itboffins-live-opening-hours' ),
					/* translators: %1$d: range number, %2$s: weekday or special date. */
					'rangeRemoveLabel'    => __( 'Remove time %1$d for %2$s', 'itboffins-live-opening-hours' ),
					/* translators: %s: weekday or special date. */
					'timeRangesForContext' => __( 'Opening times for %s', 'itboffins-live-opening-hours' ),
					/* translators: %s: special date in YYYY-MM-DD format. */
					'specialDateContext'  => __( 'the date %s', 'itboffins-live-opening-hours' ),
					'newSpecialDateContext' => __( 'a new date', 'itboffins-live-opening-hours' ),
					/* translators: %d: maximum number of special dates. */
					'holidayLimit'        => sprintf( __( 'You can add up to %d dates.', 'itboffins-live-opening-hours' ), IBOH_Settings::MAX_HOLIDAYS ),
					'limitReached'        => __( 'Limit reached.', 'itboffins-live-opening-hours' ),
					'holidayLimitReached' => __( 'The date limit has been reached. Remove a date before adding another.', 'itboffins-live-opening-hours' ),
					/* translators: %1$d: number of dates added, %2$d: number of existing dates replaced, %3$d: number of dates already in the list. */
					'bankHolidaysAdded'   => __( 'Added: %1$d. Replaced existing dates: %2$d. Already in your list: %3$d. Click Save changes when you are happy.', 'itboffins-live-opening-hours' ),
					'bankHolidaysNone'    => __( 'Those bank holidays are already in your list.', 'itboffins-live-opening-hours' ),
					/* translators: %s: description of the days that were filled, e.g. "Tuesday to Friday". */
					'copyDone'            => __( "Copied Monday's hours to %s. Click Save changes when you are happy.", 'itboffins-live-opening-hours' ),
					/* translators: %s: description of the days that will be overwritten, e.g. "Tuesday to Friday". */
					'copyConfirm'         => __( "Replace the hours already set for %s with Monday's hours?", 'itboffins-live-opening-hours' ),
					/* translators: %1$s: time, %2$s: weekday name, %3$s: timezone label. */
					'siteTime'            => __( 'Right now it is %1$s on %2$s in %3$s.', 'itboffins-live-opening-hours' ),
					/* translators: %s: timezone label such as "UTC-9". */
					'fixedOffsetNote'     => __( '%s is a fixed offset, so it never changes for daylight saving. If your area changes its clocks, choose the nearest city instead (for example America/Anchorage for Alaska, or Europe/London for the UK).', 'itboffins-live-opening-hours' ),
					'unsaved'             => __( 'Unsaved changes', 'itboffins-live-opening-hours' ),
					'bankHolidayLabels'   => array(
						'new_year'    => __( "New Year's Day", 'itboffins-live-opening-hours' ),
						'jan_second'  => __( '2 January', 'itboffins-live-opening-hours' ),
						'good_friday' => __( 'Good Friday', 'itboffins-live-opening-hours' ),
						'easter_mon'  => __( 'Easter Monday', 'itboffins-live-opening-hours' ),
						'early_may'   => __( 'Early May bank holiday', 'itboffins-live-opening-hours' ),
						'spring'      => __( 'Spring bank holiday', 'itboffins-live-opening-hours' ),
						'summer'      => __( 'Summer bank holiday', 'itboffins-live-opening-hours' ),
						'christmas'   => __( 'Christmas Day', 'itboffins-live-opening-hours' ),
						'boxing'      => __( 'Boxing Day', 'itboffins-live-opening-hours' ),
						'st_patrick'  => __( "St Patrick's Day", 'itboffins-live-opening-hours' ),
						'boyne'       => __( 'Battle of the Boyne', 'itboffins-live-opening-hours' ),
						'st_andrew'   => __( "St Andrew's Day", 'itboffins-live-opening-hours' ),
					),
				),
			)
		);
	}

	/**
	 * Render the settings screen.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Include yesterday so an override that still shapes the early hours
		// of today can be seen and corrected.
		$settings = IBOH_Settings::all( true );
		$schedule = $settings['schedule'];
		$banner   = $settings['banner'];
		$labels   = $settings['labels'];
		$options  = $settings['options'];
		$days     = IBOH_Config::day_names();
		$order    = array( 1, 2, 3, 4, 5, 6, 0 ); // Display Monday..Sunday.

		$timezone   = IBOH_Timezone::timezone();
		$site_zone  = IBOH_Timezone::site_timezone();
		$now        = IBOH_Timezone::now();
		$today      = $now->format( 'Y-m-d' );
		$year_now   = (int) $now->format( 'Y' );
		$raw_option = get_option( IBOH_OPTION, array() );
		$never_saved = ! is_array( $raw_option ) || ! isset( $raw_option['schedule'] );

		$holidays          = array();
		$yesterday_row     = null;
		$yesterday_date    = '';
		$public_date_count = 0;
		foreach ( $settings['holidays'] as $special_date => $holiday ) {
			if ( $special_date >= $today ) {
				$holidays[ $special_date ] = $holiday;
				$public_date_count++;
			} else {
				// Yesterday's date is always listed until midnight: any override
				// for yesterday can still shape the early hours of today (its own
				// late hours, or by cancelling the weekday's overnight hours), and
				// a save keeps only what the form sends.
				$yesterday_row  = $holiday;
				$yesterday_date = $special_date;
			}
		}
		$public_date_limit_reached = $public_date_count >= IBOH_Settings::MAX_HOLIDAYS;
		$fixed_offset              = IBOH_Timezone::is_fixed_offset( $timezone );
		$timezone_label            = IBOH_Timezone::label( $timezone );
		?>
		<div class="wrap iboh-wrap">
			<?php $this->brand_header(); ?>
			<h1><?php esc_html_e( 'Live Opening Hours', 'itboffins-live-opening-hours' ); ?></h1>
			<p class="iboh-intro"><?php esc_html_e( 'Tell customers when you are open, closed, or taking a day off - without making them hunt for it.', 'itboffins-live-opening-hours' ); ?></p>
			<?php settings_errors(); ?>

			<?php if ( $never_saved ) : ?>
				<div class="notice notice-info inline iboh-notice">
					<p><?php esc_html_e( 'Your notice bar is already live on the site using the default hours (Monday to Friday, 09:00 to 17:00). Set your real hours below and click Save changes.', 'itboffins-live-opening-hours' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php" id="iboh-form">
				<?php settings_fields( 'iboh_settings_group' ); ?>
				<?php // Must stay the FIRST plugin field: marks a submission from this editor. ?>
				<input type="hidden" name="<?php echo esc_attr( IBOH_OPTION . '[' . IBOH_Settings::FORM_MARKER . ']' ); ?>" value="1" />

				<div class="iboh-card iboh-preview-card">
					<div class="iboh-preview-head">
						<span class="iboh-eyebrow"><?php esc_html_e( 'Customer preview', 'itboffins-live-opening-hours' ); ?></span>
						<span class="iboh-unsaved" id="iboh-unsaved" hidden><?php esc_html_e( 'Unsaved changes', 'itboffins-live-opening-hours' ); ?></span>
					</div>
					<div class="iboh-preview" id="iboh-preview">
						<span class="iboh-dot" aria-hidden="true"></span>
						<span class="iboh-preview-main" data-iboh-main>—</span>
						<span class="iboh-preview-sub" data-iboh-sub></span>
						<span class="iboh-preview-upcoming" data-iboh-upcoming></span>
					</div>
					<span class="screen-reader-text" id="iboh-preview-announce" role="status" aria-live="polite" aria-atomic="true"></span>
					<p class="iboh-site-time">
						<span class="dashicons dashicons-clock" aria-hidden="true"></span>
						<span id="iboh-site-time">
							<?php
							printf(
								/* translators: %1$s: time, %2$s: weekday name, %3$s: timezone label. */
								esc_html__( 'Right now it is %1$s on %2$s in %3$s.', 'itboffins-live-opening-hours' ),
								esc_html( wp_date( get_option( 'time_format', 'H:i' ), $now->getTimestamp(), $timezone ) ),
								esc_html( isset( $days[ (int) $now->format( 'w' ) ] ) ? $days[ (int) $now->format( 'w' ) ] : $now->format( 'l' ) ),
								esc_html( $timezone_label )
							);
							?>
						</span>
						<a href="#iboh-timezone"><?php esc_html_e( 'Change timezone', 'itboffins-live-opening-hours' ); ?></a>
					</p>
					<p class="description"><?php esc_html_e( 'The preview updates as you edit. Nothing changes on your site until you click Save changes.', 'itboffins-live-opening-hours' ); ?></p>
					<p class="iboh-preview-save"><?php submit_button( __( 'Save changes', 'itboffins-live-opening-hours' ), 'primary', 'iboh-save-top', false ); ?></p>
				</div>

				<div class="iboh-card" id="iboh-timezone-card">
					<h2><?php esc_html_e( 'Timezone', 'itboffins-live-opening-hours' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Your opening hours are read in this timezone. Choose a city rather than a UTC offset so the clocks change automatically for daylight saving.', 'itboffins-live-opening-hours' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="iboh-timezone"><?php esc_html_e( 'Timezone for your hours', 'itboffins-live-opening-hours' ); ?></label></th>
							<td>
								<select id="iboh-timezone" data-iboh-option="timezone" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][timezone]">
									<option value="" <?php selected( '', $options['timezone'] ); ?>>
										<?php
										printf(
											/* translators: %s: the WordPress site timezone label. */
											esc_html__( 'Same as your WordPress site (%s)', 'itboffins-live-opening-hours' ),
											esc_html( IBOH_Timezone::label( $site_zone ) )
										);
										?>
									</option>
									<?php
									// An empty value makes core prepend its own selected "Select a
									// city" option, which would hide the "Same as your WordPress
									// site" choice above. Pass a value that matches nothing instead.
									$choice = wp_timezone_choice( '' === $options['timezone'] ? 'iboh-follow-site' : $options['timezone'], get_user_locale() );
									echo wp_kses(
										$choice,
										array(
											'optgroup' => array( 'label' => true ),
											'option'   => array(
												'value'    => true,
												'selected' => true,
												'dir'      => true,
											),
										)
									);
									?>
								</select>
								<p class="description">
									<?php
									printf(
										/* translators: %s: link to the WordPress General Settings screen. */
										esc_html__( 'Your WordPress site timezone is set under %s.', 'itboffins-live-opening-hours' ),
										'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings → General', 'itboffins-live-opening-hours' ) . '</a>'
									);
									?>
								</p>
								<div class="notice notice-warning inline iboh-tz-warning" id="iboh-timezone-warning" <?php echo $fixed_offset ? '' : 'hidden'; ?>>
									<p>
										<?php
										printf(
											/* translators: %s: timezone label such as "UTC-9". */
											esc_html__( '%s is a fixed offset, so it never changes for daylight saving. If your area changes its clocks, choose the nearest city instead (for example America/Anchorage for Alaska, or Europe/London for the UK).', 'itboffins-live-opening-hours' ),
											'<strong data-iboh-tz-label>' . esc_html( $timezone_label ) . '</strong>'
										);
										?>
									</p>
								</div>
							</td>
						</tr>
					</table>
				</div>

				<div class="iboh-card">
					<h2><?php esc_html_e( 'Your regular week', 'itboffins-live-opening-hours' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Choose Open or Closed for each day. An open day needs at least one time; add a second time for a lunch break or an evening session. A closing time earlier than the opening time means you close after midnight.', 'itboffins-live-opening-hours' ); ?></p>
					<p class="description iboh-public-warning"><strong><?php esc_html_e( 'Public information:', 'itboffins-live-opening-hours' ); ?></strong> <?php esc_html_e( 'These hours are published on your site. Do not include confidential information.', 'itboffins-live-opening-hours' ); ?></p>

					<p class="iboh-weekly-toggle">
						<label>
							<input type="checkbox" id="iboh-weekly-enabled" data-iboh-option="weekly_enabled" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][weekly_enabled]" value="1" <?php checked( $options['weekly_enabled'] ); ?> />
							<?php esc_html_e( 'Use a regular weekly schedule', 'itboffins-live-opening-hours' ); ?>
						</label>
						<span class="description"><?php esc_html_e( 'Turn this off if you only open on specific dates, such as pop-ups, events, or appointments.', 'itboffins-live-opening-hours' ); ?></span>
					</p>
					<p class="description iboh-weekly-off-note" id="iboh-weekly-off-note" <?php echo empty( $options['weekly_enabled'] ) ? '' : 'hidden'; ?>><?php esc_html_e( 'Weekly hours are off. Only the dates in the next section decide when you are open.', 'itboffins-live-opening-hours' ); ?></p>

					<div class="iboh-weekly-body" id="iboh-weekly-body" <?php echo empty( $options['weekly_enabled'] ) ? 'hidden' : ''; ?>>
						<div class="iboh-quick-fill">
							<span class="iboh-quick-fill-label"><?php esc_html_e( 'Same hours on several days?', 'itboffins-live-opening-hours' ); ?></span>
							<label class="screen-reader-text" for="iboh-copy-target"><?php esc_html_e( "Days to copy Monday's hours to", 'itboffins-live-opening-hours' ); ?></label>
							<select id="iboh-copy-target">
								<option value="weekdays"><?php esc_html_e( 'Tuesday to Friday', 'itboffins-live-opening-hours' ); ?></option>
								<option value="mon_sat"><?php esc_html_e( 'Tuesday to Saturday', 'itboffins-live-opening-hours' ); ?></option>
								<option value="all"><?php esc_html_e( 'Every day', 'itboffins-live-opening-hours' ); ?></option>
							</select>
							<button type="button" class="button" id="iboh-copy-monday"><?php esc_html_e( "Copy Monday's hours", 'itboffins-live-opening-hours' ); ?></button>
							<span class="description iboh-copy-status" id="iboh-copy-status" role="status" aria-live="polite"></span>
						</div>
						<div class="iboh-days">
							<?php foreach ( $order as $dow ) : ?>
								<?php $this->render_day_row( $dow, $schedule[ $dow ], isset( $days[ $dow ] ) ? $days[ $dow ] : '' ); ?>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="iboh-card">
					<h2 id="iboh-special-dates-heading"><?php esc_html_e( 'Holidays & one-off dates', 'itboffins-live-opening-hours' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Dates that do not follow your normal week: bank holidays, Christmas, an early finish, a special Saturday opening. Each date is either closed all day or open with its own hours. Past dates are removed automatically.', 'itboffins-live-opening-hours' ); ?></p>
					<p class="description iboh-public-warning"><strong><?php esc_html_e( 'Public information:', 'itboffins-live-opening-hours' ); ?></strong> <?php esc_html_e( 'Dates, hours, and notes here are shown to visitors. Do not include confidential information.', 'itboffins-live-opening-hours' ); ?></p>

					<div class="iboh-holiday-actions">
						<button type="button" class="button" id="iboh-add-holiday" aria-describedby="iboh-holiday-limit" <?php disabled( $public_date_limit_reached ); ?>><?php esc_html_e( '+ Add a date', 'itboffins-live-opening-hours' ); ?></button>
						<span class="iboh-or"><?php esc_html_e( 'or', 'itboffins-live-opening-hours' ); ?></span>
						<span class="iboh-bank-holiday-actions">
							<label>
								<span class="screen-reader-text"><?php esc_html_e( 'Bank holiday area', 'itboffins-live-opening-hours' ); ?></span>
								<select id="iboh-bank-holiday-region">
									<option value="england-wales"><?php esc_html_e( 'England & Wales', 'itboffins-live-opening-hours' ); ?></option>
									<option value="scotland"><?php esc_html_e( 'Scotland', 'itboffins-live-opening-hours' ); ?></option>
									<option value="northern-ireland"><?php esc_html_e( 'Northern Ireland', 'itboffins-live-opening-hours' ); ?></option>
								</select>
							</label>
							<label>
								<span class="screen-reader-text"><?php esc_html_e( 'Bank holiday year', 'itboffins-live-opening-hours' ); ?></span>
								<select id="iboh-bank-holiday-year">
									<?php for ( $year = $year_now; $year <= $year_now + 2; $year++ ) : ?>
										<option value="<?php echo esc_attr( $year ); ?>"><?php echo esc_html( $year ); ?></option>
									<?php endfor; ?>
								</select>
							</label>
							<button type="button" class="button" id="iboh-add-bank-holidays" aria-describedby="iboh-holiday-limit" <?php disabled( $public_date_limit_reached ); ?>><?php esc_html_e( 'Add UK bank holidays as closed', 'itboffins-live-opening-hours' ); ?></button>
						</span>
						<span class="description iboh-limit-note" id="iboh-holiday-limit" aria-live="polite" <?php echo $public_date_limit_reached ? '' : 'hidden'; ?>>
							<?php
							printf(
								/* translators: %d: maximum number of special dates. */
								esc_html__( 'You can add up to %d dates.', 'itboffins-live-opening-hours' ),
								(int) IBOH_Settings::MAX_HOLIDAYS
							);
							?>
						</span>
					</div>
					<p class="description iboh-bank-holiday-status" id="iboh-bank-holiday-status" role="status" aria-live="polite"></p>

					<div class="iboh-holidays" id="iboh-holidays" role="group" aria-labelledby="iboh-special-dates-heading">
						<?php
						$i = 0;
						if ( null !== $yesterday_row ) {
							$this->render_holiday_row( $i, $yesterday_date, $yesterday_row, true );
							$i++;
						}
						foreach ( $holidays as $date => $holiday ) {
							$this->render_holiday_row( $i, $date, $holiday, false );
							$i++;
						}
						?>
					</div>
					<p class="iboh-empty" id="iboh-holidays-empty" <?php echo $i > 0 ? 'hidden' : ''; ?>><?php esc_html_e( 'No dates yet. Add one above, or add the UK bank holidays in one go.', 'itboffins-live-opening-hours' ); ?></p>

					<fieldset class="iboh-upcoming-mode">
						<legend><?php esc_html_e( 'Tell customers in advance?', 'itboffins-live-opening-hours' ); ?></legend>
						<label>
							<input type="radio" data-iboh-option="show_upcoming" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][show_upcoming]" value="0" <?php checked( empty( $options['show_upcoming'] ) ); ?> />
							<?php esc_html_e( 'Only mention a change on the day itself', 'itboffins-live-opening-hours' ); ?>
						</label>
						<label>
							<input type="radio" data-iboh-option="show_upcoming" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][show_upcoming]" value="1" <?php checked( ! empty( $options['show_upcoming'] ) ); ?> />
							<?php esc_html_e( 'Show upcoming dates ahead of time in the notice bar and the hours table', 'itboffins-live-opening-hours' ); ?>
						</label>
					</fieldset>

					<fieldset class="iboh-upcoming-mode iboh-upcoming-mode-sub">
						<legend><?php esc_html_e( 'Bank holidays in those advance notices', 'itboffins-live-opening-hours' ); ?></legend>
						<?php $bank_mode = isset( $options['bank_holiday_mode'] ) ? $options['bank_holiday_mode'] : 'seven_days'; ?>
						<label>
							<input type="radio" data-iboh-option="bank_holiday_mode" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][bank_holiday_mode]" value="seven_days" <?php checked( $bank_mode, 'seven_days' ); ?> />
							<?php esc_html_e( 'Only when they are within the next 7 days', 'itboffins-live-opening-hours' ); ?>
						</label>
						<label>
							<input type="radio" data-iboh-option="bank_holiday_mode" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][bank_holiday_mode]" value="all" <?php checked( $bank_mode, 'all' ); ?> />
							<?php esc_html_e( 'Show all future bank holidays', 'itboffins-live-opening-hours' ); ?>
						</label>
						<label>
							<input type="radio" data-iboh-option="bank_holiday_mode" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][bank_holiday_mode]" value="none" <?php checked( $bank_mode, 'none' ); ?> />
							<?php esc_html_e( 'Never show bank holidays in advance (they still apply on the day)', 'itboffins-live-opening-hours' ); ?>
						</label>
					</fieldset>
				</div>

				<div class="iboh-card">
					<h2><?php esc_html_e( 'Customer notice bar', 'itboffins-live-opening-hours' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The live "open" or "closed" message shown on every page of your site.', 'itboffins-live-opening-hours' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Show notice bar', 'itboffins-live-opening-hours' ); ?></th>
							<td>
								<label>
									<input type="checkbox" data-iboh-banner-field="enabled" name="<?php echo esc_attr( IBOH_OPTION ); ?>[banner][enabled]" value="1" <?php checked( $banner['enabled'] ); ?> />
									<?php esc_html_e( 'Show customers whether you are open or closed', 'itboffins-live-opening-hours' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="iboh-position"><?php esc_html_e( 'Where it sits', 'itboffins-live-opening-hours' ); ?></label></th>
							<td>
								<select id="iboh-position" data-iboh-banner-field="position" name="<?php echo esc_attr( IBOH_OPTION ); ?>[banner][position]">
									<option value="top" <?php selected( $banner['position'], 'top' ); ?>><?php esc_html_e( 'Top of the page', 'itboffins-live-opening-hours' ); ?></option>
									<option value="bottom" <?php selected( $banner['position'], 'bottom' ); ?>><?php esc_html_e( 'Bottom of the page', 'itboffins-live-opening-hours' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'While customers scroll', 'itboffins-live-opening-hours' ); ?></th>
							<td>
								<label>
									<input type="checkbox" data-iboh-banner-field="sticky" name="<?php echo esc_attr( IBOH_OPTION ); ?>[banner][sticky]" value="1" <?php checked( $banner['sticky'] ); ?> />
									<?php esc_html_e( 'Keep the notice visible as customers scroll', 'itboffins-live-opening-hours' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Turn this off if you only want the notice to appear where it is placed on the page.', 'itboffins-live-opening-hours' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Top of page spacing', 'itboffins-live-opening-hours' ); ?></th>
							<td>
								<label>
									<input type="checkbox" data-iboh-banner-field="push_top" name="<?php echo esc_attr( IBOH_OPTION ); ?>[banner][push_top]" value="1" <?php checked( $banner['push_top'] ); ?> />
									<?php esc_html_e( 'Make room for the notice when it is at the top', 'itboffins-live-opening-hours' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Turn this off if your theme already has space for it, or you want the notice to sit over the page.', 'itboffins-live-opening-hours' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Close button', 'itboffins-live-opening-hours' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( IBOH_OPTION ); ?>[banner][dismissible]" value="1" <?php checked( $banner['dismissible'] ); ?> />
									<?php esc_html_e( 'Let customers close the notice', 'itboffins-live-opening-hours' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Next opening time', 'itboffins-live-opening-hours' ); ?></th>
							<td>
								<label>
									<input type="checkbox" data-iboh-banner-field="show_next" name="<?php echo esc_attr( IBOH_OPTION ); ?>[banner][show_next]" value="1" <?php checked( $banner['show_next'] ); ?> />
									<?php esc_html_e( 'Show the next opening or closing time beside the main message', 'itboffins-live-opening-hours' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="iboh-soon"><?php esc_html_e( 'Soon message', 'itboffins-live-opening-hours' ); ?></label></th>
							<td>
								<input type="number" min="0" max="720" id="iboh-soon" class="small-text" data-iboh-banner-field="soon_mins" name="<?php echo esc_attr( IBOH_OPTION ); ?>[banner][soon_mins]" value="<?php echo esc_attr( $banner['soon_mins'] ); ?>" />
								<?php esc_html_e( 'minutes', 'itboffins-live-opening-hours' ); ?>
								<p class="description"><?php esc_html_e( 'When you are this close to opening or closing, show the friendly "Opening soon" or "Closing soon" message. Set to 0 to turn it off.', 'itboffins-live-opening-hours' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Colours', 'itboffins-live-opening-hours' ); ?></th>
							<td class="iboh-colours">
								<label><?php esc_html_e( 'Open', 'itboffins-live-opening-hours' ); ?><br><input type="color" data-iboh-banner-field="colour_open" name="<?php echo esc_attr( IBOH_OPTION ); ?>[banner][colour_open]" value="<?php echo esc_attr( $banner['colour_open'] ); ?>" /></label>
								<label><?php esc_html_e( 'Closed', 'itboffins-live-opening-hours' ); ?><br><input type="color" data-iboh-banner-field="colour_closed" name="<?php echo esc_attr( IBOH_OPTION ); ?>[banner][colour_closed]" value="<?php echo esc_attr( $banner['colour_closed'] ); ?>" /></label>
								<label><?php esc_html_e( 'Text', 'itboffins-live-opening-hours' ); ?><br><input type="color" data-iboh-banner-field="colour_text" name="<?php echo esc_attr( IBOH_OPTION ); ?>[banner][colour_text]" value="<?php echo esc_attr( $banner['colour_text'] ); ?>" /></label>
							</td>
						</tr>
					</table>
				</div>

				<div class="iboh-card">
					<h2><?php esc_html_e( 'Your wording', 'itboffins-live-opening-hours' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Make the public messages sound like your business. Keep any placeholders shown in the field help. Leave a field blank to use the standard wording.', 'itboffins-live-opening-hours' ); ?></p>
					<p class="description iboh-public-warning"><strong><?php esc_html_e( 'Public information:', 'itboffins-live-opening-hours' ); ?></strong> <?php esc_html_e( 'This wording is published on your site. Do not include confidential information.', 'itboffins-live-opening-hours' ); ?></p>
					<?php
					$label_groups = array(
						__( 'Open and closed messages', 'itboffins-live-opening-hours' ) => array(
							'open'         => __( 'Main message when you are open', 'itboffins-live-opening-hours' ),
							'closed'       => __( 'Main message when you are closed', 'itboffins-live-opening-hours' ),
							/* translators: %s: closing time placeholder shown to administrators. */
							'closes_at'    => __( 'Closing time line (%s = time)', 'itboffins-live-opening-hours' ),
							/* translators: %s: opening time placeholder shown to administrators. */
							'opens_today'  => __( 'Opening later today line (%s = time)', 'itboffins-live-opening-hours' ),
							/* translators: %1$s: weekday placeholder, %2$s: opening time placeholder shown to administrators. */
							'opens_on'     => __( 'Opening another day line (%1$s = day, %2$s = time)', 'itboffins-live-opening-hours' ),
							'opening_soon' => __( 'Message just before opening', 'itboffins-live-opening-hours' ),
							'closing_soon' => __( 'Message just before closing', 'itboffins-live-opening-hours' ),
							'open_24h'     => __( 'Message for a 24-hour day', 'itboffins-live-opening-hours' ),
						),
						__( 'Upcoming date messages', 'itboffins-live-opening-hours' )   => array(
							'upcoming_heading'     => __( 'Heading above upcoming changes', 'itboffins-live-opening-hours' ),
							/* translators: %s: date placeholder shown to administrators. */
							'upcoming_closed'      => __( 'One closed date (%s = date)', 'itboffins-live-opening-hours' ),
							/* translators: %s: date list placeholder shown to administrators. */
							'upcoming_closed_list' => __( 'Several closed dates (%s = date list)', 'itboffins-live-opening-hours' ),
							/* translators: %1$s: date placeholder, %2$s: opening-hours placeholder shown to administrators. */
							'upcoming_hours'       => __( 'One date with different hours (%1$s = date, %2$s = hours)', 'itboffins-live-opening-hours' ),
						),
					);
					foreach ( $label_groups as $group_title => $group ) :
						?>
						<h3 class="iboh-subheading"><?php echo esc_html( $group_title ); ?></h3>
						<table class="form-table" role="presentation">
							<?php foreach ( $group as $key => $help ) : ?>
								<?php if ( ! array_key_exists( $key, $labels ) ) { continue; } ?>
								<tr>
									<th scope="row"><label for="iboh-label-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $help ); ?></label></th>
									<td>
										<input type="text" class="regular-text" id="iboh-label-<?php echo esc_attr( $key ); ?>" data-iboh-label="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( IBOH_OPTION ); ?>[labels][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $labels[ $key ] ); ?>" />
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					<?php endforeach; ?>
				</div>

				<?php submit_button( __( 'Save changes', 'itboffins-live-opening-hours' ) ); ?>
				<?php // Must stay the LAST field: its absence tells sanitize() the POST was cut short. ?>
				<input type="hidden" name="<?php echo esc_attr( IBOH_OPTION . '[' . IBOH_Settings::FORM_COMPLETE_MARKER . ']' ); ?>" value="1" />
			</form>

			<div class="iboh-card iboh-promo">
				<h2><?php esc_html_e( 'Need hours on a page?', 'itboffins-live-opening-hours' ); ?></h2>
				<p><?php esc_html_e( 'Use the block in the editor, or paste one of these shortcodes:', 'itboffins-live-opening-hours' ); ?></p>
				<p><code>[iboh_opening_hours show="table"]</code> · <code>[iboh_opening_hours show="status"]</code> · <code>[iboh_opening_hours show="both"]</code></p>
			</div>

			<?php $this->render_templates(); ?>
		</div>
		<?php
	}

	/**
	 * Short localized date such as "Thu 25 Dec" for the editor.
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	private static function short_date( $ymd ) {
		$utc = new DateTimeZone( 'UTC' );
		$dt  = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, $utc );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $ymd ) {
			return '';
		}
		return wp_date( 'D j M', $dt->getTimestamp(), $utc );
	}

	/**
	 * Render the Open / Closed choice for a weekday or special date.
	 *
	 * @param string $name         Field name.
	 * @param bool   $is_closed    Current state.
	 * @param string $legend       Screen-reader legend.
	 * @param string $open_label   Visible "open" label.
	 * @param string $closed_label Visible "closed" label.
	 * @param bool   $closed_first Show the closed choice first.
	 * @param bool   $template     Emit data-field instead of name (for JS cloning).
	 */
	private function render_open_closed( $name, $is_closed, $legend, $open_label, $closed_label, $closed_first = false, $template = false ) {
		$attr = $template ? 'data-field="closed"' : 'name="' . esc_attr( $name ) . '"';
		$open = '<label class="iboh-pill iboh-pill-open"><input type="radio" class="iboh-closed-toggle" ' . $attr . ' value="0" ' . checked( ! $is_closed, true, false ) . ' /><span>' . esc_html( $open_label ) . '</span></label>';
		$shut = '<label class="iboh-pill iboh-pill-closed"><input type="radio" class="iboh-closed-toggle" ' . $attr . ' value="1" ' . checked( $is_closed, true, false ) . ' /><span>' . esc_html( $closed_label ) . '</span></label>';
		echo '<fieldset class="iboh-open-closed"><legend class="screen-reader-text">' . esc_html( $legend ) . '</legend>';
		echo $closed_first ? $shut . $open : $open . $shut; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped parts.
		echo '</fieldset>';
	}

	/**
	 * Render one weekday editor row.
	 *
	 * @param int    $dow  Weekday index 0..6.
	 * @param array  $day  Day plan { closed, ranges }.
	 * @param string $name Localised day name.
	 */
	private function render_day_row( $dow, $day, $name ) {
		$ranges_name = IBOH_OPTION . '[schedule][' . $dow . '][ranges]';
		$is_closed   = ! empty( $day['closed'] );
		$day_id      = 'iboh-day-' . $dow;
		$ranges      = isset( $day['ranges'] ) && is_array( $day['ranges'] ) ? $day['ranges'] : array();
		$range_limit = count( $ranges ) >= IBOH_Settings::MAX_RANGES;
		/* translators: %s: weekday or special date. */
		$ranges_label = sprintf( __( 'Opening times for %s', 'itboffins-live-opening-hours' ), $name );
		/* translators: %s: weekday name. */
		$legend = sprintf( __( '%s: open or closed?', 'itboffins-live-opening-hours' ), $name );
		?>
		<div class="iboh-day-row <?php echo $is_closed ? 'iboh-is-closed' : ''; ?>" data-iboh-day data-dow="<?php echo esc_attr( $dow ); ?>" role="group" aria-labelledby="<?php echo esc_attr( $day_id . '-name' ); ?>">
			<div class="iboh-day-name" id="<?php echo esc_attr( $day_id . '-name' ); ?>"><?php echo esc_html( $name ); ?></div>
			<?php
			$this->render_open_closed(
				IBOH_OPTION . '[schedule][' . $dow . '][closed]',
				$is_closed,
				$legend,
				__( 'Open', 'itboffins-live-opening-hours' ),
				__( 'Closed', 'itboffins-live-opening-hours' )
			);
			?>
			<div class="iboh-day-hours">
				<div class="iboh-day-body" <?php echo $is_closed ? 'hidden' : ''; ?>>
					<div class="iboh-ranges" data-name="<?php echo esc_attr( $ranges_name ); ?>" role="group" aria-label="<?php echo esc_attr( $ranges_label ); ?>">
						<?php
						$i = 0;
						foreach ( $ranges as $range ) {
							$this->render_range_row( $ranges_name . '[' . $i . ']', $range, $name, $i + 1 );
							$i++;
						}
						?>
					</div>
					<button type="button" class="button-link iboh-add-range" aria-describedby="<?php echo esc_attr( $day_id . '-range-limit' ); ?>" <?php disabled( $range_limit ); ?>><?php echo esc_html( $i > 0 ? __( '+ Add another time', 'itboffins-live-opening-hours' ) : __( 'Add opening hours', 'itboffins-live-opening-hours' ) ); ?></button>
					<span class="description iboh-limit-note" id="<?php echo esc_attr( $day_id . '-range-limit' ); ?>" aria-live="polite" <?php echo $range_limit ? '' : 'hidden'; ?>>
						<?php
						printf(
							/* translators: %d: maximum number of time ranges. */
							esc_html__( 'You can add up to %d times per day.', 'itboffins-live-opening-hours' ),
							(int) IBOH_Settings::MAX_RANGES
						);
						?>
					</span>
				</div>
				<p class="iboh-day-closed-note" <?php echo $is_closed ? '' : 'hidden'; ?>><?php esc_html_e( 'Closed all day', 'itboffins-live-opening-hours' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single open/close range row.
	 *
	 * @param string $name_prefix  Full name prefix, e.g. "...[ranges][0]".
	 * @param array  $range        { open, close }.
	 * @param string $context      Human-readable day/date context.
	 * @param int    $range_number One-based range number.
	 */
	private function render_range_row( $name_prefix, $range, $context, $range_number ) {
		$open          = isset( $range['open'] ) ? $range['open'] : '';
		$close         = isset( $range['close'] ) ? $range['close'] : '';
		$is_24h        = ( '00:00' === $open && '24:00' === $close );
		$display_open  = $is_24h ? '00:00' : $open;
		// A 24:00 close with any other opening time means "until midnight". The
		// time picker cannot show 24:00, and 00:00 evaluates identically.
		$display_close = $is_24h ? '23:59' : ( '24:00' === $close ? '00:00' : $close );
		/* translators: %1$d: range number, %2$s: weekday or special date. */
		$group_label  = sprintf( __( 'Time %1$d for %2$s', 'itboffins-live-opening-hours' ), $range_number, $context );
		/* translators: %1$s: weekday or special date, %2$d: range number. */
		$open_label   = sprintf( __( 'Opening time for %1$s, time %2$d', 'itboffins-live-opening-hours' ), $context, $range_number );
		/* translators: %1$s: weekday or special date, %2$d: range number. */
		$close_label  = sprintf( __( 'Closing time for %1$s, time %2$d', 'itboffins-live-opening-hours' ), $context, $range_number );
		/* translators: %1$d: range number, %2$s: weekday or special date. */
		$remove_label = sprintf( __( 'Remove time %1$d for %2$s', 'itboffins-live-opening-hours' ), $range_number, $context );
		?>
		<div class="iboh-range" role="group" aria-label="<?php echo esc_attr( $group_label ); ?>">
			<input type="hidden" data-iboh-24h-open name="<?php echo esc_attr( $name_prefix ); ?>[open]" value="00:00" <?php disabled( ! $is_24h ); ?> />
			<input type="hidden" data-iboh-24h-close name="<?php echo esc_attr( $name_prefix ); ?>[close]" value="24:00" <?php disabled( ! $is_24h ); ?> />
			<input type="time" data-iboh-time-open name="<?php echo esc_attr( $name_prefix ); ?>[open]" value="<?php echo esc_attr( $display_open ); ?>" aria-label="<?php echo esc_attr( $open_label ); ?>" <?php disabled( $is_24h ); ?> />
			<span class="iboh-range-sep" aria-hidden="true"><?php echo esc_html_x( 'to', 'between an opening and a closing time', 'itboffins-live-opening-hours' ); ?></span>
			<input type="time" data-iboh-time-close name="<?php echo esc_attr( $name_prefix ); ?>[close]" value="<?php echo esc_attr( $display_close ); ?>" aria-label="<?php echo esc_attr( $close_label ); ?>" <?php disabled( $is_24h ); ?> />
			<label class="iboh-range-24h"><input type="checkbox" class="iboh-24h-toggle" <?php checked( $is_24h ); ?> /> <?php esc_html_e( 'Open 24 hours', 'itboffins-live-opening-hours' ); ?></label>
			<span class="iboh-range-hint" data-iboh-range-hint></span>
			<button type="button" class="button-link iboh-remove-range" aria-label="<?php echo esc_attr( $remove_label ); ?>"><?php esc_html_e( 'Remove', 'itboffins-live-opening-hours' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Render one holiday / special-date row.
	 *
	 * @param int    $i            Row index.
	 * @param string $date         Date (YYYY-MM-DD).
	 * @param array  $holiday      { closed, ranges, label }.
	 * @param bool   $is_yesterday Whether this is yesterday's still-running override.
	 */
	private function render_holiday_row( $i, $date, $holiday, $is_yesterday = false ) {
		$base        = IBOH_OPTION . '[holidays][' . $i . ']';
		$ranges_name = $base . '[ranges]';
		$is_closed   = ! empty( $holiday['closed'] );
		$source      = ( isset( $holiday['source'] ) && 'bank_holiday' === $holiday['source'] ) ? 'bank_holiday' : '';
		$ranges      = isset( $holiday['ranges'] ) && is_array( $holiday['ranges'] ) ? $holiday['ranges'] : array();
		$range_limit = count( $ranges ) >= IBOH_Settings::MAX_RANGES;
		/* translators: %s: special date in YYYY-MM-DD format. */
		$context        = sprintf( __( 'the date %s', 'itboffins-live-opening-hours' ), $date );
		/* translators: %s: weekday or special date. */
		$ranges_label   = sprintf( __( 'Opening times for %s', 'itboffins-live-opening-hours' ), $context );
		$range_limit_id = 'iboh-holiday-' . $i . '-range-limit';
		?>
		<div class="iboh-holiday <?php echo $is_closed ? 'iboh-is-closed' : ''; ?>" data-iboh-holiday <?php echo $is_yesterday ? 'data-iboh-yesterday="1"' : ''; ?> role="group" aria-label="<?php echo esc_attr( $context ); ?>">
			<div class="iboh-holiday-top">
				<input type="hidden" class="iboh-holiday-source" name="<?php echo esc_attr( $base ); ?>[source]" value="<?php echo esc_attr( $source ); ?>" />
				<label class="iboh-field">
					<span class="iboh-field-label"><?php esc_html_e( 'Date', 'itboffins-live-opening-hours' ); ?></span>
					<input type="date" name="<?php echo esc_attr( $base ); ?>[date]" value="<?php echo esc_attr( $date ); ?>" required />
				</label>
				<span class="iboh-holiday-weekday" data-iboh-holiday-weekday><?php echo esc_html( self::short_date( $date ) ); ?></span>
				<span class="iboh-tag iboh-tag-bank" data-iboh-bank-tag <?php echo 'bank_holiday' === $source ? '' : 'hidden'; ?>><?php esc_html_e( 'Bank holiday', 'itboffins-live-opening-hours' ); ?></span>
				<?php if ( $is_yesterday ) : ?>
					<span class="iboh-tag iboh-tag-yesterday"><?php esc_html_e( 'Yesterday - shown until midnight because it can still affect the early hours of today', 'itboffins-live-opening-hours' ); ?></span>
				<?php endif; ?>
				<label class="iboh-field iboh-field-grow">
					<span class="iboh-field-label"><?php esc_html_e( 'Note shown to customers (optional)', 'itboffins-live-opening-hours' ); ?></span>
					<input type="text" class="regular-text iboh-holiday-label" name="<?php echo esc_attr( $base ); ?>[label]" value="<?php echo esc_attr( isset( $holiday['label'] ) ? $holiday['label'] : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Christmas Day', 'itboffins-live-opening-hours' ); ?>" />
				</label>
				<button type="button" class="button-link iboh-remove-holiday" aria-label="<?php esc_attr_e( 'Remove this date', 'itboffins-live-opening-hours' ); ?>"><?php esc_html_e( 'Remove', 'itboffins-live-opening-hours' ); ?></button>
			</div>
			<?php
			$this->render_open_closed(
				$base . '[closed]',
				$is_closed,
				__( 'What happens on this date?', 'itboffins-live-opening-hours' ),
				__( 'Open with different hours', 'itboffins-live-opening-hours' ),
				__( 'Closed all day', 'itboffins-live-opening-hours' ),
				true
			);
			?>
			<div class="iboh-day-body" <?php echo $is_closed ? 'hidden' : ''; ?>>
				<div class="iboh-ranges" data-name="<?php echo esc_attr( $ranges_name ); ?>" role="group" aria-label="<?php echo esc_attr( $ranges_label ); ?>">
					<?php
					$j = 0;
					foreach ( $ranges as $range ) {
						$this->render_range_row( $ranges_name . '[' . $j . ']', $range, $context, $j + 1 );
						$j++;
					}
					?>
				</div>
				<button type="button" class="button-link iboh-add-range" aria-describedby="<?php echo esc_attr( $range_limit_id ); ?>" <?php disabled( $range_limit ); ?>><?php echo esc_html( $j > 0 ? __( '+ Add another time', 'itboffins-live-opening-hours' ) : __( 'Add opening hours', 'itboffins-live-opening-hours' ) ); ?></button>
				<span class="description iboh-limit-note" id="<?php echo esc_attr( $range_limit_id ); ?>" aria-live="polite" <?php echo $range_limit ? '' : 'hidden'; ?>>
					<?php
					printf(
						/* translators: %d: maximum number of time ranges. */
						esc_html__( 'You can add up to %d times per day.', 'itboffins-live-opening-hours' ),
						(int) IBOH_Settings::MAX_RANGES
					);
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Hidden <template> markup cloned by admin.js for new rows.
	 */
	private function render_templates() {
		?>
		<template id="iboh-range-tpl">
			<div class="iboh-range" role="group" aria-label="<?php esc_attr_e( 'Time', 'itboffins-live-opening-hours' ); ?>">
				<input type="hidden" data-field="open" data-iboh-24h-open value="00:00" disabled />
				<input type="hidden" data-field="close" data-iboh-24h-close value="24:00" disabled />
				<input type="time" data-field="open" data-iboh-time-open aria-label="<?php esc_attr_e( 'Opening time', 'itboffins-live-opening-hours' ); ?>" />
				<span class="iboh-range-sep" aria-hidden="true"><?php echo esc_html_x( 'to', 'between an opening and a closing time', 'itboffins-live-opening-hours' ); ?></span>
				<input type="time" data-field="close" data-iboh-time-close aria-label="<?php esc_attr_e( 'Closing time', 'itboffins-live-opening-hours' ); ?>" />
				<label class="iboh-range-24h"><input type="checkbox" class="iboh-24h-toggle" /> <?php esc_html_e( 'Open 24 hours', 'itboffins-live-opening-hours' ); ?></label>
				<span class="iboh-range-hint" data-iboh-range-hint></span>
				<button type="button" class="button-link iboh-remove-range" aria-label="<?php esc_attr_e( 'Remove this time', 'itboffins-live-opening-hours' ); ?>"><?php esc_html_e( 'Remove', 'itboffins-live-opening-hours' ); ?></button>
			</div>
		</template>

		<template id="iboh-holiday-tpl">
			<div class="iboh-holiday iboh-is-closed" data-iboh-holiday role="group" aria-label="<?php esc_attr_e( 'New date', 'itboffins-live-opening-hours' ); ?>">
				<div class="iboh-holiday-top">
					<input type="hidden" class="iboh-holiday-source" data-field="source" value="" />
					<label class="iboh-field">
						<span class="iboh-field-label"><?php esc_html_e( 'Date', 'itboffins-live-opening-hours' ); ?></span>
						<input type="date" data-field="date" required />
					</label>
					<span class="iboh-holiday-weekday" data-iboh-holiday-weekday></span>
					<span class="iboh-tag iboh-tag-bank" data-iboh-bank-tag hidden><?php esc_html_e( 'Bank holiday', 'itboffins-live-opening-hours' ); ?></span>
					<label class="iboh-field iboh-field-grow">
						<span class="iboh-field-label"><?php esc_html_e( 'Note shown to customers (optional)', 'itboffins-live-opening-hours' ); ?></span>
						<input type="text" class="regular-text iboh-holiday-label" data-field="label" placeholder="<?php esc_attr_e( 'e.g. Christmas Day', 'itboffins-live-opening-hours' ); ?>" />
					</label>
					<button type="button" class="button-link iboh-remove-holiday" aria-label="<?php esc_attr_e( 'Remove this date', 'itboffins-live-opening-hours' ); ?>"><?php esc_html_e( 'Remove', 'itboffins-live-opening-hours' ); ?></button>
				</div>
				<?php
				$this->render_open_closed(
					'',
					true,
					__( 'What happens on this date?', 'itboffins-live-opening-hours' ),
					__( 'Open with different hours', 'itboffins-live-opening-hours' ),
					__( 'Closed all day', 'itboffins-live-opening-hours' ),
					true,
					true
				);
				?>
				<div class="iboh-day-body" hidden>
					<div class="iboh-ranges" data-iboh-ranges role="group" aria-label="<?php esc_attr_e( 'Opening times for this date', 'itboffins-live-opening-hours' ); ?>"></div>
					<button type="button" class="button-link iboh-add-range"><?php esc_html_e( 'Add opening hours', 'itboffins-live-opening-hours' ); ?></button>
					<span class="description iboh-limit-note" data-iboh-range-limit-note aria-live="polite" hidden></span>
				</div>
			</div>
		</template>
		<?php
	}

	/**
	 * Output the IT Boffins branded header bar.
	 */
	private function brand_header() {
		$logo_url = add_query_arg( 'ver', IBOH_VERSION, IBOH_URL . 'assets/logo.png' );
		?>
		<div class="iboh-brandbar">
			<span class="iboh-logo">
				<img class="iboh-logo-img" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'IT Boffins', 'itboffins-live-opening-hours' ); ?>" />
			</span>
			<span class="iboh-brandbar-side">
				<span class="iboh-eyebrow"><?php esc_html_e( 'Made by IT Boffins', 'itboffins-live-opening-hours' ); ?></span>
				<span class="iboh-ver">v<?php echo esc_html( IBOH_VERSION ); ?></span>
			</span>
		</div>
		<?php
	}
}
