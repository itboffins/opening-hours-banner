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
	 * @param DateTimeInterface|null $now Optional instant for deterministic evaluation.
	 * @return array { show: bool, open: bool, main: string, sub: string, upcoming: string, upcoming_html: string }
	 */
	public static function state( $cfg = null, $now = null ) {
		if ( null === $cfg ) {
			$cfg = IBOH_Config::data();
		}
		if ( ! is_array( $cfg ) ) {
			$cfg = array();
		}

		$now     = self::site_now( $now );
		$ymd     = $now->format( 'Y-m-d' );
		$dow     = (int) $now->format( 'w' ); // 0..6, Sun..Sat.
		$minutes = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );

		$today          = self::day_plan( $cfg, $ymd, $dow );
		$today_defined  = ! empty( $today['defined'] );
		$current        = self::current_open_interval( $cfg, $ymd, $dow, $minutes );
		$open           = null !== $current;
		$closes_at      = $open ? $current['end'] : null;
		$show_upcoming  = ! empty( $cfg['options']['show_upcoming'] );

		$next_open = $open ? null : self::find_next_open( $cfg, $ymd, $dow, $minutes );
		$upcoming  = $show_upcoming ? self::upcoming_events( $cfg, $ymd ) : array();

		return self::build_status( $cfg, $open, $closes_at, $next_open, $ymd, $minutes, $now, $today_defined, $upcoming );
	}

	/**
	 * Resolve an injected instant into the site's timezone.
	 *
	 * @param mixed $now Optional DateTimeInterface.
	 * @return DateTimeImmutable
	 */
	private static function site_now( $now ) {
		if ( $now instanceof DateTimeInterface ) {
			$resolved = new DateTimeImmutable( '@' . $now->getTimestamp() );
			return $resolved->setTimezone( IBOH_Timezone::timezone() );
		}
		return IBOH_Timezone::now();
	}

	/**
	 * Find the merged opening interval containing the current absolute minute.
	 *
	 * @param array  $cfg     Config.
	 * @param string $ymd     Current date.
	 * @param int    $dow     Current weekday.
	 * @param int    $minutes Current minute after midnight.
	 * @return array|null Interval with an absolute end, or null when closed.
	 */
	private static function current_open_interval( $cfg, $ymd, $dow, $minutes ) {
		$probe_days = self::interval_probe_days( $cfg );
		$intervals  = self::collect_intervals( $cfg, $ymd, $dow, $probe_days );
		$horizon    = $probe_days * 1440;
		foreach ( $intervals as $interval ) {
			if ( $interval['start'] <= $minutes && $minutes < $interval['end'] ) {
				// An interval that continues beyond a full repeated weekly cycle
				// has no useful known closing time in this time-only UI.
				if ( $interval['end'] > $horizon ) {
					$interval['end'] = null;
				}
				return $interval;
			}
		}
		return null;
	}

	/**
	 * Build and merge absolute-minute intervals from yesterday through a future
	 * horizon. A special date clips any earlier overnight tail at midnight.
	 *
	 * @param array  $cfg      Config.
	 * @param string $ymd      Anchor date.
	 * @param int    $dow      Anchor weekday.
	 * @param int    $max_days Last future day offset to include.
	 * @return array<int,array{start:int,end:int}>
	 */
	private static function collect_intervals( $cfg, $ymd, $dow, $max_days ) {
		$intervals = array();
		$max_days  = max( 0, (int) $max_days );
		for ( $offset = -1; $offset <= $max_days; $offset++ ) {
			$date = self::shift_ymd( $ymd, $offset );
			$plan = self::day_plan( $cfg, $date['ymd'], $date['dow'] );
			$base = $offset * 1440;

			if ( ! empty( $plan['special'] ) ) {
				foreach ( $intervals as $index => $interval ) {
					if ( $interval['start'] < $base && $interval['end'] > $base ) {
						$intervals[ $index ]['end'] = $base;
					}
				}
			}

			$intervals = array_merge( $intervals, self::plan_intervals( $plan, $offset ) );
		}

		return self::merge_intervals( $intervals );
	}

	/**
	 * Convert one day's normalized ranges to absolute-minute intervals.
	 *
	 * @param array $plan   Day plan.
	 * @param int   $offset Day offset from the anchor date.
	 * @return array<int,array{start:int,end:int}>
	 */
	private static function plan_intervals( $plan, $offset ) {
		if ( ! empty( $plan['closed'] ) || empty( $plan['ranges'] ) ) {
			return array();
		}

		$base = (int) $offset * 1440;
		$out  = array();
		foreach ( $plan['ranges'] as $range ) {
			$open  = self::to_min( isset( $range['open'] ) ? $range['open'] : '' );
			$close = self::to_min( isset( $range['close'] ) ? $range['close'] : '' );
			if ( null === $open || null === $close || $open >= 1440 || $open === $close ) {
				continue;
			}
			$out[] = array(
				'start' => $base + $open,
				'end'   => $base + ( $close > $open ? $close : 1440 + $close ),
			);
		}
		return $out;
	}

	/**
	 * Merge overlapping and touching intervals.
	 *
	 * @param array $intervals Intervals.
	 * @return array
	 */
	private static function merge_intervals( $intervals ) {
		$intervals = array_values(
			array_filter(
				$intervals,
				function ( $interval ) {
					return is_array( $interval ) && isset( $interval['start'], $interval['end'] ) && $interval['end'] > $interval['start'];
				}
			)
		);
		usort(
			$intervals,
			function ( $a, $b ) {
				if ( $a['start'] === $b['start'] ) {
					return $a['end'] <=> $b['end'];
				}
				return $a['start'] <=> $b['start'];
			}
		);

		$merged = array();
		foreach ( $intervals as $interval ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $interval['start'] <= $merged[ $last ]['end'] ) {
				$merged[ $last ]['end'] = max( $merged[ $last ]['end'], $interval['end'] );
			} else {
				$merged[] = $interval;
			}
		}
		return $merged;
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
		$bank_mode   = isset( $cfg['options']['bank_holiday_mode'] ) && is_scalar( $cfg['options']['bank_holiday_mode'] ) ? (string) $cfg['options']['bank_holiday_mode'] : 'seven_days';
		if ( ! in_array( $bank_mode, array( 'seven_days', 'all', 'none' ), true ) ) {
			$bank_mode = 'seven_days';
		}
		sort( $dates );
		foreach ( $dates as $date ) {
			if ( ! is_string( $date ) || $date <= $today || null === self::date_day_offset( $today, $date ) ) {
				continue;
			}
			$h      = is_array( $cfg['holidays'][ $date ] ) ? $cfg['holidays'][ $date ] : array();
			$source = isset( $h['source'] ) && is_scalar( $h['source'] ) ? (string) $h['source'] : '';
			if ( 'bank_holiday' === $source ) {
				if ( 'none' === $bank_mode ) {
					continue;
				}
				if ( 'all' !== $bank_mode && $date > $bank_cutoff ) {
					continue;
				}
			}
			$ranges   = empty( $h['closed'] ) ? self::normalize_ranges( isset( $h['ranges'] ) ? $h['ranges'] : array() ) : array();
			$closed   = ! empty( $h['closed'] ) || empty( $ranges );
			$events[] = array(
				'date'   => $date,
				'closed' => $closed,
				'ranges' => $closed ? array() : $ranges,
				'label'  => isset( $h['label'] ) && is_scalar( $h['label'] ) ? (string) $h['label'] : '',
				'source' => $source,
			);
		}
		return $events;
	}

	/**
	 * Find the next actual closed-to-open transition. The weekly scan expands
	 * for every possible override, while distant special openings are considered
	 * directly so special-only schedules are not limited to seven days.
	 *
	 * @param array  $cfg     Config.
	 * @param string $ymd     Today's date.
	 * @param int    $dow     Today's weekday.
	 * @param int    $minutes Minutes since midnight now.
	 * @return array|null
	 */
	private static function find_next_open( $cfg, $ymd, $dow, $minutes ) {
		$scan_days = self::interval_probe_days( $cfg );
		$candidate = null;

		foreach ( self::collect_intervals( $cfg, $ymd, $dow, $scan_days ) as $interval ) {
			if ( $interval['start'] > $minutes ) {
				$candidate = self::opening_candidate( $cfg, $ymd, $interval['start'] );
				break;
			}
		}

		$special = self::next_special_opening( $cfg, $ymd, $minutes );
		if ( null === $candidate || ( null !== $special && $special['absolute'] < $candidate['absolute'] ) ) {
			$candidate = $special;
		}

		return $candidate;
	}

	/**
	 * Bounded interval probe. Each date override can mask one weekly candidate;
	 * seven days per override covers even a once-weekly opening pattern, while
	 * two additional weeks expose whether a merged interval is continuous.
	 *
	 * @param array $cfg Config.
	 * @return int Days to inspect.
	 */
	private static function interval_probe_days( $cfg ) {
		$holiday_count = isset( $cfg['holidays'] ) && is_array( $cfg['holidays'] )
			? min( IBOH_Settings::MAX_HOLIDAYS, count( $cfg['holidays'] ) )
			: 0;
		return 7 * ( $holiday_count + 2 );
	}

	/**
	 * Earliest valid opening row on any current/future special date.
	 *
	 * @param array  $cfg     Config.
	 * @param string $ymd     Current date.
	 * @param int    $minutes Current minute.
	 * @return array|null
	 */
	private static function next_special_opening( $cfg, $ymd, $minutes ) {
		if ( empty( $cfg['holidays'] ) || ! is_array( $cfg['holidays'] ) ) {
			return null;
		}

		$absolute = null;
		foreach ( $cfg['holidays'] as $date => $holiday ) {
			$offset = self::date_day_offset( $ymd, $date );
			if ( null === $offset || $offset < 0 || ! is_array( $holiday ) || ! empty( $holiday['closed'] ) ) {
				continue;
			}
			foreach ( self::normalize_ranges( isset( $holiday['ranges'] ) ? $holiday['ranges'] : array() ) as $range ) {
				$start = $offset * 1440 + self::to_min( $range['open'] );
				if ( $start > $minutes && ( null === $absolute || $start < $absolute ) ) {
					$absolute = $start;
				}
			}
		}

		return null === $absolute ? null : self::opening_candidate( $cfg, $ymd, $absolute );
	}

	/**
	 * Convert an absolute minute into the legacy next-opening descriptor.
	 *
	 * @param array  $cfg      Config.
	 * @param string $ymd      Anchor date.
	 * @param int    $absolute Absolute minute from anchor midnight.
	 * @return array
	 */
	private static function opening_candidate( $cfg, $ymd, $absolute ) {
		$day_offset = (int) floor( $absolute / 1440 );
		$minute     = $absolute - ( $day_offset * 1440 );
		$date       = self::shift_ymd( $ymd, $day_offset );
		$plan       = self::day_plan( $cfg, $date['ymd'], $date['dow'] );
		return array(
			'dayOffset' => $day_offset,
			'minute'    => $minute,
			'dow'       => $date['dow'],
			'date'      => $date['ymd'],
			'special'   => ! empty( $plan['special'] ),
			'absolute'  => $absolute,
		);
	}

	/**
	 * Compose the human-readable status from the computed state.
	 *
	 * @param array      $cfg           Config.
	 * @param bool       $open          Open now?
	 * @param int|null   $closes_at     Absolute wall minute at which the current range ends.
	 * @param array|null $next_open     Next opening descriptor.
	 * @param string     $ymd           Current site date.
	 * @param int        $minutes       Minutes since midnight now.
	 * @param DateTimeInterface $now    Current instant in the site timezone.
	 * @param bool       $today_defined Whether today has a defined status (weekly on, or a special date).
	 * @param array      $upcoming      Upcoming special dates.
	 * @return array { show, open, main, sub, upcoming, upcoming_html }
	 */
	private static function build_status( $cfg, $open, $closes_at, $next_open, $ymd, $minutes, $now, $today_defined, $upcoming ) {
		$labels   = self::labels( $cfg );
		$defaults = IBOH_Settings::defaults();
		$soon     = isset( $cfg['banner']['soon_mins'] ) && is_scalar( $cfg['banner']['soon_mins'] ) ? max( 0, (int) $cfg['banner']['soon_mins'] ) : (int) $defaults['banner']['soon_mins'];
		$show     = isset( $cfg['banner']['show_next'] ) ? ! empty( $cfg['banner']['show_next'] ) : ! empty( $defaults['banner']['show_next'] );
		$up_text  = ! empty( $upcoming ) ? self::format_events( $cfg, $upcoming ) : '';
		$up_html  = ! empty( $upcoming ) ? self::format_events_html( $cfg, $upcoming ) : '';

		if ( $open ) {
			$main = $labels['open'];
			$sub  = '';
			if ( null !== $closes_at ) {
				if ( $show ) {
					$sub = self::format_label( $labels['closes_at'], array( self::transition_label( $cfg, $ymd, $closes_at, $minutes ) ) );
				}
				$until_close = self::transition_delta_minutes( $ymd, $closes_at, $now, $minutes );
				if ( $soon > 0 && $until_close > 0 && $until_close <= $soon ) {
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
						$opening_day = ! empty( $next_open['special'] ) || $next_open['dayOffset'] >= 7
							? self::fmt_date( isset( $next_open['date'] ) ? $next_open['date'] : self::shift_ymd( $ymd, $next_open['dayOffset'] )['ymd'] )
							: self::day_name( $cfg, $next_open['dow'] );
						$sub = self::format_label( $labels['opens_on'], array( $opening_day, $time ) );
					}
				}
				$next_absolute = isset( $next_open['absolute'] )
					? $next_open['absolute']
					: ( $next_open['dayOffset'] * 1440 + $next_open['minute'] );
				$until_open = self::transition_delta_minutes( $ymd, $next_absolute, $now, $minutes );
				if ( $soon > 0 && $until_open > 0 && $until_open <= $soon ) {
					$main = $labels['opening_soon'];
				}
			}
			return array( 'show' => true, 'open' => false, 'main' => $main, 'sub' => $sub, 'upcoming' => $up_text, 'upcoming_html' => $up_html );
		}

		// Weekly hours are off and today has no special date. If there is an
		// upcoming date to announce, the banner becomes that announcement;
		// otherwise there is nothing to show, so hide it entirely.
		if ( ! empty( $upcoming ) ) {
			return array( 'show' => true, 'open' => false, 'main' => $up_text, 'sub' => '', 'upcoming' => '', 'upcoming_html' => '' );
		}

		return array( 'show' => false, 'open' => false, 'main' => '', 'sub' => '', 'upcoming' => '', 'upcoming_html' => '' );
	}

	/**
	 * Format a transition time, including its date when it is after today.
	 *
	 * A close at exactly midnight tonight (absolute minute 1440) reads as
	 * "today" during the afternoon and evening, so it is shown as a bare time
	 * then. Earlier in the day a bare "00:00" could be read as already past,
	 * so the dated form is kept.
	 *
	 * @param array  $cfg      Config.
	 * @param string $ymd      Anchor date.
	 * @param int    $absolute Absolute wall minute from anchor midnight.
	 * @param int    $current  Current wall minute after midnight.
	 * @return string
	 */
	private static function transition_label( $cfg, $ymd, $absolute, $current = 0 ) {
		$day_offset = (int) floor( $absolute / 1440 );
		$minute     = $absolute - ( $day_offset * 1440 );
		$time       = self::fmt_time( $minute );
		if ( $day_offset <= 0 || ( 1 === $day_offset && 0 === $minute && (int) $current >= 720 ) ) {
			return $time;
		}
		$date = self::shift_ymd( $ymd, $day_offset );
		return self::format_label( self::fmt_pattern( $cfg, 'dateTime' ), array( self::fmt_date( $date['ymd'] ), $time ) );
	}

	/**
	 * Resolve a translatable joining pattern from the config, with a fallback.
	 *
	 * @param array  $cfg Config.
	 * @param string $key Pattern key (range, ranges, dateTime, dateNote, items).
	 * @return string
	 */
	private static function fmt_pattern( $cfg, $key ) {
		if ( isset( $cfg['formats'][ $key ] ) && is_scalar( $cfg['formats'][ $key ] ) && '' !== (string) $cfg['formats'][ $key ] ) {
			return (string) $cfg['formats'][ $key ];
		}
		return IBOH_Config::format( $key );
	}

	/**
	 * Real elapsed minutes to a future wall-clock transition.
	 *
	 * Absolute schedule minutes are ideal for ordering, but subtracting them is
	 * wrong across a daylight-saving gap or fold. Resolve the transition in the
	 * site's timezone and compare timestamps, falling back to wall minutes only
	 * if the wall date cannot be constructed.
	 *
	 * @param string            $ymd            Anchor date.
	 * @param int               $absolute       Absolute wall minute.
	 * @param DateTimeInterface $now            Current instant.
	 * @param int               $current_minute Current wall minute.
	 * @return float|int
	 */
	private static function transition_delta_minutes( $ymd, $absolute, $now, $current_minute ) {
		$fallback   = $absolute - $current_minute;
		if ( ! ( $now instanceof DateTimeInterface ) ) {
			return $fallback;
		}
		$day_offset = (int) floor( $absolute / 1440 );
		$minute     = $absolute - ( $day_offset * 1440 );
		$date       = self::shift_ymd( $ymd, $day_offset );
		$wall       = sprintf( '%s %02d:%02d', $date['ymd'], (int) floor( $minute / 60 ), $minute % 60 );
		$utc        = new DateTimeZone( 'UTC' );
		$naive      = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $wall, $utc );
		if ( ! $naive ) {
			return $fallback;
		}

		$timezone   = IBOH_Timezone::timezone();
		$naive_time = $naive->getTimestamp();
		$offsets    = array( $timezone->getOffset( $naive ) );
		$changes    = $timezone->getTransitions( $naive_time - ( 2 * DAY_IN_SECONDS ), $naive_time + ( 2 * DAY_IN_SECONDS ) );
		if ( is_array( $changes ) ) {
			foreach ( $changes as $change ) {
				if ( isset( $change['offset'] ) ) {
					$offsets[] = (int) $change['offset'];
				}
			}
		}

		$candidates = array();
		foreach ( array_unique( $offsets ) as $offset ) {
			$timestamp = $naive_time - (int) $offset;
			$resolved  = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone );
			if ( $resolved->format( 'Y-m-d H:i' ) === $wall ) {
				$candidates[] = $timestamp;
			}
		}
		sort( $candidates, SORT_NUMERIC );
		foreach ( $candidates as $timestamp ) {
			if ( $timestamp >= $now->getTimestamp() ) {
				return ( $timestamp - $now->getTimestamp() ) / 60;
			}
		}

		return $fallback;
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
				$closed_dates[] = self::event_date_label( $cfg, $event );
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

		return implode( self::fmt_pattern( $cfg, 'items' ), array_filter( $parts ) );
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

		$items        = array();
		$closed_dates = array();
		foreach ( $events as $event ) {
			if ( ! empty( $event['closed'] ) || empty( $event['ranges'] ) ) {
				$closed_dates[] = self::event_date_label( $cfg, $event );
				continue;
			}
			if ( $closed_dates ) {
				$items[]      = self::format_closed_dates_html( $cfg, $closed_dates );
				$closed_dates = array();
			}
			$items[] = self::format_event_html( $cfg, $event );
		}
		if ( $closed_dates ) {
			$items[] = self::format_closed_dates_html( $cfg, $closed_dates );
		}

		// Retain a real text boundary between flex items so assistive technology
		// does not concatenate the final word of one notice with the next.
		return implode( ' ', array_filter( $items ) );
	}

	/**
	 * Mark up one upcoming date.
	 *
	 * @param array $cfg Config.
	 * @param array $ev  Event.
	 * @return string
	 */
	private static function format_event_html( $cfg, $ev ) {
		$labels    = self::labels( $cfg );
		$date_html = '<strong class="iboh-upcoming-date">' . esc_html( self::event_date_label( $cfg, $ev ) ) . '</strong>';
		if ( ! empty( $ev['closed'] ) || empty( $ev['ranges'] ) ) {
			$content = self::format_label_html( $labels['upcoming_closed'], array( $date_html ) );
		} else {
			$hours_html = '<span class="iboh-upcoming-detail">' . esc_html( self::fmt_ranges( $cfg, $ev['ranges'] ) ) . '</span>';
			$content    = self::format_label_html( $labels['upcoming_hours'], array( $date_html, $hours_html ) );
		}
		return '<span class="iboh-upcoming-item">' . $content . '</span>';
	}

	/**
	 * Mark up one or more consecutive closed dates using the editable template.
	 *
	 * @param array $cfg          Config.
	 * @param array $closed_dates Formatted date labels.
	 * @return string
	 */
	private static function format_closed_dates_html( $cfg, $closed_dates ) {
		$labels = self::labels( $cfg );
		$dates  = array();
		foreach ( $closed_dates as $date ) {
			$dates[] = '<strong class="iboh-upcoming-date">' . esc_html( $date ) . '</strong>';
		}
		if ( 1 === count( $dates ) ) {
			$content = self::format_label_html( $labels['upcoming_closed'], array( $dates[0] ) );
		} else {
			$content = self::format_label_html( $labels['upcoming_closed_list'], array( self::human_list_html( $cfg, $dates ) ) );
		}
		return '<span class="iboh-upcoming-item">' . $content . '</span>';
	}

	/**
	 * Format a special date plus optional label.
	 *
	 * @param array $cfg   Config.
	 * @param array $event Event { date, label }.
	 * @return string
	 */
	private static function event_date_label( $cfg, $event ) {
		$date_text = self::fmt_date( isset( $event['date'] ) ? $event['date'] : '' );
		$label     = isset( $event['label'] ) && is_scalar( $event['label'] ) ? (string) $event['label'] : '';
		return ( '' !== $label )
			? self::format_label( self::fmt_pattern( $cfg, 'dateNote' ), array( $date_text, $label ) )
			: $date_text;
	}

	/**
	 * Format one or more closed dates.
	 *
	 * @param array $cfg          Config.
	 * @param array $closed_dates Formatted date labels.
	 * @return string
	 */
	private static function format_closed_dates( $cfg, $closed_dates ) {
		$labels = self::labels( $cfg );
		if ( 1 === count( $closed_dates ) ) {
			return self::format_label( $labels['upcoming_closed'], array( $closed_dates[0] ) );
		}

		$template = isset( $labels['upcoming_closed_list'] ) ? $labels['upcoming_closed_list'] : '%s we are closed';
		return self::format_label( $template, array( self::human_list( $cfg, $closed_dates ) ) );
	}

	/**
	 * Join date labels using localized list patterns supplied in the config.
	 *
	 * @param array $cfg   Config.
	 * @param array $items Labels.
	 * @return string
	 */
	private static function human_list( $cfg, $items ) {
		$items = array_values( $items );
		$count = count( $items );
		if ( $count <= 1 ) {
			return isset( $items[0] ) ? $items[0] : '';
		}
		if ( 2 === $count ) {
			return self::format_label( self::list_pattern( $cfg, 'pair' ), array( $items[0], $items[1] ) );
		}

		$last = array_pop( $items );
		$lead = implode( self::list_pattern( $cfg, 'separator' ), $items );
		return self::format_label( self::list_pattern( $cfg, 'final' ), array( $lead, $last ) );
	}

	/**
	 * HTML-safe equivalent of human_list(); list literals are escaped while the
	 * date fragments remain the only permitted markup.
	 *
	 * @param array $cfg   Config.
	 * @param array $items Safe HTML fragments.
	 * @return string
	 */
	private static function human_list_html( $cfg, $items ) {
		$items = array_values( $items );
		$count = count( $items );
		if ( $count <= 1 ) {
			return isset( $items[0] ) ? $items[0] : '';
		}
		if ( 2 === $count ) {
			return self::format_label_html( self::list_pattern( $cfg, 'pair' ), array( $items[0], $items[1] ) );
		}

		$last = array_pop( $items );
		$lead = implode( esc_html( self::list_pattern( $cfg, 'separator' ) ), $items );
		return self::format_label_html( self::list_pattern( $cfg, 'final' ), array( $lead, $last ) );
	}

	/**
	 * Resolve one localized list pattern with a gettext fallback.
	 *
	 * @param array  $cfg Config.
	 * @param string $key Pattern key.
	 * @return string
	 */
	private static function list_pattern( $cfg, $key ) {
		if ( isset( $cfg['list'][ $key ] ) && is_scalar( $cfg['list'][ $key ] ) ) {
			return (string) $cfg['list'][ $key ];
		}
		if ( 'pair' === $key ) {
			/* translators: 1: First list item, 2: Second list item. */
			return _x( '%1$s and %2$s', 'two-item list', 'itboffins-live-opening-hours' );
		}
		if ( 'final' === $key ) {
			/* translators: 1: Leading list items, 2: Final list item. */
			return _x( '%1$s, and %2$s', 'last two items in a list of three or more', 'itboffins-live-opening-hours' );
		}
		return _x( ', ', 'separator between list items', 'itboffins-live-opening-hours' );
	}

	/**
	 * Human one-line note for an upcoming special date.
	 *
	 * @param array $cfg Config.
	 * @param array $ev  Event { date, closed, ranges, label }.
	 * @return string
	 */
	private static function format_event( $cfg, $ev ) {
		$labels     = self::labels( $cfg );
		$date_label = self::event_date_label( $cfg, $ev );

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
		$ranges = self::normalize_ranges( $ranges );
		if ( empty( $ranges ) ) {
			return '';
		}
		if ( 1 === count( $ranges ) && '00:00' === $ranges[0]['open'] && '24:00' === $ranges[0]['close'] ) {
			$labels = self::labels( $cfg );
			return $labels['open_24h'];
		}
		$parts = array();
		foreach ( $ranges as $r ) {
			$close   = ( '24:00' === $r['close'] ) ? 1439 : self::to_min( $r['close'] );
			$open    = self::to_min( $r['open'] );
			if ( null !== $open && null !== $close ) {
				$parts[] = self::format_label( self::fmt_pattern( $cfg, 'range' ), array( self::fmt_time( $open ), self::fmt_time( $close ) ) );
			}
		}
		return implode( self::fmt_pattern( $cfg, 'ranges' ), $parts );
	}

	/**
	 * Format a 'YYYY-MM-DD' as a short, localised date, e.g. "Wed 25 Dec".
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	private static function fmt_date( $ymd ) {
		$ymd = is_scalar( $ymd ) ? (string) $ymd : '';
		$utc = new DateTimeZone( 'UTC' );
		$dt  = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, $utc );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $ymd ) {
			return $ymd;
		}
		return wp_date( 'D j M', $dt->getTimestamp(), $utc );
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
	 * @return array { closed: bool, ranges: array, defined: bool, special: bool }
	 */
	private static function day_plan( $cfg, $ymd, $dow ) {
		if ( isset( $cfg['holidays'] ) && is_array( $cfg['holidays'] ) && array_key_exists( $ymd, $cfg['holidays'] ) ) {
			$h      = is_array( $cfg['holidays'][ $ymd ] ) ? $cfg['holidays'][ $ymd ] : array();
			$ranges = empty( $h['closed'] ) ? self::normalize_ranges( isset( $h['ranges'] ) ? $h['ranges'] : array() ) : array();
			return array(
				'closed'  => ! empty( $h['closed'] ) || empty( $ranges ),
				'ranges'  => $ranges,
				'defined' => true,
				'special' => true,
			);
		}
		if ( ! self::weekly_enabled( $cfg ) ) {
			return array( 'closed' => true, 'ranges' => array(), 'defined' => false, 'special' => false );
		}
		$d      = isset( $cfg['schedule'][ $dow ] ) && is_array( $cfg['schedule'][ $dow ] ) ? $cfg['schedule'][ $dow ] : array( 'closed' => 1, 'ranges' => array() );
		$ranges = empty( $d['closed'] ) ? self::normalize_ranges( isset( $d['ranges'] ) ? $d['ranges'] : array() ) : array();
		return array(
			'closed'  => ! empty( $d['closed'] ) || empty( $ranges ),
			'ranges'  => $ranges,
			'defined' => true,
			'special' => false,
		);
	}

	/**
	 * Defensively normalize persisted/opening ranges before evaluation.
	 *
	 * @param mixed $ranges Raw ranges.
	 * @return array<int,array{open:string,close:string}>
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
			$open_text  = isset( $range['open'] ) && is_scalar( $range['open'] ) ? trim( (string) $range['open'] ) : '';
			$close_text = isset( $range['close'] ) && is_scalar( $range['close'] ) ? trim( (string) $range['close'] ) : '';
			$open       = self::to_min( $open_text );
			$close      = self::to_min( $close_text );
			if ( null === $open || null === $close || $open >= 1440 || $open === $close ) {
				continue;
			}
			$out[] = array( 'open' => $open_text, 'close' => $close_text );
		}
		return $out;
	}

	/**
	 * Shift a 'YYYY-MM-DD' by a number of days, returning date + weekday.
	 *
	 * @param string $ymd   Date.
	 * @param int    $delta Days to add (may be negative).
	 * @return array { ymd: string, dow: int }
	 */
	private static function shift_ymd( $ymd, $delta ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, new DateTimeZone( 'UTC' ) );
		if ( ! $dt ) {
			$dt = new DateTimeImmutable( 'today', new DateTimeZone( 'UTC' ) );
		}
		$dt = $dt->modify( ( $delta >= 0 ? '+' : '' ) . $delta . ' days' );
		return array(
			'ymd' => $dt->format( 'Y-m-d' ),
			'dow' => (int) $dt->format( 'w' ),
		);
	}

	/**
	 * Whole UTC-calendar days from one strict ISO date to another.
	 *
	 * @param string $from Anchor date.
	 * @param mixed  $to   Candidate date.
	 * @return int|null
	 */
	private static function date_day_offset( $from, $to ) {
		if ( ! is_string( $from ) || ! is_string( $to ) ) {
			return null;
		}
		$utc      = new DateTimeZone( 'UTC' );
		$from_dt  = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $utc );
		$to_dt    = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $utc );
		if ( ! $from_dt || ! $to_dt || $from_dt->format( 'Y-m-d' ) !== $from || $to_dt->format( 'Y-m-d' ) !== $to ) {
			return null;
		}
		return (int) ( ( $to_dt->getTimestamp() - $from_dt->getTimestamp() ) / DAY_IN_SECONDS );
	}

	/**
	 * Minutes since midnight for an 'HH:MM' string.
	 *
	 * @param mixed $hhmm Time.
	 * @return int|null
	 */
	private static function to_min( $hhmm ) {
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
	 * Format a minute-of-day using the site's time format.
	 *
	 * Only the time tokens shared with the browser evaluator are interpreted;
	 * other characters remain literal. This preserves a configured wall time
	 * such as 01:30 even when that instant does not exist during a DST jump.
	 *
	 * @param int $minutes Minutes since midnight.
	 * @return string
	 */
	private static function fmt_time( $minutes ) {
		$minutes = (int) $minutes;
		$minutes = ( ( $minutes % 1440 ) + 1440 ) % 1440;
		$hour    = (int) floor( $minutes / 60 );
		$minute  = $minutes % 60;
		$hour_12 = $hour % 12 ? $hour % 12 : 12;
		$lower   = self::meridiem( $hour < 12 ? 'am' : 'pm' );
		$upper   = self::meridiem( $hour < 12 ? 'AM' : 'PM' );
		$tokens  = array(
			'G' => (string) $hour,
			'H' => sprintf( '%02d', $hour ),
			'g' => (string) $hour_12,
			'h' => sprintf( '%02d', $hour_12 ),
			'i' => sprintf( '%02d', $minute ),
			's' => '00',
			'a' => $lower,
			'A' => $upper,
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
		$literal  = "\x1AIBOH_PERCENT\x1A";
		$template = str_replace( '%%', $literal, $template );
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

		return str_replace( $literal, '%', $result );
	}

	/**
	 * Escape a template's literals while allowing only supplied safe fragments
	 * to occupy supported string placeholders.
	 *
	 * @param string $template Label template.
	 * @param array  $args     Safe HTML fragments.
	 * @return string
	 */
	private static function format_label_html( $template, $args ) {
		return self::format_label( esc_html( (string) $template ), $args );
	}

	/**
	 * Complete label map with runtime-translated defaults.
	 *
	 * @param array $cfg Config.
	 * @return array
	 */
	private static function labels( $cfg ) {
		$defaults = IBOH_Settings::defaults();
		$stored   = isset( $cfg['labels'] ) && is_array( $cfg['labels'] ) ? $cfg['labels'] : array();
		$labels   = $defaults['labels'];
		foreach ( $labels as $key => $fallback ) {
			if ( isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ) {
				$labels[ $key ] = (string) $stored[ $key ];
			}
		}
		return $labels;
	}

	/**
	 * Localised weekday name for an index.
	 *
	 * @param array $cfg Config.
	 * @param int   $dow Weekday index 0..6.
	 * @return string
	 */
	private static function day_name( $cfg, $dow ) {
		$dow = ( ( (int) $dow % 7 ) + 7 ) % 7;
		if ( isset( $cfg['dayNames'][ $dow ] ) && is_scalar( $cfg['dayNames'][ $dow ] ) ) {
			return (string) $cfg['dayNames'][ $dow ];
		}
		if ( isset( $GLOBALS['wp_locale'] ) && is_object( $GLOBALS['wp_locale'] ) ) {
			return $GLOBALS['wp_locale']->get_weekday( $dow );
		}
		$fallback = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		return $fallback[ $dow ];
	}
}
