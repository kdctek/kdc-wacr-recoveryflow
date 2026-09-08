<?php
/**
 * What the merchant told this adapter to watch.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\GravityForms;

use WAcr\RecoveryFlow\Integration\Source_Registry;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The adapter's own settings, read in one place instead of five.
 *
 * These are ordinary fields in the plugin's settings tree -- declared by
 * get_settings_fields(), rendered, validated and deeplinked by the same code as
 * everything else -- so all this class does is read them back under the keys
 * they are stored as. It exists so that no other file in this directory has to
 * know the naming convention, which is the sort of thing that gets typed
 * slightly differently in the sixth place it appears.
 */
final class Settings {

	/**
	 * Which forms to watch: a list of ids, or empty for all of them.
	 */
	public const FORMS = 'forms';

	/**
	 * Whether to recover save-and-continue drafts.
	 */
	public const DRAFTS = 'drafts';

	/**
	 * Whether to recover entries whose payment never arrived.
	 */
	public const UNPAID = 'unpaid';

	/**
	 * Read one of this adapter's settings.
	 *
	 * @param string $key      One of the class constants.
	 * @param mixed  $fallback Value when nothing is stored.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$stored = Options::get( Source_Registry::setting_key( Source::ID, $key ), null );

		return null === $stored ? $fallback : $stored;
	}

	/**
	 * Whether drafts are being recovered.
	 *
	 * @return bool
	 */
	public static function drafts_enabled(): bool {
		return (bool) self::get( self::DRAFTS, true );
	}

	/**
	 * Whether unpaid entries are being recovered.
	 *
	 * @return bool
	 */
	public static function unpaid_enabled(): bool {
		return (bool) self::get( self::UNPAID, true );
	}

	/**
	 * Whether this form is one of the ones being watched.
	 *
	 * An empty list means every form, because that is what a merchant who has
	 * not thought about it wants, and because a list that had to be filled in
	 * before anything worked would make the integration look broken.
	 *
	 * @param int $form_id The form.
	 * @return bool
	 */
	public static function watches( int $form_id ): bool {
		$ids = self::form_ids();

		return array() === $ids || in_array( $form_id, $ids, true );
	}

	/**
	 * The form ids the merchant listed.
	 *
	 * @return int[]
	 */
	public static function form_ids(): array {
		$raw = (string) self::get( self::FORMS, '' );
		$ids = array();

		$pieces = preg_split( '/[^0-9]+/', $raw );

		foreach ( is_array( $pieces ) ? $pieces : array() as $piece ) {
			$id = (int) $piece;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Field ids pinned for a form, overriding "the first field of its type".
	 *
	 * A filter rather than a screen. Pinning a field is a thing a handful of
	 * sites need and every site would have to read past, and the honest shape
	 * for it is code: the merchant who needs it has a form with two phone
	 * fields and already knows which one is the mobile.
	 *
	 * @param array<string,mixed> $form The form.
	 * @return array<string,string> Field type to field id.
	 */
	public static function field_overrides( array $form ): array {
		/**
		 * Pins which field of a Gravity Forms form holds a contact detail.
		 *
		 * Keys are Gravity Forms field types -- email, phone, name, address --
		 * and values are field ids on this form. Anything not named falls back
		 * to the first field of that type.
		 *
		 * @param array<string,string> $overrides Field type to field id.
		 * @param array<string,mixed>  $form      The form object.
		 */
		$overrides = apply_filters( 'recoveryflow_gf_field_overrides', array(), $form );

		return is_array( $overrides ) ? array_map( 'strval', $overrides ) : array();
	}

	/**
	 * The fields this adapter puts on the Integrations tab.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		return array(
			self::DRAFTS => array(
				'type'    => 'checkbox',
				'label'   => __( 'Remind people who saved a form to finish later', 'kdc-wacr-recoveryflow' ),
				'help'    => __( 'Gravity Forms\' "Save and continue" gives somebody a link back to a half-finished form and then never mentions it again. Gravity Forms deletes those drafts after thirty days by default, and the link dies with them, so a reminder is worth little after about a week.', 'kdc-wacr-recoveryflow' ),
				'default' => true,
			),
			self::UNPAID => array(
				'type'    => 'checkbox',
				'label'   => __( 'Remind people whose payment never went through', 'kdc-wacr-recoveryflow' ),
				'help'    => __( 'An entry that was submitted but never paid for. A refused card is not a lost customer, and this is the case where a reminder is most often what completes the sale.', 'kdc-wacr-recoveryflow' ),
				'default' => true,
			),
			self::FORMS  => array(
				'type'      => 'text',
				'label'     => __( 'Only these forms', 'kdc-wacr-recoveryflow' ),
				'help'      => __( 'Form numbers, separated by commas, for example 3, 7, 12. Leave this empty to watch every form. A form with no phone field and no email field is skipped either way, because nobody on it could be messaged.', 'kdc-wacr-recoveryflow' ),
				'maxlength' => 200,
				'default'   => '',
			),
		);
	}
}
