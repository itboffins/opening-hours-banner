<?php
/**
 * Standalone regression checks for the PHP fallback evaluator and settings.
 *
 * Run with: php tests/php-regression.php
 */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
define( 'IBOH_OPTION', 'iboh_settings' );
define( 'IBOH_VERSION', 'test' );
define( 'IBOH_DIR', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'IBOH_URL', 'https://example.test/wp-content/plugins/itboffins-live-opening-hours/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['iboh_test_timezone']       = new DateTimeZone( 'UTC' );
$GLOBALS['iboh_test_clock']          = new DateTimeImmutable( '2026-08-04 12:00:00', $GLOBALS['iboh_test_timezone'] );
$GLOBALS['iboh_test_options']        = array(
	'time_format' => 'H:i',
	IBOH_OPTION   => array(),
);
$GLOBALS['iboh_test_errors']         = array();
$GLOBALS['iboh_test_failures']       = array();
$GLOBALS['iboh_test_passes']         = 0;
$GLOBALS['iboh_test_multisite']      = false;
$GLOBALS['iboh_test_current_blog']   = 1;
$GLOBALS['iboh_test_blog_stack']     = array();
$GLOBALS['iboh_test_deleted_blogs']  = array();
$GLOBALS['iboh_test_uninstall_mode'] = false;
$GLOBALS['iboh_test_locale']         = 'en_GB';
$GLOBALS['iboh_test_translations']   = array();
$GLOBALS['iboh_test_locale_maps']    = array();
$GLOBALS['iboh_test_locale_stack']   = array();
$GLOBALS['iboh_test_languages']      = array();
$GLOBALS['iboh_test_registered_block'] = null;

function __( $text ) {
	$locale_map = isset( $GLOBALS['iboh_test_locale_maps'][ get_locale() ] )
		? $GLOBALS['iboh_test_locale_maps'][ get_locale() ]
		: array();
	if ( isset( $locale_map[ $text ] ) ) {
		return $locale_map[ $text ];
	}
	return isset( $GLOBALS['iboh_test_translations'][ $text ] )
		? $GLOBALS['iboh_test_translations'][ $text ]
		: $text;
}

function _x( $text ) {
	return $text;
}

function _n( $single, $plural, $number ) {
	return 1 === (int) $number ? $single : $plural;
}

function get_locale() {
	return $GLOBALS['iboh_test_locale'];
}

function determine_locale() {
	return get_locale();
}

function get_available_languages() {
	return $GLOBALS['iboh_test_languages'];
}

function switch_to_locale( $locale ) {
	if ( ! is_string( $locale ) || '' === $locale ) {
		return false;
	}
	$GLOBALS['iboh_test_locale_stack'][] = $GLOBALS['iboh_test_locale'];
	$GLOBALS['iboh_test_locale']         = $locale;
	return true;
}

function restore_previous_locale() {
	if ( empty( $GLOBALS['iboh_test_locale_stack'] ) ) {
		return false;
	}
	$GLOBALS['iboh_test_locale'] = array_pop( $GLOBALS['iboh_test_locale_stack'] );
	return $GLOBALS['iboh_test_locale'];
}

function get_current_blog_id() {
	return (int) $GLOBALS['iboh_test_current_blog'];
}

function home_url( $path = '' ) {
	return 'https://example.test/' . ltrim( (string) $path, '/' );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( (array) $defaults, is_array( $args ) ? $args : array() );
}

function maybe_serialize( $value ) {
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
}

function wp_timezone() {
	return $GLOBALS['iboh_test_timezone'];
}

function wp_date( $format, $timestamp = null, $timezone = null ) {
	if ( null === $timestamp ) {
		$timestamp = $GLOBALS['iboh_test_clock']->getTimestamp();
	}
	$zone = $timezone instanceof DateTimeZone ? $timezone : wp_timezone();
	return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $zone )->format( $format );
}

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['iboh_test_options'] )
		? $GLOBALS['iboh_test_options'][ $key ]
		: $default;
}

function update_option( $key, $value ) {
	$GLOBALS['iboh_test_options'][ $key ] = $value;
	return true;
}

function sanitize_text_field( $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';
	return trim( strip_tags( $value ) );
}

function sanitize_hex_color( $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';
	return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? strtolower( $value ) : null;
}

function absint( $value ) {
	return abs( (int) $value );
}

function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['iboh_test_errors'][] = array(
		'setting' => $setting,
		'code'    => $code,
		'message' => $message,
		'type'    => $type,
	);
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_attr( $value ) {
	return esc_html( $value );
}

function wp_kses_post( $value ) {
	return (string) $value;
}

function shortcode_atts( $defaults, $attributes ) {
	return array_merge( $defaults, is_array( $attributes ) ? $attributes : array() );
}

function get_block_wrapper_attributes() {
	return 'class="wp-block-iboh-opening-hours custom-class"';
}

function wp_register_style() {
	return true;
}

function wp_register_script() {
	return true;
}

function wp_set_script_translations() {
	return true;
}

function register_block_type( $name, $args ) {
	$GLOBALS['iboh_test_registered_block'] = array(
		'name' => $name,
		'args' => $args,
	);
	return true;
}

function is_multisite() {
	return ! empty( $GLOBALS['iboh_test_multisite'] );
}

function get_sites( $args ) {
	return 0 === (int) $args['offset'] ? array( 11, 12 ) : array();
}

function switch_to_blog( $blog_id ) {
	$GLOBALS['iboh_test_blog_stack'][]   = $GLOBALS['iboh_test_current_blog'];
	$GLOBALS['iboh_test_current_blog'] = (int) $blog_id;
}

function restore_current_blog() {
	if ( ! empty( $GLOBALS['iboh_test_blog_stack'] ) ) {
		$GLOBALS['iboh_test_current_blog'] = array_pop( $GLOBALS['iboh_test_blog_stack'] );
	}
}

function delete_option( $key ) {
	if ( $GLOBALS['iboh_test_uninstall_mode'] && IBOH_OPTION === $key ) {
		$GLOBALS['iboh_test_deleted_blogs'][] = get_current_blog_id();
	}
	unset( $GLOBALS['iboh_test_options'][ $key ] );
	return true;
}

class IBOH_Timezone {
	public static function now() {
		return $GLOBALS['iboh_test_clock']->setTimezone( wp_timezone() );
	}

	public static function iana() {
		return wp_timezone()->getName();
	}

	public static function offset_minutes() {
		return (int) ( wp_timezone()->getOffset( self::now() ) / 60 );
	}
}

class IBOH_Frontend {
	public static function enqueue() {
		return;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-iboh-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-iboh-config.php';
require_once dirname( __DIR__ ) . '/includes/class-iboh-evaluator.php';
require_once dirname( __DIR__ ) . '/includes/class-iboh-shortcode.php';
require_once dirname( __DIR__ ) . '/includes/class-iboh-block.php';

function iboh_test_check( $condition, $message ) {
	if ( $condition ) {
		$GLOBALS['iboh_test_passes']++;
		return;
	}
	$GLOBALS['iboh_test_failures'][] = $message;
}

function iboh_test_same( $expected, $actual, $message ) {
	iboh_test_check(
		$expected === $actual,
		$message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')'
	);
}

function iboh_test_closed_schedule() {
	$schedule = array();
	for ( $day = 0; $day < 7; $day++ ) {
		$schedule[ $day ] = array( 'closed' => 1, 'ranges' => array() );
	}
	return $schedule;
}

function iboh_test_config() {
	return array(
		'timeFormat' => 'H:i',
		'lang'       => 'en-GB',
		'list'       => array(
			'separator' => ', ',
			'pair'      => '%1$s and %2$s',
			'final'     => '%1$s, and %2$s',
		),
		'dayNames'   => array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ),
		'schedule'   => iboh_test_closed_schedule(),
		'holidays'   => array(),
		'options'    => array(
			'weekly_enabled'    => 1,
			'show_upcoming'     => 0,
			'bank_holiday_mode' => 'all',
		),
		'labels'     => array(
			'open'                 => "We're open",
			'closed'               => "Sorry, we're closed",
			'closes_at'            => 'Closes at %s',
			'opens_today'          => 'Opens today at %s',
			'opens_on'             => 'Opens %1$s at %2$s',
			'opening_soon'         => 'Opening soon',
			'closing_soon'         => 'Closing soon',
			'open_24h'             => 'Open 24 hours',
			'upcoming_heading'     => 'Upcoming dates',
			'upcoming_closed'      => 'Closed %s',
			'upcoming_closed_list' => '%s we are closed',
			'upcoming_hours'       => '%1$s: %2$s',
		),
		'banner'     => array(
			'show_next' => 1,
			'soon_mins' => 60,
		),
	);
}

function iboh_test_add_days( $ymd, $days ) {
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, new DateTimeZone( 'UTC' ) );
	return $date->modify( (int) $days . ' days' )->format( 'Y-m-d' );
}

function iboh_test_private( $class, $method, $args ) {
	$reflection = new ReflectionMethod( $class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $args );
}

// Continuous, overnight, touching, and 24/7 evaluation.
$cfg = iboh_test_config();
$cfg['schedule'][1] = array(
	'closed' => 0,
	'ranges' => array( array( 'open' => '22:00', 'close' => '02:00' ) ),
);
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-03 23:30:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( true, $state['open'], 'Overnight range should be open before midnight' );
iboh_test_same( 'Closes at Tue 4 Aug, 02:00', $state['sub'], 'Overnight close should include its real next-day endpoint' );
iboh_test_same( "We're open", $state['main'], 'Overnight range should not report an artificial midnight closing-soon state' );

$cfg = iboh_test_config();
$cfg['schedule'][1] = array(
	'closed' => 0,
	'ranges' => array(
		array( 'open' => '09:00', 'close' => '12:00' ),
		array( 'open' => '11:00', 'close' => '17:00' ),
	),
);
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-03 10:30:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( 'Closes at 17:00', $state['sub'], 'Overlapping ranges should close at the merged endpoint' );

$cfg['schedule'][1]['ranges'][1]['open'] = '12:00';
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-03 11:00:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( 'Closes at 17:00', $state['sub'], 'Touching ranges should be one continuous opening' );

$cfg = iboh_test_config();
$cfg['schedule'][1] = array( 'closed' => 0, 'ranges' => array( array( 'open' => '09:00', 'close' => '24:00' ) ) );
$cfg['schedule'][2] = array( 'closed' => 0, 'ranges' => array( array( 'open' => '00:00', 'close' => '24:00' ) ) );
$cfg['schedule'][3] = array( 'closed' => 0, 'ranges' => array( array( 'open' => '00:00', 'close' => '17:00' ) ) );
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-03 10:00:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( 'Closes at Wed 5 Aug, 17:00', $state['sub'], 'A multi-day continuous run should identify its closing date' );

$cfg = iboh_test_config();
for ( $day = 0; $day < 7; $day++ ) {
	$cfg['schedule'][ $day ] = array(
		'closed' => 0,
		'ranges' => array( array( 'open' => '00:00', 'close' => '24:00' ) ),
	);
}
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-03 23:59:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( true, $state['open'], 'A continuous 24/7 week should remain open' );
iboh_test_same( '', $state['sub'], 'A continuous 24/7 week should have no artificial close time' );

// Current special dates own their complete calendar date.
$cfg = iboh_test_config();
$cfg['schedule'][1] = array(
	'closed' => 0,
	'ranges' => array( array( 'open' => '22:00', 'close' => '02:00' ) ),
);
$cfg['holidays']['2026-08-04'] = array( 'closed' => 1, 'ranges' => array(), 'label' => '' );
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-04 00:30:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( false, $state['open'], 'A closed current-date override should suppress yesterday weekly tail' );

$cfg['holidays']['2026-08-04'] = array(
	'closed' => 0,
	'ranges' => array( array( 'open' => '10:00', 'close' => '12:00' ) ),
	'label'  => '',
);
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-04 00:30:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( false, $state['open'], 'An open current-date override should also suppress yesterday weekly tail' );

// Extended next-open search, including a sparse weekly schedule.
$cfg = iboh_test_config();
$cfg['schedule'][0] = array(
	'closed' => 0,
	'ranges' => array( array( 'open' => '09:00', 'close' => '17:00' ) ),
);
for ( $occurrence = 0; $occurrence < 10; $occurrence++ ) {
	$cfg['holidays'][ iboh_test_add_days( '2026-08-03', 6 + $occurrence * 7 ) ] = array(
		'closed' => 1,
		'ranges' => array(),
		'label'  => '',
	);
}
$next = iboh_test_private(
	'IBOH_Evaluator',
	'find_next_open',
	array( $cfg, '2026-08-03', 1, 18 * 60 )
);
iboh_test_same( 76, $next['dayOffset'], 'Sparse weekly search should survive ten same-weekday overrides' );

$cfg = iboh_test_config();
$cfg['options']['weekly_enabled'] = 0;
$cfg['holidays']['2026-08-20'] = array(
	'closed' => 0,
	'ranges' => array( array( 'open' => '10:00', 'close' => '14:00' ) ),
	'label'  => 'Market',
);
$next = iboh_test_private(
	'IBOH_Evaluator',
	'find_next_open',
	array( $cfg, '2026-08-03', 1, 12 * 60 )
);
iboh_test_same( 17, $next['dayOffset'], 'Special-only next opening should not be capped at seven days' );

$cfg = iboh_test_config();
$cfg['holidays']['2026-08-20'] = array(
	'closed' => 0,
	'ranges' => array( array( 'open' => '10:00', 'close' => '14:00' ) ),
	'label'  => 'Market',
);
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-03 18:00:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( 'Opens Thu 20 Aug at 10:00', $state['sub'], 'A distant special opening should show its exact date, not only a weekday' );

// Opening-soon across midnight and explicit upcoming visibility.
$cfg = iboh_test_config();
$cfg['banner']['soon_mins'] = 30;
$cfg['schedule'][2] = array(
	'closed' => 0,
	'ranges' => array( array( 'open' => '00:10', 'close' => '01:00' ) ),
);
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-03 23:50:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( 'Opening soon', $state['main'], 'Opening soon should cross midnight using an absolute delta' );

$cfg = iboh_test_config();
$cfg['options']['weekly_enabled'] = 0;
$cfg['holidays']['2026-08-20'] = array(
	'closed' => 0,
	'ranges' => array( array( 'open' => '10:00', 'close' => '14:00' ) ),
	'label'  => 'Market',
);
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-03 12:00:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( false, $state['show'], 'Special-only schedules should honor disabled upcoming notices' );
$cfg['options']['show_upcoming'] = 1;
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-03 12:00:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( true, $state['show'], 'Special-only schedules should show enabled upcoming notices' );
iboh_test_same( false, $state['open'], 'A future-only announcement should never be styled as currently open' );

// Safe structured output must honor custom templates and localized lists.
$cfg = iboh_test_config();
$cfg['options']['show_upcoming'] = 1;
$cfg['labels']['upcoming_closed'] = '<script>Unavailable on %s!</script>';
$cfg['labels']['upcoming_hours'] = 'Hours %2$s on %1$s';
$cfg['list']['pair'] = '%1$s plus %2$s';
$cfg['holidays']['2026-08-20'] = array( 'closed' => 1, 'ranges' => array(), 'label' => 'Rest' );
$cfg['holidays']['2026-08-21'] = array(
	'closed' => 0,
	'ranges' => array( array( 'open' => '10:00', 'close' => '14:00' ) ),
	'label'  => 'Market',
);
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-03 12:00:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_check( false !== strpos( $state['upcoming_html'], '&lt;script&gt;Unavailable on <strong' ), 'Custom closed template should be used and escaped in structured HTML' );
iboh_test_check( false !== strpos( $state['upcoming_html'], 'Hours <span class="iboh-upcoming-detail">10:00 – 14:00</span> on <strong' ), 'Custom special-hours template order should be preserved in structured HTML' );
iboh_test_check( false === strpos( $state['upcoming_html'], '<script>' ), 'Custom template markup must not be executable' );
iboh_test_check( false !== strpos( $state['upcoming_html'], '</span> <span class="iboh-upcoming-item">' ), 'Structured notices should retain an accessible text boundary between items' );

$cfg['holidays']['2026-08-21'] = array( 'closed' => 1, 'ranges' => array(), 'label' => 'Rest 2' );
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-08-03 12:00:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_check( false !== strpos( $state['upcoming_html'], ' plus ' ), 'Localized pair pattern should join structured closed dates' );

// DST-independent wall-time formatting in both evaluator and shortcode.
$GLOBALS['iboh_test_timezone'] = new DateTimeZone( 'Europe/London' );
$cfg = iboh_test_config();
$cfg['banner']['soon_mins'] = 60;
$cfg['schedule'][0] = array(
	'closed' => 0,
	'ranges' => array( array( 'open' => '00:00', 'close' => '02:00' ) ),
);
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-03-29 00:30:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( 'Closing soon', $state['main'], 'Spring DST gap should use 30 real minutes to a 02:00 close' );
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-10-25 00:30:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( "We're open", $state['main'], 'Autumn DST fold should use 90 real minutes to a 02:00 close' );
$cfg['banner']['soon_mins'] = 20;
$cfg['schedule'][0]['ranges'][0]['close'] = '01:45';
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-10-25 00:30:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( 'Closing soon', $state['main'], 'The first repeated DST hour should select its first 01:45 boundary' );
$state = IBOH_Evaluator::state( $cfg, new DateTimeImmutable( '2026-10-25 01:30:00', new DateTimeZone( 'UTC' ) ) );
iboh_test_same( 'Closing soon', $state['main'], 'The second repeated DST hour should select its second 01:45 boundary' );

foreach ( array( '2026-03-29', '2026-10-25' ) as $transition_date ) {
	$GLOBALS['iboh_test_clock'] = new DateTimeImmutable( $transition_date . ' 00:00:00', $GLOBALS['iboh_test_timezone'] );
	iboh_test_same(
		'09:00',
		iboh_test_private( 'IBOH_Evaluator', 'fmt_time', array( 9 * 60 ) ),
		'Evaluator should preserve 09:00 wall time on ' . $transition_date
	);
	iboh_test_same(
		'09:00',
		iboh_test_private( 'IBOH_Shortcode', 'fmt', array( '09:00' ) ),
		'Shortcode should preserve 09:00 wall time on ' . $transition_date
	);
}
$GLOBALS['iboh_test_options']['time_format'] = 'H:i T \\T';
iboh_test_same(
	'09:00 T T',
	iboh_test_private( 'IBOH_Evaluator', 'fmt_time', array( 9 * 60 ) ),
	'PHP evaluator should treat unsupported and escaped time-format characters literally'
);
iboh_test_same(
	'09:00 T T',
	iboh_test_private( 'IBOH_Shortcode', 'fmt', array( '09:00' ) ),
	'PHP shortcode should match browser literal time-format handling'
);
$GLOBALS['iboh_test_options']['time_format'] = 'H:i';
$GLOBALS['iboh_test_timezone'] = new DateTimeZone( 'UTC' );
$GLOBALS['iboh_test_clock']    = new DateTimeImmutable( '2026-08-04 12:00:00', $GLOBALS['iboh_test_timezone'] );

// Empty storage uses translated runtime defaults; malformed storage is closed.
$GLOBALS['iboh_test_options'][ IBOH_OPTION ] = array();
$settings = IBOH_Settings::all();
iboh_test_same( 1, $settings['options']['weekly_enabled'], 'Empty option should retain the weekly default' );
iboh_test_same( 1, $settings['banner']['enabled'], 'Empty option should retain the enabled banner default' );
iboh_test_same( 0, $settings['schedule'][1]['closed'], 'Empty option should retain the Monday default' );

$GLOBALS['iboh_test_options'][ IBOH_OPTION ] = array(
	'schedule' => array(
		1 => array( 'closed' => 0, 'ranges' => array( array( 'open' => array(), 'close' => '17:00' ) ) ),
		2 => array( 'closed' => 0, 'ranges' => array() ),
	),
);
$settings = IBOH_Settings::all();
iboh_test_same( 1, $settings['schedule'][1]['closed'], 'Malformed stored ranges should normalize to closed' );
iboh_test_same( 1, $settings['schedule'][2]['closed'], 'An open weekday with no valid ranges should normalize to closed' );

$GLOBALS['iboh_test_options'][ IBOH_OPTION ] = array();
$saved = IBOH_Settings::sanitize(
	array(
		'schedule' => iboh_test_closed_schedule(),
		'holidays' => array(
			array(
				'date'   => "2026-08-04\0invalid",
				'closed' => 1,
			),
		),
	)
);
iboh_test_same( array(), $saved['holidays'], 'Malformed dates should be rejected before modern PHP date parsing' );

// Yesterday is hidden from admin callers, retained for evaluators, and survives a save.
$GLOBALS['iboh_test_options'][ IBOH_OPTION ] = array(
	'holidays' => array(
		'2026-08-03' => array( 'closed' => 1, 'ranges' => array(), 'label' => 'Yesterday closed' ),
		'2026-08-04' => array( 'closed' => 1, 'ranges' => array(), 'label' => 'Today closed' ),
	),
);
iboh_test_check( ! isset( IBOH_Settings::all( false )['holidays']['2026-08-03'] ), 'Admin settings should hide yesterday' );
iboh_test_check( isset( IBOH_Settings::all( true )['holidays']['2026-08-03'] ), 'Evaluator settings should retain yesterday' );
$saved = IBOH_Settings::sanitize( array( 'schedule' => iboh_test_closed_schedule(), 'holidays' => array() ) );
iboh_test_check( isset( $saved['holidays']['2026-08-03'] ), 'Saving after midnight should preserve a yesterday closed override' );
$saved = IBOH_Settings::sanitize(
	array(
		'schedule' => iboh_test_closed_schedule(),
		'holidays' => array(
			array(
				'date'   => '2026-08-03',
				'closed' => 0,
				'ranges' => array( array( 'open' => '22:00', 'close' => '03:00' ) ),
				'label'  => 'Edited after midnight',
			),
		),
	)
);
iboh_test_same( 0, $saved['holidays']['2026-08-03']['closed'], 'A submitted yesterday row should replace its saved fallback after midnight' );
iboh_test_same( 'Edited after midnight', $saved['holidays']['2026-08-03']['label'], 'Yesterday edits should not be discarded by the server' );

// Server caps remain authoritative and report truncation.
$GLOBALS['iboh_test_errors'] = array();
$ranges = array();
for ( $index = 0; $index < 7; $index++ ) {
	$ranges[] = array( 'open' => '09:00', 'close' => '10:00' );
}
$input = array(
	'schedule' => iboh_test_closed_schedule(),
	'holidays' => array(),
);
$input['schedule'][1] = array( 'closed' => 0, 'ranges' => $ranges );
$saved = IBOH_Settings::sanitize( $input );
iboh_test_same( IBOH_Settings::MAX_RANGES, count( $saved['schedule'][1]['ranges'] ), 'Server should cap ranges per day' );
iboh_test_check(
	in_array( 'iboh_ranges_truncated', array_column( $GLOBALS['iboh_test_errors'], 'code' ), true ),
	'Range truncation should add a Settings API error'
);

$GLOBALS['iboh_test_errors'] = array();
$holidays = array();
for ( $index = 0; $index < 201; $index++ ) {
	$date = iboh_test_add_days( '2026-08-04', $index );
	$holidays[] = array( 'date' => $date, 'closed' => 1, 'ranges' => array(), 'label' => '' );
}
$saved = IBOH_Settings::sanitize(
	array(
		'schedule' => iboh_test_closed_schedule(),
		'holidays' => $holidays,
	)
);
$current_count = count( array_filter( array_keys( $saved['holidays'] ), function ( $date ) {
	return $date >= '2026-08-04';
} ) );
iboh_test_same( IBOH_Settings::MAX_HOLIDAYS, $current_count, 'Server should cap current/future special dates independently of yesterday carry' );
iboh_test_check(
	in_array( 'iboh_holidays_truncated', array_column( $GLOBALS['iboh_test_errors'], 'code' ), true ),
	'Special-date truncation should add a Settings API error'
);

// Read-time cap, sparse labels, and raw-option-aware cache behavior.
$raw_holidays = array(
	'2026-08-03' => array( 'closed' => 1, 'ranges' => array(), 'label' => 'Carry' ),
);
for ( $index = 0; $index < 205; $index++ ) {
	$date = iboh_test_add_days( '2026-08-04', $index );
	$raw_holidays[ $date ] = array( 'closed' => 1, 'ranges' => array(), 'label' => '' );
}
$GLOBALS['iboh_test_options'][ IBOH_OPTION ] = array( 'holidays' => $raw_holidays );
iboh_test_same( 201, count( IBOH_Settings::all( true )['holidays'] ), 'Read normalization should cap 200 current/future dates plus yesterday carry' );

$defaults = IBOH_Settings::defaults();
$saved = IBOH_Settings::sanitize(
	array(
		'schedule' => iboh_test_closed_schedule(),
		'labels'   => $defaults['labels'],
	)
);
iboh_test_same( array(), $saved['labels'], 'Untouched translated defaults should be stored sparsely' );
$saved = IBOH_Settings::sanitize(
	array(
		'schedule' => iboh_test_closed_schedule(),
		'labels'   => array( 'open' => 'Come on in' ),
	)
);
iboh_test_same( 'Come on in', $saved['labels']['open'], 'Custom wording should remain an explicit override' );

// Legacy source-language defaults migrate to the active translation, while a
// deliberately submitted source-language value remains an explicit override.
$GLOBALS['iboh_test_locale'] = 'fr_FR';
$GLOBALS['iboh_test_translations'][ "We're open" ] = 'Nous sommes ouverts';
$GLOBALS['iboh_test_options'][ IBOH_OPTION ] = array(
	'labels' => $defaults['labels'],
);
iboh_test_same(
	'Nous sommes ouverts',
	IBOH_Settings::all()['labels']['open'],
	'An unmarked legacy source default should follow the current translation'
);

$translated_defaults = IBOH_Settings::defaults();
$saved = IBOH_Settings::sanitize(
	array(
		'schedule' => iboh_test_closed_schedule(),
		'labels'   => array( 'open' => "We're open" ),
	)
);
iboh_test_check( ! empty( $saved[ IBOH_Settings::LABEL_OVERRIDE_MARKER ] ), 'Sparse label saves should carry an override marker' );
$GLOBALS['iboh_test_options'][ IBOH_OPTION ] = $saved;
iboh_test_same(
	"We're open",
	IBOH_Settings::all()['labels']['open'],
	'An intentional source-language label should survive on a translated site'
);
$saved = IBOH_Settings::sanitize(
	array(
		'schedule' => iboh_test_closed_schedule(),
		'labels'   => $translated_defaults['labels'],
	)
);
iboh_test_same( array(), $saved['labels'], 'Untouched non-English defaults should also be stored sparsely' );
$GLOBALS['iboh_test_locale']       = 'en_GB';
$GLOBALS['iboh_test_translations'] = array();

// A one-time upgrade recognizes one coherent previously active locale while
// preserving a deliberate value that happens to equal another locale's default.
$source_defaults = $defaults['labels'];
$fr_map = array();
$de_map = array();
foreach ( $source_defaults as $source_text ) {
	$fr_map[ $source_text ] = 'FR ' . $source_text;
	$de_map[ $source_text ] = 'DE ' . $source_text;
}
$GLOBALS['iboh_test_languages'] = array( 'de_DE', 'fr_FR' );
$GLOBALS['iboh_test_locale_maps'] = array(
	'fr_FR' => $fr_map,
	'de_DE' => $de_map,
);
$GLOBALS['iboh_test_locale'] = 'fr_FR';
$fr_defaults = IBOH_Settings::defaults()['labels'];
$GLOBALS['iboh_test_locale'] = 'de_DE';
$de_defaults = IBOH_Settings::defaults()['labels'];
$GLOBALS['iboh_test_locale_stack'] = array();
$legacy_defaults_property = new ReflectionProperty( 'IBOH_Settings', 'legacy_label_defaults' );
$legacy_defaults_property->setAccessible( true );
$legacy_defaults_property->setValue( null, null );
$GLOBALS['iboh_test_options'][ IBOH_OPTION ] = array(
	'labels' => $fr_defaults,
);
IBOH_Settings::maybe_migrate_labels();
$migrated = $GLOBALS['iboh_test_options'][ IBOH_OPTION ];
iboh_test_check( ! empty( $migrated[ IBOH_Settings::LABEL_OVERRIDE_MARKER ] ), 'Legacy label migration should persist its sparse marker' );
iboh_test_same( array(), $migrated['labels'], 'A complete prior-locale default set should become sparse during migration' );
iboh_test_same( $de_defaults['open'], IBOH_Settings::all()['labels']['open'], 'Migrated labels should follow the active locale' );
iboh_test_same( 'de_DE', $GLOBALS['iboh_test_locale'], 'Legacy locale discovery should restore the starting locale' );
iboh_test_same( array(), $GLOBALS['iboh_test_locale_stack'], 'Legacy locale discovery should leave no locale-stack entries behind' );

$cross_locale = $de_defaults;
$cross_locale['open'] = $fr_defaults['open'];
$GLOBALS['iboh_test_options'][ IBOH_OPTION ] = array( 'labels' => $cross_locale );
IBOH_Settings::maybe_migrate_labels();
$migrated = $GLOBALS['iboh_test_options'][ IBOH_OPTION ];
iboh_test_same(
	array( 'open' => $fr_defaults['open'] ),
	$migrated['labels'],
	'A cross-locale value should remain a deliberate override when the other labels coherently match the active locale'
);
iboh_test_same( $fr_defaults['open'], IBOH_Settings::all()['labels']['open'], 'A preserved cross-locale override should remain active' );
iboh_test_same( $de_defaults['closed'], IBOH_Settings::all()['labels']['closed'], 'Other migrated labels should continue to use active-locale defaults' );
iboh_test_same( 'de_DE', $GLOBALS['iboh_test_locale'], 'Repeated migration should preserve the active locale' );
iboh_test_same( array(), $GLOBALS['iboh_test_locale_stack'], 'Repeated migration should leave the locale stack empty' );
$GLOBALS['iboh_test_locale']      = 'en_GB';
$GLOBALS['iboh_test_locale_maps'] = array();
$GLOBALS['iboh_test_languages']   = array();
$GLOBALS['iboh_test_locale_stack'] = array();

$GLOBALS['iboh_test_options'][ IBOH_OPTION ] = array(
	'schedule' => array(
		1 => array( 'closed' => 0, 'ranges' => array( array( 'open' => '09:00', 'close' => '17:00' ) ) ),
	),
);
iboh_test_same( 0, IBOH_Settings::all()['schedule'][1]['closed'], 'Initial cached raw option should be open' );
$GLOBALS['iboh_test_options'][ IBOH_OPTION ]['schedule'][1] = array( 'closed' => 1, 'ranges' => array() );
iboh_test_same( 1, IBOH_Settings::all()['schedule'][1]['closed'], 'Cache key should detect a raw option change in the same request' );

// Public config retains evaluator context, and block/status markup preserves
// WordPress support attributes and live-region semantics without JavaScript.
$GLOBALS['iboh_test_options'][ IBOH_OPTION ] = array(
	'holidays' => array(
		'2026-08-03' => array( 'closed' => 1, 'ranges' => array(), 'label' => 'Carry' ),
	),
);
$GLOBALS['iboh_test_locale'] = 'pt_PT_ao90';
$public_config = IBOH_Config::data();
iboh_test_check( isset( $public_config['holidays']['2026-08-03'] ), 'Public config should retain yesterday for overnight evaluation' );
iboh_test_check( isset( $public_config['meridiem']['AM'], $public_config['list']['pair'] ), 'Public config should include localized time/list formatting data' );
iboh_test_same( 'pt-PT', $public_config['lang'], 'Public config should remove invalid WordPress locale suffixes before Intl formatting' );
iboh_test_same( '1|https://example.test/', $public_config['siteKey'], 'Public config should namespace dismissal storage by site and home URL' );
iboh_test_same( 'Thu', $public_config['dateNames']['weekdays'][4], 'Public config should supply the exact WordPress short weekday names' );
iboh_test_same( 'Aug', $public_config['dateNames']['months'][7], 'Public config should supply the exact WordPress short month names' );
$GLOBALS['iboh_test_locale'] = 'en_GB';

$status_html = IBOH_Shortcode::status_html();
iboh_test_check(
	false !== strpos( $status_html, 'role="status" aria-live="polite" aria-atomic="true"' ),
	'Server-rendered inline status should be a polite atomic live region'
);
$block_html = IBOH_Block::render( array( 'show' => 'table' ) );
iboh_test_check(
	0 === strpos( $block_html, '<div class="wp-block-iboh-opening-hours custom-class">' ),
	'Dynamic block should preserve get_block_wrapper_attributes output'
);
$block = new IBOH_Block();
$block->init();
iboh_test_same( 'iboh/opening-hours', $GLOBALS['iboh_test_registered_block']['name'], 'Dynamic block should register under its public name' );
iboh_test_same( 3, $GLOBALS['iboh_test_registered_block']['args']['api_version'], 'Dynamic block should use iframe-compatible Block API version 3' );

// Multisite uninstall removes the per-site option without direct SQL.
$GLOBALS['iboh_test_multisite']      = true;
$GLOBALS['iboh_test_uninstall_mode'] = true;
define( 'WP_UNINSTALL_PLUGIN', true );
require dirname( __DIR__ ) . '/uninstall.php';
iboh_test_same( array( 11, 12 ), $GLOBALS['iboh_test_deleted_blogs'], 'Multisite uninstall should delete the option on every site' );
iboh_test_same( 1, get_current_blog_id(), 'Multisite uninstall should restore the original blog' );

if ( ! empty( $GLOBALS['iboh_test_failures'] ) ) {
	fwrite( STDERR, "PHP regression failures:\n- " . implode( "\n- ", $GLOBALS['iboh_test_failures'] ) . "\n" );
	exit( 1 );
}

echo 'PHP regression checks passed: ' . (int) $GLOBALS['iboh_test_passes'] . PHP_EOL;
