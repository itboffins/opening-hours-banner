<?php
/**
 * Admin settings screen: schedule editor, holidays, banner, and labels.
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

		$format_config  = IBOH_Config::data();
		$default_labels = IBOH_Settings::defaults()['labels'];
		$yesterday      = IBOH_Timezone::now()->modify( '-1 day' )->format( 'Y-m-d' );
		$preview_carry = array();
		if ( isset( $format_config['holidays'][ $yesterday ] ) ) {
			// The form intentionally omits past dates, but yesterday's complete
			// override still controls any post-midnight tail in the live preview.
			$preview_carry[ $yesterday ] = $format_config['holidays'][ $yesterday ];
		}
		wp_localize_script(
			'iboh-admin',
			'IBOH',
			array(
				'tz'         => IBOH_Timezone::iana(),
				'offset'     => IBOH_Timezone::offset_minutes(),
				'timeFormat' => $format_config['timeFormat'],
				'meridiem'   => $format_config['meridiem'],
				'list'       => $format_config['list'],
				'lang'       => $format_config['lang'],
				'dateNames'  => $format_config['dateNames'],
				'dayNames'   => IBOH_Config::day_names(),
				'closedText' => __( 'Closed', 'itboffins-live-opening-hours' ),
				'defaultLabels' => $default_labels,
				'optionName'    => IBOH_OPTION,
				'maxRanges'     => IBOH_Settings::MAX_RANGES,
				'maxHolidays'   => IBOH_Settings::MAX_HOLIDAYS,
				'previewCarry'  => $preview_carry,
				'i18n'          => array(
					'remove'              => __( 'Remove', 'itboffins-live-opening-hours' ),
					'hidden'              => __( 'Nothing to show today', 'itboffins-live-opening-hours' ),
					'missingDate'         => __( 'Pick a date, or remove this row.', 'itboffins-live-opening-hours' ),
					/* translators: %d: maximum number of time ranges. */
					'rangeLimit'          => sprintf( __( 'You can add up to %d time ranges here.', 'itboffins-live-opening-hours' ), IBOH_Settings::MAX_RANGES ),
					/* translators: %1$d: range number, %2$s: weekday or public special date. */
					'rangeGroupLabel'     => __( 'Time range %1$d for %2$s', 'itboffins-live-opening-hours' ),
					/* translators: %1$s: weekday or public special date, %2$d: range number. */
					'rangeOpenLabel'      => __( 'Opening time for %1$s, range %2$d', 'itboffins-live-opening-hours' ),
					/* translators: %1$s: weekday or public special date, %2$d: range number. */
					'rangeCloseLabel'     => __( 'Closing time for %1$s, range %2$d', 'itboffins-live-opening-hours' ),
					/* translators: %1$d: range number, %2$s: weekday or public special date. */
					'rangeRemoveLabel'    => __( 'Remove time range %1$d for %2$s', 'itboffins-live-opening-hours' ),
					/* translators: %s: public special-date description. */
					'timeRangesForContext' => __( 'Time ranges for %s', 'itboffins-live-opening-hours' ),
					/* translators: %s: public special date in YYYY-MM-DD format. */
					'publicSpecialDateContext' => __( 'public special date %s', 'itboffins-live-opening-hours' ),
					'newPublicSpecialDateContext' => __( 'New public special date', 'itboffins-live-opening-hours' ),
					/* translators: %d: maximum number of public special dates. */
					'holidayLimit'        => sprintf( __( 'You can add up to %d public special dates.', 'itboffins-live-opening-hours' ), IBOH_Settings::MAX_HOLIDAYS ),
					'limitReached'        => __( 'Limit reached.', 'itboffins-live-opening-hours' ),
					'holidayLimitReached' => __( 'The public special-date limit has been reached. Remove a date before adding another.', 'itboffins-live-opening-hours' ),
					/* translators: %1$d: number of dates added, %2$d: number of existing dates replaced, %3$d: number of dates already in the list. */
					'bankHolidaysAdded'   => __( 'Added: %1$d. Replaced existing dates: %2$d. Already in your list: %3$d. Save changes when you are happy.', 'itboffins-live-opening-hours' ),
					'bankHolidaysNone'    => __( 'Those bank holidays are already in your list.', 'itboffins-live-opening-hours' ),
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

		$settings = IBOH_Settings::all();
		$schedule = $settings['schedule'];
		$banner   = $settings['banner'];
		$labels   = $settings['labels'];
		$options  = $settings['options'];
		$days     = IBOH_Config::day_names();
		$order    = array( 1, 2, 3, 4, 5, 6, 0 ); // Display Monday..Sunday.
		$year_now         = (int) wp_date( 'Y' );
		$today            = IBOH_Timezone::now()->format( 'Y-m-d' );
		$public_date_count = 0;
		foreach ( array_keys( $settings['holidays'] ) as $special_date ) {
			if ( $special_date >= $today ) {
				$public_date_count++;
			}
		}
		$public_date_limit_reached = $public_date_count >= IBOH_Settings::MAX_HOLIDAYS;
		?>
		<div class="wrap iboh-wrap">
			<?php $this->brand_header(); ?>
			<h1><?php esc_html_e( 'Live Opening Hours', 'itboffins-live-opening-hours' ); ?></h1>
			<p class="iboh-intro"><?php esc_html_e( 'Tell customers when you are open, closed, or taking a day off - without making them hunt for it.', 'itboffins-live-opening-hours' ); ?></p>
			<?php settings_errors(); ?>

			<div class="iboh-card iboh-preview-card">
				<span class="iboh-eyebrow"><?php esc_html_e( 'Customer preview', 'itboffins-live-opening-hours' ); ?></span>
				<div class="iboh-preview" id="iboh-preview" role="status" aria-live="polite" aria-atomic="true">
					<span class="iboh-dot" aria-hidden="true"></span>
					<span class="iboh-preview-main" data-iboh-main>—</span>
					<span class="iboh-preview-sub" data-iboh-sub></span>
					<span class="iboh-preview-upcoming" data-iboh-upcoming></span>
				</div>
				<p class="description"><?php esc_html_e( 'This is what your customers will see. Save when you are happy.', 'itboffins-live-opening-hours' ); ?></p>
			</div>

			<form method="post" action="options.php" id="iboh-form">
				<?php settings_fields( 'iboh_settings_group' ); ?>

				<div class="iboh-card">
					<h2><?php esc_html_e( 'Your regular week', 'itboffins-live-opening-hours' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Set the hours you usually keep. Add another row for a lunch break, evening shift, or late-night opening.', 'itboffins-live-opening-hours' ); ?></p>
					<p class="description iboh-public-warning"><strong><?php esc_html_e( 'Public information:', 'itboffins-live-opening-hours' ); ?></strong> <?php esc_html_e( 'These hours are published on your site. Do not include confidential information.', 'itboffins-live-opening-hours' ); ?></p>

					<p class="iboh-weekly-toggle">
						<label>
							<input type="checkbox" id="iboh-weekly-enabled" data-iboh-option="weekly_enabled" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][weekly_enabled]" value="1" <?php checked( $options['weekly_enabled'] ); ?> />
							<?php esc_html_e( 'Use my regular weekly hours', 'itboffins-live-opening-hours' ); ?>
						</label>
						<span class="description"><?php esc_html_e( 'Turn this off if you only open on certain dates, pop-ups, or appointments.', 'itboffins-live-opening-hours' ); ?></span>
					</p>

					<div class="iboh-weekly-body" <?php echo empty( $options['weekly_enabled'] ) ? 'style="display:none;"' : ''; ?>>
						<div class="iboh-days">
							<?php foreach ( $order as $dow ) : ?>
								<?php $this->render_day_row( $dow, $schedule[ $dow ], isset( $days[ $dow ] ) ? $days[ $dow ] : '' ); ?>
							<?php endforeach; ?>
						</div>
						<p><button type="button" class="button" id="iboh-copy-weekdays"><?php esc_html_e( 'Copy Monday to the working week', 'itboffins-live-opening-hours' ); ?></button></p>
					</div>
				</div>

				<div class="iboh-card">
					<h2 id="iboh-special-dates-heading"><?php esc_html_e( 'Public special dates & one-off changes', 'itboffins-live-opening-hours' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Publish bank holidays, Christmas closures, early finishes, special Saturdays, or any date that does not follow your normal week.', 'itboffins-live-opening-hours' ); ?></p>
					<p class="description iboh-public-warning"><strong><?php esc_html_e( 'Public information:', 'itboffins-live-opening-hours' ); ?></strong> <?php esc_html_e( 'Dates, hours, and notes in this section are public and may be shown to visitors. Do not include confidential information.', 'itboffins-live-opening-hours' ); ?></p>

					<fieldset class="iboh-upcoming-mode">
						<legend><?php esc_html_e( 'Public special-date notices', 'itboffins-live-opening-hours' ); ?></legend>
						<label>
							<input type="radio" data-iboh-option="show_upcoming" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][show_upcoming]" value="0" <?php checked( empty( $options['show_upcoming'] ) ); ?> />
							<?php esc_html_e( 'Only mention a change on the day', 'itboffins-live-opening-hours' ); ?>
						</label>
						<label>
							<input type="radio" data-iboh-option="show_upcoming" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][show_upcoming]" value="1" <?php checked( ! empty( $options['show_upcoming'] ) ); ?> />
							<?php esc_html_e( 'Tell customers about upcoming changes in advance', 'itboffins-live-opening-hours' ); ?>
						</label>
					</fieldset>

					<fieldset class="iboh-upcoming-mode">
						<legend><?php esc_html_e( 'Public bank-holiday notices', 'itboffins-live-opening-hours' ); ?></legend>
						<label>
							<input type="radio" data-iboh-option="bank_holiday_mode" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][bank_holiday_mode]" value="seven_days" <?php checked( isset( $options['bank_holiday_mode'] ) ? $options['bank_holiday_mode'] : 'seven_days', 'seven_days' ); ?> />
							<?php esc_html_e( 'Show bank holidays only when they are within 7 days', 'itboffins-live-opening-hours' ); ?>
						</label>
						<label>
							<input type="radio" data-iboh-option="bank_holiday_mode" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][bank_holiday_mode]" value="all" <?php checked( isset( $options['bank_holiday_mode'] ) ? $options['bank_holiday_mode'] : 'seven_days', 'all' ); ?> />
							<?php esc_html_e( 'Show all future bank holidays', 'itboffins-live-opening-hours' ); ?>
						</label>
						<label>
							<input type="radio" data-iboh-option="bank_holiday_mode" name="<?php echo esc_attr( IBOH_OPTION ); ?>[options][bank_holiday_mode]" value="none" <?php checked( isset( $options['bank_holiday_mode'] ) ? $options['bank_holiday_mode'] : 'seven_days', 'none' ); ?> />
							<?php esc_html_e( 'Do not show bank holidays in upcoming notices', 'itboffins-live-opening-hours' ); ?>
						</label>
					</fieldset>

					<div class="iboh-bank-holidays">
						<div>
							<strong><?php esc_html_e( 'Closed on bank holidays?', 'itboffins-live-opening-hours' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Add the usual UK bank holidays as public closed dates. Choose your part of the UK and the year, then save.', 'itboffins-live-opening-hours' ); ?></p>
							<p class="description"><?php esc_html_e( 'One-off public holidays are not added automatically, so add those separately if you need them.', 'itboffins-live-opening-hours' ); ?></p>
						</div>
						<div class="iboh-bank-holiday-actions">
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
							<button type="button" class="button" id="iboh-add-bank-holidays" aria-describedby="iboh-holiday-limit" <?php disabled( $public_date_limit_reached ); ?>><?php esc_html_e( 'Add public closed bank holidays', 'itboffins-live-opening-hours' ); ?></button>
						</div>
						<p class="description iboh-bank-holiday-status" id="iboh-bank-holiday-status" aria-live="polite"></p>
					</div>

					<div class="iboh-holidays" id="iboh-holidays" role="group" aria-labelledby="iboh-special-dates-heading">
						<?php
						$i = 0;
						foreach ( $settings['holidays'] as $date => $holiday ) {
							$this->render_holiday_row( $i, $date, $holiday );
							$i++;
						}
						?>
					</div>
					<p>
						<button type="button" class="button" id="iboh-add-holiday" aria-describedby="iboh-holiday-limit" <?php disabled( $public_date_limit_reached ); ?>><?php esc_html_e( 'Add a public special date', 'itboffins-live-opening-hours' ); ?></button>
						<span class="description iboh-limit-note" id="iboh-holiday-limit" aria-live="polite">
							<?php
							printf(
								/* translators: %d: maximum number of public special dates. */
								esc_html__( 'You can add up to %d public special dates.', 'itboffins-live-opening-hours' ),
								(int) IBOH_Settings::MAX_HOLIDAYS
							);
							?>
						</span>
					</p>
				</div>

				<div class="iboh-card">
					<h2><?php esc_html_e( 'Customer notice bar', 'itboffins-live-opening-hours' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Show notice bar', 'itboffins-live-opening-hours' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( IBOH_OPTION ); ?>[banner][enabled]" value="1" <?php checked( $banner['enabled'] ); ?> />
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
					<p class="description"><?php esc_html_e( 'Make the public messages sound like your business. Keep any placeholders shown in the field help.', 'itboffins-live-opening-hours' ); ?></p>
					<p class="description iboh-public-warning"><strong><?php esc_html_e( 'Public information:', 'itboffins-live-opening-hours' ); ?></strong> <?php esc_html_e( 'This wording is published on your site. Do not include confidential information.', 'itboffins-live-opening-hours' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						$label_help = array(
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
							'open_24h'         => __( 'Message for a 24-hour day', 'itboffins-live-opening-hours' ),
							'upcoming_heading'     => __( 'Heading above upcoming changes', 'itboffins-live-opening-hours' ),
							/* translators: %s: date placeholder shown to administrators. */
							'upcoming_closed'      => __( 'One closed date (%s = date)', 'itboffins-live-opening-hours' ),
							/* translators: %s: date list placeholder shown to administrators. */
							'upcoming_closed_list' => __( 'Several closed dates (%s = date list)', 'itboffins-live-opening-hours' ),
							/* translators: %1$s: date placeholder, %2$s: opening-hours placeholder shown to administrators. */
							'upcoming_hours'       => __( 'One date with different hours (%1$s = date, %2$s = hours)', 'itboffins-live-opening-hours' ),
						);
						foreach ( $labels as $key => $value ) :
							?>
							<tr>
								<th scope="row"><label for="iboh-label-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( isset( $label_help[ $key ] ) ? $label_help[ $key ] : $key ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="iboh-label-<?php echo esc_attr( $key ); ?>" data-iboh-label="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( IBOH_OPTION ); ?>[labels][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" />
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>

				<?php submit_button(); ?>
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
		$range_limit = count( $day['ranges'] ) >= IBOH_Settings::MAX_RANGES;
		/* translators: %s: weekday name. */
		$ranges_label = sprintf( __( 'Time ranges for %s', 'itboffins-live-opening-hours' ), $name );
		?>
		<div class="iboh-day-row" data-iboh-day data-dow="<?php echo esc_attr( $dow ); ?>" role="group" aria-labelledby="<?php echo esc_attr( $day_id . '-name' ); ?>">
			<div class="iboh-day-name" id="<?php echo esc_attr( $day_id . '-name' ); ?>"><?php echo esc_html( $name ); ?></div>
			<label class="iboh-day-toggle">
				<input type="checkbox" class="iboh-closed-toggle" name="<?php echo esc_attr( IBOH_OPTION ); ?>[schedule][<?php echo esc_attr( $dow ); ?>][closed]" value="1" <?php checked( $is_closed ); ?> />
				<?php esc_html_e( 'Closed', 'itboffins-live-opening-hours' ); ?>
			</label>
			<div class="iboh-day-body" <?php echo $is_closed ? 'style="display:none;"' : ''; ?>>
				<div class="iboh-ranges" data-name="<?php echo esc_attr( $ranges_name ); ?>" role="group" aria-label="<?php echo esc_attr( $ranges_label ); ?>">
					<?php
					$i = 0;
					foreach ( $day['ranges'] as $range ) {
						$this->render_range_row( $ranges_name . '[' . $i . ']', $range, $name, $i + 1 );
						$i++;
					}
					?>
				</div>
				<button type="button" class="button-link iboh-add-range" aria-describedby="<?php echo esc_attr( $day_id . '-range-limit' ); ?>" <?php disabled( $range_limit ); ?>><?php esc_html_e( '+ Add another time', 'itboffins-live-opening-hours' ); ?></button>
				<span class="description iboh-limit-note" id="<?php echo esc_attr( $day_id . '-range-limit' ); ?>" aria-live="polite">
					<?php
					printf(
						/* translators: %d: maximum number of time ranges. */
						esc_html__( 'You can add up to %d time ranges here.', 'itboffins-live-opening-hours' ),
						(int) IBOH_Settings::MAX_RANGES
					);
					?>
				</span>
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
		$display_close = $is_24h ? '23:59' : $close;
		/* translators: %1$d: range number, %2$s: weekday or public special date. */
		$group_label  = sprintf( __( 'Time range %1$d for %2$s', 'itboffins-live-opening-hours' ), $range_number, $context );
		/* translators: %1$s: weekday or public special date, %2$d: range number. */
		$open_label   = sprintf( __( 'Opening time for %1$s, range %2$d', 'itboffins-live-opening-hours' ), $context, $range_number );
		/* translators: %1$s: weekday or public special date, %2$d: range number. */
		$close_label  = sprintf( __( 'Closing time for %1$s, range %2$d', 'itboffins-live-opening-hours' ), $context, $range_number );
		/* translators: %1$d: range number, %2$s: weekday or public special date. */
		$remove_label = sprintf( __( 'Remove time range %1$d for %2$s', 'itboffins-live-opening-hours' ), $range_number, $context );
		?>
		<div class="iboh-range" role="group" aria-label="<?php echo esc_attr( $group_label ); ?>">
			<input type="hidden" data-iboh-24h-open name="<?php echo esc_attr( $name_prefix ); ?>[open]" value="00:00" <?php disabled( ! $is_24h ); ?> />
			<input type="hidden" data-iboh-24h-close name="<?php echo esc_attr( $name_prefix ); ?>[close]" value="24:00" <?php disabled( ! $is_24h ); ?> />
			<input type="time" data-iboh-time-open name="<?php echo esc_attr( $name_prefix ); ?>[open]" value="<?php echo esc_attr( $display_open ); ?>" aria-label="<?php echo esc_attr( $open_label ); ?>" <?php disabled( $is_24h ); ?> />
			<span class="iboh-range-sep">&ndash;</span>
			<input type="time" data-iboh-time-close name="<?php echo esc_attr( $name_prefix ); ?>[close]" value="<?php echo esc_attr( $display_close ); ?>" aria-label="<?php echo esc_attr( $close_label ); ?>" <?php disabled( $is_24h ); ?> />
			<label class="iboh-range-24h"><input type="checkbox" class="iboh-24h-toggle" <?php checked( $is_24h ); ?> /> <?php esc_html_e( 'Open 24 hours', 'itboffins-live-opening-hours' ); ?></label>
			<button type="button" class="button-link iboh-remove-range" aria-label="<?php echo esc_attr( $remove_label ); ?>"><?php esc_html_e( 'Remove', 'itboffins-live-opening-hours' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Render one holiday / special-date row.
	 *
	 * @param int    $i       Row index.
	 * @param string $date    Date (YYYY-MM-DD).
	 * @param array  $holiday { closed, ranges, label }.
	 */
	private function render_holiday_row( $i, $date, $holiday ) {
		$base        = IBOH_OPTION . '[holidays][' . $i . ']';
		$ranges_name = $base . '[ranges]';
		$is_closed   = ! empty( $holiday['closed'] );
		$source      = ( isset( $holiday['source'] ) && 'bank_holiday' === $holiday['source'] ) ? 'bank_holiday' : '';
		$range_limit = isset( $holiday['ranges'] ) && is_array( $holiday['ranges'] ) && count( $holiday['ranges'] ) >= IBOH_Settings::MAX_RANGES;
		/* translators: %s: public special date in YYYY-MM-DD format. */
		$context        = sprintf( __( 'public special date %s', 'itboffins-live-opening-hours' ), $date );
		/* translators: %s: public special-date description. */
		$ranges_label   = sprintf( __( 'Time ranges for %s', 'itboffins-live-opening-hours' ), $context );
		$range_limit_id = 'iboh-holiday-' . $i . '-range-limit';
		?>
		<div class="iboh-holiday" data-iboh-holiday role="group" aria-label="<?php echo esc_attr( $context ); ?>">
			<div class="iboh-holiday-top">
				<input type="hidden" class="iboh-holiday-source" name="<?php echo esc_attr( $base ); ?>[source]" value="<?php echo esc_attr( $source ); ?>" />
				<input type="date" name="<?php echo esc_attr( $base ); ?>[date]" value="<?php echo esc_attr( $date ); ?>" aria-label="<?php esc_attr_e( 'Public special date', 'itboffins-live-opening-hours' ); ?>" required />
				<input type="text" class="regular-text iboh-holiday-label" name="<?php echo esc_attr( $base ); ?>[label]" value="<?php echo esc_attr( isset( $holiday['label'] ) ? $holiday['label'] : '' ); ?>" aria-label="<?php esc_attr_e( 'Public note (optional)', 'itboffins-live-opening-hours' ); ?>" placeholder="<?php esc_attr_e( 'Public note (optional)', 'itboffins-live-opening-hours' ); ?>" />
				<label class="iboh-day-toggle">
					<input type="checkbox" class="iboh-closed-toggle" name="<?php echo esc_attr( $base ); ?>[closed]" value="1" <?php checked( $is_closed ); ?> />
					<?php esc_html_e( 'Closed', 'itboffins-live-opening-hours' ); ?>
				</label>
				<button type="button" class="button-link iboh-remove-holiday" aria-label="<?php esc_attr_e( 'Remove this public special date', 'itboffins-live-opening-hours' ); ?>"><?php esc_html_e( 'Remove', 'itboffins-live-opening-hours' ); ?></button>
			</div>
			<div class="iboh-day-body" <?php echo $is_closed ? 'style="display:none;"' : ''; ?>>
				<div class="iboh-ranges" data-name="<?php echo esc_attr( $ranges_name ); ?>" role="group" aria-label="<?php echo esc_attr( $ranges_label ); ?>">
					<?php
					$j      = 0;
					$ranges = isset( $holiday['ranges'] ) && is_array( $holiday['ranges'] ) ? $holiday['ranges'] : array();
					foreach ( $ranges as $range ) {
						$this->render_range_row( $ranges_name . '[' . $j . ']', $range, $context, $j + 1 );
						$j++;
					}
					?>
				</div>
				<button type="button" class="button-link iboh-add-range" aria-describedby="<?php echo esc_attr( $range_limit_id ); ?>" <?php disabled( $range_limit ); ?>><?php esc_html_e( '+ Add another time', 'itboffins-live-opening-hours' ); ?></button>
				<span class="description iboh-limit-note" id="<?php echo esc_attr( $range_limit_id ); ?>" aria-live="polite">
					<?php
					printf(
						/* translators: %d: maximum number of time ranges. */
						esc_html__( 'You can add up to %d time ranges here.', 'itboffins-live-opening-hours' ),
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
			<div class="iboh-range" role="group" aria-label="<?php esc_attr_e( 'Time range', 'itboffins-live-opening-hours' ); ?>">
				<input type="hidden" data-field="open" data-iboh-24h-open value="00:00" disabled />
				<input type="hidden" data-field="close" data-iboh-24h-close value="24:00" disabled />
				<input type="time" data-field="open" data-iboh-time-open aria-label="<?php esc_attr_e( 'Opening time', 'itboffins-live-opening-hours' ); ?>" />
				<span class="iboh-range-sep">&ndash;</span>
				<input type="time" data-field="close" data-iboh-time-close aria-label="<?php esc_attr_e( 'Closing time', 'itboffins-live-opening-hours' ); ?>" />
				<label class="iboh-range-24h"><input type="checkbox" class="iboh-24h-toggle" /> <?php esc_html_e( 'Open 24 hours', 'itboffins-live-opening-hours' ); ?></label>
				<button type="button" class="button-link iboh-remove-range" aria-label="<?php esc_attr_e( 'Remove this time range', 'itboffins-live-opening-hours' ); ?>"><?php esc_html_e( 'Remove', 'itboffins-live-opening-hours' ); ?></button>
			</div>
		</template>

		<template id="iboh-holiday-tpl">
			<div class="iboh-holiday" data-iboh-holiday role="group" aria-label="<?php esc_attr_e( 'New public special date', 'itboffins-live-opening-hours' ); ?>">
				<div class="iboh-holiday-top">
					<input type="hidden" class="iboh-holiday-source" data-field="source" value="" />
					<input type="date" data-field="date" aria-label="<?php esc_attr_e( 'Public special date', 'itboffins-live-opening-hours' ); ?>" required />
					<input type="text" class="regular-text iboh-holiday-label" data-field="label" aria-label="<?php esc_attr_e( 'Public note (optional)', 'itboffins-live-opening-hours' ); ?>" placeholder="<?php esc_attr_e( 'Public note (optional)', 'itboffins-live-opening-hours' ); ?>" />
					<label class="iboh-day-toggle">
						<input type="checkbox" class="iboh-closed-toggle" data-field="closed" value="1" checked />
						<?php esc_html_e( 'Closed', 'itboffins-live-opening-hours' ); ?>
					</label>
					<button type="button" class="button-link iboh-remove-holiday" aria-label="<?php esc_attr_e( 'Remove this public special date', 'itboffins-live-opening-hours' ); ?>"><?php esc_html_e( 'Remove', 'itboffins-live-opening-hours' ); ?></button>
				</div>
				<div class="iboh-day-body" style="display:none;">
					<div class="iboh-ranges" data-iboh-ranges role="group" aria-label="<?php esc_attr_e( 'Time ranges for this public special date', 'itboffins-live-opening-hours' ); ?>"></div>
					<button type="button" class="button-link iboh-add-range"><?php esc_html_e( '+ Add another time', 'itboffins-live-opening-hours' ); ?></button>
					<span class="description iboh-limit-note" data-iboh-range-limit-note aria-live="polite"></span>
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
