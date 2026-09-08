<?php
/**
 * The plugin's public hook names.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Every action and filter third-party code may rely on, in one place.
 *
 * These names are a public contract: they are documented in
 * docs/developer-api.md and must not change without a major version. Using the
 * constants internally means a rename is a compile-time problem rather than a
 * silent breakage in somebody else's integration.
 */
final class Hooks {

	// Lifecycle.
	public const BOOTED    = 'recoveryflow_booted';
	public const ACTIVATED = 'recoveryflow_activated';
	public const UPGRADED  = 'recoveryflow_upgraded';

	// Recovery events and journeys.
	public const EVENT_CREATED      = 'recoveryflow_event_created';
	public const EVENT_COMPLETED    = 'recoveryflow_event_completed';
	public const JOURNEY_CREATED    = 'recoveryflow_journey_created';
	public const JOURNEY_TRANSITION = 'recoveryflow_journey_transition';
	public const JOURNEY_SCHEDULED  = 'recoveryflow_journey_scheduled';
	public const MESSAGE_SENT       = 'recoveryflow_journey_message_sent';
	public const JOURNEY_ENGAGED    = 'recoveryflow_journey_engaged';
	public const JOURNEY_RECOVERED  = 'recoveryflow_journey_recovered';
	public const JOURNEY_EXPIRED    = 'recoveryflow_journey_expired';
	public const JOURNEY_CANCELLED  = 'recoveryflow_journey_cancelled';
	public const JOURNEY_OPTED_OUT  = 'recoveryflow_journey_opted_out';

	// Messaging.
	public const BEFORE_MESSAGE = 'recoveryflow_before_message';
	public const AFTER_MESSAGE  = 'recoveryflow_after_message';

	// Registries.
	public const REGISTER_SOURCES    = 'recoveryflow_register_sources';
	public const REGISTER_SOURCE     = 'recoveryflow_register_source';
	public const REGISTER_STEPS      = 'recoveryflow_register_workflow_steps';
	public const REGISTER_CONDITIONS = 'recoveryflow_register_workflow_conditions';
	public const REGISTER_ACTIONS    = 'recoveryflow_register_workflow_actions';

	// Behaviour filters.
	public const FILTER_NORMALIZE_PHONE   = 'recoveryflow_normalize_phone';
	public const FILTER_CALLING_CODES     = 'recoveryflow_calling_codes';
	public const FILTER_CAPABILITY_MAP    = 'recoveryflow_capability_map';
	public const FILTER_FEATURE_ENABLED   = 'recoveryflow_feature_enabled';
	public const FILTER_OPTOUT_KEYWORDS   = 'recoveryflow_optout_keywords';
	public const FILTER_ALLOWED_HOSTS     = 'recoveryflow_wacr_allowed_hosts';
	public const FILTER_TICK_INTERVAL     = 'recoveryflow_tick_interval';
	public const FILTER_BATCH_SIZE        = 'recoveryflow_batch_size';
	public const FILTER_TIME_BUDGET       = 'recoveryflow_time_budget';
	public const FILTER_RETENTION_DAYS    = 'recoveryflow_retention_days';
	public const FILTER_RECOVERED_STATES  = 'recoveryflow_wc_recovered_statuses';
	public const FILTER_RESTORE_ITEM_DATA = 'recoveryflow_wc_restore_cart_item_data';

	// Presentation.
	public const FILTER_TEMPLATE                = 'recoveryflow_template';
	public const FILTER_BLOCKS_CONSENT_LOCATION = 'recoveryflow_wc_blocks_consent_location';
}
