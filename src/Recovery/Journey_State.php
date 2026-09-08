<?php
/**
 * The recovery journey state machine.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Every state a recovery journey can be in, and every move it can make.
 *
 * The transitions are declared rather than scattered through the code that
 * performs them, so "can this journey still be messaged?" has one answer and
 * an illegal move is caught at the boundary instead of producing a journey in
 * an impossible state.
 *
 * Two states are worth explaining.
 *
 * PENDING_PAYMENT is not a conversion. An order exists, so messaging must stop
 * at once, but the money has not arrived; a failed payment sends the journey
 * back to work exactly once, which is the difference between recovering an
 * abandoned checkout and pestering somebody whose card was declined twice.
 *
 * ENGAGED means the customer replied. It is a better outcome than MESSAGE_SENT
 * and a worse one than RECOVERED, and it exists because a reply should stop the
 * follow-ups: the conversation has started, and the next message should come
 * from a person.
 */
final class Journey_State {

	public const NEW             = 'new';
	public const IDENTIFIED      = 'identified';
	public const ELIGIBLE        = 'eligible';
	public const SCHEDULED       = 'scheduled';
	public const MESSAGE_SENT    = 'message_sent';
	public const ENGAGED         = 'engaged';
	public const PENDING_PAYMENT = 'pending_payment';
	public const RECOVERED       = 'recovered';
	public const EXPIRED         = 'expired';
	public const CANCELLED       = 'cancelled';
	public const OPTED_OUT       = 'opted_out';
	public const INVALID         = 'invalid';
	public const FAILED          = 'failed';

	/**
	 * States a journey can leave.
	 *
	 * @return string[]
	 */
	public static function active(): array {
		return array(
			self::NEW,
			self::IDENTIFIED,
			self::ELIGIBLE,
			self::SCHEDULED,
			self::MESSAGE_SENT,
			self::ENGAGED,
			self::PENDING_PAYMENT,
		);
	}

	/**
	 * States a journey can never leave.
	 *
	 * @return string[]
	 */
	public static function terminal(): array {
		return array(
			self::RECOVERED,
			self::EXPIRED,
			self::CANCELLED,
			self::OPTED_OUT,
			self::INVALID,
			self::FAILED,
		);
	}

	/**
	 * Every state.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_merge( self::active(), self::terminal() );
	}

	/**
	 * Whether a journey in this state is finished with.
	 *
	 * @param string $state State name.
	 * @return bool
	 */
	public static function is_terminal( string $state ): bool {
		return in_array( $state, self::terminal(), true );
	}

	/**
	 * Whether a journey in this state is still being worked.
	 *
	 * @param string $state State name.
	 * @return bool
	 */
	public static function is_active( string $state ): bool {
		return in_array( $state, self::active(), true );
	}

	/**
	 * Whether a recovery message may still be sent for a journey in this state.
	 *
	 * Deliberately narrow. A journey that has engaged the customer, or that is
	 * waiting on a payment, is not a candidate however many workflow steps it
	 * has left.
	 *
	 * @param string $state State name.
	 * @return bool
	 */
	public static function may_send( string $state ): bool {
		return in_array( $state, array( self::ELIGIBLE, self::SCHEDULED, self::MESSAGE_SENT ), true );
	}

	/**
	 * The moves allowed out of each state.
	 *
	 * @return array<string,string[]>
	 */
	public static function transitions(): array {
		$stop = array(
			self::RECOVERED,
			self::EXPIRED,
			self::CANCELLED,
			self::OPTED_OUT,
			self::INVALID,
			self::FAILED,
		);

		return array(
			self::NEW             => array_merge( array( self::IDENTIFIED, self::ELIGIBLE ), $stop ),
			self::IDENTIFIED      => array_merge( array( self::ELIGIBLE ), $stop ),
			self::ELIGIBLE        => array_merge( array( self::SCHEDULED, self::PENDING_PAYMENT ), $stop ),
			self::SCHEDULED       => array_merge( array( self::SCHEDULED, self::MESSAGE_SENT, self::ENGAGED, self::PENDING_PAYMENT ), $stop ),
			self::MESSAGE_SENT    => array_merge( array( self::SCHEDULED, self::MESSAGE_SENT, self::ENGAGED, self::PENDING_PAYMENT ), $stop ),
			self::ENGAGED         => array_merge( array( self::PENDING_PAYMENT ), $stop ),
			// A declined payment hands the journey back to the workflow, once.
			self::PENDING_PAYMENT => array_merge( array( self::SCHEDULED, self::MESSAGE_SENT ), $stop ),
			self::RECOVERED       => array(),
			self::EXPIRED         => array(),
			self::CANCELLED       => array(),
			self::OPTED_OUT       => array(),
			self::INVALID         => array(),

			/*
			 * FAILED is the one terminal state that means "the machinery could
			 * not", rather than "do not message this person". RECOVERED,
			 * EXPIRED, CANCELLED, OPTED_OUT and INVALID all carry a decision
			 * about the customer and stay closed for good; a send that died
			 * because WA.cr was unreachable or a template had been withdrawn
			 * carries no such decision, and a shopkeeper who has since fixed
			 * the cause is entitled to ask for it again.
			 *
			 * SCHEDULED and nothing else, and only ever from a person asking:
			 * no background pass may resurrect a journey, or a fault that
			 * failed a thousand recoveries would retry all thousand by itself.
			 * Eligibility is not re-granted by this -- the send gate still asks
			 * about consent, opt-out and quiet hours before anything goes out,
			 * so a customer who opted out between the failure and the retry is
			 * still not messaged.
			 */
			self::FAILED          => array( self::SCHEDULED ),
		);
	}

	/**
	 * Whether one state may move to another.
	 *
	 * @param string $from Current state.
	 * @param string $to   Proposed state.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		$map = self::transitions();

		if ( ! isset( $map[ $from ] ) ) {
			return false;
		}

		return in_array( $to, $map[ $from ], true );
	}

	/**
	 * A human label for a state.
	 *
	 * @param string $state State name.
	 * @return string
	 */
	public static function label( string $state ): string {
		/*
		 * Every label carries a context. On their own "New", "Replied",
		 * "Expired" and "Failed" are one word each and could be a noun, a verb
		 * or an adjective; languages that inflect for gender or number cannot
		 * translate them correctly without knowing they describe the state of a
		 * recovery journey.
		 */
		$labels = array(
			self::NEW             => _x( 'New', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::IDENTIFIED      => _x( 'Identified', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::ELIGIBLE        => _x( 'Eligible', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::SCHEDULED       => _x( 'Scheduled', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::MESSAGE_SENT    => _x( 'Message sent', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::ENGAGED         => _x( 'Replied', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::PENDING_PAYMENT => _x( 'Awaiting payment', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::RECOVERED       => _x( 'Recovered', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::EXPIRED         => _x( 'Expired', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::CANCELLED       => _x( 'Cancelled', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::OPTED_OUT       => _x( 'Opted out', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::INVALID         => _x( 'Not recoverable', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
			self::FAILED          => _x( 'Failed', 'recovery journey state', 'kdc-wacr-recoveryflow' ),
		);

		return $labels[ $state ] ?? $state;
	}
}
