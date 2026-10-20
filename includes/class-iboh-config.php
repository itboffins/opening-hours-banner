<?php
/**
 * Builds the canonical configuration consumed identically by the PHP fallback
 * evaluator and the client-side JavaScript evaluator.
 *
 * IMPORTANT: this payload contains only the schedule *definition* (which changes
 * only when the admin saves settings) — never a computed open/closed status.
 * The live status is always derived in the browser, which is what keeps the
 * banner correct under full-page caching.
 *
 * @package ITBoffins_Live_Opening_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical front-end config provider.
 */
class IBOH_Config {

	/**
	 * Build the config array.
	 *
	 * @return array
	 */
	public static function data() {
		// Keep yesterday's special date in the public evaluator payload long
		// enough for a valid overnight range to finish after midnight.
		$settings    = IBOH_Settings::all( true );
		$time_format = get_option( 'time_format', 'H:i' );
		$time_format = is_scalar( $time_format ) ? (string) $time_format : 'H:i';
		$blog_id     = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		$home        = function_exists( 'home_url' ) ? home_url( '/' ) : '';

		return array(
			'tz'         => IBOH_Timezone::iana(),
			'offset'     => IBOH_Timezone::offset_minutes(),
			// Upcoming [ epochSeconds, offsetMinutes ] pairs: lets a browser
			// without working Intl time-zone support follow daylight saving
			// even when this payload was cached before a transition.
			'offsets'    => IBOH_Timezone::offset_table(),
			'timeFormat' => $time_format,
			'meridiem'   => self::meridiem_names(),
			'list'       => self::list_patterns(),
			'formats'    => self::formats(),
			'lang'       => self::browser_locale(),
			'siteKey'    => (string) $blog_id . '|' . (string) $home,
			'dateNames'  => self::short_date_names(),
			'dayNames'   => self::day_names(),
			'schedule'   => $settings['schedule'],
			'holidays'   => $settings['holidays'],
			'options'    => $settings['options'],
			'closedText' => __( 'Closed', 'itboffins-live-opening-hours' ),
			'labels'     => $settings['labels'],
			'banner'     => $settings['banner'],
		);
	}

	/**
	 * Localised full weekday names, index 0..6 = Sunday..Saturday.
	 *
	 * @return array
	 */
	public static function day_names() {
		$names = array();
		if ( isset( $GLOBALS['wp_locale'] ) && is_object( $GLOBALS['wp_locale'] ) ) {
			for ( $i = 0; $i < 7; $i++ ) {
				$names[ $i ] = $GLOBALS['wp_locale']->get_weekday( $i );
			}
			return $names;
		}
		return array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
	}

	/**
	 * Localised meridiem strings used by the JavaScript PHP-date formatter.
	 *
	 * @return array
	 */
	private static function meridiem_names() {
		$names = array();
		foreach ( array( 'am', 'pm', 'AM', 'PM' ) as $meridiem ) {
			if ( isset( $GLOBALS['wp_locale'] ) && is_object( $GLOBALS['wp_locale'] ) ) {
				$names[ $meridiem ] = $GLOBALS['wp_locale']->get_meridiem( $meridiem );
			} else {
				$names[ $meridiem ] = $meridiem;
			}
		}
		return $names;
	}

	/**
	 * Localised list-joining patterns shared by PHP and JavaScript.
	 *
	 * @return array
	 */
	private static function list_patterns() {
		return array(
			'separator' => _x( ', ', 'separator between list items', 'itboffins-live-opening-hours' ),
			/* translators: 1: First list item, 2: Second list item. */
			'pair'      => _x( '%1$s and %2$s', 'two-item list', 'itboffins-live-opening-hours' ),
			/* translators: 1: Leading list items, 2: Final list item. */
			'final'     => _x( '%1$s, and %2$s', 'last two items in a list of three or more', 'itboffins-live-opening-hours' ),
		);
	}

	/**
	 * Translatable joining patterns for customer-facing text, shared by the PHP
	 * evaluator, the shortcode/table renderer, and the browser evaluator.
	 *
	 * @return array<string,string>
	 */
	public static function formats() {
		return array(
			/* translators: 1: opening time, 2: closing time, e.g. "09:00 – 17:00". */
			'range'    => _x( '%1$s – %2$s', 'opening time range', 'itboffins-live-opening-hours' ),
			/* translators: separator between several time ranges on one day. */
			'ranges'   => _x( ', ', 'separator between time ranges', 'itboffins-live-opening-hours' ),
			/* translators: 1: short date, 2: time, e.g. "Tue 4 Aug, 02:00". */
			'dateTime' => _x( '%1$s, %2$s', 'date followed by a time', 'itboffins-live-opening-hours' ),
			/* translators: 1: short date, 2: public note, e.g. "Thu 25 Dec (Christmas Day)". */
			'dateNote' => _x( '%1$s (%2$s)', 'date with a note', 'itboffins-live-opening-hours' ),
			/* translators: separator between upcoming-date notices in plain text. */
			'items'    => _x( ' · ', 'separator between upcoming-date notices', 'itboffins-live-opening-hours' ),
		);
	}

	/**
	 * One translatable joining pattern, with a source-language fallback.
	 *
	 * @param string $key Pattern key from formats().
	 * @return string
	 */
	public static function format( $key ) {
		$formats  = self::formats();
		$fallback = array(
			'range'    => '%1$s – %2$s',
			'ranges'   => ', ',
			'dateTime' => '%1$s, %2$s',
			'dateNote' => '%1$s (%2$s)',
			'items'    => ' · ',
		);
		if ( isset( $formats[ $key ] ) && is_string( $formats[ $key ] ) && '' !== $formats[ $key ] ) {
			return $formats[ $key ];
		}
		return isset( $fallback[ $key ] ) ? $fallback[ $key ] : '%1$s';
	}

	/**
	 * Convert a WordPress locale to a browser-safe BCP 47 language tag.
	 *
	 * WordPress locale suffixes are not always valid BCP 47 variants. For
	 * example, pt_PT_ao90 must become pt-PT because an alphabetic four-character
	 * variant is invalid and makes Intl.DateTimeFormat throw a RangeError.
	 *
	 * @return string
	 */
	public static function browser_locale() {
		$locale = get_locale();
		$parts  = is_string( $locale ) ? preg_split( '/[_-]+/', trim( $locale ) ) : array();
		if ( empty( $parts ) || ! preg_match( '/^[A-Za-z]{2,8}$/', $parts[0] ) ) {
			return 'en-US';
		}

		$out   = array( strtolower( $parts[0] ) );
		$index = 1;
		if ( isset( $parts[ $index ] ) && preg_match( '/^[A-Za-z]{4}$/', $parts[ $index ] ) ) {
			$out[] = ucfirst( strtolower( $parts[ $index ] ) );
			$index++;
		}
		if ( isset( $parts[ $index ] ) && ( preg_match( '/^[A-Za-z]{2}$/', $parts[ $index ] ) || preg_match( '/^[0-9]{3}$/', $parts[ $index ] ) ) ) {
			$out[] = strtoupper( $parts[ $index ] );
			$index++;
		}
		for ( ; isset( $parts[ $index ] ); $index++ ) {
			$variant = $parts[ $index ];
			if ( ! preg_match( '/^(?:[A-Za-z0-9]{5,8}|[0-9][A-Za-z0-9]{3})$/', $variant ) ) {
				break;
			}
			$out[] = strtolower( $variant );
		}

		return implode( '-', $out );
	}

	/**
	 * Exact localized components used by wp_date( 'D j M' ).
	 *
	 * Supplying these to JavaScript avoids CLDR/WordPress translation drift and
	 * prevents a locale's default non-Gregorian calendar changing the date.
	 *
	 * @return array{weekdays:array<int,string>,months:array<int,string>}
	 */
	public static function short_date_names() {
		$utc      = new DateTimeZone( 'UTC' );
		$sunday   = new DateTimeImmutable( '2023-01-01 12:00:00', $utc );
		$weekdays = array();
		$months   = array();

		for ( $index = 0; $index < 7; $index++ ) {
			$day        = $sunday->modify( '+' . $index . ' days' );
			$weekdays[] = wp_date( 'D', $day->getTimestamp(), $utc );
		}
		for ( $month = 1; $month <= 12; $month++ ) {
			$date     = new DateTimeImmutable( sprintf( '2023-%02d-01 12:00:00', $month ), $utc );
			$months[] = wp_date( 'M', $date->getTimestamp(), $utc );
		}

		return array(
			'weekdays' => $weekdays,
			'months'   => $months,
		);
	}
}
