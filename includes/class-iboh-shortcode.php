<?php
/**
 * [iboh_opening_hours] shortcode and the shared table/status renderers used by
 * both the shortcode and the block.
 *
 * @package ITBoffins_Live_Opening_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode + reusable front-end renderers.
 */
class IBOH_Shortcode {

	/**
	 * Register the shortcode.
	 */
	public function init() {
		add_shortcode( 'iboh_opening_hours', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the shortcode / block output.
	 *
	 * @param array $atts Shortcode attributes. Supported: show = table|status|both.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'show' => 'table',
			),
			$atts,
			'iboh_opening_hours'
		);

		$show = in_array( $atts['show'], array( 'table', 'status', 'both' ), true ) ? $atts['show'] : 'table';

		// Ensure assets load even when the banner is disabled.
		IBOH_Frontend::enqueue();

		$out = '<div class="iboh-widget">';
		if ( 'status' === $show || 'both' === $show ) {
			$out .= self::status_html();
		}
		if ( 'table' === $show || 'both' === $show ) {
			$out .= self::table_html();
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * Inline status element (filled live by JavaScript, server-rendered fallback).
	 *
	 * @return string
	 */
	public static function status_html() {
		$state           = IBOH_Evaluator::state();
		$class           = $state['open'] ? 'iboh-open' : 'iboh-closed';
		$style           = empty( $state['show'] ) ? ' style="display:none;"' : '';
		$upcoming_markup = ( isset( $state['upcoming_html'] ) && '' !== $state['upcoming_html'] )
			? $state['upcoming_html']
			: esc_html( $state['upcoming'] );

		$html  = '<p class="iboh-status ' . esc_attr( $class ) . '" data-iboh-status role="status" aria-live="polite" aria-atomic="true"' . $style . '>';
		$html .= '<span class="iboh-dot" aria-hidden="true"></span>';
		$html .= '<span class="iboh-status-main" data-iboh-main>' . esc_html( $state['main'] ) . '</span>';
		$html .= ' <span class="iboh-status-sub" data-iboh-sub>' . esc_html( $state['sub'] ) . '</span>';
		$html .= ' <span class="iboh-status-upcoming" data-iboh-upcoming>' . wp_kses_post( $upcoming_markup ) . '</span>';
		$html .= '</p>';

		return $html;
	}

	/**
	 * Weekly hours table. Static (cache-safe); JavaScript only adds the
	 * "today" highlight, which would otherwise go stale in a cached page.
	 *
	 * @return string
	 */
	public static function table_html() {
		$schedule = IBOH_Settings::get( 'schedule' );
		$labels   = IBOH_Settings::get( 'labels' );
		$options  = IBOH_Settings::get( 'options' );
		$days     = IBOH_Config::day_names();

		$weekly        = ! isset( $options['weekly_enabled'] ) || ! empty( $options['weekly_enabled'] );
		$show_upcoming = ! empty( $options['show_upcoming'] );

		$html = '';

		if ( $weekly ) {
			// Display order Monday..Sunday (store/key order is 0..6 = Sun..Sat).
			$order          = array( 1, 2, 3, 4, 5, 6, 0 );
			$html .= '<table class="iboh-hours" data-iboh-table><tbody>';
			foreach ( $order as $dow ) {
				$day    = isset( $schedule[ $dow ] ) ? $schedule[ $dow ] : array( 'closed' => 1, 'ranges' => array() );
				$name   = isset( $days[ $dow ] ) ? $days[ $dow ] : '';
				$hours  = self::format_day_hours( $day, $labels );

				$html .= '<tr data-iboh-dow="' . esc_attr( $dow ) . '">';
				$html .= '<th scope="row" class="iboh-day">' . esc_html( $name ) . '</th>';
				$html .= '<td class="iboh-times">' . esc_html( $hours ) . '</td>';
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';
		}

		if ( $show_upcoming ) {
			$html .= self::upcoming_html();
		}

		return $html;
	}

	/**
	 * "Upcoming dates" list: every special date from today onward.
	 *
	 * Rendered statically (cache-safe). Each row carries its date so the
	 * front-end script can drop any entry that has since passed and hide the
	 * whole list once empty.
	 *
	 * @return string
	 */
	public static function upcoming_html() {
		$holidays = IBOH_Settings::get( 'holidays' );
		$labels   = IBOH_Settings::get( 'labels' );
		$options  = IBOH_Settings::get( 'options' );

		if ( ! is_array( $holidays ) ) {
			$holidays = array();
		}
		ksort( $holidays );
		$now         = IBOH_Timezone::now();
		$today       = $now->format( 'Y-m-d' );
		$bank_cutoff = $now->modify( '+7 days' )->format( 'Y-m-d' );
		$bank_mode   = isset( $options['bank_holiday_mode'] ) ? (string) $options['bank_holiday_mode'] : 'seven_days';
		if ( ! in_array( $bank_mode, array( 'seven_days', 'all', 'none' ), true ) ) {
			$bank_mode = 'seven_days';
		}

		$rows         = '';
		$visible_rows = false;
		foreach ( $holidays as $date => $h ) {
			if ( $date < $today ) {
				continue;
			}
			$closed     = ! empty( $h['closed'] );
			$label      = isset( $h['label'] ) ? $h['label'] : '';
			$source     = ( isset( $h['source'] ) && 'bank_holiday' === $h['source'] ) ? 'bank_holiday' : '';
			$hide_row   = ( 'bank_holiday' === $source && ( 'none' === $bank_mode || ( 'all' !== $bank_mode && $date > $bank_cutoff ) ) );
			$date_text  = self::fmt_date( $date );
			$date_label = ( '' !== $label ) ? $date_text . ' (' . $label . ')' : $date_text;
			$hours      = $closed
				? __( 'Closed', 'itboffins-live-opening-hours' )
				: self::format_day_hours( array( 'closed' => 0, 'ranges' => isset( $h['ranges'] ) ? $h['ranges'] : array() ), $labels );

			if ( ! $hide_row ) {
				$visible_rows = true;
			}

			$rows .= '<tr data-iboh-date="' . esc_attr( $date ) . '" data-iboh-source="' . esc_attr( $source ) . '"' . ( $hide_row ? ' style="display:none;"' : '' ) . '>';
			$rows .= '<th scope="row" class="iboh-day">' . esc_html( $date_label ) . '</th>';
			$rows .= '<td class="iboh-times">' . esc_html( $hours ) . '</td>';
			$rows .= '</tr>';
		}

		$heading = isset( $labels['upcoming_heading'] ) ? $labels['upcoming_heading'] : '';
		$hidden  = ( '' === $rows || ! $visible_rows ) ? ' style="display:none;"' : '';

		$html  = '<div class="iboh-upcoming" data-iboh-upcoming-list' . $hidden . '>';
		if ( '' !== $heading ) {
			$html .= '<h3 class="iboh-upcoming-heading">' . esc_html( $heading ) . '</h3>';
		}
		$html .= '<table class="iboh-hours iboh-upcoming-table"><tbody>' . $rows . '</tbody></table>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Human string for a day's ranges, e.g. "9:00 am – 5:00 pm" or "Closed".
	 *
	 * @param array $day    Day plan { closed, ranges }.
	 * @param array $labels Label strings (unused placeholders reserved).
	 * @return string
	 */
	private static function format_day_hours( $day, $labels ) {
		$ranges = self::normalize_ranges( isset( $day['ranges'] ) ? $day['ranges'] : array() );
		if ( ! empty( $day['closed'] ) || empty( $ranges ) ) {
			return __( 'Closed', 'itboffins-live-opening-hours' );
		}

		// Whole-day open shortcut.
		if ( 1 === count( $ranges ) && '00:00' === $ranges[0]['open'] && '24:00' === $ranges[0]['close'] ) {
			return isset( $labels['open_24h'] ) ? $labels['open_24h'] : __( 'Open 24 hours', 'itboffins-live-opening-hours' );
		}

		$parts = array();
		foreach ( $ranges as $r ) {
			$parts[] = self::fmt( $r['open'] ) . ' – ' . self::fmt( $r['close'] );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Defensively normalize ranges before static table rendering.
	 *
	 * @param mixed $ranges Raw ranges.
	 * @return array
	 */
	private static function normalize_ranges( $ranges ) {
		if ( ! is_array( $ranges ) ) {
			return array();
		}
		$out = array();
		foreach ( $ranges as $range ) {
			if ( ! is_array( $range ) ) {
				continue;
			}
			$open  = isset( $range['open'] ) && is_scalar( $range['open'] ) ? trim( (string) $range['open'] ) : '';
			$close = isset( $range['close'] ) && is_scalar( $range['close'] ) ? trim( (string) $range['close'] ) : '';
			if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $open ) ) {
				continue;
			}
			if ( '24:00' !== $close && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $close ) ) {
				continue;
			}
			if ( $open === $close ) {
				continue;
			}
			$out[] = array( 'open' => $open, 'close' => $close );
		}
		return $out;
	}

	/**
	 * Convert a strict wall-clock string to minutes after midnight.
	 *
	 * @param mixed $hhmm Time.
	 * @return int|null
	 */
	private static function to_minutes( $hhmm ) {
		$hhmm = is_scalar( $hhmm ) ? trim( (string) $hhmm ) : '';
		if ( '24:00' === $hhmm ) {
			return 1440;
		}
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $matches ) ) {
			return null;
		}
		return ( (int) $matches[1] * 60 ) + (int) $matches[2];
	}

	/**
	 * Format an 'HH:MM' string using the supported tokens in the site's time
	 * format. Tokens that require a specific instant/timezone remain literal.
	 *
	 * @param string $hhmm Time.
	 * @return string
	 */
	private static function fmt( $hhmm ) {
		if ( '24:00' === $hhmm ) {
			$hhmm = '23:59'; // Display only; treated as end-of-day elsewhere.
		}
		$minutes = self::to_minutes( $hhmm );
		$minutes = null === $minutes ? 0 : $minutes;
		$hour    = (int) floor( $minutes / 60 );
		$minute  = $minutes % 60;
		$hour_12 = $hour % 12 ? $hour % 12 : 12;
		$tokens  = array(
			'G' => (string) $hour,
			'H' => sprintf( '%02d', $hour ),
			'g' => (string) $hour_12,
			'h' => sprintf( '%02d', $hour_12 ),
			'i' => sprintf( '%02d', $minute ),
			's' => '00',
			'a' => self::meridiem( $hour < 12 ? 'am' : 'pm' ),
			'A' => self::meridiem( $hour < 12 ? 'AM' : 'PM' ),
		);
		$format  = get_option( 'time_format', 'H:i' );
		$format  = is_scalar( $format ) ? (string) $format : 'H:i';
		$output  = '';
		$escaped = false;
		$length  = strlen( $format );
		for ( $index = 0; $index < $length; $index++ ) {
			$character = $format[ $index ];
			if ( $escaped ) {
				$output .= $character;
				$escaped = false;
			} elseif ( '\\' === $character ) {
				$escaped = true;
			} else {
				$output .= array_key_exists( $character, $tokens ) ? $tokens[ $character ] : $character;
			}
		}
		if ( $escaped ) {
			$output .= '\\';
		}
		return $output;
	}

	/**
	 * Localized meridiem name with an English fallback.
	 *
	 * @param string $key am, pm, AM, or PM.
	 * @return string
	 */
	private static function meridiem( $key ) {
		if ( isset( $GLOBALS['wp_locale'] ) && is_object( $GLOBALS['wp_locale'] ) ) {
			$value = $GLOBALS['wp_locale']->get_meridiem( $key );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}
		return $key;
	}

	/**
	 * Format a 'YYYY-MM-DD' as a short, localised date, e.g. "Wed 25 Dec".
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	private static function fmt_date( $ymd ) {
		$utc = new DateTimeZone( 'UTC' );
		$dt  = is_string( $ymd ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, $utc ) : false;
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $ymd ) {
			return $ymd;
		}
		return wp_date( 'D j M', $dt->getTimestamp(), $utc );
	}
}
