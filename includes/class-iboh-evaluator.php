<?php
/**
 * Server-side open/closed evaluation.
 *
 * This mirrors the JavaScript evaluator (assets/frontend.js) and exists purely
 * to render a sensible initial / no-JavaScript status. The live status is always
 * recomputed in the browser, so a cached copy of this server-rendered markup is
 * still corrected on load. Never treat this output as the authoritative state.
 *
 * @package ITBoffins_Live_Opening_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure-ish evaluator over IBOH_Config::data().
 */
class IBOH_Evaluator {

	/**
	 * Compute the current status text.
	 *
	 * @param array|null $cfg Optional pre-built config (defaults to IBOH_Config::data()).
	 * @return array { show: bool, open: bool, main: string, sub: string, upcoming: string, upcoming_html: string }
	 */
	public static function state( $cfg = null ) {
		if ( null === $cfg ) {
			$cfg = IBOH_Config::data();
		}

		$now     = IBOH_Timezone::now();
		$ymd     = $now->format( 'Y-m-d' );
		$dow     = (int) $now->format( 'w' ); // 0..6, Sun..Sat.
		$minutes = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );

		$weekly        = self::weekly_enabled( $cfg );
		$show_upcoming = ! empty( $cfg['options']['show_upcoming'] ) || ! $weekly;

		$open     = false;
		$closes_at = null;

		$today          = self::day_plan( $cfg, $ymd, $dow );
		$today_defined  = ! empty( $today['defined'] );
		if ( empty( $today['closed'] ) ) {
			foreach ( $today['ranges'] as $r ) {
				$o = self::to_min( $r['open'] );
				$c = self::to_min( $r['close'] );
				if ( $c > $o ) {
					if ( $minutes >= $o && $minutes < $c ) {
						$open = true;
						if ( null === $closes_at || $c > $closes_at ) {
							$closes_at = $c;
						}
					}
				} elseif ( $minutes >= $o ) { // Crosses midnight.
					$open      = true;
					$closes_at = 1440;
				}
			}
		}

		// Tail of yesterday's overnight range.
		if ( ! $open ) {
			$yesterday = self::shift_ymd( $ymd, -1 );
			$yplan     = self::day_plan( $cfg, $yesterday['ymd'], $yesterday['dow'] );
			if ( empty( $yplan['closed'] ) ) {
				foreach ( $yplan['ranges'] as $r ) {
					$o = self::to_min( $r['open'] );
					$c = self::to_min( $r['close'] );
					if ( $c <= $o && $minutes < $c ) {
						$open = true;
						if ( null === $closes_at || $c > $closes_at ) {
							$closes_at = $c;
						}
					}
				}
			}
		}

		$next_open = $open ? null : self::find_next_open( $cfg, $ymd, $dow, $minutes );
		$upcoming  = $show_upcoming ? self::upcoming_events( $cfg, $ymd ) : array();

		return self::build_status( $cfg, $open, $closes_at, $next_open, $minutes, $today_defined, $upcoming );
	}

	/**
	 * Whether the weekly schedule is in use (default true for forward-compat).
	 *
	 * @param array $cfg Config.
	 * @return bool
	 */
	private static function weekly_enabled( $cfg ) {
		return ! isset( $cfg['options']['weekly_enabled'] ) || ! empty( $cfg['options']['weekly_enabled'] );
	}

	/**
	 * Special dates strictly after today, sorted by date.
	 *
	 * Past dates and today itself are excluded — today's own override already
	 * drives the live status, so the "upcoming" note always looks ahead.
	 *
	 * @param array  $cfg   Config.
	 * @param string $today Today's date (YYYY-MM-DD).
	 * @return array<int,array{date:string,closed:bool,ranges:array,label:string}>
	 */
	private static function upcoming_events( $cfg, $today ) {
		if ( empty( $cfg['holidays'] ) || ! is_array( $cfg['holidays'] ) ) {
			return array();
		}
		$events      = array();
		$dates       = array_keys( $cfg['holidays'] );
		$bank_cutoff = self::shift_ymd( $today, 7 )['ymd'];
		$bank_mode   = isset( $cfg['options']['bank_holiday_mode'] ) ? (string) $cfg['options']['bank_holiday_mode'] : 'seven_days';
		if ( ! in_array( $bank_mode, array( 'seven_days', 'all', 'none' ), true ) ) {
			$bank_mode = 'seven_days';
		}
		sort( $dates );
		foreach ( $dates as $date ) {
			if ( $date <= $today ) {
				continue;
			}
			$h      = $cfg['holidays'][ $date ];
			$source = isset( $h['source'] ) ? $h['source'] : '';
			if ( 'bank_holiday' === $source ) {
				if ( 'none' === $bank_mode ) {
					continue;
				}
				if ( 'all' !== $bank_mode && $date > $bank_cutoff ) {
					continue;
				}
			}
			$closed   = ! empty( $h['closed'] );
			$events[] = array(
				'date'   => $date,
				'closed' => $closed,
				'ranges' => $closed ? array() : ( isset( $h['ranges'] ) ? $h['ranges'] : array() ),
				'label'  => isset( $h['label'] ) ? $h['label'] : '',
				'source' => $source,
			);
		}
		return $events;
	}

	/**
	 * Find the next opening as ['dayOffset'=>int,'minute'=>int,'dow'=>int] or null.
	 *
	 * @param array  $cfg     Config.
	 * @param string $ymd     Today's date.
	 * @param int    $dow     Today's weekday.
	 * @param int    $minutes Minutes since midnight now.
	 * @return array|null
	 */
	private static function find_next_open( $cfg, $ymd, $dow, $minutes ) {
		$today = self::day_plan( $cfg, $ymd, $dow );
		if ( empty( $today['closed'] ) ) {
			$cands = array();
			foreach ( $today['ranges'] as $r ) {
				$o = self::to_min( $r['open'] );
				if ( $o > $minutes ) {
					$cands[] = $o;
				}
			}
			if ( $cands ) {
				sort( $cands );
				return array( 'dayOffset' => 0, 'minute' => $cands[0], 'dow' => $dow );
			}
		}
		for ( $i = 1; $i <= 7; $i++ ) {
			$d    = self::shift_ymd( $ymd, $i );
			$plan = self::day_plan( $cfg, $d['ymd'], $d['dow'] );
			if ( empty( $plan['closed'] ) && ! empty( $plan['ranges'] ) ) {
				$opens = array();
				foreach ( $plan['ranges'] as $r ) {
					$opens[] = self::to_min( $r['open'] );
				}
				sort( $opens );
				return array( 'dayOffset' => $i, 'minute' => $opens[0], 'dow' => $d['dow'] );
			}
		}
		return null;
	}

	/**
	 * Compose the human-readable status from the computed state.
	 *
	 * @param array      $cfg           Config.
	 * @param bool       $open          Open now?
	 * @param int|null   $closes_at     Minute today the current range ends.
	 * @param array|null $next_open     Next opening descriptor.
	 * @param int        $minutes       Minutes since midnight now.
	 * @param bool       $today_defined Whether today has a defined status (weekly on, or a special date).
	 * @param array      $upcoming      Upcoming special dates.
	 * @return array { show, open, main, sub, upcoming, upcoming_html }
	 */
	private static function build_status( $cfg, $open, $closes_at, $next_open, $minutes, $today_defined, $upcoming ) {
		$labels   = $cfg['labels'];
		$soon     = (int) $cfg['banner']['soon_mins'];
		$show     = ! empty( $cfg['banner']['show_next'] );
		$up_text  = ! empty( $upcoming ) ? self::format_events( $cfg, $upcoming ) : '';
		$up_html  = ! empty( $upcoming ) ? self::format_events_html( $cfg, $upcoming ) : '';

		if ( $open ) {
			$main = $labels['open'];
			$sub  = '';
			if ( null !== $closes_at ) {
				if ( $show ) {
					$sub = self::format_label( $labels['closes_at'], array( self::fmt_time( $closes_at ) ) );
				}
				if ( $soon > 0 && ( $closes_at - $minutes ) <= $soon ) {
					$main = $labels['closing_soon'];
				}
			}
			return array( 'show' => true, 'open' => true, 'main' => $main, 'sub' => $sub, 'upcoming' => $up_text, 'upcoming_html' => $up_html );
		}

		// Closed, but today has a defined status (regular weekly day, or a
		// special date that closes today).
		if ( $today_defined ) {
			$main = $labels['closed'];
			$sub  = '';
			if ( $next_open ) {
				$time = self::fmt_time( $next_open['minute'] );
				if ( $show ) {
					if ( 0 === $next_open['dayOffset'] ) {
						$sub = self::format_label( $labels['opens_today'], array( $time ) );
					} else {
						$sub = self::format_label( $labels['opens_on'], array( self::day_name( $cfg, $next_open['dow'] ), $time ) );
					}
				}
				if ( $soon > 0 && 0 === $next_open['dayOffset'] && ( $next_open['minute'] - $minutes ) <= $soon ) {
					$main = $labels['opening_soon'];
				}
			}
			return array( 'show' => true, 'open' => false, 'main' => $main, 'sub' => $sub, 'upcoming' => $up_text, 'upcoming_html' => $up_html );
		}

		// Weekly hours are off and today has no special date. If there is an
		// upcoming date to announce, the banner becomes that announcement;
		// otherwise there is nothing to show, so hide it entirely.
		if ( ! empty( $upcoming ) ) {
			$first         = reset( $upcoming );
			$announce_open = 1 === count( $upcoming ) && empty( $first['closed'] ) && ! empty( $first['ranges'] );
			return array( 'show' => true, 'open' => $announce_open, 'main' => $up_text, 'sub' => '', 'upcoming' => '', 'upcoming_html' => '' );
		}

		return array( 'show' => false, 'open' => false, 'main' => '', 'sub' => '', 'upcoming' => '', 'upcoming_html' => '' );
	}

	/**
	 * Human one-line summary for multiple upcoming special dates.
	 *
	 * @param array $cfg    Config.
	 * @param array $events Upcoming events.
	 * @return string
	 */
	private static function format_events( $cfg, $events ) {
		if ( empty( $events ) ) {
			return '';
		}

		$parts        = array();
		$closed_dates = array();
		foreach ( $events as $event ) {
			if ( ! empty( $event['closed'] ) || empty( $event['ranges'] ) ) {
				$closed_dates[] = self::event_date_label( $event );
				continue;
			}

			if ( $closed_dates ) {
				$parts[]      = self::format_closed_dates( $cfg, $closed_dates );
				$closed_dates = array();
			}
			$parts[] = self::format_event( $cfg, $event );
		}

		if ( $closed_dates ) {
			$parts[] = self::format_closed_dates( $cfg, $closed_dates );
		}

		return implode( ' · ', array_filter( $parts ) );
	}

	/**
	 * Structured markup for upcoming dates: bold date, normal-weight detail.
	 *
	 * @param array $cfg    Config.
	 * @param array $events Upcoming events.
	 * @return string
	 */
	private static function format_events_html( $cfg, $events ) {
		if ( empty( $events ) ) {
			return '';
		}

		$items = array();
		foreach ( $events as $event ) {
			$items[] = self::format_event_html( $cfg, $event );
		}

		return implode( '', array_filter( $items ) );
	}

	/**
	 * Mark up one upcoming date.
	 *
	 * @param array $cfg Config.
	 * @param array $ev  Event.
	 * @return string
	 */
	private static function format_event_html( $cfg, $ev ) {
		$parts = self::event_parts( $cfg, $ev );
		return '<span class="iboh-upcoming-item"><strong class="iboh-upcoming-date">' . esc_html( $parts['date'] ) . '</strong><span class="iboh-upcoming-detail">' . esc_html( $parts['detail'] ) . '</span></span>';
	}

	/**
	 * Split an event into display date and display detail.
	 *
	 * @param array $cfg Config.
	 * @param array $ev  Event.
	 * @return array{date:string,detail:string}
	 */
	private static function event_parts( $cfg, $ev ) {
		$detail = ( ! empty( $ev['closed'] ) || empty( $ev['ranges'] ) )
			? __( 'Closed', 'itboffins-live-opening-hours' )
			: self::fmt_ranges( $cfg, $ev['ranges'] );

		return array(
			'date'   => self::event_date_label( $ev ),
			'detail' => $detail,
		);
	}

	/**
	 * Format a special date plus optional label.
	 *
	 * @param array $event Event { date, label }.
	 * @return string
	 */
	private static function event_date_label( $event ) {
		$date_text = self::fmt_date( $event['date'] );
		$label     = isset( $event['label'] ) ? $event['label'] : '';
		return ( '' !== $label ) ? $date_text . ' (' . $label . ')' : $date_text;
	}

	/**
	 * Format one or more closed dates.
	 *
	 * @param array $cfg          Config.
	 * @param array $closed_dates Formatted date labels.
	 * @return string
	 */
	private static function format_closed_dates( $cfg, $closed_dates ) {
		$labels = $cfg['labels'];
		if ( 1 === count( $closed_dates ) ) {
			return self::format_label( $labels['upcoming_closed'], array( $closed_dates[0] ) );
		}

		$template = isset( $labels['upcoming_closed_list'] ) ? $labels['upcoming_closed_list'] : '%s we are closed';
		return self::format_label( $template, array( self::human_list( $closed_dates ) ) );
	}

	/**
	 * Join date labels with a final "and".
	 *
	 * @param array $items Labels.
	 * @return string
	 */
	private static function human_list( $items ) {
		$count = count( $items );
		if ( $count <= 1 ) {
			return isset( $items[0] ) ? $items[0] : '';
		}
		if ( 2 === $count ) {
			return $items[0] . ' and ' . $items[1];
		}

		$last = array_pop( $items );
		return implode( ', ', $items ) . ', and ' . $last;
	}

	/**
	 * Human one-line note for an upcoming special date.
	 *
	 * @param array $cfg Config.
	 * @param array $ev  Event { date, closed, ranges, label }.
	 * @return string
	 */
	private static function format_event( $cfg, $ev ) {
		$labels     = $cfg['labels'];
		$date_text  = self::fmt_date( $ev['date'] );
		$label      = isset( $ev['label'] ) ? $ev['label'] : '';
		$date_label = ( '' !== $label ) ? $date_text . ' (' . $label . ')' : $date_text;

		if ( ! empty( $ev['closed'] ) || empty( $ev['ranges'] ) ) {
			return self::format_label( $labels['upcoming_closed'], array( $date_label ) );
		}
		return self::format_label( $labels['upcoming_hours'], array( $date_label, self::fmt_ranges( $cfg, $ev['ranges'] ) ) );
	}

	/**
	 * Format a list of {open, close} ranges as display text (mirrors the table).
	 *
	 * @param array $cfg    Config.
	 * @param array $ranges Ranges.
	 * @return string
	 */
	private static function fmt_ranges( $cfg, $ranges ) {
		if ( empty( $ranges ) ) {
			return '';
		}
		if ( 1 === count( $ranges ) && '00:00' === $ranges[0]['open'] && '24:00' === $ranges[0]['close'] ) {
			return $cfg['labels']['open_24h'];
		}
		$parts = array();
		foreach ( $ranges as $r ) {
			$close   = ( '24:00' === $r['close'] ) ? 1439 : self::to_min( $r['close'] );
			$parts[] = self::fmt_time( self::to_min( $r['open'] ) ) . ' – ' . self::fmt_time( $close );
		}
		return implode( ', ', $parts );
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

	/**
	 * Resolve the effective plan for a date (holiday override wins).
	 *
	 * The 'defined' flag distinguishes a real status from "no status at all":
	 * when the weekly schedule is disabled, a day with no special date is not
	 * "closed" — it simply has nothing to say, so the banner can hide.
	 *
	 * @param array  $cfg Config.
	 * @param string $ymd Date.
	 * @param int    $dow Weekday.
	 * @return array { closed: bool, ranges: array, defined: bool }
	 */
	private static function day_plan( $cfg, $ymd, $dow ) {
		if ( isset( $cfg['holidays'][ $ymd ] ) ) {
			$h = $cfg['holidays'][ $ymd ];
			return array(
				'closed'  => ! empty( $h['closed'] ),
				'ranges'  => empty( $h['closed'] ) && isset( $h['ranges'] ) ? $h['ranges'] : array(),
				'defined' => true,
			);
		}
		if ( ! self::weekly_enabled( $cfg ) ) {
			return array( 'closed' => true, 'ranges' => array(), 'defined' => false );
		}
		$d = isset( $cfg['schedule'][ $dow ] ) ? $cfg['schedule'][ $dow ] : array( 'closed' => 1, 'ranges' => array() );
		return array(
			'closed'  => ! empty( $d['closed'] ),
			'ranges'  => isset( $d['ranges'] ) ? $d['ranges'] : array(),
			'defined' => true,
		);
	}

	/**
	 * Shift a 'YYYY-MM-DD' by a number of days, returning date + weekday.
	 *
	 * @param string $ymd   Date.
	 * @param int    $delta Days to add (may be negative).
	 * @return array { ymd: string, dow: int }
	 */
	private static function shift_ymd( $ymd, $delta ) {
		$dt = new DateTimeImmutable( $ymd . ' 00:00:00', new DateTimeZone( 'UTC' ) );
		$dt = $dt->modify( ( $delta >= 0 ? '+' : '' ) . $delta . ' days' );
		return array(
			'ymd' => $dt->format( 'Y-m-d' ),
			'dow' => (int) $dt->format( 'w' ),
		);
	}

	/**
	 * Minutes since midnight for an 'HH:MM' string.
	 *
	 * @param string $hhmm Time.
	 * @return int
	 */
	private static function to_min( $hhmm ) {
		$parts = explode( ':', (string) $hhmm );
		return ( (int) $parts[0] ) * 60 + ( isset( $parts[1] ) ? (int) $parts[1] : 0 );
	}

	/**
	 * Format a minute-of-day using the site's time format.
	 *
	 * @param int $minutes Minutes since midnight (0..1440).
	 * @return string
	 */
	private static function fmt_time( $minutes ) {
		$minutes = ( ( $minutes % 1440 ) + 1440 ) % 1440;
		$base    = new DateTimeImmutable( IBOH_Timezone::now()->format( 'Y-m-d' ) . ' 00:00:00', wp_timezone() );
		$ts      = $base->getTimestamp() + $minutes * 60;
		return wp_date( (string) get_option( 'time_format', 'H:i' ), $ts );
	}

	/**
	 * Replace supported label placeholders without PHP sprintf fatal/warning risk.
	 *
	 * Admin-editable labels may contain stray percent signs or unsupported
	 * placeholders. Only %s and positional %1$s-style string placeholders are
	 * replaced; everything else remains literal text.
	 *
	 * @param string $template Label template.
	 * @param array  $args     Replacement values.
	 * @return string
	 */
	private static function format_label( $template, $args ) {
		$template = (string) $template;
		$args     = array_values( (array) $args );
		$index    = 0;
		$result   = preg_replace_callback(
			'/%(\\d+)\\$s|%s/',
			function ( $matches ) use ( $args, &$index ) {
				if ( ! empty( $matches[1] ) ) {
					$position = (int) $matches[1] - 1;
					return isset( $args[ $position ] ) ? (string) $args[ $position ] : '';
				}

				$value = isset( $args[ $index ] ) ? (string) $args[ $index ] : '';
				$index++;
				return $value;
			},
			$template
		);

		if ( ! is_string( $result ) ) {
			return $template;
		}

		return str_replace( '%%', '%', $result );
	}
	/**
	 * Localised weekday name for an index.
	 *
	 * @param array $cfg Config.
	 * @param int   $dow Weekday index 0..6.
	 * @return string
	 */
	private static function day_name( $cfg, $dow ) {
		return isset( $cfg['dayNames'][ $dow ] ) ? $cfg['dayNames'][ $dow ] : '';
	}
}
