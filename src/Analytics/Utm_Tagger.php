<?php
/**
 * Adds campaign tags to the address a recovery link restores to.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Analytics;

use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Recovery\Attempt;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Workflow\Workflow_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Puts utm_* parameters on the restore redirect, so analytics can credit it.
 *
 * **On the redirect, because there is nowhere else.** A WhatsApp template's
 * button is a fixed address plus one variable, the token, and the token is
 * the credential; tags added there would be part of a URL Meta approved and
 * nobody can change per message. The redirect is computed here, per click,
 * from the exact message that was tapped.
 *
 * **Why the sessions need it.** The recovery endpoint sends
 * `Referrer-Policy: no-referrer` so the token cannot leak to the shop's
 * scripts, and the side effect is that every recovered visit arrives with no
 * referrer at all and is counted as "direct". These tags are the only thing
 * that can say where it came from.
 *
 * - utm_source   recoveryflow
 * - utm_medium   the channel of the message that was tapped: whatsapp or email
 * - utm_campaign the workflow's slug
 * - utm_content  which step of the workflow sent it, as step-1, step-2 ...
 *
 * In GA4's default channel grouping, email sessions land in "Email" and
 * WhatsApp sessions in "Unassigned": WhatsApp matches no default rule, and
 * pretending it is "sms" or "social" to get a tidier bucket would be wrong in
 * every report built on it. docs/integrations.md tells the merchant how to add
 * a custom channel group instead.
 *
 * A tag the shop's own target already carries is never overwritten.
 */
final class Utm_Tagger {

	/**
	 * The value of utm_source.
	 */
	public const SOURCE = 'recoveryflow';

	/**
	 * Workflow storage, for the slug.
	 *
	 * @var Workflow_Repository
	 */
	private Workflow_Repository $workflows;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Repository $workflows Workflow storage.
	 */
	public function __construct( Workflow_Repository $workflows ) {
		$this->workflows = $workflows;
	}

	/**
	 * The restore address with campaign tags, or unchanged when tagging is off.
	 *
	 * @param string           $url     The validated restore address.
	 * @param Recovery_Journey $journey The journey being restored.
	 * @param Attempt          $attempt The message whose link was tapped.
	 * @return string
	 */
	public function tag( string $url, Recovery_Journey $journey, Attempt $attempt ): string {
		if ( ! Ga4_Settings::utm_enabled() ) {
			return $url;
		}

		/**
		 * Filters the campaign tags added to a recovery link's destination.
		 *
		 * Return an empty array to add none. Only utm_* keys are used, values
		 * are cut to 100 characters, and a tag the destination already carries
		 * is never overwritten.
		 *
		 * @param array<string,string> $params  utm_source, utm_medium, utm_campaign, utm_content.
		 * @param Recovery_Journey     $journey The journey being restored.
		 * @param Attempt              $attempt The message whose link was tapped.
		 */
		$params = apply_filters( Hooks::FILTER_RESTORE_UTM, $this->params( $journey, $attempt ), $journey, $attempt );

		// Cast rather than trusted: a filter returning a string becomes a
		// one-element list whose numeric key merge() ignores.
		return self::merge( $url, (array) $params );
	}

	/**
	 * The four tags for one tapped message.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param Attempt          $attempt The message.
	 * @return array<string,string>
	 */
	public function params( Recovery_Journey $journey, Attempt $attempt ): array {
		$workflow = $this->workflows->find( $journey->workflow_id );
		$campaign = null !== $workflow && '' !== $workflow->slug ? $workflow->slug : 'workflow-' . $journey->workflow_id;

		return array(
			'utm_source'   => self::SOURCE,
			'utm_medium'   => '' !== $attempt->channel ? $attempt->channel : 'whatsapp',
			'utm_campaign' => $campaign,
			'utm_content'  => 'step-' . ( $attempt->step_index + 1 ),
		);
	}

	/**
	 * Add tags to an address, keeping any it already has and its fragment.
	 *
	 * Pure, so the rules are testable without WordPress: only utm_* keys, no
	 * empty values, nothing over 100 characters, existing keys win, and the
	 * fragment stays at the end where a browser expects it.
	 *
	 * @param string              $url    Address.
	 * @param array<string,mixed> $params Tags to add.
	 * @return string
	 */
	public static function merge( string $url, array $params ): string {
		$fragment = '';
		$hash     = strpos( $url, '#' );

		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		$existing = array();
		$question = strpos( $url, '?' );

		if ( false !== $question ) {
			parse_str( substr( $url, $question + 1 ), $existing );
		}

		$add = array();

		foreach ( $params as $key => $value ) {
			$key = strtolower( trim( (string) $key ) );

			if ( 1 !== preg_match( '/^utm_[a-z_]{1,20}$/', $key ) || array_key_exists( $key, $existing ) || ! is_scalar( $value ) ) {
				continue;
			}

			$value = substr( trim( (string) $value ), 0, 100 );

			if ( '' !== $value ) {
				$add[ $key ] = $value;
			}
		}

		if ( array() === $add ) {
			return $url . $fragment;
		}

		if ( false === $question ) {
			$joiner = '?';
		} elseif ( '?' === substr( $url, -1 ) || '&' === substr( $url, -1 ) ) {
			$joiner = '';
		} else {
			$joiner = '&';
		}

		return $url . $joiner . http_build_query( $add, '', '&', PHP_QUERY_RFC3986 ) . $fragment;
	}
}
