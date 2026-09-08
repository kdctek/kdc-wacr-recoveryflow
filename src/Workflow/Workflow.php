<?php
/**
 * A stored recovery workflow.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow;

defined( 'ABSPATH' ) || exit;

/**
 * One named sequence of steps, at one exact version.
 *
 * The definition is held as a decoded array rather than as objects because it
 * is data, not code: it arrives as JSON from an administrator, it is stored as
 * JSON, and it is executed by looking values up. Turning it into a graph of
 * step objects would create somewhere for behaviour to hide, and the whole
 * safety argument for this feature rests on there being nowhere for behaviour
 * to hide -- nothing in a definition is ever called, instantiated or evaluated.
 *
 * An instance may be either the live workflow row or an immutable snapshot of
 * an older version. They are the same shape deliberately: the dispatcher always
 * runs a snapshot, and an administrator always edits the live row, and neither
 * needs to know which it is holding.
 */
final class Workflow {

	public const STATUS_DRAFT  = 'draft';
	public const STATUS_ACTIVE = 'active';

	/**
	 * Row id.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Administrator-facing name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Stable machine identifier. UNIQUE, which is what makes seeding safe to repeat.
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * The source this workflow applies to, or '' for every source.
	 *
	 * @var string
	 */
	public string $source_id = '';

	/**
	 * Whether it may run: draft or active.
	 *
	 * @var string
	 */
	public string $status = self::STATUS_DRAFT;

	/**
	 * The decoded definition.
	 *
	 * @var array<string,mixed>
	 */
	public array $definition = array();

	/**
	 * Digest of the canonical definition, for change detection.
	 *
	 * @var string
	 */
	public string $definition_hash = '';

	/**
	 * Which version this object holds.
	 *
	 * @var int
	 */
	public int $version = 1;

	/**
	 * Whether new journeys on this source start here.
	 *
	 * @var bool
	 */
	public bool $is_default = false;

	/**
	 * When the row was created, UTC.
	 *
	 * @var string
	 */
	public string $created_at = '';

	/**
	 * When it last changed, UTC.
	 *
	 * @var string
	 */
	public string $updated_at = '';

	/**
	 * Build one from a database row.
	 *
	 * @param array<string,mixed> $row Row as returned by the repository.
	 * @return Workflow
	 */
	public static function from_row( array $row ): self {
		$workflow = new self();

		$workflow->id              = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$workflow->name            = (string) ( $row['name'] ?? '' );
		$workflow->slug            = (string) ( $row['slug'] ?? '' );
		$workflow->source_id       = isset( $row['source_id'] ) ? (string) $row['source_id'] : '';
		$workflow->status          = (string) ( $row['status'] ?? self::STATUS_DRAFT );
		$workflow->definition      = self::decode( $row['definition_json'] ?? null );
		$workflow->definition_hash = (string) ( $row['definition_hash'] ?? '' );
		$workflow->version         = isset( $row['version'] ) ? (int) $row['version'] : 1;
		$workflow->is_default      = ! empty( $row['is_default'] );
		$workflow->created_at      = (string) ( $row['created_at'] ?? '' );
		$workflow->updated_at      = (string) ( $row['updated_at'] ?? '' );

		return $workflow;
	}

	/**
	 * The steps, as a plain list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function steps(): array {
		if ( ! isset( $this->definition['steps'] ) || ! is_array( $this->definition['steps'] ) ) {
			return array();
		}

		$steps = array();

		foreach ( $this->definition['steps'] as $step ) {
			if ( is_array( $step ) ) {
				$steps[] = $step;
			}
		}

		return $steps;
	}

	/**
	 * One step, or null when the index is past the end.
	 *
	 * @param int $index Zero-based step index.
	 * @return array<string,mixed>|null
	 */
	public function step( int $index ): ?array {
		$steps = $this->steps();

		return $steps[ $index ] ?? null;
	}

	/**
	 * How many steps the workflow has.
	 *
	 * @return int
	 */
	public function step_count(): int {
		return count( $this->steps() );
	}

	/**
	 * Whether this workflow may start new journeys.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->status;
	}

	/**
	 * Whether this workflow covers a given source.
	 *
	 * @param string $source_id Source identifier.
	 * @return bool
	 */
	public function applies_to( string $source_id ): bool {
		return '' === $this->source_id || $this->source_id === $source_id;
	}

	/**
	 * The source named in the trigger, or '*'.
	 *
	 * @return string
	 */
	public function trigger_source(): string {
		if ( ! isset( $this->definition['trigger']['source'] ) ) {
			return Workflow_Definition::ANY_SOURCE;
		}

		return (string) $this->definition['trigger']['source'];
	}

	/**
	 * Decode a stored JSON column.
	 *
	 * @param mixed $json Raw column value.
	 * @return array<string,mixed>
	 */
	private static function decode( $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
