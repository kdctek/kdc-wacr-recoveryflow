<?php
/**
 * What the distributable zip must contain, and what it must not.
 *
 * Run with: php tests/dist-manifest.php
 *
 * Two things are checked here, and both are things that no other gate can see
 * and that fail silently on a customer's site rather than in CI.
 *
 * **The contents of the zip.** `.distignore` decides what ships, and it is a
 * deny-list: everything not named in it is included. That is the right default
 * for a plugin, and it means a NEW top-level directory ships by accident while a
 * newly-ignored one disappears silently. Both have nearly happened here.
 * `assets/` was added for the checkout script and is deliberately absent from
 * `.distignore` -- `tests/`, `docs/` and `bin/` are all excluded there, so
 * adding a directory without checking is a way to ship a plugin whose script is
 * simply missing, and the plugin still activates, still runs, and only fails at
 * the checkout. So the manifest is asserted in both directions: every path the
 * plugin needs at runtime must be present, and every development path must be
 * absent.
 *
 * **The version, in every place that carries one.** Six files state it. A
 * release where the header says one thing and `readme.txt`'s stable tag says
 * another is a release WordPress.org serves incorrectly, or refuses; and one
 * where the constant disagrees with the header is a plugin whose upgrade routine
 * does not run. None of that is visible from reading any single file.
 *
 * @package WAcr\RecoveryFlow
 */

declare( strict_types = 1 );

$root   = dirname( __DIR__ );
$failed = 0;
$passed = 0;

/**
 * Assert one thing.
 *
 * @param string $label What is being checked.
 * @param bool   $value Whether it holds.
 * @return void
 */
function dist_ok( string $label, bool $value ): void {
	global $failed, $passed;

	if ( $value ) {
		++$passed;

		return;
	}

	++$failed;

	echo "FAIL  {$label}\n";
}

/**
 * Assert two things are equal, and say what they were when they are not.
 *
 * @param string $label    What is being checked.
 * @param mixed  $actual   What was found.
 * @param mixed  $expected What was wanted.
 * @return void
 */
function dist_is( string $label, $actual, $expected ): void {
	global $failed, $passed;

	if ( $actual === $expected ) {
		++$passed;

		return;
	}

	++$failed;

	echo "FAIL  {$label}\n";
	echo '      expected: ' . var_export( $expected, true ) . "\n";
	echo '      actual:   ' . var_export( $actual, true ) . "\n";
}

// ---------------------------------------------------------------------------
// The manifest.
// ---------------------------------------------------------------------------

$listing = array();
$status  = 0;

exec( 'bash ' . escapeshellarg( $root . '/bin/build.sh' ) . ' --check 2>/dev/null', $listing, $status );

dist_is( 'the build script runs', $status, 0 );
dist_ok( 'and produces a listing', count( $listing ) > 50 );

$files = array();

foreach ( $listing as $line ) {
	$line = trim( $line );

	if ( '' !== $line && 0 === strpos( $line, 'kdc-wacr-recoveryflow/' ) ) {
		$files[] = substr( $line, strlen( 'kdc-wacr-recoveryflow/' ) );
	}
}

dist_ok( 'every path sits under one folder named for the slug', count( $files ) === count( $listing ) );

/*
 * Present. Each of these is something the plugin reads at runtime, and each
 * would fail in a different, quiet way if it were missing: no main file and
 * WordPress sees no plugin at all; no src and it fatals on activation; no
 * assets and the checkout silently stops capturing; no languages and every
 * locale falls back to English with nothing to say why; no templates and the
 * public opt-out page is a fatal; no readme.txt and WordPress.org rejects the
 * submission; no LICENSE and the GPL claim in the header is unsupported.
 */
$required = array(
	'kdc-wacr-recoveryflow.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'src/Core/Plugin.php',
	'src/Core/Autoloader.php',
	'assets/js/checkout-capture.js',
	'assets/js/admin.js',
	'assets/css/admin.css',
	'languages/kdc-wacr-recoveryflow.pot',
	'templates/opt-out-confirm.php',
	'templates/opt-out-done.php',
	'templates/recovery-invalid.php',
);

foreach ( $required as $path ) {
	dist_ok( "the zip carries {$path}", in_array( $path, $files, true ) );
}

/*
 * Absent. Development files on a customer's server are not a tidiness problem:
 * tests/perf/seed.php writes a hundred thousand rows of invented customers,
 * composer.json invites somebody to run composer on a production host, and
 * .wp-env.json and the CI workflow describe infrastructure that is nobody
 * else's business.
 */
$forbidden_prefixes = array( 'tests/', 'docs/', 'bin/', 'vendor/', 'node_modules/', '.github/', '.wordpress-org/', 'dist/' );
$forbidden_exact    = array(
	'composer.json',
	'composer.lock',
	'package.json',
	'package-lock.json',
	'phpcs.xml.dist',
	'phpunit.xml.dist',
	'phpstan.neon.dist',
	'.wp-env.json',
	'.pa11yci.json',
	'.distignore',
	'.editorconfig',
	'CHANGELOG.md',
	'README.md',
	'LOCAL-DEV.md',
);

foreach ( $forbidden_prefixes as $prefix ) {
	$found = array_filter( $files, static fn ( string $f ): bool => 0 === strpos( $f, $prefix ) );

	dist_is( "the zip carries nothing under {$prefix}", array_values( $found ), array() );
}

foreach ( $forbidden_exact as $path ) {
	dist_ok( "the zip does not carry {$path}", ! in_array( $path, $files, true ) );
}

// The benchmarking harness by name, because it is the one whose presence would
// be actively dangerous rather than merely untidy.
dist_ok(
	'the benchmarking harness never ships',
	array() === array_filter( $files, static fn ( string $f ): bool => false !== strpos( $f, 'seed.php' ) )
);

// ---------------------------------------------------------------------------
// The version, everywhere it is written down.
// ---------------------------------------------------------------------------

$main = (string) file_get_contents( $root . '/kdc-wacr-recoveryflow.php' );

preg_match( '/^ \* Version:\s*(.+)$/m', $main, $header );
$version = isset( $header[1] ) ? trim( $header[1] ) : '';

dist_ok( 'the plugin header states a version', '' !== $version );
dist_ok( 'and it looks like a version', 1 === preg_match( '/^\d+\.\d+\.\d+$/', $version ) );

preg_match( "/define\(\s*'KDC_WACR_RECOVERYFLOW_VERSION',\s*'([^']+)'/", $main, $constant );

dist_is(
	'the version constant agrees with the header, or the upgrade routine never runs',
	isset( $constant[1] ) ? $constant[1] : '',
	$version
);

$readme = (string) file_get_contents( $root . '/readme.txt' );

preg_match( '/^Stable tag:\s*(.+)$/m', $readme, $stable );

dist_is(
	'readme.txt\'s stable tag agrees with the header, or WordPress.org serves the wrong release',
	isset( $stable[1] ) ? trim( $stable[1] ) : '',
	$version
);

preg_match( '/^Requires at least:\s*(.+)$/m', $readme, $readme_wp );
preg_match( '/^ \* Requires at least:\s*(.+)$/m', $main, $header_wp );

dist_is(
	'readme.txt and the header agree on the WordPress version required',
	isset( $readme_wp[1] ) ? trim( $readme_wp[1] ) : 'a',
	isset( $header_wp[1] ) ? trim( $header_wp[1] ) : 'b'
);

preg_match( '/^Requires PHP:\s*(.+)$/m', $readme, $readme_php );
preg_match( '/^ \* Requires PHP:\s*(.+)$/m', $main, $header_php );

dist_is(
	'readme.txt and the header agree on the PHP version required',
	isset( $readme_php[1] ) ? trim( $readme_php[1] ) : 'a',
	isset( $header_php[1] ) ? trim( $header_php[1] ) : 'b'
);

// The changelog has to name this release. A version nobody wrote an entry for
// is a version whose users cannot find out what changed.
$changelog = (string) file_get_contents( $root . '/CHANGELOG.md' );

dist_ok(
	'CHANGELOG.md has an entry for this version',
	false !== strpos( $changelog, '## [' . $version . ']' )
);

dist_ok(
	'readme.txt\'s changelog names this version too',
	false !== strpos( $readme, '= ' . $version . ' =' )
);

// The two test bootstraps define the constant so that PHPStan and the smoke
// suite can run without WordPress. They drift silently, and a stale one there
// means the suite is asserting against a version that was never released.
foreach ( array( 'tests/wp-stubs.php', 'tests/phpstan-bootstrap.php' ) as $bootstrap ) {
	$source = (string) file_get_contents( $root . '/' . $bootstrap );

	preg_match( "/KDC_WACR_RECOVERYFLOW_VERSION',\s*'([^']+)'/", $source, $found );

	dist_is( "{$bootstrap} defines the current version", isset( $found[1] ) ? $found[1] : '', $version );
}

// The .pot's Project-Id-Version carries it as well, and a translator reading a
// stale one cannot tell which release their strings belong to.
$pot = (string) file_get_contents( $root . '/languages/kdc-wacr-recoveryflow.pot' );

dist_ok(
	'the .pot names this version',
	false !== strpos( $pot, 'KDC WAcr RecoveryFlow ' . $version )
);

echo "\n";
echo $failed > 0 ? "FAILED\n" : "PASSED\n";
echo "{$passed} passed, {$failed} failed\n";

exit( $failed > 0 ? 1 : 0 );
