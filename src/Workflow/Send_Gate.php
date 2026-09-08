<?php
/**
 * The last check before a recovery message goes out.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Support\Options;
use WAcr\RecoveryFlow\WAcr\Client;
use WAcr\RecoveryFlow\WAcr\Rate_Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Answers one question: may this message go out right now?
 *
 * Eligibility_Evaluator has already asked whether this person may be messaged
 * at all -- consent, opt-out, phone, the frequency cap. This class asks only
 * about the things that are true of the moment rather than of the person, and
 * it asks them again here because a scheduled message can sit for a day between
 * the two checks.
 *
 * The distinction between deferring and skipping is the useful part. Quiet
 * hours and a paused rate budget are reasons to send later, and the answer
 * carries the time to come back at, so the journey is not merely dropped from
 * this batch and retried in a minute. Reaching the maximum number of touches is
 * a reason never to send on this journey again, and the answer says so, so the
 * caller stops the workflow instead of asking again every tick until the
 * journey expires.
 *
 * Quiet hours are read in the site's timezone, not UTC. A shop in Kolkata
 * setting "no messages after 21:00" means nine in the evening where the shop
 * is, and a window that runs from 21:00 to 09:00 crosses midnight, which is the
 * case a naive comparison gets wrong in exactly the direction that messages
 * somebody at three in the morning.
 */
final class Send_Gate {

	public const ALLOW = 'allow';
	public const DEFER = 'defer';
	public const SKIP  = 'skip';

	/**
	 * Why a send was held back or abandoned.
	 */
	public const REASON_STATE       = 'journey_state';
	public const REASON_MAX_TOUCHES = 'max_touches';
	public const REASON_PAUSED      = 'rate_paused';
	public const REASON_BUDGET      = 'rate_budget';
	public const REASON_QUIET       = 'quiet_hours';
	public const REASON_RECIPIENT   = 'no_recipient';
	public const REASON_WACR_OPTOUT = 'wacr_opted_out';

	/**
	 * How long WA.cr's opt-out flag is trusted for.
	 *
	 * Six hours because it changes rarely and every check costs a request
	 * against the merchant's shared rate limit.
	 */
	private const OPTOUT_CACHE_TTL = 21600;

	/**
	 * Prefix for the cached opt-out flag. Keyed on the phone hash, never the number.
	 */
	private const OPTOUT_CACHE_PREFIX = 'recoveryflow_wacr_optout_';

	/**
	 * Request budget.
	 *
	 * @var Rate_Budget
	 */
	private Rate_Budget $budget;

	/**
	 * WA.cr client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Rate_Budget $budget Request budget.
	 * @param Client      $client WA.cr client.
	 * @param Logger      $logger Logger.
	 * @param Clock       $clock  Clock.
	 */
	public function __construct( Rate_Budget $budget, Client $client, Logger $logger, Clock $clock ) {
		$this->budget = $budget;
		$this->client = $client;
		$this->logger = $logger;
		$this->clock  = $clock;
	}

	/**
	 * Decide whether this journey may be messaged now.
	 *
	 * @param Recovery_Journey $journey  The journey.
	 * @param Customer|null    $customer Who would be messaged.
	 * @param Rule_Set         $rules    The thresholds in force.
	 * @return array{decision:string,until:string,reason:string,stop:bool}
	 */
	public function check( Recovery_Journey $journey, ?Customer $customer, Rule_Set $rules ): array {
		if ( ! $journey->may_send() ) {
			return self::skip( self::REASON_STATE, true );
		}

		// Counted from attempts that actually reached WA.cr, so a run of
		// network failures cannot silently consume a merchant's touch budget.
		if ( $journey->attempts_count >= $rules->max_touches() ) {
			return self::skip( self::REASON_MAX_TOUCHES, true );
		}

		$paused = $this->budget->paused_until();

		if ( $paused > 0 ) {
			return $this->defer( $paused, self::REASON_PAUSED );
		}

		// Checked, never taken: Client::send_template() debits the budget
		// itself, and taking it here as well would halve the real allowance.
		if ( $this->budget->remaining() < 1 ) {
			return $this->defer( $this->next_minute(), self::REASON_BUDGET );
		}

		$now     = $this->clock->timestamp();
		$shifted = $this->quiet_shift( $now, $rules );

		if ( $shifted > $now ) {
			return $this->defer( $shifted, self::REASON_QUIET );
		}

		if ( null === $customer || ! $customer->is_messageable() ) {
			return self::skip( self::REASON_RECIPIENT, true );
		}

		if ( $this->is_opted_out_at_wacr( $customer ) ) {
			return self::skip( self::REASON_WACR_OPTOUT, true );
		}

		return self::allow();
	}

	/**
	 * Move a moment out of quiet hours, if it falls inside them.
	 *
	 * @param int      $timestamp Unix timestamp.
	 * @param Rule_Set $rules     The thresholds in force.
	 * @return int The same timestamp, or the moment quiet hours end.
	 */
	public function quiet_shift( int $timestamp, Rule_Set $rules ): int {
		$window = self::window( $rules );

		if ( null === $window ) {
			return $timestamp;
		}

		$zone  = wp_timezone();
		$local = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $zone );

		if ( ! self::inside( self::minutes( $local ), $window['start'], $window['end'] ) ) {
			return $timestamp;
		}

		$resume = $local->setTime( intdiv( $window['end'], 60 ), $window['end'] % 60, 0 );

		if ( $resume->getTimestamp() <= $timestamp ) {
			$resume = $resume->modify( '+1 day' );
		}

		$shifted = $resume->getTimestamp();

		// A guard rather than an expectation: a timezone database that produced
		// a resume time in the past would otherwise park the journey forever.
		return $shifted > $timestamp ? $shifted : $timestamp + HOUR_IN_SECONDS;
	}

	/**
	 * Whether a moment falls inside quiet hours.
	 *
	 * @param int      $timestamp Unix timestamp.
	 * @param Rule_Set $rules     The thresholds in force.
	 * @return bool
	 */
	public function in_quiet_hours( int $timestamp, Rule_Set $rules ): bool {
		return $this->quiet_shift( $timestamp, $rules ) > $timestamp;
	}

	/**
	 * Read WA.cr's own opt-out flag for a number.
	 *
	 * WA.cr does not apply it to direct sends, so somebody who told the
	 * business to stop messaging them would otherwise receive a cart reminder
	 * because the refusal was recorded in a different system.
	 *
	 * A failed lookup allows the send. The plugin's own consent ledger is the
	 * authority and has already said yes; letting a WA.cr outage stop every
	 * recovery on the site would be a worse failure than a stale flag.
	 *
	 * @param Customer $customer The person.
	 * @return bool
	 */
	private function is_opted_out_at_wacr( Customer $customer ): bool {
		/*
		 * The setting is read here, and it defaults to ON.
		 *
		 * It had a control and a default and nothing read it, so this check ran
		 * whenever the credential held contacts:read, whatever the merchant had
		 * chosen. Wiring it up with its original default of false would have
		 * been the obvious fix and a bad one: every site already running would
		 * have stopped honouring WA.cr opt-outs on upgrade, and somebody who
		 * replied STOP in WhatsApp would have started receiving cart reminders
		 * again. A dead setting is a bug; silently switching off a live
		 * compliance behaviour to fix it is a worse one.
		 *
		 * So the default is true, which is exactly what every install has been
		 * doing, and the control now genuinely does what its label says.
		 */
		if ( ! Options::get( 'wacr_sync_optout', true ) ) {
			return false;
		}

		if ( '' === $customer->phone_hash || ! Feature_Gate::has_scope( 'contacts:read' ) ) {
			return false;
		}

		$key    = self::OPTOUT_CACHE_PREFIX . substr( $customer->phone_hash, 0, 32 );
		$cached = get_transient( $key );

		if ( '1' === $cached || '0' === $cached ) {
			return '1' === $cached;
		}

		$result = $this->client->find_contact( $customer->phone_e164 );

		if ( ! $result->ok ) {
			return false;
		}

		$contact   = $result->get( 'contact' );
		$opted_out = is_array( $contact ) && self::reads_as_opted_out( $contact );

		set_transient( $key, $opted_out ? '1' : '0', self::OPTOUT_CACHE_TTL );

		if ( $opted_out ) {
			$this->logger->info( 'workflow', 'Send withheld: the contact is opted out in WA.cr' );
		}

		return $opted_out;
	}

	/**
	 * Whether a WA.cr contact record says the person opted out.
	 *
	 * Several shapes are accepted because the field has been spelled more than
	 * one way across WA.cr's own surfaces, and reading the wrong one would
	 * message somebody who has said stop.
	 *
	 * @param array<string,mixed> $contact The contact record.
	 * @return bool
	 */
	private static function reads_as_opted_out( array $contact ): bool {
		foreach ( array( 'optedOut', 'opted_out', 'optOut', 'isOptedOut' ) as $field ) {
			if ( isset( $contact[ $field ] ) && ! empty( $contact[ $field ] ) ) {
				return true;
			}
		}

		$status = isset( $contact['status'] ) ? strtolower( (string) $contact['status'] ) : '';

		return in_array( $status, array( 'opted_out', 'optedout', 'unsubscribed', 'blocked' ), true );
	}

	/**
	 * The configured quiet window, as minutes past local midnight.
	 *
	 * @param Rule_Set $rules The thresholds in force.
	 * @return array{start:int,end:int}|null Null when there is no window to observe.
	 */
	private static function window( Rule_Set $rules ): ?array {
		if ( ! $rules->quiet_hours_enabled() ) {
			return null;
		}

		$start = self::to_minutes( $rules->quiet_hours_start() );
		$end   = self::to_minutes( $rules->quiet_hours_end() );

		// Equal times are read as "no quiet hours" rather than "quiet all day":
		// the second reading would silently stop every message on the site with
		// no error anywhere, which is the hardest kind of bug to find.
		if ( null === $start || null === $end || $start === $end ) {
			return null;
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Read an HH:MM setting.
	 *
	 * @param string $value The setting.
	 * @return int|null Minutes past midnight, or null when unreadable.
	 */
	private static function to_minutes( string $value ): ?int {
		$matches = array();

		if ( 1 !== preg_match( '/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', trim( $value ), $matches ) ) {
			return null;
		}

		return (int) $matches[1] * 60 + (int) $matches[2];
	}

	/**
	 * Minutes past local midnight for a moment.
	 *
	 * @param \DateTimeImmutable $local The moment, in the site's timezone.
	 * @return int
	 */
	private static function minutes( \DateTimeImmutable $local ): int {
		return (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' );
	}

	/**
	 * Whether a minute of the day falls in the window.
	 *
	 * @param int $minute Minutes past midnight.
	 * @param int $start  Window start.
	 * @param int $end    Window end.
	 * @return bool
	 */
	private static function inside( int $minute, int $start, int $end ): bool {
		if ( $start > $end ) {
			// The window crosses midnight: 21:00 to 09:00 is late evening or
			// early morning, not the eleven hours in between.
			return $minute >= $start || $minute < $end;
		}

		return $minute >= $start && $minute < $end;
	}

	/**
	 * The start of the next rate-limit window.
	 *
	 * @return int
	 */
	private function next_minute(): int {
		return ( (int) floor( $this->clock->timestamp() / MINUTE_IN_SECONDS ) + 1 ) * MINUTE_IN_SECONDS;
	}

	/**
	 * A yes.
	 *
	 * @return array{decision:string,until:string,reason:string,stop:bool}
	 */
	private static function allow(): array {
		return array(
			'decision' => self::ALLOW,
			'until'    => '',
			'reason'   => '',
			'stop'     => false,
		);
	}

	/**
	 * A not-yet, with the moment to come back at.
	 *
	 * @param int    $timestamp When to try again.
	 * @param string $reason    One of the REASON_ constants.
	 * @return array{decision:string,until:string,reason:string,stop:bool}
	 */
	private function defer( int $timestamp, string $reason ): array {
		return array(
			'decision' => self::DEFER,
			'until'    => $this->clock->at( $timestamp ),
			'reason'   => $reason,
			'stop'     => false,
		);
	}

	/**
	 * A no.
	 *
	 * @param string $reason One of the REASON_ constants.
	 * @param bool   $stop   Whether this journey should stop asking.
	 * @return array{decision:string,until:string,reason:string,stop:bool}
	 */
	private static function skip( string $reason, bool $stop ): array {
		return array(
			'decision' => self::SKIP,
			'until'    => '',
			'reason'   => $reason,
			'stop'     => $stop,
		);
	}
}
