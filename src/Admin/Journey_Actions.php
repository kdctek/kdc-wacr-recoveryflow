<?php
/**
 * Acting on one recovery from wp-admin.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\REST\Journeys_Controller;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The four things a shopkeeper can do to a recovery, as buttons.
 *
 * Until now the queue could be read from wp-admin and only worked from curl.
 * Cancelling had a REST route and no control anywhere -- the same shape as the
 * "use Test connection" health check that named a button which did not exist
 * for two whole slices.
 *
 * **Every action here runs the REST controller's own handler.** Not a copy of
 * it: this builds a `WP_REST_Request`, calls the same method the API calls, and
 * turns the answer into a notice. Two implementations of "retry a recovery"
 * would be two sets of rules about when a retry is allowed, and the one nobody
 * exercised would be the one that let a message reach somebody who had opted
 * out. It also means the refusals arrive already written: a retry on an
 * exhausted step explains the attempt cap, in the same words, in both places.
 *
 * **They are POST forms, not row-action links.** A `WP_List_Table` row action
 * is an anchor, and an anchor that changes state is fetched by anything that
 * follows links -- a prefetcher, a crawler, an antivirus proxy opening every
 * URL in an email. Cancelling somebody's recovery because their mail client
 * warmed a link is not a risk worth the convenience, so the list links to this
 * screen and this screen posts.
 *
 * **Each button says what it will do before it is pressed**, because three of
 * the four cannot be undone: a revoked link never works again, an opt-out is a
 * promise to a customer, and a cancelled recovery has no path back.
 */
final class Journey_Actions {

	/**
	 * The admin-post action name.
	 */
	public const ACTION = 'recoveryflow_journey_action';

	/**
	 * How long the result waits to be shown, in seconds.
	 */
	private const RESULT_TTL = 60;

	/**
	 * The REST controller whose handlers these buttons run.
	 *
	 * @var Journeys_Controller
	 */
	private Journeys_Controller $controller;

	/**
	 * Constructor.
	 *
	 * @param Journeys_Controller $controller The REST controller.
	 */
	public function __construct( Journeys_Controller $controller ) {
		$this->controller = $controller;
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Do it, remember what happened, and go back to the recovery.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE_JOURNEYS ) ) {
			wp_die(
				esc_html__( 'You are not allowed to work the recovery queue on this site.', 'kdc-wacr-recoveryflow' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		$act = isset( $_POST['recoveryflow_action'] ) ? sanitize_key( wp_unslash( $_POST['recoveryflow_action'] ) ) : '';

		/*
		 * One form posts a single reference and the queue's bulk form posts an
		 * array of them, and they are otherwise the same post: same nonce, same
		 * action name, same vocabulary. Reading both here is what keeps there
		 * from being a second handler with a second idea of what may be done.
		 */
		$posted = isset( $_POST['journey'] )
			? map_deep( wp_unslash( $_POST['journey'] ), 'sanitize_text_field' )
			: '';
		$many   = is_array( $posted );

		$uids = array_values(
			array_filter(
				array_map(
					static fn ( $value ): string => (string) $value,
					$many ? $posted : array( $posted )
				),
				static fn ( string $value ): bool => '' !== $value
			)
		);

		set_transient( self::transient_key(), $this->run_many( $uids, $act ), self::RESULT_TTL );

		/*
		 * A bulk post came from the queue and goes back to the queue. Sending
		 * somebody to the first of the eleven recoveries they just acted on
		 * would lose the list they were working through.
		 */
		wp_safe_redirect(
			$many || 1 !== count( $uids )
				? Screen::url( Screen::JOURNEYS )
				: Screen::journey_url( $uids[0] )
		);

		exit;
	}

	/**
	 * Run one action over any number of recoveries and summarise it.
	 *
	 * Every recovery goes through `run()` -- and therefore through the REST
	 * controller -- one at a time. Nothing here decides whether a recovery may
	 * be cancelled or retried; a bulk action that answered that question itself
	 * would be a second set of rules, and the one nobody exercised would be the
	 * one that messaged somebody who had opted out.
	 *
	 * Refusals are reported BY REFERENCE rather than counted. "Three were
	 * refused" tells a shop worker nothing they can act on; naming them tells
	 * them which three to open.
	 *
	 * @param string[] $uids   The recoveries' public references.
	 * @param string   $action One of Journeys_Controller::ACTIONS.
	 * @return array{ok:bool,message:string}
	 */
	public function run_many( array $uids, string $action ): array {
		if ( array() === $uids ) {
			return array(
				'ok'      => false,
				'message' => __( 'No recoveries were selected, so nothing was done.', 'kdc-wacr-recoveryflow' ),
			);
		}

		if ( 1 === count( $uids ) ) {
			return $this->run( $uids[0], $action );
		}

		$done   = 0;
		$argued = array();

		foreach ( $uids as $uid ) {
			$result = $this->run( $uid, $action );

			if ( $result['ok'] ) {
				++$done;

				continue;
			}

			$argued[] = sprintf(
				/* translators: 1: the recovery's public reference, 2: the reason it was refused. */
				__( '%1$s (%2$s)', 'kdc-wacr-recoveryflow' ),
				$uid,
				$result['message']
			);
		}

		$message = sprintf(
			/* translators: %d: how many recoveries the action succeeded on. */
			_n( '%d recovery was updated.', '%d recoveries were updated.', $done, 'kdc-wacr-recoveryflow' ),
			$done
		);

		if ( array() !== $argued ) {
			$message .= ' ' . sprintf(
				/* translators: %s: a comma-separated list of references, each with its reason. */
				__( 'These were not: %s', 'kdc-wacr-recoveryflow' ),
				implode( ', ', $argued )
			);
		}

		return array(
			'ok'      => 0 < $done,
			'message' => $message,
		);
	}

	/**
	 * Run one action and say what came back.
	 *
	 * The decision, separated from the redirect that follows it, so every
	 * branch can be exercised without a request in the way.
	 *
	 * @param string $uid    The recovery's public reference.
	 * @param string $action One of Journeys_Controller::ACTIONS.
	 * @return array{ok:bool,message:string}
	 */
	public function run( string $uid, string $action ): array {
		if ( '' === $uid || ! in_array( $action, Journeys_Controller::ACTIONS, true ) ) {
			// The enum is enforced by WordPress on the REST route and by this
			// check here, because a hand-posted form reaches neither.
			return array(
				'ok'      => false,
				'message' => __( 'That is not something RecoveryFlow can do to a recovery.', 'kdc-wacr-recoveryflow' ),
			);
		}

		$request = new \WP_REST_Request( 'POST', '' );
		$request->set_param( 'uid', $uid );
		$request->set_param( 'action', $action );

		$result = $this->controller->act( $request );

		if ( $result instanceof \WP_Error ) {
			// The controller has already written the refusal, and it says what
			// was refused, why, and what to do instead. Replacing it with a
			// sentence of our own here would tell the merchant less and would
			// drift from what the same refusal says over the API.
			return array(
				'ok'      => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'ok'      => true,
			'message' => self::outcome( $action, is_array( $result->get_data() ) ? $result->get_data() : array() ),
		);
	}

	/**
	 * What to tell somebody who has just done one of these.
	 *
	 * Public because it is the decision, not the plumbing: "nothing was
	 * revoked" and "four links were revoked" need different sentences, and the
	 * difference matters to somebody who came here worried about one link.
	 *
	 * @param string              $action What they did.
	 * @param array<string,mixed> $data   What the controller reported.
	 * @return string
	 */
	public static function outcome( string $action, array $data ): string {
		switch ( $action ) {
			case 'retry':
				return __( 'This recovery is back in the queue. Nothing has been sent yet: the next background pass will try it, and will still check consent, opt-outs and quiet hours before anything goes out.', 'kdc-wacr-recoveryflow' );

			case 'revoke_links':
				$revoked = (int) ( $data['revoked'] ?? 0 );

				if ( 0 === $revoked ) {
					// Nothing revoked is a real outcome, not a failure, and
					// saying "done" would leave somebody believing a link they
					// are worried about has just been killed.
					return __( 'There were no working links on this recovery, so nothing changed. Any link already sent for it had expired or been revoked already.', 'kdc-wacr-recoveryflow' );
				}

				return sprintf(
					/* translators: %d: how many recovery links stopped working. */
					_n(
						'%d recovery link has stopped working. The recovery itself is still running, so a later step may send a new one.',
						'%d recovery links have stopped working. The recovery itself is still running, so a later step may send a new one.',
						$revoked,
						'kdc-wacr-recoveryflow'
					),
					$revoked
				);

			case 'opt_out':
				return sprintf(
					/* translators: 1: how many contact details were silenced, 2: how many recoveries were stopped. */
					__( 'This customer will not be messaged again. %1$d contact detail was silenced and %2$d open recovery was stopped, along with its links.', 'kdc-wacr-recoveryflow' ),
					(int) ( $data['identities'] ?? 0 ),
					(int) ( $data['journeys'] ?? 0 )
				);

			default:
				return __( 'This recovery has been cancelled and its links no longer work. Nothing further will be sent for it.', 'kdc-wacr-recoveryflow' );
		}//end switch
	}

	/**
	 * The buttons, for the recovery's own screen to place.
	 *
	 * @param Recovery_Journey $journey The recovery.
	 * @return void
	 */
	public static function buttons( Recovery_Journey $journey ): void {
		printf( '<h2>%s</h2>', esc_html__( 'Act on this recovery', 'kdc-wacr-recoveryflow' ) );

		if ( ! current_user_can( Capabilities::MANAGE_JOURNEYS ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Acting on a recovery needs the "recoveryflow_manage_journeys" permission, which this account does not have. Reading the queue and changing it are separate permissions.', 'kdc-wacr-recoveryflow' )
			);

			return;
		}

		echo '<div class="card recoveryflow-card recoveryflow-journey-actions">';

		foreach ( self::available( $journey ) as $action => $labels ) {
			printf(
				'<form method="post" action="%1$s" class="recoveryflow-journey-action">',
				esc_url( admin_url( 'admin-post.php' ) )
			);

			Nonce_Field::render( self::ACTION );

			printf(
				'<input type="hidden" name="action" value="%1$s" />
				<input type="hidden" name="recoveryflow_action" value="%2$s" />
				<input type="hidden" name="journey" value="%3$s" />
				<p><button type="submit" class="button">%4$s</button></p>
				<p class="description">%5$s</p>
				</form>',
				esc_attr( self::ACTION ),
				esc_attr( $action ),
				esc_attr( $journey->journey_uid ),
				esc_html( $labels['label'] ),
				esc_html( $labels['help'] )
			);
		}//end foreach

		echo '</div>';
	}

	/**
	 * Which actions make sense for this recovery, and how to describe them.
	 *
	 * Offering an action that will certainly be refused is worse than not
	 * offering it: a button that always answers "no" teaches somebody that the
	 * screen is broken. So retry appears only on a failed recovery, and the
	 * three that stop things appear only while there is something to stop.
	 *
	 * @param Recovery_Journey $journey The recovery.
	 * @return array<string,array{label:string,help:string}>
	 */
	public static function available( Recovery_Journey $journey ): array {
		$actions = array();

		if ( Journey_State::FAILED === $journey->status ) {
			$actions['retry'] = array(
				'label' => __( 'Try this recovery again', 'kdc-wacr-recoveryflow' ),
				'help'  => __( 'Puts it back in the queue. It does not send anything now, and consent, opt-outs and quiet hours are all still checked before it does.', 'kdc-wacr-recoveryflow' ),
			);
		}

		if ( ! $journey->is_terminal() ) {
			$actions['cancel'] = array(
				'label' => __( 'Stop this recovery', 'kdc-wacr-recoveryflow' ),
				'help'  => __( 'Nothing further is sent for it and its links stop working. This cannot be undone.', 'kdc-wacr-recoveryflow' ),
			);
		}

		$actions['revoke_links'] = array(
			'label' => __( 'Stop the links working', 'kdc-wacr-recoveryflow' ),
			'help'  => __( 'For a link that has gone somewhere it should not have. The recovery keeps running, so a later step may send a new link. Links already sent stop working for good.', 'kdc-wacr-recoveryflow' ),
		);

		$actions['opt_out'] = array(
			'label' => __( 'Never message this customer again', 'kdc-wacr-recoveryflow' ),
			'help'  => __( 'For somebody who has asked by telephone or in person. It stops every open recovery for them, on every contact detail they have given, and survives being forgotten under a privacy request. This cannot be undone from here.', 'kdc-wacr-recoveryflow' ),
		);

		return $actions;
	}

	/**
	 * Show what the last action did, once.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$result = get_transient( self::transient_key() );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( self::transient_key() );

		printf(
			'<div class="notice %1$s" role="status"><p>%2$s</p></div>',
			esc_attr( empty( $result['ok'] ) ? 'notice-error' : 'notice-success' ),
			esc_html( isset( $result['message'] ) ? (string) $result['message'] : '' )
		);
	}

	/**
	 * Where one user's pending result lives.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return 'recoveryflow_journey_action_' . get_current_user_id();
	}
}
