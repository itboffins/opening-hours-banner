'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );

global.window = {};
global.document = {
	readyState: 'loading',
	addEventListener: function () {}
};

require( path.join( __dirname, '..', 'assets', 'frontend.js' ) );

const evaluator = global.window.IBOHEval;
global.window.IBOH = { previewCarry: {} };
require( path.join( __dirname, '..', 'assets', 'admin.js' ) );
const adminEvaluator = global.window.IBOHAdminEval;

let registeredEditorBlock = null;
global.window.wp = {
	blocks: {
		registerBlockType: function ( name, settings ) {
			registeredEditorBlock = { name: name, settings: settings };
		}
	},
	element: { createElement: function () {} },
	i18n: { __: function ( text ) { return text; } },
	serverSideRender: function () {},
	blockEditor: {
		InspectorControls: function () {},
		useBlockProps: function () { return {}; }
	},
	components: {
		PanelBody: function () {},
		SelectControl: function () {}
	}
};
require( path.join( __dirname, '..', 'assets', 'editor.js' ) );

function closedSchedule() {
	const schedule = {};
	for ( let day = 0; day < 7; day++ ) {
		schedule[ day ] = { closed: 1, ranges: [] };
	}
	return schedule;
}

function baseConfig() {
	return {
		offset: 0,
		timeFormat: 'H:i',
		lang: 'en-GB',
		meridiem: { am: 'am', pm: 'pm', AM: 'AM', PM: 'PM' },
		list: {
			separator: ', ',
			pair: '%1$s and %2$s',
			final: '%1$s, and %2$s'
		},
		dateNames: {
			weekdays: [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ],
			months: [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ]
		},
		dayNames: [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ],
		schedule: closedSchedule(),
		holidays: {},
		options: {
			weekly_enabled: 1,
			show_upcoming: 0,
			bank_holiday_mode: 'all'
		},
		labels: {
			open: "We're open",
			closed: "Sorry, we're closed",
			closes_at: 'Closes at %s',
			opens_today: 'Opens today at %s',
			opens_on: 'Opens %1$s at %2$s',
			opening_soon: 'Opening soon',
			closing_soon: 'Closing soon',
			open_24h: 'Open 24 hours',
			upcoming_closed: 'Closed %s',
			upcoming_closed_list: '%s we are closed',
			upcoming_hours: '%1$s: %2$s'
		},
		banner: {
			show_next: 1,
			soon_mins: 60,
			colour_open: '#00794a',
			colour_closed: '#b3261e',
			colour_text: '#ffffff'
		}
	};
}

function addDays( ymd, days ) {
	const parts = ymd.split( '-' ).map( Number );
	const date = new Date( Date.UTC( parts[ 0 ], parts[ 1 ] - 1, parts[ 2 ] + days ) );
	return [
		date.getUTCFullYear(),
		String( date.getUTCMonth() + 1 ).padStart( 2, '0' ),
		String( date.getUTCDate() ).padStart( 2, '0' )
	].join( '-' );
}

test( 'editor block uses iframe-compatible Block API version 3', function () {
	assert.equal( registeredEditorBlock.name, 'iboh/opening-hours' );
	assert.equal( registeredEditorBlock.settings.apiVersion, 3 );
} );

test( 'admin accessibility labels format numbered and unnumbered placeholders', function () {
	assert.equal(
		adminEvaluator.formatTemplate( 'Time range %1$d for %2$s', [ 2, 'Monday' ] ),
		'Time range 2 for Monday'
	);
	assert.equal(
		adminEvaluator.formatTemplate( 'Public special date %s', [ '2026-08-31' ] ),
		'Public special date 2026-08-31'
	);
} );

test( 'overnight and overlapping ranges close at the end of the continuous interval', function () {
	const overnight = baseConfig();
	overnight.schedule[ 1 ] = {
		closed: 0,
		ranges: [ { open: '22:00', close: '02:00' } ]
	};
	const late = { ymd: '2026-08-03', dow: 1, minutes: 23 * 60 + 30 };
	const overnightState = evaluator.evaluate( overnight, late );
	assert.equal( overnightState.open, true );
	assert.equal( overnightState.closesAt, 26 * 60 );
	assert.equal( evaluator.buildStatus( overnight, late ).sub, 'Closes at Tue 4 Aug, 02:00' );
	assert.equal( evaluator.buildStatus( overnight, late ).main, "We're open" );

	const overlapping = baseConfig();
	overlapping.schedule[ 1 ] = {
		closed: 0,
		ranges: [
			{ open: '09:00', close: '12:00' },
			{ open: '11:00', close: '17:00' }
		]
	};
	assert.equal(
		evaluator.evaluate( overlapping, { ymd: '2026-08-03', dow: 1, minutes: 10 * 60 + 30 } ).closesAt,
		17 * 60
	);

	const touching = baseConfig();
	touching.schedule[ 1 ] = {
		closed: 0,
		ranges: [
			{ open: '09:00', close: '12:00' },
			{ open: '12:00', close: '17:00' }
		]
	};
	assert.equal(
		evaluator.evaluate( touching, { ymd: '2026-08-03', dow: 1, minutes: 11 * 60 } ).closesAt,
		17 * 60
	);

	const multiDay = baseConfig();
	multiDay.schedule[ 1 ] = { closed: 0, ranges: [ { open: '09:00', close: '24:00' } ] };
	multiDay.schedule[ 2 ] = { closed: 0, ranges: [ { open: '00:00', close: '24:00' } ] };
	multiDay.schedule[ 3 ] = { closed: 0, ranges: [ { open: '00:00', close: '17:00' } ] };
	assert.equal(
		evaluator.buildStatus( multiDay, { ymd: '2026-08-03', dow: 1, minutes: 10 * 60 } ).sub,
		'Closes at Wed 5 Aug, 17:00'
	);
} );

test( 'a continuous 24/7 week has no artificial midnight close', function () {
	const cfg = baseConfig();
	for ( let day = 0; day < 7; day++ ) {
		cfg.schedule[ day ] = {
			closed: 0,
			ranges: [ { open: '00:00', close: '24:00' } ]
		};
	}
	const state = evaluator.buildStatus(
		cfg,
		{ ymd: '2026-08-03', dow: 1, minutes: 23 * 60 + 59 }
	);
	assert.equal( state.open, true );
	assert.equal( state.main, "We're open" );
	assert.equal( state.sub, '' );
} );

test( 'a current-date special override suppresses the prior weekly overnight tail', function () {
	const cfg = baseConfig();
	cfg.schedule[ 1 ] = {
		closed: 0,
		ranges: [ { open: '22:00', close: '02:00' } ]
	};
	cfg.holidays[ '2026-08-04' ] = { closed: 1, ranges: [], label: '' };
	const afterMidnight = { ymd: '2026-08-04', dow: 2, minutes: 30 };
	assert.equal( evaluator.evaluate( cfg, afterMidnight ).open, false );

	cfg.holidays[ '2026-08-04' ] = {
		closed: 0,
		ranges: [ { open: '10:00', close: '12:00' } ],
		label: ''
	};
	assert.equal( evaluator.evaluate( cfg, afterMidnight ).open, false );
} );

test( 'next openings can be beyond seven days or on a distant special-only date', function () {
	const cfg = baseConfig();
	for ( let day = 0; day < 7; day++ ) {
		cfg.schedule[ day ] = {
			closed: 0,
			ranges: [ { open: '09:00', close: '17:00' } ]
		};
	}
	for ( let day = 4; day <= 10; day++ ) {
		cfg.holidays[ '2026-08-' + String( day ).padStart( 2, '0' ) ] = {
			closed: 1,
			ranges: [],
			label: ''
		};
	}
	const next = evaluator.evaluate(
		cfg,
		{ ymd: '2026-08-03', dow: 1, minutes: 18 * 60 }
	).nextOpen;
	assert.equal( next.dayOffset, 8 );
	assert.equal( next.minute, 9 * 60 );
	assert.equal(
		evaluator.buildStatus( cfg, { ymd: '2026-08-03', dow: 1, minutes: 18 * 60 } ).sub,
		'Opens Tue 11 Aug at 09:00'
	);

	const specialOnly = baseConfig();
	specialOnly.options.weekly_enabled = 0;
	specialOnly.holidays[ '2026-08-20' ] = {
		closed: 0,
		ranges: [ { open: '10:00', close: '14:00' } ],
		label: 'Market'
	};
	const specialNext = evaluator.evaluate(
		specialOnly,
		{ ymd: '2026-08-03', dow: 1, minutes: 12 * 60 }
	).nextOpen;
	assert.equal( specialNext.dayOffset, 17 );
	assert.equal( specialNext.minute, 10 * 60 );
	const specialStatus = baseConfig();
	specialStatus.holidays[ '2026-08-20' ] = specialOnly.holidays[ '2026-08-20' ];
	assert.equal(
		evaluator.buildStatus( specialStatus, { ymd: '2026-08-03', dow: 1, minutes: 18 * 60 } ).sub,
		'Opens Thu 20 Aug at 10:00'
	);
} );

test( 'sparse weekly openings survive many same-weekday overrides', function () {
	const cfg = baseConfig();
	cfg.schedule[ 0 ] = {
		closed: 0,
		ranges: [ { open: '09:00', close: '17:00' } ]
	};
	for ( let occurrence = 0; occurrence < 10; occurrence++ ) {
		cfg.holidays[ addDays( '2026-08-03', 6 + occurrence * 7 ) ] = {
			closed: 1,
			ranges: [],
			label: ''
		};
	}
	const next = evaluator.evaluate(
		cfg,
		{ ymd: '2026-08-03', dow: 1, minutes: 18 * 60 }
	).nextOpen;
	assert.equal( next.dayOffset, 76 );
	assert.equal( next.dow, 0 );
	assert.equal( next.minute, 9 * 60 );
} );

test( 'opening soon crosses midnight using an absolute-minute delta', function () {
	const cfg = baseConfig();
	cfg.banner.soon_mins = 30;
	cfg.schedule[ 2 ] = {
		closed: 0,
		ranges: [ { open: '00:10', close: '01:00' } ]
	};
	const status = evaluator.buildStatus(
		cfg,
		{ ymd: '2026-08-03', dow: 1, minutes: 23 * 60 + 50 }
	);
	assert.equal( status.open, false );
	assert.equal( status.main, 'Opening soon' );
	assert.equal( status.sub, 'Opens Tuesday at 00:10' );
} );

test( 'soon thresholds use elapsed time across Europe/London DST changes', function () {
	const cfg = baseConfig();
	cfg.tz = 'Europe/London';
	cfg.banner.soon_mins = 60;
	cfg.schedule[ 0 ] = {
		closed: 0,
		ranges: [ { open: '00:00', close: '02:00' } ]
	};

	const spring = evaluator.siteNow( cfg, new Date( '2026-03-29T00:30:00Z' ) );
	assert.equal( evaluator.buildStatus( cfg, spring ).main, 'Closing soon' );

	const autumnFirstHour = evaluator.siteNow( cfg, new Date( '2026-10-25T00:30:00Z' ) );
	assert.equal( autumnFirstHour.minutes, 90 );
	assert.equal( evaluator.buildStatus( cfg, autumnFirstHour ).main, "We're open" );

	cfg.banner.soon_mins = 20;
	cfg.schedule[ 0 ].ranges[ 0 ].close = '01:45';
	assert.equal( evaluator.buildStatus( cfg, autumnFirstHour ).main, 'Closing soon' );
	const autumnSecondHour = evaluator.siteNow( cfg, new Date( '2026-10-25T01:30:00Z' ) );
	assert.equal( autumnSecondHour.minutes, 90 );
	assert.equal( evaluator.buildStatus( cfg, autumnSecondHour ).main, 'Closing soon' );
} );

test( 'special-only future announcements respect show_upcoming and stay non-open', function () {
	const cfg = baseConfig();
	cfg.options.weekly_enabled = 0;
	cfg.holidays[ '2026-08-20' ] = {
		closed: 0,
		ranges: [ { open: '10:00', close: '14:00' } ],
		label: 'Market'
	};
	const now = { ymd: '2026-08-03', dow: 1, minutes: 12 * 60 };
	assert.equal( evaluator.buildStatus( cfg, now ).show, false );

	cfg.options.show_upcoming = 1;
	const one = evaluator.buildStatus( cfg, now );
	assert.equal( one.show, true );
	assert.equal( one.open, false );

	cfg.holidays[ '2026-08-21' ] = { closed: 1, ranges: [], label: 'Rest day' };
	assert.equal( evaluator.buildStatus( cfg, now ).open, false );
} );

test( 'WordPress time-format tokens, escaped literals, and meridiem are exact', function () {
	const cfg = baseConfig();
	cfg.timeFormat = 'g:i A';
	assert.equal( evaluator.fmtTime( cfg, 9 * 60 + 5 ), '9:05 AM' );
	cfg.timeFormat = 'H.i';
	assert.equal( evaluator.fmtTime( cfg, 9 * 60 + 5 ), '09.05' );
	cfg.timeFormat = '\\a\\t g:i a';
	assert.equal( evaluator.fmtTime( cfg, 9 * 60 + 5 ), 'at 9:05 am' );
	cfg.timeFormat = 'h:i:s A';
	assert.equal( evaluator.fmtTime( cfg, 9 * 60 + 5 ), '09:05:00 AM' );
	cfg.timeFormat = 'H:i T';
	assert.equal( evaluator.fmtTime( cfg, 9 * 60 + 5 ), '09:05 T' );
} );

test( 'localized dates use exact WordPress Gregorian components', function () {
	const cfg = {
		lang: 'fa-IR',
		dateNames: {
			weekdays: [ 'WP-Sun', 'WP-Mon', 'WP-Tue', 'WP-Wed', 'WP-Thu', 'WP-Fri', 'WP-Sat' ],
			months: [ 'WP-Jan', 'WP-Feb', 'WP-Mar', 'WP-Apr', 'WP-May', 'WP-Jun', 'WP-Jul', 'WP-Aug', 'WP-Sep', 'WP-Oct', 'WP-Nov', 'WP-Dec' ]
		}
	};
	assert.equal( evaluator.fmtDate( cfg, '2026-08-20' ), 'WP-Thu 20 WP-Aug' );
} );

test( 'WordPress locale suffixes fall back to a valid Intl locale', function ( t ) {
	if ( ! Intl.DateTimeFormat.supportedLocalesOf( [ 'pt-PT' ] ).length ) {
		t.skip( 'Portuguese locale data is unavailable in this Node.js build' );
		return;
	}
	const formatted = evaluator.fmtDate( { lang: 'pt-PT-ao90' }, '2026-08-20' );
	assert.notEqual( formatted, '2026-08-20' );
	assert.match( formatted, /20/ );
} );

test( 'structured upcoming markup honors custom templates and escapes custom text', function () {
	const cfg = baseConfig();
	cfg.labels.upcoming_closed = '<script>Unavailable on %s!</script>';
	cfg.labels.upcoming_hours = 'Hours %2$s on %1$s';
	const html = evaluator.formatEventsHtml( cfg, [
		{ date: '2026-08-20', closed: true, ranges: [], label: 'Rest' },
		{
			date: '2026-08-21',
			closed: false,
			ranges: [ { open: '10:00', close: '14:00' } ],
			label: 'Market'
		}
	] );
	assert.match( html, /&lt;script&gt;Unavailable on <strong class="iboh-upcoming-date">/ );
	assert.match( html, /<\/strong>!&lt;\/script&gt;/ );
	assert.match( html, /Hours <span class="iboh-upcoming-detail">10:00 – 14:00<\/span> on <strong/ );
	assert.doesNotMatch( html, /<script>/ );
	assert.match( html, /<\/span> <span class="iboh-upcoming-item">/ );
} );

test( 'localized list templates are used for grouped closed dates', function () {
	const cfg = baseConfig();
	cfg.list.pair = '%1$s plus %2$s';
	cfg.labels.upcoming_closed_list = 'No service %s';
	const events = [
		{ date: '2026-08-20', closed: true, ranges: [], label: '' },
		{ date: '2026-08-21', closed: true, ranges: [], label: '' }
	];
	assert.match( evaluator.formatEvents( cfg, events ), /No service .* plus / );
	assert.match( evaluator.formatEventsHtml( cfg, events ), /No service .* plus / );
} );

test( 'malformed ranges are ignored and live signatures include occurrence and site identity', function () {
	const cfg = baseConfig();
	cfg.schedule[ 1 ] = {
		closed: 0,
		ranges: [
			null,
			{ open: 'not-a-time', close: [] },
			{ open: '09:00', close: '17:00' }
		]
	};
	assert.equal(
		evaluator.evaluate( cfg, { ymd: '2026-08-03', dow: 1, minutes: 10 * 60 } ).open,
		true
	);
	assert.equal( evaluator.toMin( 'not-a-time' ), null );

	cfg.schedule[ 2 ] = { closed: 0, ranges: [ { open: '09:00', close: '17:00' } ] };
	const mondayState = evaluator.buildStatus( cfg, { ymd: '2026-08-03', dow: 1, minutes: 10 * 60 } );
	const tuesdayState = evaluator.buildStatus( cfg, { ymd: '2026-08-04', dow: 2, minutes: 10 * 60 } );
	assert.equal( mondayState.main, tuesdayState.main );
	assert.equal( mondayState.sub, tuesdayState.sub );
	assert.notEqual( mondayState.occurrence, tuesdayState.occurrence );
	assert.equal( evaluator.liveSignature( 'cfg', mondayState ), evaluator.liveSignature( 'cfg', mondayState ) );
	assert.notEqual( evaluator.liveSignature( 'cfg', mondayState ), evaluator.liveSignature( 'cfg', tuesdayState ) );
	assert.notEqual( evaluator.dismissalStorageKey( { siteKey: '1' } ), evaluator.dismissalStorageKey( { siteKey: '2' } ) );
	assert.equal( evaluator.dismissalStorageKey( { siteKey: '1' } ), evaluator.dismissalStorageKey( { siteKey: '1' } ) );
} );

test( 'fixed-offset sites follow the shipped offset table instead of a frozen offset', function () {
	// UTC-9 (Alaska standard time) with no IANA zone: the plain offset branch.
	const fixed = { tz: '', offset: -540 };
	assert.deepEqual(
		evaluator.siteNow( fixed, new Date( '2026-10-20T03:00:00Z' ) ),
		{ ymd: '2026-10-19', dow: 1, minutes: 18 * 60, epochMs: Date.parse( '2026-10-20T03:00:00Z' ) }
	);
	// wp_localize_script casts top-level scalars to strings.
	assert.equal( evaluator.siteNow( { tz: '', offset: '-540' }, new Date( '2026-10-20T03:00:00Z' ) ).minutes, 18 * 60 );
	assert.equal( evaluator.siteNow( { tz: '', offset: 330 }, new Date( '2026-10-20T03:00:00Z' ) ).minutes, 8 * 60 + 30 );

	// A cached page generated during AKDT (-480) keeps the right offset after
	// the 1 November 2026 change to AKST (-540) via the transition table.
	const anchorageChange = Date.UTC( 2026, 10, 1, 10, 0, 0 ) / 1000;
	const table = { tz: '', offset: -480, offsets: [ [ 0, -480 ], [ anchorageChange, -540 ] ] };
	assert.equal( evaluator.fallbackOffset( table, new Date( '2026-10-20T20:00:00Z' ) ), -480 );
	assert.equal( evaluator.fallbackOffset( table, new Date( '2026-11-02T20:00:00Z' ) ), -540 );
	assert.equal( evaluator.siteNow( table, new Date( '2026-11-02T20:00:00Z' ) ).minutes, 11 * 60 );

	// The IANA path is DST-correct on its own: 10:00 AKDT at the same instant.
	assert.equal( evaluator.siteNow( { tz: 'America/Anchorage' }, new Date( '2026-10-20T18:00:00Z' ) ).minutes, 10 * 60 );
	assert.equal( evaluator.siteNow( { tz: 'America/Anchorage' }, new Date( '2026-11-02T18:00:00Z' ) ).minutes, 9 * 60 );
} );

test( 'admin timezone helpers parse WordPress manual offsets and overnight hints', function () {
	assert.equal( adminEvaluator.utcOffsetMinutes( 'UTC-9' ), -540 );
	assert.equal( adminEvaluator.utcOffsetMinutes( 'UTC+5.75' ), 345 );
	assert.equal( adminEvaluator.utcOffsetMinutes( 'UTC+0' ), 0 );
	assert.equal( adminEvaluator.utcOffsetMinutes( 'UTC' ), null );
	assert.equal( adminEvaluator.utcOffsetMinutes( 'America/Anchorage' ), null );
	assert.equal( adminEvaluator.isOvernight( '22:00', '02:00' ), true );
	assert.equal( adminEvaluator.isOvernight( '09:00', '17:00' ), false );
	assert.equal( adminEvaluator.isOvernight( '09:00', '24:00' ), false );
	assert.equal( adminEvaluator.rangeHint( '22:00', '02:00' ), 'closes 02:00 the next day' );
	assert.equal( adminEvaluator.rangeHint( '18:00', '00:00' ), 'until midnight' );
	assert.equal( adminEvaluator.rangeHint( '09:00', '17:00' ), '' );
	assert.deepEqual( adminEvaluator.copyTargets( 'weekdays' ), [ 2, 3, 4, 5 ] );
	assert.deepEqual( adminEvaluator.copyTargets( 'all' ), [ 2, 3, 4, 5, 6, 0 ] );
} );

test( 'UK bank-holiday generator applies Easter and substitute-day rules', function () {
	assert.equal( adminEvaluator.easterSunday( 2026 ).toISOString().slice( 0, 10 ), '2026-04-05' );
	assert.equal( adminEvaluator.easterSunday( 2027 ).toISOString().slice( 0, 10 ), '2027-03-28' );
	const ew2026 = adminEvaluator.standardBankHolidays( 2026, 'england-wales' ).map( function ( h ) { return h.date; } );
	assert.ok( ew2026.includes( '2026-04-03' ), 'Good Friday 2026' );
	assert.ok( ew2026.includes( '2026-04-06' ), 'Easter Monday 2026' );
	assert.ok( ew2026.includes( '2026-12-25' ), 'Christmas Day 2026 is a Friday' );
	assert.ok( ew2026.includes( '2026-12-28' ), 'Boxing Day 2026 falls on a Saturday and moves to Monday' );
	const ew2027 = adminEvaluator.standardBankHolidays( 2027, 'england-wales' ).map( function ( h ) { return h.date; } );
	assert.ok( ew2027.includes( '2027-12-27' ) && ew2027.includes( '2027-12-28' ), 'Christmas 2027 weekend moves to Monday and Tuesday' );
	const scot2022 = adminEvaluator.standardBankHolidays( 2022, 'scotland' ).map( function ( h ) { return h.date; } );
	assert.ok( scot2022.includes( '2022-01-03' ) && scot2022.includes( '2022-01-04' ), 'Scotland 2022 New Year weekend moves to Monday and Tuesday' );
	assert.ok( ! scot2022.includes( '2022-04-18' ), 'Scotland has no Easter Monday' );
} );

test( 'midnight closes, translatable joiners, 24:00 display, and entity handling match PHP', function () {
	const cfg = baseConfig();
	cfg.schedule[ 1 ] = { closed: 0, ranges: [ { open: '18:00', close: '00:00' } ] };
	assert.equal( evaluator.buildStatus( cfg, { ymd: '2026-08-03', dow: 1, minutes: 20 * 60 } ).sub, 'Closes at 00:00' );
	// Just after midnight on a full day, the next midnight keeps its date.
	const fullDay = baseConfig();
	fullDay.schedule[ 1 ] = { closed: 0, ranges: [ { open: '00:00', close: '24:00' } ] };
	assert.equal( evaluator.buildStatus( fullDay, { ymd: '2026-08-03', dow: 1, minutes: 5 } ).sub, 'Closes at Tue 4 Aug, 00:00' );

	assert.equal( evaluator.fmtRanges( cfg, [ { open: '10:00', close: '24:00' } ] ), '10:00 – 23:59' );
	assert.equal( evaluator.fmtRanges( cfg, [ { open: '00:00', close: '24:00' } ] ), 'Open 24 hours' );
	cfg.formats = { range: '%1$s to %2$s', ranges: ' / ', dateNote: '%1$s [%2$s]', dateTime: '%2$s on %1$s' };
	assert.equal( evaluator.fmtRanges( cfg, [ { open: '09:00', close: '12:00' }, { open: '13:00', close: '17:00' } ] ), '09:00 to 12:00 / 13:00 to 17:00' );
	cfg.options.show_upcoming = 1;
	cfg.holidays[ '2026-08-20' ] = { closed: 0, ranges: [ { open: '10:00', close: '14:00' } ], label: 'Late' };
	assert.equal( evaluator.buildStatus( cfg, { ymd: '2026-08-03', dow: 1, minutes: 12 * 60 } ).upcoming, 'Thu 20 Aug [Late]: 10:00 to 14:00' );
	cfg.schedule[ 1 ] = { closed: 0, ranges: [ { open: '22:00', close: '02:00' } ] };
	assert.equal( evaluator.buildStatus( cfg, { ymd: '2026-08-03', dow: 1, minutes: 23 * 60 } ).sub, 'Closes at 02:00 on Tue 4 Aug' );

	// sanitize_text_field() stores "<3" as "&lt;3"; esc_html() does not double-encode.
	assert.equal( evaluator.escapeHtml( 'Open till late &lt;3 & more' ), 'Open till late &lt;3 &amp; more' );
	assert.equal( evaluator.escapeHtml( '<b>' ), '&lt;b&gt;' );
	assert.equal( evaluator.decodeEntities( 'Open till late &lt;3 &amp; more' ), 'Open till late <3 & more' );
} );

test( 'admin preview helpers avoid unchanged live-region rewrites', function () {
	global.window.IBOH.defaultLabels = { open: "We're open" };
	const holidayRows = [
		{ querySelector: function () { return { value: '2026-08-03' }; } },
		{ querySelector: function () { return { value: '2026-08-04' }; } },
		{ querySelector: function () { return { value: '' }; } }
	];
	assert.equal( adminEvaluator.holidayCountForLimit( {
		querySelectorAll: function () { return holidayRows; }
	}, '2026-08-04' ), 2 );
	assert.equal( adminEvaluator.previousYmd( '2026-08-05' ), '2026-08-04' );
	assert.equal( adminEvaluator.shouldIncludePreviewDate( '2026-08-04', '2026-08-05' ), true );
	assert.equal( adminEvaluator.shouldIncludePreviewDate( '2026-08-03', '2026-08-05' ), false );
	assert.equal( adminEvaluator.previewLabelValue( 'open', '   ' ), "We're open" );
	assert.equal( adminEvaluator.previewLabelValue( 'open', 'Come in' ), 'Come in' );

	function fakeElement() {
		let text = '';
		let html = '';
		let writes = 0;
		return {
			get textContent() { return text; },
			set textContent( value ) { text = String( value ); html = text; writes++; },
			get innerHTML() { return html; },
			set innerHTML( value ) {
				html = String( value ).replace( /&#0*39;/g, "'" ).replace( /&quot;/g, '"' );
				text = html.replace( /<[^>]*>/g, '' );
				writes++;
			},
			get childElementCount() { return /<[^>]+>/.test( html ) ? 1 : 0; },
			get writes() { return writes; }
		};
	}

	const main = fakeElement();
	const sub = fakeElement();
	const upcoming = fakeElement();
	const state = {
		show: true,
		main: 'Open',
		sub: 'Closes at 17:00',
		upcoming: '',
		upcomingHtml: '<strong>Friday&#039;s hours</strong>'
	};
	adminEvaluator.updatePreviewContent( main, sub, upcoming, state );
	const writes = main.writes + sub.writes + upcoming.writes;
	adminEvaluator.updatePreviewContent( main, sub, upcoming, state );
	assert.equal( main.writes + sub.writes + upcoming.writes, writes );

	adminEvaluator.updatePreviewContent( main, sub, upcoming, {
		show: true,
		main: state.main,
		sub: state.sub,
		upcoming: '',
		upcomingHtml: ''
	} );
	assert.equal( upcoming.textContent, '' );
	const clearedWrites = upcoming.writes;
	adminEvaluator.updatePreviewContent( main, sub, upcoming, state );
	assert.equal( upcoming.writes, clearedWrites + 1 );
	assert.match( upcoming.innerHTML, /Friday's hours/ );
} );
