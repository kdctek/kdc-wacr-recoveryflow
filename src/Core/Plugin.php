<?php
/**
 * The plugin container.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

use WAcr\RecoveryFlow\Admin\Assets as Admin_Assets;
use WAcr\RecoveryFlow\Admin\Connection_Test;
use WAcr\RecoveryFlow\Admin\Diagnostics;
use WAcr\RecoveryFlow\Admin\Hook_Test;
use WAcr\RecoveryFlow\Admin\Journey_Actions;
use WAcr\RecoveryFlow\Admin\Run_Now;
use WAcr\RecoveryFlow\Admin\Workflow_Form;
use WAcr\RecoveryFlow\Admin\Setup;
use WAcr\RecoveryFlow\Admin\Menu as Admin_Menu;
use WAcr\RecoveryFlow\Admin\Pages\Integrations as Integrations_Page;
use WAcr\RecoveryFlow\Admin\Pages\Journey_Detail;
use WAcr\RecoveryFlow\Admin\Pages\Journeys as Journeys_Page;
use WAcr\RecoveryFlow\Admin\Pages\Overview as Overview_Page;
use WAcr\RecoveryFlow\Admin\Pages\System_Status;
use WAcr\RecoveryFlow\Admin\Pages\Workflow_Edit;
use WAcr\RecoveryFlow\Admin\Pages\Workflows as Workflows_Page;
use WAcr\RecoveryFlow\Customer\Consent_Repository;
use WAcr\RecoveryFlow\Customer\Consent_Store;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Customer\Identity_Repository;
use WAcr\RecoveryFlow\Customer\Identity_Resolver;
use WAcr\RecoveryFlow\Database\Lock_Repository;
use WAcr\RecoveryFlow\Database\Receipt_Repository;
use WAcr\RecoveryFlow\Integration\GravityForms\Draft_Watcher as Gf_Draft_Watcher;
use WAcr\RecoveryFlow\Integration\GravityForms\Entry_Poller as Gf_Entry_Poller;
use WAcr\RecoveryFlow\Integration\GravityForms\Entry_Watcher as Gf_Entry_Watcher;
use WAcr\RecoveryFlow\Integration\GravityForms\Field_Map as Gf_Field_Map;
use WAcr\RecoveryFlow\Integration\GravityForms\Source as Gf_Source;
use WAcr\RecoveryFlow\CLI\Command as CLI_Command;
use WAcr\RecoveryFlow\Integration\Source_Cursors;
use WAcr\RecoveryFlow\Integration\Source_Registry;
use WAcr\RecoveryFlow\Integration\WooCommerce\Cart_Restorer;
use WAcr\RecoveryFlow\Integration\WooCommerce\Cart_Tracker;
use WAcr\RecoveryFlow\Integration\WooCommerce\Checkout_Capture;
use WAcr\RecoveryFlow\Integration\WooCommerce\Checkout_Script;
use WAcr\RecoveryFlow\Integration\WooCommerce\Consent_Field;
use WAcr\RecoveryFlow\Integration\WooCommerce\Order_Observer;
use WAcr\RecoveryFlow\Integration\WooCommerce\Session as Wc_Session;
use WAcr\RecoveryFlow\Integration\WooCommerce\Source as Woo_Source;
use WAcr\RecoveryFlow\Jobs\Action_Scheduler_Driver;
use WAcr\RecoveryFlow\Jobs\Lock;
use WAcr\RecoveryFlow\Jobs\Scheduler_Factory;
use WAcr\RecoveryFlow\Jobs\Stage_Runner;
use WAcr\RecoveryFlow\Jobs\Stages\Dispatch;
use WAcr\RecoveryFlow\Jobs\Stages\Evaluate;
use WAcr\RecoveryFlow\Jobs\Stages\Expire;
use WAcr\RecoveryFlow\Jobs\Stages\Poll;
use WAcr\RecoveryFlow\Jobs\Stages\Retention;
use WAcr\RecoveryFlow\Jobs\Wp_Cron_Driver;
use WAcr\RecoveryFlow\Privacy\Anonymizer;
use WAcr\RecoveryFlow\Privacy\Erase_By_Phone;
use WAcr\RecoveryFlow\Privacy\Eraser;
use WAcr\RecoveryFlow\Privacy\Exporter;
use WAcr\RecoveryFlow\REST\Journeys_Controller;
use WAcr\RecoveryFlow\REST\Integrations_Controller;
use WAcr\RecoveryFlow\REST\Settings_Controller;
use WAcr\RecoveryFlow\REST\Templates_Controller;
use WAcr\RecoveryFlow\REST\Status_Controller;
use WAcr\RecoveryFlow\Recovery\Attempt_Repository;
use WAcr\RecoveryFlow\Recovery\Conversion_Tracker;
use WAcr\RecoveryFlow\Recovery\Eligibility_Evaluator;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Recovery_Controller;
use WAcr\RecoveryFlow\Recovery\Suppressor;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Security\Rate_Limiter;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\WAcr\Client;
use WAcr\RecoveryFlow\WAcr\Credentials;
use WAcr\RecoveryFlow\WAcr\Opt_Out_Sync;
use WAcr\RecoveryFlow\WAcr\Template_Catalog;
use WAcr\RecoveryFlow\WAcr\Rate_Budget;
use WAcr\RecoveryFlow\WAcr\Transport;
use WAcr\RecoveryFlow\Workflow\Actions\Send_Template;
use WAcr\RecoveryFlow\Workflow\Actions\Start_Flow;
use WAcr\RecoveryFlow\Workflow\Conditions\Customer_Eligible;
use WAcr\RecoveryFlow\Workflow\Conditions\Event_Amount_Gte;
use WAcr\RecoveryFlow\Workflow\Conditions\Journey_Not_Completed;
use WAcr\RecoveryFlow\Workflow\Conditions\Journey_Not_Engaged;
use WAcr\RecoveryFlow\Workflow\Engine;
use WAcr\RecoveryFlow\Workflow\Message_Composer;
use WAcr\RecoveryFlow\Workflow\Send_Gate;
use WAcr\RecoveryFlow\Workflow\Step_Registry;
use WAcr\RecoveryFlow\Workflow\Workflow_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together and hands services to whoever needs them.
 *
 * Services are created lazily and stored by id. Anything with a hooks() method
 * is asked to attach its own hooks at boot, which keeps the wiring in the class
 * that owns the behaviour rather than in one long list here.
 */
final class Plugin {

	/**
	 * The one instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Resolved services, by id.
	 *
	 * @var array<string,object>
	 */
	private array $services = array();

	/**
	 * Factories, by id.
	 *
	 * @var array<string,callable>
	 */
	private array $factories = array();

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private: use instance().
	 */
	private function __construct() {
		$this->register_factories();
	}

	/**
	 * The container.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Declare how each service is built.
	 *
	 * @return void
	 */
	private function register_factories(): void {
		$this->factories = array(
			'clock'               => static fn (): Clock => new Clock(),
			'logger'              => static fn ( Plugin $c ): Logger => new Logger( $c->clock() ),

			// Storage. Every repository shares the one clock, so freezing time
			// in a test freezes it for the whole plugin at once.
			'events'              => static fn ( Plugin $c ): Event_Repository => new Event_Repository( $c->clock() ),
			'journeys'            => static fn ( Plugin $c ): Journey_Repository => new Journey_Repository( $c->clock() ),
			'attempts'            => static fn ( Plugin $c ): Attempt_Repository => new Attempt_Repository( $c->clock() ),
			'identities'          => static fn ( Plugin $c ): Identity_Repository => new Identity_Repository( $c->clock() ),
			'customers'           => static fn ( Plugin $c ): Customer_Repository => new Customer_Repository( $c->clock(), $c->identities() ),
			'consents'            => static fn ( Plugin $c ): Consent_Repository => new Consent_Repository( $c->clock() ),
			'locks'               => static fn ( Plugin $c ): Lock_Repository => new Lock_Repository( $c->clock() ),
			'receipts'            => static fn ( Plugin $c ): Receipt_Repository => new Receipt_Repository( $c->clock() ),
			'workflows'           => static fn ( Plugin $c ): Workflow_Repository => new Workflow_Repository( $c->clock() ),

			// Identity, consent and the decision to message.
			'identity'            => static fn ( Plugin $c ): Identity_Resolver => new Identity_Resolver( $c->customers(), $c->identities(), $c->lock(), $c->logger() ),
			'consent'             => static fn ( Plugin $c ): Consent_Store => new Consent_Store( $c->consents() ),
			'eligibility'         => static fn ( Plugin $c ): Eligibility_Evaluator => new Eligibility_Evaluator( $c->consent(), $c->journeys(), $c->attempts() ),
			'ingest'              => static fn ( Plugin $c ): Event_Ingest => new Event_Ingest( $c->events(), $c->identity(), $c->consent(), $c->logger() ),
			'conversions'         => static fn ( Plugin $c ): Conversion_Tracker => new Conversion_Tracker(
				$c->journeys(),
				$c->events(),
				$c->attempts(),
				$c->customers(),
				$c->logger(),
				$c->clock()
			),

			// WA.cr.
			'credentials'         => static fn (): Credentials => new Credentials(),
			'transport'           => static fn (): Transport => new Transport(),
			'rate_budget'         => static fn ( Plugin $c ): Rate_Budget => new Rate_Budget( $c->clock() ),
			'wacr'                => static fn ( Plugin $c ): Client => new Client(
				$c->credentials(),
				$c->transport(),
				$c->rate_budget(),
				$c->logger(),
				$c->clock()
			),

			// Integrations.
			'sources'             => static fn ( Plugin $c ): Source_Registry => new Source_Registry( $c->ingest() ),
			'source_cursors'      => static fn (): Source_Cursors => new Source_Cursors(),
			'wc_session'          => static fn (): Wc_Session => new Wc_Session(),

			// The workflow engine and its registries.
			'send_gate'           => static fn ( Plugin $c ): Send_Gate => new Send_Gate( $c->rate_budget(), $c->wacr(), $c->logger(), $c->clock() ),
			'composer'            => static fn ( Plugin $c ): Message_Composer => new Message_Composer( $c->logger() ),
			'steps'               => static fn ( Plugin $c ): Step_Registry => $c->build_step_registry(),
			'engine'              => static fn ( Plugin $c ): Engine => new Engine(
				$c->workflows(),
				$c->journeys(),
				$c->events(),
				$c->customers(),
				$c->sources(),
				$c->steps(),
				$c->eligibility(),
				$c->send_gate(),
				$c->logger(),
				$c->clock()
			),

			// Background processing.
			'lock'                => static fn ( Plugin $c ): Lock => new Lock( $c->locks(), $c->logger() ),
			'scheduler'           => static fn (): Scheduler_Factory => new Scheduler_Factory( new Action_Scheduler_Driver(), new Wp_Cron_Driver() ),
			'runner'              => static fn ( Plugin $c ): Stage_Runner => $c->build_stage_runner(),

			// The health checks, shared by the status screen and the status
			// endpoint so the two cannot drift into different diagnoses.
			'health'              => static fn ( Plugin $c ): Health => new Health( $c->credentials(), $c->scheduler() ),

			// REST. Registered on rest_api_init, which fires on its own request
			// rather than in wp-admin, so these are built on every request the
			// same way the public endpoint is.
			'rest_journeys'       => static fn ( Plugin $c ): Journeys_Controller => new Journeys_Controller(
				$c->journeys(),
				$c->events(),
				$c->customers(),
				$c->attempts(),
				$c->receipts(),
				$c->suppressor(),
				$c->clock()
			),
			'rest_status'         => static fn ( Plugin $c ): Status_Controller => new Status_Controller( $c->credentials(), $c->wacr(), $c->health() ),
			'rest_settings'       => static fn (): Settings_Controller => new Settings_Controller(),
			'rest_integrations'   => static fn ( Plugin $c ): Integrations_Controller => new Integrations_Controller( $c->sources() ),
			'rest_templates'      => static fn ( Plugin $c ): Templates_Controller => new Templates_Controller( $c->template_catalog() ),

			// Privacy. The anonymiser is shared: WordPress's eraser and the
			// daily retention clear-out must not drift into two ideas of what
			// "erased" means.
			'anonymizer'          => static fn ( Plugin $c ): Anonymizer => new Anonymizer(
				$c->customers(),
				$c->journeys(),
				$c->events(),
				$c->attempts(),
				$c->consents(),
				$c->logger()
			),
			'privacy_exporter'    => static fn ( Plugin $c ): Exporter => new Exporter(
				$c->customers(),
				$c->identities(),
				$c->consents(),
				$c->journeys(),
				$c->events(),
				$c->attempts()
			),
			'privacy_eraser'      => static fn ( Plugin $c ): Eraser => new Eraser( $c->customers(), $c->anonymizer() ),

			// The admin. Built only when a request is actually in wp-admin --
			// see boot() -- so a shop page never pays for a screen nobody is
			// looking at.
			'admin_overview'      => static fn ( Plugin $c ): Overview_Page => new Overview_Page( $c->journeys(), $c->health() ),
			'admin_journeys'      => static fn ( Plugin $c ): Journeys_Page => new Journeys_Page( $c->journeys(), $c->events(), $c->customers() ),
			'admin_journey'       => static fn ( Plugin $c ): Journey_Detail => new Journey_Detail(
				$c->journeys(),
				$c->events(),
				$c->customers(),
				$c->attempts(),
				$c->receipts()
			),
			'admin_integrations'  => static fn ( Plugin $c ): Integrations_Page => new Integrations_Page( $c->sources() ),
			'admin_diagnostics'   => static fn ( Plugin $c ): Diagnostics => new Diagnostics( $c->credentials(), $c->health() ),
			'admin_status'        => static fn ( Plugin $c ): System_Status => new System_Status( $c->health(), $c->admin_diagnostics() ),
			'admin_workflows'     => static fn ( Plugin $c ): Workflows_Page => new Workflows_Page( $c->workflows() ),
			'template_catalog'    => static fn ( Plugin $c ): Template_Catalog => new Template_Catalog( $c->wacr() ),
			'opt_out_sync'        => static fn ( Plugin $c ): Opt_Out_Sync => new Opt_Out_Sync( $c->wacr(), $c->customers(), $c->logger() ),
			'admin_workflow'      => static fn ( Plugin $c ): Workflow_Edit => new Workflow_Edit( $c->workflows(), $c->steps(), $c->template_catalog() ),
			'admin_workflow_form' => static fn ( Plugin $c ): Workflow_Form => new Workflow_Form( $c->workflows() ),
			'admin_setup'         => static fn ( Plugin $c ): Setup => new Setup( $c->wacr(), $c->credentials() ),
			'admin_menu'          => static fn ( Plugin $c ): Admin_Menu => new Admin_Menu(
				$c->admin_overview(),
				$c->admin_journeys(),
				$c->admin_journey(),
				$c->admin_integrations(),
				$c->admin_status(),
				$c->admin_workflows(),
				$c->admin_workflow(),
				$c->admin_setup()
			),
			'admin_assets'        => static fn ( Plugin $c ): Admin_Assets => new Admin_Assets( $c->admin_menu() ),
			'admin_connection'    => static fn ( Plugin $c ): Connection_Test => new Connection_Test( $c->wacr(), $c->credentials() ),
			'admin_hook_test'     => static fn ( Plugin $c ): Hook_Test => new Hook_Test( $c->wacr(), $c->credentials() ),
			'admin_run_now'       => static fn ( Plugin $c ): Run_Now => new Run_Now( $c->runner() ),
			'admin_journey_acts'  => static fn ( Plugin $c ): Journey_Actions => new Journey_Actions( $c->rest_journeys() ),
			'privacy_erase_phone' => static fn ( Plugin $c ): Erase_By_Phone => new Erase_By_Phone( $c->customers(), $c->anonymizer() ),

			// The public endpoint.
			'rate_limiter'        => static fn ( Plugin $c ): Rate_Limiter => new Rate_Limiter( $c->clock() ),
			'recovery_controller' => static fn ( Plugin $c ): Recovery_Controller => new Recovery_Controller(
				$c->attempts(),
				$c->journeys(),
				$c->events(),
				$c->sources(),
				$c->rate_limiter(),
				$c->logger(),
				$c->clock(),
				$c->suppressor()
			),
			'suppressor'          => static fn ( Plugin $c ): Suppressor => new Suppressor(
				$c->customers(),
				$c->consent(),
				$c->journeys(),
				$c->attempts(),
				$c->logger()
			),
		);
	}

	/**
	 * Build the workflow registries with the built-in conditions and actions.
	 *
	 * Third parties add their own through the registration hooks rather than by
	 * editing this list, which is why the registry is filled here and nowhere
	 * else.
	 *
	 * @return Step_Registry
	 */
	public function build_step_registry(): Step_Registry {
		$registry = new Step_Registry();

		$registry->add_condition( new Journey_Not_Completed( $this->logger() ) );
		$registry->add_condition( new Journey_Not_Engaged() );
		$registry->add_condition( new Customer_Eligible( $this->eligibility() ) );
		$registry->add_condition( new Event_Amount_Gte() );

		$registry->add_action(
			new Send_Template(
				$this->wacr(),
				$this->attempts(),
				$this->journeys(),
				$this->send_gate(),
				$this->composer(),
				$this->rate_budget(),
				$this->logger(),
				$this->clock()
			)
		);

		$registry->add_action(
			new Start_Flow(
				$this->wacr(),
				$this->credentials(),
				$this->attempts(),
				$this->journeys(),
				$this->send_gate(),
				$this->rate_budget(),
				$this->logger(),
				$this->clock()
			)
		);

		/**
		 * Registers workflow conditions and actions.
		 *
		 * @param Step_Registry $registry The registry.
		 */
		do_action( Hooks::REGISTER_ACTIONS, $registry );

		/**
		 * Registers workflow conditions.
		 *
		 * @param Step_Registry $registry The registry.
		 */
		do_action( Hooks::REGISTER_CONDITIONS, $registry );

		return $registry;
	}

	/**
	 * Build the stage runner with the five stages attached.
	 *
	 * @return Stage_Runner
	 */
	public function build_stage_runner(): Stage_Runner {
		$scheduler = $this->scheduler();

		$runner = new Stage_Runner( $scheduler->scheduler(), $this->lock(), $this->clock(), $this->logger() );

		$runner->add( new Evaluate( $this->events(), $this->journeys(), $this->customers(), $this->eligibility(), $this->sources(), $this->workflows(), $this->ingest(), $this->source_cursors(), $this->clock(), $this->logger() ) );
		$runner->add( new Dispatch( $this->journeys(), $this->engine(), $this->rate_budget(), $this->logger() ) );
		$runner->add( new Poll( $this->journeys(), $this->attempts(), $this->customers(), $this->consent(), $this->wacr(), $this->clock(), $this->logger() ) );
		$runner->add( new Expire( $this->journeys(), $this->events(), $this->attempts() ) );
		$runner->add( new Retention( $this->events(), $this->attempts(), $this->receipts(), $this->customers(), $this->anonymizer(), $this->clock() ) );

		return $runner;
	}

	/**
	 * Register the integrations the plugin ships with.
	 *
	 * @return void
	 */
	public function register_built_in_sources(): void {
		$registry = $this->sources();
		$session  = $this->wc_session();
		$logger   = $this->logger();

		$registry->add(
			new Woo_Source(
				$this->ingest(),
				new Cart_Tracker( $this->ingest(), $session, $logger ),
				new Checkout_Capture( $session, $logger ),
				new Consent_Field( $session, $logger ),
				new Order_Observer( $this->conversions(), $this->ingest(), $this->receipts(), $this->customers(), $session, $logger ),
				new Cart_Restorer( $session, $this->customers(), $logger ),
				new Checkout_Script()
			)
		);

		$gf_fields = new Gf_Field_Map();

		$registry->add(
			new Gf_Source(
				$this->ingest(),
				new Gf_Draft_Watcher( $this->ingest(), $gf_fields, $logger ),
				new Gf_Entry_Watcher( $this->ingest(), $this->conversions(), $this->receipts(), $gf_fields, $logger ),
				new Gf_Entry_Poller( $gf_fields, $logger )
			)
		);

		$registry->register_all();
	}

	/**
	 * Fetch a service.
	 *
	 * @param string $id Service id.
	 * @return object|null
	 */
	public function get( string $id ): ?object {
		if ( isset( $this->services[ $id ] ) ) {
			return $this->services[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			return null;
		}

		$this->services[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->services[ $id ];
	}

	/**
	 * Register a service or replace one. Test seam.
	 *
	 * @param string $id      Service id.
	 * @param object $service The service.
	 * @return void
	 */
	public function set( string $id, object $service ): void {
		$this->services[ $id ] = $service;
	}

	/**
	 * The clock, which everything that stores a time uses.
	 *
	 * @return Clock
	 */
	public function clock(): Clock {
		$clock = $this->get( 'clock' );

		return $clock instanceof Clock ? $clock : new Clock();
	}

	/**
	 * Fetch a service, insisting on its type.
	 *
	 * A missing service is a wiring mistake rather than a runtime condition, so
	 * this fails loudly in development and quietly enough in production that a
	 * shopper never sees it.
	 *
	 * @param string $id    Service id.
	 * @param string $expected Expected class name.
	 * @return object
	 */
	private function typed( string $id, string $expected ) {
		$service = $this->get( $id );

		if ( $service instanceof $expected ) {
			return $service;
		}

		$built = ( $this->factories[ $id ] )( $this );

		$this->services[ $id ] = $built;

		return $built;
	}

	/**
	 * The logger service.
	 *
	 * @return Logger
	 */
	public function logger(): Logger {
		return $this->typed( 'logger', Logger::class );
	}

	/**
	 * The events service.
	 *
	 * @return Event_Repository
	 */
	public function events(): Event_Repository {
		return $this->typed( 'events', Event_Repository::class );
	}

	/**
	 * The journeys service.
	 *
	 * @return Journey_Repository
	 */
	public function journeys(): Journey_Repository {
		return $this->typed( 'journeys', Journey_Repository::class );
	}

	/**
	 * The attempts service.
	 *
	 * @return Attempt_Repository
	 */
	public function attempts(): Attempt_Repository {
		return $this->typed( 'attempts', Attempt_Repository::class );
	}

	/**
	 * The customers service.
	 *
	 * @return Customer_Repository
	 */
	public function customers(): Customer_Repository {
		return $this->typed( 'customers', Customer_Repository::class );
	}

	/**
	 * The identities service.
	 *
	 * @return Identity_Repository
	 */
	public function identities(): Identity_Repository {
		return $this->typed( 'identities', Identity_Repository::class );
	}

	/**
	 * The consents service.
	 *
	 * @return Consent_Repository
	 */
	public function consents(): Consent_Repository {
		return $this->typed( 'consents', Consent_Repository::class );
	}

	/**
	 * The locks service.
	 *
	 * @return Lock_Repository
	 */
	public function locks(): Lock_Repository {
		return $this->typed( 'locks', Lock_Repository::class );
	}

	/**
	 * The receipts service.
	 *
	 * @return Receipt_Repository
	 */
	public function receipts(): Receipt_Repository {
		return $this->typed( 'receipts', Receipt_Repository::class );
	}

	/**
	 * The workflows service.
	 *
	 * @return Workflow_Repository
	 */
	public function workflows(): Workflow_Repository {
		return $this->typed( 'workflows', Workflow_Repository::class );
	}

	/**
	 * The identity service.
	 *
	 * @return Identity_Resolver
	 */
	public function identity(): Identity_Resolver {
		return $this->typed( 'identity', Identity_Resolver::class );
	}

	/**
	 * The consent service.
	 *
	 * @return Consent_Store
	 */
	public function consent(): Consent_Store {
		return $this->typed( 'consent', Consent_Store::class );
	}

	/**
	 * The eligibility service.
	 *
	 * @return Eligibility_Evaluator
	 */
	public function eligibility(): Eligibility_Evaluator {
		return $this->typed( 'eligibility', Eligibility_Evaluator::class );
	}

	/**
	 * The ingest service.
	 *
	 * @return Event_Ingest
	 */
	public function ingest(): Event_Ingest {
		return $this->typed( 'ingest', Event_Ingest::class );
	}

	/**
	 * The conversions service.
	 *
	 * @return Conversion_Tracker
	 */
	public function conversions(): Conversion_Tracker {
		return $this->typed( 'conversions', Conversion_Tracker::class );
	}

	/**
	 * The credentials service.
	 *
	 * @return Credentials
	 */
	public function credentials(): Credentials {
		return $this->typed( 'credentials', Credentials::class );
	}

	/**
	 * The transport service.
	 *
	 * @return Transport
	 */
	public function transport(): Transport {
		return $this->typed( 'transport', Transport::class );
	}

	/**
	 * The rate budget service.
	 *
	 * @return Rate_Budget
	 */
	public function rate_budget(): Rate_Budget {
		return $this->typed( 'rate_budget', Rate_Budget::class );
	}

	/**
	 * The wacr service.
	 *
	 * @return Client
	 */
	public function wacr(): Client {
		return $this->typed( 'wacr', Client::class );
	}

	/**
	 * The sources service.
	 *
	 * @return Source_Registry
	 */
	public function sources(): Source_Registry {
		return $this->typed( 'sources', Source_Registry::class );
	}

	/**
	 * Where each pollable source stopped reading.
	 *
	 * @return Source_Cursors
	 */
	public function source_cursors(): Source_Cursors {
		return $this->typed( 'source_cursors', Source_Cursors::class );
	}

	/**
	 * The wc session service.
	 *
	 * @return Wc_Session
	 */
	public function wc_session(): Wc_Session {
		return $this->typed( 'wc_session', Wc_Session::class );
	}

	/**
	 * The send gate service.
	 *
	 * @return Send_Gate
	 */
	public function send_gate(): Send_Gate {
		return $this->typed( 'send_gate', Send_Gate::class );
	}

	/**
	 * The composer service.
	 *
	 * @return Message_Composer
	 */
	public function composer(): Message_Composer {
		return $this->typed( 'composer', Message_Composer::class );
	}

	/**
	 * The steps service.
	 *
	 * @return Step_Registry
	 */
	public function steps(): Step_Registry {
		return $this->typed( 'steps', Step_Registry::class );
	}

	/**
	 * The engine service.
	 *
	 * @return Engine
	 */
	public function engine(): Engine {
		return $this->typed( 'engine', Engine::class );
	}

	/**
	 * The lock service.
	 *
	 * @return Lock
	 */
	public function lock(): Lock {
		return $this->typed( 'lock', Lock::class );
	}

	/**
	 * The scheduler service.
	 *
	 * @return Scheduler_Factory
	 */
	public function scheduler(): Scheduler_Factory {
		return $this->typed( 'scheduler', Scheduler_Factory::class );
	}

	/**
	 * The runner service.
	 *
	 * @return Stage_Runner
	 */
	public function runner(): Stage_Runner {
		return $this->typed( 'runner', Stage_Runner::class );
	}

	/**
	 * The health checks.
	 *
	 * @return Health
	 */
	public function health(): Health {
		return $this->typed( 'health', Health::class );
	}

	/**
	 * The journeys REST controller.
	 *
	 * @return Journeys_Controller
	 */
	public function rest_journeys(): Journeys_Controller {
		return $this->typed( 'rest_journeys', Journeys_Controller::class );
	}

	/**
	 * The status REST controller.
	 *
	 * @return Status_Controller
	 */
	public function rest_status(): Status_Controller {
		return $this->typed( 'rest_status', Status_Controller::class );
	}

	/**
	 * The settings REST controller.
	 *
	 * @return Settings_Controller
	 */
	public function rest_settings(): Settings_Controller {
		return $this->typed( 'rest_settings', Settings_Controller::class );
	}

	/**
	 * The integrations REST collection.
	 *
	 * @return Integrations_Controller
	 */
	public function rest_integrations(): Integrations_Controller {
		return $this->typed( 'rest_integrations', Integrations_Controller::class );
	}

	/**
	 * The approved-templates REST collection.
	 *
	 * @return Templates_Controller
	 */
	public function rest_templates(): Templates_Controller {
		return $this->typed( 'rest_templates', Templates_Controller::class );
	}

	/**
	 * The anonymizer service.
	 *
	 * @return Anonymizer
	 */
	public function anonymizer(): Anonymizer {
		return $this->typed( 'anonymizer', Anonymizer::class );
	}

	/**
	 * The privacy exporter service.
	 *
	 * @return Exporter
	 */
	public function privacy_exporter(): Exporter {
		return $this->typed( 'privacy_exporter', Exporter::class );
	}

	/**
	 * The privacy eraser service.
	 *
	 * @return Eraser
	 */
	public function privacy_eraser(): Eraser {
		return $this->typed( 'privacy_eraser', Eraser::class );
	}

	/**
	 * The overview screen.
	 *
	 * @return Overview_Page
	 */
	public function admin_overview(): Overview_Page {
		return $this->typed( 'admin_overview', Overview_Page::class );
	}

	/**
	 * The journeys screen.
	 *
	 * @return Journeys_Page
	 */
	public function admin_journeys(): Journeys_Page {
		return $this->typed( 'admin_journeys', Journeys_Page::class );
	}

	/**
	 * The journey detail screen.
	 *
	 * @return Journey_Detail
	 */
	public function admin_journey(): Journey_Detail {
		return $this->typed( 'admin_journey', Journey_Detail::class );
	}

	/**
	 * The integrations screen.
	 *
	 * @return Integrations_Page
	 */
	public function admin_integrations(): Integrations_Page {
		return $this->typed( 'admin_integrations', Integrations_Page::class );
	}

	/**
	 * The status screen.
	 *
	 * @return System_Status
	 */
	public function admin_status(): System_Status {
		return $this->typed( 'admin_status', System_Status::class );
	}

	/**
	 * The support report.
	 *
	 * @return Diagnostics
	 */
	public function admin_diagnostics(): Diagnostics {
		return $this->typed( 'admin_diagnostics', Diagnostics::class );
	}

	/**
	 * The workflows screen.
	 *
	 * @return Workflows_Page
	 */
	public function admin_workflows(): Workflows_Page {
		return $this->typed( 'admin_workflows', Workflows_Page::class );
	}

	/**
	 * The opt-out sync.
	 *
	 * @return Opt_Out_Sync
	 */
	public function opt_out_sync(): Opt_Out_Sync {
		return $this->typed( 'opt_out_sync', Opt_Out_Sync::class );
	}

	/**
	 * The approved templates a step may choose from.
	 *
	 * @return Template_Catalog
	 */
	public function template_catalog(): Template_Catalog {
		return $this->typed( 'template_catalog', Template_Catalog::class );
	}

	/**
	 * One workflow's editor.
	 *
	 * @return Workflow_Edit
	 */
	public function admin_workflow(): Workflow_Edit {
		return $this->typed( 'admin_workflow', Workflow_Edit::class );
	}

	/**
	 * The editor's form handler.
	 *
	 * @return Workflow_Form
	 */
	public function admin_workflow_form(): Workflow_Form {
		return $this->typed( 'admin_workflow_form', Workflow_Form::class );
	}

	/**
	 * The admin menu service.
	 *
	 * @return Admin_Menu
	 */
	public function admin_menu(): Admin_Menu {
		return $this->typed( 'admin_menu', Admin_Menu::class );
	}

	/**
	 * The connection test control.
	 *
	 * @return Connection_Test
	 */
	public function admin_connection(): Connection_Test {
		return $this->typed( 'admin_connection', Connection_Test::class );
	}

	/**
	 * The Auto Flow hook test.
	 *
	 * @return Hook_Test
	 */
	public function admin_hook_test(): Hook_Test {
		return $this->typed( 'admin_hook_test', Hook_Test::class );
	}

	/**
	 * The "Run now" button on the status screen.
	 *
	 * @return Run_Now
	 */
	public function admin_run_now(): Run_Now {
		return $this->typed( 'admin_run_now', Run_Now::class );
	}

	/**
	 * The buttons that act on one recovery.
	 *
	 * @return Journey_Actions
	 */
	public function admin_journey_acts(): Journey_Actions {
		return $this->typed( 'admin_journey_acts', Journey_Actions::class );
	}

	/**
	 * Erasing a customer who only ever gave a phone number.
	 *
	 * @return Erase_By_Phone
	 */
	public function privacy_erase_phone(): Erase_By_Phone {
		return $this->typed( 'privacy_erase_phone', Erase_By_Phone::class );
	}

	/**
	 * The first-run setup screen.
	 *
	 * @return Setup
	 */
	public function admin_setup(): Setup {
		return $this->typed( 'admin_setup', Setup::class );
	}

	/**
	 * The admin assets service.
	 *
	 * @return Admin_Assets
	 */
	public function admin_assets(): Admin_Assets {
		return $this->typed( 'admin_assets', Admin_Assets::class );
	}

	/**
	 * The rate limiter service.
	 *
	 * @return Rate_Limiter
	 */
	public function rate_limiter(): Rate_Limiter {
		return $this->typed( 'rate_limiter', Rate_Limiter::class );
	}

	/**
	 * The recovery controller service.
	 *
	 * @return Recovery_Controller
	 */
	/**
	 * The one implementation of "stop messaging me".
	 *
	 * @return Suppressor
	 */
	public function suppressor(): Suppressor {
		return $this->typed( 'suppressor', Suppressor::class );
	}

	public function recovery_controller(): Recovery_Controller {
		return $this->typed( 'recovery_controller', Recovery_Controller::class );
	}

	/**
	 * Attach the plugin to WordPress.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_filter( 'map_meta_cap', array( Capabilities::class, 'map_meta_cap' ), 10, 4 );

		// A role can appear after RecoveryFlow is installed -- WooCommerce
		// creates shop_manager when it activates -- so the grant is re-run at
		// the one moment the role set can change.
		add_action( 'activated_plugin', array( Capabilities::class, 'on_plugin_activated' ) );

		Rewrites::hooks();

		// Migrations run late on init so that anything they touch is registered.
		add_action( 'init', array( Upgrader::class, 'maybe_upgrade' ), 99 );

		// Sources attach here rather than on init because WooCommerce re-keys a
		// guest's session during init_session_cookie(), before wp_loaded. A
		// listener registered any later never sees it, and the shopper's cart
		// becomes a second open event and a second WhatsApp message.
		$this->register_built_in_sources();

		// The admin screens. Registering them on a front-end request would
		// build the whole settings schema -- six tabs of translated labels --
		// to answer a hook that never fires there.
		if ( is_admin() ) {
			$this->admin_menu()->hooks();
			$this->admin_assets()->hooks();
			$this->admin_connection()->hooks();
			$this->admin_workflow_form()->hooks();
			$this->admin_hook_test()->hooks();
			$this->admin_run_now()->hooks();
			$this->admin_journey_acts()->hooks();
			$this->privacy_erase_phone()->hooks();
			$this->admin_setup()->hooks();
		}

		// The REST routes.
		$this->rest_journeys()->hooks();
		$this->rest_status()->hooks();
		$this->rest_settings()->hooks();
		$this->rest_integrations()->hooks();
		$this->rest_templates()->hooks();

		// The privacy tools. Registered on every request, not only in wp-admin:
		// a privacy request is fulfilled by a background job, and an exporter
		// that only existed on an admin screen would silently export nothing.
		$this->privacy_exporter()->hooks();
		$this->privacy_eraser()->hooks();

		// The public recovery endpoint, the stage hooks and the scheduler.
		$this->opt_out_sync()->hooks();
		$this->recovery_controller()->hooks();
		$this->runner()->hooks();
		$this->scheduler()->hooks();

		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'hooks' ) ) {
				$service->hooks();
			}
		}

		// WP-CLI, which needs the container fully built and so goes last. The
		// commands run the scheduler's own code rather than a copy of it, so
		// what happens in a terminal is what happens at three in the morning.
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			CLI_Command::register( $this );
		}

		/**
		 * Fires once RecoveryFlow has attached itself to WordPress.
		 *
		 * The registries are not populated yet at this point; use
		 * recoveryflow_register_sources to add an integration.
		 *
		 * @param Plugin $plugin The container.
		 */
		do_action( Hooks::BOOTED, $this );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'kdc-wacr-recoveryflow',
			false,
			dirname( KDC_WACR_RECOVERYFLOW_BASENAME ) . '/languages'
		);
	}

	/**
	 * Whether the plugin has booted.
	 *
	 * @return bool
	 */
	public function is_booted(): bool {
		return $this->booted;
	}
}
