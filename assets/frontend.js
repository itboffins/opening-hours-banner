/* ITBoffins Live Opening Hours — front-end (vanilla JS, no jQuery).
 *
 * The open/closed state is computed here, in the browser, from the schedule
 * definition. That is what keeps the status correct even when the page HTML is
 * served from a full-page cache: the cached markup carries the schedule, never a
 * frozen status, and this script recomputes the live state on load and on a
 * timer.
 *
 * Timezone correctness: we read the *site's* wall-clock time via
 * Intl.DateTimeFormat with the configured IANA zone (DST-correct), NOT the
 * visitor's local offset. When the site is configured with only a fixed UTC
 * offset we shift from UTC by that offset (such sites do not model DST).
 */
( function () {
	'use strict';

	var WD = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
	var timezoneFormatters = Object.create( null );

	function pad( n ) {
		return n < 10 ? '0' + n : '' + n;
	}

	function toMin( hhmm ) {
		var match = /^([01]\d|2[0-4]):([0-5]\d)$/.exec( String( hhmm ) );
		if ( ! match || ( '24' === match[ 1 ] && '00' !== match[ 2 ] ) ) {
			return null;
		}
		return parseInt( match[ 1 ], 10 ) * 60 + parseInt( match[ 2 ], 10 );
	}

	function hasOwn( object, key ) {
		return !! object && Object.prototype.hasOwnProperty.call( object, key );
	}

	function normalizeRanges( ranges ) {
		if ( ! Array.isArray( ranges ) ) {
			return [];
		}
		return ranges.filter( function ( range ) {
			if ( ! range || 'object' !== typeof range ) {
				return false;
			}
			var open = toMin( range.open );
			var close = toMin( range.close );
			return null !== open && null !== close && open < 1440 && open !== close;
		} );
	}

	function timeZoneParts( timeZone, when ) {
		if ( ! timezoneFormatters[ timeZone ] ) {
			timezoneFormatters[ timeZone ] = new Intl.DateTimeFormat( 'en-GB', {
				timeZone: timeZone,
				calendar: 'gregory',
				numberingSystem: 'latn',
				weekday: 'short',
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
				hour12: false
			} );
		}
		var parts = {};
		timezoneFormatters[ timeZone ].formatToParts( when ).forEach( function ( part ) {
			parts[ part.type ] = part.value;
		} );
		parts.hour = '24' === parts.hour ? '00' : parts.hour;
		return parts;
	}

	/* Current time in the SITE timezone: { ymd, dow, minutes, epochMs }. */
	function siteNow( cfg, when ) {
		var now = when || new Date();
		if ( cfg.tz ) {
			try {
				var p = timeZoneParts( cfg.tz, now );
				var hour = parseInt( p.hour, 10 );
				var dow = ( WD[ p.weekday ] !== undefined )
					? WD[ p.weekday ]
					: new Date( Date.UTC( +p.year, ( +p.month ) - 1, +p.day ) ).getUTCDay();
				return {
					ymd: p.year + '-' + p.month + '-' + p.day,
					dow: dow,
					minutes: hour * 60 + parseInt( p.minute, 10 ),
					epochMs: now.getTime()
				};
			} catch ( e ) {
				/* Fall through to the offset path. */
			}
		}
		var shifted = new Date( now.getTime() + ( ( cfg.offset || 0 ) * 60000 ) );
		return {
			ymd: shifted.getUTCFullYear() + '-' + pad( shifted.getUTCMonth() + 1 ) + '-' + pad( shifted.getUTCDate() ),
			dow: shifted.getUTCDay(),
			minutes: shifted.getUTCHours() * 60 + shifted.getUTCMinutes(),
			epochMs: now.getTime()
		};
	}

	function shiftYmd( ymd, delta ) {
		var a = ymd.split( '-' );
		var dt = new Date( Date.UTC( +a[ 0 ], ( +a[ 1 ] ) - 1, +a[ 2 ] ) );
		dt.setUTCDate( dt.getUTCDate() + delta );
		return {
			ymd: dt.getUTCFullYear() + '-' + pad( dt.getUTCMonth() + 1 ) + '-' + pad( dt.getUTCDate() ),
			dow: dt.getUTCDay()
		};
	}

	function weeklyEnabled( cfg ) {
		var o = cfg.options || {};
		return ( o.weekly_enabled === undefined ) ? true : !! ( +o.weekly_enabled );
	}

	function bankHolidayMode( cfg ) {
		var mode = ( ( cfg.options || {} ).bank_holiday_mode || 'seven_days' );
		return ( 'all' === mode || 'none' === mode ) ? mode : 'seven_days';
	}

	/* Effective plan for a date, holiday override winning. The 'defined' flag is
	 * false when weekly hours are off and the date has no special override —
	 * meaning the day has no status to show, as distinct from being "closed". */
	function hasSpecialDate( cfg, ymd ) {
		return !! ( cfg.holidays && hasOwn( cfg.holidays, ymd ) );
	}

	function dayPlan( cfg, ymd, dow ) {
		if ( hasSpecialDate( cfg, ymd ) ) {
			var h = cfg.holidays[ ymd ];
			h = h && 'object' === typeof h ? h : {};
			var specialRanges = +h.closed ? [] : normalizeRanges( h.ranges );
			var closed = !! ( +h.closed ) || ! specialRanges.length;
			return {
				closed: closed,
				ranges: closed ? [] : specialRanges,
				defined: true,
				special: true
			};
		}
		if ( ! weeklyEnabled( cfg ) ) {
			return { closed: true, ranges: [], defined: false, special: false };
		}
		var d = ( cfg.schedule && ( cfg.schedule[ String( dow ) ] || cfg.schedule[ dow ] ) ) || { closed: 1, ranges: [] };
		var dayRanges = +d.closed ? [] : normalizeRanges( d.ranges );
		var dayClosed = !! ( +d.closed ) || ! dayRanges.length;
		return {
			closed: dayClosed,
			ranges: dayClosed ? [] : dayRanges,
			defined: true,
			special: false
		};
	}

	function wallTimeCandidates( cfg, ymd, minute ) {
		var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( ymd || '' ) );
		if ( ! cfg.tz || ! match ) {
			return [];
		}
		var hour = Math.floor( minute / 60 );
		var minutePart = minute % 60;
		var naive = Date.UTC( +match[ 1 ], ( +match[ 2 ] ) - 1, +match[ 3 ], hour, minutePart );
		var offsets = {};
		try {
			[ -2160, -720, 0, 720, 2160 ].forEach( function ( probeMinutes ) {
				var probe = naive + probeMinutes * 60000;
				var parts = timeZoneParts( cfg.tz, new Date( probe ) );
				var localAsUtc = Date.UTC( +parts.year, ( +parts.month ) - 1, +parts.day, +parts.hour, +parts.minute );
				offsets[ String( localAsUtc - probe ) ] = localAsUtc - probe;
			} );
		} catch ( err ) {
			return [];
		}

		var candidates = [];
		Object.keys( offsets ).forEach( function ( key ) {
			var candidate = naive - offsets[ key ];
			var parts = timeZoneParts( cfg.tz, new Date( candidate ) );
			if (
				+parts.year === +match[ 1 ] &&
				+parts.month === +match[ 2 ] &&
				+parts.day === +match[ 3 ] &&
				+parts.hour === hour &&
				+parts.minute === minutePart &&
				-1 === candidates.indexOf( candidate )
			) {
				candidates.push( candidate );
			}
		} );
		return candidates.sort( function ( a, b ) { return a - b; } );
	}

	function transitionDeltaMinutes( cfg, t, absoluteMinute ) {
		var fallback = absoluteMinute - t.minutes;
		if ( ! cfg.tz || 'number' !== typeof t.epochMs || ! isFinite( t.epochMs ) ) {
			return fallback;
		}
		var dayOffset = Math.floor( absoluteMinute / 1440 );
		var minute = ( ( absoluteMinute % 1440 ) + 1440 ) % 1440;
		var target = shiftYmd( t.ymd, dayOffset ).ymd;
		var candidates = wallTimeCandidates( cfg, target, minute );
		for ( var index = 0; index < candidates.length; index++ ) {
			if ( candidates[ index ] >= t.epochMs ) {
				return ( candidates[ index ] - t.epochMs ) / 60000;
			}
		}
		return fallback;
	}

	/* Special dates strictly after today, sorted by date. */
	function upcomingEvents( cfg, todayYmd ) {
		if ( ! cfg.holidays ) {
			return [];
		}
		var out = [];
		var dates = Object.keys( cfg.holidays ).sort();
		var bankCutoff = shiftYmd( todayYmd, 7 ).ymd;
		var bankMode = bankHolidayMode( cfg );
		for ( var i = 0; i < dates.length; i++ ) {
			if ( dates[ i ] <= todayYmd || shiftYmd( dates[ i ], 0 ).ymd !== dates[ i ] ) {
				continue;
			}
			var h = cfg.holidays[ dates[ i ] ];
			h = h && 'object' === typeof h ? h : {};
			var source = 'string' === typeof h.source ? h.source : '';
			if ( 'bank_holiday' === source ) {
				if ( 'none' === bankMode ) {
					continue;
				}
				if ( 'all' !== bankMode && dates[ i ] > bankCutoff ) {
					continue;
				}
			}
			var ranges = +h.closed ? [] : normalizeRanges( h.ranges );
			var closed = !! ( +h.closed ) || ! ranges.length;
			out.push( {
				date: dates[ i ],
				closed: closed,
				ranges: closed ? [] : ranges,
				label: [ 'string', 'number', 'boolean' ].indexOf( typeof h.label ) >= 0 ? String( h.label ) : '',
				source: source
			} );
		}
		return out;
	}

	function mergeIntervals( intervals ) {
		var merged = [];
		intervals.sort( function ( a, b ) {
			return a.start === b.start ? a.end - b.end : a.start - b.start;
		} ).forEach( function ( interval ) {
			var last = merged.length ? merged[ merged.length - 1 ] : null;
			if ( last && interval.start <= last.end ) {
				last.end = Math.max( last.end, interval.end );
			} else {
				merged.push( { start: interval.start, end: interval.end } );
			}
		} );
		return merged;
	}

	function intervalsForDay( cfg, day, offset ) {
		var plan = dayPlan( cfg, day.ymd, day.dow );
		var intervals = [];
		if ( plan.closed ) {
			return intervals;
		}

		plan.ranges.forEach( function ( range ) {
			if ( ! range || 'object' !== typeof range ) {
				return;
			}
			var open = toMin( range.open );
			var close = toMin( range.close );
			if ( null === open || null === close || open >= 1440 || open === close ) {
				return;
			}

			var start = offset * 1440 + open;
			var end = offset * 1440 + close;
			if ( close < open ) {
				end = ( offset + 1 ) * 1440 + close;
				// A special date owns its whole calendar day, including the tail
				// of a range that began on the previous date.
				var next = shiftYmd( day.ymd, 1 );
				if ( hasSpecialDate( cfg, next.ymd ) ) {
					end = Math.min( end, ( offset + 1 ) * 1440 );
				}
			}
			if ( end > start ) {
				intervals.push( { start: start, end: end } );
			}
		} );
		return intervals;
	}

	function intervalWindow( cfg, t ) {
		var holidayCount = cfg.holidays && 'object' === typeof cfg.holidays
			? Math.min( Object.keys( cfg.holidays ).length, 200 )
			: 0;
		// Each override can mask the sole opening in a sparse weekly schedule.
		// Give every override a full week, plus two baseline weeks: one to find
		// the next opening and one to distinguish a finite run from 24/7 hours.
		var probeOffset = 7 * ( holidayCount + 2 );
		var intervals = [];
		for ( var offset = -1; offset <= probeOffset; offset++ ) {
			var day = shiftYmd( t.ymd, offset );
			intervals = intervals.concat( intervalsForDay( cfg, day, offset ) );
		}
		return {
			intervals: mergeIntervals( intervals ),
			coverageEnd: probeOffset * 1440
		};
	}

	function dayOffsetBetween( fromYmd, toYmd ) {
		var from = fromYmd.split( '-' );
		var to = toYmd.split( '-' );
		var fromTime = Date.UTC( +from[ 0 ], ( +from[ 1 ] ) - 1, +from[ 2 ] );
		var toTime = Date.UTC( +to[ 0 ], ( +to[ 1 ] ) - 1, +to[ 2 ] );
		return Math.round( ( toTime - fromTime ) / 86400000 );
	}

	function openingDescriptor( cfg, t, absoluteMinute ) {
		var dayOffset = Math.floor( absoluteMinute / 1440 );
		var minute = ( ( absoluteMinute % 1440 ) + 1440 ) % 1440;
		var date = shiftYmd( t.ymd, dayOffset );
		var plan = dayPlan( cfg, date.ymd, date.dow );
		return {
			dayOffset: dayOffset,
			minute: minute,
			dow: date.dow,
			ymd: date.ymd,
			special: !! plan.special,
			absoluteMinute: absoluteMinute
		};
	}

	function nextDistantSpecialOpen( cfg, t ) {
		if ( ! cfg.holidays || 'object' !== typeof cfg.holidays ) {
			return null;
		}
		var dates = Object.keys( cfg.holidays ).sort();
		for ( var i = 0; i < dates.length; i++ ) {
			if ( dates[ i ] < t.ymd || shiftYmd( dates[ i ], 0 ).ymd !== dates[ i ] ) {
				continue;
			}
			var dayOffset = dayOffsetBetween( t.ymd, dates[ i ] );
			var day = shiftYmd( t.ymd, dayOffset );
			var plan = dayPlan( cfg, dates[ i ], day.dow );
			if ( plan.closed ) {
				continue;
			}
			var opens = plan.ranges.map( function ( range ) {
				if ( ! range || 'object' !== typeof range ) {
					return null;
				}
				var open = toMin( range.open );
				var close = toMin( range.close );
				return null !== open && null !== close && open < 1440 && open !== close ? open : null;
			} ).filter( function ( minute ) {
				return null !== minute;
			} ).sort( function ( a, b ) {
				return a - b;
			} );
			for ( var j = 0; j < opens.length; j++ ) {
				var absolute = dayOffset * 1440 + opens[ j ];
				if ( absolute > t.minutes ) {
					return openingDescriptor( cfg, t, absolute );
				}
			}
		}
		return null;
	}

	function findNextOpen( cfg, t, windowData ) {
		windowData = windowData || intervalWindow( cfg, t );
		var next = null;
		for ( var i = 0; i < windowData.intervals.length; i++ ) {
			if ( windowData.intervals[ i ].start > t.minutes ) {
				next = openingDescriptor( cfg, t, windowData.intervals[ i ].start );
				break;
			}
		}

		var distant = nextDistantSpecialOpen( cfg, t );
		if ( distant && ( ! next || distant.absoluteMinute < next.absoluteMinute ) ) {
			return distant;
		}
		return next;
	}

	function evaluate( cfg, t ) {
		var windowData = intervalWindow( cfg, t );
		var current = null;
		for ( var i = 0; i < windowData.intervals.length; i++ ) {
			var interval = windowData.intervals[ i ];
			if ( interval.start <= t.minutes && t.minutes < interval.end ) {
				current = interval;
				break;
			}
		}

		var open = null !== current;
		// If the merged interval continues into the probe day, the weekly
		// schedule has no finite near-term close (the common 24/7 case).
		var closesAt = open && current.end <= windowData.coverageEnd ? current.end : null;
		return {
			open: open,
			closesAt: closesAt,
			nextOpen: open ? null : findNextOpen( cfg, t, windowData )
		};
	}

	function fmtTime( cfg, minutes ) {
		minutes = ( ( minutes % 1440 ) + 1440 ) % 1440;
		var h = Math.floor( minutes / 60 );
		var m = minutes % 60;
		var h12 = h % 12 || 12;
		var meridiem = cfg.meridiem || {};
		var lower = h < 12
			? ( meridiem.am !== undefined ? meridiem.am : 'am' )
			: ( meridiem.pm !== undefined ? meridiem.pm : 'pm' );
		var upper = h < 12
			? ( meridiem.AM !== undefined ? meridiem.AM : String( lower ).toUpperCase() )
			: ( meridiem.PM !== undefined ? meridiem.PM : String( lower ).toUpperCase() );
		var replacements = {
			G: String( h ),
			H: pad( h ),
			g: String( h12 ),
			h: pad( h12 ),
			i: pad( m ),
			s: '00',
			a: String( lower ),
			A: String( upper )
		};
		var format = cfg.timeFormat !== undefined ? String( cfg.timeFormat ) : 'H:i';
		var output = '';
		var escaped = false;
		for ( var i = 0; i < format.length; i++ ) {
			var character = format.charAt( i );
			if ( escaped ) {
				output += character;
				escaped = false;
			} else if ( '\\' === character ) {
				escaped = true;
			} else {
				output += hasOwn( replacements, character ) ? replacements[ character ] : character;
			}
		}
		if ( escaped ) {
			output += '\\';
		}
		return output;
	}

	/* Short, localised date for an 'YYYY-MM-DD' string, e.g. "Wed 25 Dec". */
	function intlLocale( locale ) {
		var parts = String( locale || '' ).replace( /_/g, '-' ).split( '-' ).filter( function ( part ) {
			return '' !== part;
		} );
		while ( parts.length ) {
			var candidate = parts.join( '-' );
			try {
				new Intl.DateTimeFormat( candidate );
				return candidate;
			} catch ( err ) {
				parts.pop();
			}
		}
		return undefined;
	}

	function fmtDate( cfg, ymd ) {
		var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( ymd || '' ) );
		if ( ! match ) {
			return ymd;
		}
		var dt = new Date( Date.UTC( +match[ 1 ], ( +match[ 2 ] ) - 1, +match[ 3 ] ) );
		if ( dt.getUTCFullYear() !== +match[ 1 ] || dt.getUTCMonth() + 1 !== +match[ 2 ] || dt.getUTCDate() !== +match[ 3 ] ) {
			return ymd;
		}
		var dateNames = cfg.dateNames || {};
		if (
			dateNames.weekdays && dateNames.months &&
			dateNames.weekdays[ dt.getUTCDay() ] !== undefined &&
			dateNames.months[ dt.getUTCMonth() ] !== undefined
		) {
			return String( dateNames.weekdays[ dt.getUTCDay() ] ) + ' ' + dt.getUTCDate() + ' ' + String( dateNames.months[ dt.getUTCMonth() ] );
		}
		try {
			var formatter = new Intl.DateTimeFormat( intlLocale( cfg.lang ), {
				calendar: 'gregory',
				numberingSystem: 'latn',
				weekday: 'short',
				day: 'numeric',
				month: 'short',
				timeZone: 'UTC'
			} );
			if ( 'function' === typeof formatter.formatToParts ) {
				var labels = {};
				formatter.formatToParts( dt ).forEach( function ( part ) {
					if ( 'weekday' === part.type || 'day' === part.type || 'month' === part.type ) {
						labels[ part.type ] = part.value;
					}
				} );
				if ( labels.weekday && labels.day && labels.month ) {
					// Match wp_date( 'D j M' ) in the PHP fallback: localized
					// components, but a stable weekday/day/month order.
					return labels.weekday + ' ' + labels.day + ' ' + labels.month;
				}
			}
			return formatter.format( dt );
		} catch ( e ) {
			return ymd;
		}
	}

	/* Display text for a list of ranges, mirroring the server table. */
	function fmtRanges( cfg, ranges ) {
		var L = cfg.labels || {};
		if ( ! Array.isArray( ranges ) || ! ranges.length ) {
			return '';
		}
		if ( 1 === ranges.length && '00:00' === ranges[ 0 ].open && '24:00' === ranges[ 0 ].close ) {
			return L.open_24h || 'Open 24 hours';
		}
		return ranges.map( function ( range ) {
			if ( ! range || 'object' !== typeof range ) {
				return '';
			}
			var open = toMin( range.open );
			var close = toMin( range.close );
			if ( null === open || null === close || open >= 1440 ) {
				return '';
			}
			close = ( '24:00' === range.close ) ? 1439 : close;
			return fmtTime( cfg, open ) + ' – ' + fmtTime( cfg, close );
		} ).filter( Boolean ).join( ', ' );
	}

	/* One-line note for an upcoming special date. */
	function formatEvent( cfg, ev ) {
		var L = cfg.labels || {};
		var dateLabel = eventDateLabel( cfg, ev );
		if ( ev.closed || ! ev.ranges || ! ev.ranges.length ) {
			return sprintf( L.upcoming_closed || '', [ dateLabel ] );
		}
		return sprintf( L.upcoming_hours || '', [ dateLabel, fmtRanges( cfg, ev.ranges ) ] );
	}

	function formatEvents( cfg, events ) {
		if ( ! events || ! events.length ) {
			return '';
		}

		var parts = [];
		var closedDates = [];
		function flushClosedDates() {
			if ( closedDates.length ) {
				parts.push( formatClosedDates( cfg, closedDates ) );
				closedDates = [];
			}
		}

		events.forEach( function ( ev ) {
			if ( ev.closed || ! ev.ranges || ! ev.ranges.length ) {
				closedDates.push( eventDateLabel( cfg, ev ) );
				return;
			}

			flushClosedDates();
			parts.push( formatEvent( cfg, ev ) );
		} );
		flushClosedDates();

		return parts.filter( Boolean ).join( ' · ' );
	}

	function eventDateLabel( cfg, ev ) {
		var dateText = fmtDate( cfg, ev.date );
		var label = ev.label === undefined || null === ev.label ? '' : String( ev.label );
		return '' !== label ? ( dateText + ' (' + label + ')' ) : dateText;
	}

	function formatClosedDates( cfg, closedDates ) {
		var L = cfg.labels || {};
		if ( 1 === closedDates.length ) {
			return sprintf( L.upcoming_closed || '', [ closedDates[ 0 ] ] );
		}
		return sprintf( L.upcoming_closed_list || '%s we are closed', [ humanList( cfg, closedDates, false ) ] );
	}

	function listSettings( cfg ) {
		var list = cfg.list || {};
		return {
			separator: list.separator !== undefined ? String( list.separator ) : ', ',
			pair: list.pair !== undefined ? String( list.pair ) : '%1$s and %2$s',
			final: list.final !== undefined ? String( list.final ) : '%1$s, and %2$s'
		};
	}

	function humanList( cfg, items, html ) {
		if ( items.length <= 1 ) {
			return items[ 0 ] || '';
		}
		var settings = listSettings( cfg );
		if ( 2 === items.length ) {
			return html
				? formatTemplateHtml( settings.pair, [ items[ 0 ], items[ 1 ] ] )
				: sprintf( settings.pair, [ items[ 0 ], items[ 1 ] ] );
		}
		var separator = html ? escapeHtml( settings.separator ) : settings.separator;
		var initial = items.slice( 0, -1 ).join( separator );
		return html
			? formatTemplateHtml( settings.final, [ initial, items[ items.length - 1 ] ] )
			: sprintf( settings.final, [ initial, items[ items.length - 1 ] ] );
	}

	function escapeHtml( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function interpolateTemplate( template, args, html ) {
		var source = String( template || '' );
		var values = args || [];
		var output = '';
		var cursor = 0;
		var sequential = 0;
		var token = /%%|%(\d+)\$s|%s/g;
		var match;
		while ( ( match = token.exec( source ) ) ) {
			var literal = source.slice( cursor, match.index );
			output += html ? escapeHtml( literal ) : literal;
			if ( '%%' === match[ 0 ] ) {
				output += '%';
			} else {
				var position = match[ 1 ] ? ( +match[ 1 ] ) - 1 : sequential++;
				output += values[ position ] !== undefined ? String( values[ position ] ) : '';
			}
			cursor = token.lastIndex;
		}
		var tail = source.slice( cursor );
		output += html ? escapeHtml( tail ) : tail;
		return output;
	}

	function formatTemplateHtml( template, args ) {
		return interpolateTemplate( template, args, true );
	}

	function eventDateHtml( cfg, ev ) {
		return '<strong class="iboh-upcoming-date">' + escapeHtml( eventDateLabel( cfg, ev ) ) + '</strong>';
	}

	function formatEventHtml( cfg, ev ) {
		var L = cfg.labels || {};
		var date = eventDateHtml( cfg, ev );
		var content;
		if ( ev.closed || ! ev.ranges || ! ev.ranges.length ) {
			content = formatTemplateHtml( L.upcoming_closed || '%s', [ date ] );
		} else {
			var hours = '<span class="iboh-upcoming-detail">' + escapeHtml( fmtRanges( cfg, ev.ranges ) ) + '</span>';
			content = formatTemplateHtml( L.upcoming_hours || '%1$s: %2$s', [ date, hours ] );
		}
		return '<span class="iboh-upcoming-item">' + content + '</span>';
	}

	function formatClosedEventsHtml( cfg, events ) {
		var L = cfg.labels || {};
		var dates = events.map( function ( event ) {
			return eventDateHtml( cfg, event );
		} );
		var template = 1 === dates.length
			? ( L.upcoming_closed || '%s' )
			: ( L.upcoming_closed_list || '%s we are closed' );
		var value = 1 === dates.length ? dates[ 0 ] : humanList( cfg, dates, true );
		return '<span class="iboh-upcoming-item">' + formatTemplateHtml( template, [ value ] ) + '</span>';
	}

	function formatEventsHtml( cfg, events ) {
		if ( ! events || ! events.length ) {
			return '';
		}
		var items = [];
		var closedEvents = [];
		function flushClosedEvents() {
			if ( closedEvents.length ) {
				items.push( formatClosedEventsHtml( cfg, closedEvents ) );
				closedEvents = [];
			}
		}
		events.forEach( function ( event ) {
			if ( event.closed || ! event.ranges || ! event.ranges.length ) {
				closedEvents.push( event );
				return;
			}
			flushClosedEvents();
			items.push( formatEventHtml( cfg, event ) );
		} );
		flushClosedEvents();
		// The flex gap is visual only; whitespace preserves an accessible text
		// boundary between adjacent notices.
		return items.join( ' ' );
	}

	function sprintf( tpl, args ) {
		return interpolateTemplate( tpl, args, false );
	}

	function absoluteMomentKey( t, absoluteMinute ) {
		var dayOffset = Math.floor( absoluteMinute / 1440 );
		var minute = ( ( absoluteMinute % 1440 ) + 1440 ) % 1440;
		return shiftYmd( t.ymd, dayOffset ).ymd + 'T' + pad( Math.floor( minute / 60 ) ) + ':' + pad( minute % 60 );
	}

	function transitionLabel( cfg, t, absoluteMinute ) {
		var dayOffset = Math.floor( absoluteMinute / 1440 );
		var minute = ( ( absoluteMinute % 1440 ) + 1440 ) % 1440;
		var time = fmtTime( cfg, minute );
		return dayOffset > 0 ? fmtDate( cfg, shiftYmd( t.ymd, dayOffset ).ymd ) + ', ' + time : time;
	}

	/* Build { show, open, main, sub, upcoming, occurrence } status text. */
	function buildStatus( cfg, t ) {
		var ev = evaluate( cfg, t );
		var L = cfg.labels || {};
		var B = cfg.banner || {};
		var O = cfg.options || {};
		var soon = ( +B.soon_mins ) || 0;
		var show = !! ( +B.show_next );
		var showUpcoming = !! ( +O.show_upcoming );
		var todayDefined = dayPlan( cfg, t.ymd, t.dow ).defined;
		var upcoming = showUpcoming ? upcomingEvents( cfg, t.ymd ) : [];
		var upText = upcoming.length ? formatEvents( cfg, upcoming ) : '';
		var upHtml = upcoming.length ? formatEventsHtml( cfg, upcoming ) : '';

		if ( ev.open ) {
			var main = L.open || '';
			var sub = '';
			if ( null !== ev.closesAt ) {
				if ( show ) {
					sub = sprintf( L.closes_at || '', [ transitionLabel( cfg, t, ev.closesAt ) ] );
				}
				var untilClose = transitionDeltaMinutes( cfg, t, ev.closesAt );
				if ( soon > 0 && untilClose > 0 && untilClose <= soon ) {
					main = L.closing_soon || main;
				}
			}
			return {
				show: true,
				open: true,
				main: main,
				sub: sub,
				upcoming: upText,
				upcomingHtml: upHtml,
				occurrence: null === ev.closesAt ? 'open:continuous' : 'open-until:' + absoluteMomentKey( t, ev.closesAt )
			};
		}

		if ( todayDefined ) {
			var cmain = L.closed || '';
			var csub = '';
			var closedOccurrence = 'closed:none';
			if ( ev.nextOpen ) {
				var time = fmtTime( cfg, ev.nextOpen.minute );
				if ( show ) {
					if ( 0 === ev.nextOpen.dayOffset ) {
						csub = sprintf( L.opens_today || '', [ time ] );
					} else {
						var openingDay = ev.nextOpen.special || ev.nextOpen.dayOffset >= 7
							? fmtDate( cfg, ev.nextOpen.ymd || shiftYmd( t.ymd, ev.nextOpen.dayOffset ).ymd )
							: dayName( cfg, ev.nextOpen.dow );
						csub = sprintf( L.opens_on || '', [ openingDay, time ] );
					}
				}
				var nextAbsolute = ev.nextOpen.absoluteMinute !== undefined
					? ev.nextOpen.absoluteMinute
					: ev.nextOpen.dayOffset * 1440 + ev.nextOpen.minute;
				closedOccurrence = 'closed-until:' + absoluteMomentKey( t, nextAbsolute );
				var untilOpen = transitionDeltaMinutes( cfg, t, nextAbsolute );
				if ( soon > 0 && untilOpen > 0 && untilOpen <= soon ) {
					cmain = L.opening_soon || cmain;
				}
			}
			return { show: true, open: false, main: cmain, sub: csub, upcoming: upText, upcomingHtml: upHtml, occurrence: closedOccurrence };
		}

		// Weekly off, nothing today: optionally announce future dates. An
		// announcement is never the same thing as being open right now.
		if ( upcoming.length ) {
			return {
				show: true,
				open: false,
				main: upText,
				sub: '',
				upcoming: '',
				upcomingHtml: '',
				occurrence: 'announcement'
			};
		}
		return { show: false, open: false, main: '', sub: '', upcoming: '', upcomingHtml: '', occurrence: 'hidden' };
	}

	function dayName( cfg, dow ) {
		return ( cfg.dayNames && cfg.dayNames[ dow ] ) ? cfg.dayNames[ dow ] : '';
	}

	/* ---- DOM application -------------------------------------------------- */

	function setText( root, sel, text ) {
		var el = root.querySelector( sel );
		var value = String( text || '' );
		if ( el && el.textContent !== value ) {
			el.textContent = value;
		}
	}

	function setHtml( root, sel, html ) {
		var el = root.querySelector( sel );
		var value = String( html || '' );
		// Browsers normalize entity spellings in innerHTML, so comparing the DOM
		// serialization can produce a false change every tick. Cache the exact
		// generated value to avoid repeatedly re-announcing a stable live region.
		if ( el && el.__ibohRenderedHtml !== value ) {
			el.innerHTML = value;
			el.__ibohRenderedHtml = value;
		}
	}

	function ensureLiveRegion( el ) {
		var live = el.hasAttribute( 'data-iboh-banner' )
			? el.querySelector( '.iboh-banner-inner' )
			: el;
		if ( live ) {
			live.setAttribute( 'role', 'status' );
			live.setAttribute( 'aria-live', 'polite' );
			live.setAttribute( 'aria-atomic', 'true' );
		}
	}

	function moveFooterFallbackBanner( el, cfg ) {
		var B = cfg.banner || {};
		if (
			'bottom' !== B.position &&
			'1' === el.getAttribute( 'data-iboh-footer-fallback' ) &&
			document.body
		) {
			document.body.insertBefore( el, document.body.firstChild );
			el.setAttribute( 'data-iboh-footer-fallback', '0' );
		}
	}

	function applyBannerLayout( el, cfg ) {
		var B = cfg.banner || {};
		var pos = ( 'bottom' === B.position ) ? 'bottom' : 'top';
		var sticky = ( B.sticky === undefined ) ? true : !! ( +B.sticky );
		var pushTop = ( B.push_top === undefined ) ? true : !! ( +B.push_top );

		el.classList.toggle( 'iboh-pos-top', 'top' === pos );
		el.classList.toggle( 'iboh-pos-bottom', 'bottom' === pos );
		el.classList.toggle( 'iboh-sticky', sticky );
		el.classList.toggle( 'iboh-not-sticky', ! sticky );
		el.classList.toggle( 'iboh-push-top', 'top' === pos && pushTop );
		el.classList.toggle( 'iboh-overlay-top', 'top' === pos && ! pushTop );
	}

	function finishBannerLoading( el ) {
		el.classList.remove( 'iboh-loading' );
		el.removeAttribute( 'aria-busy' );
		var live = el.querySelector( '.iboh-banner-inner' );
		if ( live ) {
			live.removeAttribute( 'aria-busy' );
		}
	}

	function applyBanner( el, cfg, state ) {
		applyBannerLayout( el, cfg );
		if ( ! state.show ) {
			el.style.display = 'none';
			finishBannerLoading( el );
			return;
		}
		el.classList.toggle( 'iboh-open', state.open );
		el.classList.toggle( 'iboh-closed', ! state.open );
		var B = cfg.banner || {};
		el.style.background = state.open ? B.colour_open : B.colour_closed;
		el.style.color = B.colour_text;
		setText( el, '[data-iboh-main]', state.main );
		setText( el, '[data-iboh-sub]', state.sub );
		setHtml( el, '[data-iboh-upcoming]', state.upcomingHtml || state.upcoming || '' );
		finishBannerLoading( el );
		el.style.display = '';
	}

	function applyStatus( el, state ) {
		el.style.display = state.show ? '' : 'none';
		el.classList.toggle( 'iboh-open', state.open );
		el.classList.toggle( 'iboh-closed', ! state.open );
		setText( el, '[data-iboh-main]', state.main );
		setText( el, '[data-iboh-sub]', state.sub );
		setHtml( el, '[data-iboh-upcoming]', state.upcomingHtml || state.upcoming || '' );
	}

	function applyTable( el, t ) {
		var rows = el.querySelectorAll( '[data-iboh-dow]' );
		Array.prototype.forEach.call( rows, function ( row ) {
			row.classList.toggle( 'iboh-today', String( t.dow ) === row.getAttribute( 'data-iboh-dow' ) );
		} );
	}

	/* Hide special-date rows that have since passed; hide the whole list if all
	 * have. Keeps a cached "Upcoming dates" list from showing stale entries. */
	function applyUpcoming( el, cfg, t ) {
		var rows = el.querySelectorAll( '[data-iboh-date]' );
		var bankCutoff = shiftYmd( t.ymd, 7 ).ymd;
		var bankMode = bankHolidayMode( cfg );
		var anyVisible = false;
		Array.prototype.forEach.call( rows, function ( row ) {
			var date = row.getAttribute( 'data-iboh-date' ) || '';
			var holiday = ( cfg.holidays && cfg.holidays[ date ] ) ? cfg.holidays[ date ] : {};
			var source = holiday.source || row.getAttribute( 'data-iboh-source' ) || '';
			var hidden = date < t.ymd || ( 'bank_holiday' === source && ( 'none' === bankMode || ( 'all' !== bankMode && date > bankCutoff ) ) );
			row.style.display = hidden ? 'none' : '';
			if ( ! hidden ) {
				anyVisible = true;
			}
		} );
		el.style.display = anyVisible ? '' : 'none';
	}

	function hashString( value ) {
		var hash = 2166136261;
		var string = String( value );
		for ( var i = 0; i < string.length; i++ ) {
			hash ^= string.charCodeAt( i );
			hash = Math.imul( hash, 16777619 );
		}
		return ( '00000000' + ( hash >>> 0 ).toString( 16 ) ).slice( -8 );
	}

	function liveSignature( configSignature, state ) {
		return String( configSignature || '1' ) + '-' + hashString( JSON.stringify( {
			show: !! state.show,
			open: !! state.open,
			main: state.main || '',
			sub: state.sub || '',
			upcoming: state.upcoming || '',
			upcomingHtml: state.upcomingHtml || '',
			occurrence: state.occurrence || ''
		} ) );
	}

	function dismissalStorageKey( cfg ) {
		var siteKey = cfg && cfg.siteKey !== undefined ? cfg.siteKey : '0';
		return 'iboh_dismissed_' + hashString( String( siteKey ) );
	}

	function storedDismissal( cfg ) {
		try {
			return window.localStorage.getItem( dismissalStorageKey( cfg ) );
		} catch ( err ) {
			return null;
		}
	}

	function clearStoredDismissal( cfg ) {
		try {
			window.localStorage.removeItem( dismissalStorageKey( cfg ) );
		} catch ( err ) {}
	}

	function bannerIsDismissed( banner, state, cfg ) {
		var base = banner.getAttribute( 'data-iboh-config-sig' );
		if ( ! base ) {
			// Older cached markup only has data-iboh-sig. Preserve that original
			// value as the base rather than hashing the previous tick recursively.
			base = banner.getAttribute( 'data-iboh-sig' ) || '1';
			banner.setAttribute( 'data-iboh-config-sig', base );
		}
		var signature = liveSignature( base, state );
		banner.setAttribute( 'data-iboh-sig', signature );
		if ( '1' !== banner.getAttribute( 'data-iboh-dismissible' ) ) {
			clearStoredDismissal( cfg );
			banner.removeAttribute( 'data-iboh-dismissed' );
			banner.removeAttribute( 'data-iboh-dismissed-sig' );
			return false;
		}

		var sessionSignature = banner.getAttribute( 'data-iboh-dismissed-sig' );
		var savedSignature = storedDismissal( cfg );
		var dismissed = signature === sessionSignature || signature === savedSignature;
		if ( dismissed ) {
			banner.setAttribute( 'data-iboh-dismissed', '1' );
			banner.setAttribute( 'data-iboh-dismissed-sig', signature );
			banner.style.display = 'none';
			finishBannerLoading( banner );
			return true;
		}

		// A dismissal applies only to the exact live message that was dismissed.
		// Consume a stale saved value at the first state transition so an identical
		// message on a later day cannot disappear without a fresh dismissal.
		if ( savedSignature && savedSignature !== signature ) {
			clearStoredDismissal( cfg );
		}

		banner.removeAttribute( 'data-iboh-dismissed' );
		banner.removeAttribute( 'data-iboh-dismissed-sig' );
		return false;
	}

	function handleDismiss( cfg ) {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-iboh-close]' ) : null;
			if ( ! btn ) {
				return;
			}
			var banner = btn.closest( '[data-iboh-banner]' );
			if ( ! banner ) {
				return;
			}
			banner.setAttribute( 'data-iboh-dismissed', '1' );
			banner.setAttribute( 'data-iboh-dismissed-sig', banner.getAttribute( 'data-iboh-sig' ) || '1' );
			banner.style.display = 'none';
			try {
				window.localStorage.setItem( dismissalStorageKey( cfg ), banner.getAttribute( 'data-iboh-sig' ) || '1' );
			} catch ( err ) {}
		} );
	}

	function init() {
		var cfg = window.IBOH_DATA;
		if ( ! cfg ) {
			return; // Inert (e.g. in the admin preview context).
		}

		var banners = document.querySelectorAll( '[data-iboh-banner]' );
		var statuses = document.querySelectorAll( '[data-iboh-status]' );
		var tables = document.querySelectorAll( '[data-iboh-table]' );
		var upcomings = document.querySelectorAll( '[data-iboh-upcoming-list]' );

		Array.prototype.forEach.call( banners, function ( banner ) {
			moveFooterFallbackBanner( banner, cfg );
			ensureLiveRegion( banner );
		} );
		Array.prototype.forEach.call( statuses, ensureLiveRegion );

		function tick() {
			var now = siteNow( cfg );
			var state = ( banners.length || statuses.length ) ? buildStatus( cfg, now ) : null;
			Array.prototype.forEach.call( banners, function ( banner ) {
				if ( ! bannerIsDismissed( banner, state, cfg ) ) {
					applyBanner( banner, cfg, state );
				}
			} );
			Array.prototype.forEach.call( statuses, function ( status ) { applyStatus( status, state ); } );
			Array.prototype.forEach.call( tables, function ( table ) { applyTable( table, now ); } );
			Array.prototype.forEach.call( upcomings, function ( upcoming ) { applyUpcoming( upcoming, cfg, now ); } );
		}

		tick();
		window.setInterval( tick, 30000 );
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				tick();
			}
		} );
		handleDismiss( cfg );
	}

	// Expose the evaluator so the admin customer preview can reuse it verbatim.
	window.IBOHEval = {
		siteNow: siteNow,
		toMin: toMin,
		dayPlan: dayPlan,
		intervalWindow: intervalWindow,
		mergeIntervals: mergeIntervals,
		findNextOpen: findNextOpen,
		evaluate: evaluate,
		buildStatus: buildStatus,
		upcomingEvents: upcomingEvents,
		formatEvents: formatEvents,
		formatEventsHtml: formatEventsHtml,
		fmtDate: fmtDate,
		fmtTime: fmtTime,
		liveSignature: liveSignature,
		dismissalStorageKey: dismissalStorageKey
	};

	if ( 'loading' !== document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
} )();
