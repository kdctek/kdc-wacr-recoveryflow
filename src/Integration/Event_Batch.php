<?php
/**
 * A page of drafts from a pollable source.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration;

use WAcr\RecoveryFlow\Recovery\Event_Draft;

defined( 'ABSPATH' ) || exit;

/**
 * What a pollable source hands back, and where to resume.
 *
 * The cursor is opaque to the core: a source may use a row id, a timestamp or
 * an API page token, and only it needs to understand which.
 */
final class Event_Batch {

	/**
	 * The drafts found.
	 *
	 * @var array<int,Event_Draft>
	 */
	public array $drafts;

	/**
	 * Where the next run should resume, or null when there is no more.
	 *
	 * @var string|null
	 */
	public ?string $cursor;

	/**
	 * Whether the source believes there is more to fetch.
	 *
	 * @var bool
	 */
	public bool $has_more;

	/**
	 * Constructor.
	 *
	 * @param array<int,Event_Draft> $drafts   Drafts found.
	 * @param string|null            $cursor   Resume point.
	 * @param bool                   $has_more Whether more remain.
	 */
	public function __construct( array $drafts = array(), ?string $cursor = null, bool $has_more = false ) {
		$this->drafts   = $drafts;
		$this->cursor   = $cursor;
		$this->has_more = $has_more;
	}

	/**
	 * An empty batch.
	 *
	 * @return Event_Batch
	 */
	public static function empty_batch(): self {
		return new self();
	}
}
