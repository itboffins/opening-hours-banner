/* ITBoffins Live Opening Hours — admin editor (vanilla JS, no jQuery). */
( function () {
	'use strict';

	var i18n = ( window.IBOH && window.IBOH.i18n ) || {};
	var optionName = ( window.IBOH && window.IBOH.optionName ) || 'iboh_settings';
	var missingDateMessage = i18n.missingDate || 'Choose a date, or remove this special-date row.';
	var uid = Date.now(); // Unique-enough indices for newly added rows.

	function nextIndex() {
		uid += 1;
		return uid;
	}

	function assignFieldName( input, base ) {
		if ( ! input || ! base || ! input.hasAttribute( 'data-field' ) ) {
			return;
		}
		input.name = base + '[' + input.getAttribute( 'data-field' ) + ']';
		input.removeAttribute( 'data-field' );
	}

	function holidayBaseFromExistingName( row ) {
		var named = row.querySelector( 'input[name$="[date]"], input[name$="[label]"], input[name$="[closed]"], input[name$="[source]"]' );
		var name = named ? named.getAttribute( 'name' ) : '';
		return name ? name.replace( /\[(date|label|closed|source)\]$/, '' ) : '';
	}

	function ensureHolidayRowNames( row ) {
		if ( ! row ) {
			return;
		}
		var base = row.getAttribute( 'data-name' ) || holidayBaseFromExistingName( row );
		if ( ! base ) {
			base = optionName + '[holidays][' + nextIndex() + ']';
			row.setAttribute( 'data-name', base );
		}

		row.querySelectorAll( '[data-field]' ).forEach( function ( input ) {
			assignFieldName( input, base );
		} );

		var ranges = row.querySelector( '[data-iboh-ranges], .iboh-ranges' );
		if ( ranges ) {
			ranges.classList.add( 'iboh-ranges' );
			ranges.setAttribute( 'data-name', base + '[ranges]' );
			ranges.removeAttribute( 'data-iboh-ranges' );
		}
	}

	function sync24hRange( row ) {
		if ( ! row ) {
			return;
		}
		var toggle = row.querySelector( '.iboh-24h-toggle' );
		var is24h = !! ( toggle && toggle.checked );
		var hiddenOpen = row.querySelector( '[data-iboh-24h-open]' );
		var hiddenClose = row.querySelector( '[data-iboh-24h-close]' );
		var timeOpen = row.querySelector( '[data-iboh-time-open]' );
		var timeClose = row.querySelector( '[data-iboh-time-close]' );

		if ( hiddenOpen ) { hiddenOpen.disabled = ! is24h; }
		if ( hiddenClose ) { hiddenClose.disabled = ! is24h; }
		if ( timeOpen ) {
			timeOpen.disabled = is24h;
			if ( is24h ) { timeOpen.value = '00:00'; }
		}
		if ( timeClose ) {
			timeClose.disabled = is24h;
			if ( is24h ) { timeClose.value = '23:59'; }
		}
	}

	function setRangeValues( row, open, close ) {
		var is24h = ( open === '00:00' && close === '24:00' );
		var toggle = row.querySelector( '.iboh-24h-toggle' );
		var timeOpen = row.querySelector( '[data-iboh-time-open]' );
		var timeClose = row.querySelector( '[data-iboh-time-close]' );

		if ( toggle ) { toggle.checked = is24h; }
		if ( timeOpen ) { timeOpen.value = open || ''; }
		if ( timeClose ) { timeClose.value = is24h ? '23:59' : ( close || '' ); }
		sync24hRange( row );
	}

	/* ---- Range rows ------------------------------------------------------- */

	function addRange( container ) {
		var tpl = document.getElementById( 'iboh-range-tpl' );
		if ( ! tpl || ! container ) {
			return;
		}
		var node = tpl.content.firstElementChild.cloneNode( true );
		var base = container.getAttribute( 'data-name' );
		var idx = nextIndex();
		node.querySelectorAll( '[data-field]' ).forEach( function ( input ) {
			assignFieldName( input, base + '[' + idx + ']' );
		} );
		sync24hRange( node );
		container.appendChild( node );
		schedulePreview();
	}

	/* ---- Holiday rows ----------------------------------------------------- */

	function addHoliday( date, label, closed, source ) {
		var tpl = document.getElementById( 'iboh-holiday-tpl' );
		var list = document.getElementById( 'iboh-holidays' );
		if ( ! tpl || ! list ) {
			return null;
		}
		var node = tpl.content.firstElementChild.cloneNode( true );
		node.setAttribute( 'data-name', optionName + '[holidays][' + nextIndex() + ']' );
		ensureHolidayRowNames( node );

		var dateInput = node.querySelector( 'input[type="date"]' );
		if ( dateInput && date ) {
			dateInput.value = date;
		}

		var labelInput = node.querySelector( '.iboh-holiday-label' );
		if ( labelInput && label ) {
			labelInput.value = label;
		}

		var sourceInput = node.querySelector( '.iboh-holiday-source' );
		if ( sourceInput ) {
			sourceInput.value = ( 'bank_holiday' === source ) ? 'bank_holiday' : '';
		}

		var closedToggle = node.querySelector( '.iboh-closed-toggle' );
		if ( closedToggle ) {
			closedToggle.checked = ( closed !== false );
			toggleClosed( closedToggle );
		}

		list.appendChild( node );
		schedulePreview();
		return node;
	}

	/* ---- Standard UK bank holidays ---------------------------------------- */

	function bankHolidayLabel( key, fallback ) {
		var labels = i18n.bankHolidayLabels || {};
		return labels[ key ] || fallback;
	}

	function dateUtc( year, month, day ) {
		return new Date( Date.UTC( year, month - 1, day ) );
	}

	function addDays( date, days ) {
		var out = new Date( date.getTime() );
		out.setUTCDate( out.getUTCDate() + days );
		return out;
	}

	function ymdFromDate( date ) {
		var y = date.getUTCFullYear();
		var m = String( date.getUTCMonth() + 1 ).padStart( 2, '0' );
		var d = String( date.getUTCDate() ).padStart( 2, '0' );
		return y + '-' + m + '-' + d;
	}

	function isWeekday( date ) {
		var day = date.getUTCDay();
		return day > 0 && day < 6;
	}

	function nextWeekdayNotUsed( date, used ) {
		var out = new Date( date.getTime() );
		while ( ! isWeekday( out ) || used[ ymdFromDate( out ) ] ) {
			out = addDays( out, 1 );
		}
		return out;
	}

	function addObserved( out, used, date, label ) {
		var ymd = ymdFromDate( date );
		used[ ymd ] = true;
		out.push( { date: ymd, label: label } );
	}

	function addObservedGroup( out, used, items ) {
		var pending = [];
		items.forEach( function ( item ) {
			var ymd = ymdFromDate( item.date );
			if ( isWeekday( item.date ) && ! used[ ymd ] ) {
				addObserved( out, used, item.date, item.label );
			} else {
				pending.push( item );
			}
		} );
		pending.forEach( function ( item ) {
			addObserved( out, used, nextWeekdayNotUsed( item.date, used ), item.label );
		} );
	}

	function firstWeekdayInMonth( year, month, weekday ) {
		var date = dateUtc( year, month, 1 );
		while ( date.getUTCDay() !== weekday ) {
			date = addDays( date, 1 );
		}
		return date;
	}

	function lastWeekdayInMonth( year, month, weekday ) {
		var date = dateUtc( year, month + 1, 0 );
		while ( date.getUTCDay() !== weekday ) {
			date = addDays( date, -1 );
		}
		return date;
	}

	function easterSunday( year ) {
		var a = year % 19;
		var b = Math.floor( year / 100 );
		var c = year % 100;
		var d = Math.floor( b / 4 );
		var e = b % 4;
		var f = Math.floor( ( b + 8 ) / 25 );
		var g = Math.floor( ( b - f + 1 ) / 3 );
		var h = ( 19 * a + b - d - g + 15 ) % 30;
		var i = Math.floor( c / 4 );
		var k = c % 4;
		var l = ( 32 + 2 * e + 2 * i - h - k ) % 7;
		var m = Math.floor( ( a + 11 * h + 22 * l ) / 451 );
		var month = Math.floor( ( h + l - 7 * m + 114 ) / 31 );
		var day = ( ( h + l - 7 * m + 114 ) % 31 ) + 1;
		return dateUtc( year, month, day );
	}

	function standardBankHolidays( year, region ) {
		var out = [];
		var used = {};
		var easter = easterSunday( year );

		if ( 'scotland' === region ) {
			addObservedGroup( out, used, [
				{ date: dateUtc( year, 1, 1 ), label: bankHolidayLabel( 'new_year', "New Year's Day" ) },
				{ date: dateUtc( year, 1, 2 ), label: bankHolidayLabel( 'jan_second', '2 January' ) }
			] );
		} else {
			addObservedGroup( out, used, [
				{ date: dateUtc( year, 1, 1 ), label: bankHolidayLabel( 'new_year', "New Year's Day" ) }
			] );
		}

		if ( 'northern-ireland' === region ) {
			addObservedGroup( out, used, [
				{ date: dateUtc( year, 3, 17 ), label: bankHolidayLabel( 'st_patrick', "St Patrick's Day" ) }
			] );
		}

		addObserved( out, used, addDays( easter, -2 ), bankHolidayLabel( 'good_friday', 'Good Friday' ) );

		if ( 'scotland' !== region ) {
			addObserved( out, used, addDays( easter, 1 ), bankHolidayLabel( 'easter_mon', 'Easter Monday' ) );
		}

		addObserved( out, used, firstWeekdayInMonth( year, 5, 1 ), bankHolidayLabel( 'early_may', 'Early May bank holiday' ) );
		addObserved( out, used, lastWeekdayInMonth( year, 5, 1 ), bankHolidayLabel( 'spring', 'Spring bank holiday' ) );

		if ( 'northern-ireland' === region ) {
			addObservedGroup( out, used, [
				{ date: dateUtc( year, 7, 12 ), label: bankHolidayLabel( 'boyne', 'Battle of the Boyne' ) }
			] );
		}

		addObserved(
			out,
			used,
			'scotland' === region ? firstWeekdayInMonth( year, 8, 1 ) : lastWeekdayInMonth( year, 8, 1 ),
			bankHolidayLabel( 'summer', 'Summer bank holiday' )
		);

		if ( 'scotland' === region ) {
			addObservedGroup( out, used, [
				{ date: dateUtc( year, 11, 30 ), label: bankHolidayLabel( 'st_andrew', "St Andrew's Day" ) }
			] );
		}

		addObservedGroup( out, used, [
			{ date: dateUtc( year, 12, 25 ), label: bankHolidayLabel( 'christmas', 'Christmas Day' ) },
			{ date: dateUtc( year, 12, 26 ), label: bankHolidayLabel( 'boxing', 'Boxing Day' ) }
		] );

		return out.sort( function ( a, b ) { return a.date.localeCompare( b.date ); } );
	}

	function existingHolidayRows() {
		var rows = {};
		document.querySelectorAll( '[data-iboh-holiday]' ).forEach( function ( row ) {
			var dateInput = row.querySelector( 'input[type="date"]' );
			var sourceInput = row.querySelector( '.iboh-holiday-source' );
			if ( dateInput && dateInput.value ) {
				rows[ dateInput.value ] = {
					row: row,
					source: sourceInput ? sourceInput.value : ''
				};
			}
		} );
		return rows;
	}

	function sortHolidayRows() {
		var list = document.getElementById( 'iboh-holidays' );
		if ( ! list ) {
			return;
		}
		Array.prototype.slice.call( list.querySelectorAll( '[data-iboh-holiday]' ) )
			.sort( function ( a, b ) {
				var ad = ( a.querySelector( 'input[type="date"]' ) || {} ).value || '9999-12-31';
				var bd = ( b.querySelector( 'input[type="date"]' ) || {} ).value || '9999-12-31';
				return ad.localeCompare( bd );
			} )
			.forEach( function ( row ) { list.appendChild( row ); } );
	}

	function bankHolidayMessage( added, replaced, skipped ) {
		if ( added < 1 && replaced < 1 ) {
			return i18n.bankHolidaysNone || 'Those bank holidays are already in your list.';
		}
		return String( i18n.bankHolidaysAdded || 'Added: %1$d. Replaced existing dates: %2$d. Already in your list: %3$d. Save changes when you are happy.' )
			.replace( '%1$d', added )
			.replace( '%2$d', replaced )
			.replace( '%3$d', skipped );
	}

	function addClosedBankHolidays() {
		var regionSelect = document.getElementById( 'iboh-bank-holiday-region' );
		var yearSelect = document.getElementById( 'iboh-bank-holiday-year' );
		var status = document.getElementById( 'iboh-bank-holiday-status' );
		var region = regionSelect ? regionSelect.value : 'england-wales';
		var year = yearSelect ? parseInt( yearSelect.value, 10 ) : new Date().getFullYear();
		var existing = existingHolidayRows();
		var added = 0;
		var replaced = 0;
		var skipped = 0;

		standardBankHolidays( year, region ).forEach( function ( holiday ) {
			if ( existing[ holiday.date ] ) {
				if ( 'bank_holiday' === existing[ holiday.date ].source ) {
					skipped += 1;
					return;
				}
				existing[ holiday.date ].row.remove();
				replaced += 1;
			}
			existing[ holiday.date ] = {
				row: addHoliday( holiday.date, holiday.label, true, 'bank_holiday' ),
				source: 'bank_holiday'
			};
			added += existing[ holiday.date ].row ? 1 : 0;
		} );

		sortHolidayRows();
		schedulePreview();
		if ( status ) {
			status.textContent = bankHolidayMessage( added, replaced, skipped );
		}
	}

	/* ---- Copy Monday to the other weekdays ------------------------------- */

	function copyWeekdays() {
		var monday = document.querySelector( '[data-iboh-day][data-dow="1"]' );
		if ( ! monday ) {
			return;
		}
		var mondayState = readDay( monday );
		[ 2, 3, 4, 5 ].forEach( function ( dow ) {
			var row = document.querySelector( '[data-iboh-day][data-dow="' + dow + '"]' );
			if ( ! row ) {
				return;
			}
			var toggle = row.querySelector( '.iboh-closed-toggle' );
			if ( toggle ) {
				toggle.checked = mondayState.closed;
				toggleClosed( toggle );
			}
			var container = row.querySelector( '.iboh-ranges' );
			if ( container ) {
				container.innerHTML = '';
				mondayState.ranges.forEach( function ( r ) {
					addRange( container );
					var rows = container.querySelectorAll( '.iboh-range' );
					var last = rows[ rows.length - 1 ];
					setRangeValues( last, r.open, r.close );
				} );
			}
		} );
		schedulePreview();
	}

	/* ---- Weekly hours master toggle (show/hide all the day rows) --------- */

	function toggleWeekly( checkbox ) {
		var body = document.querySelector( '.iboh-weekly-body' );
		if ( body ) {
			body.style.display = checkbox.checked ? '' : 'none';
		}
	}

	/* ---- Closed toggle (show/hide the hours body) ------------------------ */

	function toggleClosed( checkbox ) {
		var wrap = checkbox.closest( '[data-iboh-day], [data-iboh-holiday]' );
		if ( ! wrap ) {
			return;
		}
		var body = wrap.querySelector( '.iboh-day-body' );
		if ( body ) {
			body.style.display = checkbox.checked ? 'none' : '';
		}
	}

	/* ---- Reading the form into a config object --------------------------- */

	function rangesFrom( container ) {
		var out = [];
		if ( ! container ) {
			return out;
		}
		container.querySelectorAll( '.iboh-range' ).forEach( function ( row ) {
			var toggle = row.querySelector( '.iboh-24h-toggle' );
			if ( toggle && toggle.checked ) {
				out.push( { open: '00:00', close: '24:00' } );
				return;
			}

			var openInput = row.querySelector( '[data-iboh-time-open]' );
			var closeInput = row.querySelector( '[data-iboh-time-close]' );
			var open = openInput ? openInput.value : '';
			var close = closeInput ? closeInput.value : '';
			if ( open && close ) {
				out.push( { open: open, close: close } );
			}
		} );
		return out;
	}

	function readDay( row ) {
		var toggle = row.querySelector( '.iboh-closed-toggle' );
		var closed = toggle ? toggle.checked : false;
		return {
			closed: closed,
			ranges: closed ? [] : rangesFrom( row.querySelector( '.iboh-ranges' ) )
		};
	}

	function normaliseHolidayRowsForSubmit( event ) {
		var firstInvalid = null;

		document.querySelectorAll( '[data-iboh-holiday]' ).forEach( function ( row ) {
			ensureHolidayRowNames( row );

			var dateInput = row.querySelector( 'input[type="date"]' );
			if ( dateInput ) {
				dateInput.setCustomValidity( '' );
				if ( ! dateInput.value ) {
					dateInput.setCustomValidity( missingDateMessage );
					if ( ! firstInvalid ) {
						firstInvalid = dateInput;
					}
				}
			}

			var closedToggle = row.querySelector( '.iboh-closed-toggle' );
			if ( dateInput && dateInput.value && closedToggle && ! closedToggle.checked && ! rangesFrom( row.querySelector( '.iboh-ranges' ) ).length ) {
				closedToggle.checked = true;
				toggleClosed( closedToggle );
			}
		} );

		if ( firstInvalid ) {
			event.preventDefault();
			if ( firstInvalid.reportValidity ) {
				firstInvalid.reportValidity();
			}
			firstInvalid.focus();
		}
	}

	function buildConfig() {
		var cfg = {
			tz: window.IBOH ? window.IBOH.tz : '',
			offset: window.IBOH ? window.IBOH.offset : 0,
			timeFormat: window.IBOH ? window.IBOH.timeFormat : 'H:i',
			lang: window.IBOH ? window.IBOH.lang : '',
			dayNames: window.IBOH ? window.IBOH.dayNames : [],
			closedText: window.IBOH ? window.IBOH.closedText : 'Closed',
			schedule: {},
			holidays: {},
			labels: {},
			banner: {},
			options: {}
		};

		document.querySelectorAll( '[data-iboh-day]' ).forEach( function ( row ) {
			var dow = row.getAttribute( 'data-dow' );
			cfg.schedule[ dow ] = readDay( row );
		} );

		document.querySelectorAll( '[data-iboh-holiday]' ).forEach( function ( row ) {
			var dateInput = row.querySelector( 'input[type="date"]' );
			var date = dateInput ? dateInput.value : '';
			if ( ! date ) {
				return;
			}
			var labelInput = row.querySelector( '.iboh-holiday-label' );
			var sourceInput = row.querySelector( '.iboh-holiday-source' );
			var source = ( sourceInput && 'bank_holiday' === sourceInput.value ) ? 'bank_holiday' : '';
			if ( cfg.holidays[ date ] && 'bank_holiday' === cfg.holidays[ date ].source && 'bank_holiday' !== source ) {
				return;
			}
			cfg.holidays[ date ] = {
				closed: readDay( row ).closed,
				ranges: readDay( row ).ranges,
				label: labelInput ? labelInput.value : '',
				source: source
			};
		} );

		document.querySelectorAll( '[data-iboh-label]' ).forEach( function ( input ) {
			cfg.labels[ input.getAttribute( 'data-iboh-label' ) ] = input.value;
		} );

		document.querySelectorAll( '[data-iboh-banner-field]' ).forEach( function ( input ) {
			var key = input.getAttribute( 'data-iboh-banner-field' );
			cfg.banner[ key ] = ( 'checkbox' === input.type ) ? ( input.checked ? 1 : 0 ) : input.value;
		} );

		document.querySelectorAll( '[data-iboh-option]' ).forEach( function ( input ) {
			var key = input.getAttribute( 'data-iboh-option' );
			if ( 'checkbox' === input.type ) {
				cfg.options[ key ] = input.checked ? 1 : 0;
			} else if ( 'radio' === input.type ) {
				if ( input.checked ) {
					cfg.options[ key ] = input.value;
				}
			} else {
				cfg.options[ key ] = input.value;
			}
		} );

		return cfg;
	}

	/* ---- Customer preview ------------------------------------------------ */

	var previewTimer = null;

	function schedulePreview() {
		if ( previewTimer ) {
			window.clearTimeout( previewTimer );
		}
		previewTimer = window.setTimeout( renderPreview, 150 );
	}

	function renderPreview() {
		var box = document.getElementById( 'iboh-preview' );
		if ( ! box || ! window.IBOHEval ) {
			return;
		}
		var cfg = buildConfig();
		var s = window.IBOHEval.buildStatus( cfg, window.IBOHEval.siteNow( cfg ) );
		var main = box.querySelector( '[data-iboh-main]' );
		var sub = box.querySelector( '[data-iboh-sub]' );
		var up = box.querySelector( '[data-iboh-upcoming]' );

		if ( ! s.show ) {
			box.classList.remove( 'iboh-open' );
			box.classList.add( 'iboh-closed', 'iboh-preview-hidden' );
			box.style.background = '';
			box.style.color = '';
			if ( main ) { main.textContent = i18n.hidden || '—'; }
			if ( sub ) { sub.textContent = ''; }
			if ( up ) { up.textContent = ''; }
			return;
		}

		box.classList.remove( 'iboh-preview-hidden' );
		box.classList.toggle( 'iboh-open', s.open );
		box.classList.toggle( 'iboh-closed', ! s.open );
		box.style.background = s.open ? cfg.banner.colour_open : cfg.banner.colour_closed;
		box.style.color = cfg.banner.colour_text;
		if ( main ) { main.textContent = s.main; }
		if ( sub ) { sub.textContent = s.sub; }
		if ( up ) { up.innerHTML = s.upcomingHtml || s.upcoming || ''; }
	}

	/* ---- Wiring ---------------------------------------------------------- */

	function init() {
		var form = document.getElementById( 'iboh-form' );
		if ( form ) {
			form.addEventListener( 'submit', normaliseHolidayRowsForSubmit );
		}

		document.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( t.closest( '.iboh-add-range' ) ) {
				e.preventDefault();
				var body = t.closest( '.iboh-day-body' );
				addRange( body ? body.querySelector( '.iboh-ranges' ) : null );
			} else if ( t.closest( '.iboh-remove-range' ) ) {
				e.preventDefault();
				var range = t.closest( '.iboh-range' );
				if ( range ) { range.remove(); }
				schedulePreview();
			} else if ( t.closest( '.iboh-remove-holiday' ) ) {
				e.preventDefault();
				var holiday = t.closest( '.iboh-holiday' );
				if ( holiday ) { holiday.remove(); }
				schedulePreview();
			} else if ( t.id === 'iboh-add-holiday' ) {
				e.preventDefault();
				addHoliday();
			} else if ( t.id === 'iboh-add-bank-holidays' ) {
				e.preventDefault();
				addClosedBankHolidays();
			} else if ( t.id === 'iboh-copy-weekdays' ) {
				e.preventDefault();
				copyWeekdays();
			}
		} );

		document.addEventListener( 'change', function ( e ) {
			if ( e.target.classList.contains( 'iboh-24h-toggle' ) ) {
				sync24hRange( e.target.closest( '.iboh-range' ) );
			} else if ( e.target.classList.contains( 'iboh-closed-toggle' ) ) {
				toggleClosed( e.target );
			} else if ( 'iboh-weekly-enabled' === e.target.id ) {
				toggleWeekly( e.target );
			}
			schedulePreview();
		} );

		document.addEventListener( 'input', function () {
			schedulePreview();
		} );

		document.querySelectorAll( '.iboh-range' ).forEach( sync24hRange );
		renderPreview();
		// Keep the "soon"/transition wording live even with no edits.
		window.setInterval( renderPreview, 30000 );
	}

	if ( 'loading' !== document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
} )();
