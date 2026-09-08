<?php
/**
 * What one run of one stage did.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs;

use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The record of a background run, kept so somebody can see the thing working.
 *
 * Background work that reports nothing is indistinguishable from background
 * work that is not running, and "is it running?" is the first question anybody
 * asks when no messages have gone out. So each stage's last run is stored and
 * shown on System Status: when it ran, how long it took, how much it did, and
 * how much it did not get to.
 *
 * Lost races are counted apart from failures on purpose. A conversion landing
 * while a batch is deciding to message that customer is the system working
 * exactly as intended, and filing it under "failed" would have a merchant
 * chasing a fault that does not exist.
 *
 * Only counts and codes are stored. Nothing here identifies a customer.
 */
final class Stage_Stats {

	/**
	 * Which stage this describes.
	 *
	 * @var string
	 */
	public string $stage = '';

	/**
	 * Rows this run acted on.
	 *
	 * @var int
	 */
	public int $processed = 0;

	/**
	 * Rows this run deliberately left alone.
	 *
	 * @var int
	 */
	public int $skipped = 0;

	/**
	 * Rows this run could not complete.
	 *
	 * @var int
	 */
	public int $failed = 0;

	/**
	 * Rows another writer changed first.
	 *
	 * @var int
	 */
	public int $lost_race = 0;

	/**
	 * Rows known to be still waiting when the run stopped.
	 *
	 * @var int
	 */
	public int $backlog = 0;

	/**
	 * How long the run took, in seconds.
	 *
	 * @var float
	 */
	public float $duration = 0.0;

	/**
	 * The last thing that went wrong, as a short machine-readable code.
	 *
	 * @var string
	 */
	public string $last_error = '';

	/**
	 * When the run finished, as a UTC datetime string.
	 *
	 * @var string
	 */
	public string $ran_at = '';

	/**
	 * Constructor.
	 *
	 * @param string $stage Which stage this describes.
	 */
	public function __construct( string $stage = '' ) {
		$this->stage = $stage;
	}

	/**
	 * Whether the stage stopped with work still waiting.
	 *
	 * @return bool
	 */
	public function has_backlog(): bool {
		return $this->backlog > 0;
	}

	/**
	 * Note that something went wrong, keeping the first cause rather than the last.
	 *
	 * The first failure in a batch is usually the one that explains the rest --
	 * a rejected API key produces fifty identical errors, and only the first
	 * one tells anybody anything.
	 *
	 * @param string $code Short machine-readable code.
	 * @return void
	 */
	public function fail( string $code ): void {
		++$this->failed;

		if ( '' === $this->last_error ) {
			$this->last_error = substr( $code, 0, 32 );
		}
	}

	/**
	 * Flatten for storage.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'stage'      => $this->stage,
			'processed'  => $this->processed,
			'skipped'    => $this->skipped,
			'failed'     => $this->failed,
			'lost_race'  => $this->lost_race,
			'backlog'    => $this->backlog,
			'duration'   => $this->duration,
			'last_error' => $this->last_error,
			'ran_at'     => $this->ran_at,
		);
	}

	/**
	 * Rebuild from storage.
	 *
	 * Every field is optional: an older stored shape must not fatal a status
	 * screen, it must simply show zeroes.
	 *
	 * @param array<string,mixed> $row Stored values.
	 * @return Stage_Stats
	 */
	public static function from_array( array $row ): self {
		$stats = new self( isset( $row['stage'] ) ? (string) $row['stage'] : '' );

		$stats->processed  = isset( $row['processed'] ) ? (int) $row['processed'] : 0;
		$stats->skipped    = isset( $row['skipped'] ) ? (int) $row['skipped'] : 0;
		$stats->failed     = isset( $row['failed'] ) ? (int) $row['failed'] : 0;
		$stats->lost_race  = isset( $row['lost_race'] ) ? (int) $row['lost_race'] : 0;
		$stats->backlog    = isset( $row['backlog'] ) ? (int) $row['backlog'] : 0;
		$stats->duration   = isset( $row['duration'] ) ? (float) $row['duration'] : 0.0;
		$stats->last_error = isset( $row['last_error'] ) ? (string) $row['last_error'] : '';
		$stats->ran_at     = isset( $row['ran_at'] ) ? (string) $row['ran_at'] : '';

		return $stats;
	}

	/**
	 * Store this as the stage's last run.
	 *
	 * The option is not autoloaded: it is read by one admin screen and written
	 * by every background run, which is the opposite of what autoloading is for.
	 *
	 * @return void
	 */
	public function save(): void {
		if ( '' === $this->stage ) {
			return;
		}

		$all                 = self::stored();
		$all[ $this->stage ] = $this->to_array();

		// Bounded to the stages that exist, so a renamed or removed stage
		// cannot leave a row growing quietly in an option for ever.
		$all = array_intersect_key( $all, array_flip( Scheduler_Interface::STAGES ) );

		update_option( Options::STAGE_STATS, $all, false );
	}

	/**
	 * The last run of every stage.
	 *
	 * @return array<string,Stage_Stats>
	 */
	public static function all(): array {
		$out = array();

		foreach ( self::stored() as $stage => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row['stage']  = (string) $stage;
			$out[ $stage ] = self::from_array( $row );
		}

		return $out;
	}

	/**
	 * The last run of one stage.
	 *
	 * @param string $stage Stage key.
	 * @return Stage_Stats|null
	 */
	public static function for_stage( string $stage ): ?self {
		$all = self::all();

		return $all[ $stage ] ?? null;
	}

	/**
	 * Forget every recorded run.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_option( Options::STAGE_STATS );
	}

	/**
	 * The raw stored option.
	 *
	 * @return array<string,mixed>
	 */
	public static function stored(): array {
		$all = get_option( Options::STAGE_STATS, array() );

		return is_array( $all ) ? $all : array();
	}
}
