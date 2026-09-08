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
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}
function esc_attr( $text ) {
	return esc_html( $text );
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

/**
 * Enough of wpdb to read charset, prefix and to record queries.
 */
class Fake_Wpdb {
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
