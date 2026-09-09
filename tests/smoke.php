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
use WAcr\RecoveryFlow\Customer\Identity_Resolver;
use WAcr\RecoveryFlow\Customer\Mask;
use WAcr\RecoveryFlow\Customer\Phone_Normalizer;
use WAcr\RecoveryFlow\Privacy\Anonymizer;
use WAcr\RecoveryFlow\Privacy\Eraser;
use WAcr\RecoveryFlow\Privacy\Exporter;
use WAcr\RecoveryFlow\Privacy\Redactor;
use WAcr\RecoveryFlow\Privacy\Erase_By_Phone;
use WAcr\RecoveryFlow\Admin\Deferred_Form;
use WAcr\RecoveryFlow\Recovery\Attempt;
use WAcr\RecoveryFlow\Recovery\Channel;
use WAcr\RecoveryFlow\Recovery\Eligibility;
use WAcr\RecoveryFlow\Support\User_Agent;
use WAcr\RecoveryFlow\Workflow\Actions\Send_Email;
use WAcr\RecoveryFlow\Workflow\Email_Composer;
use WAcr\RecoveryFlow\Recovery\Email_Sender;
use WAcr\RecoveryFlow\Recovery\Email_Message;
use WAcr\RecoveryFlow\Recovery\Email_Compliance;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Workflow\Send_Gate;
use WAcr\RecoveryFlow\Integration\Abstract_Source;
use WAcr\RecoveryFlow\Integration\Custom\Example_Source;
use WAcr\RecoveryFlow\Integration\Recovery_Source_Interface;
use WAcr\RecoveryFlow\Integration\GravityForms\Draft_Watcher as Gf_Draft_Watcher;
use WAcr\RecoveryFlow\Integration\GravityForms\Field_Map as Gf_Field_Map;
use WAcr\RecoveryFlow\Integration\GravityForms\Settings as Gf_Settings;
use WAcr\RecoveryFlow\Integration\GravityForms\Source as Gf_Source;
use WAcr\RecoveryFlow\Integration\GravityForms\Unpaid_Entry as Gf_Unpaid_Entry;
use WAcr\RecoveryFlow\Integration\Source_Registry;
use WAcr\RecoveryFlow\Integration\Event_Batch;
use WAcr\RecoveryFlow\Integration\Pollable_Source_Interface;
use WAcr\RecoveryFlow\Jobs\Scheduler_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Label;
use WAcr\RecoveryFlow\Jobs\Stage_Stats;
use WAcr\RecoveryFlow\Jobs\Stages\Evaluate;
use WAcr\RecoveryFlow\Jobs\Time_Budget;
use WAcr\RecoveryFlow\Recovery\Event_Draft;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Integration\WooCommerce\Checkout_Script;
use WAcr\RecoveryFlow\Integration\WooCommerce\Consent_Field;
use WAcr\RecoveryFlow\Integration\WooCommerce\Contact_Snapshot;
use WAcr\RecoveryFlow\Integration\WooCommerce\Phone_Requirement;
use WAcr\RecoveryFlow\Integration\WooCommerce\Session;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Database\Schema;
use WAcr\RecoveryFlow\Database\Table_Names;
use WAcr\RecoveryFlow\REST\Abstract_Controller;
use WAcr\RecoveryFlow\REST\Routes;
use WAcr\RecoveryFlow\REST\Journeys_Controller;
use WAcr\RecoveryFlow\Security\Webhook_Secret;
use WAcr\RecoveryFlow\REST\Webhook_Controller;
use WAcr\RecoveryFlow\Database\Receipt_Repository;
use WAcr\RecoveryFlow\REST\Settings_Controller;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Security\Crypto;
use WAcr\RecoveryFlow\Security\Hash_Key;
use WAcr\RecoveryFlow\Security\Token_Service;
use WAcr\RecoveryFlow\Support\Money;
use WAcr\RecoveryFlow\Support\Options;
use WAcr\RecoveryFlow\Support\Uuid;
use WAcr\RecoveryFlow\Admin\Connection_Test;
use WAcr\RecoveryFlow\Admin\Diagnostics;
use WAcr\RecoveryFlow\Admin\Hook_Test;
use WAcr\RecoveryFlow\Admin\Webhook_Setup;
use WAcr\RecoveryFlow\Admin\Journey_Actions;
use WAcr\RecoveryFlow\Admin\Run_Now;
use WAcr\RecoveryFlow\Admin\Setup;
use WAcr\RecoveryFlow\WAcr\Flow_Status;
use WAcr\RecoveryFlow\Workflow\Actions\Start_Flow;
use WAcr\RecoveryFlow\Workflow\Actions\Send_Template;
use WAcr\RecoveryFlow\Workflow\Step_Outcome;
use WAcr\RecoveryFlow\WAcr\Credentials;
use WAcr\RecoveryFlow\WAcr\Opt_Out_Sync;
use WAcr\RecoveryFlow\WAcr\Template_Catalog;
use WAcr\RecoveryFlow\WAcr\Error;
use WAcr\RecoveryFlow\WAcr\Result;
use WAcr\RecoveryFlow\WAcr\Rate_Budget;
use WAcr\RecoveryFlow\WAcr\Send_Request;
use WAcr\RecoveryFlow\WAcr\Transport;
use WAcr\RecoveryFlow\Admin\Step_Describer;
use WAcr\RecoveryFlow\Admin\Workflow_Form;
use WAcr\RecoveryFlow\Workflow\Message_Composer;
use WAcr\RecoveryFlow\Workflow\Variable_Context;
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

/*
 * And says what THAT needs, which for a release it did not. "Your workspace can
 * still hand recovery journeys to a WA.cr Auto Flow" is false on free, trial
 * and starter -- whose Auto Flow allowance is zero -- and false on enterprise
 * for the same reason. Only Growth and above can run one at all. The assertion
 * above passed the whole time, because it only ever asked whether the words
 * "Auto Flow" appeared.
 */
ok( 'and what the hand-off itself needs, so it is not read as "any plan"', false !== strpos( Feature_Gate::unavailable_reason(), 'Growth' ) );
ok( 'stated in one place, so the two messages cannot drift apart', false !== strpos( Feature_Gate::unavailable_reason(), Feature_Gate::auto_flow_requirement() ) );

/*
 * The listing is where this claim does the most damage, because it is read
 * before anybody installs anything and it is the one text nothing else checks.
 * It said the hand-off "works on every WA.cr plan".
 */
$recoveryflow_listing = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );

ok( 'the listing was read at all', strlen( $recoveryflow_listing ) > 2000 );

foreach ( array(
	'works on every WA.cr plan',
	'works on any WA.cr workspace',
	'no particular plan is',
) as $recoveryflow_overclaim ) {
	ok(
		"the listing does not promise the hand-off works regardless of plan ({$recoveryflow_overclaim})",
		false === stripos( $recoveryflow_listing, $recoveryflow_overclaim )
	);
}

ok( 'and names the plan the hand-off needs', false !== strpos( $recoveryflow_listing, 'Growth' ) );
ok(
	'and says plainly what the whole WhatsApp half requires, rather than leaving it to be assembled',
	false !== strpos( $recoveryflow_listing, 'needs the WA.cr Growth plan or above' )
);

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

/*
 * Every terminal state is a dead end EXCEPT failed, and the exception is the
 * whole of what "retry" is allowed to mean. Recovered, expired, cancelled,
 * opted-out and invalid each carry a decision about the customer; failed
 * carries only "the machinery could not", which a person may reverse once they
 * have fixed the cause. Nothing else may follow failed either -- least of all a
 * jump straight back to message_sent, which would skip the send gate.
 */
foreach ( Journey_State::terminal() as $terminal_state ) {
	if ( Journey_State::FAILED === $terminal_state ) {
		check( 'a failed journey may be retried, and only into scheduled', Journey_State::transitions()[ $terminal_state ], array( Journey_State::SCHEDULED ) );

		continue;
	}

	check( "nothing follows {$terminal_state}", Journey_State::transitions()[ $terminal_state ], array() );
}

ok( 'a failed journey can be put back in the queue', Journey_State::can_transition( Journey_State::FAILED, Journey_State::SCHEDULED ) );
ok( 'but not straight back to sent, which would skip the send gate', ! Journey_State::can_transition( Journey_State::FAILED, Journey_State::MESSAGE_SENT ) );
ok( 'and an opted-out journey is still never retryable', ! Journey_State::can_transition( Journey_State::OPTED_OUT, Journey_State::SCHEDULED ) );
ok( 'nor an invalid one', ! Journey_State::can_transition( Journey_State::INVALID, Journey_State::SCHEDULED ) );
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
ok( 'while saying what the hand-off needs, from the same one sentence', false !== strpos( $err->message, Feature_Gate::auto_flow_requirement() ) );

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

/**
 * The same body with every comment removed.
 *
 * A source-inspecting assertion that matches a word in a COMMENT is asserting
 * that somebody wrote a sentence, not that the code does anything -- and the
 * comment explaining a rule almost always quotes the rule, so the two match the
 * same string. That is how "the queue acts through a POST to admin-post.php"
 * survived the mutation that changed the form to a GET: the docblock above it
 * still said admin-post.php.
 *
 * Comments are stripped through the tokeniser rather than by regular
 * expression, because a `//` inside a string literal is not a comment and this
 * file is full of URLs.
 *
 * @param string $body A method body, from the function above.
 * @return string
 */
function kdc_wacr_recoveryflow_code_only( string $body ): string {
	$code = '';
	$body = 0 === strpos( ltrim( $body ), '<?php' ) ? ltrim( $body ) : '<?php ' . $body;

	foreach ( token_get_all( $body ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$code .= is_array( $token ) ? $token[1] : $token;
	}

	return $code;
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

// ---------------------------------------------------------------------------
// Collecting a contact detail before the checkout.
//
// Most shoppers who abandon never reach the checkout, so these two points are
// what decides whether the majority of recorded baskets are recoverable at all.
// They are also the only place in the plugin that renders a field to the public
// and, for the basket, the only place that accepts a post from one -- so what
// is asserted here is mostly the restraint rather than the feature.
// ---------------------------------------------------------------------------

$recoveryflow_snapshot = new Contact_Snapshot( new Session() );

// A blank must never overwrite something known. A shopper who typed a number on
// the product page and left the basket field empty has not withdrawn it, and
// this is the rule the whole merge exists for.
ok( 'a first contact detail is written', $recoveryflow_snapshot->remember( array( 'phone' => '07700 900123' ) ) );
ok( 'writing the same value again is not a write', ! $recoveryflow_snapshot->remember( array( 'phone' => '07700 900123' ) ) );
ok( 'and a blank does not erase what is already known', ! $recoveryflow_snapshot->remember( array( 'phone' => '' ) ) );

check(
	'the number survived both',
	(string) ( ( (array) ( new Session() )->get( Session::KEY_CONTACT, array() ) )['phone'] ?? '' ),
	'07700 900123'
);

// The allow-list. A capture point reads a form other plugins also write to.
ok( 'a field nobody asked for is not stored', ! $recoveryflow_snapshot->remember( array( 'evil' => 'x' ) ) );

// A half-recognised country is worse than none: it turns a good number into a
// wrong number rather than into a refusal.
check( 'a two-letter country is kept', Contact_Snapshot::country( 'gb' ), 'GB' );
check( 'a single letter is discarded rather than padded', Contact_Snapshot::country( 'U' ), '' );
check( 'and so is anything that is not two letters', Contact_Snapshot::country( '44' ), '' );
check( 'a value longer than the cap is trimmed', strlen( Contact_Snapshot::clean( str_repeat( 'a', 500 ) ) ), Contact_Snapshot::MAX_LENGTH );

/*
 * Both points ship OFF, and this is asserted rather than eyeballed because it
 * is a product decision that a well-meaning later commit could reverse in one
 * character. Each one puts a field in front of somebody trying to get through a
 * page, and the phone requirement can cost a sale outright.
 */
ok( 'the basket capture point is off until a merchant turns it on', false === Options::defaults()['capture_at_cart'] );
ok( 'the add-to-cart capture point is off too', false === Options::defaults()['capture_at_add_to_cart'] );
ok( 'and the checkout phone is not made compulsory by default', false === Options::defaults()['checkout_phone_required'] );

/*
 * The compulsory-phone switch FILTERS WooCommerce's option and must never write
 * it. Writing it would edit a WooCommerce screen from a RecoveryFlow switch and
 * -- the part that actually hurts -- would survive this plugin being
 * deactivated, leaving a requirement nobody chose and no control that explains
 * it. Asserted in both directions.
 */
$recoveryflow_phone_req = new Phone_Requirement();

ok( 'with the switch off, WooCommerce own answer is handed back untouched', 'optional' === $recoveryflow_phone_req->require_phone( 'optional' ) );

$recoveryflow_phone_src = kdc_wacr_recoveryflow_code_only(
	(string) file_get_contents( dirname( __DIR__ ) . '/src/Integration/WooCommerce/Phone_Requirement.php' )
);

ok( 'the phone requirement never writes WooCommerce own setting', false === strpos( $recoveryflow_phone_src, 'update_option' ) );
ok( 'it filters the option read instead', false !== strpos( $recoveryflow_phone_src, "'option_' . self::OPTION" ) );
ok(
	'and it filters the default too, or a shop that never saved the setting is missed',
	false !== strpos( $recoveryflow_phone_src, "'default_option_' . self::OPTION" )
);

/*
 * The basket form is the only public write in the plugin, so all three defences
 * are asserted at the source of the handler. A nonce alone says where a post
 * came from and nothing about how often, and this form's nonce is on a page
 * anybody can load.
 */
$recoveryflow_early_src = kdc_wacr_recoveryflow_code_only(
	kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Integration/WooCommerce/Early_Capture.php', 'handle_cart_post' )
);

ok( 'the basket handler can be read', strlen( $recoveryflow_early_src ) > 50 );
ok( 'it verifies a nonce', false !== strpos( $recoveryflow_early_src, 'check_admin_referer' ) );
ok( 'it rate-limits the caller, because the nonce is public to anybody who can load the basket', false !== strpos( $recoveryflow_early_src, 'limiter->hit' ) );
ok( 'it refuses outright when the setting is off, rather than trusting the form not to exist', false !== strpos( $recoveryflow_early_src, 'wants_cart()' ) );
ok( 'and it answers with a redirect, so a refresh cannot re-post', false !== strpos( $recoveryflow_early_src, 'wp_safe_redirect' ) );

/*
 * The add-to-cart point must NOT open one. It rides WooCommerce's own form, and
 * the day it grows an endpoint is the day it needs its own nonce and its own
 * rate limit and its own review.
 */
$recoveryflow_atc_src = kdc_wacr_recoveryflow_code_only(
	kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Integration/WooCommerce/Early_Capture.php', 'register' )
);

ok(
	'the add-to-cart point rides WooCommerce own request',
	false !== strpos( $recoveryflow_atc_src, "'woocommerce_add_to_cart'" )
);
$recoveryflow_atc_branch = substr( $recoveryflow_atc_src, (int) strpos( $recoveryflow_atc_src, 'wants_add_to_cart()' ) );

ok( 'the add-to-cart branch can be isolated', strlen( $recoveryflow_atc_branch ) > 30 );
ok(
	'and it opens no address of its own -- only the basket form does that',
	false === strpos( $recoveryflow_atc_branch, 'admin_post' )
);

/*
 * THE BASKET FORM MUST NOT BE HOOKED ON THE SHORTCODE BASKET'S TEMPLATE.
 *
 * `woocommerce_after_cart_table` and `woocommerce_after_cart` both belong to
 * the shortcode basket. WooCommerce's Cart BLOCK renders none of that template
 * and fires neither -- so a form hooked there is switched on, saves, reports
 * itself as on, and does nothing whatever on any shop built in the last few
 * years. That is exactly what shipped here first, with a docblock claiming the
 * second hook covered the block basket. The accessibility run pressed the
 * button, found no form, and that is how it was caught.
 *
 * Asserted as a refusal rather than as a preference, because the wrong hook is
 * the obvious one and the next person will reach for it.
 */
ok(
	'the basket form is not hooked on the shortcode basket template',
	false === strpos( $recoveryflow_atc_src, "'woocommerce_after_cart" )
);
ok(
	'it renders through the page content, which is what both baskets have',
	false !== strpos( $recoveryflow_atc_src, "'the_content'" )
);

$recoveryflow_append_src = kdc_wacr_recoveryflow_code_only(
	kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Integration/WooCommerce/Early_Capture.php', 'append_to_cart_page' )
);

ok( 'the content filter can be read', strlen( $recoveryflow_append_src ) > 50 );

// A content filter runs on everything, so each guard is what stops the form
// appearing in an excerpt, a widget or somebody else's page.
foreach ( array( 'is_cart()', 'is_main_query()', 'in_the_loop()', 'is_empty()' ) as $recoveryflow_guard ) {
	ok(
		sprintf( 'the basket form checks %s before rendering', $recoveryflow_guard ),
		false !== strpos( $recoveryflow_append_src, $recoveryflow_guard )
	);
}

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
$recoveryflow_suppress = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Recovery/Suppressor.php', 'suppress' );

ok( 'the opt-out routine can be read', strlen( $recoveryflow_suppress ) > 50 );

// There must be exactly one of these. Three callers now say "stop messaging
// me" -- the unsubscribe link, a shopkeeper acting on a phone call, and the
// same act over REST -- and a second implementation is a second chance to
// forget one of the identities, which is the bug this code already shipped.
ok(
	'and the link handler delegates to it rather than keeping a copy',
	false !== strpos(
		kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Recovery/Recovery_Controller.php', 'suppress' ),
		'suppressor->suppress('
	)
);
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
$plugin->rest_integrations()->register_routes();
$plugin->rest_templates()->register_routes();

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

$recoveryflow_definition = Workflow_Form::read( $recoveryflow_posted, $plugin->steps() );

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
$recoveryflow_handoff                      = Workflow_Form::read( $recoveryflow_switched, $plugin->steps() );

ok( 'switching an action drops the old action\'s arguments', ! isset( $recoveryflow_handoff['steps'][2]['with']['template'] ) );
ok( 'and supplies the new one\'s default', 'primary' === $recoveryflow_handoff['steps'][2]['with']['hook'] );
ok( 'so the switched definition still validates', true === Workflow_Definition::validate( $recoveryflow_handoff ) );

// A step type nobody registered is dropped rather than written through.
$recoveryflow_junk                  = $recoveryflow_posted;
$recoveryflow_junk['step'][]        = array(
	'type' => 'exec',
	'do'   => 'rm -rf',
);
$recoveryflow_read                  = Workflow_Form::read( $recoveryflow_junk, $plugin->steps() );

check( 'a step type the plugin does not know is dropped, not stored', count( $recoveryflow_read['steps'] ), 3 );

// A channel nobody offers falls back rather than being written through.
$recoveryflow_junk                          = $recoveryflow_posted;
$recoveryflow_junk['step'][2]['channel']    = 'carrier-pigeon';
$recoveryflow_read                          = Workflow_Form::read( $recoveryflow_junk, $plugin->steps() );

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
 * Found by saving one on a real install: the refusal said "No WA.cr API key is
 * connected yet" and nothing else. True of the stored credential, and an answer
 * to a question nobody asked -- somebody who pressed Save on a workflow with a
 * send step in it has not been told what they did, what was refused, or what to
 * do instead. So the message must name all three.
 */
update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => false,
		'reason' => 'plan_upgrade_required',
	)
);

$recoveryflow_refusal = Workflow_Form::refusal();

ok( 'a refused save says a step of THIS workflow is what was refused', false !== strpos( $recoveryflow_refusal, 'sends a message from WordPress' ) );
ok( 'and says the workflow was not saved, rather than leaving it ambiguous', false !== strpos( $recoveryflow_refusal, 'was not saved' ) );
ok( 'and carries the reason the connection cannot do it', false !== strpos( $recoveryflow_refusal, Feature_Gate::unavailable_reason() ) );
ok( 'and offers the way forward that works on every plan', false !== strpos( $recoveryflow_refusal, 'Auto Flow' ) );
ok( 'and is more than the bare reason on its own', Feature_Gate::unavailable_reason() !== $recoveryflow_refusal );

/*
 * The branch that CHOOSES that message, not just the function that builds it.
 * Asserting only on the message let the whole refusal be swapped for the bare
 * reason with every test still green -- the same shape of gap that Setup's
 * plan-upgrade branch had, and the reason store() is free of redirects.
 */
$recoveryflow_outcome = $plugin->admin_workflow_form()->store( 2, $recoveryflow_definition, array( 'workflow_active' => '1' ) );

ok( 'saving a sending workflow below Scale is refused', ! $recoveryflow_outcome['ok'] );
check( 'and the refusal is the one that explains itself', $recoveryflow_outcome['message'], Workflow_Form::refusal() );
check( 'and the merchant is sent back to what they were editing', $recoveryflow_outcome['id'], 2 );

// A definition the validator refuses must be reported in the validator's own
// words, not in the gate's -- they are different problems with different fixes.
$recoveryflow_broken          = $recoveryflow_handoff;
$recoveryflow_broken['name']  = '';
$recoveryflow_outcome         = $plugin->admin_workflow_form()->store( 2, $recoveryflow_broken, array() );

ok( 'a workflow with no name is refused', ! $recoveryflow_outcome['ok'] );
ok( 'and is refused in the validator\'s words, not the gate\'s', Workflow_Form::refusal() !== $recoveryflow_outcome['message'] );
ok( 'which say what to do about it', false !== strpos( $recoveryflow_outcome['message'], 'name' ) );

/*
 * And the path where it works. Every branch above is a refusal, so without
 * this one the gate could stop being consulted altogether and the only thing
 * that changed would be a test going green -- which is what happened when this
 * was first written: the mutation that skipped the gate FATALED on an
 * unstubbed sanitize_title(), and a fatal reads as a failing suite, so it was
 * recorded as caught while nothing had exercised a successful save at all.
 */
update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => true,
		'scopes' => array( 'messages:send' ),
	)
);

$GLOBALS['wpdb']->rows['recoveryflow_workflows'] = array(
	$recoveryflow_row( 2, Workflow_Repository::SLUG_HANDOFF, $recoveryflow_seeded( 'handoff_definition' ), true ),
);

$recoveryflow_outcome = $plugin->admin_workflow_form()->store( 2, $recoveryflow_definition, array( 'workflow_active' => '1' ) );

ok( 'on Scale the same workflow saves', $recoveryflow_outcome['ok'] );
ok( 'and the merchant is told what saving did NOT do to journeys already running', false !== strpos( $recoveryflow_outcome['message'], 'version they started on' ) );
check( 'and lands back on the workflow they just saved, not on a blank new one', $recoveryflow_outcome['id'], 2 );

// A new workflow lands on the row it just created, which is the only way the
// merchant can see that it exists.
$recoveryflow_outcome = $plugin->admin_workflow_form()->store( 0, $recoveryflow_definition, array( 'workflow_active' => '1' ) );

ok( 'a brand new workflow saves', $recoveryflow_outcome['ok'] );
ok( 'and lands on the row it created rather than back on an empty form', $recoveryflow_outcome['id'] > 0 );

/*
 * The two switches, asserted on what reaches the database rather than on what
 * the screen drew. Unticking "Active" and being told the workflow saved, while
 * it goes on sending, is the worst failure this screen has available to it --
 * somebody deliberately stopping messages to their customers and being told
 * they had.
 */
$recoveryflow_insert = static function ( array $posted ) use ( $plugin, $recoveryflow_definition ): string {
	$GLOBALS['wpdb']->queries = array();

	$plugin->admin_workflow_form()->store( 0, $recoveryflow_definition, $posted );

	foreach ( $GLOBALS['wpdb']->queries as $recoveryflow_sql ) {
		if ( 0 === stripos( ltrim( (string) $recoveryflow_sql ), 'INSERT' ) && false !== strpos( (string) $recoveryflow_sql, 'recoveryflow_workflows' ) ) {
			return (string) $recoveryflow_sql;
		}
	}

	return '';
};

$recoveryflow_sql = $recoveryflow_insert( array( 'workflow_active' => '1' ) );

ok( 'saving writes a row to the workflows table', '' !== $recoveryflow_sql );
ok( 'ticking Active stores it as active', false !== strpos( $recoveryflow_sql, "'active'" ) );

$recoveryflow_sql = $recoveryflow_insert( array() );

ok( 'and unticking it stores a draft, which never runs', false !== strpos( $recoveryflow_sql, "'draft'" ) );
ok( 'rather than storing it active anyway', false === strpos( $recoveryflow_sql, "'active'" ) );

$GLOBALS['wpdb']->queries = array();

$GLOBALS['wpdb']->rows = array();

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
foreach ( array( 'type', 'if', 'do', 'else' ) as $recoveryflow_group ) {
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

/*
 * There is deliberately no channel select any more, and this is the assertion
 * that keeps it that way. It used to offer WhatsApp or email beside a WA.cr
 * template -- a choice that was never real: a template cannot arrive as email,
 * and whichever was picked a WhatsApp message went out and was billed as one.
 * The channel is a property of the action, so the editor states it instead of
 * asking. Putting the select back would reinstate the contradiction.
 */
check( 'the editor no longer offers a channel to choose beside the action', $recoveryflow_offered['channel'] ?? array(), array() );
ok( 'and states the channel instead, from the action that will run', false !== strpos( $recoveryflow_html, esc_html( Step_Describer::channel( Workflow_Definition::CHANNEL_WHATSAPP ) ) ) );

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
 * The template picker. The response shape asserted here is WA.cr's own, read
 * off apps/api/src/app/v1/templates/route.ts rather than guessed: `{ ok,
 * templates: [ { name, language, category, variables: [ { id, component, key,
 * index, required, sample?, before?, after? } ] } ] }`. The platform resolves
 * each template's blanks there deliberately, so that every client stops
 * reimplementing the placeholder scan and drifting from the others -- and the
 * `id` it hands back IS the slot key this plugin already stores.
 */
set_transient(
	'recoveryflow_wacr_templates',
	array(
		'waba'  => '',
		'value' => array(
			'ok'        => true,
			'templates' => array(
				array(
					'name'      => 'cart_reminder_1',
					'language'  => 'en',
					'category'  => 'MARKETING',
					'variables' => array(
						array(
							'id'       => 'body_1',
							'key'      => '1',
							'index'    => 1,
							'required' => true,
							'before'   => 'Hi',
							'after'    => ', your basket is waiting',
						),
						array(
							'id'       => 'button_0_url_1',
							'key'      => '1',
							'index'    => 1,
							'required' => true,
							'sample'   => 'abc123',
						),
					),
				),
				array(
					'name'      => 'seasonal_carousel',
					'language'  => 'en',
					'category'  => 'MARKETING',
					'variables' => array(
						array(
							'id'       => 'header_media_image',
							'key'      => 'image',
							'index'    => 1,
							'required' => true,
						),
						array(
							'id'       => 'card_0_body_1',
							'key'      => '1',
							'index'    => 1,
							'required' => true,
						),
					),
				),
				array(
					'name'      => 'plain_notice',
					'language'  => 'en',
					'category'  => 'UTILITY',
					'variables' => array(),
				),
			),
		),
	),
	900
);

$recoveryflow_catalog = $plugin->template_catalog()->all();

ok( 'the catalog reads WA.cr\'s answer', $recoveryflow_catalog['ok'] );
check( 'and lists every approved template, usable or not', count( $recoveryflow_catalog['templates'] ), 3 );

$recoveryflow_by_name = array();

foreach ( $recoveryflow_catalog['templates'] as $recoveryflow_template ) {
	$recoveryflow_by_name[ (string) $recoveryflow_template['name'] ] = $recoveryflow_template;
}

ok( 'a text-and-URL-button template can be used', $recoveryflow_by_name['cart_reminder_1']['usable'] );
check( 'and its blanks are the slots this plugin already stores', array_column( $recoveryflow_by_name['cart_reminder_1']['slots'], 'id' ), array( 'body_1', 'button_0_url_1' ) );
check( 'carrying the template\'s own wording, so the blank is recognisable', $recoveryflow_by_name['cart_reminder_1']['slots'][0]['before'], 'Hi' );

// The verdict that matters: a template needing a value this plugin cannot
// supply is listed and refused HERE, at the moment of choosing, rather than at
// send time -- hours later, in a log, against a customer who got nothing.
ok( 'a template needing an image header cannot be used', ! $recoveryflow_by_name['seasonal_carousel']['usable'] );
ok( 'and it is listed rather than hidden from somebody looking for it', isset( $recoveryflow_by_name['seasonal_carousel'] ) );
ok( 'and the refusal names the values it could not supply', false !== strpos( Template_Catalog::refusal( $recoveryflow_by_name['seasonal_carousel'] ), 'header_media_image' ) );
check( 'a usable template has nothing to refuse', Template_Catalog::refusal( $recoveryflow_by_name['cart_reminder_1'] ), '' );
ok( 'a template with no blanks is usable', $recoveryflow_by_name['plain_notice']['usable'] );

// The rule is the composer's, asked in one place, so the picker cannot offer
// what the composer will later refuse.
ok( 'the composer fills a body slot', Message_Composer::supports_slot( 'body_1' ) );
ok( 'and a header slot', Message_Composer::supports_slot( 'header_2' ) );
ok( 'and a URL button slot', Message_Composer::supports_slot( 'button_0_url_1' ) );
ok( 'but not an image header', ! Message_Composer::supports_slot( 'header_media_image' ) );
ok( 'nor a button payload', ! Message_Composer::supports_slot( 'button_1_payload' ) );
ok( 'nor a carousel card', ! Message_Composer::supports_slot( 'card_0_body_1' ) );
ok( 'nor a limited-time-offer expiry', ! Message_Composer::supports_slot( 'limited_time_offer_expiration' ) );
ok( 'and refuses a button index beyond the tenth', ! Message_Composer::supports_slot( 'button_10_url_1' ) );

// An optional slot this plugin cannot fill must not condemn the template:
// Meta marks the two decorative location-header slots optional, and refusing a
// template over a value nobody has to send hides one that works.
$recoveryflow_optional = $plugin->template_catalog();

set_transient(
	'recoveryflow_wacr_templates',
	array(
		'waba'  => '',
		'value' => array(
			'ok'        => true,
			'templates' => array(
				array(
					'name'      => 'located',
					'variables' => array(
						array(
							'id'       => 'header_location_name',
							'required' => false,
						),
						array(
							'id'       => 'body_1',
							'required' => true,
						),
					),
				),
			),
		),
	),
	900
);

ok( 'an OPTIONAL unsupported slot does not condemn a template', $recoveryflow_optional->all()['templates'][0]['usable'] );

// The picker as the merchant meets it: a list, the template's own wording
// beside each blank, and the variable choices from the allow-list.
set_transient(
	'recoveryflow_wacr_templates',
	array(
		'waba'  => '',
		'value' => array(
			'ok'        => true,
			'templates' => array(
				array(
					'name'      => 'cart_reminder_1',
					'variables' => array(
						array(
							'id'       => 'body_1',
							'required' => true,
							'before'   => 'Hi',
							'after'    => ', your basket is waiting',
						),
					),
				),
				array(
					'name'      => 'seasonal_carousel',
					'variables' => array(
						array(
							'id'       => 'header_media_image',
							'required' => true,
						),
					),
				),
			),
		),
	),
	900
);

$recoveryflow_snapshot_keep = get_option( Options::ME_SNAPSHOT, array() );

update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => true,
		'scopes' => array( 'messages:send', 'templates:read' ),
	)
);

$GLOBALS['wpdb']->rows['recoveryflow_workflows'] = array(
	$recoveryflow_row( 1, Workflow_Repository::SLUG_DIRECT, $recoveryflow_seeded( 'direct_definition' ), true ),
);

$_GET['workflow']    = 1;
$recoveryflow_picker = recoveryflow_render_screen( array( $plugin->admin_workflow(), 'render' ) );
unset( $_GET['workflow'] );

ok( 'the template is a list, not a box to type a name into', 1 === preg_match( '/<select name="step\[2\]\[template\]"/', $recoveryflow_picker ) );
ok( 'and offers the approved template', false !== strpos( $recoveryflow_picker, '>cart_reminder_1</option>' ) );
ok( 'and lists the one it cannot send, saying so rather than hiding it', false !== strpos( $recoveryflow_picker, esc_html( sprintf( __( '%s -- cannot be used', 'kdc-wacr-recoveryflow' ), 'seasonal_carousel' ) ) ) );
ok( 'and names each blank in the template\'s own words, not as body_1', false !== strpos( $recoveryflow_picker, esc_html( sprintf( __( '%1$s ____ %2$s', 'kdc-wacr-recoveryflow' ), 'Hi', ', your basket is waiting' ) ) ) );
ok( 'and offers a variable in plain language', false !== strpos( $recoveryflow_picker, esc_html__( 'The customer\'s first name', 'kdc-wacr-recoveryflow' ) ) );
ok( 'and keeps the stored value selected', false !== strpos( $recoveryflow_picker, 'value="{{customer.first_name}}" selected' ) );

/*
 * The seeded workflow's second send names cart_reminder_2, which this
 * workspace does not have. It must still appear, selected, and say why --
 * dropping it from the list would leave the select showing whatever sits at
 * the top, and the next save would silently send a different template to every
 * customer than the one the merchant last chose.
 */
ok(
	'a template no longer in the workspace is still shown, and marked',
	false !== strpos(
		$recoveryflow_picker,
		esc_html(
			sprintf(
				/* translators: %s: a message template name. */
				__( '%s -- no longer in your workspace', 'kdc-wacr-recoveryflow' ),
				'cart_reminder_2'
			)
		)
	)
);
ok( 'and stays selected rather than being swapped for the top of the list', false !== strpos( $recoveryflow_picker, 'value="cart_reminder_2" selected' ) );

// Every variable the picker offers must be one the renderer will substitute.
// A picker offering a variable that renders empty is a picker that quietly
// deletes half of somebody's message.
preg_match_all( '/name="step\[\d+\]\[variables\]\[[^\]]+\]".*?<\/select>/s', $recoveryflow_picker, $recoveryflow_varsel, PREG_SET_ORDER );

ok( 'the picker renders a control for each blank', count( $recoveryflow_varsel ) > 0 );

foreach ( $recoveryflow_varsel as $recoveryflow_one ) {
	preg_match_all( '/value="\{\{([^}]*)\}\}"/', $recoveryflow_one[0], $recoveryflow_keys );

	foreach ( $recoveryflow_keys[1] as $recoveryflow_key ) {
		ok( "the picker only offers {{{$recoveryflow_key}}}, which the renderer knows", in_array( $recoveryflow_key, Variable_Context::KEYS, true ) );
	}
}

// And the form reads only slots the composer can fill.
$recoveryflow_vars = Workflow_Form::read(
	array(
		'workflow_name' => 'Mapped',
		'step'          => array(
			array(
				'type'      => 'action',
				'do'        => 'wacr.send_template',
				'channel'   => 'whatsapp',
				'template'  => 'cart_reminder_1',
				'variables' => array(
					'body_1'                        => '{{customer.first_name}}',
					'button_0_url_1'                => '{{recovery.token}}',
					'header_media_image'            => 'https://example.test/x.png',
					'limited_time_offer_expiration' => '123',
					'body_2'                        => '',
				),
			),
		),
	),
	$plugin->steps()
);

$recoveryflow_slots = $recoveryflow_vars['steps'][0]['with']['variables'];

ok( 'the form keeps a body slot', isset( $recoveryflow_slots['body_1'] ) );
ok( 'and a URL button slot', isset( $recoveryflow_slots['button_0_url_1'] ) );
ok( 'and drops a slot the composer cannot fill', ! isset( $recoveryflow_slots['header_media_image'] ) );
ok( 'and another it cannot fill', ! isset( $recoveryflow_slots['limited_time_offer_expiration'] ) );
ok( 'and leaves an empty slot out rather than storing a gap', ! isset( $recoveryflow_slots['body_2'] ) );
ok( 'and what it builds is a definition the validator accepts', true === Workflow_Definition::validate( $recoveryflow_vars ) );

/*
 * And when WA.cr cannot be asked. Blocking here would be wrong: a credential
 * without templates:read, or a minute of no network, still leaves a merchant
 * in front of the screen who knows the name of their own template. What the
 * fallback must not do is pretend there was no list to have.
 */
delete_transient( 'recoveryflow_wacr_templates' );

$GLOBALS['__http_response'] = array(
	'response' => array( 'code' => 403 ),
	'body'     => '{"ok":false,"error":{"code":"insufficient_scope","message":"This key does not hold templates:read."}}',
	'headers'  => array(),
);

$_GET['workflow']      = 1;
$recoveryflow_fallback = recoveryflow_render_screen( array( $plugin->admin_workflow(), 'render' ) );
unset( $_GET['workflow'] );

ok( 'with no list available the template becomes a box to type into', 1 === preg_match( '/<input type="text"[^>]*name="step\[2\]\[template\]"/', $recoveryflow_fallback ) );
ok( 'and the merchant is told to type the name instead', false !== strpos( $recoveryflow_fallback, esc_html__( 'Type the name of an approved template instead. It is checked when the message is sent.', 'kdc-wacr-recoveryflow' ) ) );
ok( 'and it does not silently drop the template already chosen', false !== strpos( $recoveryflow_fallback, 'value="cart_reminder_1"' ) );

/*
 * Connected, asked, and the answer was none: a different state from "could not
 * ask", and one an empty select would render as a picker with nothing in it
 * and no way to tell whether the plugin or the workspace was at fault.
 */
$GLOBALS['__http_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"ok":true,"templates":[]}',
	'headers'  => array(),
);

delete_transient( 'recoveryflow_wacr_templates' );

$_GET['workflow']   = 1;
$recoveryflow_empty = recoveryflow_render_screen( array( $plugin->admin_workflow(), 'render' ) );
unset( $_GET['workflow'] );

ok( 'a workspace with no approved templates is told so', false !== strpos( $recoveryflow_empty, esc_html__( 'Your WA.cr workspace has no approved templates yet, so there is nothing to choose from. Approve one in the WA.cr console, or type its name here if you know it.', 'kdc-wacr-recoveryflow' ) ) );
ok( 'rather than being shown an empty list with no explanation', 1 !== preg_match( '/<select name="step\[2\]\[template\]"/', $recoveryflow_empty ) );

unset( $GLOBALS['__http_response'] );
delete_transient( 'recoveryflow_wacr_templates' );

update_option( Options::ME_SNAPSHOT, $recoveryflow_snapshot_keep );
$GLOBALS['wpdb']->rows = array();

/*
 * The Auto Flow recipe, and the one thing that can quietly make it a lie.
 *
 * The hand-off path works on every WA.cr plan, so most merchants take it -- and
 * everything that matters happens somewhere else, in a flow they build from
 * this list. WA.cr seeds every top-level scalar of a hook body as a run
 * variable named `hook_<key>`, so the documented keys ARE the variable names.
 * Add a key to the push without documenting it and merchants never learn it
 * exists; document one the push does not send and every flow that uses it
 * prints a placeholder at a customer. Both are silent, so both are asserted
 * against the real payload rather than against a copy.
 */
$recoveryflow_start_flow = new ReflectionMethod( Start_Flow::class, 'payload' );
$recoveryflow_start_flow->setAccessible( true );

$recoveryflow_documented = array_keys( Settings_Page::payload_keys() );

ok( 'the recipe documents some keys at all', count( $recoveryflow_documented ) > 0 );

$recoveryflow_source = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Workflow/Actions/Start_Flow.php', 'payload' );
$recoveryflow_sent   = array();

if ( 1 === preg_match( '/return array\((.*?)\n\t\t\);/s', $recoveryflow_source, $recoveryflow_body ) ) {
	preg_match_all( "/'([a-z_]+)'\s*=>/", $recoveryflow_body[1], $recoveryflow_found );
	$recoveryflow_sent = $recoveryflow_found[1];
}

ok( 'the real push payload can be read', count( $recoveryflow_sent ) > 0 );
check( 'and every key it sends is documented in the recipe', array_values( array_diff( $recoveryflow_sent, $recoveryflow_documented ) ), array() );
check( 'and the recipe documents nothing the push does not send', array_values( array_diff( $recoveryflow_documented, $recoveryflow_sent ) ), array() );

// The test push has to be shaped like the real one, or it proves very little.
$recoveryflow_test_keys = array_keys( Hook_Test::payload() );

check(
	'a test push carries every key a real one does',
	array_values( array_diff( $recoveryflow_sent, $recoveryflow_test_keys ) ),
	array()
);

/*
 * And it has to be harmless. A webhook trigger fires on anything that reaches
 * it -- there is no test mode to ask WA.cr for -- so a test push really does
 * run the merchant's flow. It must not be able to message a real person.
 */
check( 'a test push carries no phone number, so a flow that sends has nobody to send to', Hook_Test::payload()['phone'], '' );
ok( 'and names itself a test, so a flow can branch on it', Hook_Test::EVENT !== Start_Flow::EVENT );
ok( 'and says so in the payload as well', true === Hook_Test::payload()['test'] );

/*
 * ---------------------------------------------------------------------------
 * A 200 FROM THE AUTO FLOW HOOK IS NOT AN OUTCOME.
 *
 * WA.cr answers HTTP 200 with {ok:true, enrolled:false, reason:...} to three
 * states in which it ran nothing at all: feature_disabled, flow_not_active
 * (a draft or paused flow -- which is every workspace below the plan that can
 * activate one) and trigger_not_published. Only malformed, unresolvable,
 * unsigned and rate-limited pushes come back at 400 or above.
 *
 * The dispatcher read the status and nothing else, so on any such workspace it
 * marked every attempt sent and moved every journey to MESSAGE_SENT while not
 * one message existed. Six gates were green over that, and they still are --
 * the stub's default response is a bare 200, which is exactly the shape that
 * cannot see this.
 * ---------------------------------------------------------------------------
 */
$recoveryflow_hook_answer = static function ( array $body ): Result {
	return Result::success( $body, 200 );
};

check(
	'a flow that actually started is a send',
	Start_Flow::not_enrolled(
		$recoveryflow_hook_answer(
			array(
				'ok'       => true,
				'enrolled' => true,
			)
		)
	),
	null
);

foreach ( array( Flow_Status::FEATURE_DISABLED, Flow_Status::FLOW_NOT_ACTIVE, Flow_Status::TRIGGER_NOT_PUBLISHED ) as $recoveryflow_reason ) {
	check(
		"a 200 saying {$recoveryflow_reason} is not a send",
		Start_Flow::not_enrolled(
			$recoveryflow_hook_answer(
				array(
					'ok'       => true,
					'enrolled' => false,
					'reason'   => $recoveryflow_reason,
				)
			)
		),
		$recoveryflow_reason
	);
}

check(
	'and one that refuses without saying why still is not a send',
	Start_Flow::not_enrolled(
		$recoveryflow_hook_answer(
			array(
				'ok'       => true,
				'enrolled' => false,
			)
		)
	),
	'not_enrolled'
);

// The reason is somebody else's text on its way to a log line and a screen.
check(
	'a reason is reduced to a code before it is kept',
	Start_Flow::not_enrolled(
		$recoveryflow_hook_answer(
			array(
				'ok'       => true,
				'enrolled' => false,
				'reason'   => '<b>Flow Not Active</b>',
			)
		)
	),
	'bflownotactiveb'
);

/*
 * Absent is not false, and this direction matters as much as the other. An
 * endpoint that does not speak this contract at all would otherwise have every
 * successful hand-off deferred and pushed again an hour later -- turning a fix
 * for silent under-sending into duplicate messages at real customers.
 */
check(
	'a body with no enrolled key is left alone',
	Start_Flow::not_enrolled( $recoveryflow_hook_answer( array( 'ok' => true ) ) ),
	null
);
check(
	'and so is an empty body, which is what every older deployment answers',
	Start_Flow::not_enrolled( $recoveryflow_hook_answer( array() ) ),
	null
);

// What the merchant is told. Each reason has to name the thing to go and do:
// "flow_not_active" is a code from somebody else's system.
foreach ( array( Flow_Status::FEATURE_DISABLED, Flow_Status::FLOW_NOT_ACTIVE, Flow_Status::TRIGGER_NOT_PUBLISHED, 'something_new' ) as $recoveryflow_reason ) {
	$recoveryflow_said = Flow_Status::label( $recoveryflow_reason );

	ok( "a merchant reading about {$recoveryflow_reason} is told nothing was sent", false !== strpos( $recoveryflow_said, 'Nothing has been sent' ) );
	ok( "and that the reminders are waiting rather than lost ({$recoveryflow_reason})", false !== strpos( $recoveryflow_said, 'waiting rather than lost' ) );
}

ok(
	'the plan requirement is named, because no amount of clicking fixes a plan',
	false !== strpos( Flow_Status::label( Flow_Status::FEATURE_DISABLED ), 'Growth' )
);

// The store the background pass writes and the screen reads.
delete_option( Flow_Status::OPTION );
check( 'nothing is remembered until something is refused', Flow_Status::refusal(), array() );

Flow_Status::refused( Flow_Status::FLOW_NOT_ACTIVE );
check( 'a refusal is remembered', Flow_Status::refusal()['reason'], Flow_Status::FLOW_NOT_ACTIVE );

Flow_Status::enrolled();
check( 'and a flow that starts again clears it, so a fixed problem stops being reported', Flow_Status::refusal(), array() );

/*
 * And the half that matters: the dispatcher has to ACT on that answer. Asserting
 * not_enrolled() alone would repeat the mistake that let the per-step channel
 * survive four slices -- a helper that returns the right value, tested, while
 * nothing in the execution path calls it. So the real push() is driven, with a
 * real 200 on the wire, and the outcome is read off the far side.
 */
$recoveryflow_flow_action = new Start_Flow(
	$plugin->wacr(),
	$plugin->credentials(),
	$plugin->attempts(),
	$plugin->journeys(),
	$plugin->send_gate(),
	$plugin->rate_budget(),
	$plugin->logger(),
	$plugin->clock()
);

$recoveryflow_push = new ReflectionMethod( Start_Flow::class, 'push' );
$recoveryflow_push->setAccessible( true );

$recoveryflow_hand_off = static function ( array $body ) use ( $recoveryflow_flow_action, $recoveryflow_push ): Step_Outcome {
	$GLOBALS['__http_response'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => (string) wp_json_encode( $body ),
		'headers'  => array(),
	);

	$journey = Recovery_Journey::from_row(
		array(
			'id'               => 7700,
			'journey_uid'      => 'rec-7700-enrolment',
			'status'           => Journey_State::SCHEDULED,
			'customer_id'      => 9900,
			'event_id'         => 8800,
			'workflow_id'      => 3300,
			'workflow_version' => 1,
			'current_step'     => 0,
			'source_id'        => 'woocommerce',
		)
	);

	return $recoveryflow_push->invoke(
		$recoveryflow_flow_action,
		$journey,
		array(
			'claim_token' => 'claim-for-the-enrolment-test',
			'step_index'  => 0,
		),
		Attempt::from_row(
			array(
				'id'         => 6600,
				'journey_id' => 7700,
				'step_index' => 0,
				'attempt_no' => 1,
				'status'     => Attempt::SENDING,
			)
		),
		Customer::from_row(
			array(
				'id'         => 9900,
				'first_name' => 'Ada',
			)
		),
		Recovery_Event::from_row(
			array(
				'id'       => 8800,
				'status'   => Recovery_Event::OPEN,
				'currency' => 'GBP',
				'amount'   => '25.0000',
			)
		),
		'plaintext-token-for-the-enrolment-test'
	);
};

delete_option( Flow_Status::OPTION );

// There has to be somewhere to push to, or every run below stops at no_hook
// and the whole block asserts nothing about enrolment.
$recoveryflow_settings_before_flow = (array) get_option( Options::SETTINGS, array() );

update_option(
	Options::SETTINGS,
	array_replace( Options::defaults(), array( 'wacr_hook_url' => 'https://api.wa.cr/automations/hooks/tok_enrolment_test' ) )
);

$recoveryflow_took_it = $recoveryflow_hand_off(
	array(
		'ok'       => true,
		'enrolled' => true,
	)
);
check( 'a hand-off the flow took up is recorded as sent', $recoveryflow_took_it->status, Step_Outcome::SENT );
check( 'and says it was handed off', $recoveryflow_took_it->reason, 'handed_off' );
check( 'and leaves nothing for the Connection screen to complain about', Flow_Status::refusal(), array() );

/*
 * The bug itself. Before this, the line below returned SENT/handed_off and the
 * journey moved to MESSAGE_SENT -- on every free, trial and starter workspace,
 * every enterprise workspace, and every workspace whose flow was merely paused.
 */
$recoveryflow_dropped = $recoveryflow_hand_off(
	array(
		'ok'       => true,
		'enrolled' => false,
		'reason'   => Flow_Status::FLOW_NOT_ACTIVE,
	)
);

ok( 'a 200 that ran nothing is NOT recorded as sent', Step_Outcome::SENT !== $recoveryflow_dropped->status );
check( 'it waits instead', $recoveryflow_dropped->status, Step_Outcome::WAITING );
check( 'carrying the reason WA.cr gave', $recoveryflow_dropped->reason, Flow_Status::FLOW_NOT_ACTIVE );
check( 'and the Connection screen is told, because a background pass has no reader', Flow_Status::refusal()['reason'], Flow_Status::FLOW_NOT_ACTIVE );

// And it recovers: the merchant activates the flow, the next push is taken up,
// and the warning goes away on its own.
$recoveryflow_recovered = $recoveryflow_hand_off(
	array(
		'ok'       => true,
		'enrolled' => true,
	)
);
check( 'activating the flow lets the next one through', $recoveryflow_recovered->status, Step_Outcome::SENT );
check( 'and clears the warning', Flow_Status::refusal(), array() );

/*
 * And it has to reach a person. A deferral inside a background pass has no
 * reader at all: the merchant's evidence is a queue that never sends and a
 * status screen that says everything is fine.
 */
$recoveryflow_flow_check = static function () use ( $plugin ): array {
	foreach ( $plugin->health()->connection_checks() as $recoveryflow_row ) {
		if ( 'wacr_flow' === $recoveryflow_row['id'] ) {
			return $recoveryflow_row;
		}
	}

	return array();
};

check( 'a working flow puts nothing on the Connection screen', $recoveryflow_flow_check(), array() );

Flow_Status::refused( Flow_Status::FEATURE_DISABLED );
$recoveryflow_shown = $recoveryflow_flow_check();

ok( 'a refused hand-off does', array() !== $recoveryflow_shown );
check( 'and is an error rather than a note, because nothing is being sent', $recoveryflow_shown['severity'], 'error' );
ok( 'and names the plan the hook needs', false !== strpos( (string) $recoveryflow_shown['message'], 'Growth' ) );

Flow_Status::enrolled();
check( 'and it goes away once the flow runs again', $recoveryflow_flow_check(), array() );

unset( $GLOBALS['__http_response'] );
update_option( Options::SETTINGS, $recoveryflow_settings_before_flow );

unset( $GLOBALS['__http_response'] );
ok( 'and the button warns that the flow really runs', false !== strpos( recoveryflow_render_screen( array( Hook_Test::class, 'button' ) ), esc_html__( 'This really runs your Auto Flow, because a webhook trigger fires on anything that reaches it. The test carries no phone number, so a flow that goes on to send has nobody to send to.', 'kdc-wacr-recoveryflow' ) ) );

// Without a hook address there is nothing to test, and saying so beats a
// request that fails for a reason the merchant has to work out.
ok( 'with no hook saved the test says what is missing rather than failing obscurely', false !== strpos( (string) $plugin->admin_hook_test()->push()['message'], 'no Auto Flow hook address saved' ) );

/*
 * Carrying an opt-out into the merchant's WA.cr workspace. Off by default
 * because it writes to their workspace and changes who their OTHER campaigns
 * reach -- that is a decision, not a default.
 */
$recoveryflow_snapshot_sync = get_option( Options::ME_SNAPSHOT, array() );
$recoveryflow_settings_sync = get_option( Options::SETTINGS, array() );

ok( 'carrying opt-outs to WA.cr is off until somebody turns it on', ! (bool) Options::get( Opt_Out_Sync::SETTING, false ) );

// Both halves are required, and they fail differently: the setting is the
// merchant's decision, the scope is whether their credential can act on it.
// Reporting the sync as on while every attempt is refused would be worse than
// reporting it off.
update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => true,
		'scopes' => array( 'messages:send' ),
	)
);
Options::set( Opt_Out_Sync::SETTING, true );

ok( 'switched on but without contacts:write, the sync is not enabled', ! Opt_Out_Sync::is_enabled() );

update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => true,
		'scopes' => array( 'messages:send', Opt_Out_Sync::SCOPE ),
	)
);

ok( 'with the setting and the scope, it is', Opt_Out_Sync::is_enabled() );

Options::set( Opt_Out_Sync::SETTING, false );

ok( 'and the scope alone is not enough either', ! Opt_Out_Sync::is_enabled() );

// What gets queued. A cron argument lives in wp_options in the clear, so it
// carries the internal row id and never the phone number -- the same rule the
// rest of the plugin follows for logs.
$GLOBALS['__single_events'] = array();

Opt_Out_Sync::queue( 4242 );

check( 'a disabled sync queues nothing at all', count( $GLOBALS['__single_events'] ), 0 );

Options::set( Opt_Out_Sync::SETTING, true );
Opt_Out_Sync::queue( 4242 );

check( 'an enabled one queues exactly one job', count( $GLOBALS['__single_events'] ), 1 );
check( 'against the sync action', $GLOBALS['__single_events'][0]['hook'], Opt_Out_Sync::ACTION );
check( 'carrying the internal customer id', $GLOBALS['__single_events'][0]['args'], array( 4242 ) );

foreach ( $GLOBALS['__single_events'][0]['args'] as $recoveryflow_arg ) {
	ok( 'and nothing that looks like a phone number', 1 !== preg_match( '/\+?\d{7,}/', (string) $recoveryflow_arg ) );
}

$GLOBALS['__single_events'] = array();
Opt_Out_Sync::queue( 0 );

check( 'and a customer that does not exist queues nothing', count( $GLOBALS['__single_events'] ), 0 );

// The job re-checks before acting. A merchant who switches this off, or whose
// key loses the scope, between the opt-out and the job running should not have
// the change made anyway.
Options::set( Opt_Out_Sync::SETTING, false );
$GLOBALS['wpdb']->queries = array();

$plugin->opt_out_sync()->run( 4242 );

check( 'a job that runs after the setting was switched off does nothing', count( $GLOBALS['wpdb']->queries ), 0 );

/*
 * And the order it happens in. "Stop messaging me" is recorded here first;
 * whether it also reaches WA.cr is a setting and a network call, and neither
 * may stand between a customer and being left alone.
 */
$recoveryflow_suppress = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Recovery/Suppressor.php', 'suppress' );

ok( 'the opt-out path queues the sync', false !== strpos( $recoveryflow_suppress, 'Opt_Out_Sync::queue' ) );

/*
 * Both halves have to be PRESENT before their order means anything. strpos()
 * answers false for "not found", and false < 40 is true in PHP -- so this
 * assertion used to pass when the local suppression had been deleted outright,
 * which is the one arrangement it exists to forbid. A mutation survived and
 * said so.
 */
$recoveryflow_local_at = strpos( $recoveryflow_suppress, '$this->consent->suppress(' );
$recoveryflow_sync_at  = strpos( $recoveryflow_suppress, 'Opt_Out_Sync::queue' );

ok( 'the local suppression is recorded at all', false !== $recoveryflow_local_at );
ok(
	'and only after the local suppression is already recorded',
	false !== $recoveryflow_local_at && false !== $recoveryflow_sync_at && $recoveryflow_local_at < $recoveryflow_sync_at
);

update_option( Options::ME_SNAPSHOT, $recoveryflow_snapshot_sync );
update_option( Options::SETTINGS, $recoveryflow_settings_sync );

/*
 * The diagnostic report. Its whole design constraint is what is NOT in it: it
 * gets pasted into email, chat and public forums by people with no way to audit
 * what they are sending. So the secrets are planted first and the report is
 * searched for them -- asserting on what it contains would never catch a leak.
 */
$recoveryflow_diag_key      = 'wacr_live_abcdef0123456789abcdef0123456789';
$recoveryflow_diag_hook     = 'https://api.wa.cr/hooks/vJx8Kq2mNp4RtY7wZa1BcD3eF6gH9iJk';
$recoveryflow_diag_secret   = 'f47ac10b58cc4372a5670e02b2c3d479f47ac10b58cc4372a5670e02b2c3d479';
$recoveryflow_diag_snapshot = get_option( Options::ME_SNAPSHOT, array() );

$plugin->credentials()->set_api_key( $recoveryflow_diag_key );
$plugin->credentials()->set_hook_secret( $recoveryflow_diag_secret );
Options::set( 'wacr_hook_url', $recoveryflow_diag_hook );

update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'          => true,
		'tenant_name' => 'A Test Shop',
		'scopes'      => array( 'messages:send', 'templates:read' ),
		'checked_at'  => '2026-09-08 10:00:00',
	)
);

$recoveryflow_report = $plugin->admin_diagnostics()->report();

ok( 'the report says something at all', strlen( $recoveryflow_report ) > 200 );

// Each secret, by name, so a failure says which one leaked.
$recoveryflow_secrets = array(
	'the API key'          => $recoveryflow_diag_key,
	'the hook address'     => $recoveryflow_diag_hook,
	'the hook signing key' => $recoveryflow_diag_secret,
);

foreach ( $recoveryflow_secrets as $recoveryflow_what => $recoveryflow_secret ) {
	ok( "the report does not contain {$recoveryflow_what}", false === strpos( $recoveryflow_report, $recoveryflow_secret ) );
}

// Not even a piece of one. A report that leaks the last eight characters of a
// key has still leaked part of a key, and "it was masked" is how that gets
// argued for.
ok( 'nor the tail of the API key', false === strpos( $recoveryflow_report, substr( $recoveryflow_diag_key, -8 ) ) );
ok( 'nor the token out of the hook address', false === strpos( $recoveryflow_report, 'vJx8Kq2mNp4RtY7wZa1BcD3eF6gH9iJk' ) );
ok( 'and does not state how long the key is', 1 !== preg_match( '/\b39\b|\bkey length\b/i', $recoveryflow_report ) );

// What it SHOULD say, so the whole thing is not vacuously safe by being empty.
ok( 'it says whether a key is saved', false !== strpos( $recoveryflow_report, 'API key saved: yes' ) );
ok( 'and whether the hook is set, without giving the address', false !== strpos( $recoveryflow_report, 'Hook address set: yes' ) );
ok( 'and whether pushes are signed, without giving the secret', false !== strpos( $recoveryflow_report, 'Hook signed: yes' ) );
ok( 'and names the workspace, which identifies no customer', false !== strpos( $recoveryflow_report, 'A Test Shop' ) );
ok( 'and states the versions somebody would ask for', false !== strpos( $recoveryflow_report, 'PHP: ' ) && false !== strpos( $recoveryflow_report, 'WordPress: ' ) );
ok( 'and reports each background pass', false !== strpos( $recoveryflow_report, 'processed' ) );
ok( 'and says plainly what it left out', false !== strpos( $recoveryflow_report, 'No API key, hook address, customer details or log entries are included.' ) );

/*
 * The allow-list is the guarantee, so it has to actually be one: a setting
 * added later must be absent until somebody decides it belongs. Reading it back
 * off the class and checking every name is a real setting keeps the list from
 * rotting into a list of names that no longer mean anything.
 */
$recoveryflow_safe = new ReflectionMethod( Diagnostics::class, 'safe_settings' );
$recoveryflow_safe->setAccessible( true );

$recoveryflow_allowed = (array) $recoveryflow_safe->invoke( null );

ok( 'the allow-list names some settings', count( $recoveryflow_allowed ) > 0 );

foreach ( $recoveryflow_allowed as $recoveryflow_name ) {
	ok( "the report's allow-list entry {$recoveryflow_name} is a real setting", array_key_exists( $recoveryflow_name, Options::defaults() ) );
}

// And the two credentials must never be on it, however the list is edited.
foreach ( array( 'wacr_hook_url', 'api_key' ) as $recoveryflow_never ) {
	ok( "the allow-list never admits {$recoveryflow_never}", ! in_array( $recoveryflow_never, $recoveryflow_allowed, true ) );
}

/*
 * The second line of defence, actually exercised. The allow-list keeps our own
 * secrets out, so nothing normally reaches the Redactor and a test that only
 * checks the allow-list would let the Redactor pass be deleted without noticing.
 *
 * These are the two ways somebody else's text gets into this report: a stage's
 * last error, which can quote whatever a failed send was carrying, and the
 * workspace name, which is typed by the merchant in WA.cr and arrives here
 * verbatim. Both are given something that must not survive.
 */
$recoveryflow_stats_before = get_option( Options::STAGE_STATS, array() );

update_option(
	Options::STAGE_STATS,
	array(
		'dispatch' => array(
			'stage'      => 'dispatch',
			'processed'  => 3,
			'failed'     => 1,
			'backlog'    => 0,
			'duration'   => 12,
			'ran_at'     => '2026-09-08 09:00:00',
			'last_error' => 'send to +447700900123 failed for asha@example.test',
		),
	),
	false
);

update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'          => true,
		'tenant_name' => 'Shop wacr_live_deadbeefdeadbeefdeadbeefdeadbeef',
		'scopes'      => array( 'messages:send' ),
		'checked_at'  => '2026-09-08 10:00:00',
	)
);

$recoveryflow_report = $plugin->admin_diagnostics()->report();

ok( 'a failed stage does not put its error text in the report', false === strpos( $recoveryflow_report, 'failed for' ) );
ok( 'so a phone number quoted in an error cannot reach it', false === strpos( $recoveryflow_report, '+447700900123' ) );
ok( 'nor an email address', false === strpos( $recoveryflow_report, 'asha@example.test' ) );
ok( 'but it still says that the last run errored', false !== strpos( $recoveryflow_report, 'last run errored: yes' ) );

/*
 * The bug this test found. stage_report() read the RAW stored option -- an
 * array of arrays -- while treating each entry as an object, so a stage that
 * had never run was reported correctly from the fallback object and a stage
 * that HAD run answered null to every property. The status screen therefore
 * showed zeroes for exactly the stages that had done some work, which is the
 * opposite of what a status screen is for, and said nothing while doing it.
 */
$recoveryflow_stages = $plugin->health()->stage_report();
$recoveryflow_by_stage = array();

foreach ( $recoveryflow_stages as $recoveryflow_stage ) {
	$recoveryflow_by_stage[ (string) $recoveryflow_stage['stage'] ] = $recoveryflow_stage;
}

check( 'a stage that has run reports what it processed, not zero', $recoveryflow_by_stage['dispatch']['processed'], 3 );
check( 'and what failed', $recoveryflow_by_stage['dispatch']['failed'], 1 );
check( 'and when it ran', $recoveryflow_by_stage['dispatch']['ran_at'], '2026-09-08 09:00:00' );
check( 'while a stage that never ran still reports zero', $recoveryflow_by_stage['expire']['processed'], 0 );

// And a key-shaped value arriving from somebody else's system is masked by the
// Redactor even though it came through a field this report includes on purpose.
ok( 'a key-shaped workspace name is masked rather than printed', false === strpos( $recoveryflow_report, 'wacr_live_deadbeefdeadbeefdeadbeefdeadbeef' ) );

update_option( Options::STAGE_STATS, $recoveryflow_stats_before );
$plugin->credentials()->set_api_key( '' );
Options::set( 'wacr_hook_url', '' );
update_option( Options::ME_SNAPSHOT, $recoveryflow_diag_snapshot );

/*
 * Honouring an opt-out recorded in WA.cr. The whole check was already built --
 * cached, scope-gated, failing open -- and wired into the send decision. What
 * was missing was that NOTHING READ THE SETTING offering it, so the control
 * said one thing and the plugin did another whatever anybody chose. It went
 * unnoticed because none of this had a test at all.
 *
 * Wiring it up with its original default of false would have been the obvious
 * fix and a bad one: every site already running would have stopped honouring
 * WA.cr opt-outs on upgrade, and somebody who replied STOP in WhatsApp would
 * have started receiving cart reminders again. So the default is now true,
 * which is exactly what every install has been doing.
 */
ok( 'honouring a WA.cr opt-out is on unless somebody turns it off', (bool) Options::get( 'wacr_sync_optout', false ) );

$recoveryflow_gate_settings = get_option( Options::SETTINGS, array() );
$recoveryflow_gate_snapshot = get_option( Options::ME_SNAPSHOT, array() );

/*
 * The key is saved BEFORE the snapshot, not after. Storing a different key
 * retires what was learned about the old one -- that is the fix from the
 * previous slice working -- so setting the key second would wipe the scopes
 * this test depends on and every assertion below would pass for the wrong
 * reason. Without a key the lookup fails before it starts and the gate
 * correctly allows, which looks identical to the opt-out check being absent.
 */
$plugin->credentials()->set_api_key( 'wacr_live_0123456789abcdef0123456789abcdef' );

update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => true,
		'scopes' => array( 'messages:send', 'contacts:read' ),
	)
);

// Quiet hours are checked before this and would defer every send, hiding the
// decision under test behind an unrelated one.
Options::set( 'quiet_hours_enabled', false );

$recoveryflow_gate_journey                 = new Recovery_Journey();
$recoveryflow_gate_journey->status         = Journey_State::SCHEDULED;
$recoveryflow_gate_journey->attempts_count = 0;

$recoveryflow_gate_customer             = new Customer();
$recoveryflow_gate_customer->id         = 77;
$recoveryflow_gate_customer->phone_e164 = '+447700900123';
$recoveryflow_gate_customer->phone_hash   = str_repeat( 'a', 64 );
$recoveryflow_gate_customer->phone_status = Customer::PHONE_VALID;

/**
 * Ask the gate, with WA.cr answering whatever this test wants.
 *
 * @param bool $opted_out What the contact record says.
 * @return array<string,mixed>
 */
function recoveryflow_gate_says( bool $opted_out ): array {
	global $plugin, $recoveryflow_gate_journey, $recoveryflow_gate_customer;

	delete_transient( 'recoveryflow_wacr_optout_' . substr( str_repeat( 'a', 64 ), 0, 32 ) );

	$GLOBALS['__http_response'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode(
			array(
				'contacts' => array(
					array(
						'id'        => 'c_1',
						'phoneE164' => '+447700900123',
						'optedOut'  => $opted_out,
					),
				),
			)
		),
		'headers'  => array(),
	);

	return $plugin->send_gate()->check( $recoveryflow_gate_journey, $recoveryflow_gate_customer, Rule_Set::for_source() );
}

Options::set( 'wacr_sync_optout', true );

check( 'with the setting on, a contact opted out in WA.cr is not messaged', recoveryflow_gate_says( true )['decision'], Send_Gate::SKIP );
check( 'and the reason names WA.cr, so a support call is not a mystery', recoveryflow_gate_says( true )['reason'], Send_Gate::REASON_WACR_OPTOUT );
check( 'while a contact who has not opted out is messaged', recoveryflow_gate_says( false )['decision'], Send_Gate::ALLOW );

/*
 * The half that had no test and therefore no defence. With the setting off the
 * gate must not consult WA.cr at all -- not consult it and ignore the answer,
 * which would still spend a request per send on something the merchant
 * switched off.
 */
Options::set( 'wacr_sync_optout', false );

delete_transient( 'recoveryflow_wacr_optout_' . substr( str_repeat( 'a', 64 ), 0, 32 ) );
$GLOBALS['__http_requests'] = 0;

$recoveryflow_gate_result = recoveryflow_gate_says( true );

check( 'with the setting off, an opted-out contact IS messaged, as chosen', $recoveryflow_gate_result['decision'], Send_Gate::ALLOW );

Options::set( 'wacr_sync_optout', true );

/*
 * A failed lookup allows the send. The plugin's own consent ledger is the
 * authority and has already said yes; letting a WA.cr outage stop every
 * recovery on a site would be a worse failure than acting on a stale flag.
 */
delete_transient( 'recoveryflow_wacr_optout_' . substr( str_repeat( 'a', 64 ), 0, 32 ) );

$GLOBALS['__http_response'] = array(
	'response' => array( 'code' => 500 ),
	'body'     => '{"ok":false}',
	'headers'  => array(),
);

check( 'a WA.cr outage does not stop every recovery on the site', $plugin->send_gate()->check( $recoveryflow_gate_journey, $recoveryflow_gate_customer, Rule_Set::for_source() )['decision'], Send_Gate::ALLOW );

// And without the scope there is nothing to ask with, so it does not ask.
update_option(
	Options::ME_SNAPSHOT,
	array(
		'ok'     => true,
		'scopes' => array( 'messages:send' ),
	)
);
delete_transient( 'recoveryflow_wacr_optout_' . substr( str_repeat( 'a', 64 ), 0, 32 ) );

check( 'without contacts:read the gate does not pretend to check', recoveryflow_gate_says( true )['decision'], Send_Gate::ALLOW );

unset( $GLOBALS['__http_response'] );
$plugin->credentials()->set_api_key( '' );
update_option( Options::SETTINGS, $recoveryflow_gate_settings );
update_option( Options::ME_SNAPSHOT, $recoveryflow_gate_snapshot );

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


require_once __DIR__ . '/fixtures/pollable-source.php';

/*
 * ---------------------------------------------------------------------------
 * Integrations: a screen that cannot be confidently wrong.
 *
 * Source_Registry::active() excluded a source the plan does not include, while
 * the Integrations screen worked its answer out from the other two facts and
 * announced "Active. Abandoned baskets from here are being recorded." about an
 * integration whose hooks were never attached. Both sentences were true of what
 * each one read. This is the third time in this plugin that a screen and a
 * behaviour answered the same question separately and disagreed.
 * ---------------------------------------------------------------------------
 */

update_option( Options::SETTINGS, Options::defaults() );

check( 'a source nobody registered has no status but "unavailable"', $plugin->sources()->status( 'nope' ), Source_Registry::UNAVAILABLE );

/**
 * How many times the Integrations screen says a thing.
 *
 * Counted rather than merely looked for: WooCommerce is registered and absent
 * in this run, so "Not available." is already on the screen before a test
 * source is added, and a plain strpos would pass on somebody else's card.
 *
 * @param string $phrase Words from one of the status sentences.
 * @return int
 */
function recoveryflow_integration_says( string $phrase ): int {
	return substr_count( recoveryflow_render_screen( array( Plugin::instance()->admin_integrations(), 'render' ) ), $phrase );
}

$recoveryflow_phrases = array(
	'unavailable' => 'Not available.',
	'off'         => 'switched off here',
	'plan'        => 'not included in this WA.cr plan',
	'active'      => 'Abandoned baskets from here are being recorded',
);

$recoveryflow_before = array_map( 'recoveryflow_integration_says', $recoveryflow_phrases );

// The entitlement, made switchable so all four states can actually be rendered
// rather than read out of the source of the method that words them.
$GLOBALS['recoveryflow_extra_sources'] = false;

add_filter(
	Hooks::FILTER_FEATURE_ENABLED,
	static fn ( bool $on, string $feature ): bool => Feature_Gate::EXTRA_SOURCES === $feature
		? (bool) $GLOBALS['recoveryflow_extra_sources']
		: $on,
	10,
	2
);

$recoveryflow_state_source = new Recoveryflow_Fake_Pollable( $plugin->ingest(), 'statecheck' );
$plugin->sources()->add( $recoveryflow_state_source );

// A switch that is on must RENDER as on. Its default cannot live in
// Options::defaults(), which knows nothing about a source somebody else's
// plugin registered, so the field declares it -- and a renderer that ignored
// that would draw an unticked box whose first save turns the source off for
// real. Asserted before anything has been saved, because a stored value would
// answer for the default and the gap would go unseen.
ok(
	'a switch nobody has saved yet still renders ticked',
	false !== strpos(
		recoveryflow_render_settings( 'sources', Source_Registry::enabled_key( 'statecheck' ) ),
		'name="' . Options::SETTINGS . '[' . Source_Registry::enabled_key( 'statecheck' ) . ']" value="1" checked'
	)
);

$recoveryflow_state_source->available = false;
check( 'a source whose dependency is missing is described as unavailable', recoveryflow_integration_says( $recoveryflow_phrases['unavailable'] ), $recoveryflow_before['unavailable'] + 1 );

$recoveryflow_state_source->available = true;
$plugin->sources()->set_enabled( 'statecheck', false );
check( 'a source somebody switched off says so', recoveryflow_integration_says( $recoveryflow_phrases['off'] ), $recoveryflow_before['off'] + 1 );

// The bug this section exists for: installed, switched on, excluded by the
// plan, and the screen used to call that "Active".
$plugin->sources()->set_enabled( 'statecheck', true );
check( 'a source the plan does not include says that, in those words', recoveryflow_integration_says( $recoveryflow_phrases['plan'] ), $recoveryflow_before['plan'] + 1 );
check( 'and is not described as recording anything', recoveryflow_integration_says( $recoveryflow_phrases['active'] ), $recoveryflow_before['active'] );
check( 'and its hooks are not attached', array_key_exists( 'statecheck', $plugin->sources()->active() ), false );

$GLOBALS['recoveryflow_extra_sources'] = true;
check( 'and once the plan includes it, it is recording', recoveryflow_integration_says( $recoveryflow_phrases['active'] ), $recoveryflow_before['active'] + 1 );
check( 'and its hooks are attached', array_key_exists( 'statecheck', $plugin->sources()->active() ), true );

// The switch. enabled_sources was read by the registry from the first slice and
// written by nothing: a merchant could not switch an integration off, and the
// only reason nobody noticed is that the only integration was the one they had
// installed the plugin for.
ok( 'a source with no stored preference is on', $plugin->sources()->is_enabled( 'statecheck' ) );
check( 'and its switch is a field on the Integrations tab', Settings_Schema::field( Source_Registry::enabled_key( 'statecheck' ) )['tab'] ?? '', 'sources' );

$recoveryflow_after = recoveryflow_save_tab( 'sources', array( Source_Registry::enabled_key( 'statecheck' ) => '' ) );

ok( 'unticking it switches the source off', ! $plugin->sources()->is_enabled( 'statecheck' ) );
check( 'and the source is no longer active', $plugin->sources()->status( 'statecheck' ), Source_Registry::SWITCHED_OFF );
ok( 'and saving that tab did not disturb another', 30 === (int) ( $recoveryflow_after['inactivity_minutes'] ?? 0 ) );

$plugin->sources()->set_enabled( 'statecheck', true );
ok( 'and it can be switched back on', $plugin->sources()->is_enabled( 'statecheck' ) );

// An adapter's own fields are namespaced, because the settings live in one
// option and two form plugins would both reach for "phone_field".
ok(
	'an adapter field is stored under a key of the adapter\'s own',
	'source_statecheck_phone_field' === Source_Registry::setting_key( 'statecheck', 'phone_field' )
);

$GLOBALS['recoveryflow_extra_sources'] = true;

/*
 * ---------------------------------------------------------------------------
 * THE CONSENT NOTE. docs/integrations.md and the Gravity Forms adapter both
 * said the consent constraint was "stated on the Integrations screen rather
 * than left to be discovered". It was stated nowhere, and the cost was not
 * cosmetic: on a site in the default mode that source identified people and
 * then never messaged one of them, with no screen anywhere saying why.
 * ---------------------------------------------------------------------------
 */
// Counted as a delta, not an absolute: the built-in sources answer this too,
// so a bare count would pass on somebody else's card. Same trap the status
// phrases above are counted around.
$recoveryflow_state_source->note = '';
$recoveryflow_notes_before       = recoveryflow_integration_says( 'Consent:' );

ok( 'the built-in sources already answer, so the screen is never silent about consent', $recoveryflow_notes_before > 0 );

$recoveryflow_state_source->note = 'Put a consent question on the form.';
check( 'a source that needs consent arranged says so on its card', recoveryflow_integration_says( 'Consent:' ), $recoveryflow_notes_before + 1 );
check( 'in its own words, not a generic sentence', recoveryflow_integration_says( 'Put a consent question on the form.' ), 1 );

// Advice about a rule that is switched off is its own kind of wrong.
update_option( Options::SETTINGS, array_replace( Options::defaults(), array( 'eligibility_mode' => 'identified_contact' ) ) );
check( 'and no source says anything when the site does not require consent at all', recoveryflow_integration_says( 'Consent:' ), 0 );
update_option( Options::SETTINGS, Options::defaults() );
check( 'and they say it again once consent is required', recoveryflow_integration_says( 'Consent:' ), $recoveryflow_notes_before + 1 );

$recoveryflow_state_source->note = '';
check( 'a source with nothing to say adds no note of its own', recoveryflow_integration_says( 'Consent:' ), $recoveryflow_notes_before );

// The two built-in sources both answer, because a card that is silent about
// consent reads the same whether consent is handled or forgotten.
ok(
	'the shop adapter says consent is already taken care of',
	'' !== trim( $plugin->sources()->get( 'woocommerce' )->consent_note() )
);

// What a source is CALLED, asked in one place. A recovery screen printing a
// raw source id was telling a shop worker "gravityforms".
check( 'a registered source is named, not slugged', $plugin->sources()->name_for( 'statecheck' ), 'Fake pollable' );
check(
	'and one whose plugin has gone keeps its id, because the journeys outlive it',
	$plugin->sources()->name_for( 'vanished' ),
	'vanished'
);

$GLOBALS['recoveryflow_extra_sources'] = false;
update_option( Options::SETTINGS, Options::defaults() );


/*
 * ---------------------------------------------------------------------------
 * Pollable sources.
 *
 * Pollable_Source_Interface shipped in slice 1 and nothing ever called
 * detect_recovery_events(). The interface, the Event_Batch value object and a
 * paragraph of documentation all described a feature that did not exist, and
 * no gate could tell -- an interface nobody implements is green in every
 * static check there is. These assertions exist to make the wiring itself the
 * thing under test.
 * ---------------------------------------------------------------------------
 */

/**
 * Build a draft that will actually be written, so a poll can be counted.
 */
function recoveryflow_poll_draft( string $source_id, string $key ): Event_Draft {
	$draft = new Event_Draft( $source_id, 'booking', $key );

	return $draft->with_value( '49.00', 'GBP' )->with_items(
		array(
			array(
				'name' => 'A slot',
				'qty'  => 1,
			),
		)
	);
}

function recoveryflow_run_evaluate( Plugin $plugin ): Stage_Stats {
	$evaluate = new Evaluate(
		$plugin->events(),
		$plugin->journeys(),
		$plugin->customers(),
		$plugin->eligibility(),
		$plugin->sources(),
		$plugin->workflows(),
		$plugin->ingest(),
		$plugin->source_cursors(),
		$plugin->clock(),
		$plugin->logger()
	);

	return $evaluate->run( new Time_Budget( 30.0 ) );
}

$recoveryflow_pollable = new Recoveryflow_Fake_Pollable( $plugin->ingest() );
$plugin->sources()->add( $recoveryflow_pollable );

// A source beyond the built-in set is a paid feature, and the gate is asked
// before a single row is read: an entitlement that only hid the card while the
// integration went on working would be no gate at all.
$recoveryflow_pollable->pages = array( new Event_Batch( array( recoveryflow_poll_draft( 'fake_pollable', 'b:1' ) ), 'cur-1', false ) );
recoveryflow_run_evaluate( $plugin );
check( 'a source the plan does not include is never even asked', $recoveryflow_pollable->asked, array() );

add_filter(
	Hooks::FILTER_FEATURE_ENABLED,
	static fn ( bool $on, string $feature ): bool => Feature_Gate::EXTRA_SOURCES === $feature ? true : $on,
	10,
	2
);

$recoveryflow_stats = recoveryflow_run_evaluate( $plugin );

ok( 'a pollable source is asked what has appeared since it last looked', array( null ) === $recoveryflow_pollable->asked );
check( 'and what it hands back is ingested', $recoveryflow_stats->processed, 1 );
check( 'and where it stopped is remembered for the next run', $plugin->source_cursors()->get( 'fake_pollable' ), 'cur-1' );

// The cursor is the only reason a hundred thousand unread rows drain instead
// of being rescanned from the top on every tick.
$recoveryflow_pollable->asked = array();
$recoveryflow_pollable->pages = array( new Event_Batch( array(), 'cur-2', true ) );
$recoveryflow_stats           = recoveryflow_run_evaluate( $plugin );

check( 'the next run resumes from the stored cursor', $recoveryflow_pollable->asked, array( 'cur-1' ) );
ok( 'and a source that says there is more sets the backlog flag', $recoveryflow_stats->backlog > 0 );

// (source_id, dedupe_key) is UNIQUE, so a draft filed under somebody else's id
// would upsert onto their row -- one integration silently closing another's
// events.
$recoveryflow_pollable->pages = array(
	new Event_Batch( array( recoveryflow_poll_draft( 'woocommerce', 'someone-elses-cart' ) ), null, false ),
);
$recoveryflow_stats = recoveryflow_run_evaluate( $plugin );

check( 'a draft filed under another source is dropped', $recoveryflow_stats->processed, 0 );
check( 'and a finished source forgets its place', $plugin->source_cursors()->get( 'fake_pollable' ), null );

// One broken integration must not stop the others, and must not lose its place.
$plugin->source_cursors()->set( 'fake_pollable', 'cur-keep' );
$recoveryflow_pollable->explode = true;
$recoveryflow_second            = new Recoveryflow_Fake_Pollable( $plugin->ingest(), 'fake_pollable_2' );
$recoveryflow_second->pages     = array( new Event_Batch( array( recoveryflow_poll_draft( 'fake_pollable_2', 'b:2' ) ), null, false ) );
$plugin->sources()->add( $recoveryflow_second );

$recoveryflow_stats = recoveryflow_run_evaluate( $plugin );

check( 'a source that throws keeps its place rather than starting again', $plugin->source_cursors()->get( 'fake_pollable' ), 'cur-keep' );
check( 'and the sources after it are still polled', $recoveryflow_stats->processed, 1 );

// A poll may go over the network, and this stage shares one budget with the
// four that follow it. A guard that is never exercised is a guard that is not
// there: the budget is emptied by reflection because Time_Budget is final and
// measures real elapsed time, and a test cannot wait for twenty seconds.
$recoveryflow_pollable->explode = false;
$recoveryflow_pollable->asked   = array();
$recoveryflow_second->asked     = array();
$recoveryflow_second->pages     = array( new Event_Batch( array( recoveryflow_poll_draft( 'fake_pollable_2', 'b:3' ) ), null, false ) );

$recoveryflow_spent = new Time_Budget( 30.0 );
$recoveryflow_started = new ReflectionProperty( Time_Budget::class, 'started_at' );
$recoveryflow_started->setAccessible( true );
$recoveryflow_started->setValue( $recoveryflow_spent, microtime( true ) - 100.0 );

$recoveryflow_evaluate = new Evaluate(
	$plugin->events(),
	$plugin->journeys(),
	$plugin->customers(),
	$plugin->eligibility(),
	$plugin->sources(),
	$plugin->workflows(),
	$plugin->ingest(),
	$plugin->source_cursors(),
	$plugin->clock(),
	$plugin->logger()
);
$recoveryflow_stats = $recoveryflow_evaluate->run( $recoveryflow_spent );

check( 'a run with no time left asks nobody', $recoveryflow_pollable->asked, array() );
check( 'not even the source after it', $recoveryflow_second->asked, array() );
ok( 'and it says there is more to do, so the next tick comes back for them', $recoveryflow_stats->backlog > 0 );


/*
 * ---------------------------------------------------------------------------
 * Gravity Forms: the adapter that has to prove the abstraction was one.
 *
 * WooCommerce came first, so every seam in the core was cut where WooCommerce
 * needed one. What is under test here is whether those seams were general: a
 * second adapter recovering two things that are nothing like a basket, through
 * a plugin with no session, no cart and no orders.
 *
 * Loading the fixture is what makes class_exists( '\GFAPI' ) true, so from here
 * on this site has Gravity Forms installed.
 * ---------------------------------------------------------------------------
 */

require_once __DIR__ . '/fixtures/gravity-forms.php';

$recoveryflow_gf     = $plugin->sources()->get( Gf_Source::ID );
$recoveryflow_fields = new Gf_Field_Map();

ok( 'the Gravity Forms source ships registered', $recoveryflow_gf instanceof Gf_Source );
ok( 'and is available now that Gravity Forms is', $recoveryflow_gf->is_available() );
ok( 'and it is pollable, because hooks only ever tell you about the future', $recoveryflow_gf instanceof Pollable_Source_Interface );
check( 'and it produces two kinds of thing, neither of them a basket', $recoveryflow_gf->get_event_types(), array( 'form', 'payment' ) );

// The core must not have learned anything about forms. If a second adapter
// needed the engine changed, the abstraction was a description of WooCommerce.
$recoveryflow_leaks = array();
$recoveryflow_walk  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src' ) );

foreach ( $recoveryflow_walk as $recoveryflow_file ) {
	$recoveryflow_path = str_replace( dirname( __DIR__ ) . '/', '', (string) $recoveryflow_file );

	if ( 'php' !== pathinfo( $recoveryflow_path, PATHINFO_EXTENSION ) ) {
		continue;
	}

	// The adapter itself, obviously, and the container that registers it.
	if ( 0 === strpos( $recoveryflow_path, 'src/Integration/' ) || 'src/Core/Plugin.php' === $recoveryflow_path ) {
		continue;
	}

	if ( false !== stripos( (string) file_get_contents( (string) $recoveryflow_file ), 'gravity' ) ) {
		$recoveryflow_leaks[] = $recoveryflow_path;
	}
}

sort( $recoveryflow_leaks );

check( 'nothing outside the adapter has heard of Gravity Forms', $recoveryflow_leaks, array() );

/*
 * Identity. WooCommerce has one billing phone field with one name for ever;
 * Gravity Forms has whatever the merchant dragged onto the canvas, so the
 * form's own field TYPES are what is read.
 */
$recoveryflow_form = array(
	'id'     => 7,
	'title'  => 'Membership application',
	'fields' => array(
		(object) array(
			'id'   => 1,
			'type' => 'text',
		),
		(object) array(
			'id'   => 2,
			'type' => 'name',
		),
		(object) array(
			'id'   => 3,
			'type' => 'email',
		),
		(object) array(
			'id'   => 4,
			'type' => 'phone',
		),
		(object) array(
			'id'   => 9,
			'type' => 'phone',
		),
	),
);

$recoveryflow_entry = array(
	'id'             => 55,
	'form_id'        => 7,
	'date_created'   => '2026-09-01 10:00:00',
	'status'         => 'active',
	'payment_status' => 'Failed',
	'payment_amount' => '49.00',
	'currency'       => 'GBP',
	'source_url'     => 'https://shop.test/join/?first_name=Ada&email=ada%40example.test',
	'2.3'            => 'Ada',
	'2.6'            => 'Lovelace',
	'3'              => 'ada@example.test',
	'4'              => '07700 900123',
	'9'              => '02079460000',
);

$recoveryflow_hints = $recoveryflow_fields->hints( $recoveryflow_form, $recoveryflow_entry );

check( 'the first field of a type wins, so a work number does not beat the mobile', $recoveryflow_hints->phone_raw, '07700 900123' );
check( 'the email comes off the email field, whatever it is labelled', $recoveryflow_hints->email, 'ada@example.test' );
check( 'a name field is read as its two halves', $recoveryflow_hints->first_name . '/' . $recoveryflow_hints->last_name, 'Ada/Lovelace' );

// A merchant with two phone fields knows which one is the mobile; nobody else
// does, so it is a filter rather than a screen.
add_filter( 'recoveryflow_gf_field_overrides', static fn (): array => array( 'phone' => '9' ) );
check( 'and a pinned field overrides that', $recoveryflow_fields->hints( $recoveryflow_form, $recoveryflow_entry, Gf_Settings::field_overrides( $recoveryflow_form ) )->phone_raw, '02079460000' );
$GLOBALS['__filters']['recoveryflow_gf_field_overrides'] = array();

// Gravity Forms hands fields over as objects in most contexts and as plain
// arrays in a few. Reading them one way works until the day it does not.
$recoveryflow_array_form = array(
	'id'     => 8,
	'fields' => array(
		array(
			'id'   => 3,
			'type' => 'email',
		),
	),
);
check( 'a form whose fields are arrays reads the same as one of objects', $recoveryflow_fields->hints( $recoveryflow_array_form, array( '3' => 'grace@example.test' ) )->email, 'grace@example.test' );

// A form nobody could be messaged from is not an integration failure; it is a
// row the eligibility rules would spend the rest of their life refusing.
$recoveryflow_mute_form = array(
	'id'     => 9,
	'fields' => array(
		array(
			'id'   => 1,
			'type' => 'text',
		),
	),
);
ok( 'a form with a phone or an email field can produce somebody to message', $recoveryflow_fields->is_messageable( $recoveryflow_form ) );
ok( 'a form with neither cannot, and is refused before anything is written', ! $recoveryflow_fields->is_messageable( $recoveryflow_mute_form ) );

/*
 * Asking the FORM and asking the ENTRY are different questions with different
 * answers, and the gap between them is where somebody unreachable gets written
 * down. Gravity Forms drops a phone value whose E.164 form it cannot validate
 * -- which is what happens every time a person types a national number into an
 * International (formatted) field -- so a contactable form routinely produces
 * an entry with nothing on it.
 */
ok(
	'an entry that actually carries a contact detail is messageable',
	$recoveryflow_fields->has_contact( $recoveryflow_fields->hints( $recoveryflow_form, $recoveryflow_entry ) )
);
ok(
	'but a form full of contact FIELDS whose entry answered none of them is not',
	! $recoveryflow_fields->has_contact(
		$recoveryflow_fields->hints(
			$recoveryflow_form,
			array(
				'id'      => 56,
				'form_id' => 7,
			)
		)
	)
);
ok(
	'an email on its own is enough, because email is a channel',
	$recoveryflow_fields->has_contact(
		$recoveryflow_fields->hints(
			$recoveryflow_form,
			array(
				'id'      => 56,
				'form_id' => 7,
				'3'       => 'ada@example.test',
			)
		)
	)
);
ok(
	'and so is a number on its own',
	$recoveryflow_fields->has_contact(
		$recoveryflow_fields->hints(
			$recoveryflow_form,
			array(
				'id'      => 56,
				'form_id' => 7,
				'4'       => '07700 900123',
			)
		)
	)
);

// The refusal has to reach the thing that writes rows, not just the helper.
ok(
	'an unpaid entry with no contact detail on it is not chased',
	null === Gf_Unpaid_Entry::draft(
		array(
			'id'             => 58,
			'form_id'        => 7,
			'status'         => 'active',
			'payment_status' => 'Failed',
			'date_created'   => '2026-09-01 10:00:00',
		),
		$recoveryflow_form,
		$recoveryflow_fields,
		'hook'
	)
);

/*
 * And the save-and-continue path, which had no test of any kind -- the clearest
 * abandonment signal the plugin gets, and nothing asserted it wrote a row.
 * Driven through the real hook signature Gravity Forms calls it with.
 */
$recoveryflow_drafts_watcher = new Gf_Draft_Watcher( $plugin->ingest(), $recoveryflow_fields, $plugin->logger() );

/**
 * Whether saving a draft wrote a recovery event.
 *
 * Read off the recorded SQL rather than a return value, because on_saved()
 * returns nothing: a version that quietly did nothing at all would otherwise
 * look exactly like a version that worked. The event upsert is a raw INSERT
 * rather than $wpdb->insert(), so it lands in queries and not in writes --
 * looking in the wrong one reports every call as a no-op and turns all three
 * of these assertions green while measuring nothing.
 *
 * @param Gf_Draft_Watcher    $watcher The watcher.
 * @param array<string,mixed> $form    The form.
 * @param array<string,mixed> $entry   The partial entry.
 * @return bool
 */
$recoveryflow_saved_a_draft = static function ( Gf_Draft_Watcher $watcher, array $form, array $entry ): bool {
	static $token = 0;

	++$token;
	$GLOBALS['wpdb']->queries = array();

	$watcher->on_saved( array( 'partial_entry' => $entry ), 'tok' . $token, $form, $entry );

	foreach ( $GLOBALS['wpdb']->queries as $sql ) {
		if ( 0 === stripos( ltrim( (string) $sql ), 'INSERT' ) && false !== strpos( (string) $sql, 'recoveryflow_events' ) ) {
			return true;
		}
	}

	return false;
};

ok(
	'saving a half-finished form records it',
	$recoveryflow_saved_a_draft( $recoveryflow_drafts_watcher, $recoveryflow_form, $recoveryflow_entry )
);
ok(
	'but one whose contact fields came back empty is not recorded, because nobody could be reached',
	! $recoveryflow_saved_a_draft(
		$recoveryflow_drafts_watcher,
		$recoveryflow_form,
		array(
			'id'      => 59,
			'form_id' => 7,
		)
	)
);
ok(
	'and neither is a form that never asked for a way to reach anybody',
	! $recoveryflow_saved_a_draft(
		$recoveryflow_drafts_watcher,
		$recoveryflow_mute_form,
		array(
			'id'      => 60,
			'form_id' => 9,
		)
	)
);

/*
 * Consent. Gravity Forms has no checkout and RecoveryFlow adds no field of its
 * own to anybody's form, so the merchant's own consent question is the only
 * place a yes can come from -- and for a whole release nothing read one, which
 * meant this source could not produce a single journey on a site that requires
 * consent.
 */
$recoveryflow_consent_form = array(
	'id'     => 11,
	'fields' => array(
		(object) array(
			'id'   => 3,
			'type' => 'email',
		),
		(object) array(
			'id'   => 5,
			'type' => 'consent',
		),
	),
);

$recoveryflow_consent_hints = $recoveryflow_fields->hints(
	$recoveryflow_consent_form,
	array(
		'id'      => 60,
		'form_id' => 11,
		'3'       => 'ada@example.test',
		'5.1'     => '1',
		'5.3'     => '4',
	)
);
check( 'a ticked consent field is read as a yes', $recoveryflow_consent_hints->consent, true );
check( 'and the form revision its wording came from is kept, so an old yes stays auditable', $recoveryflow_consent_hints->consent_text_version, '4' );
check( 'recorded against the form rather than a checkout', $recoveryflow_consent_hints->consent_source, Gf_Field_Map::CONSENT_SOURCE );

$recoveryflow_consent_hints = $recoveryflow_fields->hints(
	$recoveryflow_consent_form,
	array(
		'id'      => 61,
		'form_id' => 11,
		'3'       => 'ada@example.test',
		'5.1'     => '0',
	)
);
check( 'an unticked one is read as a no, not as silence', $recoveryflow_consent_hints->consent, false );

// The distinction the whole thing turns on: a form that never asked is not a
// person who declined, and writing down the second would be inventing a refusal.
check(
	'a form with no consent question says nothing either way',
	$recoveryflow_fields->hints( $recoveryflow_form, $recoveryflow_entry )->consent,
	null
);

/*
 * Which payment statuses mean the money is in. Getting this wrong is expensive
 * in both directions, so it is a list rather than "anything that is not
 * Failed" -- a negative rule would call every status a future add-on invents a
 * completed sale, and the recovery would never happen.
 */
foreach ( array( 'Paid', 'Active', 'Approved', 'Authorized' ) as $recoveryflow_status ) {
	ok( "{$recoveryflow_status} counts as paid", Gf_Unpaid_Entry::is_paid( $recoveryflow_status ) );
}

foreach ( array( 'Failed', 'Cancelled', 'Pending', 'Processing', 'Refunded', 'Voided', 'Expired', 'Something_New' ) as $recoveryflow_status ) {
	ok( "{$recoveryflow_status} does not", ! Gf_Unpaid_Entry::is_paid( $recoveryflow_status ) );
}

/*
 * One rule, one home. The submission hook and the backfill both ask
 * Unpaid_Entry, because written twice they would differ -- and the way they
 * would differ is a backfill chasing exactly the people the live path had
 * decided to leave alone.
 */
$recoveryflow_draft = Gf_Unpaid_Entry::draft( $recoveryflow_entry, $recoveryflow_form, $recoveryflow_fields, 'hook' );

ok( 'an unpaid entry is worth recovering', $recoveryflow_draft instanceof Event_Draft );
check( 'and it is filed under this adapter', $recoveryflow_draft->source_id, Gf_Source::ID );
check( 'with one row per entry', $recoveryflow_draft->dedupe_key, 'entry:55' );
check( 'carrying what it was worth', $recoveryflow_draft->amount . ' ' . $recoveryflow_draft->currency, '49.00 GBP' );

// The privacy rule this adapter could most easily have broken: a form's prefill
// parameters are exactly where somebody's name and email address end up, and
// metadata is contractually free of personal data.
check( 'and a link with the query string stripped off it', $recoveryflow_draft->metadata['resume_url'], 'https://shop.test/join/' );
ok( 'so no personal data reaches the metadata', false === stripos( (string) wp_json_encode( $recoveryflow_draft->metadata ), 'ada' ) );

// Named for the LAST sighting rather than the first, because ingest is an
// upsert: the backfill rewrites this key on a row the live hook wrote first,
// so a field called found_by was wrong within one pass of the queue.
check( 'the row records how it was last seen', $recoveryflow_draft->metadata['seen_by'], 'hook' );
check(
	'and the backfill says so when it is the one that saw it',
	Gf_Unpaid_Entry::draft( $recoveryflow_entry, $recoveryflow_form, $recoveryflow_fields, 'poll' )->metadata['seen_by'],
	'poll'
);

foreach ( array(
	'a paid entry'             => array( 'payment_status' => 'Paid' ),
	'an entry asking no money' => array( 'payment_status' => '' ),
	'a spam entry'             => array( 'status' => 'spam' ),
	'a trashed entry'          => array( 'status' => 'trash' ),
) as $recoveryflow_label => $recoveryflow_patch ) {
	ok(
		"{$recoveryflow_label} is not chased",
		null === Gf_Unpaid_Entry::draft( array_replace( $recoveryflow_entry, $recoveryflow_patch ), $recoveryflow_form, $recoveryflow_fields, 'hook' )
	);
}

ok(
	'and neither is an entry on a form nobody could be messaged from',
	null === Gf_Unpaid_Entry::draft( array_replace( $recoveryflow_entry, array( 'form_id' => 9 ) ), $recoveryflow_mute_form, $recoveryflow_fields, 'hook' )
);

// Only these forms.
$plugin->sources()->set_enabled( Gf_Source::ID, true );
Options::set( Source_Registry::setting_key( Gf_Source::ID, Gf_Settings::FORMS ), '3, 12' );
ok( 'a form the merchant did not list is left alone', null === Gf_Unpaid_Entry::draft( $recoveryflow_entry, $recoveryflow_form, $recoveryflow_fields, 'hook' ) );
Options::set( Source_Registry::setting_key( Gf_Source::ID, Gf_Settings::FORMS ), '3, 7, 12' );
ok( 'and one they did is not', null !== Gf_Unpaid_Entry::draft( $recoveryflow_entry, $recoveryflow_form, $recoveryflow_fields, 'hook' ) );
Options::set( Source_Registry::setting_key( Gf_Source::ID, Gf_Settings::FORMS ), '' );

Options::set( Source_Registry::setting_key( Gf_Source::ID, Gf_Settings::UNPAID ), false );
ok( 'and switching unpaid entries off stops all of it', null === Gf_Unpaid_Entry::draft( $recoveryflow_entry, $recoveryflow_form, $recoveryflow_fields, 'hook' ) );
Options::set( Source_Registry::setting_key( Gf_Source::ID, Gf_Settings::UNPAID ), true );

/*
 * Completion, re-asked from live state immediately before every send. Both
 * kinds fail closed, and they have to: the alternative is guessing about
 * whether to message somebody.
 */
GFAPI::$entries    = array( 55 => $recoveryflow_entry );
GFAPI::$forms      = array( 7 => $recoveryflow_form );
GFFormsModel::$drafts = array( 'tok123' => array( 'partial_entry' => array() ) );

$recoveryflow_open_entry = Recovery_Event::from_row(
	array(
		'id'          => 1,
		'source_id'   => Gf_Source::ID,
		'source_type' => 'payment',
		'external_id' => '55',
		'status'      => Recovery_Event::OPEN,
	)
);

ok( 'an entry still waiting for money is not complete', ! $recoveryflow_gf->is_conversion_complete( $recoveryflow_open_entry ) );

GFAPI::$entries[55]['payment_status'] = 'Paid';
ok( 'and once it is paid, it is', $recoveryflow_gf->is_conversion_complete( $recoveryflow_open_entry ) );

GFAPI::$entries[55]['payment_status'] = 'Failed';
GFAPI::$entries[55]['status']         = 'trash';
ok( 'an entry the shop trashed is treated as finished rather than chased', $recoveryflow_gf->is_conversion_complete( $recoveryflow_open_entry ) );

GFAPI::$entries = array();
ok( 'an entry that has been deleted is finished, not retried for ever', $recoveryflow_gf->is_conversion_complete( $recoveryflow_open_entry ) );

$recoveryflow_open_draft = Recovery_Event::from_row(
	array(
		'id'          => 2,
		'source_id'   => Gf_Source::ID,
		'source_type' => 'form',
		'external_id' => 'tok123',
		'status'      => Recovery_Event::OPEN,
	)
);

ok( 'a draft that is still there is not complete', ! $recoveryflow_gf->is_conversion_complete( $recoveryflow_open_draft ) );

GFFormsModel::$drafts = array();
ok( 'and one Gravity Forms has purged or consumed is', $recoveryflow_gf->is_conversion_complete( $recoveryflow_open_draft ) );

/*
 * The backfill, which is the reason this adapter is pollable at all. A merchant
 * installing RecoveryFlow onto a site with four hundred unpaid entries gets
 * nothing from any of them: the submissions happened and nothing was listening.
 */
GFAPI::$entries = array(
	55 => $recoveryflow_entry,
	56 => array_replace(
		$recoveryflow_entry,
		array(
			'id'             => 56,
			'date_created'   => '2026-09-02 10:00:00',
			'payment_status' => 'Paid',
		)
	),
	57 => array_replace(
		$recoveryflow_entry,
		array(
			'id'           => 57,
			'date_created' => '2026-09-03 10:00:00',
		)
	),
);
GFAPI::$asked = array();

$recoveryflow_batch = $recoveryflow_gf->detect_recovery_events( 10, null );

check( 'the backfill finds the unpaid entries', count( $recoveryflow_batch->drafts ), 2 );
check( 'and skips the paid one, exactly as the live path would', array_map( static fn ( Event_Draft $d ): string => $d->dedupe_key, $recoveryflow_batch->drafts ), array( 'entry:55', 'entry:57' ) );
check( 'and stops at the newest row it read', $recoveryflow_batch->cursor, '2026-09-03 10:00:00' );
ok( 'and does not claim there is more when it read a short page', ! $recoveryflow_batch->has_more );
check( 'and it asked only for active entries', GFAPI::$asked[0]['search']['status'], 'active' );
check( 'oldest first, or a cursor would step over rows for ever', GFAPI::$asked[0]['sort']['direction'], 'ASC' );

$recoveryflow_batch = $recoveryflow_gf->detect_recovery_events( 1, null );
ok( 'a full page says there is more to read', $recoveryflow_batch->has_more );

$recoveryflow_batch = $recoveryflow_gf->detect_recovery_events( 10, '2026-09-03 00:00:00' );
check( 'and a cursor resumes rather than starting again', count( $recoveryflow_batch->drafts ), 1 );

// Nothing new must not clear the cursor, or the next run reads the whole window
// a second time, for ever.
$recoveryflow_batch = $recoveryflow_gf->detect_recovery_events( 10, '2027-01-01 00:00:00' );
check( 'an empty page keeps its place', $recoveryflow_batch->cursor, '2027-01-01 00:00:00' );

GFAPI::$fail        = true;
$recoveryflow_batch = $recoveryflow_gf->detect_recovery_events( 10, '2026-09-01 00:00:00' );
check( 'and Gravity Forms refusing to answer costs nothing but the run', count( $recoveryflow_batch->drafts ), 0 );
GFAPI::$fail = false;

/*
 * Restore. Nothing is rebuilt: Gravity Forms holds the half-finished form
 * itself and hands it back on its own resume link, which is a far better
 * arrangement than this plugin reconstructing somebody's answers from a
 * snapshot it took.
 */
check(
	'a draft is sent back on Gravity Forms\' own resume link',
	Gf_Source::resume_url( array( 'source_url' => 'https://shop.test/join/?utm_source=email' ), 'tok123' ),
	'https://shop.test/join/?gf_token=tok123'
);

$recoveryflow_restored = Recovery_Event::from_row(
	array(
		'id'            => 3,
		'source_id'     => Gf_Source::ID,
		'source_type'   => 'form',
		'external_id'   => 'tok123',
		'status'        => Recovery_Event::OPEN,
		'metadata_json' => (string) wp_json_encode( array( 'resume_url' => 'https://shop.test/join/?gf_token=tok123' ) ),
	)
);

check( 'and the link stored on the event is where they land', $recoveryflow_gf->restore( new Recovery_Journey(), $recoveryflow_restored ), 'https://shop.test/join/?gf_token=tok123' );

$recoveryflow_lost = Recovery_Event::from_row(
	array(
		'id'          => 4,
		'source_id'   => Gf_Source::ID,
		'source_type' => 'form',
		'external_id' => 'tok999',
		'status'      => Recovery_Event::OPEN,
	)
);

ok( 'an event with no link left shows the generic page rather than guessing', $recoveryflow_gf->restore( new Recovery_Journey(), $recoveryflow_lost ) instanceof WP_Error );

update_option( Options::SETTINGS, Options::defaults() );


/*
 * ---------------------------------------------------------------------------
 * The documented custom source.
 *
 * The example in docs/developer-api.md called a constructor with the wrong
 * signature and two methods that had never existed, and said so confidently
 * for three slices, because an example in a Markdown file is checked by
 * nothing. It is now a real file that phpcs lints, PHPStan type-checks and
 * these assertions instantiate -- and the document quotes it.
 * ---------------------------------------------------------------------------
 */

$recoveryflow_example = new Example_Source( $plugin->ingest() );

ok( 'the documented example is a source', $recoveryflow_example instanceof Recovery_Source_Interface );
ok( 'and it is never registered, because it recovers a plugin that does not exist', null === $plugin->sources()->get( $recoveryflow_example->get_id() ) );
ok( 'and it is unavailable on any real site', ! $recoveryflow_example->is_available() );

// Fail closed: an adapter that cannot tell whether somebody has paid must not
// be the reason they are asked to pay twice.
ok(
	'an example that cannot reach its own plugin reports the thing as finished',
	$recoveryflow_example->is_conversion_complete(
		Recovery_Event::from_row(
			array(
				'id'          => 9,
				'source_id'   => 'mybookings',
				'external_id' => '1',
				'status'      => Recovery_Event::OPEN,
			)
		)
	)
);

ok(
	'and refuses to guess at a link rather than sending somebody somewhere wrong',
	$recoveryflow_example->restore(
		new Recovery_Journey(),
		Recovery_Event::from_row(
			array(
				'id'          => 9,
				'external_id' => '1',
			)
		)
	) instanceof WP_Error
);

// The gate that stops the document rotting again: every method the printed
// example calls on itself must actually exist. This is what nothing was
// checking before.
$recoveryflow_doc = (string) file_get_contents( dirname( __DIR__ ) . '/docs/developer-api.md' );

preg_match_all( '/\$this->([a-z_]+)\(/', $recoveryflow_doc, $recoveryflow_calls );

$recoveryflow_missing = array();

foreach ( array_unique( $recoveryflow_calls[1] ) as $recoveryflow_method ) {
	if ( ! method_exists( Example_Source::class, $recoveryflow_method ) ) {
		$recoveryflow_missing[] = $recoveryflow_method;
	}
}

sort( $recoveryflow_missing );

check( 'every method the developer documentation calls exists', $recoveryflow_missing, array() );
ok( 'and the documentation is calling some, so the check is not vacuous', count( $recoveryflow_calls[1] ) > 2 );

// The two helpers the example leans on, which is the whole reason an adapter
// author never touches Event_Ingest directly.
foreach ( array( 'report', 'report_completed' ) as $recoveryflow_helper ) {
	ok( "Abstract_Source gives an adapter {$recoveryflow_helper}()", method_exists( Abstract_Source::class, $recoveryflow_helper ) );
}

ok( 'and an adapter can be built inside the registration hook without the container', $plugin->sources()->ingest() instanceof Event_Ingest );


/*
 * ---------------------------------------------------------------------------
 * Claiming a batch, in two statements.
 *
 * Splitting one UPDATE ... ORDER BY ... LIMIT into a SELECT and an UPDATE is
 * only safe because the UPDATE re-checks the lease: two runs may select the
 * same rows, and the second must then claim none of them. Losing that re-check
 * would leave a plan that looks better, a suite that stays green, and two
 * background runs sending the same customer the same reminder.
 * ---------------------------------------------------------------------------
 */

$GLOBALS['wpdb']->queries    = array();
$GLOBALS['wpdb']->rows['recoveryflow_journeys'] = array( array( 'id' => 41 ), array( 'id' => 42 ) );

$plugin->journeys()->claim_due( 'token-abc', 50 );

$recoveryflow_sql = array_values(
	array_filter(
		$GLOBALS['wpdb']->queries,
		static fn ( $q ): bool => false !== stripos( (string) $q, 'recoveryflow_journeys' )
	)
);

ok( 'claiming a batch reads first and writes second', count( $recoveryflow_sql ) >= 2 );
ok( 'the read is ordered by when the work is due', false !== stripos( $recoveryflow_sql[0], 'ORDER BY next_action_at ASC' ) );
ok( 'and the write is addressed by primary key', false !== stripos( $recoveryflow_sql[1], 'id IN (41,42)' ) );
ok( 'and it re-checks the lease, so a second run claims nothing', false !== stripos( $recoveryflow_sql[1], 'claimed_until IS NULL OR claimed_until <' ) );
ok( 'and there is no UPDATE ... LIMIT left to be unsafe under replication', false === stripos( $recoveryflow_sql[1], 'LIMIT' ) );

$GLOBALS['wpdb']->queries = array();
$plugin->journeys()->claim_pollable( 'token-abc', 50 );

$recoveryflow_sql = array_values(
	array_filter(
		$GLOBALS['wpdb']->queries,
		static fn ( $q ): bool => false !== stripos( (string) $q, 'recoveryflow_journeys' )
	)
);

ok( 'the polling claim reads by its own due column', false !== stripos( $recoveryflow_sql[0], 'ORDER BY poll_at ASC' ) );

// A page with nothing on it must not run an UPDATE with an empty IN () clause,
// which is a syntax error rather than a no-op.
$GLOBALS['wpdb']->rows['recoveryflow_journeys'] = array();
$GLOBALS['wpdb']->queries                       = array();

check( 'nothing due claims nothing', $plugin->journeys()->claim_due( 'token-abc', 50 ), 0 );
check(
	'and issues no write at all',
	count(
		array_filter(
			$GLOBALS['wpdb']->queries,
			static fn ( $q ): bool => 0 === stripos( ltrim( (string) $q ), 'UPDATE' )
		)
	),
	0
);

$GLOBALS['wpdb']->rows['recoveryflow_events'] = array( array( 'id' => 7 ) );
$GLOBALS['wpdb']->queries                     = array();

$plugin->events()->expire_unidentified( '2026-01-01 00:00:00', 500 );

$recoveryflow_sql = array_values(
	array_filter(
		$GLOBALS['wpdb']->queries,
		static fn ( $q ): bool => 0 === stripos( ltrim( (string) $q ), 'UPDATE' )
	)
);

ok( 'closing stale events is addressed by primary key too', false !== stripos( $recoveryflow_sql[0], 'id IN (7)' ) );
ok( 'and re-checks that they are still open and unclaimed', false !== stripos( $recoveryflow_sql[0], 'journey_id IS NULL' ) );

$GLOBALS['wpdb']->rows = array();


// ---------------------------------------------------------------------------
// Basket values, as a shopkeeper reads them.
//
// Amounts are decimal(13,4), so MySQL hands 18.99 back as "18.9900" -- and both
// screens that showed a basket value printed exactly that next to the currency
// code, on the queue a shop worker has open all day. Nothing was wrong with the
// number; it had simply never been looked at.
// ---------------------------------------------------------------------------

check( 'a stored decimal is shown the way it is read, not the way it is stored', Money::format( '18.9900', 'GBP' ), '18.99 GBP' );
check( 'and trailing places never leak onto the screen', Money::format( '128.0000', 'GBP' ), '128.00 GBP' );
check( 'a whole amount still shows its pence', Money::format( '7', 'EUR' ), '7.00 EUR' );
check( 'an amount with no currency is still readable', Money::format( '4.5000', '' ), '4.50' );
check( 'and a currency is upper-cased, because the column does not promise it', Money::format( '4.5000', 'gbp' ), '4.50 GBP' );
check( 'nothing is invented for an empty amount', Money::format( '', 'GBP' ), '' );
check( 'nor for a value that is not a number at all', Money::format( 'lots', 'GBP' ), '' );

// One home: both screens ask it rather than each formatting for itself.
foreach ( array( 'Journeys_Table', 'Journey_Detail' ) as $recoveryflow_screen ) {
	$recoveryflow_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Pages/' . $recoveryflow_screen . '.php' );

	ok( "{$recoveryflow_screen} formats money through Money", false !== strpos( $recoveryflow_src, 'Money::format(' ) );
	ok(
		"{$recoveryflow_screen} does not print a raw amount beside a currency",
		false === strpos( $recoveryflow_src, "amount . ' ' . " )
	);
}


// ---------------------------------------------------------------------------
// The receipt ledger: the guarantee that a retry cannot do the work twice.
//
// This shipped BROKEN and no gate could see it. recoveryflow_receipts is keyed
// on receipt_key itself, so it has no AUTO_INCREMENT column and MySQL leaves
// insert_id at 0. claim() read the outcome of INSERT IGNORE as an id, so it
// answered "somebody else got there first" to EVERY caller including the one
// whose insert wrote the row -- and Order_Observer gates every order event on
// it, so on a real install an order never stopped a recovery and never marked
// one recovered. The fake database set insert_id for every insert, which is why
// the suite stayed green. Found by running it against a real WordPress.
// ---------------------------------------------------------------------------

$recoveryflow_ledger = $plugin->receipts();
$recoveryflow_key    = 'wc_order:4242:completed';

/*
 * insert_id PERSISTS across statements -- it holds the last AUTO_INCREMENT
 * value generated on the connection, and a natural-key table never updates it.
 * That is what made the shipped bug nondeterministic rather than merely wrong:
 * in a request that had already inserted something, claim() read a STALE id and
 * answered "yes, it is yours" to every caller, so the dedupe was simply absent;
 * in a request that had not, it answered "no" to everyone and the work was
 * never done at all. Both are asserted, because a fix that only handles one of
 * them is not a fix.
 */
$GLOBALS['wpdb']->insert_id = 7;

ok(
	'the first caller to claim an event gets it',
	true === $recoveryflow_ledger->claim( $recoveryflow_key, Receipt_Repository::KIND_ORDER )
);
ok(
	'and the second is turned away, which is the whole guarantee',
	false === $recoveryflow_ledger->claim( $recoveryflow_key, Receipt_Repository::KIND_ORDER )
);
ok(
	'a different event is not blocked by it',
	true === $recoveryflow_ledger->claim( 'wc_order:4243:completed', Receipt_Repository::KIND_ORDER )
);

// And the other half: a request that has inserted nothing yet leaves insert_id
// at zero, where the old reading answered "somebody else got there first" to
// everybody and the work was never done by anyone.
$GLOBALS['wpdb']->insert_id = 0;

ok(
	'the first caller still gets it when nothing has set an insert id',
	true === $recoveryflow_ledger->claim( 'wc_order:4244:completed', Receipt_Repository::KIND_ORDER )
);
ok(
	'and the second is still turned away',
	false === $recoveryflow_ledger->claim( 'wc_order:4244:completed', Receipt_Repository::KIND_ORDER )
);

// The reason it broke, asserted directly: a natural-key table must not have its
// outcome read as an id.
ok(
	'the ledger asks whether IT wrote the row, not what id the row was given',
	false !== strpos(
		kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Database/Receipt_Repository.php', 'claim' ),
		'insert_ignore_wrote'
	)
);


// ---------------------------------------------------------------------------
// The webhook receiver an Auto Flow can call back into.
//
// Read the controller's own note for what this deliberately is NOT: WA.cr has
// no outbound webhook subscription and its Auto Flow webhook node does not sign
// the body, so a receiver verifying x-wacr-signature would verify a header
// nothing sends. It is a shared secret because that is what the platform can
// actually present.
// ---------------------------------------------------------------------------

$GLOBALS['recoveryflow_caps'] = null;

// A flow reports whatever WhatsApp gave it, which is normally the full
// international form -- but the resolver is given the shop's own country so a
// local form resolves too, exactly as it does when erasing by phone.
update_option(
	Options::SETTINGS,
	array_merge( (array) get_option( Options::SETTINGS, array() ), array( Email_Compliance::SETTING_COUNTRY => 'GB' ) )
);

$recoveryflow_hook = $plugin->rest_webhook();

$recoveryflow_post = static function ( array $body, string $secret = '', string $type = 'application/json' ): WP_REST_Request {
	$request = new WP_REST_Request( 'POST', '' );
	$request->set_header( 'content-type', $type );

	if ( '' !== $secret ) {
		$request->set_header( Webhook_Secret::HEADER, $secret );
	}

	$request->set_body( wp_json_encode( $body ) );

	return $request;
};

// Closed until configured. An endpoint that is open until somebody sets a
// secret is open on every site that never got round to it.
Webhook_Secret::forget();

ok( 'the receiver is shut while no secret has been generated', ! Webhook_Secret::exists() );
ok(
	'and refuses a caller presenting nothing',
	$recoveryflow_hook->authorised( $recoveryflow_post( array() ) ) instanceof WP_Error
);
$recoveryflow_unconfigured = $recoveryflow_hook->authorised( $recoveryflow_post( array(), 'guessed-it' ) );

ok( 'and refuses a caller presenting anything', $recoveryflow_unconfigured instanceof WP_Error );

$recoveryflow_secret = Webhook_Secret::generate();

ok( 'generating a secret hands back a plaintext once', strlen( $recoveryflow_secret ) > 20 );
ok( 'and the database keeps only its hash', (string) get_option( Options::WEBHOOK_SECRET, '' ) !== $recoveryflow_secret );
ok( 'the right secret is accepted', true === $recoveryflow_hook->authorised( $recoveryflow_post( array(), $recoveryflow_secret ) ) );
ok( 'a wrong one is not', $recoveryflow_hook->authorised( $recoveryflow_post( array(), 'not-the-secret' ) ) instanceof WP_Error );

$recoveryflow_unauth = $recoveryflow_hook->authorised( $recoveryflow_post( array(), 'not-the-secret' ) );

/*
 * The comparison that matters is between a site with NO secret configured and a
 * site with one where the caller guessed wrong. Telling those apart tells
 * somebody probing which sites are worth coming back to. Both messages are
 * captured from those two different states, not from the same one twice.
 */
check(
	'a wrong secret and a site with none configured get the identical answer, so probing learns nothing',
	$recoveryflow_unauth instanceof WP_Error ? $recoveryflow_unauth->get_error_message() : 'a',
	$recoveryflow_unconfigured instanceof WP_Error ? $recoveryflow_unconfigured->get_error_message() : 'b'
);

// The refusals a flow author has to tell apart from their node log.
$recoveryflow_form = $recoveryflow_post( array( 'event' => 'recovery.opt_out' ), $recoveryflow_secret, 'application/x-www-form-urlencoded' );

$recoveryflow_wrong_type = $recoveryflow_hook->receive( $recoveryflow_form );

check(
	'a body that is not JSON is refused as unsupported media',
	$recoveryflow_wrong_type instanceof WP_Error ? $recoveryflow_wrong_type->get_error_data()['status'] : 0,
	415
);

$recoveryflow_big = $recoveryflow_post(
	array(
		'event' => 'recovery.opt_out',
		'pad'   => str_repeat( 'x', Webhook_Controller::MAX_BODY ),
	),
	$recoveryflow_secret
);

$recoveryflow_oversize = $recoveryflow_hook->receive( $recoveryflow_big );

check(
	'an oversized body is refused rather than read',
	$recoveryflow_oversize instanceof WP_Error ? $recoveryflow_oversize->get_error_data()['status'] : 0,
	413
);

$recoveryflow_unknown_event = $recoveryflow_hook->receive( $recoveryflow_post( array( 'event' => 'message.delivered' ), $recoveryflow_secret ) );

check(
	'an event this site does not accept is refused',
	$recoveryflow_unknown_event instanceof WP_Error ? $recoveryflow_unknown_event->get_error_data()['status'] : 0,
	400
);
ok(
	'and the refusal lists what it does accept, so a mistyped event is not a silent 200',
	$recoveryflow_unknown_event instanceof WP_Error
		&& false !== strpos( $recoveryflow_unknown_event->get_error_message(), 'recovery.opt_out' )
);

// A number nobody here has is a 200 with nothing matched -- the flow did
// nothing wrong, and a 404 would answer "is this number one of your customers".
$GLOBALS['wpdb']->rows['recoveryflow_identities'] = array();
$GLOBALS['wpdb']->rows['recoveryflow_customers']  = array();

$recoveryflow_nobody = $recoveryflow_hook->receive(
	$recoveryflow_post(
		array(
			'event' => 'recovery.opt_out',
			'id'    => 'evt-nobody-1',
			'phone' => '+447700900999',
		),
		$recoveryflow_secret
	)
);

ok( 'an unknown customer is accepted rather than refused', $recoveryflow_nobody instanceof WP_REST_Response );
check(
	'and reports nothing matched',
	$recoveryflow_nobody instanceof WP_REST_Response ? $recoveryflow_nobody->get_data()['matched'] : -1,
	0
);

// A STOP reported by a flow suppresses here and now. Every minute of waiting
// for a poll is a minute another reminder can reach somebody who said stop.
$recoveryflow_hook_hash = Identity_Repository::hash_for( Identity::E164, '+447700900123' );

$GLOBALS['wpdb']->rows['recoveryflow_identities'] = array( array( 'customer_id' => 91 ) );
$GLOBALS['wpdb']->rows['recoveryflow_customers']  = array(
	array(
		'id'            => 91,
		'phone_hash'    => $recoveryflow_hook_hash,
		'email_hash'    => '',
		'anonymized_at' => null,
	),
);

$GLOBALS['wpdb']->queries = array();

$recoveryflow_stop = $recoveryflow_hook->receive(
	$recoveryflow_post(
		array(
			'event' => 'recovery.opt_out',
			'id'    => 'evt-stop-1',
			'phone' => '07700 900123',
		),
		$recoveryflow_secret
	)
);

ok( 'a STOP reported by a flow is accepted', $recoveryflow_stop instanceof WP_REST_Response );
check(
	'and matches the customer',
	$recoveryflow_stop instanceof WP_REST_Response ? $recoveryflow_stop->get_data()['matched'] : -1,
	1
);

/*
 * And that it matched by the NORMALISED number. The fake database hands back a
 * primed row for any query naming the table, so "matched: 1" says nothing about
 * what was searched for -- a receiver that skipped normalisation would look
 * identical here. The query itself is the only witness, so it is what is
 * asserted. A mutation survived until this existed.
 */
ok(
	'and searched for the international form, not the local one it was sent',
	'' !== $recoveryflow_hook_hash
		&& false !== strpos( implode( ' | ', $GLOBALS['wpdb']->queries ), $recoveryflow_hook_hash )
);

// The same event twice does what the first did, which is nothing. The node is
// at-most-once, but a shared secret is replayable by whoever has seen it.
$recoveryflow_replay = $recoveryflow_hook->receive(
	$recoveryflow_post(
		array(
			'event' => 'recovery.opt_out',
			'id'    => 'evt-stop-1',
			'phone' => '07700 900123',
		),
		$recoveryflow_secret
	)
);

ok(
	'a replayed event is recognised',
	$recoveryflow_replay instanceof WP_REST_Response && true === ( $recoveryflow_replay->get_data()['repeat'] ?? false )
);
check(
	'and does nothing the first did not',
	$recoveryflow_replay instanceof WP_REST_Response ? $recoveryflow_replay->get_data()['matched'] : -1,
	0
);

// It must never accept a GET: WhatsApp's link-preview fetcher and every crawler
// will GET any URL they find, and this one suppresses customers.
$GLOBALS['recoveryflow_routes'] = array();
$recoveryflow_hook->register_routes();

$recoveryflow_hook_methods = array();

foreach ( $GLOBALS['recoveryflow_routes'] as $recoveryflow_route ) {
	foreach ( $recoveryflow_route['endpoints'] as $recoveryflow_endpoint ) {
		$recoveryflow_hook_methods[] = (string) ( $recoveryflow_endpoint['methods'] ?? '' );
	}
}

check( 'the receiver answers POST and nothing else', $recoveryflow_hook_methods, array( 'POST' ) );
ok(
	'and sits under this plugin\'s prefix in the shared KDC namespace',
	0 === strpos( $GLOBALS['recoveryflow_routes'][0]['route'], '/' . Routes::PREFIX . '/' )
);

// No event here reports a delivery status, because WA.cr does not push them and
// an event for one would document a capability that does not exist.
foreach ( Webhook_Controller::EVENTS as $recoveryflow_event ) {
	ok(
		"{$recoveryflow_event} is not a delivery status this platform cannot send",
		false === strpos( $recoveryflow_event, 'deliver' ) && false === strpos( $recoveryflow_event, 'read' )
	);
}

// The address and the secret have to arrive together: a merchant types all
// three of address, header name and secret into WA.cr by hand, and giving them
// one while leaving the rest to the documentation is how this gets configured
// wrongly and reported as broken.
$GLOBALS['recoveryflow_caps'] = array( Capabilities::MANAGE_SETTINGS );

Webhook_Secret::forget();

$recoveryflow_setup_html = recoveryflow_render_screen( array( Webhook_Setup::class, 'render' ) );

ok( 'the setup card gives the address to post to', false !== strpos( $recoveryflow_setup_html, Routes::path( 'webhooks/wacr' ) ) );
ok( 'and names the header the secret goes in', false !== strpos( $recoveryflow_setup_html, Webhook_Secret::HEADER ) );
ok( 'and lists the events a flow may send', false !== strpos( $recoveryflow_setup_html, 'recovery.opt_out' ) );
ok(
	'and says the secret is shown once before it is generated, not after',
	false !== strpos( $recoveryflow_setup_html, 'shown once' )
);
ok(
	'and says the endpoint refuses everything until one exists',
	false !== strpos( $recoveryflow_setup_html, 'refuses every request' )
);

Webhook_Secret::generate();

$recoveryflow_setup_html = recoveryflow_render_screen( array( Webhook_Setup::class, 'render' ) );

ok(
	'once a secret exists the screen warns that replacing it breaks the flow',
	false !== strpos( $recoveryflow_setup_html, 'will start being refused' )
);
ok(
	'and never offers to show the existing one, because only its fingerprint is kept',
	false === strpos( $recoveryflow_setup_html, (string) get_option( Options::WEBHOOK_SECRET, 'no-secret' ) )
);

Webhook_Secret::forget();
$GLOBALS['wpdb']->rows = array();


// ---------------------------------------------------------------------------
// Erasing a customer who only ever gave a phone number.
//
// Core's privacy eraser is keyed by email address. A shopper who typed a phone
// number at the checkout and never an address could not be found by it at all,
// so the honest answer to "please delete my data" was to open the database.
// ---------------------------------------------------------------------------

$GLOBALS['recoveryflow_caps'] = array( Capabilities::MANAGE_SETTINGS );

$recoveryflow_erase = $plugin->privacy_erase_phone();

// The two refusals that look alike from outside and mean opposite things: one
// says try again, the other says stop looking.
check(
	'an empty box is refused without pretending to search',
	$recoveryflow_erase->erase( '' )['ok'],
	false
);

$recoveryflow_unreadable = $recoveryflow_erase->erase( 'not a phone number' );

ok( 'something that is not a number is refused', false === $recoveryflow_unreadable['ok'] );
ok(
	'and is told it could not be read, not that nobody has it',
	false !== strpos( $recoveryflow_unreadable['message'], 'not a phone number RecoveryFlow can read' )
);

$GLOBALS['wpdb']->rows['recoveryflow_identities'] = array();
$GLOBALS['wpdb']->rows['recoveryflow_customers']  = array();

$recoveryflow_absent = $recoveryflow_erase->erase( '+447700900123' );

ok( 'a number nobody has is refused', false === $recoveryflow_absent['ok'] );
ok(
	'and is told nobody has it, not that it could not be read',
	false !== strpos( $recoveryflow_absent['message'], 'No customer is recorded against that number' )
);

// The number is normalised before it is hashed. A customer reads their number
// off their phone as 07700 900123; the identity was stored as +447700900123, so
// hashing what was typed finds nobody who is certainly there.
update_option(
	Options::SETTINGS,
	array_merge( (array) get_option( Options::SETTINGS, array() ), array( Email_Compliance::SETTING_COUNTRY => 'GB' ) )
);

$recoveryflow_local_hash = Identity_Repository::hash_for( Identity::E164, '+447700900123' );

$GLOBALS['wpdb']->rows['recoveryflow_identities'] = array( array( 'customer_id' => 77 ) );
$GLOBALS['wpdb']->rows['recoveryflow_customers']  = array(
	array(
		'id'            => 77,
		'phone_hash'    => $recoveryflow_local_hash,
		'anonymized_at' => null,
	),
);

$recoveryflow_local = $recoveryflow_erase->erase( '07700 900123' );

ok( 'a number typed the way a customer reads it is found', true === $recoveryflow_local['ok'] );
ok(
	'and the erasure says what was kept and why, rather than only that it is done',
	false !== strpos( $recoveryflow_local['message'], 'stopped working' )
);

// It must go through the resolver, not the normaliser beneath it: a site that
// filters how a number is stored has to be searched the same way.
ok(
	'the lookup normalises through the same path the checkout stored by',
	false !== strpos(
		kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Privacy/Erase_By_Phone.php', 'erase' ),
		'Identity_Resolver::to_e164'
	)
);

// One implementation of forgetting somebody. A second would eventually disagree
// about what "erased" means, and the half nobody watched would forget something.
ok(
	'erasing by phone ends at the same anonymiser as everything else',
	false !== strpos(
		kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Privacy/Erase_By_Phone.php', 'erase' ),
		'anonymizer->anonymize_customer'
	)
);

// Erasing somebody already erased is not an error and is not work. Saying
// "done" again would be a lie about something that did not happen.
$GLOBALS['wpdb']->rows['recoveryflow_identities'] = array( array( 'customer_id' => 78 ) );
$GLOBALS['wpdb']->rows['recoveryflow_customers']  = array(
	array(
		'id'            => 78,
		'phone_hash'    => $recoveryflow_local_hash,
		'anonymized_at' => '2026-01-01 00:00:00',
	),
);

$recoveryflow_again = $recoveryflow_erase->erase( '+447700900123' );

ok( 'erasing somebody already erased is not an error', true === $recoveryflow_again['ok'] );
ok(
	'and says nothing changed rather than claiming the work was done again',
	false !== strpos( $recoveryflow_again['message'], 'already been erased' )
);

// The form never echoes back who was found: it would otherwise answer "does
// this phone number belong to one of your customers" for anyone who can see it.
$recoveryflow_erase_html = recoveryflow_render_screen( array( Erase_By_Phone::class, 'form' ) );

/*
 * The card renders inside the settings form, so its own form is declared after
 * that one closes and the controls name it by id. The assertions below are
 * about the pair: rendering only the card would say nothing about the method or
 * the nonce, because neither is in the card any more.
 */
$recoveryflow_erase_deferred = recoveryflow_render_screen( array( Deferred_Form::class, 'flush' ) );

ok( 'the erase form is a post', false !== strpos( $recoveryflow_erase_deferred, 'method="post"' ) );
ok( 'and carries a nonce', false !== strpos( $recoveryflow_erase_deferred, 'name="_wpnonce"' ) );
ok(
	'and the controls in the card point at that form rather than at whatever encloses them',
	false !== strpos( $recoveryflow_erase_html, 'form="' . Erase_By_Phone::FORM_ID . '"' )
		&& false !== strpos( $recoveryflow_erase_deferred, 'id="' . Erase_By_Phone::FORM_ID . '"' )
);
ok(
	'and the card itself opens no form, which is what put the Save button outside one',
	false === strpos( kdc_wacr_recoveryflow_code_only( $recoveryflow_erase_html ), '<form' )
);
ok( 'and says it cannot be undone before it is used', false !== strpos( $recoveryflow_erase_html, 'cannot be undone' ) );
ok(
	'and its box has a real label rather than a placeholder standing in for one',
	false !== strpos( $recoveryflow_erase_html, 'for="' . Erase_By_Phone::FIELD . '"' )
);

$GLOBALS['wpdb']->rows = array();


// ---------------------------------------------------------------------------
// Working a recovery: retry, revoke links, and an opt-out taken by telephone.
//
// Three of these could not be done from wp-admin at all, and cancelling had a
// REST route with no control anywhere -- the same shape as the health check
// that told merchants to press a button which did not exist.
// ---------------------------------------------------------------------------

$GLOBALS['recoveryflow_caps'] = array( Capabilities::MANAGE_JOURNEYS, Capabilities::VIEW_JOURNEYS );

$recoveryflow_journey_row = array(
	'id'             => 900,
	'journey_uid'    => 'rec-900-abcdef',
	'status'         => Journey_State::FAILED,
	'customer_id'    => 55,
	'event_id'       => 0,
	'current_step'   => 0,
	'attempts_count' => 1,
	'source_id'      => 'woocommerce',
);

$GLOBALS['wpdb']->rows['recoveryflow_journeys'] = array( $recoveryflow_journey_row );
$GLOBALS['wpdb']->rows['recoveryflow_attempts'] = array();

$recoveryflow_act = static function ( string $uid, string $action ) use ( $plugin ) {
	$request = new WP_REST_Request( 'POST', '' );
	$request->set_param( 'uid', $uid );
	$request->set_param( 'action', $action );

	return $plugin->rest_journeys()->act( $request );
};

// Retry. It re-queues; it must never send, and must never be mistaken for
// sending by whoever is watching the bill.
$recoveryflow_retry = $recoveryflow_act( 'rec-900-abcdef', 'retry' );

ok( 'a failed recovery can be retried', $recoveryflow_retry instanceof WP_REST_Response );
check(
	'and goes back to scheduled rather than straight to sent',
	$recoveryflow_retry instanceof WP_REST_Response ? $recoveryflow_retry->get_data()['status'] : '',
	Journey_State::SCHEDULED
);
ok(
	'and says it was queued rather than sent',
	$recoveryflow_retry instanceof WP_REST_Response && true === ( $recoveryflow_retry->get_data()['queued'] ?? false )
);

// The state machine is what enforces this, not the handler's own opinion.
ok( 'retrying cannot skip the send gate', ! Journey_State::can_transition( Journey_State::FAILED, Journey_State::MESSAGE_SENT ) );

// A recovery that did not fail is refused, and told what it actually is.
$GLOBALS['wpdb']->rows['recoveryflow_journeys'] = array(
	array_merge( $recoveryflow_journey_row, array( 'status' => Journey_State::RECOVERED ) ),
);

$recoveryflow_refused = $recoveryflow_act( 'rec-900-abcdef', 'retry' );

ok( 'a recovery that did not fail cannot be retried', $recoveryflow_refused instanceof WP_Error );
check( 'and is refused as a conflict rather than a not-found', $recoveryflow_refused instanceof WP_Error ? $recoveryflow_refused->get_status() : 0, 409 );
ok(
	'and the refusal says what the recovery actually is, not merely that it is not failed',
	$recoveryflow_refused instanceof WP_Error
		&& false !== strpos( $recoveryflow_refused->get_error_message(), Journey_State::label( Journey_State::RECOVERED ) )
);

// An opted-out customer must never be retried back into the queue. This is the
// one that would put a message in front of somebody who said stop.
$GLOBALS['wpdb']->rows['recoveryflow_journeys'] = array(
	array_merge( $recoveryflow_journey_row, array( 'status' => Journey_State::OPTED_OUT ) ),
);

ok( 'an opted-out recovery cannot be retried', $recoveryflow_act( 'rec-900-abcdef', 'retry' ) instanceof WP_Error );

// A step already at its attempt cap fails again the moment a pass reaches it,
// so answering "queued" would be a lie with a delay on it.
$GLOBALS['wpdb']->rows['recoveryflow_journeys'] = array( $recoveryflow_journey_row );
$GLOBALS['wpdb']->vars['COUNT(*)'] = Attempt::MAX_PER_STEP;

$recoveryflow_spent = $recoveryflow_act( 'rec-900-abcdef', 'retry' );

ok( 'a step at its attempt cap is refused rather than queued to fail again', $recoveryflow_spent instanceof WP_Error );
ok(
	'and the refusal names the cap rather than saying only "no"',
	$recoveryflow_spent instanceof WP_Error
		&& false !== strpos( $recoveryflow_spent->get_error_message(), (string) Attempt::MAX_PER_STEP )
);
ok(
	'and says what to do instead',
	$recoveryflow_spent instanceof WP_Error
		&& false !== strpos( $recoveryflow_spent->get_error_message(), 'Edit the workflow' )
);

$GLOBALS['wpdb']->vars = array();

// Revoking links stops the links without stopping the recovery -- that is the
// whole difference between it and cancelling.
$recoveryflow_revoked = $recoveryflow_act( 'rec-900-abcdef', 'revoke_links' );

ok( 'the links can be killed on their own', $recoveryflow_revoked instanceof WP_REST_Response );
check(
	'and the recovery itself is left running',
	$recoveryflow_revoked instanceof WP_REST_Response ? $recoveryflow_revoked->get_data()['status'] : '',
	Journey_State::FAILED
);

// Revoking nothing is an outcome, not a success. Saying "done" would leave
// somebody believing a link they are worried about had just been killed.
check(
	'revoking no links says so rather than reporting a job done',
	Journey_Actions::outcome( 'revoke_links', array( 'revoked' => 0 ) ),
	__( 'There were no working links on this recovery, so nothing changed. Any link already sent for it had expired or been revoked already.', 'kdc-wacr-recoveryflow' )
);

// Which buttons are offered. Offering one that will certainly be refused
// teaches somebody the screen is broken.
$recoveryflow_failed_journey = Recovery_Journey::from_row( $recoveryflow_journey_row );

ok( 'a failed recovery offers a retry', isset( Journey_Actions::available( $recoveryflow_failed_journey )['retry'] ) );
ok( 'and no longer offers to stop something already stopped', ! isset( Journey_Actions::available( $recoveryflow_failed_journey )['cancel'] ) );

$recoveryflow_live_journey = Recovery_Journey::from_row(
	array_merge( $recoveryflow_journey_row, array( 'status' => Journey_State::SCHEDULED ) )
);

ok( 'a running recovery offers to stop it', isset( Journey_Actions::available( $recoveryflow_live_journey )['cancel'] ) );
ok( 'and does not offer to retry something that has not failed', ! isset( Journey_Actions::available( $recoveryflow_live_journey )['retry'] ) );
ok( 'the opt-out is offered whatever state the recovery is in, because the customer is not the recovery', isset( Journey_Actions::available( $recoveryflow_live_journey )['opt_out'] ) );

// The admin buttons run the REST handler rather than a second implementation.
ok(
	'the admin buttons call the endpoint\'s own handler rather than repeating its rules',
	false !== strpos(
		kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Admin/Journey_Actions.php', 'run' ),
		'controller->act('
	)
);
check(
	'and can only ask for something the endpoint accepts',
	Journeys_Controller::ACTIONS,
	array( 'cancel', 'retry', 'revoke_links', 'opt_out' )
);

$recoveryflow_bogus = $plugin->admin_journey_acts()->run( 'rec-900-abcdef', 'send_now' );

ok( 'a hand-posted action the endpoint does not offer is refused', false === $recoveryflow_bogus['ok'] );

// Row actions must not change anything: an anchor that cancels a recovery is
// fetched by whatever follows links.
$recoveryflow_row_body = kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Admin/Pages/Journeys_Table.php', 'column_reference' );

ok( 'the row-action column can be read', strlen( $recoveryflow_row_body ) > 50 );

foreach ( Journeys_Controller::ACTIONS as $recoveryflow_verb ) {
	ok(
		"no row action performs {$recoveryflow_verb} from a link",
		false === strpos( $recoveryflow_row_body, "'" . $recoveryflow_verb . "'" )
	);
}

ok( 'the row action only opens the recovery', false !== strpos( $recoveryflow_row_body, 'Screen::journey_url' ) );

// And the things that DO change a recovery are posts carrying a nonce.
$recoveryflow_buttons_html = recoveryflow_render_screen(
	static function () use ( $recoveryflow_failed_journey ): void {
		Journey_Actions::buttons( $recoveryflow_failed_journey );
	}
);

ok( 'acting on a recovery is a form, not a link', false !== strpos( $recoveryflow_buttons_html, 'method="post"' ) );
ok( 'and carries a nonce', false !== strpos( $recoveryflow_buttons_html, 'name="_wpnonce"' ) );
ok(
	'and the irreversible ones say so before they are pressed',
	false !== strpos( $recoveryflow_buttons_html, esc_html__( 'Nothing further is sent for it and its links stop working. This cannot be undone.', 'kdc-wacr-recoveryflow' ) )
		|| false !== strpos( $recoveryflow_buttons_html, 'cannot be undone' )
);
ok(
	'and the retry says plainly that it does not send',
	false !== strpos( $recoveryflow_buttons_html, 'It does not send anything now' )
);

/*
 * Working the queue in bulk. The whole design goal is that this adds a control
 * and not a second set of rules, so what is asserted is mostly that it DID NOT
 * grow one: every selected recovery goes through the same run(), which goes
 * through the same REST controller.
 */
$recoveryflow_acts = $plugin->admin_journey_acts();

$recoveryflow_bulk_none = $recoveryflow_acts->run_many( array(), 'cancel' );

ok( 'a bulk action with nothing ticked refuses rather than reporting success', false === $recoveryflow_bulk_none['ok'] );

check(
	'one recovery selected gives exactly what the single-recovery form gives',
	$recoveryflow_acts->run_many( array( 'rec-900-abcdef' ), 'send_now' ),
	$recoveryflow_acts->run( 'rec-900-abcdef', 'send_now' )
);

/*
 * Refusals are named rather than counted. "Two were refused" is not something a
 * shop worker can act on; two references are.
 */
$recoveryflow_bulk_many = $recoveryflow_acts->run_many( array( 'rec-900-aaaaaa', 'rec-900-bbbbbb' ), 'send_now' );

ok(
	'a bulk refusal names each recovery it refused',
	false !== strpos( $recoveryflow_bulk_many['message'], 'rec-900-aaaaaa' )
		&& false !== strpos( $recoveryflow_bulk_many['message'], 'rec-900-bbbbbb' )
);
ok( 'and a bulk run where nothing succeeded is not reported as a success', false === $recoveryflow_bulk_many['ok'] );

/*
 * The bulk select cannot be called `action`. That name already means "which
 * admin-post handler" on this form, so core's own naming would post a bulk verb
 * into the slot that chooses the handler and the whole form would go nowhere.
 */
$recoveryflow_bulk_src = kdc_wacr_recoveryflow_code_only(
	kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Admin/Pages/Journeys_Table.php', 'bulk_actions' )
);

ok(
	'the bulk select posts under the same name the single-recovery form uses',
	false !== strpos( $recoveryflow_bulk_src, 'name="recoveryflow_action"' )
);
ok(
	'and never under core\'s name, which this form has already spent on the handler',
	false === strpos( $recoveryflow_bulk_src, 'name="action"' )
);

/*
 * Core's display_tablenav() opens with wp_nonce_field(), which writes both a
 * name this handler does not check and an id this plugin removed from every
 * other form on accessibility grounds. Overridden, and asserted in both
 * directions so restoring core's call fails here.
 */
$recoveryflow_tablenav_src = kdc_wacr_recoveryflow_code_only(
	kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Admin/Pages/Journeys_Table.php', 'display_tablenav' )
);

ok( 'the queue writes its nonce through the field that carries no id', false !== strpos( $recoveryflow_tablenav_src, 'Nonce_Field::render' ) );
ok( 'and not through the core call that would put a second #_wpnonce on the screen', false === strpos( $recoveryflow_tablenav_src, 'wp_nonce_field(' ) );

// And the queue screen posts its actions rather than getting them, for the same
// reason a row action is not a link that does something.
$recoveryflow_queue_src = kdc_wacr_recoveryflow_code_only(
	kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Admin/Pages/Journeys.php', 'render' )
);

ok( 'the queue screen can be read', strlen( $recoveryflow_queue_src ) > 50 );
ok(
	'the queue acts through a POST to admin-post.php',
	false !== strpos( $recoveryflow_queue_src, 'method="post"' )
		&& false !== strpos( $recoveryflow_queue_src, "admin_url( 'admin-post.php' )" )
);
ok( 'and still searches over GET, so a filtered queue stays a linkable address', false !== strpos( $recoveryflow_queue_src, 'method="get"' ) );
ok( 'and reports what a bulk action did', false !== strpos( $recoveryflow_queue_src, 'Journey_Actions::notice' ) );

$GLOBALS['wpdb']->rows = array();


// ---------------------------------------------------------------------------
// The /integrations and /templates collections.
//
// Both shipped as documented-but-absent for three slices. The security matrix
// above already walks them; what is asserted here is that neither works its
// answer out a second time -- the fault this plugin has shipped four times.
// ---------------------------------------------------------------------------

$GLOBALS['recoveryflow_caps'] = array( Capabilities::MANAGE_SETTINGS, Capabilities::MANAGE_WORKFLOWS );

$recoveryflow_integrations = $plugin->rest_integrations()->index()->get_data();

ok( 'the integrations collection lists what is registered', count( $recoveryflow_integrations['integrations'] ) > 0 );
check(
	'and lists exactly the registry\'s sources, not a hand-kept list of its own',
	count( $recoveryflow_integrations['integrations'] ),
	count( $plugin->sources()->all() )
);

// Switch one source OFF first. Available-and-switched-off is the state where a
// verdict derived from "is it installed" and the registry's real verdict
// disagree -- so without it, an endpoint that worked the answer out for itself
// would agree with the registry by luck and the assertion below would be
// vacuous. It was, until a mutation survived and said so.
$recoveryflow_first_source = '';

foreach ( $plugin->sources()->all() as $recoveryflow_candidate ) {
	if ( '' === $recoveryflow_first_source && $recoveryflow_candidate->is_available() ) {
		$recoveryflow_first_source = $recoveryflow_candidate->get_id();
	}
}

ok( 'there is an available source to switch off, so the assertions below are not vacuous', '' !== $recoveryflow_first_source );

$plugin->sources()->set_enabled( $recoveryflow_first_source, false );

$recoveryflow_integrations = $plugin->rest_integrations()->index()->get_data();

$recoveryflow_row = array();

foreach ( $recoveryflow_integrations['integrations'] as $recoveryflow_candidate ) {
	if ( (string) $recoveryflow_candidate['id'] === $recoveryflow_first_source ) {
		$recoveryflow_row = $recoveryflow_candidate;
	}
}

check(
	'a source that is installed but switched off is reported as switched off',
	$recoveryflow_row['status'],
	Source_Registry::SWITCHED_OFF
);
ok( 'and is still reported as available, because it is', true === $recoveryflow_row['available'] );
ok( 'and as switched off', false === $recoveryflow_row['enabled'] );

foreach ( array( 'id', 'name', 'status', 'message', 'available', 'enabled', 'built_in', 'settings' ) as $recoveryflow_key ) {
	ok( "an integration row carries {$recoveryflow_key}", array_key_exists( $recoveryflow_key, $recoveryflow_row ) );
}

// The endpoint must not re-derive the verdict. Asked of the registry directly,
// the two answers have to be the same object of truth.
check(
	'the verdict comes from the registry rather than being worked out again',
	$recoveryflow_row['status'],
	$plugin->sources()->status( (string) $recoveryflow_row['id'] )
);
check(
	'and so does the sentence, so the API and the screen cannot disagree',
	$recoveryflow_row['message'],
	Source_Registry::status_message( (string) $recoveryflow_row['status'] )
);
ok(
	'a machine code is never sent without the sentence that explains it',
	'' !== (string) $recoveryflow_row['message']
);
ok(
	'and every row links to the control that switches it',
	false !== strpos( (string) $recoveryflow_row['settings'], Source_Registry::enabled_key( (string) $recoveryflow_row['id'] ) )
);

// Read-only, for the same reason the settings endpoint is: a second way to
// write a setting is a second set of rules about what a valid setting is.
$GLOBALS['recoveryflow_routes'] = array();
$plugin->rest_integrations()->register_routes();

$recoveryflow_methods = array();

foreach ( $GLOBALS['recoveryflow_routes'] as $recoveryflow_route ) {
	foreach ( $recoveryflow_route['endpoints'] as $recoveryflow_endpoint ) {
		$recoveryflow_methods[] = (string) ( $recoveryflow_endpoint['methods'] ?? '' );
	}
}

check( 'the integrations collection offers reading and nothing else', $recoveryflow_methods, array( 'GET' ) );

// Templates. A refusal is not an empty list: no key, a plan below Scale, a dead
// network and a workspace with no approved templates are four situations that
// need four responses, and all four look identical as [].
delete_transient( 'recoveryflow_wacr_templates' );

set_transient(
	'recoveryflow_wacr_templates',
	array(
		'waba'  => '',
		'value' => array(
			'ok'        => true,
			'templates' => array(
				array(
					'name'      => 'usable_one',
					'language'  => 'en',
					'variables' => array(
						array(
							'id'       => 'body_1',
							'required' => true,
						),
					),
				),
				array(
					'name'      => 'needs_an_image',
					'language'  => 'en',
					'variables' => array(
						array(
							'id'       => 'header_media_image',
							'required' => true,
						),
					),
				),
			),
		),
	),
	900
);

$recoveryflow_tpl = $plugin->rest_templates()->index( new WP_REST_Request( array() ) )->get_data();

ok( 'the templates collection reports success', true === $recoveryflow_tpl['ok'] );
check( 'and lists every approved template, usable or not', count( $recoveryflow_tpl['templates'] ), 2 );
check( 'while saying how many can actually be sent', $recoveryflow_tpl['usable'], 1 );
ok(
	'a template this plugin cannot fill is listed rather than hidden from whoever approved it',
	in_array( 'needs_an_image', array_column( $recoveryflow_tpl['templates'], 'name' ), true )
);

// The same verdict the picker uses, not a second opinion.
check(
	'and the endpoint agrees with the catalogue the workflow editor reads',
	array_column( $recoveryflow_tpl['templates'], 'usable' ),
	array_column( $plugin->template_catalog()->all()['templates'], 'usable' )
);

$plugin->sources()->set_enabled( $recoveryflow_first_source, true );


// ---------------------------------------------------------------------------
// "Run now": running the background passes from the status screen.
//
// The button sends real reminders and bills a real account, so what is asserted
// here is mostly about what it REFUSES and what it SAYS -- the counts are the
// easy part. Note the harness detail that makes these honest: the stats are
// built by the real Stage_Runner against the real stages, so a pass that is
// removed from the runner changes these answers.
// ---------------------------------------------------------------------------

$recoveryflow_caps_before = $GLOBALS['recoveryflow_caps'] ?? null;

$GLOBALS['recoveryflow_caps'] = array( Capabilities::MANAGE_JOURNEYS );

$recoveryflow_run = $plugin->admin_run_now()->run();

ok( 'running the passes by hand reports one row per pass that ran', count( $recoveryflow_run['rows'] ) > 0 );
check(
	'and runs the same five passes the scheduler does, not a set of its own',
	array_column( $recoveryflow_run['rows'], 'pass' ),
	array_values( Scheduler_Interface::STAGES )
);
ok(
	'and really works the primed rows rather than reporting an idle queue',
	false !== strpos( $recoveryflow_run['message'], 'It handled' )
		&& 0 < array_sum( array_column( $recoveryflow_run['rows'], 'handled' ) )
);

// The idle wording matters most on a shop where nothing is wrong, which is the
// hardest state to reach through the primed harness -- so it is asserted of the
// decision directly.
$recoveryflow_idle = Run_Now::describe(
	array_combine(
		Scheduler_Interface::STAGES,
		array_map(
			static fn ( string $stage ): Stage_Stats => new Stage_Stats( $stage ),
			Scheduler_Interface::STAGES
		)
	)
);

ok( 'an idle run is reported as working, not as a fault', true === $recoveryflow_idle['ok'] );
ok(
	'and says plainly that nothing was waiting rather than leaving a row of zeroes to be read as a breakage',
	false !== strpos( $recoveryflow_idle['message'], 'Nothing was waiting.' )
);

// The counts are summed from the stats, so a pass reporting work must show up
// in the summary. Asserted by driving the real runner rather than by faking a
// stats array, which would only test the formatter.
$recoveryflow_run_html = recoveryflow_render_screen( array( Run_Now::class, 'button' ) );

ok(
	'the button warns that this really sends and really bills, before it is pressed',
	false !== strpos(
		$recoveryflow_run_html,
		esc_html__( 'The dispatch pass is one of the five, so any recovery that is due right now will be messaged, and your WA.cr account will be billed for it. Nothing that is not already due is brought forward.', 'kdc-wacr-recoveryflow' )
	)
);
ok(
	'and posts to admin-post.php under its own action',
	false !== strpos( $recoveryflow_run_html, 'name="action" value="' . Run_Now::ACTION . '"' )
);
ok( 'and carries a nonce', false !== strpos( $recoveryflow_run_html, 'name="_wpnonce"' ) );

// Reading the status screen and making the shop send are separate permissions.
// VIEW_STATUS falls back to manage_options, so an account holding only it is
// exactly the case that must not get the button.
$GLOBALS['recoveryflow_caps'] = array( Capabilities::VIEW_STATUS );

$recoveryflow_run_html = recoveryflow_render_screen( array( Run_Now::class, 'button' ) );

ok(
	'somebody who may read the status screen but not work the queue gets no button',
	false === strpos( $recoveryflow_run_html, 'name="action" value="' . Run_Now::ACTION . '"' )
);
ok(
	'and is told which permission is missing rather than finding a control silently absent',
	false !== strpos( $recoveryflow_run_html, 'recoveryflow_manage_journeys' )
);

$GLOBALS['recoveryflow_caps'] = $recoveryflow_caps_before;

// A pass that could not take its lock must not read as "there was nothing to
// do" -- that is the opposite diagnosis on the one screen somebody uses to work
// out why nothing is happening.
$recoveryflow_locked             = new Stage_Stats( Scheduler_Interface::DISPATCH );
$recoveryflow_locked->last_error = 'locked';

$recoveryflow_busy = Run_Now::describe( array( Scheduler_Interface::DISPATCH => $recoveryflow_locked ) );

ok( 'a pass that was already running is reported as skipped', false !== strpos( $recoveryflow_busy['rows'][0]['note'], 'Skipped' ) );
ok( 'and says that is ordinary rather than reading as a fault', false !== strpos( $recoveryflow_busy['rows'][0]['note'], 'ordinary' ) );
ok(
	'and the summary counts it as skipped rather than as an idle queue',
	false !== strpos( $recoveryflow_busy['message'], 'already running' )
);
ok(
	'so it never claims nothing was waiting',
	false === strpos( $recoveryflow_busy['message'], 'Nothing was waiting.' )
);

// A pass that failed is not a pass that succeeded quietly.
$recoveryflow_broken         = new Stage_Stats( Scheduler_Interface::DISPATCH );
$recoveryflow_broken->failed = 3;

$recoveryflow_bad = Run_Now::describe( array( Scheduler_Interface::DISPATCH => $recoveryflow_broken ) );

ok( 'a run with failures is not reported as ok', false === $recoveryflow_bad['ok'] );
ok( 'and says how many failed', false !== strpos( $recoveryflow_bad['message'], '3 records failed' ) );

// No passes at all is a fault, not an empty queue -- the two look identical in
// a count and need opposite responses from whoever is reading.
$recoveryflow_none = Run_Now::describe( array() );

ok( 'no registered passes is reported as a fault', false === $recoveryflow_none['ok'] );
ok(
	'and is distinguished from an empty queue in words',
	false !== strpos( $recoveryflow_none['message'], 'fault rather than an empty queue' )
);

// One rule, one home: both tables on the status screen name a pass the same way.
check(
	'the status table and the run-now table give a pass the same name',
	Stage_Label::for_stage( Scheduler_Interface::DISPATCH ),
	__( 'Sending reminders', 'kdc-wacr-recoveryflow' )
);
check(
	'and a stage nobody here registered keeps its own key rather than becoming "Unknown"',
	Stage_Label::for_stage( 'somebody-elses-pass' ),
	'somebody-elses-pass'
);


// ---------------------------------------------------------------------------
// The channel a step says it sends on is the channel it sends on.
//
// This is the fifth dead contract of the same shape, and the largest. The
// per-step channel was stored, validated, defaulted, described on screen and
// offered in the editor's dropdown -- and NOTHING in the execution path read
// it. channel_for() had two callers and both only drew the step; both send
// actions hardcoded 'whatsapp' into the attempt row; for_send() took no channel
// at all and allowed a person if ANY channel allowed them, so switching email
// on WIDENED eligibility and let an email-only customer reach a step that then
// ran a WhatsApp action. A merchant who configured email got WhatsApp.
//
// Every gate was green on it for four slices. The only channel assertion in
// this suite tested a STORAGE round-trip, which is a different claim, and the
// engine had no test of any kind. That is the hole these fill.
// ---------------------------------------------------------------------------

$recoveryflow_reachable = array_merge(
	$recoveryflow_compliant,
	array(
		'enabled'               => true,
		'eligibility_mode'      => 'identified_contact',
		'channel_email_enabled' => true,
		'frequency_cap_hours'   => 0,
	)
);

$recoveryflow_both_on = new Rule_Set( $recoveryflow_reachable );

ok( 'the fixture really has both channels open, or nothing below means anything', $recoveryflow_both_on->channel_enabled( Channel::EMAIL ) && $recoveryflow_both_on->channel_enabled( Channel::WHATSAPP ) );

// Somebody who gave an address and never a number.
$recoveryflow_email_only = Customer::from_row( array( 'id' => 8801 ) );
$recoveryflow_email_only->with_identities(
	array(
		array(
			'kind'       => Identity::EMAIL,
			'value_raw'  => 'nobody@example.test',
			'value_hash' => 'email-hash-8801',
		),
	)
);

// And somebody who gave a number and never an address.
$recoveryflow_phone_only = Customer::from_row( array( 'id' => 8802 ) );
$recoveryflow_phone_only->with_identities(
	array(
		array(
			'kind'       => Identity::E164,
			'value_raw'  => '+447700900123',
			'value_hash' => 'phone-hash-8802',
			'status'     => Customer::PHONE_VALID,
		),
	)
);

$recoveryflow_eligibility = $plugin->eligibility();

ok( 'the fixture customers are what they claim to be', '' !== $recoveryflow_email_only->email_hash && ! $recoveryflow_email_only->has_valid_phone() && $recoveryflow_phone_only->has_valid_phone() && '' === $recoveryflow_phone_only->email_hash );

/*
 * Asked nothing in particular, the any-channel answer stands: both of these
 * people are reachable somehow, which is the right question when deciding
 * whether a journey is worth starting at all.
 */
ok( 'an email-only customer is reachable on some channel', $recoveryflow_eligibility->for_send( $recoveryflow_email_only, $recoveryflow_both_on )->allowed );
ok( 'so is a phone-only customer', $recoveryflow_eligibility->for_send( $recoveryflow_phone_only, $recoveryflow_both_on )->allowed );

/*
 * Asked about ONE channel, the answer narrows -- and this is the whole fix. A
 * step sending email must be refused for somebody who left no address, and a
 * step sending WhatsApp refused for somebody who left no number, even though
 * the any-channel answer above allows both of them.
 */
$recoveryflow_verdict = $recoveryflow_eligibility->for_send( $recoveryflow_email_only, $recoveryflow_both_on, Channel::WHATSAPP );

ok( 'but an email-only customer may NOT be sent a WhatsApp message', ! $recoveryflow_verdict->allowed );
check( 'and the refusal names the missing number rather than the channel', $recoveryflow_verdict->reason, Eligibility::NO_PHONE );
ok( 'while the same person may be sent an email', $recoveryflow_eligibility->for_send( $recoveryflow_email_only, $recoveryflow_both_on, Channel::EMAIL )->allowed );

$recoveryflow_verdict = $recoveryflow_eligibility->for_send( $recoveryflow_phone_only, $recoveryflow_both_on, Channel::EMAIL );

ok( 'and a phone-only customer may NOT be sent an email', ! $recoveryflow_verdict->allowed );
check( 'because there is no address to send it to', $recoveryflow_verdict->reason, Eligibility::NO_CHANNEL );
ok( 'while the same person may be sent a WhatsApp message', $recoveryflow_eligibility->for_send( $recoveryflow_phone_only, $recoveryflow_both_on, Channel::WHATSAPP )->allowed );

// A channel the site has switched off refuses every send on it, however
// reachable the person is. This is what makes the default-off email channel
// mean something at send time rather than only on the settings screen.
$recoveryflow_email_off = new Rule_Set( array_merge( $recoveryflow_reachable, array( 'channel_email_enabled' => false ) ) );

ok( 'a step sending email on a site with email switched off is refused', ! $recoveryflow_eligibility->for_send( $recoveryflow_email_only, $recoveryflow_email_off, Channel::EMAIL )->allowed );

// An action declares the channel it sends on, and the ledger records THAT
// rather than a literal typed beside it.
check( 'a WA.cr template says it goes over WhatsApp', ( new ReflectionClass( Send_Template::class ) )->newInstanceWithoutConstructor()->get_channel(), Channel::WHATSAPP );
check( 'and so does a hand-off to an Auto Flow', ( new ReflectionClass( Start_Flow::class ) )->newInstanceWithoutConstructor()->get_channel(), Channel::WHATSAPP );

foreach ( array( 'Send_Template', 'Start_Flow' ) as $recoveryflow_action_file ) {
	$recoveryflow_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Workflow/Actions/' . $recoveryflow_action_file . '.php' );

	ok(
		"{$recoveryflow_action_file} records the channel it declares, not a hardcoded one",
		false === strpos( $recoveryflow_src, "'channel'          => 'whatsapp'" )
			&& false !== strpos( $recoveryflow_src, "'channel'          => \$this->get_channel()" )
	);
}

// Every registered action must answer the question, or the engine's comparison
// below is against a value somebody forgot to supply. Asked of the registry
// rather than of a list written here, so an action registered by another plugin
// is held to it too.
$recoveryflow_action_channels = 0;

foreach ( $plugin->steps()->actions() as $recoveryflow_name => $recoveryflow_action ) {
	++$recoveryflow_action_channels;

	ok(
		"the registered action {$recoveryflow_name} declares a real channel",
		Channel::is_channel( $recoveryflow_action->get_channel() )
	);
}

ok( 'and there really were actions to ask -- an empty loop asserts nothing', $recoveryflow_action_channels > 0 );

/*
 * And now the engine itself, which had no test of any kind -- which is the
 * reason a step could name one channel and send on another for four slices.
 *
 * The fixture is primed on `definition_json`, a fragment unique to the workflow
 * version query: the fake matches the FIRST primed fragment found in the SQL,
 * and that query names both the versions table and the workflows table, so
 * priming on a table name alone would answer whichever was declared first.
 */
$recoveryflow_rows_before = $GLOBALS['wpdb']->rows;
$recoveryflow_vars_before = $GLOBALS['wpdb']->vars;

$recoveryflow_engine_steps = static function ( string $channel ): array {
	return array(
		'steps' => array(
			array(
				'type'    => Workflow_Definition::TYPE_ACTION,
				'do'      => 'wacr.send_template',
				'channel' => $channel,
				'with'    => array(
					'template' => 'cart_reminder',
					'language' => 'en',
				),
			),
		),
	);
};

$recoveryflow_run_step = static function ( string $channel, array $extra_rows = array() ) use ( $plugin, $recoveryflow_engine_steps ): Step_Outcome {
	// The workflow and event rows are replaced on every call so one scenario
	// cannot inherit another's, and anything the caller needs on top is merged
	// in rather than assigned over the top of them.
	$GLOBALS['wpdb']->rows = $extra_rows + array(
		'definition_json'     => array(
			array(
				'id'              => 3300,
				'name'            => 'Channel fixture',
				'slug'            => 'channel-fixture',
				'source_id'       => 'woocommerce',
				'status'          => 'active',
				'definition_json' => wp_json_encode( $recoveryflow_engine_steps( $channel ) ),
				'version'         => 1,
			),
		),
		'recoveryflow_events' => array(
			array(
				'id'       => 4400,
				'status'   => Recovery_Event::OPEN,
				'currency' => 'GBP',
				'amount'   => '25.0000',
			),
		),
	);

	$journey = Recovery_Journey::from_row(
		array(
			'id'               => 5500,
			'journey_uid'      => 'rec-5500-channel',
			'status'           => Journey_State::SCHEDULED,
			'customer_id'      => 8801,
			'event_id'         => 4400,
			'workflow_id'      => 3300,
			'workflow_version' => 1,
			'current_step'     => 0,
			'source_id'        => 'woocommerce',
		)
	);

	return $plugin->engine()->run( $journey, 'claim-token-for-the-channel-test' );
};

/*
 * The contrast is the assertion, and it is built this way deliberately.
 *
 * Recovery is switched off in the settings for this fixture, so a step that
 * gets PAST the channel check runs on into the ELIGIBILITY guard and is
 * deferred there. A step that fails the channel check never reaches it. So the
 * two runs stop at measurably different distances through run_action, and that
 * is what proves the refusal happens before anything could be sent -- which a
 * bare "no attempt row was written" cannot show, because this fixture writes no
 * attempt row either way. That weaker assertion was written here first and
 * SURVIVED its mutation, which is how the fixture problem was found.
 */
update_option( Options::SETTINGS, array_merge( Options::defaults(), array( 'eligibility_mode' => 'disabled' ) ) );

$recoveryflow_agreeing = $recoveryflow_run_step( Workflow_Definition::CHANNEL_WHATSAPP );

check( 'a step whose channel agrees with its action gets past the channel check', $recoveryflow_agreeing->reason, Eligibility::DISABLED );
check( 'and is held back by a later guard instead, rather than sent', $recoveryflow_agreeing->status, Step_Outcome::WAITING );

// The one that matters. A WA.cr template cannot arrive as email, so a step
// asking for that is refused rather than quietly sent over WhatsApp.
$GLOBALS['wpdb']->writes = array();

$recoveryflow_mismatched = $recoveryflow_run_step( Workflow_Definition::CHANNEL_EMAIL );

check( 'a step naming a channel its action cannot send on fails', $recoveryflow_mismatched->status, Step_Outcome::FAILED );
check( 'and says which of the two things disagreed', $recoveryflow_mismatched->reason, 'channel_mismatch' );

// And belt and braces: whatever else happened, no attempt was reserved, so
// nothing went out and nothing was billed.
$recoveryflow_wrote_attempt = false;

// Writes are recorded positionally -- array( 'insert', $table, $data ) -- so
// reading a 'table' key here would be unset on every row, and the assertion
// below would pass without ever looking at anything.
foreach ( $GLOBALS['wpdb']->writes as $recoveryflow_write ) {
	if ( false !== strpos( (string) ( $recoveryflow_write[1] ?? '' ), 'recoveryflow_attempts' ) ) {
		$recoveryflow_wrote_attempt = true;
	}
}

ok( 'and reserves no attempt, so nothing was sent and nothing was billed', ! $recoveryflow_wrote_attempt );

/*
 * And the other half of honouring the channel: the step's channel must reach
 * the ELIGIBILITY guard, not merely the action. Dropping it there survived its
 * first mutation -- every assertion above still passed -- because nothing
 * exercised a customer for whom the two answers differ. This is that customer.
 *
 * She gave an email address and never a phone number, on a site where both
 * channels are open. Asked "can she be reached at all", the answer is yes, via
 * email. Asked "may this WhatsApp step send to her", the answer is no. Before
 * the fix the engine asked the first question and acted on it, which is how a
 * WhatsApp send got attempted for somebody with no number.
 */
update_option(
	Options::SETTINGS,
	array_merge(
		Options::defaults(),
		$recoveryflow_compliant,
		array(
			'enabled'               => true,
			'eligibility_mode'      => 'identified_contact',
			'channel_email_enabled' => true,
		)
	)
);

$recoveryflow_wrong_channel = $recoveryflow_run_step(
	Workflow_Definition::CHANNEL_WHATSAPP,
	array(
		'recoveryflow_customers'  => array( array( 'id' => 8801 ) ),
		'recoveryflow_identities' => array(
			array(
				'customer_id' => 8801,
				'kind'        => Identity::EMAIL,
				'value_raw'   => 'nobody@example.test',
				'value_hash'  => 'email-hash-8801',
			),
		),
	)
);

check(
	'a WhatsApp step is refused for a customer who left only an email address',
	$recoveryflow_wrong_channel->reason,
	Eligibility::NO_PHONE
);
check( 'and the journey is closed rather than retried forever', $recoveryflow_wrong_channel->status, Step_Outcome::STOPPED );

$GLOBALS['wpdb']->rows = $recoveryflow_rows_before;
$GLOBALS['wpdb']->vars = $recoveryflow_vars_before;


// ---------------------------------------------------------------------------
// Sending a recovery email.
//
// Until now nothing could: the channel existed in consent, eligibility, the
// ledger and the editor, and there was no sender behind any of it. What is
// asserted here is mostly what the message CARRIES and what it REFUSES, since
// those are the two things a merchant cannot check for themselves before the
// first one goes out -- and one of them is a legal obligation.
// ---------------------------------------------------------------------------

$recoveryflow_mail_settings = array_merge(
	Options::defaults(),
	$recoveryflow_compliant,
	array( 'channel_email_enabled' => true )
);

$recoveryflow_email_customer = Customer::from_row(
	array(
		'id'         => 9001,
		'first_name' => 'Ada',
	)
);
$recoveryflow_email_customer->with_identities(
	array(
		array(
			'kind'       => Identity::EMAIL,
			'value_raw'  => 'ada@example.test',
			'value_hash' => 'email-hash-9001',
		),
	)
);

$recoveryflow_email_vars = new Variable_Context(
	array(
		'customer.first_name'      => 'Ada',
		'recovery.total_formatted' => '42.00 GBP',
		'recovery.recovery_url'    => 'https://shop.example/recovery/' . str_repeat( 'a', 43 ) . '/restore',
		'recovery.opt_out_url'     => 'https://shop.example/recovery/' . str_repeat( 'a', 43 ) . '/opt-out',
		'site.name'                => 'Northbound Supply',
	)
);

$recoveryflow_email_composer = new Email_Composer();

$recoveryflow_step_with = array(
	'subject' => 'Your basket is waiting, {{ customer.first_name }}',
	'body'    => "Hello {{ customer.first_name }},\n\nYou left {{ recovery.total_formatted }} behind at {{ site.name }}.\n\nPick up where you left off: {{ recovery.recovery_url }}",
);

$recoveryflow_composed = $recoveryflow_email_composer->compose(
	Recovery_Journey::from_row( array( 'id' => 9100 ) ),
	$recoveryflow_email_customer,
	$recoveryflow_step_with,
	$recoveryflow_email_vars,
	$recoveryflow_mail_settings
);

ok( 'a step with a subject and a body composes a message', $recoveryflow_composed instanceof Email_Message );

if ( $recoveryflow_composed instanceof Email_Message ) {
	check( 'addressed to the address the customer gave', $recoveryflow_composed->to, 'ada@example.test' );
	check( 'the subject is filled in from the step', $recoveryflow_composed->subject, 'Your basket is waiting, Ada' );
	ok( 'the body carries the recovery link', false !== strpos( $recoveryflow_composed->body, '/restore' ) );
	ok( 'and the merchant\'s own words', false !== strpos( $recoveryflow_composed->body, 'You left 42.00 GBP behind at Northbound Supply.' ) );

	/*
	 * The two things the law asks for, in the body of every message. They are
	 * appended here rather than left to the wording, because a template a
	 * merchant edits is a template the unsubscribe can be deleted from -- and
	 * shortening the message is the first thing anybody does to one.
	 */
	ok(
		'every message carries the postal address, whatever the step said',
		false !== strpos( $recoveryflow_composed->body, Email_Compliance::address( $recoveryflow_mail_settings ) )
			&& '' !== Email_Compliance::address( $recoveryflow_mail_settings )
	);
	ok( 'and an unsubscribe link', false !== strpos( $recoveryflow_composed->body, '/opt-out' ) );
	ok( 'and they are the last thing in it, after a signature separator', false !== strpos( $recoveryflow_composed->body, "\n-- \n" ) );
	check( 'and it goes out as plain text, so no stylesheet can hide either of them', $recoveryflow_composed->headers(), array( 'Content-Type: text/plain; charset=UTF-8' ) );

	// No From: the site's own mail configuration decides, so recovery mail
	// leaves by the same route as the shop's order emails.
	ok( 'the plugin sets no From address of its own', false === strpos( implode( "\n", $recoveryflow_composed->headers() ), 'From:' ) );
}

// A newline in a subject is a header injection, and the subject came out of an
// administrator-authored document.
$recoveryflow_injected = $recoveryflow_email_composer->compose(
	Recovery_Journey::from_row( array( 'id' => 9100 ) ),
	$recoveryflow_email_customer,
	array(
		'subject' => "Hello\nBcc: somebody@example.test",
		'body'    => 'Body.',
	),
	$recoveryflow_email_vars,
	$recoveryflow_mail_settings
);

ok( 'a subject carrying a newline is composed, not refused', $recoveryflow_injected instanceof Email_Message );
ok(
	'but the newline is gone, so a header cannot be smuggled into it',
	$recoveryflow_injected instanceof Email_Message && false === strpos( $recoveryflow_injected->subject, "\n" )
);

// The refusals. Each is something only the merchant can fix, so the step fails
// rather than retrying the same refusal three times.
foreach (
	array(
		'no_subject' => array(
			'subject' => '   ',
			'body'    => 'Body.',
		),
		'no_body'    => array(
			'subject' => 'Subject',
			'body'    => '',
		),
	) as $recoveryflow_expected => $recoveryflow_bad
) {
	$recoveryflow_refusal = $recoveryflow_email_composer->compose(
		Recovery_Journey::from_row( array( 'id' => 9100 ) ),
		$recoveryflow_email_customer,
		$recoveryflow_bad,
		$recoveryflow_email_vars,
		$recoveryflow_mail_settings
	);

	ok( "a step with {$recoveryflow_expected} is refused", $recoveryflow_refusal instanceof WP_Error );
	check(
		"and the refusal is named {$recoveryflow_expected} rather than a generic failure",
		$recoveryflow_refusal instanceof WP_Error ? $recoveryflow_refusal->get_error_code() : '',
		$recoveryflow_expected
	);
}

// Somebody with no address at all. Eligibility should have stopped this long
// before here; it is re-asked because this is the last place that can.
$recoveryflow_no_address = $recoveryflow_email_composer->compose(
	Recovery_Journey::from_row( array( 'id' => 9100 ) ),
	Customer::from_row( array( 'id' => 9002 ) ),
	$recoveryflow_step_with,
	$recoveryflow_email_vars,
	$recoveryflow_mail_settings
);

ok( 'a customer with no email address is refused rather than written to', $recoveryflow_no_address instanceof WP_Error );
check( 'and says so', $recoveryflow_no_address instanceof WP_Error ? $recoveryflow_no_address->get_error_code() : '', 'no_email' );

/*
 * THE ONE THAT MATTERS MOST. A site with no postal address settled cannot
 * produce a lawful footer, and a message without one must not leave -- however
 * it got this far. Rule_Set refuses the channel for the same reasons, so
 * reaching here is a fault rather than a configuration state, and it is checked
 * anyway because the cost of the two mistakes is not remotely equal.
 */
$recoveryflow_unlawful = $recoveryflow_email_composer->compose(
	Recovery_Journey::from_row( array( 'id' => 9100 ) ),
	$recoveryflow_email_customer,
	$recoveryflow_step_with,
	$recoveryflow_email_vars,
	array( 'channel_email_enabled' => true )
);

ok( 'a message that could carry no lawful footer is not composed at all', $recoveryflow_unlawful instanceof WP_Error );
check( 'and names the footer as the reason', $recoveryflow_unlawful instanceof WP_Error ? $recoveryflow_unlawful->get_error_code() : '', 'no_footer' );

// An unsubscribe link that is missing is the same refusal, from the other side.
$recoveryflow_no_link = $recoveryflow_email_composer->compose(
	Recovery_Journey::from_row( array( 'id' => 9100 ) ),
	$recoveryflow_email_customer,
	$recoveryflow_step_with,
	new Variable_Context( array( 'customer.first_name' => 'Ada' ) ),
	$recoveryflow_mail_settings
);

ok( 'and so is a message with no unsubscribe link to offer', $recoveryflow_no_link instanceof WP_Error );

// ---------------------------------------------------------------------------
// Handing it to WordPress, and learning why when that fails.
// ---------------------------------------------------------------------------

$GLOBALS['recoveryflow_mail_sent']   = array();
$GLOBALS['recoveryflow_mail_result'] = true;

$recoveryflow_mailer = new Email_Sender( $plugin->logger() );
$recoveryflow_message = new Email_Message( 'ada@example.test', 'Subject', "Body\n\n-- \nAddress" );

ok( 'an accepted message reports success', true === $recoveryflow_mailer->send( $recoveryflow_message, 9100 ) );
check( 'and really went through wp_mail rather than being reported sent', count( $GLOBALS['recoveryflow_mail_sent'] ), 1 );
check( 'with the composed body, byte for byte', $GLOBALS['recoveryflow_mail_sent'][0]['message'], "Body\n\n-- \nAddress" );

/*
 * wp_mail() answers false and says nothing. The reason arrives separately, on
 * wp_mail_failed, and capturing it is the difference between a merchant whose
 * SMTP credentials expired fixing it this morning and noticing in a fortnight.
 */
$GLOBALS['recoveryflow_mail_result'] = 'fail:smtp_auth_failed';

$recoveryflow_failed = $recoveryflow_mailer->send( $recoveryflow_message, 9100 );

ok( 'a refused message reports failure', $recoveryflow_failed instanceof WP_Error );
check(
	'and carries the reason WordPress gave rather than a generic one',
	$recoveryflow_failed instanceof WP_Error ? $recoveryflow_failed->get_error_code() : '',
	'smtp_auth_failed'
);

// A refusal with no reason at all is still a refusal, and still says so.
$GLOBALS['recoveryflow_mail_result'] = false;

$recoveryflow_silent = $recoveryflow_mailer->send( $recoveryflow_message, 9100 );

ok( 'a message refused with no explanation still fails', $recoveryflow_silent instanceof WP_Error );
check(
	'under a reason of its own rather than the last one that happened',
	$recoveryflow_silent instanceof WP_Error ? $recoveryflow_silent->get_error_code() : '',
	'mail_refused'
);

// A transport that raises rather than returning: some SMTP plugins do.
$GLOBALS['recoveryflow_mail_result'] = 'throw';

$recoveryflow_thrown = $recoveryflow_mailer->send( $recoveryflow_message, 9100 );

ok( 'a transport that throws does not take the whole pass down with it', $recoveryflow_thrown instanceof WP_Error );
check( 'and is reported as an exception rather than a refusal', $recoveryflow_thrown instanceof WP_Error ? $recoveryflow_thrown->get_error_code() : '', 'mail_exception' );

/*
 * The listener is attached for exactly the duration of one send. Left attached
 * it would collect the failures of every other email the site sends -- order
 * confirmations, password resets -- and log somebody else's recipient into this
 * plugin's tables.
 */
$GLOBALS['recoveryflow_mail_result'] = true;

do_action( 'wp_mail_failed', new WP_Error( 'somebody_elses_problem', 'Not ours' ) );

ok(
	'a failure from somebody else\'s email is not picked up afterwards',
	true === $recoveryflow_mailer->send( $recoveryflow_message, 9100 )
);

/*
 * And the property that assertion CANNOT see, which is why this one is here.
 * Because the sender clears its captured failure at the start of every send, a
 * listener left attached is invisible from the outside -- the mutation that
 * removed both remove_action() calls passed the whole suite. What it really
 * breaks is accumulation: one closure per send, every one of them firing for
 * every other email the site sends for the rest of the request. So the count is
 * what gets asserted.
 */
$recoveryflow_listeners_before = count( $GLOBALS['__actions']['wp_mail_failed'] ?? array() );

$recoveryflow_mailer->send( $recoveryflow_message, 9100 );
$recoveryflow_mailer->send( $recoveryflow_message, 9100 );

check(
	'and no listener is left behind by a send, however many are sent',
	count( $GLOBALS['__actions']['wp_mail_failed'] ?? array() ),
	$recoveryflow_listeners_before
);

$GLOBALS['recoveryflow_mail_result'] = true;

// ---------------------------------------------------------------------------
// The action, and the plan it does NOT need.
// ---------------------------------------------------------------------------

$recoveryflow_email_action = $plugin->steps()->action( 'wacr.send_email' );

ok( 'the email action is registered', $recoveryflow_email_action instanceof Send_Email );
check( 'and says it sends over email', $recoveryflow_email_action instanceof Send_Email ? $recoveryflow_email_action->get_channel() : '', Channel::EMAIL );

/*
 * Email costs the merchant nothing, leaves through their own mail
 * configuration and never touches WA.cr's API, so a workflow built from email
 * steps runs on a workspace with no API key at all. Before this the rule was
 * written as "anything that is not a hand-off needs the developer API", which
 * would have blocked every email-only workflow from being saved on the Lite
 * path the moment email could be sent.
 */
$recoveryflow_email_only = array(
	'steps' => array(
		array(
			'type'    => Workflow_Definition::TYPE_ACTION,
			'do'      => 'wacr.send_email',
			'channel' => Workflow_Definition::CHANNEL_EMAIL,
			'with'    => array(
				'subject' => 'Hello',
				'body'    => 'Body.',
			),
		),
	),
);

ok( 'a workflow that only sends email does not need the developer API', ! Workflow_Definition::needs_developer_api( $recoveryflow_email_only ) );
ok(
	'while one that sends a WA.cr template still does',
	Workflow_Definition::needs_developer_api(
		array(
			'steps' => array(
				array(
					'type' => Workflow_Definition::TYPE_ACTION,
					'do'   => 'wacr.send_template',
				),
			),
		)
	)
);

// ---------------------------------------------------------------------------
// Building an email step on the screen.
// ---------------------------------------------------------------------------

$recoveryflow_email_post = array(
	'workflow_name' => 'Email only',
	'step'          => array(
		array(
			'type'    => 'action',
			'do'      => 'wacr.send_email',
			'subject' => 'Your basket, {{ customer.first_name }}',
			'body'    => "Hello.\n\nHere is your basket: {{ recovery.recovery_url }}",
		),
	),
);

$recoveryflow_email_saved = Workflow_Form::read( $recoveryflow_email_post, $plugin->steps() );

check( 'an email step stores the subject the merchant typed', $recoveryflow_email_saved['steps'][0]['with']['subject'] ?? '', 'Your basket, {{ customer.first_name }}' );
ok( 'and the body', false !== strpos( (string) ( $recoveryflow_email_saved['steps'][0]['with']['body'] ?? '' ), 'Here is your basket' ) );
check( 'and the channel comes from the action, without the form being asked', $recoveryflow_email_saved['steps'][0]['channel'] ?? '', Workflow_Definition::CHANNEL_EMAIL );

/*
 * The forged post, the same shape as the header_media_image one: the editor
 * renders no channel field at all, so anything arriving in that slot came from
 * somebody posting straight to admin-post.php. It must not be able to store a
 * step whose channel and action disagree -- which the engine would then refuse
 * at three in the morning, on a workflow the merchant was shown as valid.
 */
$recoveryflow_forged = $recoveryflow_email_post;
$recoveryflow_forged['step'][0]['channel'] = 'whatsapp';

check(
	'a forged channel posted past the form is ignored, not stored',
	Workflow_Form::read( $recoveryflow_forged, $plugin->steps() )['steps'][0]['channel'] ?? '',
	Workflow_Definition::CHANNEL_EMAIL
);

$recoveryflow_forged_other = array(
	'workflow_name' => 'Forged the other way',
	'step'          => array(
		array(
			'type'     => 'action',
			'do'       => 'wacr.send_template',
			'channel'  => 'email',
			'template' => 'cart_reminder',
		),
	),
);

check(
	'and neither is one claiming a WA.cr template goes by email',
	Workflow_Form::read( $recoveryflow_forged_other, $plugin->steps() )['steps'][0]['channel'] ?? '',
	Workflow_Definition::CHANNEL_WHATSAPP
);

// A template's own arguments must not be written onto an email step, and the
// other way about: changing a step's action and submitting before the fields
// are redrawn posts both sets at once.
$recoveryflow_mixed = array(
	'workflow_name' => 'Mixed',
	'step'          => array(
		array(
			'type'     => 'action',
			'do'       => 'wacr.send_email',
			'subject'  => 'S',
			'body'     => 'B',
			'template' => 'cart_reminder',
		),
	),
);

$recoveryflow_mixed_read = Workflow_Form::read( $recoveryflow_mixed, $plugin->steps() )['steps'][0]['with'] ?? array();

ok( 'an email step keeps its subject', isset( $recoveryflow_mixed_read['subject'] ) );
ok( 'and is not given a WhatsApp template it has no use for', ! isset( $recoveryflow_mixed_read['template'] ) );

// An empty subject is not stored as an empty string: the composer refuses on
// the value being absent, and a stored blank would be a step that looks
// configured on the screen and refuses every time it runs.
$recoveryflow_blank = $recoveryflow_email_post;
$recoveryflow_blank['step'][0]['subject'] = '   ';

ok(
	'a blank subject is left out rather than stored as emptiness',
	! isset( Workflow_Form::read( $recoveryflow_blank, $plugin->steps() )['steps'][0]['with']['subject'] )
);

// And the screen the merchant types it on. There is no template picker here and
// nothing to approve -- the whole difference from a WhatsApp step is that the
// merchant writes the words.
$recoveryflow_rows_kept = $GLOBALS['wpdb']->rows;

$GLOBALS['wpdb']->rows = array(
	'recoveryflow_workflows' => array(
		array(
			'id'              => 77,
			'name'            => 'Email only',
			'slug'            => 'email-only',
			'source_id'       => '',
			'status'          => 'active',
			'definition_json' => wp_json_encode(
				array(
					'name'    => 'Email only',
					'version' => 1,
					'trigger' => array(
						'event'  => Workflow_Definition::TRIGGER_EVENT,
						'source' => Workflow_Definition::ANY_SOURCE,
					),
					'steps'   => array(
						array(
							'type'    => Workflow_Definition::TYPE_ACTION,
							'do'      => 'wacr.send_email',
							'channel' => Workflow_Definition::CHANNEL_EMAIL,
							'with'    => array(
								'subject' => 'Your basket',
								'body'    => 'Hello there.',
							),
						),
					),
				)
			),
			'definition_hash' => '',
			'version'         => 1,
			'is_default'      => 0,
			'created_at'      => '2026-01-01 00:00:00',
			'updated_at'      => '2026-01-01 00:00:00',
		),
	),
);

$_GET['workflow']        = 77;
$recoveryflow_email_html = recoveryflow_render_screen( array( $plugin->admin_workflow(), 'render' ) );
unset( $_GET['workflow'] );

ok( 'the editor draws a subject field for an email step', false !== strpos( $recoveryflow_email_html, 'name="step[0][subject]"' ) );
ok( 'and a body to write the message in', false !== strpos( $recoveryflow_email_html, 'name="step[0][body]"' ) );
ok( 'with what the merchant stored already in them', false !== strpos( $recoveryflow_email_html, 'Your basket' ) && false !== strpos( $recoveryflow_email_html, 'Hello there.' ) );
ok( 'and no WhatsApp template picker, which an email has no use for', false === strpos( $recoveryflow_email_html, 'name="step[0][template]"' ) );

// The placeholders are listed rather than left to be guessed, and every one
// listed is one the renderer will actually substitute.
$recoveryflow_listed = 0;

foreach ( Variable_Context::keys() as $recoveryflow_key ) {
	if ( false !== strpos( $recoveryflow_email_html, esc_html( '{{ ' . $recoveryflow_key . ' }}' ) ) ) {
		++$recoveryflow_listed;
	}
}

check( 'every placeholder the renderer knows is offered on the screen', $recoveryflow_listed, count( Variable_Context::keys() ) );

// The two things the merchant must NOT be asked to type, because they are
// appended to every message and cannot be removed.
ok(
	'the screen says the address and unsubscribe are added automatically',
	false !== strpos( $recoveryflow_email_html, esc_html__( 'Plain text. Your postal address and an unsubscribe link are added to the foot of every message automatically -- do not type them here, and they cannot be removed.', 'kdc-wacr-recoveryflow' ) )
);

// It still ships no JavaScript. A merchant configuring the one feature that
// messages their customers must not lose it to a blocked script.
ok( 'and the editor still ships no inline script', false === stripos( $recoveryflow_email_html, '<script' ) );

$GLOBALS['wpdb']->rows = $recoveryflow_rows_kept;

// ---------------------------------------------------------------------------
// What follows a link in an email, and what it must not be able to do.
// ---------------------------------------------------------------------------

foreach ( array( 'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)', 'YahooMailProxy; https://help.yahoo.com/kb/yahoo-mail-proxy-SLN28749.html', 'Mozilla/5.0 (compatible; BingPreview/1.0b)' ) as $recoveryflow_ua ) {
	ok( 'a mail proxy is not counted as somebody tapping a link', User_Agent::is_link_preview( $recoveryflow_ua ) );
}

ok( 'while a person in a browser still is', ! User_Agent::is_link_preview( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1' ) );

/*
 * The honest limit, asserted so nobody later mistakes the list for coverage.
 * Corporate link scanners fetch every URL in an incoming message behind an
 * ordinary browser's user agent, and no substring can tell one from a person.
 * What makes that survivable is not the list -- it is these two properties.
 */
ok(
	'a scanner that looks like a browser is NOT recognised, and that is known rather than assumed',
	! User_Agent::is_link_preview( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' )
);

// So a click may never be the thing that says somebody replied. Only reading a
// WhatsApp conversation back does that.
$recoveryflow_controller_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Recovery/Recovery_Controller.php' );

ok(
	'the endpoint that handles a click cannot mark a journey as replied at all',
	false === strpos( $recoveryflow_controller_src, 'Journey_State::ENGAGED' )
);
ok( 'and it does record clicks, so that is a real restraint rather than a file that does nothing', false !== strpos( $recoveryflow_controller_src, 'record_click' ) );

// And the one that matters most on email, where a scanner really does open
// every link: the opt-out cannot act on a GET, so it cannot unsubscribe the
// person it was protecting. Asserted at the source of the rule.
ok( 'the opt-out still refuses to act on a GET', false !== strpos( $recoveryflow_controller_src, '\'POST\' !== $method' ) );

/*
 * The other half of that promise, and for a long time the broken half: the
 * unsubscribe must still WORK. A token is revoked and its journey goes terminal
 * the moment the customer converts, and both were being asked of both actions
 * -- so the shopper who bought was the one shopper who could not unsubscribe
 * from the mail that brought them back, while Email_Compliance refused to send
 * at all without a thirty-day unsubscribe it could no longer honour.
 *
 * Asserted on the rule itself rather than on the endpoint, in every direction,
 * because the two questions differ in exactly one clause and a test that only
 * asked the happy one would pass with the clause restored.
 */
$recoveryflow_unsub_now = '2026-01-15 12:00:00';

$recoveryflow_attempt_with = static function ( array $overrides ): Attempt {
	return Attempt::from_row(
		array_merge(
			array(
				'id'               => 1,
				'journey_id'       => 1,
				'token_hash'       => str_repeat( 'a', 64 ),
				'token_expires_at' => '2026-02-15 12:00:00',
			),
			$overrides
		)
	);
};

$recoveryflow_live_link    = $recoveryflow_attempt_with( array() );
$recoveryflow_revoked_link = $recoveryflow_attempt_with( array( 'token_revoked_at' => '2026-01-10 09:00:00' ) );
$recoveryflow_expired_link = $recoveryflow_attempt_with( array( 'token_expires_at' => '2026-01-01 09:00:00' ) );
$recoveryflow_hashless     = Attempt::from_row( array( 'id' => 1 ) );

ok(
	'a live link both restores a basket and unsubscribes',
	$recoveryflow_live_link->link_is_usable( $recoveryflow_unsub_now )
		&& $recoveryflow_live_link->opt_out_is_usable( $recoveryflow_unsub_now )
);
ok(
	'a revoked link no longer restores a basket, because that customer has already bought',
	! $recoveryflow_revoked_link->link_is_usable( $recoveryflow_unsub_now )
);
ok(
	'but the SAME revoked link still unsubscribes, which is the whole reason the two rules differ',
	$recoveryflow_revoked_link->opt_out_is_usable( $recoveryflow_unsub_now )
);
ok(
	'an expired link does neither, so the thirty days are a window and not an open door',
	! $recoveryflow_expired_link->link_is_usable( $recoveryflow_unsub_now )
		&& ! $recoveryflow_expired_link->opt_out_is_usable( $recoveryflow_unsub_now )
);
ok(
	'and an attempt that never carried a token unsubscribes nobody',
	! $recoveryflow_hashless->opt_out_is_usable( $recoveryflow_unsub_now )
);

/*
 * Whitespace-collapsed before searching: phpcbf realigns this file, and an
 * assertion that a reformat can silently stop matching is an assertion that
 * quietly stops asking.
 */
$recoveryflow_route_flat = (string) preg_replace(
	'/\s+/',
	' ',
	kdc_wacr_recoveryflow_code_only(
		kdc_wacr_recoveryflow_method_body( dirname( __DIR__ ) . '/src/Recovery/Recovery_Controller.php', 'route' )
	)
);

ok(
	'the endpoint asks the weaker question when the visitor came to unsubscribe',
	false !== strpos( $recoveryflow_route_flat, 'opt_out_is_usable' )
);
ok(
	'and refuses a finished journey only when the visitor came to restore one',
	false !== strpos( $recoveryflow_route_flat, '! $is_opt_out && $journey->is_terminal()' )
);

// The gate's rate budget is WA.cr's allowance, and email does not spend it.
// Holding a free message back because a paid channel hit its ceiling would stop
// the reminders at exactly the moment a shop is busiest.
$recoveryflow_gate_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Workflow/Send_Gate.php' );

ok(
	'the send gate only applies the WA.cr rate budget to the channel that spends it',
	false !== strpos( $recoveryflow_gate_src, 'Channel::WHATSAPP === $channel' )
);


// ---------------------------------------------------------------------------
// A gate that checks nothing must not report success.
// ---------------------------------------------------------------------------

/*
 * Two commands here passed while testing nothing, on every slice that shipped:
 * `composer test:unit` over four empty PHPUnit suites -- run by a CI job named
 * "Unit tests", so each release carried a green tick for coverage that did not
 * exist -- and `npm run a11y` over a .pa11yci.json listing no URLs, while every
 * admin screen it was meant to check landed. Both are the shape this repository
 * keeps meeting: something that describes a check, with nothing behind it. A
 * green tick is read as an answer, which makes either worse than no gate at all.
 *
 * Both are now real. The a11y command refuses an empty URL list and checks
 * twenty-four screens; the PHPUnit suites have tests in them and the CI job is
 * back -- behind a wrapper that refuses an empty suite, because PHPUnit 9 exits
 * 0 over one and cannot be told otherwise.
 *
 * What follows is what stops either half drifting back, in BOTH directions --
 * the correction that fixes one end of a pair and not the other is the same
 * class of error as the original, and this block has already had to be rewritten
 * once for exactly that reason: an assertion that the workflow explained why
 * there was no PHPUnit job outlived the absence it described.
 */

$recoveryflow_root = dirname( __DIR__ );

$recoveryflow_phpunit_tests = array();
foreach ( array( 'unit', 'integration', 'security', 'failure' ) as $recoveryflow_suite ) {
	$recoveryflow_suite_dir = $recoveryflow_root . '/tests/' . $recoveryflow_suite;
	if ( ! is_dir( $recoveryflow_suite_dir ) ) {
		continue;
	}

	$recoveryflow_suite_walk = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $recoveryflow_suite_dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $recoveryflow_suite_walk as $recoveryflow_suite_file ) {
		if ( $recoveryflow_suite_file->isFile() && 'Test.php' === substr( $recoveryflow_suite_file->getFilename(), -8 ) ) {
			$recoveryflow_phpunit_tests[] = $recoveryflow_suite_file->getPathname();
		}
	}
}

$recoveryflow_ci_src = (string) file_get_contents( $recoveryflow_root . '/.github/workflows/ci.yml' );

/*
 * A `run:` key, not a mention. The removal left a comment explaining itself,
 * and matching that comment would read the explanation as the thing it
 * explains -- which is how the job came to be trusted in the first place.
 */
$recoveryflow_ci_runs_phpunit = 1 === preg_match( '/^[ \t]*-?[ \t]*run:.*(test:unit|test:all|phpunit)/mi', $recoveryflow_ci_src );

ok(
	'CI runs PHPUnit if and only if a PHPUnit test exists for it to run',
	( array() !== $recoveryflow_phpunit_tests ) === $recoveryflow_ci_runs_phpunit
);

/*
 * The other end of the pair, and it had to change when the job came back.
 *
 * While there was no job, this asserted that the workflow SAID WHY -- so the
 * next reader found a reason rather than an unexplained absence. That assertion
 * is now about a thing that no longer exists, and leaving it would be the exact
 * error this section is about: a check that passes while describing something
 * else. What matters now is not why the job is absent but why it can be trusted
 * present.
 *
 * PHPUnit 9 exits 0 over an empty suite and cannot be told not to: there is no
 * failOnEmptyTestSuite in this version, and setting one is silently ignored
 * (verified, not assumed -- it was tried first). PHPUnit 10 can, and needs PHP
 * 8.1, while this plugin supports 8.0. So the refusal lives in a wrapper, and a
 * job calling phpunit directly would be the original defect restored.
 */
ok(
	'CI runs PHPUnit through the guard that refuses an empty suite',
	1 === preg_match( '/^[ \t]*-?[ \t]*run:.*bin\/phpunit\.sh/mi', $recoveryflow_ci_src )
);
ok(
	'and never calls phpunit directly, which is the command that reported success over nothing',
	0 === preg_match( '/^[ \t]*-?[ \t]*run:[ \t]*(vendor\/bin\/)?phpunit\b/mi', $recoveryflow_ci_src )
);

$recoveryflow_phpunit_guard = (string) file_get_contents( $recoveryflow_root . '/bin/phpunit.sh' );

ok( 'the guard exists and is readable', strlen( $recoveryflow_phpunit_guard ) > 200 );
ok(
	'the guard recognises PHPUnit own wording for an empty suite',
	false !== strpos( $recoveryflow_phpunit_guard, 'No tests executed!' )
);
ok(
	'and leaves non-zero when it finds one, rather than passing it on',
	false !== strpos( $recoveryflow_phpunit_guard, 'status=1' )
);

/*
 * The composer scripts go through it too. A developer running `composer
 * test:unit` locally over an emptied suite must see the same refusal CI sees,
 * or the two disagree about whether the suite is real.
 */
$recoveryflow_composer = json_decode( (string) file_get_contents( $recoveryflow_root . '/composer.json' ), true );
$recoveryflow_scripts  = is_array( $recoveryflow_composer ) ? (array) ( $recoveryflow_composer['scripts'] ?? array() ) : array();

foreach ( $recoveryflow_scripts as $recoveryflow_name => $recoveryflow_cmd ) {
	if ( 0 !== strpos( (string) $recoveryflow_name, 'test' ) || '@' === substr( (string) $recoveryflow_cmd, 0, 1 ) ) {
		continue;
	}

	ok(
		sprintf( 'composer %s goes through the guard', $recoveryflow_name ),
		false !== strpos( (string) $recoveryflow_cmd, 'bin/phpunit.sh' )
	);
}

/*
 * Every suite phpunit.xml.dist declares must have a test in it. This is what
 * stops the empty-suite problem coming back by the other door: declaring a
 * suite nobody has written yet, which reads as coverage in the config and in
 * the docs while proving nothing.
 */
$recoveryflow_phpunit_config = simplexml_load_file( $recoveryflow_root . '/phpunit.xml.dist' );

ok( 'the phpunit config parses', false !== $recoveryflow_phpunit_config );

if ( false !== $recoveryflow_phpunit_config ) {
	foreach ( $recoveryflow_phpunit_config->testsuites->testsuite as $recoveryflow_declared ) {
		$recoveryflow_suite_name = (string) $recoveryflow_declared['name'];
		$recoveryflow_has_test   = false;

		foreach ( $recoveryflow_phpunit_tests as $recoveryflow_test_path ) {
			if ( false !== strpos( $recoveryflow_test_path, '/tests/' . $recoveryflow_suite_name . '/' ) ) {
				$recoveryflow_has_test = true;

				break;
			}
		}

		ok( sprintf( 'the declared %s suite has a test in it', $recoveryflow_suite_name ), $recoveryflow_has_test );
	}
}

$recoveryflow_package = json_decode( (string) file_get_contents( $recoveryflow_root . '/package.json' ), true );

ok(
	'npm run a11y goes through the guard instead of straight to pa11y-ci',
	is_array( $recoveryflow_package ) && false !== strpos( (string) ( $recoveryflow_package['scripts']['a11y'] ?? '' ), 'bin/a11y.sh' )
);

$recoveryflow_a11y_src = (string) file_get_contents( $recoveryflow_root . '/bin/a11y.sh' );

/*
 * Anchored on the refusal's own words, not on `exit 1` -- the script exits 1
 * for a missing config file too, and matching that one let the refusal itself
 * be deleted with this assertion still green. A survivor found it; the weakness
 * was in the assertion, not the mutation.
 */
$recoveryflow_a11y_refusal = strpos( $recoveryflow_a11y_src, 'lists no URLs' );
$recoveryflow_a11y_run     = strpos( $recoveryflow_a11y_src, 'npx pa11y-ci --config' );

// Both halves must be PRESENT before their order means anything: `false < 40`
// is true in PHP, so an ordering test passes vacuously when the first is gone.
ok( 'the a11y guard has both a refusal and a run in it', false !== $recoveryflow_a11y_refusal && false !== $recoveryflow_a11y_run );
ok( 'and it refuses before it would run anything', $recoveryflow_a11y_refusal < $recoveryflow_a11y_run );

// And the refusal actually leaves non-zero. Saying "not built" on the way to
// exit 0 is the failure this whole section exists to stop.
ok(
	'and the refusal exits non-zero rather than printing a warning and carrying on',
	false !== $recoveryflow_a11y_refusal
		&& false !== $recoveryflow_a11y_run
		&& false !== strpos( substr( $recoveryflow_a11y_src, $recoveryflow_a11y_refusal, $recoveryflow_a11y_run - $recoveryflow_a11y_refusal ), 'exit 1' )
);

$recoveryflow_pa11y_config = json_decode( (string) file_get_contents( $recoveryflow_root . '/.pa11yci.json' ), true );
$recoveryflow_pa11y_config = is_array( $recoveryflow_pa11y_config ) ? $recoveryflow_pa11y_config : array();
$recoveryflow_pa11y_list   = (array) ( $recoveryflow_pa11y_config['urls'] ?? array() );
$recoveryflow_pa11y_urls   = count( $recoveryflow_pa11y_list );

$recoveryflow_a11y_doc = (string) file_get_contents( $recoveryflow_root . '/docs/accessibility.md' );

/*
 * The other direction of the same pair. While the URL list is empty the docs
 * must say the suite is not built; the moment somebody lists a screen, this
 * fails and the "NOT BUILT" note has to go. A document describing a run that
 * does not happen is the dead contract this whole section is about.
 */
ok(
	'the accessibility docs claim a working pa11y run if and only if there is one',
	( 0 === $recoveryflow_pa11y_urls ) === ( false !== strpos( $recoveryflow_a11y_doc, 'NOT BUILT' ) )
);


// ---------------------------------------------------------------------------
// The accessibility suite checks every screen, and keeps meaning what it says.
// ---------------------------------------------------------------------------

/*
 * A suite that checks most of the screens is the same defect as one that checks
 * none, only harder to notice: it reports a pass, and the screen nobody listed
 * is the screen nobody looked at. Every slice here added screens; the list has
 * to be able to fail when the next one does.
 */

$recoveryflow_pa11y_text = '';

foreach ( $recoveryflow_pa11y_list as $recoveryflow_entry ) {
	$recoveryflow_pa11y_text .= is_array( $recoveryflow_entry )
		? (string) ( $recoveryflow_entry['url'] ?? '' ) . ' '
		: (string) $recoveryflow_entry . ' ';
}

$recoveryflow_screen_src = (string) file_get_contents( $recoveryflow_root . '/src/Admin/Screen.php' );

preg_match_all( "/public const [A-Z_]+ = '([a-z0-9-]+)';/", $recoveryflow_screen_src, $recoveryflow_screen_slugs );

$recoveryflow_missing_screens = array();

foreach ( (array) ( $recoveryflow_screen_slugs[1] ?? array() ) as $recoveryflow_slug ) {
	if ( false === strpos( $recoveryflow_pa11y_text, 'page=' . $recoveryflow_slug ) ) {
		$recoveryflow_missing_screens[] = $recoveryflow_slug;
	}
}

ok(
	'every admin screen this plugin registers is in the accessibility run: ' . ( array() === $recoveryflow_missing_screens ? 'all of them' : 'MISSING ' . implode( ', ', $recoveryflow_missing_screens ) ),
	array() === $recoveryflow_missing_screens
);

/*
 * Every settings tab too. The tabs are separate documents with separate
 * settings groups, and six of the seven were added after the first was written.
 */
$recoveryflow_missing_tabs = array();

foreach ( array_keys( Settings_Schema::tabs() ) as $recoveryflow_tab ) {
	if ( false === strpos( $recoveryflow_pa11y_text, 'tab=' . $recoveryflow_tab ) ) {
		$recoveryflow_missing_tabs[] = (string) $recoveryflow_tab;
	}
}

ok(
	'every settings tab is in the accessibility run: ' . ( array() === $recoveryflow_missing_tabs ? 'all of them' : 'MISSING ' . implode( ', ', $recoveryflow_missing_tabs ) ),
	array() === $recoveryflow_missing_tabs
);

/*
 * THE GATE IS AA. The requirement is AA as the minimum with AAA where it can be
 * reached, and the two are not interchangeable: running the gate at AAA would
 * fail on 380 findings that are core WordPress's own colours on core's own
 * components, which this plugin cannot change -- and a gate that cannot go
 * green is turned off within a week. AAA is run separately and reported.
 */
ok(
	'the accessibility gate is set to AA, which is the level this plugin commits to',
	'WCAG2AA' === (string) ( $recoveryflow_pa11y_config['defaults']['standard'] ?? '' )
);

ok(
	'and AAA is still run and reported, rather than dropped for being noisy',
	false !== strpos( $recoveryflow_a11y_src, 'build_config WCAG2AAA' )
);

/*
 * The axe runner is OURS. pa11y's bundled one reports axe's "could not
 * determine" results as errors -- 28 contrast failures on core <select>
 * elements measured at about 17:1. Going back to the bundled runner would not
 * fail anything; it would just bury the real findings, which is why this is
 * asserted rather than left to whoever next edits the config.
 */
$recoveryflow_pa11y_runners = (array) ( $recoveryflow_pa11y_config['defaults']['runners'] ?? array() );

ok(
	'the accessibility run uses this repository\'s axe runner, not the bundled one',
	in_array( 'tests/a11y/axe-runner.js', $recoveryflow_pa11y_runners, true )
		&& ! in_array( 'axe', $recoveryflow_pa11y_runners, true )
);

ok(
	'and that runner tells axe violations apart from what axe could not decide',
	false !== strpos( (string) file_get_contents( $recoveryflow_root . '/tests/a11y/axe-runner.js' ), "result.incomplete.map(issue => process(issue, 'warning'))" )
);

/*
 * rootElement on the admin screens is load-bearing. wp-admin is behind a
 * capability check and a login form passes an accessibility check cleanly, so a
 * run that had quietly lost its session would report twenty green screens it
 * never saw. Requiring an element that only exists inside wp-admin means such a
 * run fails instead. Verified by mutation: point it at a selector that does not
 * exist and 19 of 22 URLs fail.
 */
$recoveryflow_admin_entries = 0;
$recoveryflow_admin_rooted  = 0;

foreach ( $recoveryflow_pa11y_list as $recoveryflow_entry ) {
	$recoveryflow_entry_url = is_array( $recoveryflow_entry ) ? (string) ( $recoveryflow_entry['url'] ?? '' ) : (string) $recoveryflow_entry;

	if ( false === strpos( $recoveryflow_entry_url, 'wp-admin' ) ) {
		continue;
	}

	++$recoveryflow_admin_entries;

	if ( is_array( $recoveryflow_entry ) && '' !== (string) ( $recoveryflow_entry['rootElement'] ?? '' ) ) {
		++$recoveryflow_admin_rooted;
	}
}

ok(
	'every admin screen in the run is pinned to an element only wp-admin has, so a lost session fails the run',
	$recoveryflow_admin_entries > 0 && $recoveryflow_admin_entries === $recoveryflow_admin_rooted
);

/*
 * Two rules are ignored, and both were verified against the browser's own
 * accessibility tree rather than argued away. The pair asserted here is that
 * the config and the document agree: an ignore nobody wrote down is an
 * unexplained hole, and a document explaining an ignore that is no longer
 * there sends the next reader looking for a problem that was fixed.
 */
$recoveryflow_pa11y_ignored = (array) ( $recoveryflow_pa11y_config['defaults']['ignore'] ?? array() );

$recoveryflow_unexplained = array();

foreach ( $recoveryflow_pa11y_ignored as $recoveryflow_rule ) {
	if ( false === strpos( $recoveryflow_a11y_doc, (string) $recoveryflow_rule ) ) {
		$recoveryflow_unexplained[] = (string) $recoveryflow_rule;
	}
}

ok(
	'every rule the accessibility run ignores is written down and justified in the docs: ' . ( array() === $recoveryflow_unexplained ? 'all of them' : 'UNEXPLAINED ' . implode( ', ', $recoveryflow_unexplained ) ),
	array() === $recoveryflow_unexplained
);

/*
 * And the other direction: the document must not go on explaining an exception
 * that has been removed.
 */
preg_match_all( '/`(WCAG2AA\.[A-Za-z0-9_.,]+)`/', $recoveryflow_a11y_doc, $recoveryflow_doc_rules );

$recoveryflow_stale = array();

foreach ( array_unique( (array) ( $recoveryflow_doc_rules[1] ?? array() ) ) as $recoveryflow_rule ) {
	if ( ! in_array( $recoveryflow_rule, $recoveryflow_pa11y_ignored, true ) ) {
		$recoveryflow_stale[] = (string) $recoveryflow_rule;
	}
}

ok(
	'and the docs do not explain an exception the run no longer makes: ' . ( array() === $recoveryflow_stale ? 'none stale' : 'STALE ' . implode( ', ', $recoveryflow_stale ) ),
	array() === $recoveryflow_stale
);

/*
 * The keyboard pass. It is the half an automated document scan cannot do -- a
 * control that takes focus while invisible reads as clean markup -- and the
 * reason it never got done is that doing it by hand across twenty-two screens
 * produces one sentence indistinguishable from one written without doing it.
 */
ok(
	'there is a keyboard pass, and it is wired to a command rather than described',
	is_array( $recoveryflow_package )
		&& false !== strpos( (string) ( $recoveryflow_package['scripts']['a11y:keyboard'] ?? '' ), 'bin/keyboard.sh' )
		&& is_file( $recoveryflow_root . '/tests/a11y/keyboard.js' )
);

/*
 * The seeder is what puts rows on the screens. An empty WP_List_Table renders
 * none of the status badges, none of the masked contact columns and none of the
 * row actions the checklist makes claims about, so a run against an unseeded
 * site checks markup no merchant ever sees and passes.
 */
ok(
	'the accessibility run seeds the screens rather than checking whatever is there',
	false !== strpos( $recoveryflow_a11y_src, 'RECOVERYFLOW_A11Y_SEED' )
		&& is_file( $recoveryflow_root . '/tests/a11y/seed.php' )
);

/*
 * And the seeder clears the CONSENT LEDGER, not only the journeys. Checking the
 * opt-out page properly means pressing its button, and an opt-out is recorded
 * against the identity, which outlives the journey -- so a clear-out that
 * missed it left that person suppressed and the next seeding enrolled one
 * fewer. The queue lost a row per run while every gate stayed green.
 */
$recoveryflow_a11y_seed_src = (string) file_get_contents( $recoveryflow_root . '/tests/a11y/seed.php' );

ok(
	'and clearing the seed data removes the consents too, so the suite does not thin out run by run',
	false !== strpos( $recoveryflow_a11y_seed_src, "'consents'" )
		&& false !== strpos( $recoveryflow_a11y_seed_src, 'identities' )
);


// ---------------------------------------------------------------------------
// The listing describes the integrations that exist.
// ---------------------------------------------------------------------------

/*
 * readme.txt IS the WordPress.org listing, and its "Supported integrations"
 * list is where a merchant decides whether this plugin does what they need.
 * It said "**Gravity Forms**: planned." for an entire release line while
 * src/Integration/GravityForms/ held seven classes, the changelog on the same
 * page announced the adapter, and the Integrations screen offered its settings.
 * Nothing failed, because nothing had ever read that list.
 *
 * The pairing is asserted in both directions. One direction alone is worth
 * little: checking only that a built adapter is NAMED would pass on the word
 * "planned" beside it, and checking only that nothing is wrongly called planned
 * would pass on a listing that had stopped mentioning the adapter at all.
 */

$recoveryflow_readme = (string) file_get_contents( $recoveryflow_root . '/readme.txt' );

/*
 * What is built is read off the filesystem rather than from a list kept here,
 * because a list kept here is one more thing to forget to update -- which is
 * the defect this gate exists to catch. Custom/ is excluded: Example_Source is
 * documentation that never registers, and listing it would be a lie of the
 * opposite kind.
 */
$recoveryflow_adapters = array();

foreach ( (array) glob( $recoveryflow_root . '/src/Integration/*/Source.php' ) as $recoveryflow_adapter_file ) {
	$recoveryflow_adapter_src = (string) file_get_contents( (string) $recoveryflow_adapter_file );

	if ( false !== strpos( (string) $recoveryflow_adapter_file, '/Custom/' ) ) {
		continue;
	}

	// The human name the admin screens show, which is the name the listing uses.
	if ( preg_match( "/function get_name\(\).*?return __\( '([^']+)'/s", $recoveryflow_adapter_src, $recoveryflow_adapter_name ) ) {
		$recoveryflow_adapters[] = $recoveryflow_adapter_name[1];
	}
}

ok(
	'the adapters are found by reading src/Integration, not from a list in this file: ' . implode( ', ', $recoveryflow_adapters ),
	count( $recoveryflow_adapters ) >= 2
);

/*
 * Only the "Supported integrations" section counts. The changelog further down
 * the same file names every adapter too, so searching the whole document would
 * pass on a listing whose integration list had gone stale -- which is exactly
 * the state this gate was written to refuse.
 */
$recoveryflow_list_start = strpos( $recoveryflow_readme, '= Supported integrations =' );
$recoveryflow_list_end   = false === $recoveryflow_list_start
	? false
	: strpos( $recoveryflow_readme, "\n=", $recoveryflow_list_start + 26 );
$recoveryflow_list       = false === $recoveryflow_list_start
	? ''
	: substr( $recoveryflow_readme, $recoveryflow_list_start, ( false === $recoveryflow_list_end ? strlen( $recoveryflow_readme ) : $recoveryflow_list_end ) - $recoveryflow_list_start );

ok(
	'readme.txt has a supported-integrations section for the pairing to be checked against',
	'' !== $recoveryflow_list && false !== strpos( $recoveryflow_list, 'WooCommerce' )
);

$recoveryflow_unlisted = array();
$recoveryflow_miscalled = array();

foreach ( $recoveryflow_adapters as $recoveryflow_adapter ) {
	$recoveryflow_line = '';

	foreach ( explode( "\n", $recoveryflow_list ) as $recoveryflow_row ) {
		if ( false !== strpos( $recoveryflow_row, '**' . $recoveryflow_adapter . '**' ) ) {
			$recoveryflow_line = $recoveryflow_row;
			break;
		}
	}

	if ( '' === $recoveryflow_line ) {
		$recoveryflow_unlisted[] = $recoveryflow_adapter;
		continue;
	}

	if ( false !== stripos( $recoveryflow_line, 'planned' ) ) {
		$recoveryflow_miscalled[] = $recoveryflow_adapter;
	}
}

ok(
	'every integration this plugin ships is named in the listing: ' . ( array() === $recoveryflow_unlisted ? 'all of them' : 'MISSING ' . implode( ', ', $recoveryflow_unlisted ) ),
	array() === $recoveryflow_unlisted
);

ok(
	'and none of them is called planned: ' . ( array() === $recoveryflow_miscalled ? 'none' : 'WRONGLY PLANNED ' . implode( ', ', $recoveryflow_miscalled ) ),
	array() === $recoveryflow_miscalled
);

/*
 * The other direction. Anything the listing still calls planned must have no
 * adapter behind it -- a merchant told a thing is coming, who could have had it
 * today, is the same defect the other way round.
 */
$recoveryflow_planned_but_built = array();

foreach ( explode( "\n", $recoveryflow_list ) as $recoveryflow_row ) {
	if ( false === stripos( $recoveryflow_row, 'planned' ) ) {
		continue;
	}

	foreach ( $recoveryflow_adapters as $recoveryflow_adapter ) {
		if ( false !== strpos( $recoveryflow_row, '**' . $recoveryflow_adapter . '**' ) ) {
			$recoveryflow_planned_but_built[] = $recoveryflow_adapter;
		}
	}
}

ok(
	'nothing the listing calls planned has an adapter already shipping: ' . ( array() === $recoveryflow_planned_but_built ? 'none' : 'ALREADY BUILT ' . implode( ', ', $recoveryflow_planned_but_built ) ),
	array() === $recoveryflow_planned_but_built
);

/*
 * And the roadmap paragraph in README.md is held to the same rule, because it
 * is the other place somebody reads to find out what exists. It named Gravity
 * Forms as future work for the whole of the release line that shipped it.
 */
$recoveryflow_readme_md = (string) file_get_contents( $recoveryflow_root . '/README.md' );
$recoveryflow_status_at = strpos( $recoveryflow_readme_md, '## Status' );
$recoveryflow_status    = false === $recoveryflow_status_at
	? ''
	: substr( $recoveryflow_readme_md, $recoveryflow_status_at, 1200 );

ok(
	'README.md does not describe a built integration as still to come',
	'' !== $recoveryflow_status
		&& ! preg_match( '/(then|planned|upcoming|to come)[^.]*Gravity Forms/i', $recoveryflow_status )
);


// ---------------------------------------------------------------------------
// No screen puts a form inside a form.
// ---------------------------------------------------------------------------

/*
 * The settings screen wraps every card on a tab in one form posting to
 * options.php. Four cards carry an action of their own -- test the connection,
 * send a test push, generate a webhook secret, erase a customer by phone -- and
 * each wrote its own <form> where it stood, inside that one.
 *
 * HTML has no nested forms. The parser drops the inner start tag and lets the
 * inner </form> close the OUTER one, so pressing "Test connection" posted the
 * settings form to options.php, and everything after that point -- the
 * remaining fields, every later card and the Save button -- was left outside
 * any form at all. The WA.cr tab could not be saved and three of its settings
 * could never be changed.
 *
 * Nothing caught it for a whole release line. It is valid-looking PHP, the
 * markup reads correctly in the source, phpcs and PHPStan have no opinion about
 * HTML, and the accessibility run never pressed the button. The one trace it
 * left was two elements with the same id, which was read as an id collision and
 * fixed as one -- Nonce_Field's docblock still lists the three forms it was
 * about, having got that close without anybody noticing they were nested.
 *
 * So the property is asserted directly, on the rendered markup of every screen,
 * rather than by forbidding a string in four particular files.
 */

/**
 * The deepest a form is nested in some markup.
 *
 * 1 is a document with forms in it, however many, as long as each closes before
 * the next opens. 2 or more is the defect.
 *
 * @param string $html Rendered markup.
 * @return int
 */
function kdc_wacr_recoveryflow_form_depth( string $html ): int {
	preg_match_all( '/<form\b|<\/form\s*>/i', $html, $recoveryflow_tags );

	$depth = 0;
	$max   = 0;

	foreach ( (array) ( $recoveryflow_tags[0] ?? array() ) as $recoveryflow_tag ) {
		if ( '/' === substr( (string) $recoveryflow_tag, 1, 1 ) ) {
			$depth = max( 0, $depth - 1 );
			continue;
		}

		++$depth;
		$max = max( $max, $depth );
	}

	return $max;
}

// The helper has to be able to see a nested form, or it reports every screen
// clean for ever. Asserted in both directions on markup written here.
ok(
	'the form-depth reading counts a nested form as nested',
	2 === kdc_wacr_recoveryflow_form_depth( '<form><form></form></form>' )
);
ok(
	'and counts forms that follow one another as flat',
	1 === kdc_wacr_recoveryflow_form_depth( '<form></form><form></form><form></form>' )
);

// Earlier sections leave a restricted user in place to prove the screens refuse
// one. These assertions are about markup, so they need the full capability set.
$GLOBALS['recoveryflow_caps'] = null;

/*
 * And the screens have to be in the state where the controls exist at all.
 * The card that carries "Test connection" returns early when no key is saved --
 * it says "No key saved yet" and stops -- so a tab rendered on a blank site
 * has no button on it, and a gate reading that markup is reading a screen the
 * defect cannot appear on. This is the same lesson the accessibility suite
 * learned about empty tables, arriving from a different direction: the run was
 * checking a page that was missing the thing being checked.
 */
recoveryflow_save_tab( 'wacr', array( Settings_Schema::FIELD_API_KEY => 'wacr_live_secret_for_markup' ) );

$recoveryflow_nested_screens = array();
$recoveryflow_dangling       = array();
$recoveryflow_controls_seen  = array();

foreach ( array_keys( Settings_Schema::tabs() ) as $recoveryflow_tab_name ) {
	$_GET['tab'] = $recoveryflow_tab_name;

	$recoveryflow_tab_html = recoveryflow_render_screen( array( Settings_Page::class, 'render' ) );

	if ( kdc_wacr_recoveryflow_form_depth( $recoveryflow_tab_html ) > 1 ) {
		$recoveryflow_nested_screens[] = 'settings/' . $recoveryflow_tab_name;
	}

	foreach ( array(
		'connection test' => Connection_Test::FORM_ID,
		'hook test'       => Hook_Test::FORM_ID,
		'webhook secret'  => Webhook_Setup::FORM_ID,
		'erase by phone'  => Erase_By_Phone::FORM_ID,
	) as $recoveryflow_control => $recoveryflow_control_id ) {
		if ( false !== strpos( $recoveryflow_tab_html, 'form="' . $recoveryflow_control_id . '"' ) ) {
			$recoveryflow_controls_seen[ $recoveryflow_control ] = true;
		}
	}

	/*
	 * The other half of the fix. A control may name the form it submits with,
	 * which is what lets the button stay in its card -- but a control naming a
	 * form that was never declared submits nothing at all, silently, which is
	 * the same broken button with none of the evidence.
	 */
	preg_match_all( '/\sform="([^"]+)"/', $recoveryflow_tab_html, $recoveryflow_refs );

	foreach ( array_unique( (array) ( $recoveryflow_refs[1] ?? array() ) ) as $recoveryflow_ref ) {
		if ( false === strpos( $recoveryflow_tab_html, 'id="' . $recoveryflow_ref . '"' ) ) {
			$recoveryflow_dangling[] = $recoveryflow_tab_name . ' -> ' . $recoveryflow_ref;
		}
	}
}

unset( $_GET['tab'] );

foreach ( $recoveryflow_screens as $recoveryflow_name => $recoveryflow_render ) {
	if ( kdc_wacr_recoveryflow_form_depth( recoveryflow_render_screen( $recoveryflow_render ) ) > 1 ) {
		$recoveryflow_nested_screens[] = $recoveryflow_name;
	}
}

ok(
	'no admin screen puts a form inside a form: ' . ( array() === $recoveryflow_nested_screens ? 'none does' : 'NESTED ON ' . implode( ', ', $recoveryflow_nested_screens ) ),
	array() === $recoveryflow_nested_screens
);

ok(
	'and every control naming a form is naming one that exists: ' . ( array() === $recoveryflow_dangling ? 'all of them' : 'DANGLING ' . implode( ', ', $recoveryflow_dangling ) ),
	array() === $recoveryflow_dangling
);

/*
 * Without this the two assertions above are worth nothing. Each of the four
 * controls has to actually be on a rendered tab, or "no screen nests a form" is
 * a true statement about a screen with no forms on it to nest. Three of the
 * four were absent until this section put a key in place and made grouped cards
 * render, and each absence was a real defect rather than a test-setup detail.
 */
ok(
	'all four action controls are on a rendered settings tab: ' . implode( ', ', array_keys( $recoveryflow_controls_seen ) ),
	4 === count( $recoveryflow_controls_seen )
);

/*
 * And the Save button really is inside the settings form, which is the damage
 * the nesting actually did. Counted by position rather than by presence: the
 * button was on the page the whole time it did nothing.
 */
$_GET['tab']              = 'wacr';
$recoveryflow_wacr_html   = recoveryflow_render_screen( array( Settings_Page::class, 'render' ) );
$recoveryflow_form_opens  = strpos( $recoveryflow_wacr_html, '<form' );
$recoveryflow_form_closes = strpos( $recoveryflow_wacr_html, '</form>' );
$recoveryflow_save_at     = strpos( $recoveryflow_wacr_html, 'id="submit"' );

unset( $_GET['tab'] );

ok(
	'the Save button on the WA.cr tab is inside the settings form, not after it',
	false !== $recoveryflow_form_opens
		&& false !== $recoveryflow_form_closes
		&& false !== $recoveryflow_save_at
		&& $recoveryflow_save_at > $recoveryflow_form_opens
		&& $recoveryflow_save_at < $recoveryflow_form_closes
);


// ------------------------------------------- The WordPress.org submission.

/*
 * Three things WordPress.org's own Plugin Check refuses, or would have refused,
 * on the tree that was about to be submitted. All three are the shape this repo
 * keeps re-learning: a rule that IS obeyed everywhere, and one place where it
 * silently is not, with every other gate green.
 */

/*
 * THREE. The plan overclaim, caught by what it MEANS rather than by how it was
 * last worded.
 *
 * The existing gate above blocks three literal phrases in readme.txt. It was
 * green while the listing carried a section HEADING reading "Works with any
 * WA.cr plan" -- directly above its own paragraph explaining that the hand-off
 * starts at Growth -- and while the settings screen told merchants, in a
 * translated string, that "Handing over works on every WA.cr plan".
 *
 * A blocklist of sentences somebody already thought of cannot catch the next
 * rewording. This asks the question the claim is made of instead: does any
 * shipped text put "works" and "any/every plan" in one breath? Only Growth and
 * above can run an Auto Flow at all, so the answer must always be no.
 */
/*
 * The window between the verb and the object must cross "WA.cr" -- which holds
 * a full stop -- while still stopping at the end of a sentence. `[^.!?]` does
 * the second and not the first, and the four self-tests below failed on exactly
 * that until it was fixed. A full stop only ends a sentence when whitespace
 * follows it.
 */
$recoveryflow_overclaim_pattern = '/\b(works?|working|available|runs?)\b(?:(?!\.\s)[^!?\n]){0,45}\b(any|every)\b(?:(?!\.\s)[^!?\n]){0,30}\b(plan|workspace)\b/i';

/*
 * Assert the detector detects, before trusting it to say a tree is clean. These
 * are the four claims that actually shipped, in the words they shipped in; a
 * pattern that stopped matching would otherwise report every future tree as
 * fine. The two controls below are sentences that are TRUE and must not trip it
 * -- without them the rule could be tightened into uselessness and stay green.
 */
foreach ( array(
	'Works with any WA.cr plan',
	'Handing over works on every WA.cr plan and puts the timing in WA.cr.',
	'the hand-off works on any WA.cr workspace',
	'a "webhook received" trigger (works on any workspace with Auto Flows)',
) as $recoveryflow_known_bad ) {
	ok(
		"the overclaim detector fires on the wording that shipped: \"{$recoveryflow_known_bad}\"",
		1 === preg_match( $recoveryflow_overclaim_pattern, $recoveryflow_known_bad )
	);
}

foreach ( array(
	'Every plan needs an active account, and the plan decides which of the two paths you can use.',
	'It works on any WordPress site running 6.5 or later.',
) as $recoveryflow_known_good ) {
	ok(
		"and does not fire on a true sentence: \"{$recoveryflow_known_good}\"",
		0 === preg_match( $recoveryflow_overclaim_pattern, $recoveryflow_known_good )
	);
}

/*
 * Now the tree. The listing AND the source, because the claim was in both and
 * only the listing was ever checked -- and the source copy is the one a merchant
 * reads while deciding which dispatch path to configure.
 */
$recoveryflow_claim_walk  = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', FilesystemIterator::SKIP_DOTS )
);
$recoveryflow_claim_files = array( dirname( __DIR__ ) . '/readme.txt' );

foreach ( $recoveryflow_claim_walk as $recoveryflow_claim_file ) {
	if ( $recoveryflow_claim_file->isFile() && 'php' === $recoveryflow_claim_file->getExtension() ) {
		$recoveryflow_claim_files[] = $recoveryflow_claim_file->getPathname();
	}
}

$recoveryflow_overclaims = array();

foreach ( $recoveryflow_claim_files as $recoveryflow_claim_path ) {
	$recoveryflow_claim_body = (string) file_get_contents( $recoveryflow_claim_path );

	if ( 1 !== preg_match( $recoveryflow_overclaim_pattern, $recoveryflow_claim_body, $recoveryflow_claim_hit ) ) {
		continue;
	}

	$recoveryflow_overclaims[] = str_replace( dirname( __DIR__ ) . '/', '', $recoveryflow_claim_path )
		. ': "' . trim( (string) $recoveryflow_claim_hit[0] ) . '"';
}

ok( 'there is shipped text to check the plan claim against', count( $recoveryflow_claim_files ) > 100 );
ok(
	'nothing shipped says the plugin works on any or every WA.cr plan, in the listing or on a screen: '
		. ( array() === $recoveryflow_overclaims ? 'clean' : 'CLAIMED IN ' . implode( ' | ', $recoveryflow_overclaims ) ),
	array() === $recoveryflow_overclaims
);


echo "\n";
echo "\n";
echo $failed > 0 ? "FAILED\n" : "PASSED\n";
echo "{$passed} passed, {$failed} failed\n";

exit( $failed > 0 ? 1 : 0 );
