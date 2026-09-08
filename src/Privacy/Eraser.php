<?php
/**
 * Acting on "forget me".
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Privacy;

use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Customer\Identity;
use WAcr\RecoveryFlow\Customer\Identity_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Feeds RecoveryFlow into WordPress's personal data eraser.
 *
 * The work itself is the Anonymizer's; this is the adapter that finds who the
 * request is about and reports back in the shape WordPress's privacy screens
 * expect. Keeping the two apart matters because the retention clear-out erases
 * the same way and must not drift into erasing differently.
 *
 * It always reports items_retained, and that is not hedging. RecoveryFlow keeps
 * a one-way hash of each contact detail on purpose, because that hash is the
 * suppression list -- delete it and the shop forgets that this person asked not
 * to be messaged, and starts again the next time they type the same number in.
 * WordPress shows the accompanying message to whoever made the request, so it
 * says what is kept and why rather than leaving them to wonder.
 */
final class Eraser {

	/**
	 * The group WordPress files this under.
	 */
	public const GROUP = 'recoveryflow';

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * The erasure itself.
	 *
	 * @var Anonymizer
	 */
	private Anonymizer $anonymizer;

	/**
	 * Constructor.
	 *
	 * @param Customer_Repository $customers  Customer storage.
	 * @param Anonymizer          $anonymizer The erasure.
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
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register' ) );
	}

	/**
	 * Register this eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Registered erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public function register( array $erasers ): array {
		$erasers[ self::GROUP ] = array(
			'eraser_friendly_name' => __( 'Cart and checkout recovery', 'kdc-wacr-recoveryflow' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Erase one page of RecoveryFlow's records for an address.
	 *
	 * @param string $email_address The address being erased.
	 * @param int    $page          One-based page number.
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$found = $this->customers->find_all_by_email_hash(
			Identity_Repository::hash_for( Identity::EMAIL, $email_address )
		);

		$page  = max( 1, $page );
		$index = $page - 1;

		if ( ! isset( $found[ $index ] ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed = $this->anonymizer->anonymize_customer( $found[ $index ]->id );

		return array(
			'items_removed'  => $removed,
			'items_retained' => true,
			'messages'       => array( Anonymizer::retained_notice() ),
			'done'           => ! isset( $found[ $index + 1 ] ),
		);
	}
}
