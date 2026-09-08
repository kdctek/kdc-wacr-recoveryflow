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
 * The form carries no nonce on purpose: the recipient is not logged in and has
 * no WordPress session to carry one. The token already in the URL is the
 * credential -- 256 bits of randomness, sent to one person, for one message.
 *
 * Nothing on this page identifies anybody. The shop's name is the only thing
 * named, because the page is read on a phone that may be handed around and its
 * URL is read in full by every preview fetcher that touches the message.
 *
 * Override by copying this file to kdc-wacr-recoveryflow/opt-out-confirm.php
 * in a theme, or by filtering recoveryflow_template.
 *
 * Variables in scope:
 * - string $recoveryflow_site_name   The shop's name.
 * - string $recoveryflow_form_action Where the form posts: this same link.
 * - string $recoveryflow_home_url    The shop's front page.
 *
 * @package WAcr\RecoveryFlow
 */

defined( 'ABSPATH' ) || exit;

$recoveryflow_site_name   = isset( $recoveryflow_site_name ) ? (string) $recoveryflow_site_name : '';
$recoveryflow_form_action = isset( $recoveryflow_form_action ) ? (string) $recoveryflow_form_action : '';
$recoveryflow_home_url    = isset( $recoveryflow_home_url ) ? (string) $recoveryflow_home_url : '';

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
	<style>
		:root { color-scheme: light dark; }
		body {
			margin: 0;
			padding: 3rem 1.25rem;
			background: #ffffff;
			color: #16202c;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
			font-size: 1.0625rem;
			line-height: 1.65;
		}
		main { max-width: 34rem; margin: 0 auto; }
		h1 { font-size: 1.5rem; line-height: 1.3; margin: 0 0 1rem; }
		p { margin: 0 0 1rem; }
		a { color: inherit; text-decoration: underline; text-underline-offset: 0.2em; }
		a:hover { text-decoration-thickness: 0.16em; }
		form { margin: 1.75rem 0 0; }
		.rf-button {
			display: inline-block;
			min-height: 2.75rem;
			padding: 0.7rem 1.35rem;
			border: 2px solid #16202c;
			border-radius: 4px;
			background: #16202c;
			color: #ffffff;
			font: inherit;
			font-weight: 600;
			cursor: pointer;
		}
		.rf-button:hover { background: #2b3a4d; border-color: #2b3a4d; }
		:focus { outline: 3px solid #16202c; outline-offset: 3px; }
		:focus:not(:focus-visible) { outline: none; }
		:focus-visible { outline: 3px solid #16202c; outline-offset: 3px; }
		.rf-back { font-size: 0.9375rem; margin: 1.5rem 0 0; }
		@media (prefers-color-scheme: dark) {
			body { background: #0f151c; color: #eef2f6; }
			.rf-button { background: #eef2f6; border-color: #eef2f6; color: #0f151c; }
			.rf-button:hover { background: #c9d4de; border-color: #c9d4de; }
			:focus, :focus-visible { outline-color: #eef2f6; }
		}
	</style>
</head>
<body>
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
