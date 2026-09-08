<?php
/**
 * The "Run now" button on the system status screen.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Jobs\Stage_Label;
use WAcr\RecoveryFlow\Jobs\Stage_Runner;
use WAcr\RecoveryFlow\Jobs\Stage_Stats;
use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Running the background passes in the foreground, once, on purpose.
 *
 * "It has stopped sending" is the support call this plugin will get most, and
 * until now the only honest way to answer it was WP-CLI. A merchant on shared
 * hosting has no shell, so the answer was to wait an hour for the scheduler and
 * see -- which is not an answer, and which cannot distinguish a scheduler that
 * never fires from a queue with nothing eligible in it. This runs the real
 * passes, in the real order, under the real locks and the real time budget,
 * and then says what each of them did.
 *
 * **It is the same code the scheduler runs, not a copy of it.** A "run now"
 * that reimplemented the tick would be a second set of rules about what a pass
 * does, and the one nobody exercised would be the one that disagreed -- so a
 * button whose whole purpose is diagnosing the real behaviour would be
 * diagnosing something else. It calls Stage_Runner::run_tick(), which is what
 * WP-Cron calls and what `wp recoveryflow tick` calls.
 *
 * **It can send real reminders, and it says so before it is pressed.** The
 * dispatch pass is one of the five, so this spends money and reaches real
 * people. That is stated next to the button rather than discovered afterwards,
 * the same way the Auto Flow test push states that it really runs your flow.
 * There is no confirmation dialog. A dialog that only appears when a script
 * loads is not a safeguard, and this screen carries the admin enhancement
 * script like every other one -- so the warning is in the markup, where a
 * blocked script cannot take it away.
 *
 * **The capability is MANAGE_JOURNEYS, not VIEW_STATUS.** The screen it sits on
 * only needs the right to read a diagnosis; this button acts, and what it acts
 * on is somebody's messages and somebody's bill. Being allowed to read the
 * status of a shop is not the same as being allowed to make it send.
 *
 * **A pass that could not take its lock is reported as skipped, not as idle.**
 * Stage_Runner hands back stats marked `locked` when another run holds the
 * lock, which is ordinary on a busy site. Printing that as "handled 0" would
 * read as "there was nothing to do" -- the opposite diagnosis, on exactly the
 * screen somebody is using to work out why nothing is happening.
 */
final class Run_Now {

	/**
	 * The admin-post action name.
	 */
	public const ACTION = 'recoveryflow_run_now';

	/**
	 * How long the result waits to be shown, in seconds.
	 *
	 * Long enough to survive the redirect, short enough that yesterday's run
	 * cannot reappear on a screen opened much later.
	 */
	private const RESULT_TTL = 120;

	/**
	 * The stage runner.
	 *
	 * @var Stage_Runner
	 */
	private Stage_Runner $runner;

	/**
	 * Constructor.
	 *
	 * @param Stage_Runner $runner The stage runner.
	 */
	public function __construct( Stage_Runner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Run the passes, remember what they did, and go back to the screen.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE_JOURNEYS ) ) {
			wp_die(
				esc_html__( 'You are not allowed to run RecoveryFlow\'s background passes on this site.', 'kdc-wacr-recoveryflow' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		set_transient( self::transient_key(), $this->run(), self::RESULT_TTL );

		wp_safe_redirect( Screen::url( Screen::STATUS ) );

		exit;
	}

	/**
	 * Run the passes and describe what each one did.
	 *
	 * Separated from handle() so the decision can be tested without a redirect
	 * and an exit in the way. A method that decides, writes a transient and
	 * leaves the request in one breath can have its answer changed for a worse
	 * one with every assertion still green.
	 *
	 * @return array{ok:bool,message:string,rows:array<int,array{pass:string,handled:int,failed:int,note:string}>}
	 */
	public function run(): array {
		return self::describe( $this->runner->run_tick() );
	}

	/**
	 * Turn what the passes reported into what the screen will say.
	 *
	 * Separate from run() because this is the decision, and running the passes
	 * is the side effect. Asked of a set of stats directly, every branch here
	 * can be exercised -- including the one for a pass that could not take its
	 * lock, which is close to unreachable through the real runner and is
	 * precisely the branch whose wording matters most.
	 *
	 * @param array<string,Stage_Stats> $stats What each pass that ran reported.
	 * @return array{ok:bool,message:string,rows:array<int,array{pass:string,handled:int,failed:int,note:string}>}
	 */
	public static function describe( array $stats ): array {
		if ( array() === $stats ) {
			return array(
				'ok'      => false,
				'message' => __( 'No background passes are registered, so nothing ran. This is a fault rather than an empty queue; the diagnostic report below is worth sending to support.', 'kdc-wacr-recoveryflow' ),
				'rows'    => array(),
			);
		}

		$rows     = array();
		$handled  = 0;
		$failed   = 0;
		$locked   = 0;
		$run_time = 0.0;

		foreach ( $stats as $key => $stat ) {
			$was_locked = 'locked' === $stat->last_error;

			$rows[] = array(
				'pass'    => (string) $key,
				'handled' => $stat->processed,
				'failed'  => $stat->failed,
				'note'    => self::note( $stat, $was_locked ),
			);

			$handled  += $stat->processed;
			$failed   += $stat->failed;
			$run_time += $stat->duration;

			if ( $was_locked ) {
				++$locked;
			}
		}

		return array(
			'ok'      => 0 === $failed,
			'message' => self::summary( count( $rows ), $handled, $failed, $locked, $run_time ),
			'rows'    => $rows,
		);
	}

	/**
	 * What one pass has to say for itself beyond its counts.
	 *
	 * @param Stage_Stats $stat   What the pass reported.
	 * @param bool        $locked Whether it could not take its lock.
	 * @return string
	 */
	private static function note( Stage_Stats $stat, bool $locked ): string {
		if ( $locked ) {
			return __( 'Skipped: another run was already working on this pass. That is ordinary on a busy shop.', 'kdc-wacr-recoveryflow' );
		}

		if ( '' !== $stat->last_error ) {
			return __( 'This pass stopped on an error. The details are in the log.', 'kdc-wacr-recoveryflow' );
		}

		if ( $stat->backlog > 0 ) {
			return __( 'There is more to do than fitted in one run. The rest has been queued and will carry on.', 'kdc-wacr-recoveryflow' );
		}

		return __( 'Finished with nothing left over.', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * The one-line verdict above the table.
	 *
	 * @param int   $passes  How many passes ran.
	 * @param int   $handled Rows handled across all of them.
	 * @param int   $failed  Rows that failed across all of them.
	 * @param int   $locked  Passes that could not take their lock.
	 * @param float $seconds How long the whole thing took.
	 * @return string
	 */
	private static function summary( int $passes, int $handled, int $failed, int $locked, float $seconds ): string {
		$parts = array();

		// Three separate sentences rather than one with three placeholders in
		// it: the count of passes and the count of records pluralise
		// independently, and a single string keyed on one of them gets the
		// other wrong in every language that declines them differently.
		$parts[] = sprintf(
			/* translators: %d: number of background passes that ran. */
			_n( '%d pass ran.', '%d passes ran.', $passes, 'kdc-wacr-recoveryflow' ),
			$passes
		);

		$parts[] = sprintf(
			/* translators: %d: number of records handled. */
			_n( 'It handled %d record.', 'It handled %d records.', $handled, 'kdc-wacr-recoveryflow' ),
			$handled
		);

		$parts[] = sprintf(
			/* translators: %s: how long the run took, in seconds, already formatted. */
			__( 'It took %s seconds.', 'kdc-wacr-recoveryflow' ),
			number_format_i18n( $seconds, 2 )
		);

		if ( $failed > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of records that failed. */
				_n(
					'%d record failed; the log says why.',
					'%d records failed; the log says why.',
					$failed,
					'kdc-wacr-recoveryflow'
				),
				$failed
			);
		}

		if ( $locked > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of passes that were already running. */
				_n(
					'%d pass was skipped because it was already running.',
					'%d passes were skipped because they were already running.',
					$locked,
					'kdc-wacr-recoveryflow'
				),
				$locked
			);
		}

		if ( 0 === $handled && 0 === $failed && 0 === $locked ) {
			$parts[] = __( 'Nothing was waiting. The passes are working; there was simply no eligible recovery to act on.', 'kdc-wacr-recoveryflow' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * The button, for the status screen to place.
	 *
	 * Renders a sentence instead of the button for somebody who may read the
	 * status screen but not act on it. A control that is silently absent leaves
	 * them comparing their screen with a colleague's and no way to tell whether
	 * the button is missing or they are.
	 *
	 * @return void
	 */
	public static function button(): void {
		printf( '<h2>%s</h2>', esc_html__( 'Run the passes now', 'kdc-wacr-recoveryflow' ) );

		if ( ! current_user_can( Capabilities::MANAGE_JOURNEYS ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Running the passes by hand needs the "recoveryflow_manage_journeys" permission, which this account does not have. It can send reminders to real customers, which is why reading this screen and running them are separate permissions.', 'kdc-wacr-recoveryflow' )
			);

			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Runs all five passes once, in the foreground, using exactly the code the scheduler uses. This is the way to answer "has it stopped working" without waiting for the next scheduled run.', 'kdc-wacr-recoveryflow' )
		);

		printf(
			'<p class="description"><strong>%s</strong> %s</p>',
			esc_html__( 'This really sends.', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'The dispatch pass is one of the five, so any recovery that is due right now will be messaged, and your WA.cr account will be billed for it. Nothing that is not already due is brought forward.', 'kdc-wacr-recoveryflow' )
		);

		printf(
			'<form method="post" action="%1$s" class="recoveryflow-run-now">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		Nonce_Field::render( self::ACTION );

		printf(
			'<input type="hidden" name="action" value="%1$s" />
			<button type="submit" class="button">%2$s</button>
			</form>',
			esc_attr( self::ACTION ),
			esc_html__( 'Run the passes now', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * Show what the last run did, once.
	 *
	 * Rendered with role="status" so a screen reader hears the outcome after
	 * the page loads, without the focus being taken from somebody who had
	 * already started reading.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$result = get_transient( self::transient_key() );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( self::transient_key() );

		printf(
			'<div class="notice %1$s" role="status"><p><strong>%2$s</strong> %3$s</p>',
			esc_attr( empty( $result['ok'] ) ? 'notice-warning' : 'notice-success' ),
			esc_html__( 'The passes ran.', 'kdc-wacr-recoveryflow' ),
			esc_html( isset( $result['message'] ) ? (string) $result['message'] : '' )
		);

		$rows = isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array();

		if ( array() !== $rows ) {
			echo '<table class="widefat striped recoveryflow-run-now-result"><thead><tr>';
			printf( '<th scope="col">%s</th>', esc_html__( 'Pass', 'kdc-wacr-recoveryflow' ) );
			printf( '<th scope="col">%s</th>', esc_html__( 'Handled', 'kdc-wacr-recoveryflow' ) );
			printf( '<th scope="col">%s</th>', esc_html__( 'Failed', 'kdc-wacr-recoveryflow' ) );
			printf( '<th scope="col">%s</th>', esc_html__( 'What happened', 'kdc-wacr-recoveryflow' ) );
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				printf(
					'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
					esc_html( Stage_Label::for_stage( (string) ( $row['pass'] ?? '' ) ) ),
					esc_html( number_format_i18n( (int) ( $row['handled'] ?? 0 ) ) ),
					esc_html( number_format_i18n( (int) ( $row['failed'] ?? 0 ) ) ),
					esc_html( (string) ( $row['note'] ?? '' ) )
				);
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * Where one user's pending result lives.
	 *
	 * Scoped to the user, so two people pressing this at the same moment do not
	 * read each other's answer.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return 'recoveryflow_run_now_' . get_current_user_id();
	}
}
