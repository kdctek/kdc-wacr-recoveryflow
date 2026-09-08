<?php
/**
 * The conditions and actions a workflow may name.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow;

use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Workflow\Actions\Action_Interface;
use WAcr\RecoveryFlow\Workflow\Conditions\Condition_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * The lookup table that stands between a definition and any behaviour.
 *
 * A workflow step says `"do": "wacr.send_template"`. The engine does not call
 * that string; it asks this registry for the object registered under it. Only
 * PHP running on the site can put an object in here, which is what makes an
 * administrator-authored JSON document safe to execute: the worst a bad name
 * can do is stop one journey.
 *
 * The registration hooks fire once, the first time anything is looked up,
 * rather than at boot. A site with no recovery journeys in flight should not
 * pay for building a registry on every page load, and a plugin that registers
 * its own condition must be able to do so after RecoveryFlow has booted.
 */
final class Step_Registry {

	/**
	 * The step types the engine can execute itself.
	 */
	private const BUILT_IN_TYPES = array(
		Workflow_Definition::TYPE_CONDITION,
		Workflow_Definition::TYPE_WAIT,
		Workflow_Definition::TYPE_ACTION,
	);

	/**
	 * Conditions, by name.
	 *
	 * @var array<string,Condition_Interface>
	 */
	private array $conditions = array();

	/**
	 * Actions, by name.
	 *
	 * @var array<string,Action_Interface>
	 */
	private array $actions = array();

	/**
	 * Extra step type names contributed by other plugins.
	 *
	 * @var array<int,string>
	 */
	private array $types = array();

	/**
	 * Whether the registration hooks have run.
	 *
	 * @var bool
	 */
	private bool $collected = false;

	/**
	 * Add a condition.
	 *
	 * A later registration of the same name replaces the earlier one, so a site
	 * can substitute its own implementation for a built-in.
	 *
	 * @param Condition_Interface $condition The condition.
	 * @return Step_Registry
	 */
	public function add_condition( Condition_Interface $condition ): self {
		$this->conditions[ $condition->get_id() ] = $condition;

		return $this;
	}

	/**
	 * Add an action.
	 *
	 * @param Action_Interface $action The action.
	 * @return Step_Registry
	 */
	public function add_action( Action_Interface $action ): self {
		$this->actions[ $action->get_id() ] = $action;

		return $this;
	}

	/**
	 * Declare a step type beyond condition, wait and action.
	 *
	 * @param string $type Step type name.
	 * @return Step_Registry
	 */
	public function add_step_type( string $type ): self {
		if ( '' !== $type && ! in_array( $type, $this->types, true ) ) {
			$this->types[] = $type;
		}

		return $this;
	}

	/**
	 * One condition, or null when nothing is registered under that name.
	 *
	 * @param string $name Condition name.
	 * @return Condition_Interface|null
	 */
	public function condition( string $name ): ?Condition_Interface {
		$this->collect();

		return $this->conditions[ $name ] ?? null;
	}

	/**
	 * One action, or null when nothing is registered under that name.
	 *
	 * @param string $name Action name.
	 * @return Action_Interface|null
	 */
	public function action( string $name ): ?Action_Interface {
		$this->collect();

		return $this->actions[ $name ] ?? null;
	}

	/**
	 * Every registered condition.
	 *
	 * @return array<string,Condition_Interface>
	 */
	public function conditions(): array {
		$this->collect();

		return $this->conditions;
	}

	/**
	 * Every registered action.
	 *
	 * @return array<string,Action_Interface>
	 */
	public function actions(): array {
		$this->collect();

		return $this->actions;
	}

	/**
	 * Every step type a definition may use.
	 *
	 * @return string[]
	 */
	public function step_types(): array {
		$this->collect();

		return array_merge( self::BUILT_IN_TYPES, $this->types );
	}

	/**
	 * Whether a step type is one the engine knows how to run itself.
	 *
	 * @param string $type Step type name.
	 * @return bool
	 */
	public function is_built_in_type( string $type ): bool {
		return in_array( $type, self::BUILT_IN_TYPES, true );
	}

	/**
	 * Run the registration hooks, once.
	 *
	 * @return void
	 */
	private function collect(): void {
		if ( $this->collected ) {
			return;
		}

		$this->collected = true;

		/**
		 * Registers workflow conditions.
		 *
		 * Add one by calling add_condition() on the registry passed in:
		 *
		 *     add_action( 'recoveryflow_register_workflow_conditions', function ( $registry ) {
		 *         $registry->add_condition( new My_Condition() );
		 *     } );
		 *
		 * @param Step_Registry $registry The registry.
		 */
		do_action( Hooks::REGISTER_CONDITIONS, $this );

		/**
		 * Registers workflow actions.
		 *
		 * @param Step_Registry $registry The registry.
		 */
		do_action( Hooks::REGISTER_ACTIONS, $this );

		/**
		 * Registers workflow step types.
		 *
		 * @param Step_Registry $registry The registry.
		 */
		do_action( Hooks::REGISTER_STEPS, $this );
	}
}
