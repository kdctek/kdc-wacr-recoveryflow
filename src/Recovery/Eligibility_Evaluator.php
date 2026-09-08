<?php
/**
 * Deciding whether a lost conversion may be chased.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Customer\Consent_Store;
use WAcr\RecoveryFlow\Customer\Customer;

defined( 'ABSPATH' ) || exit;

/**
 * Every reason not to send, asked in the right order.
 *
 * The order matters because the first refusal is the one recorded, and the
 * recorded reason is what a merchant reads when they ask why a basket was left
 * alone. Consent and opt-out are asked before commercial thresholds, so a
 * customer who opted out is reported as having opted out rather than as being
 * under the minimum order value -- the second is true but useless, and it
 * invites the merchant to lower a threshold that would change nothing.
 *
 * This runs twice for every message: once when the journey is created, and
 * again immediately before each send. Consent can be withdrawn in the day
 * between scheduling a reminder and sending it, and a check performed only at
 * the start would message somebody who had opted out in the meantime.
 */
final class Eligibility_Evaluator {

	/**
	 * Consent.
	 *
	 * @var Consent_Store
	 */
	private Consent_Store $consent;

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * Attempt ledger.
	 *
	 * @var Attempt_Repository
	 */
	private Attempt_Repository $attempts;

	/**
	 * Constructor.
	 *
	 * @param Consent_Store      $consent  Consent.
	 * @param Journey_Repository $journeys Journey storage.
	 * @param Attempt_Repository $attempts Attempt ledger.
	 */
	public function __construct( Consent_Store $consent, Journey_Repository $journeys, Attempt_Repository $attempts ) {
		$this->consent  = $consent;
		$this->journeys = $journeys;
		$this->attempts = $attempts;
	}

	/**
	 * Whether a new journey may be started for this event and customer.
	 *
	 * @param Recovery_Event $event    The abandoned thing.
	 * @param Customer|null  $customer Who abandoned it.
	 * @param Rule_Set       $rules    The thresholds in force.
	 * @return Eligibility
	 */
	public function for_new_journey( Recovery_Event $event, ?Customer $customer, Rule_Set $rules ): Eligibility {
		$basic = $this->check_person( $customer, $rules );

		if ( ! $basic->allowed ) {
			return $basic;
		}

		// Checked after the person, so a suppressed customer is reported as
		// suppressed rather than as a duplicate journey.
		if ( null !== $customer && $this->journeys->has_active( $customer->id ) ) {
			return Eligibility::deny( Eligibility::ALREADY_OPEN );
		}

		if ( null !== $customer ) {
			$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

			if ( $this->journeys->count_since( $customer->id, $since ) >= $rules->max_journeys_per_month() ) {
				return Eligibility::deny( Eligibility::MONTHLY_CAP );
			}
		}

		if ( $event->amount_value() < $rules->min_amount() ) {
			return Eligibility::deny( Eligibility::BELOW_MIN );
		}

		if ( ! $event->is_open() ) {
			return Eligibility::deny( Eligibility::ALREADY_BOUGHT );
		}

		return Eligibility::allow();
	}

	/**
	 * Whether a message may go out right now for an existing journey.
	 *
	 * Deliberately narrower than the creation check: thresholds that were true
	 * when the journey started are not re-litigated, but consent, opt-out and
	 * the frequency cap are, because all three can change while a journey waits.
	 *
	 * @param Customer|null $customer Who would be messaged.
	 * @param Rule_Set      $rules    The thresholds in force.
	 * @return Eligibility
	 */
	public function for_send( ?Customer $customer, Rule_Set $rules ): Eligibility {
		$basic = $this->check_person( $customer, $rules );

		if ( ! $basic->allowed ) {
			return $basic;
		}

		if ( null === $customer ) {
			return Eligibility::deny( Eligibility::NOT_IDENTIFIED );
		}

		$cap = $rules->frequency_cap_seconds();

		if ( $cap > 0 ) {
			$last = $this->attempts->last_sent_for_customer( $customer->id );

			if ( null !== $last && strtotime( $last . ' UTC' ) > time() - $cap ) {
				return Eligibility::deny( Eligibility::FREQUENCY_CAP );
			}
		}

		return Eligibility::allow();
	}

	/**
	 * The checks that are about the person rather than the basket.
	 *
	 * @param Customer|null $customer The person.
	 * @param Rule_Set      $rules    The thresholds in force.
	 * @return Eligibility
	 */
	private function check_person( ?Customer $customer, Rule_Set $rules ): Eligibility {
		if ( ! $rules->is_enabled() || 'disabled' === $rules->eligibility_mode() ) {
			return Eligibility::deny( Eligibility::DISABLED );
		}

		if ( null === $customer ) {
			return Eligibility::deny( Eligibility::NOT_IDENTIFIED );
		}

		if ( $customer->is_anonymized() ) {
			return Eligibility::deny( Eligibility::ANONYMIZED );
		}

		if ( '' === $customer->phone_raw && '' === $customer->phone_e164 ) {
			return Eligibility::deny( Eligibility::NO_PHONE );
		}

		if ( Customer::PHONE_VALID !== $customer->phone_status || '' === $customer->phone_e164 ) {
			return Eligibility::deny( Eligibility::INVALID_PHONE );
		}

		// Asked before consent: an opt-out is a stronger statement than a
		// missing tick-box, and reporting it as "no consent" would suggest the
		// merchant could fix it by asking again.
		if ( null !== $customer->opted_out_at || $this->consent->is_suppressed( $customer->phone_hash ) ) {
			return Eligibility::deny( Eligibility::SUPPRESSED );
		}

		if ( 'explicit_consent' === $rules->eligibility_mode() && ! $this->consent->has_granted( $customer->phone_hash ) ) {
			return Eligibility::deny( Eligibility::NO_CONSENT );
		}

		if ( $rules->excludes_admins() && $this->is_staff( $customer ) ) {
			return Eligibility::deny( Eligibility::EXCLUDED_USER );
		}

		return Eligibility::allow();
	}

	/**
	 * Whether this customer is somebody who works here.
	 *
	 * Staff test their own store constantly, and a shop whose own manager is
	 * messaged every time they check the checkout stops trusting the plugin.
	 *
	 * @param Customer $customer The person.
	 * @return bool
	 */
	private function is_staff( Customer $customer ): bool {
		if ( null === $customer->wp_user_id ) {
			return false;
		}

		$user = get_userdata( $customer->wp_user_id );

		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		return $user->has_cap( 'edit_shop_orders' ) || $user->has_cap( 'manage_woocommerce' ) || $user->has_cap( 'manage_options' );
	}
}
