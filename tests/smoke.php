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

use WAcr\RecoveryFlow\Core\Autoloader;
use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Core\Upgrader;
use WAcr\RecoveryFlow\Core\Plugin;
use WAcr\RecoveryFlow\Core\Requirements;
use WAcr\RecoveryFlow\Core\Rewrites;
use WAcr\RecoveryFlow\Customer\Identity;
use WAcr\RecoveryFlow\Customer\Identity_Repository;
use WAcr\RecoveryFlow\Customer\Mask;
use WAcr\RecoveryFlow\Customer\Phone_Normalizer;
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
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Security\Crypto;
use WAcr\RecoveryFlow\Security\Hash_Key;
use WAcr\RecoveryFlow\Security\Token_Service;
use WAcr\RecoveryFlow\Support\Options;
use WAcr\RecoveryFlow\Support\Uuid;
use WAcr\RecoveryFlow\WAcr\Credentials;
use WAcr\RecoveryFlow\WAcr\Error;
use WAcr\RecoveryFlow\WAcr\Rate_Budget;
use WAcr\RecoveryFlow\WAcr\Send_Request;
use WAcr\RecoveryFlow\WAcr\Transport;


( new Autoloader( dirname( __DIR__ ) . '/src/' ) )->register();

require_once __DIR__ . '/probes.php';
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
ok( 'no credential means no direct sending', ! Feature_Gate::is_enabled( Feature_Gate::DIRECT_SEND ) );
check( 'and it says why', Feature_Gate::unavailable_reason(), 'No WA.cr API key is connected yet.' );

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

echo "\n";
echo "\n";
echo $failed > 0 ? "FAILED\n" : "PASSED\n";
echo "{$passed} passed, {$failed} failed\n";

exit( $failed > 0 ? 1 : 0 );
