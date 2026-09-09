<?php
/**
 * What the Auto Flow hook last did with a hand-off.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\WAcr;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that remembers whether WA.cr actually ran the flow.
 *
 * The hook answers HTTP 200 to three things that ran nothing --
 * feature_disabled, flow_not_active and trigger_not_published -- so a merchant
 * whose flow is paused, or whose plan cannot run Auto Flows at all, gets a
 * clean 200 for every recovery and no message. The dispatcher now defers those
 * instead of recording them as sent, and this is what carries the reason out of
 * a background pass and onto a screen somebody looks at.
 *
 * It holds one refusal, not a history: the question the Connection screen asks
 * is "is the far end running my flow right now", and the last answer is the
 * only one that bears on it. A successful enrolment clears it, so a merchant
 * who fixes the flow stops being told about a problem they have already solved.
 */
final class Flow_Status {

	/**
	 * Where the last refusal is kept.
	 */
	public const OPTION = 'recoveryflow_flow_refusal';

	/**
	 * The flow exists but is not running: draft, paused, or never published.
	 */
	public const FLOW_NOT_ACTIVE = 'flow_not_active';

	/**
	 * Auto Flows are switched off for this workspace, which on WA.cr's plans
	 * means the workspace cannot run one at all.
	 */
	public const FEATURE_DISABLED = 'feature_disabled';

	/**
	 * The flow is active but its trigger node has never been published.
	 */
	public const TRIGGER_NOT_PUBLISHED = 'trigger_not_published';

	/**
	 * Remember that WA.cr accepted a hand-off and ran nothing.
	 *
	 * @param string $reason Machine-readable reason from the hook.
	 * @return void
	 */
	public static function refused( string $reason ): void {
		update_option(
			self::OPTION,
			array(
				'reason' => substr( $reason, 0, 32 ),
				'at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);
	}

	/**
	 * Remember that a hand-off was actually taken up.
	 *
	 * Only writes when there is something to clear. A recovery site pushing
	 * steadily would otherwise write this option on every single send, for no
	 * reason other than to store the value it already held.
	 *
	 * @return void
	 */
	public static function enrolled(): void {
		if ( array() === self::refusal() ) {
			return;
		}

		delete_option( self::OPTION );
	}

	/**
	 * The last refusal, or an empty array when the flow is being taken up.
	 *
	 * @return array<string,string>
	 */
	public static function refusal(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['reason'] ) || '' === (string) $stored['reason'] ) {
			return array();
		}

		return array(
			'reason' => (string) $stored['reason'],
			'at'     => (string) ( $stored['at'] ?? '' ),
		);
	}

	/**
	 * What a refusal means, said to the merchant rather than to the log.
	 *
	 * Each one names what to go and do, because "flow_not_active" on its own is
	 * a code from somebody else's system and tells a shop owner nothing.
	 *
	 * @param string $reason Machine-readable reason.
	 * @return string
	 */
	public static function label( string $reason ): string {
		switch ( $reason ) {
			case self::FEATURE_DISABLED:
				return __( 'WA.cr accepted the last reminder and ran nothing, because this workspace cannot run Auto Flows. That needs the WA.cr Growth plan or above. Nothing has been sent, and the reminders are waiting rather than lost.', 'kdc-wacr-recoveryflow' );

			case self::FLOW_NOT_ACTIVE:
				return __( 'WA.cr accepted the last reminder and ran nothing, because the Auto Flow it was handed to is not active. Open the flow in WA.cr and activate it. Nothing has been sent, and the reminders are waiting rather than lost.', 'kdc-wacr-recoveryflow' );

			case self::TRIGGER_NOT_PUBLISHED:
				return __( 'WA.cr accepted the last reminder and ran nothing, because the flow\'s trigger has never been published. Open the flow in WA.cr and publish it. Nothing has been sent, and the reminders are waiting rather than lost.', 'kdc-wacr-recoveryflow' );

			default:
				return __( 'WA.cr accepted the last reminder and ran nothing, and did not say why. Check that the Auto Flow is active in WA.cr. Nothing has been sent, and the reminders are waiting rather than lost.', 'kdc-wacr-recoveryflow' );
		}
	}
}
