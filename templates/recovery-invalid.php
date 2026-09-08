<?php
/**
 * The one page every recovery-link failure renders.
 *
 * Unknown, expired, revoked, already finished and rate-limited all arrive here
 * with the same wording. Saying which it was would tell a stranger holding a
 * guessed token whether a link had ever been real, and therefore whether a
 * number is enrolled. The status code differs (404, or 429 when somebody is
 * asking too often) because a robot needs to know to slow down; the page a
 * person reads does not change.
 *
 * Override by copying this file to kdc-wacr-recoveryflow/recovery-invalid.php
 * in a theme, or by filtering recoveryflow_template.
 *
 * Variables in scope:
 * - string $recoveryflow_heading   Page heading.
 * - string $recoveryflow_message   Body text.
 * - string $recoveryflow_site_name The shop's name.
 * - string $recoveryflow_home_url  The shop's front page.
 *
 * @package WAcr\RecoveryFlow
 */

defined( 'ABSPATH' ) || exit;

$recoveryflow_heading   = isset( $recoveryflow_heading ) ? (string) $recoveryflow_heading : '';
$recoveryflow_message   = isset( $recoveryflow_message ) ? (string) $recoveryflow_message : '';
$recoveryflow_site_name = isset( $recoveryflow_site_name ) ? (string) $recoveryflow_site_name : '';
$recoveryflow_home_url  = isset( $recoveryflow_home_url ) ? (string) $recoveryflow_home_url : '';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta name="referrer" content="no-referrer">
	<title><?php echo esc_html( $recoveryflow_heading ); ?></title>
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
		.rf-site { font-size: 0.9375rem; margin: 2rem 0 0; }
		@media (prefers-color-scheme: dark) {
			body { background: #0f151c; color: #eef2f6; }
			:focus, :focus-visible { outline-color: #eef2f6; }
		}
	</style>
</head>
<body>
	<main>
		<h1><?php echo esc_html( $recoveryflow_heading ); ?></h1>
		<p><?php echo esc_html( $recoveryflow_message ); ?></p>
		<?php if ( '' !== $recoveryflow_home_url ) : ?>
			<p class="rf-site">
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
