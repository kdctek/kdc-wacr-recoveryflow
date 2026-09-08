<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests run without WordPress, using Brain Monkey to stand in for the
 * function calls the pure classes make. Integration and security tests need a
 * real WordPress, which wp-env provides.
 *
 * @package WAcr\RecoveryFlow
 */

$kdc_wacr_recoveryflow_root = dirname( __DIR__ );

if ( ! file_exists( $kdc_wacr_recoveryflow_root . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "Run composer install first.\n" );
	exit( 1 );
}

require_once $kdc_wacr_recoveryflow_root . '/vendor/autoload.php';

define( 'KDC_WACR_RECOVERYFLOW_TESTING', true );

$kdc_wacr_recoveryflow_suite = getenv( 'RECOVERYFLOW_SUITE' );

if ( false === $kdc_wacr_recoveryflow_suite || '' === $kdc_wacr_recoveryflow_suite ) {
	$kdc_wacr_recoveryflow_suite = 'unit';
}

if ( 'unit' === $kdc_wacr_recoveryflow_suite ) {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $kdc_wacr_recoveryflow_root . '/' );
	}

	require_once __DIR__ . '/phpstan-bootstrap.php';
	require_once $kdc_wacr_recoveryflow_root . '/src/Core/Autoloader.php';

	( new WAcr\RecoveryFlow\Core\Autoloader( $kdc_wacr_recoveryflow_root . '/src/' ) )->register();

	return;
}

$kdc_wacr_recoveryflow_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $kdc_wacr_recoveryflow_tests_dir || '' === $kdc_wacr_recoveryflow_tests_dir ) {
	$kdc_wacr_recoveryflow_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $kdc_wacr_recoveryflow_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $kdc_wacr_recoveryflow_root ): void {
		require $kdc_wacr_recoveryflow_root . '/kdc-wacr-recoveryflow.php';
	}
);

require $kdc_wacr_recoveryflow_tests_dir . '/includes/bootstrap.php';
