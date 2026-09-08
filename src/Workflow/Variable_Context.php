<?php
/**
 * The values a message template may use.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow;

use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * A closed list of fourteen strings, and nothing else.
 *
 * This class exists to be a wall. A template is a string an administrator
 * typed, and the obvious way to fill one in -- walk the placeholder path into
 * whatever object is to hand -- would let "{{customer.phone_e164}}" or
 * "{{journey.claim_token}}" put something in a WhatsApp message that has no
 * business being there, and would put the shape of the plugin's own objects
 * into a merchant-facing contract. So nothing is reached: the values are
 * resolved once, into a flat map of exactly the keys named in KEYS, and a
 * placeholder that is not one of them renders empty and is logged.
 *
 * Values are not HTML-escaped. They are bound for a JSON payload and then a
 * WhatsApp message, where an escaped ampersand would reach the customer as
 * "&amp;". Escaping happens where a value is shown in wp-admin, not here.
 */
final class Variable_Context {

	/**
	 * Every placeholder a template may use.
	 */
	public const KEYS = array(
		'customer.first_name',
		'customer.last_name',
		'recovery.total',
		'recovery.total_formatted',
		'recovery.currency',
		'recovery.item_count',
		'recovery.items_summary',
		'recovery.first_item_name',
		'recovery.recovery_url',
		'recovery.token',
		'recovery.opt_out_url',
		'site.name',
		'site.url',
		'source.name',
	);

	/**
	 * Currencies whose amounts have no minor unit.
	 */
	private const ZERO_DECIMAL = array( 'JPY', 'KRW', 'VND', 'CLP', 'ISK', 'XAF', 'XOF', 'RWF', 'UGX', 'PYG' );

	/**
	 * Symbols for the currencies a WhatsApp recovery message most often quotes.
	 *
	 * Not a complete table, and not meant to be: an unknown currency falls back
	 * to its ISO code, which is correct if plain. This exists because "INR 1499"
	 * in a message to a customer reads like a bug.
	 */
	private const SYMBOLS = array(
		'AED' => 'AED ',
		'AUD' => 'A$',
		'BRL' => 'R$',
		'CAD' => 'C$',
		'CHF' => 'CHF ',
		'CNY' => '¥',
		'EUR' => '€',
		'GBP' => '£',
		'HKD' => 'HK$',
		'IDR' => 'Rp',
		'INR' => '₹',
		'JPY' => '¥',
		'MXN' => 'MX$',
		'MYR' => 'RM',
		'NGN' => '₦',
		'NZD' => 'NZ$',
		'PHP' => '₱',
		'PKR' => '₨',
		'SAR' => 'SAR ',
		'SGD' => 'S$',
		'THB' => '฿',
		'TRY' => '₺',
		'USD' => '$',
		'ZAR' => 'R',
	);

	/**
	 * The resolved values, already narrowed to KEYS.
	 *
	 * @var array<string,string>
	 */
	private array $values;

	/**
	 * Logger, for placeholders nobody can fill.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $values Resolved values; anything outside KEYS is dropped.
	 * @param Logger|null          $logger Logger.
	 */
	public function __construct( array $values = array(), ?Logger $logger = null ) {
		$this->logger = $logger;
		$this->values = array();

		foreach ( self::KEYS as $key ) {
			$this->values[ $key ] = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
		}
	}

	/**
	 * Resolve every value for one recovery.
	 *
	 * @param Recovery_Event       $event       What was abandoned.
	 * @param Customer|null        $customer    Who abandoned it.
	 * @param array<string,string> $links       Keys recovery_url, opt_out_url and token.
	 * @param string               $source_name The source's display name.
	 * @param Logger|null          $logger      Logger.
	 * @return Variable_Context
	 */
	public static function build( Recovery_Event $event, ?Customer $customer, array $links, string $source_name = '', ?Logger $logger = null ): self {
		$currency = strtoupper( trim( $event->currency ) );

		return new self(
			array(
				'customer.first_name'      => null === $customer ? '' : $customer->first_name,
				'customer.last_name'       => null === $customer ? '' : $customer->last_name,
				'recovery.total'           => self::plain_amount( $event->amount, $currency ),
				'recovery.total_formatted' => self::format_money( $event->amount, $currency ),
				'recovery.currency'        => $currency,
				'recovery.item_count'      => (string) $event->item_count,
				'recovery.items_summary'   => $event->items_summary(),
				'recovery.first_item_name' => $event->first_item_name(),
				'recovery.recovery_url'    => (string) ( $links['recovery_url'] ?? '' ),
				'recovery.token'           => (string) ( $links['token'] ?? '' ),
				'recovery.opt_out_url'     => (string) ( $links['opt_out_url'] ?? '' ),
				'site.name'                => self::site_name(),
				'site.url'                 => home_url( '/' ),
				'source.name'              => $source_name,
			),
			$logger
		);
	}

	/**
	 * One value.
	 *
	 * @param string $key Placeholder name.
	 * @return string Empty when the name is not on the allow-list.
	 */
	public function get( string $key ): string {
		if ( array_key_exists( $key, $this->values ) ) {
			return $this->values[ $key ];
		}

		if ( $this->logger instanceof Logger ) {
			// The name is safe to log; it is something an administrator typed
			// into a template, not anything about a customer.
			$this->logger->warning(
				'workflow',
				'Template used a variable that does not exist',
				array( 'variable' => substr( $key, 0, 64 ) )
			);
		}

		return '';
	}

	/**
	 * Whether a name is on the allow-list.
	 *
	 * @param string $key Placeholder name.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->values );
	}

	/**
	 * Every resolved value.
	 *
	 * @return array<string,string>
	 */
	public function all(): array {
		return $this->values;
	}

	/**
	 * The allow-list itself, for the editor's variable picker.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return self::KEYS;
	}

	/**
	 * The site title as plain text.
	 *
	 * @return string
	 */
	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * An amount as a bare decimal, for templates that add their own symbol.
	 *
	 * @param string $amount   Decimal string as stored.
	 * @param string $currency ISO 4217 code.
	 * @return string
	 */
	private static function plain_amount( string $amount, string $currency ): string {
		return number_format( (float) $amount, self::decimals( $currency ), '.', '' );
	}

	/**
	 * An amount as a customer would expect to read it.
	 *
	 * @param string $amount   Decimal string as stored.
	 * @param string $currency ISO 4217 code.
	 * @return string
	 */
	private static function format_money( string $amount, string $currency ): string {
		$number = number_format_i18n( (float) $amount, self::decimals( $currency ) );

		if ( '' === $currency ) {
			return $number;
		}

		return ( self::SYMBOLS[ $currency ] ?? $currency . ' ' ) . $number;
	}

	/**
	 * How many decimal places a currency uses.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return int
	 */
	private static function decimals( string $currency ): int {
		return in_array( $currency, self::ZERO_DECIMAL, true ) ? 0 : 2;
	}
}
