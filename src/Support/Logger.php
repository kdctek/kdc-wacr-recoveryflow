<?php
/**
 * Diagnostic logging.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Support;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Database\Table_Names;
use WAcr\RecoveryFlow\Privacy\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Writes what happened, without writing who it happened to.
 *
 * Every context array goes through the redactor before it is stored, and the
 * message itself is scrubbed too, because the interesting failures are the ones
 * where somebody else's error string carries a phone number.
 *
 * The table is bounded and pruned by the retention job. A recovery plugin on a
 * busy store would otherwise fill a database with rows nobody will ever read.
 */
class Logger {

	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * Severity order, lowest first.
	 *
	 * @var string[]
	 */
	private const LEVELS = array( self::DEBUG, self::INFO, self::WARNING, self::ERROR );

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Clock $clock Clock.
	 */
	public function __construct( Clock $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Record something.
	 *
	 * @param string   $level      One of the level constants.
	 * @param string   $category   Subsystem, e.g. 'wacr' or 'woocommerce'.
	 * @param string   $message    What happened, in words.
	 * @param array    $context    Structured detail. Redacted before storage.
	 * @param int|null $journey_id Journey this concerns, if any.
	 * @return void
	 */
	public function log( string $level, string $category, string $message, array $context = array(), ?int $journey_id = null ): void {
		if ( ! $this->should_record( $level ) ) {
			return;
		}

		global $wpdb;

		$table = Table_Names::get( Table_Names::LOGS );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'level'        => $level,
				'category'     => substr( $category, 0, 32 ),
				'journey_id'   => $journey_id,
				'message'      => substr( Redactor::scrub_string( $message ), 0, 255 ),
				'context_json' => array() === $context ? null : wp_json_encode( Redactor::scrub( $context ) ),
				'created_at'   => $this->clock->now(),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Whether this level is worth storing at the configured threshold.
	 *
	 * @param string $level Level.
	 * @return bool
	 */
	private function should_record( string $level ): bool {
		$threshold = (string) Options::get( 'logging_level', self::WARNING );

		$at    = array_search( $level, self::LEVELS, true );
		$floor = array_search( $threshold, self::LEVELS, true );

		if ( false === $at ) {
			return false;
		}

		return false === $floor || $at >= $floor;
	}

	/**
	 * Record a debugging detail.
	 *
	 * @param string   $category   Subsystem.
	 * @param string   $message    Message.
	 * @param array    $context    Context.
	 * @param int|null $journey_id Journey.
	 * @return void
	 */
	public function debug( string $category, string $message, array $context = array(), ?int $journey_id = null ): void {
		$this->log( self::DEBUG, $category, $message, $context, $journey_id );
	}

	/**
	 * Record something notable that is not a problem.
	 *
	 * @param string   $category   Subsystem.
	 * @param string   $message    Message.
	 * @param array    $context    Context.
	 * @param int|null $journey_id Journey.
	 * @return void
	 */
	public function info( string $category, string $message, array $context = array(), ?int $journey_id = null ): void {
		$this->log( self::INFO, $category, $message, $context, $journey_id );
	}

	/**
	 * Record a problem the plugin handled.
	 *
	 * @param string   $category   Subsystem.
	 * @param string   $message    Message.
	 * @param array    $context    Context.
	 * @param int|null $journey_id Journey.
	 * @return void
	 */
	public function warning( string $category, string $message, array $context = array(), ?int $journey_id = null ): void {
		$this->log( self::WARNING, $category, $message, $context, $journey_id );
	}

	/**
	 * Record a problem that needs somebody's attention.
	 *
	 * @param string   $category   Subsystem.
	 * @param string   $message    Message.
	 * @param array    $context    Context.
	 * @param int|null $journey_id Journey.
	 * @return void
	 */
	public function error( string $category, string $message, array $context = array(), ?int $journey_id = null ): void {
		$this->log( self::ERROR, $category, $message, $context, $journey_id );
	}
}
