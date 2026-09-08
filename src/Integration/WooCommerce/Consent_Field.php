<?php
/**
 * Asking the shopper whether they want to be messaged.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\WooCommerce;

use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The consent tick-box, on both checkouts, in the one place it can be read back.
 *
 * Only rendered in explicit_consent mode. In the other modes the question is
 * not being asked, and a box that is shown but never acted on is worse than no
 * box at all.
 *
 * The block checkout registration is the part with a trap in it. WooCommerce
 * persists a contact- or order-location additional field ONLY onto the order,
 * at POST /checkout, with $set_customer = false. An abandoned checkout has no
 * order. A consent box at the contact location would therefore be shown to
 * every shopper, ticked by some of them, and then be permanently unreadable by
 * the plugin that asked -- consent collected and thrown away, which is the
 * worst possible outcome for a privacy control. An address-location field is
 * written to the customer and into the customer session on
 * POST /cart/update-customer, as the shopper types, which is what makes it
 * usable for recovery at all. The cost is that WooCommerce renders address
 * fields in both the billing and shipping forms, so the location is filterable
 * for sites that would rather pay a different price.
 *
 * The wording is hashed into a text version and stored with the consent record,
 * so "what exactly did this person agree to" has an answer years later, even
 * after the label has been reworded.
 */
final class Consent_Field {

	/**
	 * Field name on the classic checkout.
	 */
	public const FIELD_ID = 'recoveryflow_whatsapp_consent';

	/**
	 * Field id on the block checkout, which must carry a namespace.
	 */
	public const BLOCK_ID = 'recoveryflow/whatsapp-consent';

	/**
	 * Where the answer came from, recorded on the consent record.
	 */
	public const SOURCE_CLASSIC = 'checkout_classic';

	/**
	 * Where the answer came from, recorded on the consent record.
	 */
	public const SOURCE_BLOCKS = 'checkout_blocks';

	/**
	 * Guarded access to the WooCommerce session.
	 *
	 * @var Session
	 */
	private Session $session;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Whether the block field has been declared on this request.
	 *
	 * @var bool
	 */
	private bool $declared = false;

	/**
	 * Constructor.
	 *
	 * @param Session $session Guarded session access.
	 * @param Logger  $logger  Logger.
	 */
	public function __construct( Session $session, Logger $logger ) {
		$this->session = $session;
		$this->logger  = $logger;
	}

	/**
	 * Attach to WooCommerce, if the site asks for consent at all.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->is_asked() ) {
			return;
		}

		add_filter( 'woocommerce_checkout_fields', array( $this, 'add_classic_field' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_order_review' ), 20, 1 );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'capture_posted_data' ) );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( $this, 'capture_blocks' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'capture_blocks' ) );

		// The block field has to be declared on woocommerce_init; if the source
		// registry ran later than that, declaring it now is the same thing.
		if ( did_action( 'woocommerce_init' ) ) {
			$this->declare_block_field();

			return;
		}

		add_action( 'woocommerce_init', array( $this, 'declare_block_field' ) );
	}

	/**
	 * Add the tick-box to the classic checkout's billing fields.
	 *
	 * Priority 115 puts it below the phone and email fields, which WooCommerce
	 * gives 100 and 110, so the question follows the number it is about.
	 *
	 * update_totals_on_change is what makes the answer readable at all before
	 * the shopper leaves. WooCommerce reads the classic checkout back only
	 * during its update_order_review AJAX call, and checkout.js asks for that
	 * call from a fixed list of selectors: address fields, and anything inside
	 * a .update_totals_on_change container. The phone and email fields are in
	 * neither list -- WooCommerce declares them plain form-row-wide -- so
	 * typing a number fires nothing, and a shopper who answers the question and
	 * then abandons was, until this class was added, never recorded as having
	 * answered. With the class on the wrapper, checkout.js's own
	 * change-on-checkbox binding runs trigger_update_checkout, and because that
	 * request posts the whole serialised form, the one tick carries the consent
	 * AND whatever phone and email have been typed so far. It is WooCommerce's
	 * own mechanism, on WooCommerce's own nonce -- the same class core puts on
	 * its country field.
	 *
	 * The cost is one extra checkout AJAX round trip each time the box is
	 * toggled, which is the price of the answer surviving abandonment.
	 *
	 * This class is the floor rather than the whole answer, and it is worth
	 * being clear about which half it covers. It fires only when the tick-box
	 * is ticked, so it covers explicit_consent mode and nothing else: in
	 * identified_contact mode is_asked() renders no box at all, there is
	 * nothing for the class to sit on, and a shopper who types a number and
	 * leaves is still lost. Checkout_Script closes that half by asking for the
	 * same round trip when the phone or email field is left. The two are
	 * deliberately not merged -- this one keeps working when the script fails
	 * to load, so the worst case degrades to the old behaviour rather than to
	 * nothing.
	 *
	 * This fixes the CLASSIC checkout only, and nothing here reaches the block
	 * checkout -- Blocks renders no form-row wrapper and loads none of
	 * checkout.js, so the class is inert there. Blocks is covered separately,
	 * and for a different reason: its field is registered at the address
	 * location, which the Store API writes to the customer as the shopper
	 * types. The two checkouts stay two problems.
	 *
	 * @param mixed $fields The checkout field groups.
	 * @return mixed
	 */
	public function add_classic_field( $fields ) {
		if ( ! is_array( $fields ) || ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
			return $fields;
		}

		$fields['billing'][ self::FIELD_ID ] = array(
			'type'     => 'checkbox',
			// WooCommerce prints a field label without escaping it, so the site
			// name -- which an administrator can set to anything -- is escaped
			// here rather than trusted.
			'label'    => esc_html( $this->label() ),
			'required' => false,
			'default'  => 0,
			'class'    => array( 'form-row-wide', 'update_totals_on_change' ),
			'priority' => 115,
		);

		return $fields;
	}

	/**
	 * Read the answer out of the classic checkout as the shopper edits it.
	 *
	 * @param mixed $post_data Raw, URL-encoded serialisation of the checkout form.
	 * @return void
	 */
	public function capture_order_review( $post_data ): void {
		try {
			if ( ! is_string( $post_data ) || '' === $post_data ) {
				return;
			}

			$parsed = array();

			wp_parse_str( $post_data, $parsed );

			if ( ! is_array( $parsed ) || ! $this->looks_like_checkout( $parsed ) ) {
				return;
			}

			// An unticked checkbox is simply absent from a serialised form. On
			// a form that is demonstrably the checkout, absence is a refusal
			// rather than a missing answer, and the two are recorded
			// differently.
			$this->remember( $this->is_ticked( $parsed[ self::FIELD_ID ] ?? null ), self::SOURCE_CLASSIC );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_consent', 'Could not read the classic consent field.' );
		}//end try
	}

	/**
	 * Read the answer out of the data WooCommerce collected at Place Order.
	 *
	 * The order-review hook never fires on a tick, so for a shopper who goes on
	 * to buy, this is the only moment the answer is certain.
	 *
	 * @param mixed $data The posted checkout data.
	 * @return mixed
	 */
	public function capture_posted_data( $data ) {
		try {
			if ( is_array( $data ) && array_key_exists( self::FIELD_ID, $data ) ) {
				$this->remember( $this->is_ticked( $data[ self::FIELD_ID ] ), self::SOURCE_CLASSIC );
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_consent', 'Could not read the posted consent field.' );
		}

		return $data;
	}

	/**
	 * Read the answer off the block checkout's customer object.
	 *
	 * @return void
	 */
	public function capture_blocks(): void {
		try {
			$value = $this->read_block_value();

			if ( null === $value ) {
				return;
			}

			$this->remember( $value, self::SOURCE_BLOCKS );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_consent', 'Could not read the block consent field.' );
		}
	}

	/**
	 * Declare the field to the block checkout.
	 *
	 * @return void
	 */
	public function declare_block_field(): void {
		try {
			if ( $this->declared || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
				return;
			}

			$this->declared = true;

			/**
			 * Filters where the block checkout renders the consent tick-box.
			 *
			 * Only 'address' is persisted before an order exists, so anything
			 * else makes the answer unreadable for an abandoned checkout. Change
			 * it only if you are collecting consent for some other purpose.
			 *
			 * @param string $location One of 'address', 'contact' or 'order'.
			 */
			$location = (string) apply_filters( Hooks::FILTER_BLOCKS_CONSENT_LOCATION, 'address' );

			if ( ! in_array( $location, array( 'address', 'contact', 'order' ), true ) ) {
				$location = 'address';
			}

			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::BLOCK_ID,
					'label'    => $this->label(),
					'location' => $location,
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_consent', 'Could not declare the block consent field.' );
		}//end try
	}

	/**
	 * The wording the shopper is asked to agree to.
	 *
	 * @return string Plain text, unescaped.
	 */
	public function label(): string {
		$custom = (string) Options::get( 'consent_label', '' );

		if ( '' !== trim( $custom ) ) {
			return wp_strip_all_tags( $custom );
		}

		return sprintf(
			/* translators: %s: the name of this shop. */
			__( 'Yes, %s may send me a WhatsApp message about my order if I do not finish checking out.', 'kdc-wacr-recoveryflow' ),
			wp_strip_all_tags( (string) get_bloginfo( 'name' ) )
		);
	}

	/**
	 * A short, stable identifier for the exact wording that was agreed to.
	 *
	 * Derived from the wording itself, so rewording the question produces a new
	 * version without anybody having to remember to bump one.
	 *
	 * @return string
	 */
	public function text_version(): string {
		return 'wc' . substr( hash( 'sha256', $this->label() ), 0, 10 );
	}

	/**
	 * Whether this site asks for consent at all.
	 *
	 * @return bool
	 */
	private function is_asked(): bool {
		return 'explicit_consent' === (string) Options::get( 'eligibility_mode', 'explicit_consent' );
	}

	/**
	 * Whether a parsed form is recognisably the checkout.
	 *
	 * Without this, a serialised form from somewhere else -- an order-pay page,
	 * another plugin reusing the hook -- would have no consent field in it, and
	 * the missing field would be recorded as a refusal the shopper never made.
	 *
	 * @param array<string,mixed> $parsed Parsed form values.
	 * @return bool
	 */
	private function looks_like_checkout( array $parsed ): bool {
		foreach ( array( 'billing_country', 'billing_email', 'billing_first_name', 'billing_postcode' ) as $marker ) {
			if ( array_key_exists( $marker, $parsed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a submitted checkbox value means "yes".
	 *
	 * @param mixed $value The submitted value, or null when the box was absent.
	 * @return bool
	 */
	private function is_ticked( $value ): bool {
		if ( null === $value || ! is_scalar( $value ) ) {
			return false;
		}

		$value = strtolower( trim( (string) $value ) );

		return '' !== $value && '0' !== $value && 'false' !== $value && 'no' !== $value;
	}

	/**
	 * Read the block checkout's stored answer off the customer.
	 *
	 * @return bool|null Null when there is no answer to read.
	 */
	private function read_block_value(): ?bool {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
			return null;
		}

		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields' ) ) {
			return null;
		}

		$customer = $this->session->customer();

		if ( null === $customer ) {
			return null;
		}

		$container = \Automattic\WooCommerce\Blocks\Package::container();

		if ( ! is_object( $container ) || ! method_exists( $container, 'get' ) ) {
			return null;
		}

		$fields = $container->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );

		if ( ! is_object( $fields ) || ! method_exists( $fields, 'get_field_from_object' ) ) {
			return null;
		}

		foreach ( array( 'billing', 'shipping' ) as $group ) {
			$value = $fields->get_field_from_object( self::BLOCK_ID, $customer, $group );

			// Stored as the string '1' or '0', so an untouched field and a
			// deliberate "no" are told apart by emptiness, not by falsiness.
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return '1' === (string) $value;
			}
		}

		return null;
	}

	/**
	 * Keep the answer in the session for the shutdown flush to pick up.
	 *
	 * @param bool   $granted Whether the shopper agreed.
	 * @param string $source  Which checkout the answer came from.
	 * @return void
	 */
	private function remember( bool $granted, string $source ): void {
		$version = $this->text_version();
		$stored  = $this->session->get( Session::KEY_CONSENT, array() );
		$stored  = is_array( $stored ) ? $stored : array();

		if ( isset( $stored['granted'] ) && (bool) $stored['granted'] === $granted
			&& (string) ( $stored['text_version'] ?? '' ) === $version ) {
			return;
		}

		$this->session->set(
			Session::KEY_CONSENT,
			array(
				'granted'      => $granted,
				'source'       => $source,
				'text_version' => $version,
			)
		);
	}
}
