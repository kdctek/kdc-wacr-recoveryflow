<?php
/**
 * Forgetting somebody who only ever gave a phone number.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Privacy;

use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Customer\Identity;
use WAcr\RecoveryFlow\Customer\Identity_Repository;
use WAcr\RecoveryFlow\Customer\Identity_Resolver;
use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The erasure WordPress's own tools cannot reach.
 *
 * Core's privacy eraser is keyed by email address, which is the right key for
 * almost every plugin and the wrong one for this. RecoveryFlow's whole point is
 * recovering people over WhatsApp, so a large share of the customers it holds
 * gave a phone number at the checkout and never an address. Ask core to forget
 * them and there is nothing to type in the box. Until now the honest answer to
 * "please delete my data" from such a person was to go into the database.
 *
 * **It ends at the same Anonymizer as everything else.** Core's eraser and the
 * retention clear-out already share it, for the reason that a second
 * implementation would eventually disagree about what "erased" means and the
 * half nobody was looking at would be the one that forgot something. This is
 * the third caller and it gets no exception: the same fields are blanked, the
 * same links are revoked, the same keyed hashes are kept -- because those
 * hashes are the suppression list, and erasing them would forget that this
 * person asked not to be messaged and message them again the next time they
 * typed the same number into a checkout.
 *
 * **The number is normalised before it is hashed, through Identity_Resolver
 * rather than the normaliser beneath it.** A customer reading their number off
 * their phone writes `07700 900123` while the identity was stored as
 * `+447700900123`, so hashing what was typed would answer "no such customer"
 * about somebody who is certainly there -- on the one screen where that answer
 * sends a person away believing their data has already gone. Going through the
 * resolver matters for the same reason: a site filtering
 * `recoveryflow_normalize_phone` changed how the number was stored at the
 * checkout, and a lookup that skipped the filter would search for something
 * this site never wrote.
 *
 * **A bare local number is read as belonging to the merchant's own postal
 * country**, which they have already given for the email footer. A local number
 * cannot be resolved without one, and a merchant should not have to know their
 * own dialling code to honour an erasure request.
 *
 * **Nothing is revealed.** The form reports whether somebody was found and
 * erased, and never who they were: an erasure box that echoed back a name would
 * be a way to ask this site whether a given phone number belongs to one of its
 * customers, for anyone who could reach the screen. That the answer distinguishes
 * "erased" from "not found" is unavoidable -- it is the operator's own question
 * -- which is why it sits behind the settings capability rather than the queue's.
 */
final class Erase_By_Phone {

	/**
	 * The admin-post action name.
	 */
	public const ACTION = 'recoveryflow_erase_by_phone';

	/**
	 * The field the number is typed into.
	 */
	public const FIELD = 'recoveryflow_erase_phone';

	/**
	 * How long the result waits to be shown, in seconds.
	 */
	private const RESULT_TTL = 60;

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * The one implementation of forgetting somebody.
	 *
	 * @var Anonymizer
	 */
	private Anonymizer $anonymizer;

	/**
	 * Constructor.
	 *
	 * @param Customer_Repository $customers  Customer storage.
	 * @param Anonymizer          $anonymizer The one implementation of forgetting somebody.
	 */
	public function __construct( Customer_Repository $customers, Anonymizer $anonymizer ) {
		$this->customers  = $customers;
		$this->anonymizer = $anonymizer;
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
	 * Erase, remember what happened, and go back to the tab.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You are not allowed to erase customer records on this site.', 'kdc-wacr-recoveryflow' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		$typed = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';

		set_transient( self::transient_key(), $this->erase( $typed ), self::RESULT_TTL );

		wp_safe_redirect( Screen::settings_url( 'privacy', 'erase', self::FIELD ) );

		exit;
	}

	/**
	 * Find the customer behind a phone number and forget them.
	 *
	 * The decision, separated from the request that carries it, so every branch
	 * can be exercised -- including the two that look alike from outside and
	 * mean opposite things: a number that is not a number, and a number that is
	 * nobody's.
	 *
	 * @param string $typed The number as somebody typed it.
	 * @return array{ok:bool,message:string}
	 */
	public function erase( string $typed ): array {
		$typed = trim( $typed );

		if ( '' === $typed ) {
			return array(
				'ok'      => false,
				'message' => __( 'Type the phone number to erase.', 'kdc-wacr-recoveryflow' ),
			);
		}

		$e164 = Identity_Resolver::to_e164( $typed, Identity_Resolver::site_country() );

		if ( '' === $e164 ) {
			// Not the same as "nobody has this number", and saying so matters:
			// one means try again, the other means stop looking.
			return array(
				'ok'      => false,
				'message' => __( 'That is not a phone number RecoveryFlow can read. Include the country code -- for example +44 7700 900123 -- and try again.', 'kdc-wacr-recoveryflow' ),
			);
		}

		$customer = $this->customers->find_by_phone_hash(
			Identity_Repository::hash_for( Identity::E164, $e164 )
		);

		if ( null === $customer ) {
			return array(
				'ok'      => false,
				'message' => __( 'No customer is recorded against that number, so there is nothing to erase. Check the country code: a number stored with one and searched without it will not match.', 'kdc-wacr-recoveryflow' ),
			);
		}

		if ( $customer->is_anonymized() ) {
			// Repeating an erasure is not an error, and answering "done" again
			// would be a lie about work that did not happen. Somebody checking
			// whether a request was already honoured deserves the real answer.
			return array(
				'ok'      => true,
				'message' => __( 'That customer had already been erased. Nothing changed, and nothing about them was readable to begin with.', 'kdc-wacr-recoveryflow' ),
			);
		}

		if ( ! $this->anonymizer->anonymize_customer( $customer->id ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'That customer could not be erased. Nothing was changed. The log will say why.', 'kdc-wacr-recoveryflow' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'That customer has been erased. Their name, number, address and basket contents are gone, every recovery link they were sent has stopped working, and their open recoveries are closed.', 'kdc-wacr-recoveryflow' )
				. ' ' . Anonymizer::retained_notice(),
		);
	}

	/**
	 * The form, for the privacy tab to place.
	 *
	 * @return void
	 */
	public static function form(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'WordPress\'s own privacy tools find somebody by email address. RecoveryFlow holds customers who gave a phone number at the checkout and never an address, and those people cannot be found that way at all. Erase one here instead.', 'kdc-wacr-recoveryflow' )
		);

		printf(
			'<p class="description"><strong>%s</strong> %s</p>',
			esc_html__( 'This cannot be undone.', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'It does exactly what an erasure through WordPress does: the name, number, address and basket contents go, every recovery link already sent stops working, and the record that this person asked not to be messaged is deliberately kept, so erasing them does not start the reminders again.', 'kdc-wacr-recoveryflow' )
		);

		printf(
			'<form method="post" action="%1$s" class="recoveryflow-erase-by-phone">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( self::ACTION );

		printf(
			'<input type="hidden" name="action" value="%1$s" />
			<p>
				<label for="%2$s">%3$s</label><br />
				<input type="tel" class="regular-text" id="%2$s" name="%2$s" value="" autocomplete="off" aria-describedby="%2$s-help" />
			</p>
			<p class="description" id="%2$s-help">%4$s</p>
			<p><button type="submit" class="button">%5$s</button></p>
			</form>',
			esc_attr( self::ACTION ),
			esc_attr( self::FIELD ),
			esc_html__( 'Phone number of the customer to erase', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'Include the country code where you can. A number typed the way a customer reads it off their phone is understood, using this shop\'s default country when no code is given.', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'Erase this customer', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * Show what the last erasure did, once.
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
		return 'recoveryflow_erase_by_phone_' . get_current_user_id();
	}
}
