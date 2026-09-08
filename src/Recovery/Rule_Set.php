<?php
/**
 * When a lost conversion is worth recovering.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Integration\Recovery_Source_Interface;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The thresholds that decide whether and when to act, resolved once.
 *
 * Three layers merge here: the plugin's defaults, the source's own overrides,
 * and the merchant's settings, in that order of increasing authority. A
 * ticketing source that knows an abandoned booking is worth chasing after five
 * minutes can say so, and a merchant who disagrees still wins.
 *
 * Resolving them into one object rather than reading options at each decision
 * point matters for a subtle reason: a background batch can run for twenty
 * seconds, and a setting saved halfway through must not change the rules for
 * half the batch.
 */
final class Rule_Set {

	/**
	 * Resolved values.
	 *
	 * @var array<string,mixed>
	 */
	private array $rules;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $rules Resolved rule values.
	 */
	public function __construct( array $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Build the rules that apply to a source.
	 *
	 * @param Recovery_Source_Interface|null $source The source, or null for site defaults.
	 * @return Rule_Set
	 */
	public static function for_source( ?Recovery_Source_Interface $source = null ): self {
		$settings = Options::all();
		$defaults = null === $source ? array() : $source->get_default_rules();

		// The source fills gaps; the merchant's saved settings always win.
		return new self( array_merge( $defaults, $settings ) );
	}

	/**
	 * One rule value.
	 *
	 * @param string $key      Rule name.
	 * @param mixed  $fallback Value when the rule is unset.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		return array_key_exists( $key, $this->rules ) ? $this->rules[ $key ] : $fallback;
	}

	/**
	 * How long a conversion must be untouched before it counts as abandoned.
	 *
	 * @return int Seconds.
	 */
	public function inactivity_seconds(): int {
		return max( 60, (int) $this->get( 'inactivity_minutes', 30 ) * 60 );
	}

	/**
	 * How old a conversion may be before it stops being worth recovering.
	 *
	 * @return int Seconds.
	 */
	public function max_age_seconds(): int {
		return max( 3600, (int) $this->get( 'max_age_days', 7 ) * DAY_IN_SECONDS );
	}

	/**
	 * The smallest value worth a message.
	 *
	 * @return float
	 */
	public function min_amount(): float {
		return (float) $this->get( 'min_amount', 0 );
	}

	/**
	 * How many messages one journey may send.
	 *
	 * @return int
	 */
	public function max_touches(): int {
		return max( 1, (int) $this->get( 'max_touches', 3 ) );
	}

	/**
	 * The shortest gap between two messages to the same person.
	 *
	 * @return int Seconds.
	 */
	public function frequency_cap_seconds(): int {
		return max( 0, (int) $this->get( 'frequency_cap_hours', 24 ) * HOUR_IN_SECONDS );
	}

	/**
	 * How many journeys one person may have in a rolling month.
	 *
	 * @return int
	 */
	public function max_journeys_per_month(): int {
		return max( 1, (int) $this->get( 'max_journeys_per_month', 3 ) );
	}

	/**
	 * How long after a message an order may still be credited to it.
	 *
	 * @return int Seconds.
	 */
	public function attribution_window_seconds(): int {
		return max( DAY_IN_SECONDS, (int) $this->get( 'attribution_window_days', 30 ) * DAY_IN_SECONDS );
	}

	/**
	 * How long a recovery link works for.
	 *
	 * @return int Seconds.
	 */
	public function link_ttl_seconds(): int {
		$days = (int) $this->get( 'recovery_link_ttl_days', 7 );

		return max( DAY_IN_SECONDS, min( 30, $days ) * DAY_IN_SECONDS );
	}

	/**
	 * Whether staff accounts are left alone.
	 *
	 * @return bool
	 */
	public function excludes_admins(): bool {
		return (bool) $this->get( 'exclude_admins', true );
	}

	/**
	 * Whether the site is set to message at all.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->get( 'enabled', false );
	}

	/**
	 * How the site justifies messaging: explicit_consent, identified_contact or disabled.
	 *
	 * @return string
	 */
	public function eligibility_mode(): string {
		return (string) $this->get( 'eligibility_mode', 'explicit_consent' );
	}

	/**
	 * Whether the site sends on this channel at all.
	 *
	 * WhatsApp defaults on because it is what the plugin is for. Email defaults
	 * OFF, and that is deliberate rather than cautious: a recovery email is
	 * commercial mail, which in most jurisdictions has to carry the sender's
	 * postal address and a working unsubscribe, and a site that has not been
	 * asked for either cannot lawfully send one. Turning it on is a decision
	 * the merchant makes with that in front of them, not a default they
	 * discover afterwards.
	 *
	 * Email therefore passes two gates rather than one: the merchant has to
	 * switch it on, and the compliance settings the law requires have to be
	 * there. The second gate is the reason the first one is safe to expose at
	 * all -- a setting written by WP-CLI, by a migration or by a plugin that
	 * "turns everything on" cannot start unlawful mail on its own.
	 *
	 * @param string $channel Channel name.
	 * @return bool
	 */
	public function channel_enabled( string $channel ): bool {
		if ( ! Channel::is_channel( $channel ) ) {
			return false;
		}

		if ( ! (bool) $this->get( 'channel_' . $channel . '_enabled', Channel::WHATSAPP === $channel ) ) {
			return false;
		}

		return Channel::EMAIL !== $channel || array() === $this->email_compliance_blockers();
	}

	/**
	 * What is stopping this site from sending recovery email.
	 *
	 * Answered from the resolved snapshot rather than from the database, for
	 * the same reason every other rule here is: a batch that started under one
	 * set of settings finishes under them.
	 *
	 * @return string[] Email_Compliance reason codes; empty when nothing blocks.
	 */
	public function email_compliance_blockers(): array {
		return Email_Compliance::blockers( $this->rules );
	}

	/**
	 * Whether quiet hours are observed.
	 *
	 * @return bool
	 */
	public function quiet_hours_enabled(): bool {
		return (bool) $this->get( 'quiet_hours_enabled', true );
	}

	/**
	 * When quiet hours begin, as HH:MM in the site's timezone.
	 *
	 * @return string
	 */
	public function quiet_hours_start(): string {
		return (string) $this->get( 'quiet_hours_start', '21:00' );
	}

	/**
	 * When quiet hours end, as HH:MM in the site's timezone.
	 *
	 * @return string
	 */
	public function quiet_hours_end(): string {
		return (string) $this->get( 'quiet_hours_end', '09:00' );
	}
}
