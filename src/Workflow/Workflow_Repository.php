<?php
/**
 * Workflow storage and version snapshots.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow;

use WAcr\RecoveryFlow\Database\Repository;
use WAcr\RecoveryFlow\Database\Table_Names;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The live workflows, and the frozen copy of every version there has ever been.
 *
 * The snapshot table is the point of this class. A journey pins the workflow
 * version it started under and the dispatcher loads exactly that snapshot, so a
 * merchant who edits a sequence at lunchtime does not rewrite what happens next
 * to the two hundred customers already halfway through it -- nobody receives
 * step two of a sequence they were never enrolled in, and nobody skips a step
 * because it was deleted after they passed it.
 *
 * Saving is therefore append-only in spirit: every edit that changes the
 * definition bumps the version and writes a new immutable row. The bump is a
 * compare-and-set on the version number, so two administrators saving at once
 * produce two versions or one refusal, never two rows claiming to be version 4.
 */
final class Workflow_Repository extends Repository {

	/**
	 * The slugs of the two workflows seeded on activation.
	 */
	public const SLUG_DIRECT  = 'cart-recovery-direct';
	public const SLUG_HANDOFF = 'cart-recovery-auto-flow';

	/**
	 * Which table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::WORKFLOWS;
	}

	/**
	 * Fetch by row id.
	 *
	 * @param int $id Workflow id.
	 * @return Workflow|null
	 */
	public function find( int $id ): ?Workflow {
		$row = $this->find_by_id( $id );

		return null === $row ? null : Workflow::from_row( $row );
	}

	/**
	 * Fetch by slug.
	 *
	 * @param string $slug Workflow slug.
	 * @return Workflow|null
	 */
	public function find_by_slug( string $slug ): ?Workflow {
		if ( '' === $slug ) {
			return null;
		}

		$row = $this->find_one_by( 'slug', $slug );

		return null === $row ? null : Workflow::from_row( $row );
	}

	/**
	 * The workflow new journeys on a source should start.
	 *
	 * A workflow named for the source wins over the site-wide default, so a
	 * merchant can treat abandoned bookings differently from abandoned carts
	 * without having to re-point everything else.
	 *
	 * @param string $source_id Source identifier.
	 * @return Workflow|null
	 */
	public function default_for_source( string $source_id ): ?Workflow {
		$table = $this->table();

		$row = $this->one(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; every value bound.
			$this->db()->prepare(
				"SELECT * FROM `{$table}`
				WHERE status = %s AND is_default = 1
					AND ( source_id = %s OR source_id IS NULL OR source_id = '' )
				ORDER BY IF( source_id = %s, 0, 1 ) ASC, id ASC
				LIMIT 1",
				Workflow::STATUS_ACTIVE,
				$source_id,
				$source_id
			)
		);

		return null === $row ? null : Workflow::from_row( $row );
	}

	/**
	 * Fetch the frozen copy of one version.
	 *
	 * This is what the dispatcher runs. The live row is never read for
	 * execution, because it may already describe a different sequence.
	 *
	 * @param int $workflow_id Workflow id.
	 * @param int $version     Pinned version.
	 * @return Workflow|null
	 */
	public function version( int $workflow_id, int $version ): ?Workflow {
		$workflows = $this->table();
		$versions  = Table_Names::get( Table_Names::WORKFLOW_VERSIONS );

		$row = $this->one(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- both table names from class constants; every value bound.
			$this->db()->prepare(
				"SELECT w.id, w.name, w.slug, w.source_id, w.status, w.is_default, w.created_at,
					v.definition_json AS definition_json, v.version AS version, v.created_at AS updated_at
				FROM `{$versions}` AS v
				INNER JOIN `{$workflows}` AS w ON w.id = v.workflow_id
				WHERE v.workflow_id = %d AND v.version = %d
				LIMIT 1",
				$workflow_id,
				$version
			)
		);

		return null === $row ? null : Workflow::from_row( $row );
	}

	/**
	 * Every workflow, newest first.
	 *
	 * @param string $status Limit to one status, or '' for all.
	 * @param int    $limit  Maximum rows.
	 * @return array<int,Workflow>
	 */
	public function all( string $status = '', int $limit = 100 ): array {
		$table = $this->table();

		if ( '' === $status ) {
			$rows = $this->many(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
				$this->db()->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", max( 1, $limit ) )
			);
		} else {
			$rows = $this->many(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
				$this->db()->prepare( "SELECT * FROM `{$table}` WHERE status = %s ORDER BY id DESC LIMIT %d", $status, max( 1, $limit ) )
			);
		}

		return array_map( array( Workflow::class, 'from_row' ), $rows );
	}

	/**
	 * Create or update a workflow, storing a snapshot of the new version.
	 *
	 * Returns 0 for both a refused definition and a lost race with another
	 * administrator; call Workflow_Definition::validate() first when you need
	 * to tell the user which it was.
	 *
	 * @param array<string,mixed> $data Keys: id, name, slug, source_id, status, is_default, definition.
	 * @return int The workflow id, or 0 when nothing was written.
	 */
	public function save( array $data ): int {
		$definition = isset( $data['definition'] ) && is_array( $data['definition'] ) ? $data['definition'] : array();

		if ( true !== Workflow_Definition::validate( $definition ) ) {
			return 0;
		}

		$json = wp_json_encode( $definition );

		if ( ! is_string( $json ) ) {
			return 0;
		}

		$name      = trim( (string) ( $data['name'] ?? ( $definition['name'] ?? '' ) ) );
		$slug      = sanitize_title( (string) ( $data['slug'] ?? $name ) );
		$source_id = trim( (string) ( $data['source_id'] ?? '' ) );
		$status    = Workflow::STATUS_ACTIVE === ( $data['status'] ?? '' ) ? Workflow::STATUS_ACTIVE : Workflow::STATUS_DRAFT;
		$hash      = Workflow_Definition::hash( $definition );
		$id        = isset( $data['id'] ) ? (int) $data['id'] : 0;

		if ( '' === $name || '' === $slug ) {
			return 0;
		}

		$id = 0 === $id
			? $this->insert_new( $name, $slug, $source_id, $status, $json, $hash, ! empty( $data['is_default'] ) )
			: $this->update_existing( $id, $name, $slug, $source_id, $status, $json, $hash, ! empty( $data['is_default'] ) );

		if ( 0 !== $id && ! empty( $data['is_default'] ) ) {
			$this->clear_other_defaults( $id, $source_id );
		}

		return $id;
	}

	/**
	 * Install the two workflows every site starts with.
	 *
	 * Keyed on the UNIQUE slug and inserted with IGNORE, so re-activating the
	 * plugin cannot produce a second copy of either.
	 *
	 * @return void
	 */
	public function seed_defaults(): void {
		$this->seed( self::SLUG_DIRECT, self::direct_definition() );
		$this->seed( self::SLUG_HANDOFF, self::handoff_definition() );

		// Only chosen when nobody has chosen: re-running the seeder on an
		// upgrade must not take the default back off a workflow the merchant
		// built themselves.
		if ( null !== $this->default_for_source( '' ) ) {
			return;
		}

		$dispatch = (string) Options::get( 'wacr_dispatch', 'start_flow' );
		$slug     = 'send_template' === $dispatch ? self::SLUG_DIRECT : self::SLUG_HANDOFF;
		$workflow = $this->find_by_slug( $slug );

		if ( null !== $workflow ) {
			$this->update( array( 'is_default' => 1 ), array( 'id' => $workflow->id ) );
		}
	}

	/**
	 * Insert one seeded workflow if it is not already there.
	 *
	 * @param string              $slug       Workflow slug.
	 * @param array<string,mixed> $definition The definition.
	 * @return void
	 */
	private function seed( string $slug, array $definition ): void {
		$existing = $this->find_by_slug( $slug );

		if ( null !== $existing ) {
			// The row survived but its snapshot may not have; without one the
			// dispatcher would refuse to run every journey pinned to it.
			$this->write_snapshot( $existing->id, $existing->version, (string) wp_json_encode( $existing->definition ) );

			return;
		}

		$json = wp_json_encode( $definition );

		if ( ! is_string( $json ) ) {
			return;
		}

		$now = $this->clock->now();

		$id = $this->insert_ignore(
			array(
				'name'            => (string) $definition['name'],
				'slug'            => $slug,
				'source_id'       => null,
				'status'          => Workflow::STATUS_ACTIVE,
				'definition_json' => $json,
				'definition_hash' => Workflow_Definition::hash( $definition ),
				'version'         => 1,
				'is_default'      => 0,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		if ( 0 !== $id ) {
			$this->write_snapshot( $id, 1, $json );
		}
	}

	/**
	 * Write a brand-new workflow and its first snapshot.
	 *
	 * @param string $name       Administrator-facing name.
	 * @param string $slug       Slug.
	 * @param string $source_id  Source, or '' for every source.
	 * @param string $status     draft or active.
	 * @param string $json       Encoded definition.
	 * @param string $hash       Digest of the canonical definition.
	 * @param bool   $is_default Whether new journeys should start here.
	 * @return int Workflow id, or 0.
	 */
	private function insert_new( string $name, string $slug, string $source_id, string $status, string $json, string $hash, bool $is_default ): int {
		$now = $this->clock->now();

		$id = $this->insert_ignore(
			array(
				'name'            => substr( $name, 0, 191 ),
				'slug'            => $slug,
				'source_id'       => '' === $source_id ? null : substr( $source_id, 0, 32 ),
				'status'          => $status,
				'definition_json' => $json,
				'definition_hash' => $hash,
				'version'         => 1,
				'is_default'      => $is_default ? 1 : 0,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		if ( 0 === $id ) {
			return 0;
		}

		$this->write_snapshot( $id, 1, $json );

		return $id;
	}

	/**
	 * Update a workflow, bumping the version when the definition changed.
	 *
	 * @param int    $id         Workflow id.
	 * @param string $name       Administrator-facing name.
	 * @param string $slug       Slug.
	 * @param string $source_id  Source, or '' for every source.
	 * @param string $status     draft or active.
	 * @param string $json       Encoded definition.
	 * @param string $hash       Digest of the canonical definition.
	 * @param bool   $is_default Whether new journeys should start here.
	 * @return int Workflow id, or 0 when somebody else saved first.
	 */
	private function update_existing( int $id, string $name, string $slug, string $source_id, string $status, string $json, string $hash, bool $is_default ): int {
		$current = $this->find( $id );

		if ( null === $current ) {
			return 0;
		}

		$patch = array(
			'name'       => substr( $name, 0, 191 ),
			'slug'       => $slug,
			'source_id'  => '' === $source_id ? null : substr( $source_id, 0, 32 ),
			'status'     => $status,
			'is_default' => $is_default ? 1 : 0,
			'updated_at' => $this->clock->now(),
		);

		// Renaming a workflow or taking it off default is not a new version:
		// only a change to the steps can change what a journey will do, and
		// burning a version number on a typo fix makes the history unreadable.
		if ( $current->definition_hash === $hash ) {
			$this->update( $patch, array( 'id' => $id ) );

			return $id;
		}

		$next = $current->version + 1;

		$patch['definition_json'] = $json;
		$patch['definition_hash'] = $hash;
		$patch['version']         = $next;

		if ( ! $this->compare_and_set( $id, $patch, array( 'version' => $current->version ) ) ) {
			return 0;
		}

		$this->write_snapshot( $id, $next, $json );

		return $id;
	}

	/**
	 * Freeze one version. Repeating it is a no-op.
	 *
	 * @param int    $workflow_id Workflow id.
	 * @param int    $version     Version number.
	 * @param string $json        Encoded definition.
	 * @return void
	 */
	private function write_snapshot( int $workflow_id, int $version, string $json ): void {
		if ( '' === $json ) {
			return;
		}

		$table = Table_Names::get( Table_Names::WORKFLOW_VERSIONS );

		$prepared = $this->db()->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; every value bound.
			"INSERT IGNORE INTO `{$table}` (workflow_id, version, definition_json, created_at) VALUES (%d, %d, %s, %s)",
			$workflow_id,
			$version,
			$json,
			$this->clock->now()
		);

		$this->execute( $prepared );
	}

	/**
	 * Leave exactly one default per source scope.
	 *
	 * @param int    $keep_id   The workflow that keeps it.
	 * @param string $source_id Source scope, or '' for site-wide.
	 * @return void
	 */
	private function clear_other_defaults( int $keep_id, string $source_id ): void {
		$table = $this->table();

		$prepared = '' === $source_id
			? $this->db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; every value bound.
				"UPDATE `{$table}` SET is_default = 0 WHERE id <> %d AND ( source_id IS NULL OR source_id = '' )",
				$keep_id
			)
			: $this->db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; every value bound.
				"UPDATE `{$table}` SET is_default = 0 WHERE id <> %d AND source_id = %s",
				$keep_id,
				$source_id
			);

		$this->execute( $prepared );
	}

	/**
	 * The two-touch direct-send sequence.
	 *
	 * @return array<string,mixed>
	 */
	private static function direct_definition(): array {
		return array(
			'name'    => __( 'Cart recovery (2 touches)', 'kdc-wacr-recoveryflow' ),
			'version' => 1,
			'trigger' => array(
				'event'  => Workflow_Definition::TRIGGER_EVENT,
				'source' => Workflow_Definition::ANY_SOURCE,
			),
			'steps'   => array(
				array(
					'type' => 'condition',
					'if'   => 'journey.not_completed',
					'else' => 'stop:recovered',
				),
				array(
					'type' => 'condition',
					'if'   => 'customer.eligible',
					'else' => 'stop:cancelled',
				),
				array(
					'type' => 'action',
					'do'   => 'wacr.send_template',
					'with' => array(
						'template'  => 'cart_reminder_1',
						'language'  => 'en',
						'variables' => array(
							'body_1'         => '{{customer.first_name}}',
							'body_2'         => '{{recovery.total_formatted}}',
							'button_0_url_1' => '{{recovery.token}}',
						),
					),
				),
				array(
					'type' => 'wait',
					'for'  => 'P1D',
				),
				array(
					'type' => 'condition',
					'if'   => 'journey.not_completed',
					'else' => 'stop:recovered',
				),
				array(
					'type' => 'condition',
					'if'   => 'journey.not_engaged',
					'else' => 'stop:cancelled',
				),
				array(
					'type' => 'action',
					'do'   => 'wacr.send_template',
					'with' => array(
						'template'  => 'cart_reminder_2',
						'language'  => 'en',
						'variables' => array(
							'body_1'         => '{{customer.first_name}}',
							'button_0_url_1' => '{{recovery.token}}',
						),
					),
				),
			),
		);
	}

	/**
	 * The single-step hand-off to a WA.cr Auto Flow.
	 *
	 * @return array<string,mixed>
	 */
	private static function handoff_definition(): array {
		return array(
			'name'    => __( 'Hand off to WA.cr Auto Flow', 'kdc-wacr-recoveryflow' ),
			'version' => 1,
			'trigger' => array(
				'event'  => Workflow_Definition::TRIGGER_EVENT,
				'source' => Workflow_Definition::ANY_SOURCE,
			),
			'steps'   => array(
				array(
					'type' => 'action',
					'do'   => 'wacr.start_flow',
					'with' => array( 'hook' => 'primary' ),
				),
			),
		);
	}
}
