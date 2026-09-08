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
use WAcr\RecoveryFlow\Core\Plugin;
use WAcr\RecoveryFlow\Core\Requirements;
use WAcr\RecoveryFlow\Core\Rewrites;
use WAcr\RecoveryFlow\Customer\Mask;
use WAcr\RecoveryFlow\Customer\Phone_Normalizer;
use WAcr\RecoveryFlow\Privacy\Redactor;
use WAcr\RecoveryFlow\Recovery\Journey_State;
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
ok( 'a phone identifies one customer', false !== strpos( $joined, 'UNIQUE KEY phone_hash (phone_hash)' ) );
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

// -------------------------------------------------------------------- Result.




echo "\n";
echo $failed > 0 ? "FAILED\n" : "PASSED\n";
echo "{$passed} passed, {$failed} failed\n";

exit( $failed > 0 ? 1 : 0 );
