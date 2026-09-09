<?php
/**
 * The settings screen, described as data.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Settings;

use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Core\Plugin;
use WAcr\RecoveryFlow\Integration\Recovery_Source_Interface;
use WAcr\RecoveryFlow\Integration\Source_Registry;
use WAcr\RecoveryFlow\Recovery\Channel;
use WAcr\RecoveryFlow\Recovery\Email_Compliance;
use WAcr\RecoveryFlow\Support\Options;
use WAcr\RecoveryFlow\WAcr\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Every tab, section, card and field, as one tree.
 *
 * The settings screen is described rather than written. A field appears once,
 * in one place, with its label, its help text, its type and how to clean it on
 * the way in -- and the renderer, the sanitiser, the deeplink router and the
 * REST schema all read that same description. The alternative, which is a form
 * template plus a separate sanitise function plus a separate REST schema, is
 * three lists that drift apart, and the way they drift is always the same: a
 * new field renders, saves, and is silently unvalidated.
 *
 * Two rules hold the tree together and are asserted in the tests rather than
 * trusted:
 *
 * - **every stored setting appears exactly once.** A setting with no home is
 *   one no merchant can change; a setting with two homes has an ambiguous
 *   deeplink and two labels that will eventually disagree.
 * - **a field's id is the option key.** That is what makes
 *   `&field=merchant_postal_address` a stable address for a control, quotable
 *   in a notice, in a status check, in the docs and in a support reply.
 *
 * The one deliberate exception is the WA.cr API key, which is marked `virtual`:
 * it is not part of the settings option at all, because a credential is stored
 * encrypted in its own non-autoloaded option. It still lives in the tree so it
 * still has a label, a section and an address.
 */
final class Schema {

	/**
	 * The tab shown when none is asked for.
	 */
	public const DEFAULT_TAB = 'general';

	/**
	 * The pseudo-key the API key field is addressed by.
	 *
	 * Not a settings key: see the class docblock.
	 */
	public const FIELD_API_KEY = 'wacr_api_key';

	/**
	 * The whole tree.
	 *
	 * Built on each call rather than cached in a static, because every label in
	 * it is translated and a static would freeze one locale for the request --
	 * which is exactly how an admin who switches language gets a half-English
	 * screen.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function tabs(): array {
		return array(
			'general'  => array(
				'label'    => __( 'General', 'kdc-wacr-recoveryflow' ),
				'sections' => self::general_sections(),
			),
			'recovery' => array(
				'label'    => __( 'Recovery', 'kdc-wacr-recoveryflow' ),
				'sections' => self::recovery_sections(),
			),
			'channels' => array(
				'label'    => __( 'Channels', 'kdc-wacr-recoveryflow' ),
				'sections' => self::channel_sections(),
			),
			'wacr'     => array(
				'label'    => __( 'WA.cr', 'kdc-wacr-recoveryflow' ),
				'sections' => self::wacr_sections(),
			),
			'sources'  => array(
				'label'    => __( 'Integrations', 'kdc-wacr-recoveryflow' ),
				'sections' => self::source_sections(),
			),
			'privacy'  => array(
				'label'    => __( 'Privacy', 'kdc-wacr-recoveryflow' ),
				'sections' => self::privacy_sections(),
			),
			'advanced' => array(
				'label'    => __( 'Advanced', 'kdc-wacr-recoveryflow' ),
				'sections' => self::advanced_sections(),
			),
		);
	}

	/**
	 * General.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function general_sections(): array {
		return array(
			'status'    => array(
				'title'       => __( 'Recovery', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'Nothing is recorded and nothing is sent until this is switched on.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'master' => array(
						'title'  => __( 'Run recovery on this site', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'enabled' => array(
								'type'  => 'checkbox',
								'label' => __( 'Recover lost sales', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'While this is off RecoveryFlow watches nothing, stores nothing and sends nothing. Switching it off later stops new reminders; journeys already under way finish or expire on their own.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			'audience'  => array(
				'title'       => __( 'Who is left alone', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'People this site never messages, whatever else the rules say.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'exclusions' => array(
						'title'  => __( 'Exclusions', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'exclude_admins' => array(
								'type'  => 'checkbox',
								'label' => __( 'Never message staff accounts', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'Anyone who can edit the shop is skipped. Leave this on unless you are deliberately testing with a staff account, and remember that a test message still costs what a real one costs.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			'reference' => array(
				'title'       => __( 'Where things are', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'Links to the screens that answer the questions this one cannot.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'signposts' => array(
						'title'    => __( 'Other screens', 'kdc-wacr-recoveryflow' ),
						'renderer' => 'signposts',
						'fields'   => array(),
					),
				),
			),
		);
	}

	/**
	 * Recovery rules.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function recovery_sections(): array {
		return array(
			'capture'     => array(
				'title'       => __( 'Where a contact detail is collected', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'A basket is worth nothing to a recovery until the shop knows how to reach the person. The checkout is where that normally happens, and a shopper who leaves before reaching it was never reachable at all. These ask earlier. All three are off until you turn them on, because each one adds a field to a page shoppers are trying to get through.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'points' => array(
						'title'  => __( 'Earlier than the checkout', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'capture_at_cart'         => array(
								'type'  => 'checkbox',
								'label' => __( 'Ask on the basket page', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'Adds a short form below the basket offering to save it. It works with scripts blocked and posts nowhere else. Most shoppers who abandon never reach the checkout, so this is the one that changes how many baskets are recoverable at all.', 'kdc-wacr-recoveryflow' ),
							),
							'capture_at_add_to_cart'  => array(
								'type'  => 'checkbox',
								'label' => __( 'Ask when something is added to the basket', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'Adds the field beside the "add to basket" button on a product page, and it travels with WooCommerce\'s own request -- nothing extra is sent and no address of ours is opened. Leaving it blank still adds the product, exactly as before.', 'kdc-wacr-recoveryflow' ),
							),
							'checkout_phone_required' => array(
								'type'  => 'checkbox',
								'label' => __( 'Make the phone number required at the checkout', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'WooCommerce ships the billing phone as optional. Requiring it means every order carries one, and every abandoned checkout that got that far is reachable. It also means somebody who will not give a number cannot buy from you, which costs completed sales as well as saving lost ones -- so this is a trade rather than an improvement.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			'timing'      => array(
				'title'       => __( 'When a sale counts as lost', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'A basket nobody has touched for a while is treated as abandoned. These two settings decide how long that is, and how stale is too stale to bother.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'abandonment' => array(
						'title'  => __( 'Abandonment', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'inactivity_minutes' => array(
								'type'  => 'number',
								'label' => __( 'Wait before treating a basket as abandoned', 'kdc-wacr-recoveryflow' ),
								'unit'  => __( 'minutes', 'kdc-wacr-recoveryflow' ),
								'min'   => 1,
								'max'   => 10080,
								'help'  => __( 'Shorter reaches people while they are still deciding. Too short reaches people who went to find their card. Thirty minutes to an hour suits most shops.', 'kdc-wacr-recoveryflow' ),
							),
							'max_age_days'       => array(
								'type'  => 'number',
								'label' => __( 'Stop chasing a basket after', 'kdc-wacr-recoveryflow' ),
								'unit'  => __( 'days', 'kdc-wacr-recoveryflow' ),
								'min'   => 1,
								'max'   => 90,
								'help'  => __( 'A basket older than this is left alone. Messaging somebody about something they looked at a fortnight ago reads as surveillance rather than service.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			'limits'      => array(
				'title'       => __( 'How often anyone hears from you', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'Caps that apply to a person, not to a basket. They are the difference between a reminder and a nuisance, and on WhatsApp they are the difference between a working sender and a blocked one.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'frequency' => array(
						'title'    => __( 'Frequency', 'kdc-wacr-recoveryflow' ),
						'fields'   => array(
							'max_touches'            => array(
								'type'  => 'number',
								'label' => __( 'Messages per lost sale', 'kdc-wacr-recoveryflow' ),
								'min'   => 1,
								'max'   => 10,
								'help'  => __( 'How many reminders one abandoned basket may produce, however many steps the workflow has.', 'kdc-wacr-recoveryflow' ),
							),
							'frequency_cap_hours'    => array(
								'type'  => 'number',
								'label' => __( 'Shortest gap between two messages to one person', 'kdc-wacr-recoveryflow' ),
								'unit'  => __( 'hours', 'kdc-wacr-recoveryflow' ),
								'min'   => 0,
								'max'   => 720,
								'help'  => __( 'Counted across every basket, so somebody who abandons twice in an afternoon hears from you once.', 'kdc-wacr-recoveryflow' ),
							),
							'max_journeys_per_month' => array(
								'type'  => 'number',
								'label' => __( 'Most recovery attempts per person per month', 'kdc-wacr-recoveryflow' ),
								'min'   => 1,
								'max'   => 31,
								'help'  => __( 'A rolling thirty days, not a calendar month.', 'kdc-wacr-recoveryflow' ),
							),
						),
						'advanced' => array(
							'min_amount' => array(
								'type'  => 'number',
								'label' => __( 'Ignore baskets worth less than', 'kdc-wacr-recoveryflow' ),
								'min'   => 0,
								'step'  => '0.01',
								'help'  => __( 'In the shop currency. Zero chases every basket. Set this above the cost of a message if small baskets are not worth recovering.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			'quiet_hours' => array(
				'title'       => __( 'Quiet hours', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'Hours when nothing is sent. A message held back waits for the window to open rather than being dropped.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'window' => array(
						'title'  => __( 'Do not disturb', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'quiet_hours_enabled' => array(
								'type'  => 'checkbox',
								'label' => __( 'Hold messages overnight', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'A recovery message that arrives at three in the morning is remembered for the wrong reason.', 'kdc-wacr-recoveryflow' ),
							),
							'quiet_hours_start'   => array(
								'type'     => 'time',
								'label'    => __( 'Quiet from', 'kdc-wacr-recoveryflow' ),
								'requires' => 'quiet_hours_enabled',
								'help'     => __( 'In this site\'s timezone, not the customer\'s. RecoveryFlow does not know where a phone number is being read.', 'kdc-wacr-recoveryflow' ),
							),
							'quiet_hours_end'     => array(
								'type'     => 'time',
								'label'    => __( 'Quiet until', 'kdc-wacr-recoveryflow' ),
								'requires' => 'quiet_hours_enabled',
								'help'     => __( 'An end earlier than the start means the quiet window crosses midnight, which is the usual case.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			'links'       => array(
				'title'       => __( 'Recovery links', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'The link in a reminder that puts the basket back. The same link is what an unsubscribe uses, which is why its lifetime is a compliance setting as well as a convenience one.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'link' => array(
						'title'  => __( 'Link behaviour', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'recovery_link_ttl_days' => array(
								'type'  => 'number',
								'label' => __( 'A recovery link works for', 'kdc-wacr-recoveryflow' ),
								'unit'  => __( 'days', 'kdc-wacr-recoveryflow' ),
								'min'   => 1,
								'max'   => 30,
								'help'  => sprintf(
									/* translators: %d: the number of days an unsubscribe link must keep working. */
									__( 'After this the link stops working and shows a plain "no longer available" page. Email reminders need at least %d days, because the unsubscribe link in an email is this same link and it has to outlive the email.', 'kdc-wacr-recoveryflow' ),
									Email_Compliance::MIN_UNSUBSCRIBE_DAYS
								),
							),
							'prefill_guest_checkout' => array(
								'type'  => 'checkbox',
								'label' => __( 'Fill the checkout back in for guests', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'Someone who follows a recovery link gets the details they had already typed put back. It only ever fills in what that same person entered.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			'attribution' => array(
				'title'       => __( 'Crediting a recovery', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'How long after a reminder an order still counts as recovered by it.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'window' => array(
						'title'  => __( 'Attribution window', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'attribution_window_days' => array(
								'type'  => 'number',
								'label' => __( 'Credit an order to a reminder for', 'kdc-wacr-recoveryflow' ),
								'unit'  => __( 'days', 'kdc-wacr-recoveryflow' ),
								'min'   => 1,
								'max'   => 365,
								'help'  => __( 'This changes the reported figures only. It never causes or prevents a message.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Channels.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function channel_sections(): array {
		return array(
			Channel::WHATSAPP => array(
				'title'       => __( 'WhatsApp', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'The channel RecoveryFlow is built around. Sending needs a connected WA.cr workspace.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'switch' => array(
						'title'  => __( 'WhatsApp reminders', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'channel_whatsapp_enabled' => array(
								'type'  => 'checkbox',
								'label' => __( 'Send reminders over WhatsApp', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'Workflow steps set to WhatsApp are skipped while this is off. Nothing else changes: baskets are still recorded and journeys still run.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			Channel::EMAIL    => array(
				'title'       => __( 'Email', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'A recovery email is commercial mail, and the law asks for two things before one may be sent: a real postal address for the business sending it, and an unsubscribe link that keeps working. RecoveryFlow refuses to send email until both are settled.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'compliance' => array(
						'title'       => __( 'Before email can be switched on', 'kdc-wacr-recoveryflow' ),
						'description' => __( 'Settling all of these makes email reminders permissible. It does not switch them on -- that is still a separate decision, on the card below.', 'kdc-wacr-recoveryflow' ),
						'renderer'    => 'email_compliance',
						'fields'      => array(
							Email_Compliance::SETTING_ADDRESS => array(
								'type'      => 'address',
								'label'     => __( 'Postal address of the business', 'kdc-wacr-recoveryflow' ),
								'rows'      => 5,
								'maxlength' => Email_Compliance::MAX_ADDRESS_LENGTH,
								'help'      => __( 'One line per line, exactly as you would write it on an envelope. It is printed at the foot of every recovery email and is never translated or reformatted. A registered office or a post-office box is fine where the law of your country allows one.', 'kdc-wacr-recoveryflow' ),
							),
							Email_Compliance::SETTING_COUNTRY => array(
								'type'  => 'country',
								'label' => __( 'Country that address is in', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'Asked separately because an address is laid out according to its own country, and nothing in the text of an address reliably says which country it is in.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
					'switch'     => array(
						'title'  => __( 'Email reminders', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'channel_email_enabled' => array(
								'type'  => 'checkbox',
								'label' => __( 'Send reminders by email', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'Off by default. Email passes two gates: this switch, and the compliance settings above. Turning this on while anything above is unsettled changes nothing -- no email is sent either way.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * WA.cr connection.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function wacr_sections(): array {
		return array(
			'connection' => array(
				'title'       => __( 'Connection', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'The WA.cr workspace this site sends through.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'credential' => array(
						'title'    => __( 'API key', 'kdc-wacr-recoveryflow' ),
						'renderer' => 'wacr_connection',
						'fields'   => array(
							self::FIELD_API_KEY => array(
								'type'    => 'secret',
								'virtual' => true,
								'label'   => __( 'WA.cr API key', 'kdc-wacr-recoveryflow' ),
								'help'    => __( 'Created in the WA.cr console. It is stored encrypted and is never shown again after saving; entering a new one replaces it, and clearing the box leaves the saved key alone.', 'kdc-wacr-recoveryflow' ),
							),
						),
						'advanced' => array(
							'wacr_environment' => array(
								'type'    => 'select',
								'label'   => __( 'Environment', 'kdc-wacr-recoveryflow' ),
								'options' => array(
									'production' => __( 'Production', 'kdc-wacr-recoveryflow' ),
									'staging'    => __( 'Staging', 'kdc-wacr-recoveryflow' ),
								),
								'help'    => __( 'Staging talks to the WA.cr test service. Messages sent there are not delivered to real phones and are not billed.', 'kdc-wacr-recoveryflow' ),
							),
							'wacr_base_url'    => array(
								'type'        => 'url',
								'label'       => __( 'API address', 'kdc-wacr-recoveryflow' ),
								'placeholder' => Credentials::HOST_PRODUCTION,
								'help'        => __( 'Leave empty unless WA.cr support has given you a different address. An address that is not a WA.cr host is refused.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			'sending'    => array(
				'title'       => __( 'How messages are sent', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'RecoveryFlow can hand a journey to a WA.cr Auto Flow and let WA.cr do the messaging, or send each message itself on the schedule in the workflow.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'callback' => array(
						'title'       => __( 'Letting a flow call this site back', 'kdc-wacr-recoveryflow' ),
						'description' => __( 'Optional, and only useful once you have built an Auto Flow. It lets the flow tell this site that somebody replied STOP, so they are stopped here at once rather than at the next background pass.', 'kdc-wacr-recoveryflow' ),
						'cards'       => array(
							'secret' => array(
								'title'    => __( 'Webhook address and secret', 'kdc-wacr-recoveryflow' ),
								'fields'   => array(),
								'renderer' => 'webhook_setup',
							),
						),
					),
					'dispatch' => array(
						'title'    => __( 'Dispatch', 'kdc-wacr-recoveryflow' ),
						'renderer' => 'auto_flow',
						'fields'   => array(
							'wacr_dispatch'    => array(
								'type'    => 'select',
								'label'   => __( 'Send reminders by', 'kdc-wacr-recoveryflow' ),
								'options' => array(
									'start_flow'    => __( 'Handing the journey to a WA.cr Auto Flow', 'kdc-wacr-recoveryflow' ),
									'send_template' => __( 'Sending each message from WordPress', 'kdc-wacr-recoveryflow' ),
								),
								'help'    => __( 'Handing over needs a workspace that can run an Auto Flow, which starts at WA.cr Growth, and puts the timing and the wording in WA.cr. Sending from WordPress keeps both here and needs a workspace that can use the WA.cr developer API.', 'kdc-wacr-recoveryflow' ),
							),
							'wacr_hook_url'    => array(
								'type'        => 'url',
								'label'       => __( 'Auto Flow hook address', 'kdc-wacr-recoveryflow' ),
								'placeholder' => 'https://hook.wa.cr/...',
								'help'        => __( 'Copied from the Auto Flow in WA.cr that should pick these journeys up. Each request is signed, so a hook address on its own is not enough for anyone else to trigger your flow.', 'kdc-wacr-recoveryflow' ),
							),
							'wacr_push_optout' => array(
								'type'    => 'checkbox',
								'label'   => __( 'Opting out here also opts them out in WA.cr', 'kdc-wacr-recoveryflow' ),
								'feature' => Feature_Gate::DIRECT_SEND,
								'help'    => __( 'A customer who uses the unsubscribe link is asking the shop to stop, not this plugin. With this on, RecoveryFlow also marks them as opted out in your WA.cr workspace, which takes them out of broadcasts and any campaign built from a segment. It does NOT stop a message sent directly through the WA.cr API or named by an Auto Flow -- those never check the flag. Your key needs the contacts:write permission.', 'kdc-wacr-recoveryflow' ),
							),
							'wacr_sender'      => array(
								'type'    => 'text',
								'label'   => __( 'Send from', 'kdc-wacr-recoveryflow' ),
								'feature' => Feature_Gate::DIRECT_SEND,
								'help'    => __( 'The WhatsApp number in your workspace that reminders come from. Leave empty to use the workspace default.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			'sharing'    => array(
				'title'       => __( 'What is shared with WA.cr', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'A reminder cannot be sent without sending WA.cr the number it goes to. Everything beyond that is optional and off unless you turn it on.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'shared' => array(
						'title'  => __( 'Optional details', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'wacr_share_last_name' => array(
								'type'  => 'checkbox',
								'label' => __( 'Share the customer\'s last name', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'First names are shared because message templates usually greet somebody by one. Last names are not, unless a template you use needs one.', 'kdc-wacr-recoveryflow' ),
							),
							'wacr_share_email'     => array(
								'type'  => 'checkbox',
								'label' => __( 'Share the customer\'s email address', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'Only needed if your WA.cr workspace matches contacts by email or sends email itself.', 'kdc-wacr-recoveryflow' ),
							),
							'wacr_sync_optout'     => array(
								'type'  => 'checkbox',
								'label' => __( 'Opting out in WA.cr also stops reminders from here', 'kdc-wacr-recoveryflow' ),
								'help'  => __( 'Somebody who replies STOP in WhatsApp is opted out in WA.cr, and WA.cr does not apply that to messages sent from here. With this on, RecoveryFlow checks before each reminder and stays silent for them. Turning it off means somebody who has told you to stop can still receive a cart reminder from this site. The answer is remembered for six hours per number, and your key needs the contacts:read permission.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Privacy.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function privacy_sections(): array {
		return array(
			'lawful_basis' => array(
				'title'       => __( 'Why this site may message somebody', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'The most consequential setting here. It decides who RecoveryFlow considers reachable, and it is the one a regulator would ask about first.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'basis' => array(
						'title'  => __( 'Basis for messaging', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'eligibility_mode' => array(
								'type'    => 'radio',
								'label'   => __( 'Message somebody when', 'kdc-wacr-recoveryflow' ),
								'options' => array(
									'explicit_consent'   => __( 'They ticked a box asking for reminders', 'kdc-wacr-recoveryflow' ),
									'identified_contact' => __( 'They gave their details at the checkout', 'kdc-wacr-recoveryflow' ),
									'disabled'           => __( 'Never -- record baskets but message nobody', 'kdc-wacr-recoveryflow' ),
								),
								'help'    => __( 'Asking first is the safe answer everywhere and the only defensible one in the EU and the UK. Treating a typed phone number as permission may be lawful where you trade; it is your decision and your risk, and RecoveryFlow records which basis each message was sent under either way.', 'kdc-wacr-recoveryflow' ),
							),
							'consent_label'    => array(
								'type'        => 'text',
								'label'       => __( 'Wording of the consent tick-box', 'kdc-wacr-recoveryflow' ),
								'requires'    => 'eligibility_mode:explicit_consent',
								'maxlength'   => 200,
								'placeholder' => __( 'Send me a reminder if I do not finish my order', 'kdc-wacr-recoveryflow' ),
								'help'        => __( 'Shown at the checkout. Leave empty for the default wording. What was agreed to is stored with each consent, so changing this does not rewrite what anyone previously agreed to.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			'erase'        => array(
				'title'       => __( 'Erase one customer', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'WordPress finds somebody by email address. Many of the people RecoveryFlow holds gave a phone number at the checkout and never an address, and cannot be found that way at all.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'by_phone' => array(
						'title'    => __( 'Erase by phone number', 'kdc-wacr-recoveryflow' ),
						'fields'   => array(),
						'renderer' => 'erase_by_phone',
					),
				),
			),
			'retention'    => array(
				'title'       => __( 'How long anything is kept', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'An abandoned basket says what somebody was about to buy. There is no reason to still know that a year later, so finished journeys are stripped of their contents on a schedule.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'keep' => array(
						'title'  => __( 'Retention', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'retention_days' => array(
								'type'  => 'number',
								'label' => __( 'Keep the contents of a finished journey for', 'kdc-wacr-recoveryflow' ),
								'unit'  => __( 'days', 'kdc-wacr-recoveryflow' ),
								'min'   => 7,
								'max'   => 3650,
								'help'  => __( 'After this the basket contents, the notes and the session are removed and the amounts and dates stay, because those are your trading record rather than anything about a person. A week is the shortest this plugin will honour: shorter throws away the evidence you need when somebody asks why they were messaged, and that question always arrives late.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
			'removal'      => array(
				'title'       => __( 'Deleting the plugin', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'What happens to the data if RecoveryFlow is removed from this site.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'uninstall' => array(
						'title'  => __( 'On uninstall', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'delete_data_on_uninstall' => array(
								'type'    => 'checkbox',
								'label'   => __( 'Delete everything when the plugin is deleted', 'kdc-wacr-recoveryflow' ),
								'danger'  => true,
								'help'    => __( 'Off by default, because deactivating a plugin by accident is common and losing every consent record is not recoverable. With this on, deleting the plugin drops its tables -- including the record of who asked not to be messaged.', 'kdc-wacr-recoveryflow' ),
								'confirm' => __( 'This will delete every recovery record, including who opted out, when the plugin is deleted. Continue?', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Advanced.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function advanced_sections(): array {
		return array(
			'logging' => array(
				'title'       => __( 'Logging', 'kdc-wacr-recoveryflow' ),
				'description' => __( 'RecoveryFlow keeps a short diagnostic log. Contact details, message contents and API keys are stripped out before anything is written, so the log says what happened without saying who it happened to.', 'kdc-wacr-recoveryflow' ),
				'cards'       => array(
					'log' => array(
						'title'  => __( 'Diagnostic log', 'kdc-wacr-recoveryflow' ),
						'fields' => array(
							'logging_level'      => array(
								'type'    => 'select',
								'label'   => __( 'Record', 'kdc-wacr-recoveryflow' ),
								'options' => array(
									'error'   => __( 'Failures only', 'kdc-wacr-recoveryflow' ),
									'warning' => __( 'Failures and warnings', 'kdc-wacr-recoveryflow' ),
									'info'    => __( 'Everything of note', 'kdc-wacr-recoveryflow' ),
									'debug'   => __( 'Everything, for diagnosing a problem', 'kdc-wacr-recoveryflow' ),
								),
								'help'    => __( 'Leave on failures and warnings for normal running. The most detailed setting writes a great deal on a busy shop; turn it back down once you have what you needed.', 'kdc-wacr-recoveryflow' ),
							),
							'log_retention_days' => array(
								'type'  => 'number',
								'label' => __( 'Keep log entries for', 'kdc-wacr-recoveryflow' ),
								'unit'  => __( 'days', 'kdc-wacr-recoveryflow' ),
								'min'   => 1,
								'max'   => 365,
								'help'  => __( 'Older entries are removed by the daily clear-out.', 'kdc-wacr-recoveryflow' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * One section per registered integration, built from the registry.
	 *
	 * This is the half of "a second adapter ships with zero core changes" that
	 * is easy to get wrong. A source declares its own settings through
	 * get_settings_fields(), and the tempting way to show them is a bespoke
	 * form on the Integrations screen -- which would need its own sanitiser,
	 * and a sanitiser written twice is a sanitiser that disagrees with itself.
	 * Folding them into this tree instead means an adapter's fields are
	 * rendered, validated, deeplinked and REST-described by exactly the same
	 * code as the plugin's own, and an adapter cannot ship a field that saves
	 * without being cleaned.
	 *
	 * The switch is a field like any other rather than a button somewhere else,
	 * for the same reason: a control that lives outside the tree is a control
	 * with no address, no validation and no entry in the audit that proves
	 * every stored setting has exactly one home.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function source_sections(): array {
		$registry = self::registry();
		$sections = array();

		if ( null === $registry ) {
			return $sections;
		}

		foreach ( $registry->all() as $id => $source ) {
			$sections[ $id ] = array(
				'title'       => $source->get_name(),
				'description' => $source->get_description(),
				'cards'       => array(
					'watching' => array(
						'title'  => __( 'Watching', 'kdc-wacr-recoveryflow' ),
						'fields' => array_merge(
							array(
								Source_Registry::enabled_key( $id ) => array(
									'type'    => 'checkbox',
									'label'   => __( 'Record what is left unfinished here', 'kdc-wacr-recoveryflow' ),
									'help'    => self::watching_help( $registry, $id ),
									'default' => true,
								),
							),
							self::source_fields( $source )
						),
					),
				),
			);
		}//end foreach

		return $sections;
	}

	/**
	 * What switching one integration off actually means, said in its own terms.
	 *
	 * @param Source_Registry $registry The registry.
	 * @param string          $id       Source id.
	 * @return string
	 */
	private static function watching_help( Source_Registry $registry, string $id ): string {
		switch ( $registry->status( $id ) ) {
			case Source_Registry::UNAVAILABLE:
				return __( 'Whatever this integration needs is not installed or not active on this site, so this switch changes nothing until it is.', 'kdc-wacr-recoveryflow' );

			case Source_Registry::NOT_INCLUDED:
				return __( 'Integrations beyond WooCommerce are included with the WA.cr Scale plan and above. This switch is remembered, and takes effect when the workspace can use it.', 'kdc-wacr-recoveryflow' );

			default:
				return __( 'Switching this off stops new journeys being recorded from here. Journeys already under way finish or expire on their own, and nothing already recorded is deleted.', 'kdc-wacr-recoveryflow' );
		}
	}

	/**
	 * An adapter's own fields, namespaced so two adapters cannot collide.
	 *
	 * A source that returns a key another source already uses would otherwise
	 * write over it: the settings live in one option, and "phone_field" is a
	 * name two form plugins would both reach for.
	 *
	 * @param Recovery_Source_Interface $source The source.
	 * @return array<string,array<string,mixed>>
	 */
	private static function source_fields( Recovery_Source_Interface $source ): array {
		$fields = array();

		foreach ( $source->get_settings_fields() as $key => $spec ) {
			if ( ! is_array( $spec ) || ! isset( $spec['label'] ) ) {
				continue;
			}

			$fields[ Source_Registry::setting_key( $source->get_id(), (string) $key ) ] = $spec;
		}

		return $fields;
	}

	/**
	 * The source registry, or null before the plugin has been built.
	 *
	 * The settings tree is read in places the container is not guaranteed to
	 * exist -- an uninstall, a unit test of the sanitiser alone -- and a schema
	 * that fataled there would take the whole request with it. Without a
	 * registry the tab is simply empty, which is also the honest answer.
	 *
	 * @return Source_Registry|null
	 */
	private static function registry(): ?Source_Registry {
		if ( ! class_exists( Plugin::class ) ) {
			return null;
		}

		$plugin = Plugin::instance();

		return $plugin->sources();
	}

	/**
	 * Every field in the tree, flattened, keyed by setting key.
	 *
	 * @return array<string,array<string,mixed>> Setting key => field spec, with tab, section and card added.
	 */
	public static function fields(): array {
		$fields = array();

		foreach ( self::tabs() as $tab_id => $tab ) {
			foreach ( $tab['sections'] as $section_id => $section ) {
				foreach ( $section['cards'] as $card_id => $card ) {
					foreach ( array( 'fields', 'advanced' ) as $group ) {
						foreach ( $card[ $group ] ?? array() as $key => $spec ) {
							$spec['tab']      = $tab_id;
							$spec['section']  = $section_id;
							$spec['card']     = $card_id;
							$spec['advanced'] = 'advanced' === $group;

							$fields[ $key ] = $spec;
						}
					}
				}
			}
		}

		return $fields;
	}

	/**
	 * One field's spec, or null when the key is not on the settings screen.
	 *
	 * @param string $key Setting key.
	 * @return array<string,mixed>|null
	 */
	public static function field( string $key ): ?array {
		$fields = self::fields();

		return $fields[ $key ] ?? null;
	}

	/**
	 * The setting keys that belong to one tab.
	 *
	 * This is what stops a save on one tab from wiping another's values: the
	 * form posts only its own tab, so the sanitiser has to know which keys that
	 * tab is allowed to speak for and leave every other key as it was.
	 *
	 * @param string $tab Tab id.
	 * @return string[]
	 */
	public static function keys_for_tab( string $tab ): array {
		$keys = array();

		foreach ( self::fields() as $key => $spec ) {
			if ( $spec['tab'] === $tab && empty( $spec['virtual'] ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Whether a tab id exists.
	 *
	 * @param string $tab Tab id.
	 * @return bool
	 */
	public static function has_tab( string $tab ): bool {
		return array_key_exists( $tab, self::tabs() );
	}

	/**
	 * The tab a setting key lives on.
	 *
	 * @param string $key Setting key.
	 * @return string Empty when the key is not on the screen.
	 */
	public static function tab_for( string $key ): string {
		$field = self::field( $key );

		return null === $field ? '' : (string) $field['tab'];
	}

	/**
	 * A deeplink straight to one setting.
	 *
	 * @param string $key Setting key.
	 * @return string Empty when the key is not on the screen.
	 */
	public static function deeplink( string $key ): string {
		$field = self::field( $key );

		if ( null === $field ) {
			return '';
		}

		return \WAcr\RecoveryFlow\Admin\Screen::settings_url( (string) $field['tab'], (string) $field['section'], $key );
	}

	/**
	 * The settings option keys that have no place on the screen.
	 *
	 * Reported by the tests rather than by the screen: a stored setting nobody
	 * can reach is a bug in this file, not a condition a site can be in.
	 *
	 * @return string[]
	 */
	public static function unreachable_settings(): array {
		return array_values( array_diff( array_keys( Options::defaults() ), array_keys( self::fields() ) ) );
	}
}
