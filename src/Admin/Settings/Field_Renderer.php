<?php
/**
 * Drawing one setting.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Settings;

use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a field spec as a row of a core form table.
 *
 * Only core's own markup and classes are used, so the screen inherits every
 * focus outline, contrast ratio and keyboard behaviour WordPress already gets
 * right, and keeps inheriting them when WordPress changes its mind.
 *
 * Three accessibility decisions are worth naming, because each is the kind of
 * thing that looks like a detail and is the whole experience for somebody:
 *
 * - **help text is associated, not merely adjacent.** Every control points at
 *   its description with aria-describedby, so a screen reader reads the
 *   explanation as part of the control rather than as stray text somebody has
 *   to go hunting for after the fact.
 * - **conditional fields are rendered, then hidden.** A field that only matters
 *   when another is ticked is always in the HTML and always reachable; script
 *   collapses it afterwards. With scripts off the screen is complete rather
 *   than missing settings, which is the difference between an enhancement and
 *   a dependency.
 * - **a deeplinked field is marked, not merely scrolled to.** The row carries a
 *   class that draws a visible outline, and the browser's own focus goes to the
 *   control, so "the setting you were sent here for is this one" is conveyed by
 *   position, by outline and by focus rather than by colour alone.
 */
final class Field_Renderer {

	/**
	 * Settings snapshot being rendered.
	 *
	 * @var array<string,mixed>
	 */
	private array $settings;

	/**
	 * The setting the deeplink asked for, if any.
	 *
	 * @var string
	 */
	private string $focused;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @param string              $focused  Setting key the deeplink named.
	 */
	public function __construct( array $settings, string $focused = '' ) {
		$this->settings = $settings;
		$this->focused  = $focused;
	}

	/**
	 * Render one field as a form-table row.
	 *
	 * @param string              $key  Setting key.
	 * @param array<string,mixed> $spec Field spec.
	 * @return void
	 */
	public function row( string $key, array $spec ): void {
		$id       = Screen::field_anchor( $key );
		$help_id  = $id . '-help';
		$type     = (string) ( $spec['type'] ?? 'text' );
		$has_help = '' !== (string) ( $spec['help'] ?? '' );
		$locked   = $this->is_locked( $spec );

		$classes = array( 'recoveryflow-field', 'recoveryflow-field--' . sanitize_html_class( $type ) );

		if ( $key === $this->focused ) {
			$classes[] = 'recoveryflow-field--targeted';
		}

		if ( ! empty( $spec['danger'] ) ) {
			$classes[] = 'recoveryflow-field--danger';
		}

		printf(
			'<tr id="%1$s-row" class="%2$s"%3$s>',
			esc_attr( $id ),
			esc_attr( implode( ' ', $classes ) ),
			isset( $spec['requires'] ) ? ' data-requires="' . esc_attr( (string) $spec['requires'] ) . '"' : ''
		);

		echo '<th scope="row">';

		// A checkbox labels itself beside the control, so the heading cell
		// carries a plain caption rather than a second <label> pointing at the
		// same input -- two labels for one control is a validation error and
		// reads as a duplicate to a screen reader.
		if ( 'checkbox' === $type ) {
			echo '<span class="recoveryflow-field__caption">' . esc_html( (string) $spec['label'] ) . '</span>';
		} else {
			printf( '<label for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( (string) $spec['label'] ) );
		}

		echo '</th><td>';

		if ( $locked ) {
			$this->locked_control( $key, $spec );
		} else {
			$this->control( $key, $spec, $id, $has_help ? $help_id : '' );
		}

		if ( $has_help ) {
			printf(
				'<p class="description" id="%1$s">%2$s</p>',
				esc_attr( $help_id ),
				esc_html( (string) $spec['help'] )
			);
		}

		echo '</td></tr>';
	}

	/**
	 * Render the control itself.
	 *
	 * @param string              $key     Setting key.
	 * @param array<string,mixed> $spec    Field spec.
	 * @param string              $id      Element id.
	 * @param string              $help_id Id of the description, or ''.
	 * @return void
	 */
	private function control( string $key, array $spec, string $id, string $help_id ): void {
		$type = (string) ( $spec['type'] ?? 'text' );
		$name = Options::SETTINGS . '[' . $key . ']';
		// A field may declare its own default, because a source registered by
		// somebody else's plugin cannot put one in Options::defaults(). Without
		// this a switch that is on renders as off, and the first save of the
		// tab it sits on then turns it off for real.
		$value     = $this->settings[ $key ] ?? ( $spec['default'] ?? '' );
		$described = '' === $help_id ? '' : ' aria-describedby="' . esc_attr( $help_id ) . '"';

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<label for="%1$s" class="recoveryflow-checkbox"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s%5$s /> <span>%6$s</span></label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					$described, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from an escaped id.
					isset( $spec['confirm'] ) ? ' data-confirm="' . esc_attr( (string) $spec['confirm'] ) . '"' : '',
					esc_html( (string) $spec['label'] )
				);
				break;

			case 'radio':
				$this->radio_group( $key, $spec, $id, $help_id, (string) $value );
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s"%3$s>', esc_attr( $id ), esc_attr( $name ), $described ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from an escaped id.

				foreach ( (array) ( $spec['options'] ?? array() ) as $option => $label ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( (string) $option ),
						selected( (string) $value, (string) $option, false ),
						esc_html( (string) $label )
					);
				}

				echo '</select>';
				break;

			case 'country':
				$this->country( $name, $id, $described, (string) $value );
				break;

			case 'address':
			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="%3$d" cols="50" class="large-text code"%4$s%5$s>%6$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) ( $spec['rows'] ?? 4 ),
					isset( $spec['maxlength'] ) ? ' maxlength="' . (int) $spec['maxlength'] . '"' : '',
					$described, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from an escaped id.
					esc_textarea( (string) $value )
				);
				break;

			case 'secret':
				$this->secret( $name, $id, $described, $spec );
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" class="small-text"%4$s%5$s%6$s%7$s /> %8$s',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					isset( $spec['min'] ) ? ' min="' . esc_attr( (string) $spec['min'] ) . '"' : '',
					isset( $spec['max'] ) ? ' max="' . esc_attr( (string) $spec['max'] ) . '"' : '',
					isset( $spec['step'] ) ? ' step="' . esc_attr( (string) $spec['step'] ) . '"' : '',
					$described, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from an escaped id.
					isset( $spec['unit'] ) ? '<span class="recoveryflow-field__unit">' . esc_html( (string) $spec['unit'] ) . '</span>' : ''
				);
				break;

			case 'time':
				printf(
					'<input type="time" id="%1$s" name="%2$s" value="%3$s" class="small-text" pattern="[0-9]{2}:[0-9]{2}"%4$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from an escaped id.
				);
				break;

			case 'url':
				printf(
					'<input type="url" id="%1$s" name="%2$s" value="%3$s" class="regular-text code"%4$s%5$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					isset( $spec['placeholder'] ) ? ' placeholder="' . esc_attr( (string) $spec['placeholder'] ) . '"' : '',
					$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from an escaped id.
				);
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text"%4$s%5$s%6$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					isset( $spec['maxlength'] ) ? ' maxlength="' . (int) $spec['maxlength'] . '"' : '',
					isset( $spec['placeholder'] ) ? ' placeholder="' . esc_attr( (string) $spec['placeholder'] ) . '"' : '',
					$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from an escaped id.
				);
		}//end switch
	}

	/**
	 * A set of radio buttons, grouped so they are announced as one question.
	 *
	 * A fieldset rather than a bare list: without one, a screen reader reads
	 * three unrelated options and never says what they are options for. The
	 * legend is visually hidden because the row's own heading cell already
	 * shows the question on screen.
	 *
	 * @param string              $key     Setting key.
	 * @param array<string,mixed> $spec    Field spec.
	 * @param string              $id      Element id.
	 * @param string              $help_id Id of the description, or ''.
	 * @param string              $value   Current value.
	 * @return void
	 */
	private function radio_group( string $key, array $spec, string $id, string $help_id, string $value ): void {
		$name = Options::SETTINGS . '[' . $key . ']';

		printf(
			'<fieldset%s><legend class="screen-reader-text">%s</legend>',
			'' === $help_id ? '' : ' aria-describedby="' . esc_attr( $help_id ) . '"',
			esc_html( (string) $spec['label'] )
		);

		$index = 0;

		foreach ( (array) ( $spec['options'] ?? array() ) as $option => $label ) {
			$option_id = $id . ( 0 === $index ? '' : '-' . $index );

			printf(
				'<label for="%1$s" class="recoveryflow-radio"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s /> <span>%5$s</span></label>',
				esc_attr( $option_id ),
				esc_attr( $name ),
				esc_attr( (string) $option ),
				checked( $value, (string) $option, false ),
				esc_html( (string) $label )
			);

			++$index;
		}

		echo '</fieldset>';
	}

	/**
	 * The country picker.
	 *
	 * A select of real country names when WooCommerce is present, because
	 * choosing "Ireland" from a list is a different task from remembering that
	 * Ireland is IE. Without WooCommerce there is no list to draw on, so the
	 * field falls back to the code itself rather than to a list this plugin
	 * would have to ship and then keep current as ISO 3166 changes.
	 *
	 * The names come from WooCommerce already translated. Nothing here
	 * translates them, and the stored value is the code either way.
	 *
	 * @param string $name      Field name.
	 * @param string $id        Element id.
	 * @param string $described aria-describedby attribute, pre-escaped.
	 * @param string $value     Current value.
	 * @return void
	 */
	private function country( string $name, string $id, string $described, string $value ): void {
		$countries = $this->country_list();

		if ( array() === $countries ) {
			printf(
				'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="small-text code" maxlength="2" size="2" pattern="[A-Za-z]{2}" autocomplete="country"%4$s />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( $value ),
				$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by the caller from an escaped id.
			);

			return;
		}

		printf( '<select id="%1$s" name="%2$s" autocomplete="country"%3$s>', esc_attr( $id ), esc_attr( $name ), $described ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by the caller from an escaped id.
		printf( '<option value="">%s</option>', esc_html__( 'Select a country', 'kdc-wacr-recoveryflow' ) );

		foreach ( $countries as $code => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $code ),
				selected( $value, (string) $code, false ),
				esc_html( (string) $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Countries to choose from, borrowed from WooCommerce when it is there.
	 *
	 * WC_Countries is built directly rather than reached through WC()->countries.
	 * The singleton is only populated once WooCommerce has finished loading, and
	 * a settings screen is not the place to depend on that having happened --
	 * whereas the class itself is loadable whenever WooCommerce is active, which
	 * is the only condition that actually matters here.
	 *
	 * @return array<string,string> Code => name.
	 */
	private function country_list(): array {
		if ( ! class_exists( 'WC_Countries' ) ) {
			return array();
		}

		return ( new \WC_Countries() )->get_countries();
	}

	/**
	 * A credential field.
	 *
	 * Never rendered with a value in it. A saved key is described rather than
	 * shown -- a masked hint of which key is stored, so somebody can tell one
	 * workspace from another without the page carrying the secret at all.
	 * Leaving the box empty on save keeps the stored key; that is why the
	 * placeholder says so rather than leaving it to be discovered.
	 *
	 * @param string              $name      Field name.
	 * @param string              $id        Element id.
	 * @param string              $described aria-describedby attribute, pre-escaped.
	 * @param array<string,mixed> $spec      Field spec.
	 * @return void
	 */
	private function secret( string $name, string $id, string $described, array $spec ): void {
		unset( $spec );

		printf(
			'<input type="password" id="%1$s" name="%2$s" value="" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="%3$s"%4$s />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr__( 'Leave empty to keep the saved key', 'kdc-wacr-recoveryflow' ),
			$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by the caller from an escaped id.
		);
	}

	/**
	 * A control the site's WA.cr plan does not reach.
	 *
	 * Shown disabled with the reason beside it rather than hidden. A setting
	 * that vanishes reads as a bug; a setting that is visibly unavailable, and
	 * says why, is information. The reason is text, not a colour or an icon.
	 *
	 * @param string              $key  Setting key.
	 * @param array<string,mixed> $spec Field spec.
	 * @return void
	 */
	private function locked_control( string $key, array $spec ): void {
		unset( $key );
		unset( $spec );

		printf(
			'<p class="recoveryflow-locked"><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Not available on this plan.', 'kdc-wacr-recoveryflow' ),
			esc_html( Feature_Gate::unavailable_reason() )
		);
	}

	/**
	 * Whether a field is behind a feature this workspace does not have.
	 *
	 * @param array<string,mixed> $spec Field spec.
	 * @return bool
	 */
	private function is_locked( array $spec ): bool {
		return isset( $spec['feature'] ) && ! Feature_Gate::is_enabled( (string) $spec['feature'] );
	}
}
