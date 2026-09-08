<?php
/**
 * Shown once an opt-out has been recorded.
 *
 * By the time this renders the suppression is already written to the consent
 * ledger and every journey still running for that person has been ended. The
 * page says so plainly, because somebody who has just asked to be left alone
 * should not have to wonder whether it worked.
 *
 * The suppression is keyed on hashes of the person's contact details rather
 * than on the customer record, so it survives an erasure request, and it covers
 * every identity they left -- their number and their address both. That is the
 * reason this page can promise what it promises, and the reason it promises it
 * without naming a channel.
 *
 * Override by copying this file to kdc-wacr-recoveryflow/opt-out-done.php in a
 * theme, or by filtering recoveryflow_template.
 *
 * Variables in scope:
 * - string $recoveryflow_site_name The shop's name.
 * - string $recoveryflow_home_url  The shop's front page.
 *
 * @package WAcr\RecoveryFlow
 */

defined( 'ABSPATH' ) || exit;

$recoveryflow_site_name = isset( $recoveryflow_site_name ) ? (string) $recoveryflow_site_name : '';
$recoveryflow_home_url  = isset( $recoveryflow_home_url ) ? (string) $recoveryflow_home_url : '';

$recoveryflow_title = __( 'You have been unsubscribed', 'kdc-wacr-recoveryflow' );
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
		:focus { outline: 3px solid #16202c; outline-offset: 3px; }
		:focus:not(:focus-visible) { outline: none; }
		:focus-visible { outline: 3px solid #16202c; outline-offset: 3px; }
		.rf-back { font-size: 0.9375rem; margin: 2rem 0 0; }
		@media (prefers-color-scheme: dark) {
			body { background: #0f151c; color: #eef2f6; }
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
					? __( 'You will not receive any more reminders about items left in your basket.', 'kdc-wacr-recoveryflow' )
					: sprintf(
						/* translators: %s: the shop's name. */
						__( 'You will not receive any more reminders from %s about items left in your basket.', 'kdc-wacr-recoveryflow' ),
						$recoveryflow_site_name
					)
			);
			?>
		</p>
		<p><?php esc_html_e( 'You can still shop as usual, and this does not affect order confirmations or replies to messages you send yourself.', 'kdc-wacr-recoveryflow' ); ?></p>
		<?php if ( '' !== $recoveryflow_home_url ) : ?>
			<p class="rf-back">
				<a href="<?php echo esc_url( $recoveryflow_home_url ); ?>">
					<?php
					echo esc_html(
						'' === $recoveryflow_site_name
							? __( 'Go to the shop', 'kdc-wacr-recoveryflow' )
							/* translators: %s: the shop's name. */
							: sprintf( __( 'Go to %s', 'kdc-wacr-recoveryflow' ), $recoveryflow_site_name )
					);
					?>
				</a>
			</p>
		<?php endif; ?>
	</main>
</body>
</html>
