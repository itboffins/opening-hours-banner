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

test( 'admin preview retains yesterday carry and avoids unchanged live-region rewrites', function () {
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

	global.window.IBOH.previewCarry = {
		'2026-08-03': { closed: 1, ranges: [], label: 'Closed override' }
	};
	const suppressed = baseConfig();
	suppressed.schedule[ 1 ] = {
		closed: 0,
		ranges: [ { open: '22:00', close: '02:00' } ]
	};
	suppressed.holidays = adminEvaluator.previewCarryHolidays();
	assert.equal(
		evaluator.evaluate( suppressed, { ymd: '2026-08-04', dow: 2, minutes: 30 } ).open,
		false
	);

	global.window.IBOH.previewCarry = {
		'2026-08-03': {
			closed: 0,
			ranges: [ { open: '22:00', close: '02:00' } ],
			label: 'Late opening'
		}
	};
	const overnight = baseConfig();
	overnight.holidays = adminEvaluator.previewCarryHolidays();
	assert.equal(
		evaluator.evaluate( overnight, { ymd: '2026-08-04', dow: 2, minutes: 30 } ).open,
		true
	);

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
