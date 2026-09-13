<?php
/**
 * The page a recovery message's opt-out link leads to.
 *
 * This page changes nothing, and that is the whole design. WhatsApp fetches
 * every URL in a message to build its preview card, once per delivered message,
 * so an opt-out that acted on a GET would unsubscribe every recipient of a
 * campaign the moment it was delivered. The opt-out happens only when this form
 * is submitted, which no preview fetcher will ever do.
 *
 * That argument gets stronger on email, not weaker. Gmail's image proxy and
 * Outlook's SafeLinks fetch what a message links to far more eagerly than
 * WhatsApp does, and some of them follow redirects and prefetch on hover.
 *
 * No channel is named anywhere on the page. Confirming here silences every way
 * the shop has of sending these reminders, not the one the message arrived by,
 * so naming WhatsApp would have promised less than the button actually does --
 * and would have been simply wrong on an email.
 *
 * The credential is the token already in the URL -- 256 bits of randomness,
 * sent to one person, for one message. The form adds a nonce on top of it,
 * minted here and checked on submission. If that nonce has aged out the
 * controller renders this page again with a fresh one rather than refusing:
 * the recipient has no WordPress session to carry a longer-lived one, and an
 * unsubscribe that can answer "no" is not an unsubscribe.
 *
 * Nothing on this page identifies anybody. The shop's name is the only thing
 * named, because the page is read on a phone that may be handed around and its
 * URL is read in full by every preview fetcher that touches the message.
 *
 * Override by copying this file to kdc-wacr-recoveryflow/opt-out-confirm.php
 * in a theme, or by filtering recoveryflow_template.
 *
 * Variables in scope:
 * - string $recoveryflow_site_name    The shop's name.
 * - string $recoveryflow_form_action  Where the form posts: this same link.
 * - string $recoveryflow_home_url     The shop's front page.
 * - string $recoveryflow_nonce_action Nonce action for the confirmation.
 * - string $recoveryflow_nonce_field  Field name the nonce travels in.
 *
 * @package WAcr\RecoveryFlow
 */

defined( 'ABSPATH' ) || exit;

$recoveryflow_site_name    = isset( $recoveryflow_site_name ) ? (string) $recoveryflow_site_name : '';
$recoveryflow_form_action  = isset( $recoveryflow_form_action ) ? (string) $recoveryflow_form_action : '';
$recoveryflow_home_url     = isset( $recoveryflow_home_url ) ? (string) $recoveryflow_home_url : '';
$recoveryflow_nonce_action = isset( $recoveryflow_nonce_action ) ? (string) $recoveryflow_nonce_action : 'recoveryflow_opt_out';
$recoveryflow_nonce_field  = isset( $recoveryflow_nonce_field ) ? (string) $recoveryflow_nonce_field : '_rf_nonce';

$recoveryflow_title = '' === $recoveryflow_site_name
	? __( 'Stop these reminders?', 'kdc-wacr-recoveryflow' )
	/* translators: %s: the shop's name. */
	: sprintf( __( 'Stop reminders from %s?', 'kdc-wacr-recoveryflow' ), $recoveryflow_site_name );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta name="referrer" content="no-referrer">
	<title><?php echo esc_html( $recoveryflow_title ); ?></title>
	<?php wp_print_styles( 'recoveryflow-public' ); ?>
</head>
<body class="rf-page rf-page--confirm">
	<main>
		<h1><?php echo esc_html( $recoveryflow_title ); ?></h1>
		<p>
			<?php
			echo esc_html(
				'' === $recoveryflow_site_name
					? __( 'These are the reminders you receive when you leave something behind in your basket.', 'kdc-wacr-recoveryflow' )
					: sprintf(
						/* translators: %s: the shop's name. */
						__( 'These are the reminders %s sends you when you leave something behind in your basket.', 'kdc-wacr-recoveryflow' ),
						$recoveryflow_site_name
					)
			);
			?>
		</p>
		<p><?php esc_html_e( 'Nothing has changed yet. Confirm below and the reminders will stop.', 'kdc-wacr-recoveryflow' ); ?></p>
		<form method="post" action="<?php echo esc_url( $recoveryflow_form_action ); ?>">
			<?php wp_nonce_field( $recoveryflow_nonce_action, $recoveryflow_nonce_field, false, true ); ?>
			<input type="hidden" name="rf_confirm" value="1">
			<button type="submit" class="rf-button">
				<?php esc_html_e( 'Yes, stop sending me these reminders', 'kdc-wacr-recoveryflow' ); ?>
			</button>
		</form>
		<?php if ( '' !== $recoveryflow_home_url ) : ?>
			<p class="rf-back">
				<a href="<?php echo esc_url( $recoveryflow_home_url ); ?>">
					<?php esc_html_e( 'No, keep sending them and take me back to the shop', 'kdc-wacr-recoveryflow' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</main>
</body>
</html>
