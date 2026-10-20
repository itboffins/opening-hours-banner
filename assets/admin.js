/* ITBoffins Live Opening Hours — admin editor (vanilla JS, no jQuery). */
( function () {
	'use strict';

	var IBOH = window.IBOH || {};
	var i18n = IBOH.i18n || {};
	var optionName = IBOH.optionName || 'iboh_settings';
	var maxRanges = Math.max( 1, parseInt( IBOH.maxRanges || 6, 10 ) );
	var maxHolidays = Math.max( 1, parseInt( IBOH.maxHolidays || 200, 10 ) );
	var rangeLimitMessage = i18n.rangeLimit || ( 'You can add up to ' + maxRanges + ' times per day.' );
	var holidayLimitMessage = i18n.holidayLimit || ( 'You can add up to ' + maxHolidays + ' dates.' );
	var limitReachedMessage = i18n.limitReached || 'Limit reached.';
	var holidayLimitReachedMessage = i18n.holidayLimitReached || 'The date limit has been reached. Remove a date before adding another.';
	var uid = Date.now(); // Unique-enough indices for newly added rows.
	var dirty = false;

	function nextIndex() {
		uid += 1;
		return uid;
	}

	function text( key, fallback ) {
		return i18n[ key ] !== undefined && null !== i18n[ key ] ? String( i18n[ key ] ) : fallback;
	}

	function formatTemplate( template, values ) {
		var nextValue = 0;
		return String( template ).replace( /%(?:(\d+)\$)?[sd]/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : nextValue++;
			return values[ index ] === undefined ? match : String( values[ index ] );
		} );
	}

	function focusControl( container, selector ) {
		var target = container && container.querySelector ? container.querySelector( selector ) : null;
		if ( target && ! target.disabled && 'function' === typeof target.focus ) {
			target.focus();
			return true;
		}
		return false;
	}

	/* ---- Timezone -------------------------------------------------------- */

	/* "UTC-9" / "UTC+5.75" (WordPress manual offsets) -> minutes east of UTC. */
	function utcOffsetMinutes( value ) {
		var match = /^UTC([+-])(\d{1,2})(?:\.(\d{1,2}))?$/.exec( String( value || '' ) );
		if ( ! match ) {
			return null;
		}
		var minutes = Math.round( ( parseInt( match[ 2 ], 10 ) + ( match[ 3 ] ? parseFloat( '0.' + match[ 3 ] ) : 0 ) ) * 60 );
		if ( minutes > 14 * 60 ) {
			return null;
		}
		return '-' === match[ 1 ] ? -minutes : minutes;
	}

	/* Resolve the timezone the editor should evaluate against right now. */
	function timezoneSelection() {
		var select = document.getElementById( 'iboh-timezone' );
		var value = select ? select.value : String( IBOH.timezoneSetting || '' );
		if ( ! value ) {
			return {
				tz: IBOH.siteTz || '',
				offset: ( +IBOH.siteOffset ) || 0,
				offsets: Array.isArray( IBOH.siteOffsets ) ? IBOH.siteOffsets : null,
				fixed: ! IBOH.siteTz,
				label: String( IBOH.siteTzLabel || '' )
			};
		}
		var minutes = utcOffsetMinutes( value );
		if ( null !== minutes ) {
			return { tz: '', offset: minutes, offsets: [ [ 0, minutes ] ], fixed: true, label: value };
		}
		return { tz: value, offset: 0, offsets: null, fixed: false, label: value };
	}

	function baseFormatConfig() {
		return {
			timeFormat: IBOH.timeFormat !== undefined ? IBOH.timeFormat : 'H:i',
			meridiem: IBOH.meridiem || {},
			list: IBOH.list || {},
			formats: IBOH.formats || {},
			lang: IBOH.lang || '',
			dateNames: IBOH.dateNames || {},
			dayNames: IBOH.dayNames || []
		};
	}

	function siteNowFor( cfg ) {
		if ( window.IBOHEval && window.IBOHEval.siteNow ) {
			return window.IBOHEval.siteNow( cfg );
		}
		var now = new Date();
		return {
			ymd: ymdFromDate( now ),
			dow: now.getUTCDay(),
			minutes: now.getUTCHours() * 60 + now.getUTCMinutes(),
			epochMs: now.getTime()
		};
	}

	function siteTodayYmd() {
		var selection = timezoneSelection();
		var cfg = baseFormatConfig();
		cfg.tz = selection.tz;
		cfg.offset = selection.offset;
		cfg.offsets = selection.offsets;
		return siteNowFor( cfg ).ymd;
	}

	function renderSiteTime( cfg ) {
		var clock = document.getElementById( 'iboh-site-time' );
		var warning = document.getElementById( 'iboh-timezone-warning' );
		var selection = timezoneSelection();
		if ( clock && window.IBOHEval ) {
			var now = siteNowFor( cfg );
			var dayNames = cfg.dayNames || [];
			var message = formatTemplate(
				text( 'siteTime', 'Right now it is %1$s on %2$s in %3$s.' ),
				[ window.IBOHEval.fmtTime( cfg, now.minutes ), dayNames[ now.dow ] || '', selection.label ]
			);
			if ( clock.textContent.trim() !== message ) {
				clock.textContent = message;
			}
		}
		if ( warning ) {
			warning.hidden = ! selection.fixed;
			var label = warning.querySelector( '[data-iboh-tz-label]' );
			if ( label && label.textContent !== selection.label ) {
				label.textContent = selection.label;
			}
		}
	}

	/* ---- Limits ---------------------------------------------------------- */

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

	function updateAddRangeLabel( container ) {
		var body = container ? container.closest( '.iboh-day-body' ) : null;
		var button = body ? body.querySelector( '.iboh-add-range' ) : null;
		if ( ! button ) {
			return;
		}
		var hasRange = container.querySelectorAll( '.iboh-range' ).length > 0;
		var label = hasRange ? text( 'addAnotherTime', '+ Add another time' ) : text( 'addHours', 'Add opening hours' );
		if ( button.textContent !== label ) {
			button.textContent = label;
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
			note.hidden = ! reached;
		}
		updateAddRangeLabel( container );
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
			note.hidden = ! reached;
		}
		var empty = document.getElementById( 'iboh-holidays-empty' );
		if ( empty ) {
			empty.hidden = list.querySelectorAll( '[data-iboh-holiday]' ).length > 0;
		}
	}

	/* ---- Field naming for cloned rows ------------------------------------ */

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

	/* ---- Accessible labels ----------------------------------------------- */

	function rangeContext( container ) {
		var dayRow = container ? container.closest( '[data-iboh-day]' ) : null;
		var dayName = dayRow ? dayRow.querySelector( '.iboh-day-name' ) : null;
		if ( dayName && dayName.textContent.trim() ) {
			return dayName.textContent.trim();
		}

		var holiday = container ? container.closest( '[data-iboh-holiday]' ) : null;
		var dateInput = holiday ? holiday.querySelector( 'input[type="date"]' ) : null;
		if ( dateInput && dateInput.value ) {
			return formatTemplate( text( 'specialDateContext', 'the date %s' ), [ dateInput.value ] );
		}
		return text( 'newSpecialDateContext', 'a new date' );
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

			row.setAttribute( 'aria-label', formatTemplate( text( 'rangeGroupLabel', 'Time %1$d for %2$s' ), [ rangeNumber, context ] ) );
			if ( openInput ) {
				openInput.setAttribute( 'aria-label', formatTemplate( text( 'rangeOpenLabel', 'Opening time for %1$s, time %2$d' ), [ context, rangeNumber ] ) );
			}
			if ( closeInput ) {
				closeInput.setAttribute( 'aria-label', formatTemplate( text( 'rangeCloseLabel', 'Closing time for %1$s, time %2$d' ), [ context, rangeNumber ] ) );
			}
			if ( removeButton ) {
				removeButton.setAttribute( 'aria-label', formatTemplate( text( 'rangeRemoveLabel', 'Remove time %1$d for %2$s' ), [ rangeNumber, context ] ) );
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
			ranges.setAttribute( 'aria-label', formatTemplate( text( 'timeRangesForContext', 'Opening times for %s' ), [ context ] ) );
			syncRangeLabels( ranges );
		}
		syncHolidayWeekday( row );
	}

	function syncHolidayWeekday( row ) {
		var label = row ? row.querySelector( '[data-iboh-holiday-weekday]' ) : null;
		var dateInput = row ? row.querySelector( 'input[type="date"]' ) : null;
		if ( ! label ) {
			return;
		}
		var value = '';
		if ( dateInput && dateInput.value && window.IBOHEval && window.IBOHEval.fmtDate ) {
			value = window.IBOHEval.fmtDate( baseFormatConfig(), dateInput.value );
			if ( value === dateInput.value ) {
				value = '';
			}
		}
		if ( label.textContent !== value ) {
			label.textContent = value;
		}
	}

	/* ---- Open / closed state --------------------------------------------- */

	function isClosed( wrap ) {
		var closedRadio = wrap ? wrap.querySelector( '.iboh-closed-toggle[value="1"]' ) : null;
		return !! ( closedRadio && closedRadio.checked );
	}

	function setClosed( wrap, closed ) {
		if ( ! wrap ) {
			return;
		}
		var openRadio = wrap.querySelector( '.iboh-closed-toggle[value="0"]' );
		var closedRadio = wrap.querySelector( '.iboh-closed-toggle[value="1"]' );
		if ( openRadio ) { openRadio.checked = ! closed; }
		if ( closedRadio ) { closedRadio.checked = !! closed; }
		applyOpenClosed( wrap );
	}

	/* Show the hours for an open day (seeding one time if there is none) or the
	 * "Closed all day" note. Hours are kept in the DOM while closed so they come
	 * back if the owner changes their mind. */
	function applyOpenClosed( wrap, seed ) {
		if ( ! wrap ) {
			return;
		}
		var closed = isClosed( wrap );
		var body = wrap.querySelector( '.iboh-day-body' );
		var note = wrap.querySelector( '.iboh-day-closed-note' );
		wrap.classList.toggle( 'iboh-is-closed', closed );
		if ( body ) {
			body.hidden = closed;
		}
		if ( note ) {
			note.hidden = ! closed;
		}
		var container = wrap.querySelector( '.iboh-ranges' );
		if ( ! closed && container && false !== seed && ! container.querySelector( '.iboh-range' ) ) {
			seedRanges( wrap, container );
		}
		updateRangeLimit( container );
	}

	/* Default hours for a day that has just been opened: the regular weekday's
	 * hours for a date, otherwise 09:00 to 17:00. */
	function seedRanges( wrap, container ) {
		var seeds = [ { open: '09:00', close: '17:00' } ];
		if ( wrap.hasAttribute( 'data-iboh-holiday' ) ) {
			var dateInput = wrap.querySelector( 'input[type="date"]' );
			var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( dateInput ? dateInput.value : '' );
			if ( match ) {
				var dow = dateUtc( +match[ 1 ], +match[ 2 ], +match[ 3 ] ).getUTCDay();
				var dayRow = document.querySelector( '[data-iboh-day][data-dow="' + dow + '"]' );
				var state = dayRow ? readDay( dayRow ) : null;
				if ( state && ! state.closed && state.ranges.length ) {
					seeds = state.ranges;
				}
			}
		}
		seeds.forEach( function ( range ) {
			var node = addRange( container, false );
			if ( node ) {
				setRangeValues( node, range.open, range.close );
			}
		} );
	}

	function toggleWeekly( checkbox ) {
		var body = document.getElementById( 'iboh-weekly-body' );
		var note = document.getElementById( 'iboh-weekly-off-note' );
		if ( body ) {
			body.hidden = ! checkbox.checked;
		}
		if ( note ) {
			note.hidden = checkbox.checked;
		}
	}

	/* ---- Range rows ------------------------------------------------------- */

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
		var was24h = '1' === row.getAttribute( 'data-iboh-was-24h' );

		if ( hiddenOpen ) { hiddenOpen.disabled = ! is24h; }
		if ( hiddenClose ) { hiddenClose.disabled = ! is24h; }
		if ( is24h && ! was24h ) {
			// Remember the typed times so un-ticking restores them.
			row.setAttribute( 'data-iboh-prev-open', timeOpen ? timeOpen.value : '' );
			row.setAttribute( 'data-iboh-prev-close', timeClose ? timeClose.value : '' );
		}
		if ( timeOpen ) {
			timeOpen.disabled = is24h;
			if ( is24h ) {
				timeOpen.value = '00:00';
			} else if ( was24h ) {
				timeOpen.value = row.getAttribute( 'data-iboh-prev-open' ) || '09:00';
			}
		}
		if ( timeClose ) {
			timeClose.disabled = is24h;
			if ( is24h ) {
				timeClose.value = '23:59';
			} else if ( was24h ) {
				timeClose.value = row.getAttribute( 'data-iboh-prev-close' ) || '17:00';
			}
		}
		row.setAttribute( 'data-iboh-was-24h', is24h ? '1' : '0' );
		syncRangeHint( row );
	}

	function isOvernight( open, close ) {
		return !! open && !! close && '24:00' !== close && close < open;
	}

	function rangeHint( open, close ) {
		if ( ! isOvernight( open, close ) ) {
			return '';
		}
		if ( '00:00' === close ) {
			return text( 'midnightHint', 'until midnight' );
		}
		return formatTemplate( text( 'overnightHint', 'closes %s the next day' ), [ close ] );
	}

	function syncRangeHint( row ) {
		var hint = row ? row.querySelector( '[data-iboh-range-hint]' ) : null;
		if ( ! hint ) {
			return;
		}
		var toggle = row.querySelector( '.iboh-24h-toggle' );
		var timeOpen = row.querySelector( '[data-iboh-time-open]' );
		var timeClose = row.querySelector( '[data-iboh-time-close]' );
		var value = ( toggle && toggle.checked ) ? '' : rangeHint( timeOpen ? timeOpen.value : '', timeClose ? timeClose.value : '' );
		if ( hint.textContent !== value ) {
			hint.textContent = value;
		}
	}

	function setRangeValues( row, open, close ) {
		var is24h = ( open === '00:00' && close === '24:00' );
		var toggle = row.querySelector( '.iboh-24h-toggle' );
		var timeOpen = row.querySelector( '[data-iboh-time-open]' );
		var timeClose = row.querySelector( '[data-iboh-time-close]' );

		row.setAttribute( 'data-iboh-was-24h', '0' );
		if ( toggle ) { toggle.checked = is24h; }
		if ( timeOpen ) { timeOpen.value = open || ''; }
		if ( timeClose ) {
			// "until midnight" (24:00) is shown as 00:00, which evaluates the same.
			timeClose.value = is24h ? '23:59' : ( '24:00' === close ? '00:00' : ( close || '' ) );
		}
		sync24hRange( row );
	}

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
		var bankTag = node.querySelector( '[data-iboh-bank-tag]' );
		if ( sourceInput ) {
			sourceInput.value = ( 'bank_holiday' === source ) ? 'bank_holiday' : '';
		}
		if ( bankTag ) {
			bankTag.hidden = ( 'bank_holiday' !== source );
		}

		list.appendChild( node );
		setClosed( node, closed !== false );
		syncHolidayContext( node );
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
		var value_text = value === undefined || value === null ? '' : String( value );
		if ( '' !== value_text.trim() ) {
			return value_text;
		}
		var defaults = IBOH.defaultLabels || {};
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

	function holidayDate( row ) {
		var input = row ? row.querySelector( 'input[type="date"]' ) : null;
		return input && input.value ? input.value : '9999-12-31';
	}

	/* Re-order the whole list (after adding bank holidays). Rows are only
	 * moved when the order is actually wrong. */
	function sortHolidayRows() {
		var list = document.getElementById( 'iboh-holidays' );
		if ( ! list ) {
			return;
		}
		var rows = Array.prototype.slice.call( list.querySelectorAll( '[data-iboh-holiday]' ) );
		var sorted = rows.slice().sort( function ( a, b ) {
			return holidayDate( a ).localeCompare( holidayDate( b ) );
		} );
		var unchanged = sorted.every( function ( row, index ) { return row === rows[ index ]; } );
		if ( unchanged ) {
			return;
		}
		var active = document.activeElement;
		sorted.forEach( function ( row ) { list.appendChild( row ); } );
		if ( active && 'function' === typeof active.focus && document.activeElement !== active ) {
			active.focus();
		}
	}

	/* Move one edited row to its date position. Only that row moves, and only
	 * once focus has left it, so typing a date is never interrupted and focus
	 * elsewhere in the list is never disturbed. */
	function placeHolidayRow( row ) {
		var list = document.getElementById( 'iboh-holidays' );
		if ( ! list || ! row || row.parentElement !== list || row.contains( document.activeElement ) ) {
			return;
		}
		var date = holidayDate( row );
		var before = null;
		var rows = list.querySelectorAll( '[data-iboh-holiday]' );
		for ( var i = 0; i < rows.length; i++ ) {
			if ( rows[ i ] !== row && holidayDate( rows[ i ] ) > date ) {
				before = rows[ i ];
				break;
			}
		}
		var next = row.nextElementSibling;
		while ( next && ! next.hasAttribute( 'data-iboh-holiday' ) ) {
			next = next.nextElementSibling;
		}
		var previous = row.previousElementSibling;
		while ( previous && ! previous.hasAttribute( 'data-iboh-holiday' ) ) {
			previous = previous.previousElementSibling;
		}
		if ( next === before && ( ! previous || holidayDate( previous ) <= date ) ) {
			return;
		}
		list.insertBefore( row, before );
	}

	function bankHolidayMessage( added, replaced, skipped, limitReached ) {
		if ( limitReached && added < 1 && replaced < 1 ) {
			return holidayLimitReachedMessage;
		}
		if ( added < 1 && replaced < 1 ) {
			return text( 'bankHolidaysNone', 'Those bank holidays are already in your list.' );
		}
		var message = formatTemplate(
			text( 'bankHolidaysAdded', 'Added: %1$d. Replaced existing dates: %2$d. Already in your list: %3$d. Click Save changes when you are happy.' ),
			[ added, replaced, skipped ]
		);
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
		markDirty();
		schedulePreview();
		updateHolidayLimit();
		if ( status ) {
			status.textContent = bankHolidayMessage( added, replaced, skipped, limitReached );
		}
	}

	/* ---- Copy Monday's hours --------------------------------------------- */

	function copyTargets( value ) {
		if ( 'all' === value ) {
			return [ 2, 3, 4, 5, 6, 0 ];
		}
		if ( 'mon_sat' === value ) {
			return [ 2, 3, 4, 5, 6 ];
		}
		return [ 2, 3, 4, 5 ];
	}

	/* Includes the hours a closed day keeps, so replacing them asks first. */
	function dayStateKey( state ) {
		return JSON.stringify( { closed: !! state.closed, ranges: state.ranges } );
	}

	function copyMonday() {
		var monday = document.querySelector( '[data-iboh-day][data-dow="1"]' );
		var select = document.getElementById( 'iboh-copy-target' );
		var status = document.getElementById( 'iboh-copy-status' );
		if ( ! monday ) {
			return;
		}
		var targetLabel = select && select.selectedIndex >= 0 ? select.options[ select.selectedIndex ].text : '';
		var mondayState = readDay( monday );
		var mondayKey = dayStateKey( mondayState );
		var rows = copyTargets( select ? select.value : 'weekdays' ).map( function ( dow ) {
			return document.querySelector( '[data-iboh-day][data-dow="' + dow + '"]' );
		} ).filter( Boolean );

		var differs = rows.some( function ( row ) {
			return dayStateKey( readDay( row ) ) !== mondayKey;
		} );
		if ( differs && ! window.confirm( formatTemplate( text( 'copyConfirm', "Replace the hours already set for %s with Monday's hours?" ), [ targetLabel ] ) ) ) {
			return;
		}

		rows.forEach( function ( row ) {
			var container = row.querySelector( '.iboh-ranges' );
			if ( container ) {
				container.innerHTML = '';
				mondayState.ranges.forEach( function ( r ) {
					var node = addRange( container, false );
					if ( node ) {
						setRangeValues( node, r.open, r.close );
					}
				} );
			}
			setClosed( row, mondayState.closed );
			syncRangeLabels( container );
			updateRangeLimit( container );
		} );
		markDirty();
		schedulePreview( true );
		if ( status ) {
			status.textContent = formatTemplate( text( 'copyDone', "Copied Monday's hours to %s. Click Save changes when you are happy." ), [ targetLabel ] );
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
		var closed = isClosed( row );
		return {
			closed: closed,
			ranges: rangesFrom( row.querySelector( '.iboh-ranges' ) )
		};
	}

	function buildConfig() {
		var selection = timezoneSelection();
		var cfg = baseFormatConfig();
		cfg.tz = selection.tz;
		cfg.offset = selection.offset;
		cfg.offsets = selection.offsets;
		cfg.closedText = IBOH.closedText !== undefined ? IBOH.closedText : 'Closed';
		cfg.schedule = {};
		cfg.holidays = {};
		cfg.labels = {};
		cfg.banner = {};
		cfg.options = {};

		document.querySelectorAll( '[data-iboh-day]' ).forEach( function ( row ) {
			var dow = row.getAttribute( 'data-dow' );
			var state = readDay( row );
			cfg.schedule[ dow ] = { closed: state.closed ? 1 : 0, ranges: state.closed ? [] : state.ranges };
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
			var state = readDay( row );
			cfg.holidays[ date ] = {
				closed: state.closed ? 1 : 0,
				ranges: state.closed ? [] : state.ranges,
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
	var previewAnnounce = true;
	var lastAnnouncement = null;

	function setTextIfChanged( element, value ) {
		if ( ! element ) {
			return false;
		}
		value = value === undefined || value === null ? '' : String( value );
		if ( window.IBOHEval && window.IBOHEval.decodeEntities ) {
			value = window.IBOHEval.decodeEntities( value );
		}
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
		// generated value so a stable region is not rewritten on every refresh.
		if ( element.__ibohAdminRenderedHtml === value ) {
			return false;
		}
		element.innerHTML = value;
		element.__ibohAdminRenderedHtml = value;
		return true;
	}

	function updatePreviewContent( main, sub, upcoming, state ) {
		if ( ! state.show ) {
			setTextIfChanged( main, state.hiddenText || text( 'hidden', '—' ) );
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

	/* Screen readers hear the preview only when a change is committed (not on
	 * every keystroke) and when the live wording changes on the timer. */
	function announcePreview( box ) {
		var region = document.getElementById( 'iboh-preview-announce' );
		if ( ! region || ! box ) {
			return;
		}
		var message = box.textContent.replace( /\s+/g, ' ' ).trim();
		if ( ! previewAnnounce && null !== lastAnnouncement ) {
			return;
		}
		if ( message !== lastAnnouncement ) {
			region.textContent = message;
			lastAnnouncement = message;
		}
	}

	function schedulePreview( announce ) {
		if ( true === announce ) {
			previewAnnounce = true;
		} else if ( false === announce ) {
			previewAnnounce = false;
		}
		if ( previewTimer ) {
			window.clearTimeout( previewTimer );
		}
		previewTimer = window.setTimeout( renderPreview, 150 );
	}

	function renderPreview() {
		// Date edits and midnight rollover can change which rows count toward
		// the date cap without adding or removing a row.
		updateHolidayLimit();
		var box = document.getElementById( 'iboh-preview' );
		if ( ! box || ! window.IBOHEval ) {
			return;
		}
		var cfg = buildConfig();
		renderSiteTime( cfg );
		var s = window.IBOHEval.buildStatus( cfg, siteNowFor( cfg ) );
		var main = box.querySelector( '[data-iboh-main]' );
		var sub = box.querySelector( '[data-iboh-sub]' );
		var up = box.querySelector( '[data-iboh-upcoming]' );

		if ( ! ( +cfg.banner.enabled ) ) {
			s = { show: false, hiddenText: text( 'bannerOff', 'The notice bar is turned off.' ) };
		}

		if ( ! s.show ) {
			box.classList.remove( 'iboh-open' );
			box.classList.add( 'iboh-closed', 'iboh-preview-hidden' );
			box.style.background = '';
			box.style.color = '';
			updatePreviewContent( main, sub, up, s );
			announcePreview( box );
			previewAnnounce = true;
			return;
		}

		box.classList.remove( 'iboh-preview-hidden' );
		box.classList.toggle( 'iboh-open', s.open );
		box.classList.toggle( 'iboh-closed', ! s.open );
		box.style.background = s.open ? cfg.banner.colour_open : cfg.banner.colour_closed;
		box.style.color = cfg.banner.colour_text;
		updatePreviewContent( main, sub, up, s );
		announcePreview( box );
		previewAnnounce = true;
	}

	/* ---- Removal with sensible focus ------------------------------------- */

	function removeRangeAndFocus( range ) {
		if ( ! range || ! range.parentElement ) {
			return;
		}
		var container = range.parentElement;
		var rows = Array.prototype.slice.call( container.querySelectorAll( '.iboh-range' ) );
		var index = rows.indexOf( range );
		range.remove();
		clearCustomValidity( document.getElementById( 'iboh-form' ) );
		syncRangeLabels( container );
		updateRangeLimit( container );

		var remaining = container.querySelectorAll( '.iboh-range' );
		var neighbour = remaining[ index ] || remaining[ Math.max( 0, index - 1 ) ];
		if ( ! focusControl( neighbour, '[data-iboh-time-open]:not([disabled]), .iboh-24h-toggle:not([disabled])' ) ) {
			focusControl( container.closest( '.iboh-day-body' ), '.iboh-add-range:not([disabled])' );
		}
		markDirty();
		schedulePreview( true );
	}

	function removeHolidayAndFocus( holiday ) {
		var list = document.getElementById( 'iboh-holidays' );
		if ( ! holiday || ! list ) {
			return;
		}
		var rows = Array.prototype.slice.call( list.querySelectorAll( '[data-iboh-holiday]' ) );
		var index = rows.indexOf( holiday );
		holiday.remove();
		clearCustomValidity( document.getElementById( 'iboh-form' ) );
		updateHolidayLimit();

		var remaining = list.querySelectorAll( '[data-iboh-holiday]' );
		var neighbour = remaining[ index ] || remaining[ Math.max( 0, index - 1 ) ];
		if ( ! focusControl( neighbour, 'input[type="date"]:not([disabled])' ) ) {
			var addButton = document.getElementById( 'iboh-add-holiday' );
			if ( addButton && ! addButton.disabled ) {
				addButton.focus();
			}
		}
		markDirty();
		schedulePreview( true );
	}

	/* ---- Unsaved-changes tracking ---------------------------------------- */

	function markDirty() {
		dirty = true;
		var pill = document.getElementById( 'iboh-unsaved' );
		if ( pill ) {
			pill.hidden = false;
		}
	}

	/* Only controls that are submitted (or drive submitted fields) count as
	 * unsaved changes; helper menus such as the bank-holiday year do not. */
	function isSavedControl( form, target ) {
		if ( ! form || ! target || ! form.contains( target ) ) {
			return false;
		}
		return !! target.name || ( target.classList && target.classList.contains( 'iboh-24h-toggle' ) );
	}

	function markClean() {
		dirty = false;
		var pill = document.getElementById( 'iboh-unsaved' );
		if ( pill ) {
			pill.hidden = true;
		}
	}

	/* ---- Validation before submit ---------------------------------------- */

	function invalidate( input, message, state ) {
		if ( ! input ) {
			return;
		}
		input.setCustomValidity( message );
		if ( ! state.first ) {
			state.first = input;
		}
	}

	/* Custom messages are only ever set by validateForSubmit(); any edit may
	 * resolve a problem on a *different* field (a duplicate date, say), so
	 * every message is cleared and recomputed on the next submit. */
	function clearCustomValidity( form ) {
		if ( ! form ) {
			return;
		}
		form.querySelectorAll( 'input, select' ).forEach( function ( input ) {
			if ( input.validity && input.validity.customError ) {
				input.setCustomValidity( '' );
			}
		} );
	}

	function validateForSubmit( event ) {
		var state = { first: null };
		var today = siteTodayYmd();
		var cutoff = previousYmd( today );
		var seenDates = {};

		document.querySelectorAll( '[data-iboh-holiday]' ).forEach( function ( row ) {
			ensureHolidayRowNames( row );
			var dateInput = row.querySelector( 'input[type="date"]' );
			if ( dateInput ) {
				dateInput.setCustomValidity( '' );
				if ( ! dateInput.value ) {
					invalidate( dateInput, text( 'missingDate', 'Pick a date, or remove this row.' ), state );
				} else if ( dateInput.value < cutoff ) {
					invalidate( dateInput, text( 'pastDate', 'That date has already passed. Pick a later date, or remove this row.' ), state );
				} else if ( seenDates[ dateInput.value ] ) {
					invalidate( dateInput, text( 'duplicateDate', 'This date is already in the list. Edit that entry or remove this one.' ), state );
				} else {
					seenDates[ dateInput.value ] = true;
				}
			}
		} );

		var weeklyOn = document.getElementById( 'iboh-weekly-enabled' );
		document.querySelectorAll( '[data-iboh-day], [data-iboh-holiday]' ).forEach( function ( wrap ) {
			var isDay = wrap.hasAttribute( 'data-iboh-day' );
			var skip = isClosed( wrap ) || ( isDay && weeklyOn && ! weeklyOn.checked );
			wrap.querySelectorAll( '.iboh-range' ).forEach( function ( row ) {
				var toggle = row.querySelector( '.iboh-24h-toggle' );
				var openInput = row.querySelector( '[data-iboh-time-open]' );
				var closeInput = row.querySelector( '[data-iboh-time-close]' );
				if ( openInput ) { openInput.setCustomValidity( '' ); }
				if ( closeInput ) { closeInput.setCustomValidity( '' ); }
				if ( skip || ( toggle && toggle.checked ) ) {
					return;
				}
				var message = text( 'missingTime', 'Enter both an opening and a closing time, or remove this time.' );
				if ( openInput && ! openInput.value ) {
					invalidate( openInput, message, state );
				} else if ( closeInput && ! closeInput.value ) {
					invalidate( closeInput, message, state );
				}
			} );
		} );
		updateHolidayLimit();

		if ( state.first ) {
			event.preventDefault();
			if ( state.first.reportValidity ) {
				state.first.reportValidity();
			}
			state.first.focus();
			return;
		}
		markClean();
	}

	/* ---- Wiring ---------------------------------------------------------- */

	function init() {
		var form = document.getElementById( 'iboh-form' );
		if ( form ) {
			form.addEventListener( 'submit', validateForSubmit );
		}

		document.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( t.closest( '.iboh-add-range' ) ) {
				e.preventDefault();
				var body = t.closest( '.iboh-day-body' );
				addRange( body ? body.querySelector( '.iboh-ranges' ) : null );
				markDirty();
			} else if ( t.closest( '.iboh-remove-range' ) ) {
				e.preventDefault();
				removeRangeAndFocus( t.closest( '.iboh-range' ) );
			} else if ( t.closest( '.iboh-remove-holiday' ) ) {
				e.preventDefault();
				removeHolidayAndFocus( t.closest( '.iboh-holiday' ) );
			} else if ( t.id === 'iboh-add-holiday' ) {
				e.preventDefault();
				addHoliday();
				markDirty();
			} else if ( t.id === 'iboh-add-bank-holidays' ) {
				e.preventDefault();
				addClosedBankHolidays();
			} else if ( t.id === 'iboh-copy-monday' ) {
				e.preventDefault();
				copyMonday();
			}
		} );

		document.addEventListener( 'change', function ( e ) {
			var target = e.target;
			if ( ! target || ! target.classList ) {
				return;
			}
			if ( target.classList.contains( 'iboh-24h-toggle' ) ) {
				sync24hRange( target.closest( '.iboh-range' ) );
			} else if ( target.classList.contains( 'iboh-closed-toggle' ) ) {
				applyOpenClosed( target.closest( '[data-iboh-day], [data-iboh-holiday]' ) );
			} else if ( 'iboh-weekly-enabled' === target.id ) {
				toggleWeekly( target );
			} else if ( target.matches && target.matches( '[data-iboh-holiday] input[type="date"]' ) ) {
				// No re-ordering here: Chrome fires "change" on every keystroke
				// in a date field, and moving the row would scramble the typing.
				syncHolidayContext( target.closest( '[data-iboh-holiday]' ) );
			} else if ( target.matches && target.matches( '[data-iboh-time-open], [data-iboh-time-close]' ) ) {
				syncRangeHint( target.closest( '.iboh-range' ) );
			}
			if ( isSavedControl( form, target ) ) {
				clearCustomValidity( form );
				markDirty();
			}
			schedulePreview( true );
		} );

		document.addEventListener( 'input', function ( e ) {
			var target = e.target;
			if ( target && target.matches && target.matches( '[data-iboh-holiday] input[type="date"]' ) ) {
				syncHolidayContext( target.closest( '[data-iboh-holiday]' ) );
			} else if ( target && target.matches && target.matches( '[data-iboh-time-open], [data-iboh-time-close]' ) ) {
				syncRangeHint( target.closest( '.iboh-range' ) );
			}
			if ( isSavedControl( form, target ) ) {
				clearCustomValidity( form );
				markDirty();
			}
			schedulePreview( false );
		} );

		// Put an edited date in order once the owner has finished with its row.
		document.addEventListener( 'focusout', function ( e ) {
			var row = e.target && e.target.closest ? e.target.closest( '[data-iboh-holiday]' ) : null;
			if ( ! row || ( e.relatedTarget && row.contains( e.relatedTarget ) ) ) {
				return;
			}
			window.setTimeout( function () {
				placeHolidayRow( row );
			}, 0 );
		} );

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );

		// Re-apply the 24-hour state: a browser can restore a ticked checkbox
		// after a reload while the server markup has the time inputs enabled.
		document.querySelectorAll( '.iboh-range' ).forEach( function ( row ) {
			var toggle = row.querySelector( '.iboh-24h-toggle' );
			var is24h = !! ( toggle && toggle.checked );
			row.setAttribute( 'data-iboh-was-24h', '0' );
			sync24hRange( row );
			if ( is24h ) {
				// No earlier times to restore if the owner unticks it.
				row.setAttribute( 'data-iboh-prev-open', '' );
				row.setAttribute( 'data-iboh-prev-close', '' );
			}
		} );
		document.querySelectorAll( '[data-iboh-day], [data-iboh-holiday]' ).forEach( function ( wrap ) {
			applyOpenClosed( wrap, false );
		} );
		document.querySelectorAll( '[data-iboh-day] .iboh-ranges' ).forEach( syncRangeLabels );
		document.querySelectorAll( '[data-iboh-holiday]' ).forEach( syncHolidayContext );
		updateAllRangeLimits();
		updateHolidayLimit();
		renderPreview();
		// Keep the clock and "soon"/transition wording live even with no edits.
		window.setInterval( renderPreview, 30000 );
	}

	window.IBOHAdminEval = {
		formatTemplate: formatTemplate,
		holidayCountForLimit: holidayCountForLimit,
		previousYmd: previousYmd,
		shouldIncludePreviewDate: shouldIncludePreviewDate,
		previewLabelValue: previewLabelValue,
		setTextIfChanged: setTextIfChanged,
		setHtmlIfChanged: setHtmlIfChanged,
		updatePreviewContent: updatePreviewContent,
		utcOffsetMinutes: utcOffsetMinutes,
		rangeHint: rangeHint,
		isOvernight: isOvernight,
		easterSunday: easterSunday,
		standardBankHolidays: standardBankHolidays,
		copyTargets: copyTargets
	};

	if ( 'loading' !== document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
} )();
