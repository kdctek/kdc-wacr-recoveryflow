<?php
/**
 * Dependency-free smoke tests.
 *
 * Run with: php tests/smoke.php
 *
 * These cover the parts that are easy to get subtly wrong and that clicking
 * around wp-admin would never reliably catch: the autoloader's path mapping,
 * the encryption round trip and its failure mode, keyed hashing, the schema's
 * dbDelta dialect, and the recovery-link token shape. They boot the real
 * classes against a small WordPress stand-in rather than a parallel set of
 * hand-built objects, so the wiring itself is under test.
 *
 * @package WAcr\RecoveryFlow
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/src/Core/Autoloader.php';

use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Admin\Settings\Page as Settings_Page;
use WAcr\RecoveryFlow\Admin\Settings\Sanitizer as Settings_Sanitizer;
use WAcr\RecoveryFlow\Admin\Settings\Schema as Settings_Schema;
use WAcr\RecoveryFlow\Core\Autoloader;
use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Core\Health;
use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Core\Upgrader;
use WAcr\RecoveryFlow\Core\Plugin;
use WAcr\RecoveryFlow\Core\Requirements;
use WAcr\RecoveryFlow\Core\Rewrites;
use WAcr\RecoveryFlow\Customer\Identity;
use WAcr\RecoveryFlow\Customer\Identity_Repository;
use WAcr\RecoveryFlow\Customer\Mask;
use WAcr\RecoveryFlow\Customer\Phone_Normalizer;
use WAcr\RecoveryFlow\Privacy\Anonymizer;
use WAcr\RecoveryFlow\Privacy\Eraser;
use WAcr\RecoveryFlow\Privacy\Exporter;
use WAcr\RecoveryFlow\Privacy\Redactor;
use WAcr\RecoveryFlow\Recovery\Channel;
use WAcr\RecoveryFlow\Recovery\Email_Compliance;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Integration\WooCommerce\Checkout_Script;
use WAcr\RecoveryFlow\Integration\WooCommerce\Consent_Field;
use WAcr\RecoveryFlow\Integration\WooCommerce\Session;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Database\Schema;
use WAcr\RecoveryFlow\Database\Table_Names;
use WAcr\RecoveryFlow\REST\Abstract_Controller;
use WAcr\RecoveryFlow\REST\Routes;
use WAcr\RecoveryFlow\REST\Settings_Controller;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Security\Crypto;
use WAcr\RecoveryFlow\Security\Hash_Key;
use WAcr\RecoveryFlow\Security\Token_Service;
use WAcr\RecoveryFlow\Support\Options;
use WAcr\RecoveryFlow\Support\Uuid;
use WAcr\RecoveryFlow\Admin\Connection_Test;
use WAcr\RecoveryFlow\Admin\Setup;
use WAcr\RecoveryFlow\WAcr\Credentials;
use WAcr\RecoveryFlow\WAcr\Error;
use WAcr\RecoveryFlow\WAcr\Result;
use WAcr\RecoveryFlow\WAcr\Rate_Budget;
use WAcr\RecoveryFlow\WAcr\Send_Request;
use WAcr\RecoveryFlow\WAcr\Transport;
use WAcr\RecoveryFlow\Admin\Step_Describer;
use WAcr\RecoveryFlow\Admin\Workflow_Form;
use WAcr\RecoveryFlow\Workflow\Workflow_Definition;
use WAcr\RecoveryFlow\Workflow\Workflow_Repository;


( new Autoloader( dirname( __DIR__ ) . '/src/' ) )->register();

require_once __DIR__ . '/probes.php';
require_once __DIR__ . '/rest-probes.php';
require_once __DIR__ . '/i18n-audit.php';


$passed = 0;
$failed = 0;

/**
 * Assert two values match.
 *
 * @param string $label    What is being checked.
 * @param mixed  $actual   Value produced.
 * @param mixed  $expected Value wanted.
 * @return void
 */
function check( string $label, $actual, $expected ): void {
	global $passed, $failed;

	if ( $actual === $expected ) {
		++$passed;
		return;
	}

	++$failed;
	echo "FAIL  {$label}\n";
	echo '      expected: ' . var_export( $expected, true ) . "\n";
	echo '      actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * Assert a condition holds.
 *
 * @param string $label What is being checked.
 * @param bool   $value Condition.
 * @return void
 */
function ok( string $label, bool $value ): void {
	check( $label, $value, true );
}

// ---------------------------------------------------------------- Autoloader.

ok( 'autoloader resolves a namespaced class', class_exists( Clock::class ) );
ok( 'autoloader ignores foreign namespaces', ! class_exists( 'Some\\Other\\Thing' ) );

// --------------------------------------------------------------------- Files.

$files = array();
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src' ) );
foreach ( $iterator as $file ) {
	if ( 'php' === $file->getExtension() ) {
		$files[] = $file->getPathname();
	}
}
ok( 'every src file parses', count( $files ) > 0 );
foreach ( $files as $file ) {
	require_once $file;
}

// --------------------------------------------------------------------- Clock.

$clock = new Clock();
$clock->freeze( 1757000000 );
check( 'clock formats UTC', $clock->now(), gmdate( 'Y-m-d H:i:s', 1757000000 ) );
check( 'clock offsets forward', $clock->offset( 3600 ), gmdate( 'Y-m-d H:i:s', 1757003600 ) );
check( 'clock round-trips a stored datetime', $clock->parse( $clock->now() ), 1757000000 );

// ---------------------------------------------------------------------- Uuid.

$uuid = Uuid::v4();
check( 'uuid is 36 characters', strlen( $uuid ), 36 );
ok( 'uuid validates', Uuid::is_valid( $uuid ) );
ok( 'uuid rejects rubbish', ! Uuid::is_valid( 'not-a-uuid' ) );
ok( 'uuids do not repeat', Uuid::v4() !== Uuid::v4() );

// -------------------------------------------------------------------- Crypto.

ok( 'AES-256-GCM is available', Crypto::available() );
$secret    = 'wacr_live_abcdefghijklmnop';
$encrypted = Crypto::encrypt( $secret );
ok( 'ciphertext is tagged', 0 === strpos( $encrypted, 'rfenc1:' ) );
ok( 'ciphertext does not contain the secret', false === strpos( $encrypted, $secret ) );
check( 'decrypt round-trips', Crypto::decrypt( $encrypted ), $secret );
ok( 'each encryption differs (random IV)', Crypto::encrypt( $secret ) !== $encrypted );
check( 'an unencrypted legacy value passes through', Crypto::decrypt( 'plain-value' ), 'plain-value' );
check( 'an empty secret stays empty', Crypto::encrypt( '' ), '' );
$tampered = 'rfenc1:' . base64_encode( 'not really a ciphertext at all, but long enough to pass the length gate' );
check( 'a tampered payload decrypts to nothing', Crypto::decrypt( $tampered ), '' );
ok( 'a tampered payload is reported as undecryptable', Crypto::is_undecryptable( $tampered ) );
ok( 'a good payload is not reported as undecryptable', ! Crypto::is_undecryptable( $encrypted ) );

// ------------------------------------------------------------------ Hash_Key.

Hash_Key::install();
$hash = Hash_Key::hash( '+919876543210' );
check( 'hash is 64 hex characters', strlen( $hash ), 64 );
check( 'hashing is deterministic', Hash_Key::hash( '+919876543210' ), $hash );
ok( 'different numbers hash differently', Hash_Key::hash( '+919876543211' ) !== $hash );
ok( 'the hash is keyed, not a bare digest', hash( 'sha256', '+919876543210' ) !== $hash );
check( 'an empty value hashes to nothing', Hash_Key::hash( '' ), '' );
check( 'email hashing folds case', Hash_Key::email( 'Asha@Example.com' ), Hash_Key::email( 'asha@example.com' ) );

// -------------------------------------------------------------------- Schema.

$statements = Schema::statements();
check( 'one statement per table', count( $statements ), count( Table_Names::all() ) );

foreach ( $statements as $statement ) {
	ok( 'statement is a CREATE TABLE', 0 === strpos( $statement, 'CREATE TABLE ' ) );
	ok( 'dbDelta needs two spaces after PRIMARY KEY', false !== strpos( $statement, 'PRIMARY KEY  (' ) );
	ok( 'dbDelta needs KEY, never INDEX', false === strpos( $statement, ' INDEX ' ) );
	ok( 'charset collate is applied', false !== strpos( $statement, 'utf8mb4' ) );
	ok( 'table is prefixed', false !== strpos( $statement, 'wp_recoveryflow_' ) );
}

$joined = implode( "\n", $statements );
foreach ( Table_Names::all() as $table ) {
	ok( "table {$table} is created", false !== strpos( $joined, 'CREATE TABLE wp_' . $table . ' (' ) );
}

// The indexes the background stages depend on. A missing one turns a bounded
// batch query into a full table scan on a site with a hundred thousand rows.
ok( 'journeys index the due queue', false !== strpos( $joined, 'KEY due (status,next_action_at)' ) );
ok( 'journeys index the polling queue', false !== strpos( $joined, 'KEY poll (status,poll_at)' ) );
ok( 'journeys index expiry', false !== strpos( $joined, 'KEY expiry (status,expires_at)' ) );
ok( 'events index evaluation', false !== strpos( $joined, 'KEY evaluate (status,journey_id,last_activity_at)' ) );
ok( 'one open event per source key', false !== strpos( $joined, 'UNIQUE KEY source_dedupe (source_id,dedupe_key)' ) );
ok( 'one journey per event', false !== strpos( $joined, 'UNIQUE KEY event_id (event_id)' ) );
ok( 'sends are deduplicated', false !== strpos( $joined, 'UNIQUE KEY idempotency_key (idempotency_key)' ) );
ok( 'recovery tokens are unique', false !== strpos( $joined, 'UNIQUE KEY token_hash (token_hash)' ) );
// Identity is rows, not columns, and the uniqueness rule differs by kind: a
// phone number identifies exactly one person, an email address does not. MySQL
// has no partial index, so the rule rides on unique_value_hash being NULL for
// an email -- NULLs in a UNIQUE index do not collide with one another, which is
// what lets two people share a shared inbox while a race for the same number
// still resolves to one customer.
ok( 'a strong identity is unique', false !== strpos( $joined, 'UNIQUE KEY kind_unique_value (kind,unique_value_hash)' ) );
ok( 'identities are looked up by kind and hash', false !== strpos( $joined, 'KEY kind_value (kind,value_hash)' ) );
ok( 'unique_value_hash may be NULL, which is what exempts email', false !== strpos( $joined, 'unique_value_hash char(64) NULL' ) );
ok( 'customers no longer carry a phone identity column', false === strpos( $joined, 'UNIQUE KEY phone_hash (phone_hash)' ) );

// Consent is keyed by the identity HASH and the channel, never by the identity
// row id: the eraser may delete the row, and a suppression that vanished with
// it would silently grant consent again.
ok( 'consent is keyed by identity and channel', false !== strpos( $joined, 'KEY identity_latest (identity_kind,identity_hash,channel,id)' ) );
ok( 'consent defaults to the WhatsApp channel', false !== strpos( $joined, "channel varchar(16) NOT NULL DEFAULT 'whatsapp'" ) );
ok( 'email is not a unique key', false === strpos( $joined, 'UNIQUE KEY email_hash' ) );

check( 'table name is prefixed once', Table_Names::get( Table_Names::JOURNEYS ), 'wp_recoveryflow_journeys' );

// ------------------------------------------------------------------- Options.

$defaults = Options::defaults();
check( 'the plugin ships switched off', $defaults['enabled'], false );
check( 'consent is required by default', $defaults['eligibility_mode'], 'explicit_consent' );
check( 'uninstall keeps data by default', $defaults['delete_data_on_uninstall'], false );
check( 'default inactivity is 30 minutes', $defaults['inactivity_minutes'], 30 );
check( 'default recovery window is 7 days', $defaults['max_age_days'], 7 );
check( 'default dispatch hands off to WA.cr', $defaults['wacr_dispatch'], 'start_flow' );
check( 'email is not shared by default', $defaults['wacr_share_email'], false );
Options::install();
check( 'a stored setting reads back', Options::get( 'inactivity_minutes' ), 30 );
Options::set( 'inactivity_minutes', 45 );
check( 'a written setting persists', Options::get( 'inactivity_minutes' ), 45 );
check( 'unknown settings fall back', Options::get( 'nope', 'fallback' ), 'fallback' );
ok( 'the API key option is listed for uninstall', in_array( Options::API_KEY, Options::all_option_names(), true ) );

// -------------------------------------------------------------- Capabilities.

Capabilities::install();
$admin = get_role( 'administrator' );
$shop  = get_role( 'shop_manager' );
ok( 'administrators manage settings', $admin->has_cap( Capabilities::MANAGE_SETTINGS ) );
ok( 'administrators view journeys', $admin->has_cap( Capabilities::VIEW_JOURNEYS ) );
ok( 'shop managers work the queue', $shop->has_cap( Capabilities::MANAGE_JOURNEYS ) );
ok( 'shop managers do not hold the settings key', ! $shop->has_cap( Capabilities::MANAGE_SETTINGS ) );
ok( 'subscribers get nothing', ! get_role( 'subscriber' )->has_cap( Capabilities::VIEW_JOURNEYS ) );

check(
	'settings falls back to manage_options for a user without the cap',
	Capabilities::map_meta_cap( array( Capabilities::MANAGE_SETTINGS ), Capabilities::MANAGE_SETTINGS, 7, array() ),
	array( 'manage_options' )
);
$GLOBALS['__users'][8] = new WP_User( 8, array( Capabilities::MANAGE_SETTINGS => true ) );
check(
	'a role holding the cap outright keeps it',
	Capabilities::map_meta_cap( array( Capabilities::MANAGE_SETTINGS ), Capabilities::MANAGE_SETTINGS, 8, array() ),
	array( Capabilities::MANAGE_SETTINGS )
);
check(
	'reading customer details never falls back to manage_options',
	Capabilities::map_meta_cap( array( Capabilities::REVEAL_PII ), Capabilities::REVEAL_PII, 7, array() ),
	array( Capabilities::REVEAL_PII )
);

// A role that appears later -- WooCommerce creates shop_manager when it
// activates -- must still end up with its capabilities.
$GLOBALS['__roles']['late_role'] = new WP_Role( 'late_role', array() );
add_filter(
	Hooks::FILTER_CAPABILITY_MAP,
	static function ( $map ) {
		$map['late_role'] = array( Capabilities::VIEW_JOURNEYS );
		return $map;
	}
);
check( 'a role added later is reported as missing its grant', Capabilities::missing_grants(), array( 'late_role' => array( Capabilities::VIEW_JOURNEYS ) ) );
Capabilities::on_plugin_activated();
ok( 'and is granted when a plugin activates', get_role( 'late_role' )->has_cap( Capabilities::VIEW_JOURNEYS ) );
check( 'after which nothing is missing', Capabilities::missing_grants(), array() );
$GLOBALS['__filters'] = array();
unset( $GLOBALS['__roles']['late_role'] );

Capabilities::uninstall();
ok( 'uninstall removes the capabilities', ! get_role( 'administrator' )->has_cap( Capabilities::MANAGE_SETTINGS ) );

// ------------------------------------------------------------------ Rewrites.

Rewrites::register();
check( 'two public routes are registered', count( $GLOBALS['__rewrites'] ), 2 );
$GLOBALS['__rewrites'] = array();
$GLOBALS['__filters']  = array();
$GLOBALS['__actions']  = array();
Rewrites::hooks();
ok( 'rules are deferred to init, never added at plugins_loaded', array() === $GLOBALS['__rewrites'] );
ok( 'and the query vars filter is attached immediately', isset( $GLOBALS['__filters']['query_vars'] ) );
ok( 'the deferred registration is queued on init', isset( $GLOBALS['__actions']['init'] ) );
do_action( 'init' );
check( 'which then adds both rules', count( $GLOBALS['__rewrites'] ), 2 );
$GLOBALS['__filters'] = array();
$GLOBALS['__actions'] = array();
$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
check( 'a 32-byte token is 43 characters', strlen( $token ), 43 );
ok( 'the rewrite pattern accepts a real token', 1 === preg_match( '/^' . Rewrites::TOKEN_REGEX . '$/', $token ) );
ok( 'the pattern rejects a short token', 1 !== preg_match( '/^' . Rewrites::TOKEN_REGEX . '$/', substr( $token, 0, 42 ) ) );
ok( 'the pattern rejects a long token', 1 !== preg_match( '/^' . Rewrites::TOKEN_REGEX . '$/', $token . 'x' ) );
ok( 'the pattern rejects base64 padding', 1 !== preg_match( '/^' . Rewrites::TOKEN_REGEX . '$/', substr( $token, 0, 42 ) . '=' ) );

check( 'pretty recovery URL', Rewrites::url( $token ), 'https://example.test/recovery/' . $token );
check( 'pretty opt-out URL', Rewrites::url( $token, 'opt-out' ), 'https://example.test/recovery/' . $token . '/opt-out' );
ok( 'the URL carries no personal data', false === strpos( Rewrites::url( $token ), '@' ) );

$GLOBALS['__options']['permalink_structure'] = '';
ok( 'plain permalinks fall back to a query argument', false !== strpos( Rewrites::url( $token ), 'rf=' ) );
$GLOBALS['__options']['permalink_structure'] = '/%postname%/';

$GLOBALS['__query_vars'] = array(
	Rewrites::QUERY_TOKEN  => $token,
	Rewrites::QUERY_ACTION => 'restore',
);
check(
	'the current request is read back',
	Rewrites::current_request(),
	array(
		'token'  => $token,
		'action' => 'restore',
	)
);
$GLOBALS['__query_vars'][ Rewrites::QUERY_TOKEN ] = 'too-short';
check( 'a malformed token never reaches the database', Rewrites::current_request(), null );
$GLOBALS['__query_vars'] = array();

// -------------------------------------------------------------- Feature gate.

delete_option( Options::ME_SNAPSHOT );
delete_option( Options::API_KEY );
ok( 'no credential means no direct sending', ! Feature_Gate::is_enabled( Feature_Gate::DIRECT_SEND ) );
check( 'and it says why', Feature_Gate::unavailable_reason(), 'No WA.cr API key is connected yet.' );

/*
 * The bug this pair exists to prevent: an empty snapshot was read as "no key",
 * so a saved-but-unchecked key was described as absent. The settings screen
 * printed "A key is saved." and "No WA.cr API key is connected yet." one line
 * apart, and no button existed anywhere in wp-admin that could write the
 * snapshot and resolve it.
 */
( new Credentials() )->set_api_key( 'wacr_test_smoke_unchecked' );
ok(
	'a saved but unchecked key is not described as absent',
	false === strpos( Feature_Gate::unavailable_reason(), 'No WA.cr API key is connected' )
);
ok(
	'it is described as unchecked, and names the control that checks it',
	false !== strpos( Feature_Gate::unavailable_reason(), 'has not been checked yet' )
		&& false !== strpos( Feature_Gate::unavailable_reason(), 'Test connection' )
);

// A snapshot describes ONE credential: storing a different key must retire it,
// or the screen reports the old workspace's tenant and scopes against the new
// key and the gate grants sends on scopes the stored key may not hold.
update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => true,
		'scopes' => array( 'messages:send' ),
	)
);
( new Credentials() )->set_api_key( 'wacr_test_smoke_unchecked' );
ok( 're-saving the SAME key keeps what was learned about it', Feature_Gate::has_developer_api() );
( new Credentials() )->set_api_key( 'wacr_test_smoke_different' );
ok( 'but storing a DIFFERENT key retires the old snapshot', ! Feature_Gate::has_developer_api() );
check(
	'and the screen says the new key is unchecked, not that the plan is short',
	Feature_Gate::unavailable_reason(),
	'The saved WA.cr API key has not been checked yet. Use "Test connection" to check it against WA.cr.'
);
delete_option( Options::API_KEY );
delete_option( Options::ME_SNAPSHOT );

update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => false,
		'reason' => 'plan_upgrade_required',
	)
);
ok( 'a workspace below Scale cannot author workflows here', ! Feature_Gate::is_enabled( Feature_Gate::WORKFLOW_EDITOR ) );
ok( 'the upgrade message names the hand-off alternative', false !== strpos( Feature_Gate::unavailable_reason(), 'Auto Flow' ) );

update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => true,
		'scopes' => array( 'messages:send', 'templates:read' ),
	)
);
ok( 'a connected workspace can author workflows', Feature_Gate::is_enabled( Feature_Gate::WORKFLOW_EDITOR ) );
ok( 'and can send directly', Feature_Gate::is_enabled( Feature_Gate::DIRECT_SEND ) );
ok( 'but not poll without the read scope', ! Feature_Gate::is_enabled( Feature_Gate::ENGAGEMENT_POLL ) );
check( 'a working connection has nothing to explain', Feature_Gate::unavailable_reason(), '' );

add_filter( Hooks::FILTER_FEATURE_ENABLED, static fn( $enabled, $feature ) => Feature_Gate::ENGAGEMENT_POLL === $feature ? true : $enabled );
ok( 'the feature filter can override the gate', Feature_Gate::is_enabled( Feature_Gate::ENGAGEMENT_POLL ) );
$GLOBALS['__filters'] = array();

// -------------------------------------------------------------- Requirements.

ok( 'the environment meets the requirements', Requirements::met() );
check( 'and lists no failures', Requirements::failures(), array() );

// ------------------------------------------------------------------- Plugin.

$plugin = Plugin::instance();
ok( 'the container is a singleton', Plugin::instance() === $plugin );
ok( 'the clock comes from the container', $plugin->clock() instanceof Clock );
ok( 'the same clock is returned each time', $plugin->clock() === $plugin->clock() );
ok( 'an unknown service is null, not fatal', null === $plugin->get( 'nope' ) );
$plugin->boot();
ok( 'booting is idempotent', $plugin->is_booted() );
$plugin->boot();

// --------------------------------------------------------------------- Hooks.

$reflection = new ReflectionClass( Hooks::class );
$names      = $reflection->getConstants();
ok( 'every public hook is prefixed', count( array_filter( $names, static fn( $n ) => 0 === strpos( $n, 'recoveryflow_' ) ) ) === count( $names ) );
ok( 'hook names are unique', count( array_unique( $names ) ) === count( $names ) );

// ------------------------------------------------------------ Journey state.

check( 'every state is either active or terminal', count( Journey_State::all() ), count( array_unique( Journey_State::all() ) ) );
ok( 'a recovered journey is finished', Journey_State::is_terminal( Journey_State::RECOVERED ) );
ok( 'a scheduled journey is not', ! Journey_State::is_terminal( Journey_State::SCHEDULED ) );
ok( 'an opted-out journey is finished', Journey_State::is_terminal( Journey_State::OPTED_OUT ) );

ok( 'a scheduled journey may be messaged', Journey_State::may_send( Journey_State::SCHEDULED ) );
ok( 'a journey awaiting payment may NOT be messaged', ! Journey_State::may_send( Journey_State::PENDING_PAYMENT ) );
ok( 'a journey whose customer replied may NOT be messaged', ! Journey_State::may_send( Journey_State::ENGAGED ) );
ok( 'a recovered journey may NOT be messaged', ! Journey_State::may_send( Journey_State::RECOVERED ) );
ok( 'an opted-out journey may NOT be messaged', ! Journey_State::may_send( Journey_State::OPTED_OUT ) );

ok( 'scheduling follows eligibility', Journey_State::can_transition( Journey_State::ELIGIBLE, Journey_State::SCHEDULED ) );
ok( 'a sent message can be followed by another', Journey_State::can_transition( Journey_State::MESSAGE_SENT, Journey_State::SCHEDULED ) );
ok( 'a reply can arrive after a send', Journey_State::can_transition( Journey_State::MESSAGE_SENT, Journey_State::ENGAGED ) );
ok( 'an order stops the sequence', Journey_State::can_transition( Journey_State::MESSAGE_SENT, Journey_State::PENDING_PAYMENT ) );
ok( 'a declined payment hands the journey back', Journey_State::can_transition( Journey_State::PENDING_PAYMENT, Journey_State::SCHEDULED ) );
ok( 'payment completes the recovery', Journey_State::can_transition( Journey_State::PENDING_PAYMENT, Journey_State::RECOVERED ) );

ok( 'a recovered journey cannot be re-scheduled', ! Journey_State::can_transition( Journey_State::RECOVERED, Journey_State::SCHEDULED ) );
ok( 'a recovered journey cannot be messaged again', ! Journey_State::can_transition( Journey_State::RECOVERED, Journey_State::MESSAGE_SENT ) );
ok( 'an opted-out journey cannot be revived', ! Journey_State::can_transition( Journey_State::OPTED_OUT, Journey_State::ELIGIBLE ) );
ok( 'an expired journey cannot be revived', ! Journey_State::can_transition( Journey_State::EXPIRED, Journey_State::SCHEDULED ) );
ok( 'a cancelled journey cannot be revived', ! Journey_State::can_transition( Journey_State::CANCELLED, Journey_State::SCHEDULED ) );
ok( 'an unknown state transitions nowhere', ! Journey_State::can_transition( 'wat', Journey_State::SCHEDULED ) );

foreach ( Journey_State::terminal() as $terminal_state ) {
	check( "nothing follows {$terminal_state}", Journey_State::transitions()[ $terminal_state ], array() );
}
foreach ( Journey_State::active() as $active_state ) {
	foreach ( Journey_State::terminal() as $terminal_state ) {
		ok( "{$active_state} can always stop at {$terminal_state}", Journey_State::can_transition( $active_state, $terminal_state ) );
	}
}
ok( 'every state has a label', '' !== Journey_State::label( Journey_State::PENDING_PAYMENT ) );

// -------------------------------------------------------- Phone normaliser.

$phone = static fn( string $raw, string $country = '' ): string => Phone_Normalizer::to_e164( $raw, $country );

// The case this class exists for: a national number with no country hint must
// never be promoted to an international one.
check( 'an Indian mobile without a country is refused', $phone( '9876543210' ), '' );
check( 'an Indian mobile with the country becomes E.164', $phone( '9876543210', 'IN' ), '+919876543210' );
check( 'a US number with the country becomes E.164', $phone( '(555) 010-9999', 'US' ), '+15550109999' );
check( 'a UK number loses its trunk zero', $phone( '07700 900123', 'GB' ), '+447700900123' );
check( 'an Italian number keeps its leading zero', $phone( '0612345678', 'IT' ), '+390612345678' );

check( 'a plus-prefixed number is taken as written', $phone( '+919876543210' ), '+919876543210' );
check( 'spaces and dashes are ignored', $phone( '+91 98765-43210' ), '+919876543210' );
check( 'a 00 prefix means international', $phone( '00919876543210' ), '+919876543210' );
check( 'a bracketed trunk prefix is dropped', $phone( '+44 (0) 7700 900123' ), '+447700900123' );
check( 'a national number typed with its own code works', $phone( '919876543210', 'IN' ), '+919876543210' );
check( 'an Indian number written with a trunk zero works', $phone( '09876543210', 'IN' ), '+919876543210' );

check( 'an empty number is refused', $phone( '' ), '' );
check( 'a number with letters is refused', $phone( 'call me' ), '' );
check( 'a too-short number is refused', $phone( '+12345' ), '' );
check( 'a number with a zero country code is refused', $phone( '+0123456789' ), '' );
check( 'an Indian number of the wrong length is refused', $phone( '98765', 'IN' ), '' );
check( 'a US number of the wrong length is refused', $phone( '5550109', 'US' ), '' );
check( 'an absurdly long string is refused', $phone( str_repeat( '9', 30 ) ), '' );

$outcome = Phone_Normalizer::normalize( '9876543210' );
check( 'a refusal says why', $outcome['reason'], 'no_country' );
check( 'and carries no number', $outcome['e164'], '' );
check( 'a success reports valid', Phone_Normalizer::normalize( '9876543210', 'IN' )['status'], Phone_Normalizer::VALID );

check( 'digits() drops the plus for conversation lookups', Phone_Normalizer::digits( '+919876543210' ), '919876543210' );

add_filter(
	Hooks::FILTER_CALLING_CODES,
	static function ( $codes ) {
		$codes['XX'] = '999';
		return $codes;
	}
);
check( 'a store can add a country', $phone( '12345678', 'XX' ), '+99912345678' );
$GLOBALS['__filters'] = array();

// ------------------------------------------------------------------- Tokens.

$minted = Token_Service::mint();
check( 'a token is 43 characters', strlen( $minted ), Token_Service::LENGTH );
ok( 'a token is URL-safe', Token_Service::is_well_formed( $minted ) );
ok( 'tokens do not repeat', Token_Service::mint() !== $minted );
check( 'the stored form is a sha256', strlen( Token_Service::hash( $minted ) ), 64 );
ok( 'the stored form does not contain the token', false === strpos( Token_Service::hash( $minted ), $minted ) );
ok( 'a token matches its own hash', Token_Service::matches( $minted, Token_Service::hash( $minted ) ) );
ok( 'another token does not', ! Token_Service::matches( Token_Service::mint(), Token_Service::hash( $minted ) ) );
ok( 'a truncated token is rejected before any lookup', ! Token_Service::is_well_formed( substr( $minted, 0, 42 ) ) );
ok( 'a padded token is rejected', ! Token_Service::is_well_formed( $minted . 'a' ) );
ok( 'a token with a slash is rejected', ! Token_Service::is_well_formed( str_repeat( 'a', 42 ) . '/' ) );
ok( 'an empty token is rejected', ! Token_Service::is_well_formed( '' ) );
ok( 'the rewrite pattern and the minter agree', 1 === preg_match( '/^' . Rewrites::TOKEN_REGEX . '$/', $minted ) );

// ----------------------------------------------------------------- Redactor.

check( 'a phone key is dropped', Redactor::scrub( array( 'phone' => '+919876543210' ) )['phone'], Redactor::MASK );
check( 'a billing phone key is dropped', Redactor::scrub( array( 'billing_phone' => '+919876543210' ) )['billing_phone'], Redactor::MASK );
check( 'camelCase keys are caught too', Redactor::scrub( array( 'billingPhone' => '+919876543210' ) )['billingPhone'], Redactor::MASK );
check( 'the recipient of a message is dropped', Redactor::scrub( array( 'to' => '+919876543210' ) )['to'], Redactor::MASK );
check( 'message text is never logged', Redactor::scrub( array( 'text' => 'Hi Asha, your cart is waiting' ) )['text'], Redactor::MASK );
check( 'template components are dropped', Redactor::scrub( array( 'components' => array( 'anything' ) ) )['components'], Redactor::MASK );
check( 'a status code is kept', Redactor::scrub( array( 'status' => 429 ) )['status'], 429 );
check( 'an error code is kept', Redactor::scrub( array( 'error_code' => 'rate_limited' ) )['error_code'], 'rate_limited' );

$nested = Redactor::scrub(
	array(
		'request' => array(
			'customer' => array(
				'email' => 'asha@example.com',
				'id'    => 7,
			),
		),
	)
);
check( 'nested personal data is dropped', $nested['request']['customer']['email'], Redactor::MASK );
check( 'nested identifiers survive', $nested['request']['customer']['id'], 7 );

$loop = array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => array( 'g' => 'deep' ) ) ) ) ) ) );
ok( 'deep structures are cut off rather than followed forever', is_array( Redactor::scrub( $loop ) ) );

ok( 'a phone number inside a sentence is masked', false === strpos( Redactor::scrub_string( 'Send failed for +919876543210 today' ), '9876543' ) );
ok( 'and the last digits survive for correlation', false !== strpos( Redactor::scrub_string( 'Send failed for +919876543210' ), '***10' ) );
ok( 'an email inside a sentence is masked', false === strpos( Redactor::scrub_string( 'contact asha@example.com now' ), 'asha@example.com' ) );
ok( 'an API key inside a sentence is masked', false === strpos( Redactor::scrub_string( 'key wacr_live_AbCd1234EfGh refused' ), 'wacr_live_AbCd1234EfGh' ) );
ok( 'a white-label API key is masked too', false === strpos( Redactor::scrub_string( 'key waht_test_ZzYy9988 refused' ), 'waht_test_ZzYy9988' ) );
ok( 'an authorization header is masked', false === strpos( Redactor::scrub_string( 'Bearer wacr_live_secretvalue' ), 'secretvalue' ) );
$link = 'https://example.test/recovery/' . $minted;
ok( 'a recovery link is masked', false === strpos( Redactor::scrub_string( "clicked {$link}" ), $minted ) );
check( 'ordinary text is left alone', Redactor::scrub_string( 'Journey 42 expired after 7 days' ), 'Journey 42 expired after 7 days' );

// --------------------------------------------------------------- Masking UI.

check( 'a phone is masked to its ends', Mask::phone( '+919876543210' ), '+91••••••3210' );
check( 'a shorter number masks too', Mask::phone( '+15550109999' ), '+15•••••9999' );
ok( 'a mask never shows the middle digits', false === strpos( Mask::phone( '+919876543210' ), '8765' ) );
check( 'an unusable number masks to nothing', Mask::phone( '123' ), '' );
check( 'an email keeps its first letter and domain shape', Mask::email( 'asha@example.com' ), 'a•••@e•••.com' );
check( 'a malformed email masks to nothing', Mask::email( 'not-an-email' ), '' );
check( 'a name becomes first name and initial', Mask::name( 'Asha', 'Menon' ), 'Asha M.' );
check( 'a first name alone is shown whole', Mask::name( 'Asha', '' ), 'Asha' );
check( 'a last name alone is shown whole', Mask::name( '', 'Menon' ), 'Menon' );
check( 'an unnamed customer is labelled', Mask::name( '', '' ), 'Unnamed customer' );


// ------------------------------------------------------------ Error mapping.

$err = Error::from_response(
	429,
	array(
		'error' => array(
			'code'    => 'rate_limited',
			'message' => 'Too many requests',
		),
	),
	30
);
check( 'a 429 is a rate limit', $err->category, Error::RATE_LIMIT );
ok( 'and is worth retrying', $err->retryable );
check( 'and honours Retry-After', $err->retry_after, 30 );
check( 'and nothing was sent', $err->send_state, Error::NOT_SENT );

$err = Error::from_response( 401, array( 'error' => array( 'code' => 'invalid_key' ) ) );
check( 'a 401 is an authentication failure', $err->category, Error::AUTHENTICATION );
ok( 'which is not worth retrying', ! $err->retryable );
ok( 'and stops sending altogether', $err->is_fatal_for_sending() );
ok( 'and the message names where to fix it', false !== strpos( $err->message, 'settings' ) );

$err = Error::from_response( 403, array( 'error' => array( 'code' => 'plan_upgrade_required' ) ) );
check( 'a plan refusal is an authorisation failure', $err->category, Error::AUTHORIZATION );
ok( 'and the message offers the hand-off instead', false !== strpos( $err->message, 'Auto Flow' ) );

$err = Error::from_response( 403, array( 'error' => array( 'code' => 'insufficient_scope' ) ) );
ok( 'a missing scope names the console', false !== strpos( $err->message, 'WA.cr console' ) );

$err = Error::from_response(
	422,
	array(
		'error' => array(
			'code'    => 'invalid_body',
			'message' => 'Required',
		),
	)
);
check( 'a 422 is a validation failure', $err->category, Error::VALIDATION );
ok( 'which is not retried, because the answer would be the same', ! $err->retryable );
ok( 'and the useless upstream wording is replaced', 'Required' !== $err->message );

$err = Error::from_response( 502, array( 'error' => array( 'code' => 'send_failed' ) ) );
check( 'a 502 is an API error', $err->category, Error::API );
ok( 'and is retried', $err->retryable );

// The distinction the whole retry design rests on.
$err = Error::from_transport( 'http_request_failed', 'cURL error 6: Could not resolve host: api.wa.cr', false );
check( 'a connection that never opened definitely sent nothing', $err->send_state, Error::NOT_SENT );
ok( 'so it is safe to retry', $err->retryable );

$err = Error::from_transport( 'http_request_failed', 'cURL error 28: Operation timed out', true );
check( 'a timeout leaves delivery unknown', $err->send_state, Error::UNKNOWN );
ok( 'so it is NOT retried automatically', ! $err->retryable );
ok( 'and the message says why', false !== strpos( $err->message, 'twice' ) );

$transport = new Transport_Probe();
ok( 'a refused connection sent nothing', ! $transport->probe( 'http_request_failed', 'cURL error 7: Failed to connect to api.wa.cr port 443' ) );
ok( 'an unresolved host sent nothing', ! $transport->probe( 'http_request_failed', 'cURL error 6: Could not resolve host: api.wa.cr' ) );
ok( 'a bad certificate sent nothing', ! $transport->probe( 'http_request_failed', 'SSL certificate problem: unable to get local issuer' ) );
ok( 'a timeout may have sent', $transport->probe( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds' ) );
ok( 'an unrecognised failure is treated as may-have-sent', $transport->probe( 'http_request_failed', 'something nobody has seen before' ) );

// ------------------------------------------------------------- Send request.

$send = new Send_Request(
	'+919876543210',
	'cart_reminder_1',
	'en',
	array(
		array(
			'type'       => 'body',
			'parameters' => array(
				array(
					'type' => 'text',
					'text' => 'Asha',
				),
			),
		),
	)
);
$payload = $send->to_payload( 'channel-123' );
check( 'the channel is always WhatsApp', $payload['channel'], 'whatsapp' );
check( 'the recipient is E.164', $payload['to'], '+919876543210' );
check( 'the template is named', $payload['templateName'], 'cart_reminder_1' );
check( 'the language is carried', $payload['languageCode'], 'en' );
check( 'the sender is carried', $payload['from'], 'channel-123' );
ok( 'the workspace is never sent', ! isset( $payload['tenant'] ) && ! isset( $payload['workspace'] ) && ! isset( $payload['tenantId'] ) );
ok( 'no email is shared by default', ! isset( $payload['email'] ) );
ok( 'no name is shared by default', ! isset( $payload['firstName'] ) );

$bare = ( new Send_Request( '+919876543210', 'cart_reminder_1', '' ) )->to_payload();
check( 'a missing language falls back to en', $bare['languageCode'], 'en' );
ok( 'an empty components list is omitted, not sent empty', ! isset( $bare['components'] ) );
ok( 'and no sender is invented', ! isset( $bare['from'] ) );

$named = ( new Send_Request( '+919876543210', 't', 'en' ) )->with_contact( 'Asha', 'Menon', 'asha@example.com' )->to_payload();
check( 'a shared first name is sent', $named['firstName'], 'Asha' );
check( 'a shared last name is sent', $named['lastName'], 'Menon' );
check( 'a shared email is sent', $named['email'], 'asha@example.com' );

ok( 'every field that can be sent is disclosed', count( Send_Request::disclosed_fields() ) >= count( $named ) );
foreach ( array_keys( $named ) as $field ) {
	ok( "the disclosure covers {$field}", array_key_exists( $field, Send_Request::disclosed_fields() ) );
}

// ------------------------------------------------------------- Rate budget.

$budget_clock = new Clock();
$budget_clock->freeze( 1757000000 );
delete_option( 'recoveryflow_rate_budget' );
$budget = new Rate_Budget( $budget_clock, 3 );
check( 'a fresh minute has its full budget', $budget->remaining(), 3 );
ok( 'the first request is allowed', $budget->take() );
ok( 'the second is allowed', $budget->take() );
ok( 'the third is allowed', $budget->take() );
ok( 'the fourth is refused', ! $budget->take() );
check( 'and nothing is left', $budget->remaining(), 0 );

$budget_clock->freeze( 1757000060 );
check( 'the next minute starts fresh', $budget->remaining(), 3 );
ok( 'and allows a request again', $budget->take() );

$budget->pause( 120 );
check( 'a pause stops everything', $budget->remaining(), 0 );
ok( 'even with budget left', ! $budget->take() );
ok( 'and reports when it ends', $budget->paused_until() > $budget_clock->timestamp() );
$budget_clock->freeze( 1757000060 + 121 );
check( 'the pause expires on its own', $budget->paused_until(), 0 );
$budget->pause( 300 );
$budget->resume();
check( 'a successful connection test clears a pause', $budget->paused_until(), 0 );

// ------------------------------------------------------------- Credentials.

$credentials = new Credentials();
delete_option( Options::API_KEY );
ok( 'no key means not configured', ! $credentials->is_configured() );
check( 'and nothing to mask', $credentials->masked_key(), '' );

$credentials->set_api_key( 'wacr_live_AbCdEfGh12345678' );
ok( 'a saved key is readable back', $credentials->is_configured() );
check( 'the key round-trips', $credentials->api_key(), 'wacr_live_AbCdEfGh12345678' );
ok( 'the stored option is encrypted', 0 === strpos( (string) get_option( Options::API_KEY ), 'rfenc1:' ) );
check( 'the mask keeps the prefix and last four', $credentials->masked_key(), 'wacr_live_••••5678' );
ok( 'the mask hides the middle', false === strpos( $credentials->masked_key(), 'AbCdEfGh' ) );

ok( 'a live key is recognised', Credentials::looks_like_key( 'wacr_live_AbCdEfGh12345678' ) );
ok( 'a test key is recognised', Credentials::looks_like_key( 'wacr_test_AbCdEfGh12345678' ) );
ok( 'a white-label key is recognised', Credentials::looks_like_key( 'waht_live_AbCdEfGh12345678' ) );
ok( 'a pasted sentence is not', ! Credentials::looks_like_key( 'my api key is secret' ) );
ok( 'a truncated key is not', ! Credentials::looks_like_key( 'wacr_live_short' ) );

check( 'production is the default host', $credentials->base_url(), Credentials::HOST_PRODUCTION );
Options::set( 'wacr_environment', 'staging' );
check( 'staging can be chosen', $credentials->base_url(), Credentials::HOST_STAGING );
Options::set( 'wacr_base_url', 'https://evil.example.com' );
check( 'an unlisted host is refused', $credentials->base_url(), Credentials::HOST_STAGING );
Options::set( 'wacr_base_url', 'http://api.wa.cr' );
check( 'a plain-HTTP override is refused', $credentials->base_url(), Credentials::HOST_STAGING );
Options::set( 'wacr_base_url', 'https://api.wa.cr' );
check( 'an allowed host is accepted', $credentials->base_url(), 'https://api.wa.cr' );
Options::set( 'wacr_base_url', '' );
Options::set( 'wacr_environment', 'production' );

Options::set( 'wacr_hook_url', 'https://api.wa.cr/automations/hooks/abc123' );
ok( 'a hook on an allowed host is usable', $credentials->has_hook() );
Options::set( 'wacr_hook_url', 'https://evil.example.com/automations/hooks/abc123' );
ok( 'a hook pointing elsewhere is refused', ! $credentials->has_hook() );
Options::set( 'wacr_hook_url', '' );
ok( 'no hook means no hand-off', ! $credentials->has_hook() );

$credentials->set_hook_secret( 'shared-secret-value' );
check( 'the hook secret round-trips', $credentials->hook_secret(), 'shared-secret-value' );
ok( 'and is encrypted at rest', 0 === strpos( (string) get_option( Options::HOOK_SECRET ), 'rfenc1:' ) );

$credentials->set_api_key( '' );
ok( 'clearing the key removes it', ! $credentials->is_configured() );
ok( 'and forgets what the connection reported', array() === Feature_Gate::snapshot() );

// ---------------------------------------------------------------------- i18n.

/*
 * Not a test of behaviour but of every shipped string, and the one check that
 * has to keep passing as the plugin grows: a string that reaches a person and
 * is not translatable is a bug a merchant in another language pays for, and
 * nothing at runtime will ever surface it.
 */
$i18n_problems = kdc_wacr_recoveryflow_i18n_problems( dirname( __DIR__ ) );

ok( 'every shipped string is translatable and correctly domained', array() === $i18n_problems );

foreach ( $i18n_problems as $i18n_problem ) {
	echo "    {$i18n_problem}\n";
}

ok( 'the scan actually looked at the source', count( kdc_wacr_recoveryflow_shipped_php( dirname( __DIR__ ) ) ) > 10 );

// ------------------------------------------------------- Activation parity.

/*
 * WordPress does not run a plugin's activation hook when the plugin is updated,
 * so anything added to activation and not mirrored into the upgrader reaches
 * fresh installs only. That already happened once: the default workflows were
 * seeded on activation alone, and a site that had RecoveryFlow switched on
 * before that release came out of the update with its tables and its schedule
 * but nothing to run, detecting abandoned carts and silently recovering none of
 * them. Nothing appeared in a log, because nothing went wrong.
 *
 * The property worth holding is that activation and update leave a site in the
 * same state. Asserting the state itself needs a database, which this harness
 * deliberately does not have -- so what is asserted here is the invariant that
 * produces it: every subject activation provisions, the upgrader provisions
 * too. It is a weaker check than the live one, and it is the one that runs on
 * every push, on both PHP versions, even when composer itself is broken.
 */

/**
 * Extract one method's body from a source file, by brace matching.
 *
 * @param string $file   Absolute path.
 * @param string $method Method name.
 * @return string The body, or '' when it could not be found.
 */
function kdc_wacr_recoveryflow_method_body( string $file, string $method ): string {
	$source = (string) file_get_contents( $file );
	$at     = strpos( $source, 'function ' . $method . '(' );

	if ( false === $at ) {
		return '';
	}

	$open = strpos( $source, '{', $at );

	if ( false === $open ) {
		return '';
	}

	$depth = 0;

	for ( $i = $open, $len = strlen( $source ); $i < $len; $i++ ) {
		if ( '{' === $source[ $i ] ) {
			++$depth;
		} elseif ( '}' === $source[ $i ] ) {
			--$depth;

			if ( 0 === $depth ) {
				return substr( $source, $open, $i - $open );
			}
		}
	}

	return '';
}

$activate = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Core/Activator.php', 'activate' );
$upgrade  = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Core/Upgrader.php', 'maybe_upgrade' );

// A failure to read either body must fail loudly rather than quietly pass the
// comparison that follows, which would be trivially true for two empty strings.
ok( 'the activation routine can be read', strlen( $activate ) > 50 );
ok( 'the upgrade routine can be read', strlen( $upgrade ) > 50 );

/*
 * Subjects rather than calls, because the two paths legitimately reach the same
 * subject by different names: activation installs a schema that has never
 * existed, an update migrates one that has. Naming the subject keeps the check
 * true when either side is rewritten, and still fails when a subject is added
 * to one path and forgotten on the other.
 */
$provisioning_subjects = array(
	'Schema'        => 'the database schema',
	'Capabilities'  => 'the capabilities',
	'Hash_Key'      => 'the per-site hash key',
	'Options'       => 'the settings',
	'seed_defaults' => 'the default workflows',
	'scheduler()'   => 'the background schedule',
);

foreach ( $provisioning_subjects as $subject => $label ) {
	$in_activation = false !== strpos( $activate, $subject );
	$in_upgrade    = false !== strpos( $upgrade, $subject );

	ok( "activation provisions {$label}", $in_activation );
	ok( "updating provisions {$label} too, because activation hooks do not run on update", $in_upgrade );
}

// Rewrite rules are the one thing that is legitimately activation-only: they
// are registered on every request by Rewrites::hooks() and only need flushing
// when the rule set first appears.
ok( 'flushing rewrite rules stays on the activation path only', false !== strpos( $activate, 'flush_rewrite_rules' ) && false === strpos( $upgrade, 'flush_rewrite_rules' ) );

// The version guard is what stops the upgrade path running on every page load.
ok( 'the upgrader is guarded by the stored version', false !== strpos( $upgrade, Upgrader::OPTION ) || false !== strpos( $upgrade, 'self::OPTION' ) );
ok( 'the upgrader stamps the version forward', false !== strpos( $upgrade, 'update_option' ) );

// -------------------------------------------------------------------- Result.





// ---------------------------------------------------------------------------
// Identity: strong versus weak kinds, and the lock key's hard size limit.
// ---------------------------------------------------------------------------

ok( 'a phone number identifies one person', Identity::is_strong( Identity::E164 ) );
ok( 'an external id identifies one person', Identity::is_strong( Identity::EXTERNAL_ID ) );
ok( 'a WordPress account identifies one person', Identity::is_strong( Identity::WP_USER ) );

// The one that matters: households and role addresses share an inbox, so an
// email must never let two strangers' carts be merged.
ok( 'an email address does NOT identify one person', ! Identity::is_strong( Identity::EMAIL ) );

ok( 'every strong kind is a known kind', array() === array_diff( Identity::strong_kinds(), Identity::kinds() ) );
ok( 'an unknown kind is rejected', ! Identity::is_kind( 'igsid' ) );

// Email is lowercased because the hash is the lookup key and a hash of
// "Asha@Example.com" would never match one of the same address in lower case.
ok( 'an email is lowercased before hashing', 'asha@example.com' === Identity::normalize_value( Identity::EMAIL, '  Asha@Example.COM ' ) );

// A phone number is not: case does not apply, and touching it here would
// duplicate work Phone_Normalizer already owns.
ok( 'a phone number keeps its case and plus', '+919876543210' === Identity::normalize_value( Identity::E164, ' +919876543210 ' ) );
ok( 'an empty value normalises to nothing', '' === Identity::normalize_value( Identity::EMAIL, '   ' ) );

// recoveryflow_locks.lock_key is varchar(64) AND the PRIMARY KEY, and a sha256
// hash already fills all 64. Any prefix on the whole hash would overflow and be
// truncated silently on a non-strict connection, so two identities sharing a
// prefix would quietly share a lock. This assertion is the guard on that.
$identity_lock_key = Identity_Repository::lock_key( str_repeat( 'a', 64 ) );

ok( 'the identity lock key fits lock_key varchar(64)', strlen( $identity_lock_key ) <= 64 );
ok( 'the identity lock key is bucketed, not per-identity', 'id:aaaaaaaa' === $identity_lock_key );

// Bounded buckets are the point: a lock row per person would grow the lock
// table with the customer base and nothing would ever delete it.
ok(
	'two identities in one bucket share a lock',
	Identity_Repository::lock_key( str_repeat( 'b', 8 ) . str_repeat( '1', 56 ) )
		=== Identity_Repository::lock_key( str_repeat( 'b', 8 ) . str_repeat( '2', 56 ) )
);
ok(
	'identities in different buckets do not',
	Identity_Repository::lock_key( str_repeat( 'c', 64 ) ) !== Identity_Repository::lock_key( str_repeat( 'd', 64 ) )
);

ok( 'an unusable value has no hash', '' === Identity_Repository::hash_for( Identity::EMAIL, '  ' ) );
ok( 'the same address hashes the same either way', Identity_Repository::hash_for( Identity::EMAIL, 'Asha@Example.com' ) === Identity_Repository::hash_for( Identity::EMAIL, 'asha@example.com' ) );

// The hash must agree with how the rest of the plugin already hashes an
// identifier, or lookups from the order observer would silently never match.
ok( 'the email hash matches Hash_Key::email()', Identity_Repository::hash_for( Identity::EMAIL, 'Asha@Example.com' ) === Hash_Key::email( 'Asha@Example.com' ) );
ok( 'the phone hash matches Hash_Key::hash()', Identity_Repository::hash_for( Identity::E164, '+919876543210' ) === Hash_Key::hash( '+919876543210' ) );

// ------------------------------------------- Classic consent, readable back.

/*
 * The one-string bug class. WooCommerce reads the classic checkout back only
 * during its update_order_review AJAX call, and checkout.js asks for that call
 * from a fixed selector list: address fields, and anything inside a
 * .update_totals_on_change container. The phone and email fields are declared
 * plain form-row-wide and so are in neither list -- which is why typing a phone
 * number fires nothing, and why the consent box has to opt itself in.
 *
 * Drop that class in a refactor and nothing breaks loudly: the box still
 * renders, still submits at Place Order, and still reads correctly in every
 * test that posts a form. What silently stops working is the only case that
 * matters -- the shopper who ticks the box and then ABANDONS, whose answer is
 * never read because the AJAX call that would have read it was never made.
 * With explicit_consent as the default mode that failure looks exactly like
 * poor opt-in rates. Hence a test on the string itself.
 */

$consent_field = new Consent_Field( new Session(), new Logger( new Clock() ) );
$consent_fields = $consent_field->add_classic_field( array( 'billing' => array() ) );

ok( 'the classic consent box is added to the billing group', isset( $consent_fields['billing'][ Consent_Field::FIELD_ID ] ) );

$consent_box = $consent_fields['billing'][ Consent_Field::FIELD_ID ] ?? array();

check( 'the consent box is a checkbox', $consent_box['type'] ?? '', 'checkbox' );

// The class that makes the answer survive abandonment. checkout.js binds
// change on '.update_totals_on_change input[type="checkbox"]', and
// woocommerce_form_field puts this array on the wrapper <p>, so the tick
// triggers WooCommerce's own nonce-protected update_checkout -- which posts
// the whole serialised form, carrying the consent AND the typed phone/email.
ok(
	'ticking consent asks WooCommerce to re-read the checkout',
	in_array( 'update_totals_on_change', (array) ( $consent_box['class'] ?? array() ), true )
);

// It must be on the wrapper, which is what $args['class'] becomes; input_class
// would land on the <input> and match none of core's selectors.
ok( 'the trigger class is on the wrapper, not the input', ! isset( $consent_box['input_class'] ) );

// The listener has to exist for the trigger to be worth anything. These two
// are a pair: the class asks for the AJAX call, this hook reads the result.
$consent_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Integration/WooCommerce/Consent_Field.php' );
ok( 'something is listening for the re-read it triggers', false !== strpos( $consent_source, 'woocommerce_checkout_update_order_review' ) );

// The class covers explicit_consent only. In identified_contact mode is_asked()
// renders no box, so there is nothing for the class to sit on -- which is the
// whole reason the script exists as well.
$consent_script = dirname( __DIR__ ) . '/' . Checkout_Script::PATH;

ok( 'the capture script is actually shipped', is_readable( $consent_script ) );

$consent_script_body = (string) file_get_contents( $consent_script );

// It must ask for WooCommerce's own round trip and nothing else. A script that
// posted anywhere itself would need an endpoint, a nonce and a rate limit, and
// would be a different change requiring a different review.
ok( 'the script triggers WooCommerce own update_checkout', false !== strpos( $consent_script_body, "trigger( 'update_checkout' )" ) );
ok( 'the script opens no request of its own', false === strpos( $consent_script_body, 'ajax' ) && false === strpos( $consent_script_body, 'fetch(' ) && false === strpos( $consent_script_body, 'XMLHttpRequest' ) );

// It watches the two fields core does not. Watching address fields as well
// would double every request core already makes.
ok( 'the script watches the phone field', false !== strpos( $consent_script_body, '#billing_phone' ) );
ok( 'the script watches the email field', false !== strpos( $consent_script_body, '#billing_email' ) );

// Per the .pot ruling, this script carries no user-facing text, so there is no
// second extraction toolchain and no jed JSON per locale to keep in step.
ok( 'the script needs no translation', false === strpos( $consent_script_body, 'wp.i18n' ) && false === strpos( $consent_script_body, '__(' ) );

// Where the script is allowed to load. These conditions are the reason the
// class exists: the checkout is the last page on a shop where it is acceptable
// to add a request that does nothing, so every one of them is asserted in both
// directions rather than only in the direction that passes.
$consent_enqueue = new Checkout_Script();

$GLOBALS['__options']['recoveryflow_settings'] = array( 'enabled' => true );

$GLOBALS['__is_checkout']       = false;
$GLOBALS['__is_order_received'] = false;
$GLOBALS['__is_pay_page']       = false;
ok( 'no script on a page that is not the checkout', ! $consent_enqueue->is_wanted() );

$GLOBALS['__is_checkout'] = true;
ok( 'the script loads on the classic checkout', $consent_enqueue->is_wanted() );

// The two checkout pages with nothing left to recover.
$GLOBALS['__is_order_received'] = true;
ok( 'no script on the thank-you page', ! $consent_enqueue->is_wanted() );
$GLOBALS['__is_order_received'] = false;

$GLOBALS['__is_pay_page'] = true;
ok( 'no script on the order-pay page', ! $consent_enqueue->is_wanted() );
$GLOBALS['__is_pay_page'] = false;

// The one that matters most: a shop that has not switched recovery on is not
// asked to carry the script at all. Asserted by flipping the setting back and
// forth on an otherwise identical request, so it cannot pass because some
// unrelated condition happened to be false.
$GLOBALS['__options']['recoveryflow_settings'] = array( 'enabled' => false );
ok( 'no script when recovery is switched off', ! $consent_enqueue->is_wanted() );

$GLOBALS['__options']['recoveryflow_settings'] = array( 'enabled' => true );
ok( 'the same request wants the script once recovery is on', $consent_enqueue->is_wanted() );

// It must load in BOTH eligibility modes. identified_contact renders no
// tick-box, so the script is the only thing capturing a typed number there --
// gating it on the consent field would reintroduce the bug for that mode.
$GLOBALS['__options']['recoveryflow_settings'] = array(
	'enabled'          => true,
	'eligibility_mode' => 'identified_contact',
);
ok( 'the script still loads where no consent box is rendered', $consent_enqueue->is_wanted() );

$GLOBALS['__options']['recoveryflow_settings'] = array(
	'enabled'          => true,
	'eligibility_mode' => 'explicit_consent',
);

// And what it actually queues.
$GLOBALS['__scripts'] = array();
$consent_enqueue->enqueue();
ok( 'enqueuing registers the script', isset( $GLOBALS['__scripts'][ Checkout_Script::HANDLE ] ) );

$consent_queued = $GLOBALS['__scripts'][ Checkout_Script::HANDLE ] ?? array();
check( 'the script depends on jQuery, which checkout.js needs anyway', $consent_queued['deps'] ?? array(), array( 'jquery' ) );
ok( 'the script is versioned, so an update is not served from cache', ! empty( $consent_queued['ver'] ) );
ok( 'the script loads in the footer, after the checkout form', true === ( $consent_queued['args'] ?? false ) );

// Nothing queued when nothing is wanted.
$GLOBALS['__scripts']     = array();
$GLOBALS['__is_checkout'] = false;
$consent_enqueue->enqueue();
check( 'nothing is queued off the checkout', $GLOBALS['__scripts'], array() );

$GLOBALS['__is_checkout'] = false;
$GLOBALS['__options']['recoveryflow_settings'] = array();

// A missing billing group must not fatal the checkout.
check( 'a checkout with no billing group is left alone', $consent_field->add_classic_field( array() ), array() );
check( 'a non-array field set is left alone', $consent_field->add_classic_field( 'nonsense' ), 'nonsense' );


// ---------------------------------------------------------------------------
// Email compliance: the gate that has to be cleared before email may be sent.
//
// A recovery email is commercial mail, so it needs the sender's real postal
// address and an unsubscribe that keeps working for thirty days. Neither is
// something the plugin can supply, so the plugin refuses instead. These
// assertions are about the refusal.
// ---------------------------------------------------------------------------

$recoveryflow_compliant = array(
	'merchant_postal_address' => "Example Shop Ltd\n12 Example Road\nBengaluru 560001",
	'merchant_postal_country' => 'IN',
	'recovery_link_ttl_days'  => 30,
);

$recoveryflow_shipped = Options::defaults();

check( 'no postal address ships with the plugin -- only the merchant knows it', $recoveryflow_shipped['merchant_postal_address'], '' );
check( 'and no country either', $recoveryflow_shipped['merchant_postal_country'], '' );

$recoveryflow_fresh = Email_Compliance::blockers( $recoveryflow_shipped );

ok( 'a fresh site is blocked for want of an address', in_array( Email_Compliance::NO_POSTAL_ADDRESS, $recoveryflow_fresh, true ) );
ok( 'and for want of a country to format it by', in_array( Email_Compliance::NO_POSTAL_COUNTRY, $recoveryflow_fresh, true ) );
ok( 'and because a 7-day link would die three weeks before the law lets it', in_array( Email_Compliance::UNSUBSCRIBE_WINDOW_TOO_SHORT, $recoveryflow_fresh, true ) );

check( 'a merchant who has settled all three has nothing blocking', Email_Compliance::blockers( $recoveryflow_compliant ), array() );
ok( 'which is what being compliant means', Email_Compliance::is_satisfied( $recoveryflow_compliant ) );

/*
 * Each blocker on its own, from an otherwise-clean site. A test that only ever
 * checked the all-empty case would still pass if two of the three checks were
 * deleted, because the third would carry it.
 */
$recoveryflow_case = $recoveryflow_compliant;
$recoveryflow_case['merchant_postal_address'] = "  \n \n ";
check( 'whitespace is not an address', Email_Compliance::blockers( $recoveryflow_case ), array( Email_Compliance::NO_POSTAL_ADDRESS ) );

$recoveryflow_case = $recoveryflow_compliant;
$recoveryflow_case['merchant_postal_country'] = '';
check( 'a missing country blocks on its own', Email_Compliance::blockers( $recoveryflow_case ), array( Email_Compliance::NO_POSTAL_COUNTRY ) );

$recoveryflow_case = $recoveryflow_compliant;
$recoveryflow_case['recovery_link_ttl_days'] = 29;
check( 'and one day short of thirty blocks on its own', Email_Compliance::blockers( $recoveryflow_case ), array( Email_Compliance::UNSUBSCRIBE_WINDOW_TOO_SHORT ) );

// Thirty is also the ceiling Rule_Set clamps this setting to, so the
// requirement is exactly satisfiable, and asking for more is not an error.
$recoveryflow_case = $recoveryflow_compliant;
$recoveryflow_case['recovery_link_ttl_days'] = 45;
check( 'asking for longer than the ceiling still clears it', Email_Compliance::blockers( $recoveryflow_case ), array() );

// A lifetime nobody can read is not evidence of a lawful one.
$recoveryflow_case = $recoveryflow_compliant;
$recoveryflow_case['recovery_link_ttl_days'] = 0;
check( 'a zero lifetime blocks', Email_Compliance::blockers( $recoveryflow_case ), array( Email_Compliance::UNSUBSCRIBE_WINDOW_TOO_SHORT ) );

$recoveryflow_case = $recoveryflow_compliant;
$recoveryflow_case['recovery_link_ttl_days'] = 'thirty';
check( 'and so does one that is not a number at all', Email_Compliance::blockers( $recoveryflow_case ), array( Email_Compliance::UNSUBSCRIBE_WINDOW_TOO_SHORT ) );

// The setting missing entirely falls back to the shipped 7, which blocks.
$recoveryflow_case = $recoveryflow_compliant;
unset( $recoveryflow_case['recovery_link_ttl_days'] );
check( 'and the setting missing altogether blocks', Email_Compliance::blockers( $recoveryflow_case ), array( Email_Compliance::UNSUBSCRIBE_WINDOW_TOO_SHORT ) );

// The gate itself, in both directions.
$recoveryflow_rules = new Rule_Set( array_merge( $recoveryflow_compliant, array( 'channel_email_enabled' => true ) ) );
ok( 'a compliant site that switched email on may use it', $recoveryflow_rules->channel_enabled( Channel::EMAIL ) );
ok( 'an unknown channel is still nothing', ! $recoveryflow_rules->channel_enabled( 'sms' ) );

/*
 * The one that guards the ruling: compliance makes email PERMISSIBLE, never
 * enabled. Settling the settings must not switch the channel on behind the
 * merchant's back -- that is their decision and it has not been made.
 */
ok( 'settling the settings does NOT switch email on: the default is still off', ! ( new Rule_Set( $recoveryflow_compliant ) )->channel_enabled( Channel::EMAIL ) );

// And the other direction: the switch alone is not enough either.
$recoveryflow_rules = new Rule_Set( array( 'channel_email_enabled' => true ) );
ok( 'switching email on without the settings sends nothing', ! $recoveryflow_rules->channel_enabled( Channel::EMAIL ) );
check(
	'and the refusal names exactly what is missing, in the order to fix it',
	$recoveryflow_rules->email_compliance_blockers(),
	array( Email_Compliance::NO_POSTAL_ADDRESS, Email_Compliance::NO_POSTAL_COUNTRY, Email_Compliance::UNSUBSCRIBE_WINDOW_TOO_SHORT )
);

ok( 'WhatsApp is untouched by any of it', ( new Rule_Set( array() ) )->channel_enabled( Channel::WHATSAPP ) );

// Storing the address. Nothing is reordered or reformatted: an address is laid
// out by its own country, not by the language of whoever is reading it.
check( 'CRLF is normalised', Email_Compliance::sanitize_address( "A\r\nB" ), "A\nB" );
check( 'blank lines and padding go, line breaks stay', Email_Compliance::sanitize_address( "  A  \n\n\n  B \n " ), "A\nB" );
check( 'a control character cannot be smuggled into the footer', Email_Compliance::sanitize_address( "A\x00\x1BB" ), 'AB' );
check( 'nothing typed, nothing stored', Email_Compliance::sanitize_address( "\n \n" ), '' );
check( 'the number of lines is capped', count( Email_Compliance::address_lines( array( 'merchant_postal_address' => implode( "\n", array_fill( 0, 30, 'x' ) ) ) ) ), Email_Compliance::MAX_ADDRESS_LINES );
check( 'the length is capped', strlen( Email_Compliance::sanitize_address( str_repeat( 'x', 900 ) ) ), Email_Compliance::MAX_ADDRESS_LENGTH );

check( 'a country code is upper-cased', Email_Compliance::sanitize_country( 'gb' ), 'GB' );
check( 'punctuation and padding are stripped', Email_Compliance::sanitize_country( ' i-n ' ), 'IN' );
check( 'a country name is not a country code', Email_Compliance::sanitize_country( 'United Kingdom' ), '' );
check( 'and neither is a single letter', Email_Compliance::sanitize_country( 'G' ), '' );

/*
 * The footer. The address goes out byte for byte as the merchant stored it: it
 * is their value, not one of the plugin's strings, so it is never translated
 * and never appears in the .pot. Only the sentence offering the unsubscribe is
 * ours to translate, and the URL inside it is not.
 */
$recoveryflow_footer = Email_Compliance::footer( 'https://shop.example/recovery/' . str_repeat( 'a', 43 ) . '/opt-out', $recoveryflow_compliant );

ok( "the footer carries the merchant's address exactly as stored", false !== strpos( $recoveryflow_footer, "Example Shop Ltd\n12 Example Road\nBengaluru 560001" ) );
ok( 'and the unsubscribe link', false !== strpos( $recoveryflow_footer, 'https://shop.example/recovery/' ) );
check( 'no address, no footer -- which is itself the signal not to send', Email_Compliance::footer( 'https://shop.example/x', array() ), '' );
check( 'no unsubscribe link, no footer either', Email_Compliance::footer( '', $recoveryflow_compliant ), '' );

// Every blocker has something specific to say, rather than falling through to
// the generic sentence.
$recoveryflow_generic = Email_Compliance::reason_label( 'not-a-reason-code' );

foreach ( array( Email_Compliance::NO_POSTAL_ADDRESS, Email_Compliance::NO_POSTAL_COUNTRY, Email_Compliance::UNSUBSCRIBE_WINDOW_TOO_SHORT ) as $recoveryflow_code ) {
	ok( "the merchant is told what to do about {$recoveryflow_code}", Email_Compliance::reason_label( $recoveryflow_code ) !== $recoveryflow_generic );
}

/*
 * The unsubscribe that all of the above is gating. Suppression is stored per
 * identity, so an opt-out that only silenced the phone would leave the address
 * untouched -- and for a shopper who only ever gave an address, it would record
 * nothing at all while the page told them the reminders had stopped.
 */
$recoveryflow_suppress = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Recovery/Recovery_Controller.php', 'suppress' );

ok( 'the opt-out routine can be read', strlen( $recoveryflow_suppress ) > 50 );
ok( 'an opt-out silences the phone number', false !== strpos( $recoveryflow_suppress, 'Identity::E164' ) );
ok( 'an opt-out silences the email address too, or an email-only shopper unsubscribes into a void', false !== strpos( $recoveryflow_suppress, 'Identity::EMAIL' ) );

// And the pages must not promise less than the button delivers. Confirming
// stops every channel, so naming one would be wrong on WhatsApp and simply
// false on email. Comments are exempt; only what a shopper reads is checked.
foreach ( array( 'opt-out-confirm.php', 'opt-out-done.php' ) as $recoveryflow_page ) {
	$recoveryflow_named = false;

	foreach ( token_get_all( (string) file_get_contents( dirname( __DIR__ ) . '/templates/' . $recoveryflow_page ) ) as $recoveryflow_token ) {
		if ( is_array( $recoveryflow_token ) && T_CONSTANT_ENCAPSED_STRING === $recoveryflow_token[0] && false !== stripos( $recoveryflow_token[1], 'whatsapp' ) ) {
			$recoveryflow_named = true;
		}
	}

	ok( "{$recoveryflow_page} names no single channel, because the opt-out stops them all", ! $recoveryflow_named );
}


// --------------------------------------------------- Admin: settings schema.

/*
 * The settings screen is described as data, so the things that would otherwise
 * only be caught by clicking every tab are answerable here instead.
 *
 * The first of these is the one that matters most and is the easiest to break
 * by accident: a setting that is stored but has no control anywhere. Nothing
 * misbehaves when that happens -- the setting simply keeps whatever value it
 * was born with, for ever, and the merchant has no way to know it exists.
 */
check( 'every stored setting has a control on the screen', Settings_Schema::unreachable_settings(), array() );

$recoveryflow_field_homes = array();

foreach ( Settings_Schema::tabs() as $recoveryflow_tab_id => $recoveryflow_tab ) {
	ok( "the {$recoveryflow_tab_id} tab has a label", '' !== (string) $recoveryflow_tab['label'] );

	foreach ( $recoveryflow_tab['sections'] as $recoveryflow_section_id => $recoveryflow_section ) {
		ok( "{$recoveryflow_tab_id}/{$recoveryflow_section_id} has a title", '' !== (string) $recoveryflow_section['title'] );

		foreach ( $recoveryflow_section['cards'] as $recoveryflow_card_id => $recoveryflow_card ) {
			ok( "{$recoveryflow_tab_id}/{$recoveryflow_section_id}/{$recoveryflow_card_id} has a title", '' !== (string) $recoveryflow_card['title'] );

			foreach ( array( 'fields', 'advanced' ) as $recoveryflow_group ) {
				foreach ( $recoveryflow_card[ $recoveryflow_group ] ?? array() as $recoveryflow_key => $recoveryflow_spec ) {
					$recoveryflow_field_homes[ $recoveryflow_key ][] = $recoveryflow_tab_id;

					ok( "{$recoveryflow_key} has a label", '' !== (string) ( $recoveryflow_spec['label'] ?? '' ) );
					ok( "{$recoveryflow_key} declares a type", '' !== (string) ( $recoveryflow_spec['type'] ?? '' ) );
				}
			}
		}
	}
}

// A setting on two tabs has an ambiguous deeplink and two labels that will
// eventually disagree with each other.
foreach ( $recoveryflow_field_homes as $recoveryflow_key => $recoveryflow_homes ) {
	check( "{$recoveryflow_key} appears on exactly one tab", count( $recoveryflow_homes ), 1 );
}

// The address of a control is a public thing: it is quoted in notices, in the
// status checks and in support replies, so its shape is asserted rather than
// assumed.
$recoveryflow_deeplink = Settings_Schema::deeplink( Email_Compliance::SETTING_ADDRESS );

ok( 'a setting deeplink names its tab', false !== strpos( $recoveryflow_deeplink, 'tab=channels' ) );
ok( 'and its section', false !== strpos( $recoveryflow_deeplink, 'section=email' ) );
ok( 'and the control itself', false !== strpos( $recoveryflow_deeplink, 'field=merchant_postal_address' ) );
ok( 'and carries a fragment, so the deeplink still lands without JavaScript', false !== strpos( $recoveryflow_deeplink, '#recoveryflow-field-merchant-postal-address' ) );
check( 'a setting that is not on the screen has no deeplink', Settings_Schema::deeplink( 'not_a_setting' ), '' );

/*
 * The two channel switches were added to the stored defaults so the screen has
 * something to render. They must repeat what Rule_Set already fell back to and
 * not quietly change it -- email off is a legal position, not a default anybody
 * may adjust in passing.
 */
$recoveryflow_defaults = Options::defaults();

check( 'WhatsApp is on by default', $recoveryflow_defaults['channel_whatsapp_enabled'], true );
check( 'email is OFF by default, and storing the key did not change that', $recoveryflow_defaults['channel_email_enabled'], false );

// -------------------------------------------------- Admin: saving settings.

/**
 * Save one tab of the settings form, the way options.php would.
 *
 * @param string              $tab    Tab id.
 * @param array<string,mixed> $posted What the form sent.
 * @return array<string,mixed> The settings option afterwards.
 */
function recoveryflow_save_tab( string $tab, array $posted ): array {
	$_POST[ Settings_Sanitizer::TAB_FIELD ] = $tab;

	$saved = Settings_Page::sanitize_settings( $posted );

	unset( $_POST[ Settings_Sanitizer::TAB_FIELD ] );

	// Stored, because that is what options.php does next, and because a save
	// that did not persist would let each of these checks start from a clean
	// slate -- which is the one condition a real settings screen is never in.
	update_option( Options::SETTINGS, $saved );

	return is_array( $saved ) ? $saved : array();
}

update_option(
	Options::SETTINGS,
	array_merge(
		Options::defaults(),
		array(
			'inactivity_minutes' => 45,
			'max_touches'        => 2,
			'logging_level'      => 'debug',
		)
	)
);

/*
 * The trap this whole design exists to avoid. Every tab posts only its own
 * fields, so a sanitiser that returned what it was handed would replace the
 * entire option with one tab's worth of settings -- silently resetting the
 * other five to their defaults, on a site that carries on working and gives
 * nobody a reason to look.
 */
$recoveryflow_after = recoveryflow_save_tab(
	'channels',
	array(
		'channel_email_enabled'   => '1',
		'merchant_postal_address' => "  Example Shop Ltd \n\n 12 Example Road \n Bengaluru 560001 ",
		'merchant_postal_country' => 'in',
	)
);

check( 'saving the Channels tab leaves the Recovery tab alone', $recoveryflow_after['inactivity_minutes'], 45 );
check( 'and the Advanced tab', $recoveryflow_after['logging_level'], 'debug' );
check( 'and the rest of the Recovery tab', $recoveryflow_after['max_touches'], 2 );
check( 'while saving what was actually posted', $recoveryflow_after['merchant_postal_country'], 'IN' );
check( 'an address is stored as the merchant typed it, minus the padding', $recoveryflow_after['merchant_postal_address'], "Example Shop Ltd\n12 Example Road\nBengaluru 560001" );

// A browser posts nothing at all for an unticked box, so absent has to mean
// false -- but only on the tab that was submitted, or every save would switch
// off every checkbox on every other tab.
$recoveryflow_after = recoveryflow_save_tab( 'channels', array( 'merchant_postal_country' => 'GB' ) );

check( 'an unticked box on the posted tab is stored as off', $recoveryflow_after['channel_email_enabled'], false );
check( 'a box on another tab is untouched by that save', $recoveryflow_after['quiet_hours_enabled'], true );
check( 'and so is one on a third tab', $recoveryflow_after['exclude_admins'], true );

/*
 * Registering a sanitise callback makes WordPress run it on EVERY write to the
 * option, not only on a form save. This plugin writes its own settings from
 * several places, and a sanitiser that assumed a posted form would have thrown
 * every one of those writes away.
 */
$recoveryflow_direct = Settings_Page::sanitize_settings(
	array(
		'enabled'            => true,
		'inactivity_minutes' => 15,
	)
);

check(
	'a programmatic write is passed through, not rewritten from a form that was never posted',
	$recoveryflow_direct,
	array(
		'enabled'            => true,
		'inactivity_minutes' => 15,
	)
);

// A value out of range is clamped rather than refused: somebody who typed 500
// into a field that stops at 30 meant "as long as possible".
$recoveryflow_after = recoveryflow_save_tab( 'recovery', array( 'recovery_link_ttl_days' => '500' ) );
check( 'a number past the maximum is clamped to it', $recoveryflow_after['recovery_link_ttl_days'], 30 );

$recoveryflow_after = recoveryflow_save_tab( 'recovery', array( 'recovery_link_ttl_days' => '0' ) );
check( 'and one below the minimum is clamped up', $recoveryflow_after['recovery_link_ttl_days'], 1 );

$recoveryflow_after = recoveryflow_save_tab( 'recovery', array( 'recovery_link_ttl_days' => 'soon' ) );
check( 'a value that is not a number leaves the stored one alone', $recoveryflow_after['recovery_link_ttl_days'], 1 );

$recoveryflow_after = recoveryflow_save_tab( 'privacy', array( 'eligibility_mode' => 'whatever_i_like' ) );
check( 'a choice that is not on the list is refused', $recoveryflow_after['eligibility_mode'], 'explicit_consent' );

$recoveryflow_after = recoveryflow_save_tab( 'recovery', array( 'quiet_hours_start' => '25:00' ) );
check( 'a time that does not exist is refused', $recoveryflow_after['quiet_hours_start'], '21:00' );

// The credential is not part of the settings option and must never leak into
// it: that option is autoloaded on every request of every page.
$recoveryflow_after = recoveryflow_save_tab( 'wacr', array( Settings_Schema::FIELD_API_KEY => 'wacr_live_secret' ) );

ok( 'the API key is never stored in the settings option', ! array_key_exists( Settings_Schema::FIELD_API_KEY, $recoveryflow_after ) );
ok( 'and no stored value contains it', false === strpos( wp_json_encode( $recoveryflow_after ), 'wacr_live_secret' ) );

// ------------------------------------------------- Admin: the email surface.

/**
 * Render the settings screen and hand back the HTML.
 *
 * @param string $tab   Tab to render.
 * @param string $field Setting the deeplink named, if any.
 * @return string
 */
function recoveryflow_render_settings( string $tab, string $field = '' ): string {
	$_GET['tab']   = $tab;
	$_GET['field'] = $field;

	ob_start();
	Settings_Page::render();
	$html = (string) ob_get_clean();

	unset( $_GET['tab'], $_GET['field'] );

	return $html;
}

/*
 * The gap this slice exists to close. The email channel has refused to send
 * since it was built, for three reasons it can name -- and until now nothing
 * drew them, so a merchant could read that email was unavailable and have no
 * way at all to find out what to do about it.
 */
update_option( Options::SETTINGS, Options::defaults() );

$recoveryflow_html = recoveryflow_render_settings( 'channels' );

ok( 'the Channels tab renders', false !== strpos( $recoveryflow_html, 'recoveryflow-settings' ) );

foreach ( Email_Compliance::blockers( Options::defaults() ) as $recoveryflow_code ) {
	ok(
		"the screen tells the merchant what to do about {$recoveryflow_code}",
		false !== strpos( $recoveryflow_html, esc_html( Email_Compliance::reason_label( $recoveryflow_code ) ) )
	);
}

ok( 'and links to the control that clears the postal address', false !== strpos( $recoveryflow_html, esc_url( Settings_Schema::deeplink( Email_Compliance::SETTING_ADDRESS ) ) ) );
ok( 'and to the one that clears the country', false !== strpos( $recoveryflow_html, esc_url( Settings_Schema::deeplink( Email_Compliance::SETTING_COUNTRY ) ) ) );

// The unsubscribe-window blocker is cleared on a different tab entirely, which
// is exactly why it needs a link rather than a sentence.
ok( 'and to the recovery-link lifetime, which lives on another tab', false !== strpos( $recoveryflow_html, esc_url( Settings_Schema::deeplink( 'recovery_link_ttl_days' ) ) ) );

// Settling the three makes email PERMISSIBLE. It does not switch it on, and
// the screen has to say so, or a merchant reads "settled" as "sending".
update_option(
	Options::SETTINGS,
	array_merge(
		Options::defaults(),
		array(
			'merchant_postal_address' => "Example Shop Ltd\n12 Example Road",
			'merchant_postal_country' => 'GB',
			'recovery_link_ttl_days'  => 30,
		)
	)
);

$recoveryflow_html = recoveryflow_render_settings( 'channels' );

ok( 'once settled the screen says so', false !== strpos( $recoveryflow_html, 'recoveryflow-compliance--met' ) );
ok( 'and says that settling it is not the same as switching it on', false !== strpos( $recoveryflow_html, esc_html__( 'Email reminders are permissible from this site. Whether they are actually sent is the switch below.', 'kdc-wacr-recoveryflow' ) ) );
ok( 'and the switch itself is still unticked', false === strpos( $recoveryflow_html, 'name="recoveryflow_settings[channel_email_enabled]" value="1" checked' ) );
ok( 'the switch is on the screen to be ticked', false !== strpos( $recoveryflow_html, 'name="recoveryflow_settings[channel_email_enabled]"' ) );

// ------------------------------------------------ Admin: the screen itself.

$recoveryflow_html = recoveryflow_render_settings( 'channels', Email_Compliance::SETTING_ADDRESS );

ok( 'a deeplinked control is marked, so it is findable without relying on colour', false !== strpos( $recoveryflow_html, 'recoveryflow-field--targeted' ) );
ok( 'every control names its own description', false !== strpos( $recoveryflow_html, 'aria-describedby="recoveryflow-field-merchant-postal-address-help"' ) );
ok( 'the tab in view is marked for a screen reader too, not only by colour', false !== strpos( $recoveryflow_html, 'aria-current="page"' ) );
ok( 'the form posts to core, so the nonce and the capability check are WordPress\'s', false !== strpos( $recoveryflow_html, 'options.php' ) );
ok( 'and says which tab it is, or the sanitiser cannot tell what to leave alone', false !== strpos( $recoveryflow_html, 'name="' . Settings_Sanitizer::TAB_FIELD . '" value="channels"' ) );

// ------------------------------------------------------ First-run setup.

/*
 * Connecting a workspace is the one thing the plugin cannot do for itself, so
 * activation asks for the setup screen once. The flag is an option rather than
 * a transient because an object cache may evict a transient, and the single
 * chance to greet somebody is a poor thing to leave to an eviction policy.
 */
delete_option( Setup::PENDING_OPTION );
ok( 'a plugin that has been running is not greeted', ! get_option( Setup::PENDING_OPTION ) );
Setup::mark_pending();
ok( 'a fresh activation asks for the setup screen', (bool) get_option( Setup::PENDING_OPTION ) );

$GLOBALS['recoveryflow_caps'] = array( Capabilities::MANAGE_SETTINGS );
ok( 'and an administrator activating one plugin is sent there', Setup::may_greet() );

$_GET['activate-multi'] = '1';
ok(
	'but activating several at once is left alone, or the other plugins\' notices are lost',
	! Setup::may_greet()
);
unset( $_GET['activate-multi'] );

$GLOBALS['__network_admin'] = true;
ok( 'and a network activation is not the person who will type a key', ! Setup::may_greet() );
$GLOBALS['__network_admin'] = false;

$GLOBALS['recoveryflow_caps'] = array( Capabilities::VIEW_JOURNEYS );
ok( 'somebody who could not act on it is not sent there', ! Setup::may_greet() );
$GLOBALS['recoveryflow_caps'] = null;

$recoveryflow_setup = Plugin::instance()->admin_setup();

ob_start();
$recoveryflow_setup->render();
$recoveryflow_html = (string) ob_get_clean();

ok( 'the setup screen offers the one step it has', false !== strpos( $recoveryflow_html, 'name="action" value="' . Setup::ACTION . '"' ) );
ok( 'the key box is a password box, so it is not read over a shoulder', false !== strpos( $recoveryflow_html, 'type="password" id="recoveryflow-setup-key"' ) );
ok( 'and its help is associated with it rather than merely near it', false !== strpos( $recoveryflow_html, 'aria-describedby="recoveryflow-setup-key-help"' ) );
ok( 'skipping is offered, because recovery already works without a key', false !== strpos( $recoveryflow_html, 'Skip for now' ) );
ok( 'and says so, so skipping does not read as giving up', false !== strpos( $recoveryflow_html, 'go on being recorded' ) );

/*
 * The product decision this screen exists to get right. GET /v1/me answers 403
 * plan_upgrade_required for a workspace below Scale, and answers it AFTER
 * authenticating the credential -- so that refusal proves the key is real.
 * Reporting it as a failed connection would send a merchant whose key is
 * perfectly good to go and find a better one, and would dead-end the entire
 * free path, which is the one that works on every plan.
 */
check(
	'a working key is a connection',
	Setup::describe( Result::success( array( 'tenant_name' => 'Acme Retail' ) ) )['state'],
	'connected'
);
ok(
	'and it names the workspace, so somebody can see they connected the right one',
	false !== strpos( Setup::describe( Result::success( array( 'tenant_name' => 'Acme Retail' ) ) )['message'], 'Acme Retail' )
);
check(
	'a key WA.cr refuses is not a connection',
	Setup::describe( Result::failure( Error::from_response( 401, array( 'error' => array( 'code' => 'invalid_key' ) ) ) ) )['state'],
	'refused'
);
check(
	'but a plan too small for the developer API is an ACCEPTED key, not a refused one',
	Setup::describe( Result::failure( Error::from_response( 403, array( 'error' => array( 'code' => 'plan_upgrade_required' ) ) ) ) )['state'],
	'handoff'
);
ok(
	'because /v1/me authenticates before it consults the plan, so the refusal proves the key is real',
	false !== strpos(
		Setup::describe( Result::failure( Error::from_response( 403, array( 'error' => array( 'code' => 'plan_upgrade_required' ) ) ) ) )['message'],
		'Your key works'
	)
);

set_transient(
	'recoveryflow_setup_result_' . get_current_user_id(),
	array(
		'state'   => 'handoff',
		'message' => 'Your key works.',
	),
	60
);

ob_start();
$recoveryflow_setup->render();
$recoveryflow_html = (string) ob_get_clean();

ok( 'a plan that is too small is reported as an accepted key', false !== strpos( $recoveryflow_html, 'Key accepted.' ) );
ok( 'never as a failed connection', false === strpos( $recoveryflow_html, 'Not connected.' ) );
ok( 'and it points at the hand-off, which works on every plan', false !== strpos( $recoveryflow_html, 'field=wacr_hook_url' ) );
ok( 'naming what to do rather than telling them to upgrade', false !== strpos( $recoveryflow_html, 'Auto Flow' ) );

ob_start();
$recoveryflow_setup->render();
$recoveryflow_html = (string) ob_get_clean();

ok( 'an outcome is shown once and not again on the next visit', false === strpos( $recoveryflow_html, 'Key accepted.' ) );

$recoveryflow_html = recoveryflow_render_settings( 'privacy' );

ok( 'a group of choices is a fieldset, so it is announced as one question', false !== strpos( $recoveryflow_html, '<fieldset' ) );
ok( 'and the question is available to a screen reader', false !== strpos( $recoveryflow_html, 'screen-reader-text' ) );
ok( 'an irreversible option asks first', false !== strpos( $recoveryflow_html, 'data-confirm=' ) );

$recoveryflow_html = recoveryflow_render_settings( 'wacr' );

ok( 'rarely-touched settings are in a native expandable', false !== strpos( $recoveryflow_html, '<details' ) );
ok( 'the credential box is never rendered holding the credential', false !== strpos( $recoveryflow_html, 'type="password" id="recoveryflow-field-wacr-api-key" name="recoveryflow_settings[wacr_api_key]" value=""' ) );

/*
 * Nothing else in wp-admin can write the connection snapshot, so without this
 * control a saved key stays unchecked for ever and the screen goes on saying
 * the connection is absent. The health check has told merchants to "use Test
 * connection" since 1c, while no such button existed anywhere.
 */
ok(
	'the screen carries the control the rest of the plugin tells people to press',
	false !== strpos( $recoveryflow_html, 'name="action" value="' . Connection_Test::ACTION . '"' )
);
ok(
	'it posts to admin-post.php, so it works with scripts off',
	false !== strpos( $recoveryflow_html, 'action="https://shop.example/wp-admin/admin-post.php"' )
);
ok(
	'and carries a nonce, because it spends an API call on somebody else\'s service',
	false !== strpos( $recoveryflow_html, 'name="_wpnonce"' )
);

// A conditional field is rendered and then hidden by script, never omitted:
// with scripts off the screen has to be complete rather than missing settings.
$recoveryflow_html = recoveryflow_render_settings( 'recovery' );

ok( 'a conditional setting is in the HTML whether or not it currently applies', false !== strpos( $recoveryflow_html, 'name="recoveryflow_settings[quiet_hours_start]"' ) );
ok( 'and carries its condition for the script to act on', false !== strpos( $recoveryflow_html, 'data-requires="quiet_hours_enabled"' ) );

/*
 * The shipped admin script carries no user-facing text of its own -- the same
 * ruling the checkout script ships under. One string in JavaScript would need a
 * JavaScript i18n build and a second translation pipeline for the rest of the
 * plugin's life, so everything it announces is passed in from PHP, already
 * translated. Checked by looking for the strings it is given rather than by
 * reading the file's prose.
 */
$recoveryflow_admin_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin.js' );

ok( 'the admin script exists and is shipped', strlen( $recoveryflow_admin_js ) > 500 );
ok( 'it announces through wp.a11y.speak rather than moving the page about', false !== strpos( $recoveryflow_admin_js, 'wp.a11y.speak' ) );
ok( 'it takes its wording from PHP rather than carrying its own', false !== strpos( $recoveryflow_admin_js, 'strings.focused' ) );
ok( 'and defines none of that wording itself', false === strpos( $recoveryflow_admin_js, 'focused:' ) );

// assets/ is deliberately absent from .distignore, and a new file under it that
// nobody checked is a silent way to ship a plugin whose script is missing.
$recoveryflow_distignore = (string) file_get_contents( dirname( __DIR__ ) . '/.distignore' );

foreach ( array( 'assets/js/admin.js', 'assets/css/admin.css' ) as $recoveryflow_asset ) {
	ok( "{$recoveryflow_asset} is on disk", file_exists( dirname( __DIR__ ) . '/' . $recoveryflow_asset ) );
}

ok( 'assets/ is still shipped', false === strpos( $recoveryflow_distignore, "\nassets" ) );



// ------------------------------------------------------- Privacy: the tools.

/*
 * WordPress's own privacy screens are the route a site owner actually uses, so
 * RecoveryFlow has to be on them. An exporter nobody registered exports
 * nothing, and does it silently.
 */
$recoveryflow_exporters = $plugin->privacy_exporter()->register( array() );
$recoveryflow_erasers   = $plugin->privacy_eraser()->register( array() );

ok( 'the exporter registers itself with WordPress', isset( $recoveryflow_exporters[ Exporter::GROUP ] ) );
ok( 'and is callable', is_callable( $recoveryflow_exporters[ Exporter::GROUP ]['callback'] ?? null ) );
ok( 'the eraser registers itself with WordPress', isset( $recoveryflow_erasers[ Eraser::GROUP ] ) );
ok( 'and is callable', is_callable( $recoveryflow_erasers[ Eraser::GROUP ]['callback'] ?? null ) );
ok( 'both are named in words a site owner would recognise', '' !== (string) $recoveryflow_exporters[ Exporter::GROUP ]['exporter_friendly_name'] );

// An address this shop has never seen must finish rather than page for ever.
$recoveryflow_export = $plugin->privacy_exporter()->export( 'nobody@example.test' );

check( 'an unknown address exports nothing', $recoveryflow_export['data'], array() );
check( 'and says it has finished, rather than paging for ever', $recoveryflow_export['done'], true );

$recoveryflow_erase = $plugin->privacy_eraser()->erase( 'nobody@example.test' );

check( 'an unknown address erases nothing', $recoveryflow_erase['items_removed'], false );
check( 'and reports nothing retained, because there was nothing', $recoveryflow_erase['items_retained'], false );
check( 'and finishes', $recoveryflow_erase['done'], true );

check( 'anonymising customer zero is refused rather than fatal', $plugin->anonymizer()->anonymize_customer( 0 ), false );

/*
 * The one thing an erasure must NOT do. Suppression is keyed by the hash of an
 * identity, so deleting the hash deletes the record that this person asked not
 * to be messaged -- and the next time they type the same number into a checkout
 * the shop treats them as somebody new and messages them again. Erasing an
 * opt-out is not a privacy improvement; it is the failure the opt-out exists to
 * prevent.
 */
$recoveryflow_anon = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Privacy/Anonymizer.php', 'anonymize_customer' );

ok( 'the erasure can be read', strlen( $recoveryflow_anon ) > 100 );
ok( 'an erasure detaches the consent ledger from the person', false !== strpos( $recoveryflow_anon, 'detach_customer' ) );
ok( 'and never deletes it, or the opt-out dies with the customer', false === strpos( $recoveryflow_anon, 'delete' ) );
ok( 'an erasure revokes the recovery links already sent', false !== strpos( $recoveryflow_anon, 'revoke_tokens' ) );
ok( 'and strips what the basket contained', false !== strpos( $recoveryflow_anon, 'strip_items' ) );

// A journey still in flight is stopped, not merely stripped. Eligibility would
// already refuse to send for an anonymised customer, so nothing would go out --
// but a scheduled journey left in the queue shows work still being done for
// somebody who asked to be forgotten.
ok( 'an erasure stops a journey that is still running', false !== strpos( $recoveryflow_anon, 'Journey_State::CANCELLED' ) );
ok( 'and only one that is still running', false !== strpos( $recoveryflow_anon, 'is_terminal' ) );

$recoveryflow_identity_anon = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Customer/Identity_Repository.php', 'anonymize' );

ok( 'the readable contact detail is blanked', false !== strpos( $recoveryflow_identity_anon, "'value_raw'" ) );
ok( 'and the hash it is recognised by is NOT', false === strpos( $recoveryflow_identity_anon, "'value_hash'" ) );

// The person is told what was kept and why. "We kept a hash" without the reason
// reads as a shop hedging.
$recoveryflow_notice = Anonymizer::retained_notice();

ok( 'the erasure explains what it kept', false !== stripos( $recoveryflow_notice, 'one-way' ) );
ok( 'and why keeping it is in their interest', false !== stripos( $recoveryflow_notice, 'asked not to be messaged' ) );

/*
 * Retention has to reach the person, not only the basket. Stripping what
 * somebody was buying while keeping their name and phone number would be the
 * wrong half of the job.
 */
$recoveryflow_retention = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Jobs/Stages/Retention.php', 'run' );

ok( 'the daily clear-out anonymises people whose journeys are long finished', false !== strpos( $recoveryflow_retention, 'anonymize_finished_customers' ) );

$recoveryflow_due = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Customer/Customer_Repository.php', 'due_for_anonymization' );

ok( 'and only reaches customers with no journey still running', false !== strpos( $recoveryflow_due, 'NOT IN' ) );
ok( 'walking by primary key, so a clear-out cannot skip rows', false !== strpos( $recoveryflow_due, 'c.id > %d' ) );
ok( 'and never re-anonymising somebody already done', false !== strpos( $recoveryflow_due, 'anonymized_at IS NULL' ) );



// ------------------------------------------------------- REST: the surface.

/*
 * The routes are read back from what the plugin actually registered, not from
 * the source. A test that grepped for "permission_callback" would pass just as
 * happily on a route whose callback returned true.
 */
$GLOBALS['recoveryflow_routes'] = array();

$plugin->rest_journeys()->register_routes();
$plugin->rest_status()->register_routes();
$plugin->rest_settings()->register_routes();

$recoveryflow_routes = $GLOBALS['recoveryflow_routes'];

ok( 'the plugin registers REST routes', count( $recoveryflow_routes ) >= 4 );

foreach ( $recoveryflow_routes as $recoveryflow_route ) {
	$recoveryflow_path = $recoveryflow_route['namespace'] . $recoveryflow_route['route'];

	// Decision 5: KDC plugins share one namespace and separate by path.
	check( "{$recoveryflow_path} is in the shared KDC namespace", $recoveryflow_route['namespace'], Routes::REST_NAMESPACE );
	ok( "{$recoveryflow_path} sits under this plugin's prefix", 0 === strpos( $recoveryflow_route['route'], '/' . Routes::PREFIX . '/' ) );

	foreach ( $recoveryflow_route['endpoints'] as $recoveryflow_endpoint ) {
		$recoveryflow_method = (string) ( $recoveryflow_endpoint['methods'] ?? '' );
		$recoveryflow_label  = "{$recoveryflow_method} {$recoveryflow_path}";

		// A route with no permission callback is public. WordPress warns about
		// it and then serves it anyway.
		ok( "{$recoveryflow_label} has a permission callback", is_callable( $recoveryflow_endpoint['permission_callback'] ?? null ) );
		ok( "{$recoveryflow_label} has a handler", is_callable( $recoveryflow_endpoint['callback'] ?? null ) );

		foreach ( $recoveryflow_endpoint['args'] ?? array() as $recoveryflow_arg => $recoveryflow_spec ) {
			ok(
				"{$recoveryflow_label} validates or sanitises {$recoveryflow_arg}",
				isset( $recoveryflow_spec['sanitize_callback'] ) || isset( $recoveryflow_spec['enum'] ) || isset( $recoveryflow_spec['type'] )
			);
		}
	}
}

/*
 * The matrix. Every route is asked the same three questions: may a logged-out
 * visitor call it, may a subscriber, may somebody holding the right capability.
 * The first two must be refused by every single route -- these carry customer
 * contact details and the ability to cancel somebody's recovery.
 */
$recoveryflow_denied_anon = 0;
$recoveryflow_denied_sub  = 0;
$recoveryflow_allowed     = 0;

foreach ( $recoveryflow_routes as $recoveryflow_route ) {
	foreach ( $recoveryflow_route['endpoints'] as $recoveryflow_endpoint ) {
		$recoveryflow_guard = $recoveryflow_endpoint['permission_callback'];
		$recoveryflow_label = (string) ( $recoveryflow_endpoint['methods'] ?? '' ) . ' ' . $recoveryflow_route['route'];

		// Logged out.
		$GLOBALS['recoveryflow_caps']       = array();
		$GLOBALS['recoveryflow_logged_out'] = true;
		$recoveryflow_verdict               = $recoveryflow_guard();

		ok( "{$recoveryflow_label} refuses a logged-out visitor", $recoveryflow_verdict instanceof WP_Error );
		check( "{$recoveryflow_label} tells them to log in rather than that they are forbidden", $recoveryflow_verdict instanceof WP_Error ? $recoveryflow_verdict->get_status() : 0, 401 );
		++$recoveryflow_denied_anon;

		// A customer with an account on the shop. Logged in is not staff.
		$GLOBALS['recoveryflow_caps']       = array( 'read' );
		$GLOBALS['recoveryflow_logged_out'] = false;
		$recoveryflow_verdict               = $recoveryflow_guard();

		ok( "{$recoveryflow_label} refuses a subscriber", $recoveryflow_verdict instanceof WP_Error );
		check( "{$recoveryflow_label} refuses them with 403, not 401", $recoveryflow_verdict instanceof WP_Error ? $recoveryflow_verdict->get_status() : 0, 403 );
		ok( "{$recoveryflow_label} names the permission that was missing", $recoveryflow_verdict instanceof WP_Error && false !== strpos( $recoveryflow_verdict->get_error_message(), 'recoveryflow_' ) );
		++$recoveryflow_denied_sub;

		// Somebody holding every RecoveryFlow capability.
		$GLOBALS['recoveryflow_caps'] = Capabilities::all();

		check( "{$recoveryflow_label} admits a user with the capability", $recoveryflow_guard(), true );
		++$recoveryflow_allowed;
	}
}

ok( 'every route was tried logged out', $recoveryflow_denied_anon >= 6 );
ok( 'every route was tried as a subscriber', $recoveryflow_denied_sub === $recoveryflow_denied_anon );
ok( 'and every route was tried with the capability', $recoveryflow_allowed === $recoveryflow_denied_anon );

$GLOBALS['recoveryflow_caps']       = null;
$GLOBALS['recoveryflow_logged_out'] = false;

/*
 * The credential must not leave the site by any route, in any form -- not
 * masked, not as a length. An endpoint that reports facts about a secret helps
 * somebody guess it.
 */
( new WAcr\RecoveryFlow\WAcr\Credentials() )->set_api_key( 'wacr_live_do_not_leak_me' );

// Read on a site that has settled nothing, which is where every site starts.
update_option( Options::SETTINGS, Options::defaults() );

$recoveryflow_settings_body = $plugin->rest_settings()->index()->get_data();

ok( 'the settings endpoint answers', is_array( $recoveryflow_settings_body['settings'] ) );
ok( 'and never returns the API key', false === strpos( wp_json_encode( $recoveryflow_settings_body ), 'do_not_leak_me' ) );
ok( 'nor a field for it at all', ! array_key_exists( Settings_Schema::FIELD_API_KEY, $recoveryflow_settings_body['settings'] ) );
ok( 'but does say what is blocking email', count( $recoveryflow_settings_body['email_blockers'] ) > 0 );
check( 'and that email is not permitted yet', $recoveryflow_settings_body['email_permitted'], false );

// A blocker travels as a machine code AND a sentence. The code is what is
// compared and logged and must never be translated; the sentence is what a
// person reads.
$recoveryflow_first_blocker = $recoveryflow_settings_body['email_blockers'][0];

ok( 'a blocker carries its untranslated code', in_array( $recoveryflow_first_blocker['code'], array( Email_Compliance::NO_POSTAL_ADDRESS, Email_Compliance::NO_POSTAL_COUNTRY, Email_Compliance::UNSUBSCRIBE_WINDOW_TOO_SHORT ), true ) );
ok( 'and a sentence a person can act on', strlen( (string) $recoveryflow_first_blocker['message'] ) > 20 );

// Settle all three and the same endpoint says email is permitted -- and still
// does not say it is switched on, because it is not.
update_option(
	Options::SETTINGS,
	array_merge(
		Options::defaults(),
		array(
			'merchant_postal_address' => "Example Shop Ltd\n12 Example Road",
			'merchant_postal_country' => 'GB',
			'recovery_link_ttl_days'  => 30,
		)
	)
);

$recoveryflow_settled_body = $plugin->rest_settings()->index()->get_data();

check( 'once settled, the endpoint reports email as permitted', $recoveryflow_settled_body['email_permitted'], true );
check( 'with nothing left blocking it', $recoveryflow_settled_body['email_blockers'], array() );
check( 'and the switch itself still off, because permitted is not enabled', $recoveryflow_settled_body['settings']['channel_email_enabled'], false );


/*
 * The masking rule, which guards every route that can return a phone number.
 * Both halves are required, and the reason the capability alone is not enough
 * is the shop counter: a journeys list left open all day should not be
 * readable by whoever walks past, even though the person working it is
 * entitled to read any single row.
 */
$recoveryflow_reveal = new Reveal_Probe( $plugin->receipts() );

$GLOBALS['recoveryflow_caps'] = Capabilities::all();
check( 'holding the permission does not unmask a listing nobody asked to unmask', $recoveryflow_reveal->probe( new WP_REST_Request( array() ) ), false );

$GLOBALS['recoveryflow_caps'] = array( Capabilities::VIEW_JOURNEYS );
check( 'asking without the permission reveals nothing', $recoveryflow_reveal->probe( new WP_REST_Request( array( Abstract_Controller::REVEAL_ARG => true ) ) ), false );

$GLOBALS['recoveryflow_caps'] = array( Capabilities::VIEW_JOURNEYS, Capabilities::REVEAL_PII );
check( 'asking with the permission reveals', $recoveryflow_reveal->probe( new WP_REST_Request( array( Abstract_Controller::REVEAL_ARG => true ) ) ), true );

$GLOBALS['recoveryflow_caps'] = null;

/*
 * And the shaping itself. Masking is what the journeys list shows by default,
 * so the rule is asserted on the method that does it rather than only on the
 * endpoints that call it -- the fake database returns no rows, and an
 * assertion that passed because there was nothing to mask would be worthless.
 */
$recoveryflow_summary = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/REST/Journeys_Controller.php', 'customer_summary' );

ok( 'the customer shape can be read', strlen( $recoveryflow_summary ) > 100 );
ok( 'a phone number is masked unless revealing was earned', false !== strpos( $recoveryflow_summary, 'Mask::phone' ) );
ok( 'and an email address too', false !== strpos( $recoveryflow_summary, 'Mask::email' ) );
ok( 'and a name', false !== strpos( $recoveryflow_summary, 'Mask::name' ) );
ok( 'masking is what happens when revealing was not asked for', false !== strpos( $recoveryflow_summary, '$reveal ?' ) );

// An erased customer has nothing to mask or reveal, and the shape says so
// rather than returning blanks that read as missing data.
ok( 'an erased customer is described as erased', false !== strpos( $recoveryflow_summary, 'is_anonymized' ) );

// A missing journey and an erased one must look identical from outside, or the
// endpoint confirms that a given reference used to be real.
$recoveryflow_notfound = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/REST/Journeys_Controller.php', 'not_found' );

ok( 'a missing journey gets one uniform answer', false !== strpos( $recoveryflow_notfound, '404' ) );
check( 'and only one, so it cannot distinguish never-existed from erased', substr_count( $recoveryflow_notfound, 'WP_Error' ), 1 );

// Screen preferences are per person, so the shape of a screen follows somebody
// between machines rather than living in one browser.
$plugin->rest_settings()->save_ui_state(
	new WP_REST_Request(
		array(
			'panel' => 'credential',
			'open'  => true,
		)
	)
);
check( 'an opened panel is remembered for that person', get_user_meta( 1, Settings_Controller::UI_META, true ), array( 'credential' => true ) );

$plugin->rest_settings()->save_ui_state(
	new WP_REST_Request(
		array(
			'panel' => 'credential',
			'open'  => false,
		)
	)
);
check( 'and forgotten when they close it', get_user_meta( 1, Settings_Controller::UI_META, true ), array() );



// ------------------------------------------------------ Admin: the screens.

/*
 * Every screen is registered, capability-gated, and renders. The last of those
 * matters more than it sounds: an admin screen that fatals shows a white page
 * with no clue what caused it, and nothing short of loading it finds out.
 */
$GLOBALS['recoveryflow_caps'] = null;

$recoveryflow_menu = $plugin->admin_menu();
$recoveryflow_menu->register();

$recoveryflow_hooks = $recoveryflow_menu->hook_suffixes();

ok( 'the menu registers a top-level screen and its submenus', count( $recoveryflow_hooks ) >= 6 );
ok( 'and knows which screens are its own', $recoveryflow_menu->owns( $recoveryflow_hooks[0] ) );
ok( 'and does not claim somebody else\'s', ! $recoveryflow_menu->owns( 'edit.php' ) );

// A screen that forgot its capability is one anybody can open. Each is checked
// against the capability it should need rather than against "not empty".
check( 'the overview needs the status capability', Screen::capability( Screen::OVERVIEW ), Capabilities::VIEW_STATUS );
check( 'the recoveries list needs the journeys capability', Screen::capability( Screen::JOURNEYS ), Capabilities::VIEW_JOURNEYS );
check( 'one recovery needs the journeys capability', Screen::capability( Screen::JOURNEY ), Capabilities::VIEW_JOURNEYS );
check( 'settings need the settings capability', Screen::capability( Screen::SETTINGS ), Capabilities::MANAGE_SETTINGS );
check( 'integrations need the settings capability', Screen::capability( Screen::INTEGRATIONS ), Capabilities::MANAGE_SETTINGS );
check( 'status needs the status capability', Screen::capability( Screen::STATUS ), Capabilities::VIEW_STATUS );
check( 'the workflow list needs the workflows capability', Screen::capability( Screen::WORKFLOWS ), Capabilities::MANAGE_WORKFLOWS );
check( 'one workflow needs the workflows capability', Screen::capability( Screen::WORKFLOW ), Capabilities::MANAGE_WORKFLOWS );

// An unknown slug must fail closed. A screen somebody forgot to list should be
// shut, not open to everyone.
check( 'a screen nobody listed is closed rather than open', Screen::capability( 'recoveryflow-invented' ), Capabilities::MANAGE_SETTINGS );
ok( 'and is not treated as one of ours', ! Screen::is_ours( 'recoveryflow-invented' ) );

/**
 * Render an admin screen and hand back the HTML.
 *
 * @param callable $render The screen's render method.
 * @return string
 */
function recoveryflow_render_screen( callable $render ): string {
	ob_start();
	$render();

	return (string) ob_get_clean();
}

$recoveryflow_screens = array(
	'overview'     => array( $plugin->admin_overview(), 'render' ),
	'recoveries'   => array( $plugin->admin_journeys(), 'render' ),
	'recovery'     => array( $plugin->admin_journey(), 'render' ),
	'integrations' => array( $plugin->admin_integrations(), 'render' ),
	'status'       => array( $plugin->admin_status(), 'render' ),
	'workflows'    => array( $plugin->admin_workflows(), 'render' ),
	'workflow'     => array( $plugin->admin_workflow(), 'render' ),
);

foreach ( $recoveryflow_screens as $recoveryflow_name => $recoveryflow_render ) {
	$recoveryflow_html = recoveryflow_render_screen( $recoveryflow_render );

	ok( "the {$recoveryflow_name} screen renders", false !== strpos( $recoveryflow_html, '<div class="wrap' ) );
	ok( "the {$recoveryflow_name} screen has exactly one top-level heading", 1 === substr_count( $recoveryflow_html, '<h1' ) );
}

// Every screen must refuse somebody without its capability. wp_die is stubbed
// to throw, so a screen that rendered anyway is a screen that failed to check.
foreach ( $recoveryflow_screens as $recoveryflow_name => $recoveryflow_render ) {
	$GLOBALS['recoveryflow_caps'] = array( 'read' );
	$recoveryflow_refused         = false;

	try {
		recoveryflow_render_screen( $recoveryflow_render );
	} catch ( RuntimeException $e ) {
		$recoveryflow_refused = true;
	}

	ok( "the {$recoveryflow_name} screen refuses a user without the capability", $recoveryflow_refused );
}

$GLOBALS['recoveryflow_caps'] = null;

// The status screen states each result in words in a column of its own, so a
// screenshot pasted into a support thread keeps its meaning.
$recoveryflow_html = recoveryflow_render_screen( array( $plugin->admin_status(), 'render' ) );

foreach ( $plugin->health()->checks() as $recoveryflow_check ) {
	ok( "the status screen shows the {$recoveryflow_check['id']} check", false !== strpos( $recoveryflow_html, esc_html( (string) $recoveryflow_check['label'] ) ) );
}

ok( 'and says what each result means rather than only colouring it', false !== strpos( $recoveryflow_html, esc_html( _x( 'Working', 'the result of a system check', 'kdc-wacr-recoveryflow' ) ) ) );
ok( 'and names every background pass in words, not stage keys', false !== strpos( $recoveryflow_html, esc_html__( 'Finding abandoned baskets', 'kdc-wacr-recoveryflow' ) ) );
ok( 'a failing check links to the setting that fixes it', false !== strpos( $recoveryflow_html, esc_html__( 'Go to this setting', 'kdc-wacr-recoveryflow' ) ) );

// The overview shows what is wrong, not a wall of ticks somebody scrolls past.
$recoveryflow_html = recoveryflow_render_screen( array( $plugin->admin_overview(), 'render' ) );

ok( 'the overview leads with what needs attention', false !== strpos( $recoveryflow_html, esc_html__( 'Needs attention', 'kdc-wacr-recoveryflow' ) ) );

// Counted rather than looked for. A list of ticks is something people learn to
// scroll past, and the one line that matters is then buried in the middle of
// it -- so the overview must show exactly the checks that did not pass, and no
// others.
$recoveryflow_failing = 0;
$recoveryflow_passing = 0;

foreach ( $plugin->health()->checks() as $recoveryflow_check ) {
	if ( Health::OK === $recoveryflow_check['severity'] ) {
		++$recoveryflow_passing;
	} else {
		++$recoveryflow_failing;
	}
}

ok( 'this site has checks in both states, so the count means something', $recoveryflow_failing > 0 && $recoveryflow_passing > 0 );
check( 'the overview lists exactly the checks that need attention', substr_count( $recoveryflow_html, 'recoveryflow-attention__item' ), $recoveryflow_failing * 2 );

/*
 * The workflow list. Its job is to answer "what does this one actually do"
 * without the reader opening anything, so the assertions are on the SENTENCES
 * -- a card that renders the stored JSON, or renders a step count and no more,
 * would pass a "the screen renders" check and fail the reader.
 */
$recoveryflow_snapshot_before = get_option( Options::ME_SNAPSHOT, array() );

/*
 * Primed with the definitions the plugin actually seeds, read off the
 * repository rather than retyped here. A test that carries its own copy of a
 * definition stops testing the shipped one the first time somebody edits the
 * seed, and goes on passing.
 */
$recoveryflow_seeded = static function ( string $method ): array {
	$reflection = new ReflectionMethod( Workflow_Repository::class, $method );
	$reflection->setAccessible( true );

	return (array) $reflection->invoke( null );
};

$recoveryflow_row = static function ( int $id, string $slug, array $definition, bool $is_default ): array {
	return array(
		'id'              => $id,
		'name'            => (string) ( $definition['name'] ?? '' ),
		'slug'            => $slug,
		'source_id'       => '',
		'status'          => 'active',
		'definition_json' => wp_json_encode( $definition ),
		'definition_hash' => '',
		'version'         => 1,
		'is_default'      => $is_default ? 1 : 0,
		'created_at'      => '2026-01-01 00:00:00',
		'updated_at'      => '2026-01-01 00:00:00',
	);
};

$GLOBALS['wpdb']->rows['recoveryflow_workflows'] = array(
	$recoveryflow_row( 1, Workflow_Repository::SLUG_DIRECT, $recoveryflow_seeded( 'direct_definition' ), true ),
	$recoveryflow_row( 2, Workflow_Repository::SLUG_HANDOFF, $recoveryflow_seeded( 'handoff_definition' ), false ),
);

update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => true,
		'scopes' => array( 'messages:send', 'templates:read' ),
	)
);

$recoveryflow_html = recoveryflow_render_screen( array( $plugin->admin_workflows(), 'render' ) );

ok( 'the workflow list names each workflow', false !== strpos( $recoveryflow_html, esc_html( 'Cart recovery' ) ) );
ok( 'and puts a wait into words rather than an ISO duration', false !== strpos( $recoveryflow_html, esc_html__( 'Waits 1 day before going on.', 'kdc-wacr-recoveryflow' ) ) );
ok( 'and never shows a raw duration', false === strpos( $recoveryflow_html, 'P1D' ) );
ok( 'and says what a check does in plain words', false !== strpos( $recoveryflow_html, esc_html__( 'the order has still not been placed', 'kdc-wacr-recoveryflow' ) ) );
ok( 'and names the raw condition nowhere on the screen', false === strpos( $recoveryflow_html, 'journey.not_completed' ) );
ok( 'and says which channel a send goes over', false !== strpos( $recoveryflow_html, esc_html( _x( 'WhatsApp', 'message channel', 'kdc-wacr-recoveryflow' ) ) ) );
ok( 'and says whether a workflow is the one new recoveries start on', false !== strpos( $recoveryflow_html, esc_html__( 'Active, and used for new recoveries', 'kdc-wacr-recoveryflow' ) ) );
ok( 'a connected workspace is offered a new workflow', false !== strpos( $recoveryflow_html, esc_html__( 'Add workflow', 'kdc-wacr-recoveryflow' ) ) );
ok( 'and is not told the screen is read-only', false === strpos( $recoveryflow_html, esc_html__( 'Workflows are read-only on this workspace', 'kdc-wacr-recoveryflow' ) ) );

// The other direction. A gate that is only ever exercised in one state is a
// gate whose decision no assertion can see: reversing it would change nothing.
update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => false,
		'reason' => 'plan_upgrade_required',
	)
);

$recoveryflow_html = recoveryflow_render_screen( array( $plugin->admin_workflows(), 'render' ) );

ok( 'a workspace below Scale still sees its workflows', false !== strpos( $recoveryflow_html, esc_html( 'Cart recovery' ) ) );
ok( 'and is told the screen is read-only', false !== strpos( $recoveryflow_html, esc_html__( 'Workflows are read-only on this workspace', 'kdc-wacr-recoveryflow' ) ) );
ok( 'and is told why in words it can act on', false !== strpos( $recoveryflow_html, esc_html( Feature_Gate::unavailable_reason() ) ) );
ok( 'and is not offered a new workflow it could not save', false === strpos( $recoveryflow_html, esc_html__( 'Add workflow', 'kdc-wacr-recoveryflow' ) ) );

/*
 * The exemption that keeps the Lite path from being trialware: handing a
 * recovery to a WA.cr Auto Flow needs no developer API, so the hand-off
 * workflow stays editable on a plan where the direct-send one does not.
 */
ok(
	'the hand-off workflow stays editable below Scale',
	false !== strpos(
		$recoveryflow_html,
		esc_html(
			sprintf(
				/* translators: %s: a workflow name. */
				__( 'Edit %s', 'kdc-wacr-recoveryflow' ),
				(string) ( $recoveryflow_seeded( 'handoff_definition' )['name'] ?? '' )
			)
		)
	)
);
ok(
	'while the direct-send one does not',
	false === strpos(
		$recoveryflow_html,
		esc_html(
			sprintf(
				/* translators: %s: a workflow name. */
				__( 'Edit %s', 'kdc-wacr-recoveryflow' ),
				(string) ( $recoveryflow_seeded( 'direct_definition' )['name'] ?? '' )
			)
		)
	)
);
ok( 'and is told why that one is closed', false !== strpos( $recoveryflow_html, esc_html__( 'This workflow sends from WordPress, which this workspace\'s plan does not include, so it cannot be edited or run here.', 'kdc-wacr-recoveryflow' ) ) );

update_option( Options::ME_SNAPSHOT, $recoveryflow_snapshot_before );
$GLOBALS['wpdb']->rows = array();

/*
 * The editor. The property that matters is the round trip: what the screen
 * draws, posted back unchanged, must be a definition the validator accepts.
 * A screen that offers a choice its own validator refuses is a screen that
 * tells a merchant they did something wrong when they did exactly as asked,
 * so this is asserted rather than left to the fact that both were written on
 * the same afternoon.
 */
$recoveryflow_posted = array(
	'workflow_id'     => 0,
	'workflow_name'   => 'Two touches',
	'workflow_active' => '1',
	'step'            => array(
		array(
			'type' => 'condition',
			'if'   => 'journey.not_completed',
			'else' => 'stop:recovered',
		),
		array(
			'type'        => 'wait',
			'wait_amount' => '2',
			'wait_unit'   => 'hours',
		),
		array(
			'type'     => 'action',
			'do'       => 'wacr.send_template',
			'channel'  => 'whatsapp',
			'template' => 'cart_reminder_1',
		),
	),
);

$recoveryflow_definition = Workflow_Form::read( $recoveryflow_posted );

check( 'the form reads the name a merchant typed', $recoveryflow_definition['name'], 'Two touches' );
check( 'and keeps the steps in the order they were posted', count( $recoveryflow_definition['steps'] ), 3 );
ok( 'and what the form builds is a definition the validator accepts', true === Workflow_Definition::validate( $recoveryflow_definition ) );

// A number and a unit, not an ISO duration typed by hand.
check( 'two hours becomes an ISO duration', Workflow_Form::duration( 2, 'hours' ), 'PT2H' );
check( 'and a day is stored as a day', Workflow_Form::duration( 1, 'days' ), 'P1D' );
check( 'and an unknown unit falls back to hours rather than to nothing', Workflow_Form::duration( 3, 'fortnights' ), 'PT3H' );

// The way back must be the way in, or a merchant reopening the screen finds
// their "1 day" has become "1440 minutes" and edits something they did not write.
$recoveryflow_split = Workflow_Form::split_duration( 'P1D' );
check( 'a day comes back as a day', $recoveryflow_split['unit'], 'days' );
check( 'and as one of them', $recoveryflow_split['amount'], 1 );

$recoveryflow_split = Workflow_Form::split_duration( 'PT90M' );
check( 'ninety minutes stays in minutes, because no larger unit divides it', $recoveryflow_split['unit'], 'minutes' );
check( 'and keeps its value', $recoveryflow_split['amount'], 90 );

// Arguments belong to the action that understands them. Changing a step from a
// send to a hand-off and submitting must not carry a template onto a step that
// has no use for one -- the validator would refuse it, naming a field the
// merchant can no longer see.
$recoveryflow_switched                     = $recoveryflow_posted;
$recoveryflow_switched['step'][2]['do']    = 'wacr.start_flow';
$recoveryflow_handoff                      = Workflow_Form::read( $recoveryflow_switched );

ok( 'switching an action drops the old action\'s arguments', ! isset( $recoveryflow_handoff['steps'][2]['with']['template'] ) );
ok( 'and supplies the new one\'s default', 'primary' === $recoveryflow_handoff['steps'][2]['with']['hook'] );
ok( 'so the switched definition still validates', true === Workflow_Definition::validate( $recoveryflow_handoff ) );

// A step type nobody registered is dropped rather than written through.
$recoveryflow_junk                  = $recoveryflow_posted;
$recoveryflow_junk['step'][]        = array(
	'type' => 'exec',
	'do'   => 'rm -rf',
);
$recoveryflow_read                  = Workflow_Form::read( $recoveryflow_junk );

check( 'a step type the plugin does not know is dropped, not stored', count( $recoveryflow_read['steps'] ), 3 );

// A channel nobody offers falls back rather than being written through.
$recoveryflow_junk                          = $recoveryflow_posted;
$recoveryflow_junk['step'][2]['channel']    = 'carrier-pigeon';
$recoveryflow_read                          = Workflow_Form::read( $recoveryflow_junk );

check( 'an unknown channel falls back to WhatsApp', $recoveryflow_read['steps'][2]['channel'], 'whatsapp' );

// Add, remove and move are submit buttons, so each is exercised as one.
check( 'no button pressed means save', Workflow_Form::command( array() )['name'], 'save' );
check( 'and a pressed button is read with the step it names', Workflow_Form::command( array( 'move_up' => '2' ) )['index'], 2 );

$recoveryflow_moved = Workflow_Form::rearrange(
	$recoveryflow_definition,
	array(
		'name'  => 'move_up',
		'index' => 1,
	)
);

check( 'moving a step up puts it above the one that was there', $recoveryflow_moved['steps'][0]['type'], 'wait' );
check( 'and puts that one below it', $recoveryflow_moved['steps'][1]['type'], 'condition' );

$recoveryflow_moved = Workflow_Form::rearrange(
	$recoveryflow_definition,
	array(
		'name'  => 'move_up',
		'index' => 0,
	)
);
check( 'moving the first step up does nothing rather than falling off the top', $recoveryflow_moved['steps'][0]['type'], 'condition' );

$recoveryflow_moved = Workflow_Form::rearrange(
	$recoveryflow_definition,
	array(
		'name'  => 'move_down',
		'index' => 2,
	)
);
check( 'and moving the last step down does nothing either', $recoveryflow_moved['steps'][2]['type'], 'action' );

$recoveryflow_moved = Workflow_Form::rearrange(
	$recoveryflow_definition,
	array(
		'name'  => 'remove_step',
		'index' => 1,
	)
);
check( 'removing a step removes exactly one', count( $recoveryflow_moved['steps'] ), 2 );
ok( 'and what is left still validates', true === Workflow_Definition::validate( $recoveryflow_moved ) );

$recoveryflow_moved = Workflow_Form::rearrange(
	$recoveryflow_definition,
	array(
		'name'  => 'add_step',
		'index' => -1,
	)
);
check( 'adding a step adds exactly one', count( $recoveryflow_moved['steps'] ), 4 );
ok( 'and the step it adds is one the validator accepts', true === Workflow_Definition::validate( $recoveryflow_moved ) );

/*
 * The rule that decides whether a workflow may be edited and stored on a plan
 * below Scale. It lives on the definition because two screens ask it, and a
 * list that offers an edit the save then refuses would be worse than either
 * answer on its own -- so both are asserted against the same function.
 */
ok( 'a workflow that sends from WordPress needs the developer API', Workflow_Definition::needs_developer_api( $recoveryflow_definition ) );
ok( 'and one that only hands off does not', ! Workflow_Definition::needs_developer_api( $recoveryflow_handoff ) );
ok( 'a workflow with no steps at all does not either', ! Workflow_Definition::needs_developer_api( array( 'steps' => array() ) ) );

$recoveryflow_snapshot_before = get_option( Options::ME_SNAPSHOT, array() );

update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => false,
		'reason' => 'plan_upgrade_required',
	)
);

ok( 'below Scale a sending workflow is refused before it reaches the store', ! Workflow_Form::may_write( $recoveryflow_definition ) );
ok( 'but a hand-off workflow is still allowed, which is the whole Lite path', Workflow_Form::may_write( $recoveryflow_handoff ) );

update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => true,
		'scopes' => array( 'messages:send' ),
	)
);

ok( 'and on Scale both are allowed', Workflow_Form::may_write( $recoveryflow_definition ) && Workflow_Form::may_write( $recoveryflow_handoff ) );

/*
 * The editor's own round trip, asserted on the rendered HTML rather than on
 * the form reader alone: every value the screen offers in a select must be one
 * the validator accepts. A screen that offers a choice its own validator
 * refuses tells a merchant they got it wrong when they did exactly as asked.
 */
$GLOBALS['wpdb']->rows['recoveryflow_workflows'] = array(
	$recoveryflow_row( 1, Workflow_Repository::SLUG_DIRECT, $recoveryflow_seeded( 'direct_definition' ), true ),
);

$_GET['workflow']  = 1;
$recoveryflow_html = recoveryflow_render_screen( array( $plugin->admin_workflow(), 'render' ) );
unset( $_GET['workflow'] );

ok( 'the editor opens a stored workflow', false !== strpos( $recoveryflow_html, esc_html__( 'Edit workflow', 'kdc-wacr-recoveryflow' ) ) );
ok( 'and draws its name into the field', false !== strpos( $recoveryflow_html, 'name="workflow_name"' ) );
ok( 'and numbers each step where a support call can refer to it', false !== strpos( $recoveryflow_html, esc_html( sprintf( __( 'Step %1$d. %2$s', 'kdc-wacr-recoveryflow' ), 1, Step_Describer::describe( $recoveryflow_seeded( 'direct_definition' )['steps'][0] ) ) ) ) );
ok( 'and posts to admin-post rather than to an endpoint of its own', false !== strpos( $recoveryflow_html, 'admin-post.php' ) );
ok( 'and carries a nonce', false !== strpos( $recoveryflow_html, '_wpnonce' ) );

// Add, remove and move must be real submit buttons, or the editor stops working
// the moment a script is blocked -- which is the entire reason it is built this way.
foreach ( array( 'add_step', 'remove_step', 'move_down' ) as $recoveryflow_button ) {
	ok( "the editor's {$recoveryflow_button} control is a submit button, not a script", false !== strpos( $recoveryflow_html, 'name="' . $recoveryflow_button . '"' ) );
}

ok( 'the editor ships no inline script at all', false === stripos( $recoveryflow_html, '<script' ) );

// Every option in every select, checked against what the validator will accept.
$recoveryflow_offered = array();
preg_match_all( '/<select name="step\[(\d+)\]\[(\w+)\]".*?<\/select>/s', $recoveryflow_html, $recoveryflow_selects, PREG_SET_ORDER );

ok( 'the editor renders selects for its steps', count( $recoveryflow_selects ) > 0 );

foreach ( $recoveryflow_selects as $recoveryflow_select ) {
	preg_match_all( '/value="([^"]*)"/', $recoveryflow_select[0], $recoveryflow_values );
	$recoveryflow_offered[ $recoveryflow_select[2] ] = array_unique(
		array_merge( $recoveryflow_offered[ $recoveryflow_select[2] ] ?? array(), $recoveryflow_values[1] )
	);
}

/*
 * Each group is counted before it is walked. A foreach over an empty array
 * passes every assertion inside it and asserts nothing, so a regex that stops
 * matching -- or a field that stops being rendered -- would turn this whole
 * block green while checking nothing at all.
 */
foreach ( array( 'type', 'if', 'do', 'else', 'channel' ) as $recoveryflow_group ) {
	ok( "the editor offers at least one {$recoveryflow_group} to choose from", count( $recoveryflow_offered[ $recoveryflow_group ] ?? array() ) > 0 );
}

foreach ( ( $recoveryflow_offered['type'] ?? array() ) as $recoveryflow_value ) {
	ok( "the type select only offers {$recoveryflow_value}, which the validator knows", in_array( $recoveryflow_value, array( 'condition', 'wait', 'action' ), true ) );
}

foreach ( ( $recoveryflow_offered['if'] ?? array() ) as $recoveryflow_value ) {
	ok( "the check select only offers {$recoveryflow_value}, which is registered", null !== $plugin->steps()->condition( $recoveryflow_value ) );
}

foreach ( ( $recoveryflow_offered['do'] ?? array() ) as $recoveryflow_value ) {
	ok( "the send select only offers {$recoveryflow_value}, which is registered", null !== $plugin->steps()->action( $recoveryflow_value ) );
}

foreach ( ( $recoveryflow_offered['else'] ?? array() ) as $recoveryflow_value ) {
	ok(
		"the stop select only offers {$recoveryflow_value}, which is a state a journey can end in",
		'' === $recoveryflow_value || '' !== Workflow_Definition::stop_state( $recoveryflow_value )
	);
}

foreach ( ( $recoveryflow_offered['channel'] ?? array() ) as $recoveryflow_value ) {
	ok( "the channel select only offers {$recoveryflow_value}, which is a real channel", in_array( $recoveryflow_value, Workflow_Definition::CHANNELS, true ) );
}

// Every wait unit the screen offers must be one the form can actually build.
// The unit select is the one place a value goes to the form reader rather than
// to the validator, so the "only offers what the validator accepts" property
// above does not reach it: an added unit would silently become hours.
foreach ( ( $recoveryflow_offered['wait_unit'] ?? array() ) as $recoveryflow_value ) {
	ok(
		"the wait unit select only offers {$recoveryflow_value}, which the form can build",
		Workflow_Form::duration( 3, $recoveryflow_value ) !== Workflow_Form::duration( 3, 'a unit that does not exist' )
			|| 'hours' === $recoveryflow_value
	);
}

/*
 * The editor must carry the id of what it is editing. Without it every save
 * makes a NEW workflow and leaves the original untouched, which looks like a
 * save that worked and is discovered weeks later as a list of near-duplicates
 * with the original still running. Nothing else on the screen would look wrong.
 */
ok( 'the editor carries the id of the workflow it is editing', 1 === preg_match( '/name="workflow_id" value="1"/', $recoveryflow_html ) );

$_GET['workflow']       = 0;
$recoveryflow_new_html  = recoveryflow_render_screen( array( $plugin->admin_workflow(), 'render' ) );
unset( $_GET['workflow'] );

ok( 'and a new workflow carries a zero rather than somebody else\'s id', 1 === preg_match( '/name="workflow_id" value="0"/', $recoveryflow_new_html ) );
ok( 'and is headed as an addition, not an edit', false !== strpos( $recoveryflow_new_html, esc_html__( 'Add workflow', 'kdc-wacr-recoveryflow' ) ) );

/*
 * A refused save must hand the work back, not the stored version. Sending
 * somebody to the last-saved workflow after telling them their edit was wrong
 * discards everything they had typed AND makes the refusal about something
 * they can no longer see -- the two worst halves of the same page load.
 */
set_transient(
	'recoveryflow_workflow_draft_' . get_current_user_id(),
	array(
		'name'  => 'A name only in the unsaved edit',
		'steps' => array(
			array(
				'type' => 'wait',
				'for'  => 'PT45M',
			),
		),
	),
	60
);

$_GET['workflow']        = 1;
$recoveryflow_draft_html = recoveryflow_render_screen( array( $plugin->admin_workflow(), 'render' ) );
unset( $_GET['workflow'] );

ok( 'a refused edit is shown back rather than replaced by the stored one', false !== strpos( $recoveryflow_draft_html, esc_attr( 'A name only in the unsaved edit' ) ) );
ok( 'and keeps the steps as they were being edited', false !== strpos( $recoveryflow_draft_html, 'value="45"' ) );
ok( 'and the draft is consumed, so it does not reappear on the next visit', null === Workflow_Form::draft() );


update_option( Options::ME_SNAPSHOT, $recoveryflow_snapshot_before );
$GLOBALS['wpdb']->rows = array();

/*
 * The one screen that can show a customer's real phone number. Contact details
 * are shortened until somebody with the reveal capability asks -- both halves,
 * so a screen left open on a counter is not a list of phone numbers.
 */
$recoveryflow_detail = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Admin/Pages/Journey_Detail.php', 'may_reveal' );

ok( 'the reveal rule can be read', strlen( $recoveryflow_detail ) > 50 );
// Both halves in one condition, asserted as one condition: checking only that
// the words appear somewhere in the method would pass on a version that
// computed $asked and then ignored it.
ok( 'revealing requires having asked AND holding the capability', false !== strpos( $recoveryflow_detail, '! $asked || ! current_user_can' ) );
ok( 'and the capability it requires is the reveal one', false !== strpos( $recoveryflow_detail, 'REVEAL_PII' ) );
ok( 'and is recorded when it happens', false !== strpos( $recoveryflow_detail, 'KIND_REVEAL' ) );

$recoveryflow_contact = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Admin/Pages/Journey_Detail.php', 'contact' );

ok( 'a contact detail is masked when revealing was not earned', false !== strpos( $recoveryflow_contact, 'Mask::phone' ) );
ok( 'and so is an email address', false !== strpos( $recoveryflow_contact, 'Mask::email' ) );

// The list is masked with no way to unmask it at all: it is the screen that
// sits open, and one row at a time is the point of the detail screen.
$recoveryflow_list = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Pages/Journeys_Table.php' );

ok( 'the recoveries list masks the customer', false !== strpos( $recoveryflow_list, 'Mask::name' ) );
ok( 'and offers no way to unmask a whole list at once', false === strpos( $recoveryflow_list, 'REVEAL_PII' ) );

// WP_List_Table lives in wp-admin/includes and is not loaded on every request.
// A subclass of it fatals the moment its file is included unless something has
// required it first.
$recoveryflow_journeys_page = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Pages/Journeys.php' );

ok( 'the list table class is loaded before the subclass is reached', false !== strpos( $recoveryflow_journeys_page, 'class-wp-list-table.php' ) );

/*
 * The assets. Enqueuing is exercised rather than inspected, because the whole
 * failure mode here is a function that does not exist on the request the
 * enqueue actually runs on.
 */
$GLOBALS['recoveryflow_styles']         = array();
$GLOBALS['recoveryflow_inline_scripts'] = array();

$plugin->admin_assets()->enqueue( $recoveryflow_hooks[0] );

ok( 'the stylesheet loads on a RecoveryFlow screen', isset( $GLOBALS['recoveryflow_styles']['recoveryflow-admin'] ) );
ok( 'and the script is handed its configuration', isset( $GLOBALS['recoveryflow_inline_scripts']['recoveryflow-admin'] ) );

$recoveryflow_raw = $GLOBALS['recoveryflow_inline_scripts']['recoveryflow-admin'][0];

// Decoded rather than string-matched: wp_json_encode escapes the slashes in a
// URL, so looking for the path as written would fail on a config that was
// perfectly correct.
$recoveryflow_config = json_decode(
	(string) substr( $recoveryflow_raw, (int) strpos( $recoveryflow_raw, '{' ), -1 ),
	true
);

ok( 'the configuration is valid JSON', is_array( $recoveryflow_config ) );
ok( 'including a REST nonce, or every panel it saves is refused', '' !== (string) ( $recoveryflow_config['nonce'] ?? '' ) );
ok( 'and the address to save to', false !== strpos( (string) ( $recoveryflow_config['uiStateUrl'] ?? '' ), Routes::PREFIX . '/ui-state' ) );
ok( 'and its wording, already translated, so none lives in the script', is_array( $recoveryflow_config['strings'] ?? null ) );
ok( 'and nothing about any customer', false === stripos( $recoveryflow_raw, 'phone' ) && false === stripos( $recoveryflow_raw, '@' ) );

// A plugin that enqueues on every admin page is a plugin that breaks somebody
// else's screen.
$GLOBALS['recoveryflow_styles'] = array();
$plugin->admin_assets()->enqueue( 'edit.php' );

check( 'and nothing loads on a screen that is not ours', $GLOBALS['recoveryflow_styles'], array() );


echo "\n";
echo "\n";
echo $failed > 0 ? "FAILED\n" : "PASSED\n";
echo "{$passed} passed, {$failed} failed\n";

exit( $failed > 0 ? 1 : 0 );
