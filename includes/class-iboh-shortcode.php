<?php
/**
 * [opening_hours] shortcode and the shared table/status renderers used by both
 * the shortcode and the block.
 *
 * @package Opening_Hours_Banner
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
		add_shortcode( 'opening_hours', array( __CLASS__, 'render' ) );
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
			'opening_hours'
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
		$state = IBOH_Evaluator::state();
		$class = $state['open'] ? 'iboh-open' : 'iboh-closed';
		$style = empty( $state['show'] ) ? ' style="display:none;"' : '';

		$html  = '<p class="iboh-status ' . esc_attr( $class ) . '" data-iboh-status' . $style . '>';
		$html .= '<span class="iboh-dot" aria-hidden="true"></span>';
		$html .= '<span class="iboh-status-main" data-iboh-main>' . esc_html( $state['main'] ) . '</span>';
		$html .= ' <span class="iboh-status-sub" data-iboh-sub>' . esc_html( $state['sub'] ) . '</span>';
		$html .= ' <span class="iboh-status-upcoming" data-iboh-upcoming>' . esc_html( $state['upcoming'] ) . '</span>';
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
		$show_upcoming = ! empty( $options['show_upcoming'] ) || ! $weekly;

		$html = '';

		if ( $weekly ) {
			// Display order Monday..Sunday (store/key order is 0..6 = Sun..Sat).
			$order = array( 1, 2, 3, 4, 5, 6, 0 );

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

		if ( ! is_array( $holidays ) ) {
			$holidays = array();
		}
		ksort( $holidays );
		$today = IBOH_Timezone::now()->format( 'Y-m-d' );

		$rows = '';
		foreach ( $holidays as $date => $h ) {
			if ( $date < $today ) {
				continue;
			}
			$closed     = ! empty( $h['closed'] );
			$label      = isset( $h['label'] ) ? $h['label'] : '';
			$date_text  = self::fmt_date( $date );
			$date_label = ( '' !== $label ) ? $date_text . ' (' . $label . ')' : $date_text;
			$hours      = $closed
				? __( 'Closed', 'opening-hours-banner' )
				: self::format_day_hours( array( 'closed' => 0, 'ranges' => isset( $h['ranges'] ) ? $h['ranges'] : array() ), $labels );

			$rows .= '<tr data-iboh-date="' . esc_attr( $date ) . '">';
			$rows .= '<th scope="row" class="iboh-day">' . esc_html( $date_label ) . '</th>';
			$rows .= '<td class="iboh-times">' . esc_html( $hours ) . '</td>';
			$rows .= '</tr>';
		}

		$heading = isset( $labels['upcoming_heading'] ) ? $labels['upcoming_heading'] : '';
		$hidden  = ( '' === $rows ) ? ' style="display:none;"' : '';

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
		if ( ! empty( $day['closed'] ) || empty( $day['ranges'] ) ) {
			return __( 'Closed', 'opening-hours-banner' );
		}

		// Whole-day open shortcut.
		if ( 1 === count( $day['ranges'] ) && '00:00' === $day['ranges'][0]['open'] && '24:00' === $day['ranges'][0]['close'] ) {
			return isset( $labels['open_24h'] ) ? $labels['open_24h'] : __( 'Open 24 hours', 'opening-hours-banner' );
		}

		$parts = array();
		foreach ( $day['ranges'] as $r ) {
			$parts[] = self::fmt( $r['open'] ) . ' – ' . self::fmt( $r['close'] );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Format an 'HH:MM' string using the site's time format.
	 *
	 * @param string $hhmm Time.
	 * @return string
	 */
	private static function fmt( $hhmm ) {
		if ( '24:00' === $hhmm ) {
			$hhmm = '23:59'; // Display only; treated as end-of-day elsewhere.
		}
		$parts   = explode( ':', $hhmm );
		$minutes = (int) $parts[0] * 60 + ( isset( $parts[1] ) ? (int) $parts[1] : 0 );
		$base    = new DateTimeImmutable( IBOH_Timezone::now()->format( 'Y-m-d' ) . ' 00:00:00', wp_timezone() );
		return wp_date( (string) get_option( 'time_format', 'H:i' ), $base->getTimestamp() + $minutes * 60 );
	}

	/**
	 * Format a 'YYYY-MM-DD' as a short, localised date, e.g. "Wed 25 Dec".
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	private static function fmt_date( $ymd ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
		if ( ! $dt ) {
			return $ymd;
		}
		return wp_date( 'D j M', $dt->getTimestamp() );
	}
}
