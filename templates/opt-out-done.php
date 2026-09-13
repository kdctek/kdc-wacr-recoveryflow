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
	<?php wp_print_styles( 'recoveryflow-public' ); ?>
</head>
<body class="rf-page rf-page--done">
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
