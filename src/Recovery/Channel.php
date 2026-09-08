<?php
/**
 * The channels a recovery message can go out on.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Customer\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Which channel a message uses, and which identity it is addressed to.
 *
 * A channel is not a delivery detail. It decides what may be said, to whom, and
 * under which law: a WhatsApp message must be an approved template inside a
 * service window and is billed, while an email may be free text, has no window,
 * costs nothing and is governed by a different consent regime entirely.
 * Consent, opt-out and eligibility are therefore all decided per channel, and
 * this class is the vocabulary they share.
 */
final class Channel {

	public const WHATSAPP = 'whatsapp';
	public const EMAIL    = 'email';

	/**
	 * Not a channel: a decision that applies to every channel at once.
	 *
	 * A privacy erasure, an admin suppression and the workspace-level opt-out
	 * flag are all "never contact this person again", not "not on WhatsApp".
	 * Recording those against each channel separately would mean a channel added
	 * later silently escaped a refusal that was meant to cover everything.
	 */
	public const ALL = 'all';

	/**
	 * Every channel a message can actually be sent on.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::WHATSAPP, self::EMAIL );
	}

	/**
	 * Whether this names a real channel.
	 *
	 * @param string $channel Channel name.
	 * @return bool
	 */
	public static function is_channel( string $channel ): bool {
		return in_array( $channel, self::all(), true );
	}

	/**
	 * The kind of identity a message on this channel is addressed to.
	 *
	 * This is the whole reason a customer without a phone number is still
	 * recoverable: the email channel addresses an email identity, so somebody
	 * who only ever gave an address is reachable rather than written off.
	 *
	 * @param string $channel Channel name.
	 * @return string An Identity kind, or '' when the channel is unknown.
	 */
	public static function identity_kind( string $channel ): string {
		$kinds = array(
			self::WHATSAPP => Identity::E164,
			self::EMAIL    => Identity::EMAIL,
		);

		return $kinds[ $channel ] ?? '';
	}

	/**
	 * A human label for a channel.
	 *
	 * The channel itself is a stored machine value and stays untranslated: it
	 * is written to the consent ledger, the attempt row and the workflow
	 * definition, and a translated one would make those unreadable after a
	 * locale change and unqueryable for good.
	 *
	 * @param string $channel Channel name.
	 * @return string
	 */
	public static function label( string $channel ): string {
		$labels = array(
			self::WHATSAPP => _x( 'WhatsApp', 'a channel a recovery message is sent on', 'kdc-wacr-recoveryflow' ),
			self::EMAIL    => _x( 'Email', 'a channel a recovery message is sent on', 'kdc-wacr-recoveryflow' ),
			self::ALL      => _x( 'Every channel', 'a consent decision that covers all channels', 'kdc-wacr-recoveryflow' ),
		);

		return $labels[ $channel ] ?? $channel;
	}
}
