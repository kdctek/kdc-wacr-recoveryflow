<?php
/**
 * A very small stand-in for WordPress.
 *
 * Enough of the API for the dependency-free smoke runner to boot the plugin's
 * pure classes without a database or a WordPress install. It is deliberately
 * not a mocking framework: the point is that these classes can be exercised
 * with nothing but PHP.
 *
 * @package WAcr\RecoveryFlow
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );

define( 'KDC_WACR_RECOVERYFLOW_VERSION', '0.1.0' );
define( 'KDC_WACR_RECOVERYFLOW_FILE', dirname( __DIR__ ) . '/kdc-wacr-recoveryflow.php' );
define( 'KDC_WACR_RECOVERYFLOW_DIR', dirname( __DIR__ ) . '/' );
define( 'KDC_WACR_RECOVERYFLOW_URL', 'https://example.test/wp-content/plugins/kdc-wacr-recoveryflow/' );
define( 'KDC_WACR_RECOVERYFLOW_BASENAME', 'kdc-wacr-recoveryflow/kdc-wacr-recoveryflow.php' );
define( 'KDC_WACR_RECOVERYFLOW_MIN_PHP', '8.0' );
define( 'KDC_WACR_RECOVERYFLOW_MIN_WP', '6.5' );

// WordPress defines these in default-constants.php before any plugin loads, so
// plugin code may legitimately use them in a class-constant expression -- which
// is evaluated at class-load time, long before any function stub could stand in.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );

define( 'AUTH_KEY', 'auth-key-for-tests-only' );
define( 'SECURE_AUTH_KEY', 'secure-auth-key-for-tests-only' );
define( 'LOGGED_IN_KEY', 'logged-in-key-for-tests-only' );
define( 'NONCE_KEY', 'nonce-key-for-tests-only' );

$GLOBALS['__options']  = array( 'permalink_structure' => '/%postname%/' );
$GLOBALS['__actions']  = array();
$GLOBALS['__filters']  = array();
$GLOBALS['__rewrites'] = array();
$GLOBALS['__query_vars'] = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__scripts']    = array();

// WooCommerce page context, driven by the tests rather than by a real request.
$GLOBALS['__is_checkout']       = false;
$GLOBALS['__is_order_received'] = false;
$GLOBALS['__is_pay_page']       = false;

// Options.
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function add_option( $name, $value, $deprecated = '', $autoload = true ) {
	if ( array_key_exists( $name, $GLOBALS['__options'] ) ) {
		return false;
	}
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['__options'][ $name ] );
	return true;
}

// WooCommerce conditionals and the script queue.
function is_checkout() {
	return ! empty( $GLOBALS['__is_checkout'] );
}
function is_order_received_page() {
	return ! empty( $GLOBALS['__is_order_received'] );
}
function is_checkout_pay_page() {
	return ! empty( $GLOBALS['__is_pay_page'] );
}
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = false ) {
	$GLOBALS['__scripts'][ $handle ] = array(
		'src'  => $src,
		'deps' => $deps,
		'ver'  => $ver,
		'args' => $args,
	);
	return true;
}

// Hooks.
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['__actions'][ $hook ][] = $callback;
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['__filters'][ $hook ][] = $callback;
	return true;
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $callback ) {
		$callback( ...$args );
	}
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function did_action( $hook ) {
	return isset( $GLOBALS['__actions'][ $hook ] ) ? 1 : 0;
}

// Strings and escaping.
function __( $text, $domain = null ) {
	return $text;
}
function _x( $text, $context, $domain = null ) {
	return $text;
}
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}
function _nx( $single, $plural, $number, $context, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}
function _e( $text, $domain = null ) {
	echo $text;
}
function _ex( $text, $context, $domain = null ) {
	echo $text;
}
function translate( $text, $domain = null ) {
	return $text;
}
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}
function date_i18n( $format, $timestamp = null ) {
	return gmdate( $format, null === $timestamp ? time() : (int) $timestamp );
}
function wp_date( $format, $timestamp = null, $timezone = null ) {
	return gmdate( $format, null === $timestamp ? time() : (int) $timestamp );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}
function esc_attr( $text ) {
	return esc_html( $text );
}
function esc_attr__( $text, $domain = null ) {
	return esc_attr( $text );
}
function esc_html_x( $text, $context, $domain = null ) {
	return esc_html( $text );
}
function esc_attr_x( $text, $context, $domain = null ) {
	return esc_attr( $text );
}
function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text );
}
function esc_attr_e( $text, $domain = null ) {
	echo esc_attr( $text );
}
function esc_url( $url ) {
	return $url;
}
function esc_url_raw( $url ) {
	return $url;
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function wp_strip_all_tags( $value, $remove_breaks = false ) {
	$value = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $value );
	$value = strip_tags( $value );

	if ( $remove_breaks ) {
		$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );
	}

	return trim( $value );
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_email( $value ) {
	return filter_var( (string) $value, FILTER_SANITIZE_EMAIL ) ?: '';
}
function is_email( $value ) {
	return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}
function absint( $value ) {
	return abs( (int) $value );
}
function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

// URLs and rewrites.
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}
function add_query_arg( $args, $url = '' ) {
	$separator = false === strpos( $url, '?' ) ? '?' : '&';
	return $url . $separator . http_build_query( $args );
}
function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {
	$GLOBALS['__rewrites'][ $regex ] = $query;
}
function get_query_var( $var, $default = '' ) {
	return $GLOBALS['__query_vars'][ $var ] ?? $default;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['__transients'][ $key ] = $value;
	return true;
}
function get_transient( $key ) {
	return $GLOBALS['__transients'][ $key ] ?? false;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__transients'][ $key ] );
	return true;
}
function wp_remote_request( $url, $args = array() ) {
	return $GLOBALS['__http_response'] ?? array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() );
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 0;
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}
function wp_remote_retrieve_header( $response, $header ) {
	return $response['headers'][ $header ] ?? '';
}
function get_bloginfo( $what = '' ) {
	return 'version' === $what ? '6.9' : 'Example Store';
}
function current_user_can( $cap ) {
	return true;
}
function is_multisite() {
	return false;
}
function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}
function plugin_dir_url( $file ) {
	return KDC_WACR_RECOVERYFLOW_URL;
}
function plugin_basename( $file ) {
	return KDC_WACR_RECOVERYFLOW_BASENAME;
}
function load_plugin_textdomain( $domain, $deprecated, $path ) {
	return true;
}
/*
 * The admin. Enough of wp-admin to render the settings screen for real in the
 * smoke run -- which is the point of stubbing it at all. A settings screen that
 * is only ever eyeballed in a browser is one whose deeplinks, escaping and
 * conditional fields are checked by nobody; rendering it here means an assertion
 * can read the actual HTML the merchant would be served.
 */
function is_admin() {
	return true;
}
function admin_url( $path = '' ) {
	return 'https://shop.example/wp-admin/' . ltrim( $path, '/' );
}
function add_menu_page( $page_title, $menu_title, $capability, $slug, $callback = '', $icon = '', $position = null ) {
	return 'toplevel_page_' . $slug;
}
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback = '' ) {
	return $parent . '_page_' . $slug;
}
function register_setting( $group, $option, $args = array() ) {
	$GLOBALS['recoveryflow_registered_settings'][ $group ] = $args;
}
function settings_fields( $group ) {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
}
function settings_errors( $slug = '' ) {}
function submit_button( $text = null, $type = 'primary', $name = 'submit' ) {
	echo '<p class="submit"><button type="submit" class="button button-primary">Save</button></p>';
}
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['recoveryflow_styles'][ $handle ] = $src;
}
function wp_add_inline_script( $handle, $data, $position = 'after' ) {
	$GLOBALS['recoveryflow_inline_scripts'][ $handle ][] = $data;
}
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new RuntimeException( is_string( $message ) ? $message : 'wp_die' );
}
function checked( $checked, $current = true, $echo = true ) {
	$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}
function selected( $selected, $current = true, $echo = true ) {
	$out = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}
function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function sanitize_html_class( $class, $fallback = '' ) {
	$clean = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
	return '' === $clean ? $fallback : $clean;
}
function sanitize_textarea_field( $value ) {
	return trim( wp_strip_all_tags( (string) $value ) );
}

function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function flush_rewrite_rules( $hard = true ) {}
function wp_next_scheduled( $hook, $args = array() ) {
	return false;
}
function wp_unschedule_event( $timestamp, $hook, $args = array() ) {}
function wp_clear_scheduled_hook( $hook, $args = array() ) {}

/**
 * WordPress's error object, for the transport tests.
 */
class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}

/**
 * A role, with just enough behaviour for the capability installer.
 */
class WP_Role {
	public $name;
	public $capabilities = array();

	public function __construct( $name, $capabilities = array() ) {
		$this->name         = $name;
		$this->capabilities = $capabilities;
	}
	public function has_cap( $cap ) {
		return ! empty( $this->capabilities[ $cap ] );
	}
	public function add_cap( $cap, $grant = true ) {
		$this->capabilities[ $cap ] = $grant;
	}
	public function remove_cap( $cap ) {
		unset( $this->capabilities[ $cap ] );
	}
}

/**
 * A user, for the meta-capability tests.
 */
class WP_User {
	public $ID      = 1;
	public $allcaps = array();

	public function __construct( $id = 1, $allcaps = array() ) {
		$this->ID      = $id;
		$this->allcaps = $allcaps;
	}
}

$GLOBALS['__roles'] = array(
	'administrator' => new WP_Role( 'administrator', array( 'manage_options' => true ) ),
	'shop_manager'  => new WP_Role( 'shop_manager', array( 'manage_woocommerce' => true ) ),
	'subscriber'    => new WP_Role( 'subscriber', array( 'read' => true ) ),
);
$GLOBALS['__users'] = array();

function get_role( $name ) {
	return $GLOBALS['__roles'][ $name ] ?? null;
}
function wp_roles() {
	$roles        = new stdClass();
	$roles->roles = $GLOBALS['__roles'];
	return $roles;
}
function get_userdata( $user_id ) {
	return $GLOBALS['__users'][ $user_id ] ?? false;
}

// wpdb's result-format constants. Without them any repository method that asks
// for rows as arrays fatals on an undefined constant rather than returning.
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );

/**
 * The bare wpdb type.
 *
 * Repositories declare `: \wpdb` on their database accessor, so a stand-in that
 * is merely shaped like wpdb is rejected by PHP before a single query runs --
 * which quietly put every repository method out of reach of the smoke suite.
 * Declaring the type and extending it is what lets these tests exercise the
 * real query-building code paths instead of stopping at the boundary.
 */
class wpdb {
	public $prefix = 'wp_';
}

/**
 * Enough of wpdb to read charset, prefix and to record queries.
 */
class Fake_Wpdb extends wpdb {
	public $prefix  = 'wp_';
	public $queries = array();
	public $insert_id = 0;
	public $last_error = '';

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}
	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query = str_replace( array( '%s', '%d', '%f' ), '%s', $query );
		return vsprintf( $query, array_map( static fn( $a ) => is_numeric( $a ) ? $a : "'" . $a . "'", $args ) );
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}
	public function get_row( $sql, $output = null ) {
		$this->queries[] = $sql;
		return null;
	}
	public function get_results( $sql, $output = null ) {
		$this->queries[] = $sql;
		return array();
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		return null;
	}
}

$GLOBALS['wpdb'] = new Fake_Wpdb();
