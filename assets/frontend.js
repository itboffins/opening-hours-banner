/* Opening Hours by IT Boffins — front-end (vanilla JS, no jQuery).
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

	function pad( n ) {
		return n < 10 ? '0' + n : '' + n;
	}

	function toMin( hhmm ) {
		var a = String( hhmm ).split( ':' );
		return ( parseInt( a[ 0 ], 10 ) || 0 ) * 60 + ( parseInt( a[ 1 ], 10 ) || 0 );
	}

	/* Current time in the SITE timezone: { ymd, dow, minutes }. */
	function siteNow( cfg, when ) {
		var now = when || new Date();
		if ( cfg.tz ) {
			try {
				var f = new Intl.DateTimeFormat( 'en-GB', {
					timeZone: cfg.tz,
					weekday: 'short',
					year: 'numeric',
					month: '2-digit',
					day: '2-digit',
					hour: '2-digit',
					minute: '2-digit',
					hour12: false
				} );
				var p = {};
				f.formatToParts( now ).forEach( function ( x ) {
					p[ x.type ] = x.value;
				} );
				var hour = ( '24' === p.hour ) ? 0 : parseInt( p.hour, 10 );
				var dow = ( WD[ p.weekday ] !== undefined )
					? WD[ p.weekday ]
					: new Date( p.year, parseInt( p.month, 10 ) - 1, parseInt( p.day, 10 ) ).getDay();
				return {
					ymd: p.year + '-' + p.month + '-' + p.day,
					dow: dow,
					minutes: hour * 60 + parseInt( p.minute, 10 )
				};
			} catch ( e ) {
				/* Fall through to the offset path. */
			}
		}
		var shifted = new Date( now.getTime() + ( ( cfg.offset || 0 ) * 60000 ) );
		return {
			ymd: shifted.getUTCFullYear() + '-' + pad( shifted.getUTCMonth() + 1 ) + '-' + pad( shifted.getUTCDate() ),
			dow: shifted.getUTCDay(),
			minutes: shifted.getUTCHours() * 60 + shifted.getUTCMinutes()
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

	/* Effective plan for a date, holiday override winning. The 'defined' flag is
	 * false when weekly hours are off and the date has no special override —
	 * meaning the day has no status to show, as distinct from being "closed". */
	function dayPlan( cfg, ymd, dow ) {
		if ( cfg.holidays && cfg.holidays[ ymd ] ) {
			var h = cfg.holidays[ ymd ];
			var closed = !! ( +h.closed );
			return { closed: closed, ranges: closed ? [] : ( h.ranges || [] ), defined: true };
		}
		if ( ! weeklyEnabled( cfg ) ) {
			return { closed: true, ranges: [], defined: false };
		}
		var d = ( cfg.schedule && ( cfg.schedule[ String( dow ) ] || cfg.schedule[ dow ] ) ) || { closed: 1, ranges: [] };
		return { closed: !! ( +d.closed ), ranges: d.ranges || [], defined: true };
	}

	/* Soonest special date strictly after today, or null. */
	function nextUpcoming( cfg, todayYmd ) {
		if ( ! cfg.holidays ) {
			return null;
		}
		var dates = Object.keys( cfg.holidays ).sort();
		for ( var i = 0; i < dates.length; i++ ) {
			if ( dates[ i ] <= todayYmd ) {
				continue;
			}
			var h = cfg.holidays[ dates[ i ] ];
			var closed = !! ( +h.closed );
			return {
				date: dates[ i ],
				closed: closed,
				ranges: closed ? [] : ( h.ranges || [] ),
				label: h.label || ''
			};
		}
		return null;
	}

	function evaluate( cfg, t ) {
		var open = false;
		var closesAt = null;

		var today = dayPlan( cfg, t.ymd, t.dow );
		if ( ! today.closed ) {
			today.ranges.forEach( function ( r ) {
				var o = toMin( r.open );
				var c = toMin( r.close );
				if ( c > o ) {
					if ( t.minutes >= o && t.minutes < c ) {
						open = true;
						if ( null === closesAt || c > closesAt ) {
							closesAt = c;
						}
					}
				} else if ( t.minutes >= o ) { // Crosses midnight.
					open = true;
					closesAt = 1440;
				}
			} );
		}

		if ( ! open ) {
			var y = shiftYmd( t.ymd, -1 );
			var yp = dayPlan( cfg, y.ymd, y.dow );
			if ( ! yp.closed ) {
				yp.ranges.forEach( function ( r ) {
					var o = toMin( r.open );
					var c = toMin( r.close );
					if ( c <= o && t.minutes < c ) {
						open = true;
						if ( null === closesAt || c > closesAt ) {
							closesAt = c;
						}
					}
				} );
			}
		}

		var nextOpen = open ? null : findNextOpen( cfg, t );
		return { open: open, closesAt: closesAt, nextOpen: nextOpen };
	}

	function findNextOpen( cfg, t ) {
		var today = dayPlan( cfg, t.ymd, t.dow );
		if ( ! today.closed ) {
			var cands = today.ranges
				.map( function ( r ) { return toMin( r.open ); } )
				.filter( function ( o ) { return o > t.minutes; } )
				.sort( function ( a, b ) { return a - b; } );
			if ( cands.length ) {
				return { dayOffset: 0, minute: cands[ 0 ], dow: t.dow };
			}
		}
		for ( var i = 1; i <= 7; i++ ) {
			var d = shiftYmd( t.ymd, i );
			var plan = dayPlan( cfg, d.ymd, d.dow );
			if ( ! plan.closed && plan.ranges.length ) {
				var opens = plan.ranges
					.map( function ( r ) { return toMin( r.open ); } )
					.sort( function ( a, b ) { return a - b; } );
				return { dayOffset: i, minute: opens[ 0 ], dow: d.dow };
			}
		}
		return null;
	}

	function fmtTime( cfg, minutes ) {
		minutes = ( ( minutes % 1440 ) + 1440 ) % 1440;
		var h = Math.floor( minutes / 60 );
		var m = minutes % 60;
		var tf = cfg.timeFormat || '';
		var ampm = /a/i.test( tf ) || /[gh]/.test( tf );
		if ( ampm ) {
			var ap = h < 12 ? 'am' : 'pm';
			var h12 = h % 12;
			if ( 0 === h12 ) {
				h12 = 12;
			}
			return h12 + ( m ? ( ':' + pad( m ) ) : '' ) + ' ' + ap;
		}
		return pad( h ) + ':' + pad( m );
	}

	/* Short, localised date for an 'YYYY-MM-DD' string, e.g. "Wed 25 Dec". */
	function fmtDate( cfg, ymd ) {
		var a = ymd.split( '-' );
		var dt = new Date( Date.UTC( +a[ 0 ], ( +a[ 1 ] ) - 1, +a[ 2 ] ) );
		try {
			return new Intl.DateTimeFormat( cfg.lang || undefined, {
				weekday: 'short',
				day: 'numeric',
				month: 'short',
				timeZone: 'UTC'
			} ).format( dt );
		} catch ( e ) {
			return ymd;
		}
	}

	/* Display text for a list of ranges, mirroring the server table. */
	function fmtRanges( cfg, ranges ) {
		var L = cfg.labels || {};
		if ( ! ranges || ! ranges.length ) {
			return '';
		}
		if ( 1 === ranges.length && '00:00' === ranges[ 0 ].open && '24:00' === ranges[ 0 ].close ) {
			return L.open_24h || 'Open 24 hours';
		}
		return ranges.map( function ( r ) {
			var close = ( '24:00' === r.close ) ? 1439 : toMin( r.close );
			return fmtTime( cfg, toMin( r.open ) ) + ' – ' + fmtTime( cfg, close );
		} ).join( ', ' );
	}

	/* One-line note for an upcoming special date. */
	function formatEvent( cfg, ev ) {
		var L = cfg.labels || {};
		var dateText = fmtDate( cfg, ev.date );
		var dateLabel = ev.label ? ( dateText + ' (' + ev.label + ')' ) : dateText;
		if ( ev.closed || ! ev.ranges || ! ev.ranges.length ) {
			return sprintf( L.upcoming_closed || '', [ dateLabel ] );
		}
		return sprintf( L.upcoming_hours || '', [ dateLabel, fmtRanges( cfg, ev.ranges ) ] );
	}

	function sprintf( tpl, args ) {
		if ( ! tpl ) {
			return '';
		}
		var i = 0;
		return String( tpl )
			.replace( /%(\d+)\$s/g, function ( _, n ) {
				return ( args[ ( +n ) - 1 ] !== undefined ) ? args[ ( +n ) - 1 ] : '';
			} )
			.replace( /%s/g, function () {
				return ( args[ i++ ] !== undefined ) ? args[ i - 1 ] : '';
			} );
	}

	/* Build { show, open, main, sub, upcoming } status text from the evaluation. */
	function buildStatus( cfg, t ) {
		var ev = evaluate( cfg, t );
		var L = cfg.labels || {};
		var B = cfg.banner || {};
		var O = cfg.options || {};
		var soon = ( +B.soon_mins ) || 0;
		var show = !! ( +B.show_next );
		var weekly = weeklyEnabled( cfg );
		var showUpcoming = !! ( +O.show_upcoming ) || ! weekly;
		var todayDefined = dayPlan( cfg, t.ymd, t.dow ).defined;
		var upcoming = showUpcoming ? nextUpcoming( cfg, t.ymd ) : null;
		var upText = upcoming ? formatEvent( cfg, upcoming ) : '';

		if ( ev.open ) {
			var main = L.open || '';
			var sub = '';
			if ( null !== ev.closesAt ) {
				if ( show ) {
					sub = sprintf( L.closes_at || '', [ fmtTime( cfg, ev.closesAt ) ] );
				}
				if ( soon > 0 && ( ev.closesAt - t.minutes ) <= soon ) {
					main = L.closing_soon || main;
				}
			}
			return { show: true, open: true, main: main, sub: sub, upcoming: upText };
		}

		if ( todayDefined ) {
			var cmain = L.closed || '';
			var csub = '';
			if ( ev.nextOpen ) {
				var time = fmtTime( cfg, ev.nextOpen.minute );
				if ( show ) {
					if ( 0 === ev.nextOpen.dayOffset ) {
						csub = sprintf( L.opens_today || '', [ time ] );
					} else {
						csub = sprintf( L.opens_on || '', [ dayName( cfg, ev.nextOpen.dow ), time ] );
					}
				}
				if ( soon > 0 && 0 === ev.nextOpen.dayOffset && ( ev.nextOpen.minute - t.minutes ) <= soon ) {
					cmain = L.opening_soon || cmain;
				}
			}
			return { show: true, open: false, main: cmain, sub: csub, upcoming: upText };
		}

		// Weekly off, nothing today: announce the next date, or hide entirely.
		if ( upcoming ) {
			return {
				show: true,
				open: ( ! upcoming.closed && upcoming.ranges.length > 0 ),
				main: upText,
				sub: '',
				upcoming: ''
			};
		}
		return { show: false, open: false, main: '', sub: '', upcoming: '' };
	}

	function dayName( cfg, dow ) {
		return ( cfg.dayNames && cfg.dayNames[ dow ] ) ? cfg.dayNames[ dow ] : '';
	}

	/* ---- DOM application -------------------------------------------------- */

	function setText( root, sel, text ) {
		var el = root.querySelector( sel );
		if ( el ) {
			el.textContent = text;
		}
	}

	function applyBanner( el, cfg ) {
		var s = buildStatus( cfg, siteNow( cfg ) );
		if ( ! s.show ) {
			el.style.display = 'none';
			return;
		}
		el.style.display = '';
		el.classList.toggle( 'iboh-open', s.open );
		el.classList.toggle( 'iboh-closed', ! s.open );
		var B = cfg.banner || {};
		el.style.background = s.open ? B.colour_open : B.colour_closed;
		el.style.color = B.colour_text;
		setText( el, '[data-iboh-main]', s.main );
		setText( el, '[data-iboh-sub]', s.sub );
		setText( el, '[data-iboh-upcoming]', s.upcoming || '' );
	}

	function applyStatus( el, cfg ) {
		var s = buildStatus( cfg, siteNow( cfg ) );
		el.style.display = s.show ? '' : 'none';
		el.classList.toggle( 'iboh-open', s.open );
		el.classList.toggle( 'iboh-closed', ! s.open );
		setText( el, '[data-iboh-main]', s.main );
		setText( el, '[data-iboh-sub]', s.sub );
		setText( el, '[data-iboh-upcoming]', s.upcoming || '' );
	}

	function applyTable( el, cfg ) {
		var t = siteNow( cfg );
		var rows = el.querySelectorAll( '[data-iboh-dow]' );
		Array.prototype.forEach.call( rows, function ( row ) {
			row.classList.toggle( 'iboh-today', String( t.dow ) === row.getAttribute( 'data-iboh-dow' ) );
		} );
	}

	/* Hide special-date rows that have since passed; hide the whole list if all
	 * have. Keeps a cached "Upcoming dates" list from showing stale entries. */
	function applyUpcoming( el, cfg ) {
		var t = siteNow( cfg );
		var rows = el.querySelectorAll( '[data-iboh-date]' );
		var anyVisible = false;
		Array.prototype.forEach.call( rows, function ( row ) {
			var past = row.getAttribute( 'data-iboh-date' ) < t.ymd;
			row.style.display = past ? 'none' : '';
			if ( ! past ) {
				anyVisible = true;
			}
		} );
		el.style.display = anyVisible ? '' : 'none';
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
			banner.style.display = 'none';
			try {
				window.localStorage.setItem( 'iboh_dismissed', banner.getAttribute( 'data-iboh-sig' ) || '1' );
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

		// Honour a prior dismissal (kept in localStorage, never in cached HTML).
		Array.prototype.forEach.call( banners, function ( b ) {
			if ( '1' === b.getAttribute( 'data-iboh-dismissible' ) ) {
				var saved = null;
				try {
					saved = window.localStorage.getItem( 'iboh_dismissed' );
				} catch ( err ) {}
				if ( saved && saved === b.getAttribute( 'data-iboh-sig' ) ) {
					b.setAttribute( 'data-iboh-dismissed', '1' );
					b.style.display = 'none';
				}
			}
		} );

		function tick() {
			Array.prototype.forEach.call( banners, function ( b ) {
				if ( '1' !== b.getAttribute( 'data-iboh-dismissed' ) ) {
					applyBanner( b, cfg );
				}
			} );
			Array.prototype.forEach.call( statuses, function ( s ) { applyStatus( s, cfg ); } );
			Array.prototype.forEach.call( tables, function ( t ) { applyTable( t, cfg ); } );
			Array.prototype.forEach.call( upcomings, function ( u ) { applyUpcoming( u, cfg ); } );
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

	// Expose the evaluator so the admin live preview can reuse it verbatim.
	window.IBOHEval = {
		siteNow: siteNow,
		evaluate: evaluate,
		buildStatus: buildStatus,
		fmtTime: fmtTime
	};

	if ( 'loading' !== document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
} )();
