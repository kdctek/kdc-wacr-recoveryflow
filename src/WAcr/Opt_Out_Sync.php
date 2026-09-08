<?php
/**
 * Carrying an opt-out from this site into the merchant's WA.cr workspace.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\WAcr;

use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * "Stop messaging me" ought to mean more than "stop this plugin messaging me".
 *
 * A customer who uses the unsubscribe link in a recovery reminder is not asking
 * a WordPress plugin to be quiet. They are asking the shop. RecoveryFlow can
 * only silence itself, so when this is switched on it also marks the person as
 * opted out in the merchant's WA.cr workspace.
 *
 * **What that actually achieves, stated exactly, because the setting promises
 * it and a merchant will rely on it.** WA.cr expands every segment with a
 * global `opted_out = false` filter, so the contact stops being pulled into
 * broadcasts and segment-based campaigns. A direct `/v1/messages` send does not
 * consult the flag at all: anything the merchant sends by API, or an Auto Flow
 * sends by name, still reaches this person. It is a strong signal, not a kill
 * switch, and a setting that implied otherwise would be worse than no setting.
 *
 * Three deliberate choices about when and how:
 *
 * - It is OFF by default. It writes to the merchant's workspace and changes who
 *   their other campaigns reach; that is theirs to decide, not a default.
 * - It runs AFTER the local suppression, in the background, and cannot affect
 *   it. The customer's request is recorded here first and their page is not
 *   waiting on a network call to somebody else's API. A failure is logged and
 *   the opt-out still stands.
 * - The scheduled job carries the internal customer id, never the phone number.
 *   A cron argument lives in `wp_options` in the clear, and a phone number left
 *   there is the same leak this plugin refuses everywhere else.
 */
final class Opt_Out_Sync {

	/**
	 * The action a suppression schedules.
	 */
	public const ACTION = 'recoveryflow_sync_opt_out';

	/**
	 * The setting that turns it on.
	 */
	public const SETTING = 'wacr_push_optout';

	/**
	 * The scope WA.cr requires to accept the change.
	 */
	public const SCOPE = 'contacts:write';

	/**
	 * The WA.cr client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * The customer store.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * The logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Client              $client    The WA.cr client.
	 * @param Customer_Repository $customers The customer store.
	 * @param Logger              $logger    The logger.
	 */
	public function __construct( Client $client, Customer_Repository $customers, Logger $logger ) {
		$this->client    = $client;
		$this->customers = $customers;
		$this->logger    = $logger;
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( self::ACTION, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Whether this site will carry opt-outs across.
	 *
	 * Both halves are required and they fail differently: the setting is the
	 * merchant's decision, the scope is whether their credential can act on it.
	 * A screen that reports only the first tells somebody the sync is on when
	 * every attempt is being refused.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) Options::get( self::SETTING, false ) && Feature_Gate::has_scope( self::SCOPE );
	}

	/**
	 * Queue the change, if this site does that.
	 *
	 * @param int $customer_id Internal customer row id.
	 * @return void
	 */
	public static function queue( int $customer_id ): void {
		if ( $customer_id <= 0 || ! self::is_enabled() ) {
			return;
		}

		if ( false === wp_next_scheduled( self::ACTION, array( $customer_id ) ) ) {
			wp_schedule_single_event( time() + 30, self::ACTION, array( $customer_id ) );
		}
	}

	/**
	 * Find the contact and mark them opted out.
	 *
	 * @param int $customer_id Internal customer row id.
	 * @return void
	 */
	public function run( $customer_id ): void {
		$customer_id = (int) $customer_id;

		// Re-checked here, not only at queue time. A merchant who switches the
		// setting off, or whose key loses the scope, between the opt-out and
		// the job running should not have the change made anyway.
		if ( ! self::is_enabled() ) {
			return;
		}

		$customer = $this->customers->find( $customer_id );

		if ( null === $customer || '' === $customer->phone_e164 ) {
			// Erased between the opt-out and now, or never had a number. Both
			// are ordinary; neither is worth an error.
			return;
		}

		$found = $this->client->find_contact( $customer->phone_e164 );

		if ( ! $found->ok ) {
			$this->logger->warning(
				'wacr',
				'Could not look up the WA.cr contact to carry an opt-out across.',
				array( 'reason' => null === $found->error ? 'unreachable' : $found->error->code )
			);

			return;
		}

		$contact = $found->get( 'contact', null );

		if ( ! is_array( $contact ) || ! isset( $contact['id'] ) ) {
			// Nobody in the workspace has this number, which is the usual case
			// for a shopper who never messaged the shop. There is nothing to
			// mark and nothing has gone wrong.
			return;
		}

		$result = $this->client->opt_out_contact( (string) $contact['id'] );

		if ( ! $result->ok ) {
			$this->logger->warning(
				'wacr',
				'WA.cr refused to mark a contact as opted out.',
				array( 'reason' => null === $result->error ? 'unreachable' : $result->error->code )
			);

			return;
		}

		$this->logger->info( 'wacr', 'Carried an opt-out across to WA.cr.' );
	}
}
