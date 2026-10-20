<?php
/**
 * Timezone helpers — the single source of truth for "what time is it for this
 * business" used by the PHP fallback, the JavaScript evaluator, and the admin
 * preview.
 *
 * The plugin normally follows the WordPress site timezone (Settings > General).
 * Since 1.2.0 the schedule can also carry its own timezone, for sites whose
 * WordPress timezone is a fixed UTC offset (which can never follow daylight
 * saving) or whose business is in a different place from the site.
 *
 * @package ITBoffins_Live_Opening_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the schedule timezone in the forms the plugin needs.
 */
class IBOH_Timezone {

	/**
	 * Request-local resolved zones, keyed by setting + site timezone string.
	 *
	 * @var array<string,DateTimeZone>
	 */
	private static $cache = array();

	/**
	 * Request-local list of valid IANA identifiers.
	 *
	 * @var array<string,bool>|null
	 */
	private static $identifiers = null;

	/**
	 * Validate a stored/submitted timezone setting.
	 *
	 * Accepts an IANA identifier (e.g. "America/Anchorage") or a WordPress-style
	 * manual offset (e.g. "UTC-9", "UTC+5.5") as produced by wp_timezone_choice().
	 *
	 * @param mixed $value Raw value.
	 * @return string Canonical setting, or '' for "follow the site timezone".
	 */
	public static function sanitize_setting( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( self::is_identifier( $value ) ) {
			return $value;
		}
		if ( null !== self::manual_offset_minutes( $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Resolve a setting value into a DateTimeZone, falling back to the site zone.
	 *
	 * @param mixed $value Raw or sanitized setting.
	 * @return DateTimeZone
	 */
	public static function from_setting( $value ) {
		$value = self::sanitize_setting( $value );
		if ( '' === $value ) {
			return wp_timezone();
		}
		if ( self::is_identifier( $value ) ) {
			return new DateTimeZone( $value );
		}
		$minutes = self::manual_offset_minutes( $value );
		return new DateTimeZone( self::offset_string( null === $minutes ? 0 : $minutes ) );
	}

	/**
	 * The saved timezone setting ('' = follow the site).
	 *
	 * Reads the raw option directly so IBOH_Settings::all() can depend on this
	 * class without a circular call.
	 *
	 * @return string
	 */
	public static function setting() {
		$saved = get_option( IBOH_OPTION, array() );
		$raw   = ( is_array( $saved ) && isset( $saved['options'] ) && is_array( $saved['options'] ) && isset( $saved['options']['timezone'] ) )
			? $saved['options']['timezone']
			: '';
		return self::sanitize_setting( $raw );
	}

	/**
	 * The effective schedule timezone.
	 *
	 * @return DateTimeZone
	 */
	public static function timezone() {
		$setting = self::setting();
		$key     = $setting . '|' . wp_timezone_string();
		if ( ! isset( self::$cache[ $key ] ) ) {
			self::$cache[ $key ] = self::from_setting( $setting );
		}
		return self::$cache[ $key ];
	}

	/**
	 * The site's own WordPress timezone (ignoring the plugin setting).
	 *
	 * @return DateTimeZone
	 */
	public static function site_timezone() {
		return wp_timezone();
	}

	/**
	 * IANA timezone name (e.g. "Europe/London"), or '' for a fixed UTC offset.
	 *
	 * When an IANA name is available the JS evaluator uses Intl.DateTimeFormat,
	 * which is DST-correct. The empty-string case falls back to a fixed offset,
	 * which is correct precisely because such zones do not model DST.
	 *
	 * @param DateTimeZone|null $timezone Zone to describe (default: effective).
	 * @return string
	 */
	public static function iana( $timezone = null ) {
		$name = self::resolve( $timezone )->getName();
		if ( '' === $name || preg_match( '/^[+-]\d{2}:\d{2}$/', $name ) ) {
			return '';
		}
		return $name;
	}

	/**
	 * Whether the zone is a fixed UTC offset (never applies daylight saving).
	 *
	 * @param DateTimeZone|null $timezone Zone (default: effective).
	 * @return bool
	 */
	public static function is_fixed_offset( $timezone = null ) {
		return '' === self::iana( $timezone );
	}

	/**
	 * Current UTC offset in minutes (DST-aware for the present instant). Used
	 * by the browser only as a fallback when iana() is empty or Intl fails.
	 *
	 * @param DateTimeZone|null $timezone Zone (default: effective).
	 * @return int
	 */
	public static function offset_minutes( $timezone = null ) {
		$timezone = self::resolve( $timezone );
		$now      = new DateTimeImmutable( 'now', $timezone );
		return (int) ( $now->getOffset() / 60 );
	}

	/**
	 * Upcoming offset changes as [ epochSeconds, offsetMinutes ] pairs, so a
	 * browser without working Intl time-zone support can still follow daylight
	 * saving on a page that was cached before a transition.
	 *
	 * @param DateTimeZone|null $timezone Zone (default: effective).
	 * @return array<int,array{0:int,1:int}>
	 */
	public static function offset_table( $timezone = null ) {
		$timezone = self::resolve( $timezone );
		$now      = time();
		$current  = (int) ( $timezone->getOffset( new DateTimeImmutable( '@' . $now ) ) / 60 );
		$table    = array( array( 0, $current ) );

		$changes = $timezone->getTransitions( $now, $now + ( 400 * DAY_IN_SECONDS ) );
		if ( is_array( $changes ) ) {
			foreach ( $changes as $change ) {
				if ( ! isset( $change['ts'], $change['offset'] ) || (int) $change['ts'] <= $now ) {
					continue;
				}
				$table[] = array( (int) $change['ts'], (int) ( (int) $change['offset'] / 60 ) );
			}
		}
		return $table;
	}

	/**
	 * "Now" as a DateTimeImmutable in the schedule timezone.
	 *
	 * @param DateTimeZone|null $timezone Zone (default: effective).
	 * @return DateTimeImmutable
	 */
	public static function now( $timezone = null ) {
		return new DateTimeImmutable( 'now', self::resolve( $timezone ) );
	}

	/**
	 * Human label for a zone: "Europe/London", "UTC-9", "UTC+5:30".
	 *
	 * @param DateTimeZone|null $timezone Zone (default: effective).
	 * @return string
	 */
	public static function label( $timezone = null ) {
		$timezone = self::resolve( $timezone );
		$iana     = self::iana( $timezone );
		if ( '' !== $iana ) {
			return $iana;
		}
		$minutes = (int) ( $timezone->getOffset( new DateTimeImmutable( 'now', $timezone ) ) / 60 );
		$sign    = $minutes < 0 ? '-' : '+';
		$abs     = abs( $minutes );
		$label   = 'UTC' . $sign . (string) floor( $abs / 60 );
		if ( $abs % 60 ) {
			$label .= ':' . sprintf( '%02d', $abs % 60 );
		}
		return $label;
	}

	/**
	 * Parse a WordPress-style manual offset ("UTC-9", "UTC+5.75") into minutes.
	 *
	 * @param string $value Setting value.
	 * @return int|null Minutes east of UTC, or null when not a manual offset.
	 */
	public static function manual_offset_minutes( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^UTC([+-])(\d{1,2})(?:\.(\d{1,2}))?$/', $value, $m ) ) {
			return null;
		}
		$hours    = (int) $m[2];
		$fraction = isset( $m[3] ) ? (float) ( '0.' . $m[3] ) : 0.0;
		$minutes  = (int) round( ( $hours + $fraction ) * 60 );
		if ( $minutes > 14 * 60 ) {
			return null;
		}
		return '-' === $m[1] ? -$minutes : $minutes;
	}

	/**
	 * Whether a value is a valid IANA identifier on this PHP build.
	 *
	 * Backward-compatible names (Europe/Kiev, America/Godthab) are accepted as
	 * WordPress core does, so a stored zone survives a tzdata rename.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_identifier( $value ) {
		if ( null === self::$identifiers ) {
			self::$identifiers = array_fill_keys( timezone_identifiers_list( DateTimeZone::ALL_WITH_BC ), true );
		}
		return is_string( $value ) && isset( self::$identifiers[ $value ] );
	}

	/**
	 * "+05:30" style string for a minute offset.
	 *
	 * @param int $minutes Minutes east of UTC.
	 * @return string
	 */
	private static function offset_string( $minutes ) {
		$minutes = (int) $minutes;
		$sign    = $minutes < 0 ? '-' : '+';
		$abs     = abs( $minutes );
		return sprintf( '%s%02d:%02d', $sign, floor( $abs / 60 ), $abs % 60 );
	}

	/**
	 * Default a null zone argument to the effective zone.
	 *
	 * @param DateTimeZone|null $timezone Zone or null.
	 * @return DateTimeZone
	 */
	private static function resolve( $timezone ) {
		return $timezone instanceof DateTimeZone ? $timezone : self::timezone();
	}
}
