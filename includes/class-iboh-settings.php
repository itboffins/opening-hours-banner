<?php
/**
 * Settings storage, defaults, and sanitisation.
 *
 * Everything lives in a single option array (IBOH_OPTION) with five top-level
 * groups: schedule, holidays, options, banner, labels.
 *
 * @package ITBoffins_Live_Opening_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the single options array.
 */
class IBOH_Settings {

	/**
	 * Maximum number of opening ranges allowed per day / per holiday.
	 */
	const MAX_RANGES = 6;

	/**
	 * Maximum number of current/future public special dates. The previous date's
	 * temporary evaluator carry is deliberately outside this limit.
	 */
	const MAX_HOLIDAYS = 200;

	/**
	 * Option marker: stored label values are explicit sparse overrides.
	 */
	const LABEL_OVERRIDE_MARKER = '_labels_are_overrides';

	/**
	 * Form marker: the FIRST field in the settings form. It identifies a
	 * submission from the current editor, which always ends with the sentinel.
	 */
	const FORM_MARKER = '_form';

	/**
	 * Form sentinel: the LAST field in the settings form. A submission that has
	 * the form marker but not the sentinel was truncated by max_input_vars.
	 */
	const FORM_COMPLETE_MARKER = '_complete';

	/**
	 * Request-local normalized settings, keyed by blog, locale, date, raw option,
	 * and whether yesterday's special date is required by the live evaluator.
	 *
	 * @var array<string,array>
	 */
	private static $cache = array();

	/**
	 * Candidate locale-specific default label maps, collected once per request.
	 *
	 * @var array<int,array<string,string>>|null
	 */
	private static $legacy_label_defaults = null;

	/**
	 * Default settings.
	 *
	 * Weekday keys are 0..6 = Sunday..Saturday, matching JavaScript's
	 * Date.prototype.getDay() so the PHP and JS evaluators agree without an
	 * off-by-one translation layer.
	 *
	 * @return array
	 */
	public static function defaults() {
		$nine_to_five = array(
			'closed' => 0,
			'ranges' => array( array( 'open' => '09:00', 'close' => '17:00' ) ),
		);

		return array(
			'schedule' => array(
				0 => array( 'closed' => 1, 'ranges' => array() ), // Sunday.
				1 => $nine_to_five,
				2 => $nine_to_five,
				3 => $nine_to_five,
				4 => $nine_to_five,
				5 => $nine_to_five,
				6 => array( 'closed' => 1, 'ranges' => array() ), // Saturday.
			),

			// Date overrides keyed by 'YYYY-MM-DD'. Each fully replaces that
			// weekday's hours for the given date.
			'holidays' => array(),

			// Display behaviour that is neither schedule data nor banner styling.
			'options'  => array(
				// Use the weekly schedule at all. Off = "special dates only"
				// (e.g. an events / by-appointment venue with no fixed week).
				'weekly_enabled' => 1,
				// Surface upcoming special dates ahead of time (banner + table),
				// not just on the day itself. Off keeps the 1.0 behaviour.
				'show_upcoming'     => 0,
				// Bank holidays can stay quieter than manual special dates.
				'bank_holiday_mode' => 'seven_days',
				// '' = follow the WordPress site timezone. Otherwise an IANA name
				// (America/Anchorage) or a WordPress-style manual offset (UTC-9).
				'timezone'          => '',
			),

			'banner'   => array(
				'enabled'       => 1,
				'position'      => 'top',   // 'top' | 'bottom'.
				'sticky'        => 1,       // Keep visible while scrolling.
				'push_top'      => 1,       // Top banner takes up its own space.
				'dismissible'   => 1,
				'show_next'     => 1,       // Show "Closes at …" / "Opens …" line.
				'soon_mins'     => 60,      // Within N minutes -> "soon" wording.
				'colour_open'   => '#00794a',
				'colour_closed' => '#b3261e',
				'colour_text'   => '#ffffff',
			),

			// Editable, translatable display strings (British spelling).
			'labels'   => array(
				'open'         => __( "We're open", 'itboffins-live-opening-hours' ),
				'closed'       => __( "Sorry, we're closed", 'itboffins-live-opening-hours' ),
				/* translators: %s: closing time. */
				'closes_at'    => __( 'Closes at %s', 'itboffins-live-opening-hours' ),
				/* translators: %s: opening time. */
				'opens_today'  => __( 'Opens today at %s', 'itboffins-live-opening-hours' ),
				/* translators: %1$s: weekday, %2$s: opening time. */
				'opens_on'     => __( 'Opens %1$s at %2$s', 'itboffins-live-opening-hours' ),
				'opening_soon' => __( 'Opening soon', 'itboffins-live-opening-hours' ),
				'closing_soon' => __( 'Closing soon', 'itboffins-live-opening-hours' ),
				'open_24h'     => __( 'Open 24 hours', 'itboffins-live-opening-hours' ),
				/* translators: heading above the list of upcoming special dates. */
				'upcoming_heading' => __( 'Upcoming dates', 'itboffins-live-opening-hours' ),
				/* translators: %s = date (and optional label), e.g. "Wed 25 Dec (Christmas)". */
				'upcoming_closed'      => __( 'Closed %s', 'itboffins-live-opening-hours' ),
				/* translators: %s = human-readable list of dates, e.g. "Wed 25 Dec and Thu 26 Dec". */
				'upcoming_closed_list' => __( '%s we are closed', 'itboffins-live-opening-hours' ),
				/* translators: %1$s = date (and optional label), %2$s = hours, e.g. "Sat 31 Dec: 10:00 – 14:00". */
				'upcoming_hours'       => __( '%1$s: %2$s', 'itboffins-live-opening-hours' ),
			),
		);
	}

	/**
	 * Convert legacy, fully populated label storage into marked sparse overrides.
	 *
	 * Older releases saved the translated defaults alongside genuine custom
	 * wording. On upgrade, infer one coherent default set from the source,
	 * current, and installed locales so a later locale switch can use its own
	 * translations. Ambiguous cross-locale values remain custom wording.
	 *
	 * @return void
	 */
	public static function maybe_migrate_labels() {
		$saved = get_option( IBOH_OPTION, array() );
		if ( ! is_array( $saved ) || ! empty( $saved[ self::LABEL_OVERRIDE_MARKER ] ) ) {
			return;
		}

		$defaults     = self::defaults();
		$saved_labels = isset( $saved['labels'] ) && is_array( $saved['labels'] ) ? $saved['labels'] : array();
		$legacy_keys  = self::legacy_default_keys( $saved_labels );
		$overrides    = array();
		foreach ( array_keys( $defaults['labels'] ) as $key ) {
			if ( ! isset( $saved_labels[ $key ] ) || ! is_scalar( $saved_labels[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $saved_labels[ $key ] );
			if ( '' !== $value && ! isset( $legacy_keys[ $key ] ) ) {
				$overrides[ $key ] = $value;
			}
		}

		$saved['labels'] = $overrides;
		$saved[ self::LABEL_OVERRIDE_MARKER ] = 1;
		update_option( IBOH_OPTION, $saved );
		self::$cache = array();
	}

	/**
	 * Get the full settings array, merged over defaults.
	 *
	 * wp_parse_args() is shallow, so the nested groups are each merged
	 * explicitly. This keeps the structure complete (and forward-compatible:
	 * sub-keys added in future versions inherit their defaults automatically).
	 *
	 * @param bool $include_yesterday Include yesterday's complete special-date
	 *                                override when needed by live evaluation.
	 * @return array
	 */
	public static function all( $include_yesterday = false ) {
		$defaults = self::defaults();
		$saved    = get_option( IBOH_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$blog_id     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$locale      = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$fingerprint = md5( maybe_serialize( $saved ) );
		$cache_key   = implode(
			'|',
			array(
				(string) $blog_id,
				(string) $locale,
				self::today_date(),
				$include_yesterday ? 'with-yesterday' : 'current',
				$fingerprint,
			)
		);
		if ( isset( self::$cache[ $cache_key ] ) ) {
			return self::$cache[ $cache_key ];
		}

		$out = wp_parse_args( $saved, $defaults );

		// Schedule: guarantee seven normalized weekdays. An open day without a
		// valid range is closed, rather than an ambiguous empty/open state. A
		// closed day keeps its last hours so they return when it reopens; both
		// evaluators ignore ranges while a day is closed.
		$saved_schedule = ( isset( $saved['schedule'] ) && is_array( $saved['schedule'] ) ) ? $saved['schedule'] : array();
		$schedule       = array();
		foreach ( $defaults['schedule'] as $dow => $default_day ) {
			$day = isset( $saved_schedule[ $dow ] ) && is_array( $saved_schedule[ $dow ] )
				? $saved_schedule[ $dow ]
				: $default_day;
			$closed = empty( $day['closed'] ) ? 0 : 1;
			$ranges = self::sanitize_ranges( isset( $day['ranges'] ) ? $day['ranges'] : array() );
			if ( ! $closed && empty( $ranges ) ) {
				$closed = 1;
			}
			$schedule[ $dow ] = array(
				'closed' => $closed,
				'ranges' => $ranges,
			);
		}
		$out['schedule'] = $schedule;

		// Admin/table callers get today and future dates. The live evaluator can
		// request yesterday too, so its complete override remains authoritative
		// during any possible overnight tail.
		$saved_holidays  = ( isset( $saved['holidays'] ) && is_array( $saved['holidays'] ) ) ? $saved['holidays'] : array();
		$out['holidays'] = self::current_holidays( $saved_holidays, $include_yesterday );

		// Options + banner: deep-merge over defaults and normalize defensively in
		// case an old or externally modified option bypassed sanitize().
		$options = ( isset( $saved['options'] ) && is_array( $saved['options'] ) ) ? $saved['options'] : array();
		$out['options'] = array(
			'weekly_enabled'   => isset( $options['weekly_enabled'] ) ? ( empty( $options['weekly_enabled'] ) ? 0 : 1 ) : $defaults['options']['weekly_enabled'],
			'show_upcoming'    => isset( $options['show_upcoming'] ) ? ( empty( $options['show_upcoming'] ) ? 0 : 1 ) : $defaults['options']['show_upcoming'],
			'bank_holiday_mode' => isset( $options['bank_holiday_mode'] ) && in_array( $options['bank_holiday_mode'], array( 'seven_days', 'all', 'none' ), true )
				? $options['bank_holiday_mode']
				: $defaults['options']['bank_holiday_mode'],
			'timezone'          => IBOH_Timezone::sanitize_setting( isset( $options['timezone'] ) ? $options['timezone'] : '' ),
		);

		$banner = ( isset( $saved['banner'] ) && is_array( $saved['banner'] ) ) ? $saved['banner'] : array();
		$out['banner'] = array(
			'enabled'       => isset( $banner['enabled'] ) ? ( empty( $banner['enabled'] ) ? 0 : 1 ) : $defaults['banner']['enabled'],
			'position'      => isset( $banner['position'] ) && is_scalar( $banner['position'] ) && in_array( $banner['position'], array( 'top', 'bottom' ), true ) ? $banner['position'] : $defaults['banner']['position'],
			'sticky'        => isset( $banner['sticky'] ) ? ( empty( $banner['sticky'] ) ? 0 : 1 ) : $defaults['banner']['sticky'],
			'push_top'      => isset( $banner['push_top'] ) ? ( empty( $banner['push_top'] ) ? 0 : 1 ) : $defaults['banner']['push_top'],
			'dismissible'   => isset( $banner['dismissible'] ) ? ( empty( $banner['dismissible'] ) ? 0 : 1 ) : $defaults['banner']['dismissible'],
			'show_next'     => isset( $banner['show_next'] ) ? ( empty( $banner['show_next'] ) ? 0 : 1 ) : $defaults['banner']['show_next'],
			'soon_mins'     => isset( $banner['soon_mins'] ) && is_scalar( $banner['soon_mins'] ) ? max( 0, min( 720, absint( $banner['soon_mins'] ) ) ) : $defaults['banner']['soon_mins'],
			'colour_open'   => self::normalized_colour( isset( $banner['colour_open'] ) ? $banner['colour_open'] : '', $defaults['banner']['colour_open'] ),
			'colour_closed' => self::normalized_colour( isset( $banner['colour_closed'] ) ? $banner['colour_closed'] : '', $defaults['banner']['colour_closed'] ),
			'colour_text'   => self::normalized_colour( isset( $banner['colour_text'] ) ? $banner['colour_text'] : '', $defaults['banner']['colour_text'] ),
		);

		// Marked label values are explicit sparse overrides. For unmarked legacy
		// options, known defaults from installed locales are migrated while unknown
		// values remain intact rather than risking deletion of custom wording.
		$saved_labels  = ( isset( $saved['labels'] ) && is_array( $saved['labels'] ) ) ? $saved['labels'] : array();
		$sparse_labels = ! empty( $saved[ self::LABEL_OVERRIDE_MARKER ] );
		$legacy_keys   = $sparse_labels ? array() : self::legacy_default_keys( $saved_labels );
		$overrides     = array();
		foreach ( array_keys( $defaults['labels'] ) as $key ) {
			if ( ! isset( $saved_labels[ $key ] ) || ! is_scalar( $saved_labels[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $saved_labels[ $key ] );
			if ( '' !== $value && ( $sparse_labels || ! isset( $legacy_keys[ $key ] ) ) ) {
				$overrides[ $key ] = $value;
			}
		}
		$out['labels'] = wp_parse_args( $overrides, $defaults['labels'] );
		unset( $out[ self::LABEL_OVERRIDE_MARKER ] );

		self::$cache[ $cache_key ] = $out;
		return self::$cache[ $cache_key ];
	}

	/**
	 * Get a single top-level setting group.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if missing.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Sanitise an incoming settings array (Settings API callback).
	 *
	 * Always returns a complete, normalised structure so a saved option is
	 * never left partial or malformed.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();
		$range_truncated   = 0;
		$range_invalid     = 0;
		$holiday_truncated = 0;
		$auto_closed       = array();
		$duplicate_dates   = array();

		// A large schedule can exceed PHP's max_input_vars, in which case PHP
		// silently drops the end of the POST. The settings form starts with a
		// marker and ends with a sentinel; a marker without the sentinel means
		// the submission was cut short and saving it would delete data. Input
		// without the marker (an already-sanitized value passed back through
		// update_option, or a page loaded before an upgrade) is accepted.
		$from_editor = ! empty( $input[ self::FORM_MARKER ] );
		if ( $from_editor && empty( $input[ self::FORM_COMPLETE_MARKER ] ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					IBOH_OPTION,
					'iboh_form_truncated',
					__( 'Nothing was saved: the browser sent an incomplete form. This usually means the PHP max_input_vars limit is too low for the number of special dates. Ask your host to raise it, or remove some dates and try again.', 'itboffins-live-opening-hours' ),
					'error'
				);
			}
			$existing = get_option( IBOH_OPTION, array() );
			return is_array( $existing ) ? $existing : array();
		}

		// --- Options --------------------------------------------------------
		// Resolved first because "today" depends on the submitted timezone.
		$options                             = isset( $input['options'] ) && is_array( $input['options'] ) ? $input['options'] : array();
		$out['options']                      = array();
		$out['options']['weekly_enabled']    = empty( $options['weekly_enabled'] ) ? 0 : 1;
		$out['options']['show_upcoming']     = empty( $options['show_upcoming'] ) ? 0 : 1;
		$bank_holiday_modes                  = array( 'seven_days', 'all', 'none' );
		$out['options']['bank_holiday_mode'] = ( isset( $options['bank_holiday_mode'] ) && in_array( $options['bank_holiday_mode'], $bank_holiday_modes, true ) )
			? $options['bank_holiday_mode']
			: $defaults['options']['bank_holiday_mode'];
		$out['options']['timezone']          = IBOH_Timezone::sanitize_setting( isset( $options['timezone'] ) ? $options['timezone'] : '' );
		$timezone                            = IBOH_Timezone::from_setting( $out['options']['timezone'] );
		$day_names                           = IBOH_Config::day_names();

		// --- Schedule (weekdays 0..6) ---------------------------------------
		// A closed day keeps its hours so they come back when it is reopened;
		// both evaluators ignore ranges while a day is closed.
		$out['schedule'] = array();
		foreach ( array( 0, 1, 2, 3, 4, 5, 6 ) as $dow ) {
			$day             = isset( $input['schedule'][ $dow ] ) && is_array( $input['schedule'][ $dow ] ) ? $input['schedule'][ $dow ] : array();
			$closed          = empty( $day['closed'] ) ? 0 : 1;
			$ranges          = self::sanitize_ranges( isset( $day['ranges'] ) ? $day['ranges'] : array(), $range_truncated, $range_invalid );
			if ( ! $closed && empty( $ranges ) ) {
				$closed        = 1;
				$auto_closed[] = isset( $day_names[ $dow ] ) ? $day_names[ $dow ] : (string) $dow;
			}
			$out['schedule'][ $dow ] = array(
				'closed' => $closed,
				'ranges' => $ranges,
			);
		}

		// --- Holidays / special dates ---------------------------------------
		// The editor shows yesterday's row whenever it can still affect the
		// hours after midnight, so whatever the form sends is authoritative:
		// a row the owner removed stays removed.
		$out['holidays'] = array();
		$holidays        = isset( $input['holidays'] ) && is_array( $input['holidays'] ) ? $input['holidays'] : array();
		$today           = self::today_date( $timezone );
		$yesterday       = self::shift_date( $today, -1 );
		$discarded       = 0;
		$holiday_count   = 0;

		foreach ( $holidays as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$raw_date = isset( $row['date'] ) && is_scalar( $row['date'] ) ? (string) $row['date'] : '';
			if ( '' === trim( (string) $raw_date ) && is_string( $key ) ) {
				$raw_date = $key;
			}
			if ( '' === trim( (string) $raw_date ) ) {
				if ( self::holiday_row_has_content( $row ) ) {
					$discarded++;
				}
				continue;
			}

			$date = self::valid_date( $raw_date );
			if ( null === $date ) {
				$discarded++;
				continue;
			}
			if ( $date < $yesterday ) {
				continue;
			}

			$closed = empty( $row['closed'] ) ? 0 : 1;
			$ranges = self::sanitize_ranges( isset( $row['ranges'] ) ? $row['ranges'] : array(), $range_truncated, $range_invalid );
			if ( ! $closed && empty( $ranges ) ) {
				$closed        = 1;
				$auto_closed[] = $date;
			}

			$source = ( isset( $row['source'] ) && 'bank_holiday' === $row['source'] ) ? 'bank_holiday' : '';
			if ( isset( $out['holidays'][ $date ] ) ) {
				$duplicate_dates[ $date ] = true;
				if (
					'bank_holiday' === ( isset( $out['holidays'][ $date ]['source'] ) ? $out['holidays'][ $date ]['source'] : '' )
					&& 'bank_holiday' !== $source
				) {
					continue;
				}
			}
			$is_current = $date >= $today;
			if ( $is_current && ! isset( $out['holidays'][ $date ] ) && $holiday_count >= self::MAX_HOLIDAYS ) {
				$holiday_truncated++;
				continue;
			}
			if ( $is_current && ! isset( $out['holidays'][ $date ] ) ) {
				$holiday_count++;
			}

			$out['holidays'][ $date ] = array(
				'closed' => $closed,
				'ranges' => $ranges,
				'label'  => isset( $row['label'] ) && is_scalar( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
			);
			if ( 'bank_holiday' === $source ) {
				$out['holidays'][ $date ]['source'] = 'bank_holiday';
			}
		}

		// The editor lists dates from yesterday onward in the timezone that was
		// saved when the page loaded. If this save moves to a timezone whose
		// calendar is behind, dates that are still today or yesterday there were
		// never on the page: keep their stored rows rather than deleting them.
		if ( $from_editor ) {
			$existing = get_option( IBOH_OPTION, array() );
			$stored   = ( is_array( $existing ) && isset( $existing['holidays'] ) && is_array( $existing['holidays'] ) ) ? $existing['holidays'] : array();
			$shown_from = self::shift_date( self::today_date( IBOH_Timezone::timezone() ), -1 );
			foreach ( $stored as $date => $row ) {
				if ( ! is_string( $date ) || null === self::valid_date( $date ) || ! is_array( $row ) ) {
					continue;
				}
				if ( $date >= $yesterday && $date < $shown_from && ! isset( $out['holidays'][ $date ] ) ) {
					$out['holidays'][ $date ] = self::normalize_holiday( $row );
				}
			}
		}
		ksort( $out['holidays'] );

		if ( ! empty( $auto_closed ) && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				IBOH_OPTION,
				'iboh_auto_closed',
				sprintf(
					/* translators: %s: list of weekday names and/or dates. */
					_n(
						'%s was marked open but had no complete opening hours, so it was saved as closed.',
						'%s were marked open but had no complete opening hours, so they were saved as closed.',
						count( $auto_closed ),
						'itboffins-live-opening-hours'
					),
					implode( ', ', $auto_closed )
				),
				'warning'
			);
		}

		if ( $range_invalid > 0 && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				IBOH_OPTION,
				'iboh_ranges_invalid',
				sprintf(
					/* translators: %d: number of time ranges that were dropped. */
					_n(
						'%d time range was not saved because its opening or closing time was missing or invalid.',
						'%d time ranges were not saved because their opening or closing times were missing or invalid.',
						$range_invalid,
						'itboffins-live-opening-hours'
					),
					$range_invalid
				),
				'warning'
			);
		}

		if ( ! empty( $duplicate_dates ) && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				IBOH_OPTION,
				'iboh_duplicate_dates',
				sprintf(
					/* translators: %s: list of dates in YYYY-MM-DD format. */
					_n(
						'%s was listed more than once. Only one entry per date is kept: a bank holiday wins, otherwise the last entry.',
						'%s were listed more than once. Only one entry per date is kept: a bank holiday wins, otherwise the last entry.',
						count( $duplicate_dates ),
						'itboffins-live-opening-hours'
					),
					implode( ', ', array_keys( $duplicate_dates ) )
				),
				'warning'
			);
		}

		if ( $discarded > 0 && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				IBOH_OPTION,
				'iboh_holidays_discarded',
				sprintf(
					/* translators: %d: number of special dates that were discarded. */
					_n(
						'%d special date was not saved because its date was missing or invalid.',
						'%d special dates were not saved because their dates were missing or invalid.',
						$discarded,
						'itboffins-live-opening-hours'
					),
					$discarded
				),
				'error'
			);
		}

		if ( $range_truncated > 0 && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				IBOH_OPTION,
				'iboh_ranges_truncated',
				sprintf(
					/* translators: 1: number of time ranges not saved, 2: maximum ranges allowed per day/date. */
					_n(
						'%1$d time range was not saved because each day or special date is limited to %2$d ranges.',
						'%1$d time ranges were not saved because each day or special date is limited to %2$d ranges.',
						$range_truncated,
						'itboffins-live-opening-hours'
					),
					$range_truncated,
					self::MAX_RANGES
				),
				'error'
			);
		}

		if ( $holiday_truncated > 0 && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				IBOH_OPTION,
				'iboh_holidays_truncated',
				sprintf(
					/* translators: 1: number of special dates not saved, 2: maximum stored special dates. */
					_n(
						'%1$d special date was not saved because the limit is %2$d dates.',
						'%1$d special dates were not saved because the limit is %2$d dates.',
						$holiday_truncated,
						'itboffins-live-opening-hours'
					),
					$holiday_truncated,
					self::MAX_HOLIDAYS
				),
				'error'
			);
		}

		// --- Banner ---------------------------------------------------------
		$banner                  = isset( $input['banner'] ) && is_array( $input['banner'] ) ? $input['banner'] : array();
		$out['banner']           = array();
		$out['banner']['enabled']     = empty( $banner['enabled'] ) ? 0 : 1;
		$out['banner']['dismissible'] = empty( $banner['dismissible'] ) ? 0 : 1;
		$out['banner']['show_next']   = empty( $banner['show_next'] ) ? 0 : 1;
		$out['banner']['position']    = ( isset( $banner['position'] ) && 'bottom' === $banner['position'] ) ? 'bottom' : 'top';
		$out['banner']['sticky']      = empty( $banner['sticky'] ) ? 0 : 1;
		$out['banner']['push_top']    = empty( $banner['push_top'] ) ? 0 : 1;
		$out['banner']['soon_mins']   = isset( $banner['soon_mins'] ) && is_scalar( $banner['soon_mins'] ) ? max( 0, min( 720, absint( $banner['soon_mins'] ) ) ) : $defaults['banner']['soon_mins'];

		foreach ( array( 'colour_open', 'colour_closed', 'colour_text' ) as $ck ) {
			$colour            = isset( $banner[ $ck ] ) && is_scalar( $banner[ $ck ] ) ? sanitize_hex_color( (string) $banner[ $ck ] ) : '';
			$out['banner'][ $ck ] = $colour ? $colour : $defaults['banner'][ $ck ];
		}

		// --- Labels ---------------------------------------------------------
		$labels        = isset( $input['labels'] ) && is_array( $input['labels'] ) ? $input['labels'] : array();
		$out['labels'] = array();
		foreach ( $defaults['labels'] as $key => $default_label ) {
			$value = isset( $labels[ $key ] ) && is_scalar( $labels[ $key ] ) ? sanitize_text_field( (string) $labels[ $key ] ) : '';
			if ( '' !== $value && $value !== $default_label ) {
				$out['labels'][ $key ] = $value;
			}
		}
		$out[ self::LABEL_OVERRIDE_MARKER ] = 1;

		return $out;
	}

	/**
	 * Remove special dates that are now in the past, optionally retaining the
	 * previous date when requested by the live evaluator.
	 *
	 * @param array $holidays         Holiday rows keyed by YYYY-MM-DD.
	 * @param bool  $include_yesterday Retain yesterday's complete override.
	 * @return array
	 */
	private static function current_holidays( $holidays, $include_yesterday = false ) {
		if ( ! is_array( $holidays ) ) {
			return array();
		}

		$today     = self::today_date();
		$yesterday = self::shift_date( $today, -1 );
		$cutoff    = $include_yesterday ? $yesterday : $today;
		$out       = array();
		$count     = 0;
		$dates     = array_keys( $holidays );
		sort( $dates, SORT_STRING );
		foreach ( $dates as $date ) {
			$holiday = $holidays[ $date ];
			if ( ! is_string( $date ) || null === self::valid_date( $date ) || $date < $cutoff || ! is_array( $holiday ) ) {
				continue;
			}
			if ( $date >= $today ) {
				if ( $count >= self::MAX_HOLIDAYS ) {
					break;
				}
				$count++;
			}
			$normalized = self::normalize_holiday( $holiday );
			$out[ $date ] = $normalized;
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Today's date in the schedule timezone.
	 *
	 * @param DateTimeZone|null $timezone Zone to use (default: the effective
	 *                                    schedule timezone).
	 * @return string YYYY-MM-DD.
	 */
	private static function today_date( $timezone = null ) {
		return wp_date( 'Y-m-d', null, $timezone instanceof DateTimeZone ? $timezone : IBOH_Timezone::timezone() );
	}

	/**
	 * Check whether a submitted special-date row contains user-entered data.
	 *
	 * @param mixed $row Raw special-date row.
	 * @return bool
	 */
	private static function holiday_row_has_content( $row ) {
		if ( ! is_array( $row ) ) {
			return false;
		}

		foreach ( array( 'date', 'label', 'closed' ) as $key ) {
			if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return true;
			}
		}

		return ! empty( $row['ranges'] ) && is_array( $row['ranges'] );
	}

	/**
	 * Sanitise a list of {open, close} ranges.
	 *
	 * Drops malformed or zero-length ranges, caps the count, and re-indexes.
	 *
	 * @param mixed    $ranges    Raw ranges array.
	 * @param int|null $truncated Optional running count of valid ranges omitted
	 *                            because a day/date exceeded MAX_RANGES.
	 * @param int|null $invalid   Optional running count of ranges dropped
	 *                            because a time was missing or malformed (a
	 *                            fully blank row is not counted).
	 * @return array
	 */
	private static function sanitize_ranges( $ranges, &$truncated = null, &$invalid = null ) {
		if ( ! is_array( $ranges ) ) {
			return array();
		}
		$out = array();
		foreach ( $ranges as $range ) {
			if ( ! is_array( $range ) ) {
				continue;
			}
			$raw_open  = isset( $range['open'] ) && is_scalar( $range['open'] ) ? trim( (string) $range['open'] ) : '';
			$raw_close = isset( $range['close'] ) && is_scalar( $range['close'] ) ? trim( (string) $range['close'] ) : '';
			$open      = self::valid_time( $raw_open );
			$close     = self::valid_time( $raw_close );
			if ( null === $open || null === $close || '24:00' === $open ) {
				if ( null !== $invalid && ( '' !== $raw_open || '' !== $raw_close ) ) {
					$invalid++;
				}
				continue;
			}
			// Reject zero-length same-day ranges (open === close), except the
			// explicit 00:00 -> 24:00 "open 24 hours" case.
			if ( $open === $close && ! ( '00:00' === $open && '24:00' === $close ) ) {
				if ( null !== $invalid ) {
					$invalid++;
				}
				continue;
			}
			if ( count( $out ) >= self::MAX_RANGES ) {
				if ( null !== $truncated ) {
					$truncated++;
				}
				continue;
			}
			$out[] = array(
				'open'  => $open,
				'close' => $close,
			);
		}
		return array_values( $out );
	}

	/**
	 * Normalize one stored special-date row defensively.
	 *
	 * @param array $holiday Stored row.
	 * @return array
	 */
	private static function normalize_holiday( $holiday ) {
		$closed = empty( $holiday['closed'] ) ? 0 : 1;
		$ranges = self::sanitize_ranges( isset( $holiday['ranges'] ) ? $holiday['ranges'] : array() );
		if ( ! $closed && empty( $ranges ) ) {
			$closed = 1;
		}

		$out = array(
			'closed' => $closed,
			'ranges' => $ranges,
			'label'  => isset( $holiday['label'] ) && is_scalar( $holiday['label'] ) ? sanitize_text_field( (string) $holiday['label'] ) : '',
		);
		if ( isset( $holiday['source'] ) && 'bank_holiday' === $holiday['source'] ) {
			$out['source'] = 'bank_holiday';
		}

		return $out;
	}

	/**
	 * Shift an ISO date without involving the site's daylight-saving offset.
	 *
	 * @param string $date ISO date.
	 * @param int    $days Signed day offset.
	 * @return string
	 */
	private static function shift_date( $date, $days ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
		if ( ! $dt ) {
			return $date;
		}
		return $dt->modify( (int) $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Normalize a stored colour with a known-safe fallback.
	 *
	 * @param mixed  $value    Stored value.
	 * @param string $fallback Default colour.
	 * @return string
	 */
	private static function normalized_colour( $value, $fallback ) {
		$colour = is_scalar( $value ) ? sanitize_hex_color( (string) $value ) : '';
		return $colour ? $colour : $fallback;
	}

	/**
	 * Canonical source-language defaults used to migrate options saved by older
	 * versions, which persisted translated defaults as if they were overrides.
	 *
	 * @return array
	 */
	private static function source_labels() {
		return array(
			'open'                 => "We're open",
			'closed'               => "Sorry, we're closed",
			'closes_at'            => 'Closes at %s',
			'opens_today'          => 'Opens today at %s',
			'opens_on'             => 'Opens %1$s at %2$s',
			'opening_soon'         => 'Opening soon',
			'closing_soon'         => 'Closing soon',
			'open_24h'             => 'Open 24 hours',
			'upcoming_heading'     => 'Upcoming dates',
			'upcoming_closed'      => 'Closed %s',
			'upcoming_closed_list' => '%s we are closed',
			'upcoming_hours'       => '%1$s: %2$s',
		);
	}

	/**
	 * Infer which saved labels coherently match one legacy locale's defaults.
	 *
	 * A union of all installed translations would risk deleting a genuine custom
	 * cross-locale label. Instead, require at least two matches covering at least
	 * half of the stored labels, select the best-scoring locale map, and retain
	 * only matches shared by equally strong candidates.
	 *
	 * @param array $saved_labels Unmarked stored labels.
	 * @return array<string,bool> Keys safe to remove as legacy defaults.
	 */
	private static function legacy_default_keys( $saved_labels ) {
		if ( ! is_array( $saved_labels ) ) {
			return array();
		}

		$stored = array();
		foreach ( self::source_labels() as $key => $unused ) {
			if ( isset( $saved_labels[ $key ] ) && is_scalar( $saved_labels[ $key ] ) ) {
				$value = sanitize_text_field( (string) $saved_labels[ $key ] );
				if ( '' !== $value ) {
					$stored[ $key ] = $value;
				}
			}
		}
		$total = count( $stored );
		if ( $total < 2 ) {
			return array();
		}

		$best_score = 0;
		$best_sets  = array();
		foreach ( self::legacy_default_label_sets() as $candidate ) {
			$matches = array();
			foreach ( $stored as $key => $value ) {
				if ( isset( $candidate[ $key ] ) && $candidate[ $key ] === $value ) {
					$matches[ $key ] = true;
				}
			}
			$score = count( $matches );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best_sets  = array( $matches );
			} elseif ( $score === $best_score && $score > 0 ) {
				$best_sets[] = $matches;
			}
		}

		if ( $best_score < 2 || ( $best_score * 2 ) < $total || empty( $best_sets ) ) {
			return array();
		}

		$safe = array_shift( $best_sets );
		foreach ( $best_sets as $matches ) {
			$safe = array_intersect_key( $safe, $matches );
		}
		return $safe;
	}

	/**
	 * Collect distinct source/current/installed-locale default label maps.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function legacy_default_label_sets() {
		if ( null !== self::$legacy_label_defaults ) {
			return self::$legacy_label_defaults;
		}

		$candidates = array(
			self::source_labels(),
			self::defaults()['labels'],
		);

		if ( function_exists( 'get_available_languages' ) && function_exists( 'switch_to_locale' ) && function_exists( 'restore_previous_locale' ) ) {
			$locales = get_available_languages();
			$locales = is_array( $locales ) ? array_unique( array_filter( $locales, 'is_string' ) ) : array();
			foreach ( $locales as $locale ) {
				if ( '' === $locale || ! switch_to_locale( $locale ) ) {
					continue;
				}
				$candidates[] = self::defaults()['labels'];
				restore_previous_locale();
			}
		}

		$unique = array();
		foreach ( $candidates as $candidate ) {
			$fingerprint = md5( serialize( $candidate ) );
			$unique[ $fingerprint ] = $candidate;
		}
		self::$legacy_label_defaults = array_values( $unique );
		return self::$legacy_label_defaults;
	}

	/**
	 * Validate an 'HH:MM' 24-hour time string (allows 24:00 as end-of-day).
	 *
	 * @param mixed $value Raw value.
	 * @return string|null Normalised 'HH:MM' or null if invalid.
	 */
	private static function valid_time( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '/^([01]\d|2[0-4]):([0-5]\d)$/', $value, $m ) ) {
			if ( '24' === $m[1] && '00' !== $m[2] ) {
				return null; // 24:30 etc. is not valid.
			}
			return $value;
		}
		return null;
	}

	/**
	 * Validate a 'YYYY-MM-DD' date via a strict round-trip.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null Canonical date or null if invalid.
	 */
	private static function valid_date( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$dt    = DateTime::createFromFormat( '!Y-m-d', $value );
		if ( $dt && $dt->format( 'Y-m-d' ) === $value ) {
			return $value;
		}
		return null;
	}
}
