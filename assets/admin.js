/* ITBoffins Live Opening Hours — admin editor (vanilla JS, no jQuery). */
( function () {
	'use strict';

	var i18n = ( window.IBOH && window.IBOH.i18n ) || {};
	var optionName = ( window.IBOH && window.IBOH.optionName ) || 'iboh_settings';
	var missingDateMessage = i18n.missingDate || 'Choose a date, or remove this special-date row.';
	var maxRanges = Math.max( 1, parseInt( ( window.IBOH && window.IBOH.maxRanges ) || 6, 10 ) );
	var maxHolidays = Math.max( 1, parseInt( ( window.IBOH && window.IBOH.maxHolidays ) || 200, 10 ) );
	var rangeLimitMessage = i18n.rangeLimit || ( 'You can add up to ' + maxRanges + ' time ranges here.' );
	var holidayLimitMessage = i18n.holidayLimit || ( 'You can add up to ' + maxHolidays + ' public special dates.' );
	var limitReachedMessage = i18n.limitReached || 'Limit reached.';
	var holidayLimitReachedMessage = i18n.holidayLimitReached || 'The public special-date limit has been reached. Remove a date before adding another.';
	var uid = Date.now(); // Unique-enough indices for newly added rows.

	function nextIndex() {
		uid += 1;
		return uid;
	}

	function focusControl( container, selector ) {
		var target = container && container.querySelector ? container.querySelector( selector ) : null;
		if ( target && ! target.disabled && 'function' === typeof target.focus ) {
			target.focus();
			return true;
		}
		return false;
	}

	function setLimitState( button, reached ) {
		if ( ! button ) {
			return;
		}
		button.disabled = reached;
		button.setAttribute( 'aria-disabled', reached ? 'true' : 'false' );
		if ( reached ) {
			button.setAttribute( 'title', limitReachedMessage );
		} else {
			button.removeAttribute( 'title' );
		}
	}

	function updateRangeLimit( container ) {
		if ( ! container ) {
			return;
		}
		var body = container.closest( '.iboh-day-body' );
		var count = container.querySelectorAll( '.iboh-range' ).length;
		var reached = count >= maxRanges;
		var button = body ? body.querySelector( '.iboh-add-range' ) : null;
		var note = body ? body.querySelector( '.iboh-limit-note' ) : null;
		setLimitState( button, reached );
		if ( note ) {
			var message = rangeLimitMessage + ( reached ? ( ' ' + limitReachedMessage ) : '' );
			if ( note.textContent.trim() !== message ) {
				note.textContent = message;
			}
		}
	}

	function updateAllRangeLimits() {
		document.querySelectorAll( '.iboh-ranges' ).forEach( updateRangeLimit );
	}

	function holidayCountForLimit( list, today ) {
		var currentDate = today || siteTodayYmd();
		return Array.prototype.filter.call( list.querySelectorAll( '[data-iboh-holiday]' ), function ( row ) {
			var dateInput = row.querySelector( 'input[type="date"]' );
			return ! dateInput || ! dateInput.value || dateInput.value >= currentDate;
		} ).length;
	}

	function updateHolidayLimit() {
		var list = document.getElementById( 'iboh-holidays' );
		if ( ! list ) {
			return;
		}
		var reached = holidayCountForLimit( list ) >= maxHolidays;
		setLimitState( document.getElementById( 'iboh-add-holiday' ), reached );
		setLimitState( document.getElementById( 'iboh-add-bank-holidays' ), reached );
		var note = document.getElementById( 'iboh-holiday-limit' );
		if ( note ) {
			var message = holidayLimitMessage + ( reached ? ( ' ' + limitReachedMessage ) : '' );
			if ( note.textContent.trim() !== message ) {
				note.textContent = message;
			}
		}
	}

	function assignFieldName( input, base ) {
		if ( ! input || ! base || ! input.hasAttribute( 'data-field' ) ) {
			return;
		}
		input.name = base + '[' + input.getAttribute( 'data-field' ) + ']';
		input.removeAttribute( 'data-field' );
	}

	function formatTemplate( template, values ) {
		var nextValue = 0;
		return String( template ).replace( /%(?:(\d+)\$)?[sd]/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : nextValue++;
			return values[ index ] === undefined ? match : String( values[ index ] );
		} );
	}

	function rangeContext( container ) {
		var dayRow = container ? container.closest( '[data-iboh-day]' ) : null;
		var dayName = dayRow ? dayRow.querySelector( '.iboh-day-name' ) : null;
		if ( dayName && dayName.textContent.trim() ) {
			return dayName.textContent.trim();
		}

		var holiday = container ? container.closest( '[data-iboh-holiday]' ) : null;
		var dateInput = holiday ? holiday.querySelector( 'input[type="date"]' ) : null;
		if ( dateInput && dateInput.value ) {
			return formatTemplate(
				i18n.publicSpecialDateContext || 'public special date %s',
				[ dateInput.value ]
			);
		}
		return i18n.newPublicSpecialDateContext || 'New public special date';
	}

	function syncRangeLabels( container ) {
		if ( ! container ) {
			return;
		}
		var context = rangeContext( container );
		Array.prototype.slice.call( container.querySelectorAll( '.iboh-range' ) ).forEach( function ( row, index ) {
			var rangeNumber = index + 1;
			var openInput = row.querySelector( '[data-iboh-time-open]' );
			var closeInput = row.querySelector( '[data-iboh-time-close]' );
			var removeButton = row.querySelector( '.iboh-remove-range' );

			row.setAttribute( 'aria-label', formatTemplate(
				i18n.rangeGroupLabel || 'Time range %1$d for %2$s',
				[ rangeNumber, context ]
			) );
			if ( openInput ) {
				openInput.setAttribute( 'aria-label', formatTemplate(
					i18n.rangeOpenLabel || 'Opening time for %1$s, range %2$d',
					[ context, rangeNumber ]
				) );
			}
			if ( closeInput ) {
				closeInput.setAttribute( 'aria-label', formatTemplate(
					i18n.rangeCloseLabel || 'Closing time for %1$s, range %2$d',
					[ context, rangeNumber ]
				) );
			}
			if ( removeButton ) {
				removeButton.setAttribute( 'aria-label', formatTemplate(
					i18n.rangeRemoveLabel || 'Remove time range %1$d for %2$s',
					[ rangeNumber, context ]
				) );
			}
		} );
	}

	function syncHolidayContext( row ) {
		if ( ! row ) {
			return;
		}
		var ranges = row.querySelector( '.iboh-ranges' );
		var context = rangeContext( ranges );
		row.setAttribute( 'aria-label', context );
		if ( ranges ) {
			ranges.setAttribute( 'aria-label', formatTemplate(
				i18n.timeRangesForContext || 'Time ranges for %s',
				[ context ]
			) );
			syncRangeLabels( ranges );
		}
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

	function addRange( container, moveFocus ) {
		var tpl = document.getElementById( 'iboh-range-tpl' );
		if ( ! tpl || ! container ) {
			return null;
		}
		if ( container.querySelectorAll( '.iboh-range' ).length >= maxRanges ) {
			updateRangeLimit( container );
			return null;
		}
		var node = tpl.content.firstElementChild.cloneNode( true );
		var base = container.getAttribute( 'data-name' );
		var idx = nextIndex();
		node.querySelectorAll( '[data-field]' ).forEach( function ( input ) {
			assignFieldName( input, base + '[' + idx + ']' );
		} );
		sync24hRange( node );
		container.appendChild( node );
		syncRangeLabels( container );
		updateRangeLimit( container );
		schedulePreview();
		if ( false !== moveFocus ) {
			focusControl( node, '[data-iboh-time-open]:not([disabled]), .iboh-24h-toggle:not([disabled])' );
		}
		return node;
	}

	/* ---- Holiday rows ----------------------------------------------------- */

	function addHoliday( date, label, closed, source, moveFocus ) {
		var tpl = document.getElementById( 'iboh-holiday-tpl' );
		var list = document.getElementById( 'iboh-holidays' );
		if ( ! tpl || ! list ) {
			return null;
		}
		if ( holidayCountForLimit( list ) >= maxHolidays ) {
			updateHolidayLimit();
			return null;
		}
		var node = tpl.content.firstElementChild.cloneNode( true );
		var rowIndex = nextIndex();
		node.setAttribute( 'data-name', optionName + '[holidays][' + rowIndex + ']' );
		ensureHolidayRowNames( node );

		var rangeLimitNote = node.querySelector( '[data-iboh-range-limit-note]' );
		var addRangeButton = node.querySelector( '.iboh-add-range' );
		if ( rangeLimitNote ) {
			rangeLimitNote.id = 'iboh-holiday-' + rowIndex + '-range-limit';
		}
		if ( addRangeButton && rangeLimitNote ) {
			addRangeButton.setAttribute( 'aria-describedby', rangeLimitNote.id );
		}

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
		syncHolidayContext( node );
		updateRangeLimit( node.querySelector( '.iboh-ranges' ) );
		updateHolidayLimit();
		schedulePreview();
		if ( false !== moveFocus && dateInput ) {
			dateInput.focus();
		}
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

	function siteTodayYmd() {
		if ( window.IBOHEval && window.IBOHEval.siteNow ) {
			return window.IBOHEval.siteNow( {
				tz: window.IBOH ? window.IBOH.tz : '',
				offset: window.IBOH ? window.IBOH.offset : 0
			} ).ymd;
		}
		return ymdFromDate( new Date() );
	}

	function previousYmd( today ) {
		var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( today || '' ) );
		if ( ! match ) {
			return String( today || '' );
		}
		return ymdFromDate( addDays( dateUtc( +match[ 1 ], +match[ 2 ], +match[ 3 ] ), -1 ) );
	}

	function shouldIncludePreviewDate( date, today ) {
		return !! date && date >= previousYmd( today );
	}

	function previewLabelValue( key, value ) {
		var text = value === undefined || value === null ? '' : String( value );
		if ( '' !== text.trim() ) {
			return text;
		}
		var defaults = ( window.IBOH && window.IBOH.defaultLabels ) || {};
		return defaults[ key ] === undefined || defaults[ key ] === null ? '' : String( defaults[ key ] );
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

	function bankHolidayMessage( added, replaced, skipped, limitReached ) {
		if ( limitReached && added < 1 && replaced < 1 ) {
			return holidayLimitReachedMessage;
		}
		if ( added < 1 && replaced < 1 ) {
			return i18n.bankHolidaysNone || 'Those bank holidays are already in your list.';
		}
		var message = String( i18n.bankHolidaysAdded || 'Added: %1$d. Replaced existing dates: %2$d. Already in your list: %3$d. Save changes when you are happy.' )
			.replace( '%1$d', added )
			.replace( '%2$d', replaced )
			.replace( '%3$d', skipped );
		return message + ( limitReached ? ( ' ' + holidayLimitReachedMessage ) : '' );
	}

	function addClosedBankHolidays() {
		var regionSelect = document.getElementById( 'iboh-bank-holiday-region' );
		var yearSelect = document.getElementById( 'iboh-bank-holiday-year' );
		var status = document.getElementById( 'iboh-bank-holiday-status' );
		var region = regionSelect ? regionSelect.value : 'england-wales';
		var year = yearSelect ? parseInt( yearSelect.value, 10 ) : new Date().getFullYear();
		var today = siteTodayYmd();
		var existing = existingHolidayRows();
		var added = 0;
		var replaced = 0;
		var skipped = 0;
		var limitReached = false;

		standardBankHolidays( year, region ).forEach( function ( holiday ) {
			if ( holiday.date < today ) {
				return;
			}
			if ( existing[ holiday.date ] ) {
				if ( 'bank_holiday' === existing[ holiday.date ].source ) {
					skipped += 1;
					return;
				}
				existing[ holiday.date ].row.remove();
				replaced += 1;
			}
			var addedRow = addHoliday( holiday.date, holiday.label, true, 'bank_holiday', false );
			if ( ! addedRow ) {
				limitReached = true;
				return;
			}
			existing[ holiday.date ] = {
				row: addedRow,
				source: 'bank_holiday'
			};
			added += 1;
		} );

		sortHolidayRows();
		schedulePreview();
		updateHolidayLimit();
		if ( status ) {
			status.textContent = bankHolidayMessage( added, replaced, skipped, limitReached );
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
					addRange( container, false );
					var rows = container.querySelectorAll( '.iboh-range' );
					var last = rows[ rows.length - 1 ];
					setRangeValues( last, r.open, r.close );
				} );
				updateRangeLimit( container );
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
		var today = siteTodayYmd();
		var cutoff = previousYmd( today );

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

			if ( dateInput && dateInput.value && dateInput.value < cutoff ) {
				row.remove();
				return;
			}

			var closedToggle = row.querySelector( '.iboh-closed-toggle' );
			if ( dateInput && dateInput.value && closedToggle && ! closedToggle.checked && ! rangesFrom( row.querySelector( '.iboh-ranges' ) ).length ) {
				closedToggle.checked = true;
				toggleClosed( closedToggle );
			}
		} );
		updateHolidayLimit();

		if ( firstInvalid ) {
			event.preventDefault();
			if ( firstInvalid.reportValidity ) {
				firstInvalid.reportValidity();
			}
			firstInvalid.focus();
		}
	}

	function previewCarryHolidays() {
		var carry = ( window.IBOH && window.IBOH.previewCarry ) || {};
		var out = {};
		if ( ! carry || 'object' !== typeof carry || Array.isArray( carry ) ) {
			return out;
		}
		Object.keys( carry ).forEach( function ( date ) {
			var row = carry[ date ];
			if ( ! row || 'object' !== typeof row || Array.isArray( row ) ) {
				return;
			}
			out[ date ] = {
				closed: row.closed,
				ranges: Array.isArray( row.ranges ) ? row.ranges.map( function ( range ) {
					return range && 'object' === typeof range
						? { open: range.open, close: range.close }
						: range;
				} ) : [],
				label: [ 'string', 'number', 'boolean' ].indexOf( typeof row.label ) >= 0 ? String( row.label ) : '',
				source: 'bank_holiday' === row.source ? 'bank_holiday' : ''
			};
		} );
		return out;
	}

	function buildConfig() {
		var cfg = {
			tz: window.IBOH ? window.IBOH.tz : '',
			offset: window.IBOH ? window.IBOH.offset : 0,
			timeFormat: window.IBOH ? window.IBOH.timeFormat : 'H:i',
			meridiem: window.IBOH ? window.IBOH.meridiem : {},
			list: window.IBOH ? window.IBOH.list : {},
			lang: window.IBOH ? window.IBOH.lang : '',
			dateNames: window.IBOH ? window.IBOH.dateNames : {},
			dayNames: window.IBOH ? window.IBOH.dayNames : [],
			closedText: window.IBOH ? window.IBOH.closedText : 'Closed',
			schedule: {},
			holidays: previewCarryHolidays(),
			labels: {},
			banner: {},
			options: {}
		};

		document.querySelectorAll( '[data-iboh-day]' ).forEach( function ( row ) {
			var dow = row.getAttribute( 'data-dow' );
			cfg.schedule[ dow ] = readDay( row );
		} );

		var today = siteTodayYmd();
		document.querySelectorAll( '[data-iboh-holiday]' ).forEach( function ( row ) {
			var dateInput = row.querySelector( 'input[type="date"]' );
			var date = dateInput ? dateInput.value : '';
			if ( ! shouldIncludePreviewDate( date, today ) ) {
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
			var key = input.getAttribute( 'data-iboh-label' );
			cfg.labels[ key ] = previewLabelValue( key, input.value );
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

	function setTextIfChanged( element, value ) {
		if ( ! element ) {
			return false;
		}
		value = value === undefined || value === null ? '' : String( value );
		if ( element.textContent === value && ! ( element.childElementCount > 0 ) ) {
			return false;
		}
		element.textContent = value;
		delete element.__ibohAdminRenderedHtml;
		return true;
	}

	function setHtmlIfChanged( element, value ) {
		if ( ! element ) {
			return false;
		}
		value = value === undefined || value === null ? '' : String( value );
		// Browsers normalize entity spellings in innerHTML. Cache the exact
		// generated value so a stable atomic live region is not rewritten and
		// re-announced on every 30-second preview refresh.
		if ( element.__ibohAdminRenderedHtml === value ) {
			return false;
		}
		element.innerHTML = value;
		element.__ibohAdminRenderedHtml = value;
		return true;
	}

	function updatePreviewContent( main, sub, upcoming, state ) {
		if ( ! state.show ) {
			setTextIfChanged( main, i18n.hidden || '—' );
			setTextIfChanged( sub, '' );
			setTextIfChanged( upcoming, '' );
			return;
		}
		setTextIfChanged( main, state.main );
		setTextIfChanged( sub, state.sub );
		if ( state.upcomingHtml ) {
			setHtmlIfChanged( upcoming, state.upcomingHtml );
		} else {
			setTextIfChanged( upcoming, state.upcoming || '' );
		}
	}

	function schedulePreview() {
		if ( previewTimer ) {
			window.clearTimeout( previewTimer );
		}
		previewTimer = window.setTimeout( renderPreview, 150 );
	}

	function renderPreview() {
		// Date edits and midnight rollover can change which retained rows count
		// toward the public special-date cap without adding or removing a row.
		updateHolidayLimit();
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
			updatePreviewContent( main, sub, up, s );
			return;
		}

		box.classList.remove( 'iboh-preview-hidden' );
		box.classList.toggle( 'iboh-open', s.open );
		box.classList.toggle( 'iboh-closed', ! s.open );
		box.style.background = s.open ? cfg.banner.colour_open : cfg.banner.colour_closed;
		box.style.color = cfg.banner.colour_text;
		updatePreviewContent( main, sub, up, s );
	}

	function removeRangeAndFocus( range ) {
		if ( ! range || ! range.parentElement ) {
			return;
		}
		var container = range.parentElement;
		var rows = Array.prototype.slice.call( container.querySelectorAll( '.iboh-range' ) );
		var index = rows.indexOf( range );
		range.remove();
		syncRangeLabels( container );
		updateRangeLimit( container );

		var remaining = container.querySelectorAll( '.iboh-range' );
		var neighbour = remaining[ index ] || remaining[ Math.max( 0, index - 1 ) ];
		if ( ! focusControl( neighbour, '[data-iboh-time-open]:not([disabled]), .iboh-24h-toggle:not([disabled])' ) ) {
			focusControl( container.closest( '.iboh-day-body' ), '.iboh-add-range:not([disabled])' );
		}
		schedulePreview();
	}

	function removeHolidayAndFocus( holiday ) {
		var list = document.getElementById( 'iboh-holidays' );
		if ( ! holiday || ! list ) {
			return;
		}
		var rows = Array.prototype.slice.call( list.querySelectorAll( '[data-iboh-holiday]' ) );
		var index = rows.indexOf( holiday );
		holiday.remove();
		updateHolidayLimit();

		var remaining = list.querySelectorAll( '[data-iboh-holiday]' );
		var neighbour = remaining[ index ] || remaining[ Math.max( 0, index - 1 ) ];
		if ( ! focusControl( neighbour, 'input[type="date"]:not([disabled])' ) ) {
			var addButton = document.getElementById( 'iboh-add-holiday' );
			if ( addButton && ! addButton.disabled ) {
				addButton.focus();
			}
		}
		schedulePreview();
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
				removeRangeAndFocus( range );
			} else if ( t.closest( '.iboh-remove-holiday' ) ) {
				e.preventDefault();
				var holiday = t.closest( '.iboh-holiday' );
				removeHolidayAndFocus( holiday );
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

		document.addEventListener( 'input', function ( e ) {
			if ( e.target.matches && e.target.matches( '[data-iboh-holiday] input[type="date"]' ) ) {
				syncHolidayContext( e.target.closest( '[data-iboh-holiday]' ) );
			}
			schedulePreview();
		} );

		document.querySelectorAll( '.iboh-range' ).forEach( sync24hRange );
		document.querySelectorAll( '[data-iboh-day] .iboh-ranges' ).forEach( syncRangeLabels );
		document.querySelectorAll( '[data-iboh-holiday]' ).forEach( syncHolidayContext );
		updateAllRangeLimits();
		updateHolidayLimit();
		renderPreview();
		// Keep the "soon"/transition wording live even with no edits.
		window.setInterval( renderPreview, 30000 );
	}

	window.IBOHAdminEval = {
		formatTemplate: formatTemplate,
		holidayCountForLimit: holidayCountForLimit,
		previewCarryHolidays: previewCarryHolidays,
		previousYmd: previousYmd,
		shouldIncludePreviewDate: shouldIncludePreviewDate,
		previewLabelValue: previewLabelValue,
		setTextIfChanged: setTextIfChanged,
		setHtmlIfChanged: setHtmlIfChanged,
		updatePreviewContent: updatePreviewContent
	};

	if ( 'loading' !== document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
} )();
