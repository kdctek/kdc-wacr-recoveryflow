<?php
/**
 * A source scan for translation mistakes a linter cannot see at runtime.
 *
 * PHPCS catches these too, but only when a contributor has run `composer
 * install`. This scan is plain PHP with no dependencies, so it runs in the
 * smoke suite on every supported PHP version and fails a pull request from a
 * machine that has never seen Composer. It is deliberately conservative: it
 * reports only what is certainly wrong, never what is merely suspicious.
 *
 * @package WAcr\RecoveryFlow
 */

const KDC_WACR_RECOVERYFLOW_TEXT_DOMAIN = 'kdc-wacr-recoveryflow';

/**
 * Where the text domain sits in each translation function's argument list.
 *
 * @var array<string,int>
 */
const KDC_WACR_RECOVERYFLOW_I18N_FUNCTIONS = array(
	'__'         => 1,
	'_e'         => 1,
	'esc_html__' => 1,
	'esc_html_e' => 1,
	'esc_attr__' => 1,
	'esc_attr_e' => 1,
	'translate'  => 1,
	'_x'         => 2,
	'_ex'        => 2,
	'esc_html_x' => 2,
	'esc_attr_x' => 2,
	'_n'         => 3,
	'_nx'        => 4,
);

/**
 * Every PHP file that ships to a merchant's site.
 *
 * tests/ and vendor/ are excluded by .distignore and are not held to this.
 *
 * @param string $root Plugin root.
 * @return string[]
 */
function kdc_wacr_recoveryflow_shipped_php( string $root ): array {
	$files = array( $root . '/kdc-wacr-recoveryflow.php', $root . '/uninstall.php' );

	foreach ( array( '/src', '/templates' ) as $dir ) {
		if ( ! is_dir( $root . $dir ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
	}

	sort( $files );

	return array_values( array_filter( $files, 'is_file' ) );
}

/**
 * Split a call's argument list into one token list per argument.
 *
 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
 * @param int                                           $open   Index of the opening parenthesis.
 * @return array<int,array<int,array{0:int,1:string,2:int}|string>>
 */
function kdc_wacr_recoveryflow_call_arguments( array $tokens, int $open ): array {
	$depth     = 0;
	$arguments = array();
	$current   = array();
	$count     = count( $tokens );

	for ( $i = $open; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( '(' === $token || '[' === $token ) {
			++$depth;

			if ( 1 === $depth ) {
				continue;
			}
		}

		if ( ')' === $token || ']' === $token ) {
			--$depth;

			if ( 0 === $depth ) {
				$arguments[] = $current;

				return $arguments;
			}
		}

		if ( ',' === $token && 1 === $depth ) {
			$arguments[] = $current;
			$current     = array();

			continue;
		}

		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$current[] = $token;
	}

	return $arguments;
}

/**
 * Report every translation call that a translator could not work with.
 *
 * Three mistakes are caught, all of which silently produce an untranslatable
 * string rather than an error:
 *
 * - a missing or misspelled text domain, which sends the string to a catalogue
 *   nobody translates;
 * - anything but a single literal string where the translatable text belongs --
 *   a variable, a concatenation, or a double-quoted string with a value
 *   interpolated into it -- all of which either leave the extractor nothing to
 *   find or put a site's own data inside the msgid.
 *
 * @param string $root Plugin root.
 * @return string[] Human-readable problems; empty when the plugin is clean.
 */
function kdc_wacr_recoveryflow_i18n_problems( string $root ): array {
	$problems = array();

	foreach ( kdc_wacr_recoveryflow_shipped_php( $root ) as $file ) {
		$tokens   = token_get_all( (string) file_get_contents( $file ) );
		$relative = ltrim( str_replace( $root, '', $file ), '/' );
		$count    = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}

			$name = $token[1];

			if ( ! isset( KDC_WACR_RECOVERYFLOW_I18N_FUNCTIONS[ $name ] ) ) {
				continue;
			}

			// A method or a class constant of the same name is not our call.
			$before = $i - 1;
			while ( $before >= 0 && is_array( $tokens[ $before ] ) && T_WHITESPACE === $tokens[ $before ][0] ) {
				--$before;
			}
			if ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				continue;
			}

			$open = $i + 1;
			while ( $open < $count && is_array( $tokens[ $open ] ) && T_WHITESPACE === $tokens[ $open ][0] ) {
				++$open;
			}
			if ( $open >= $count || '(' !== $tokens[ $open ] ) {
				continue;
			}

			$line      = (int) $token[2];
			$arguments = kdc_wacr_recoveryflow_call_arguments( $tokens, $open );
			$position  = KDC_WACR_RECOVERYFLOW_I18N_FUNCTIONS[ $name ];

			if ( ! isset( $arguments[ $position ] ) || array() === $arguments[ $position ] ) {
				$problems[] = "{$relative}:{$line} {$name}() is missing its text domain.";

				continue;
			}

			$domain = $arguments[ $position ];

			if ( 1 !== count( $domain )
				|| ! is_array( $domain[0] )
				|| T_CONSTANT_ENCAPSED_STRING !== $domain[0][0]
				|| KDC_WACR_RECOVERYFLOW_TEXT_DOMAIN !== trim( $domain[0][1], "'\"" ) ) {
				$problems[] = "{$relative}:{$line} {$name}() must pass the literal text domain '" . KDC_WACR_RECOVERYFLOW_TEXT_DOMAIN . "'.";
			}

			// Every argument before the text domain is a translatable string
			// or, for _n()/_nx(), the count. Only the strings are checked.
			$strings = '_n' === $name || '_nx' === $name ? array( 0, 1 ) : range( 0, $position - 1 );

			foreach ( $strings as $index ) {
				if ( ! isset( $arguments[ $index ] ) || array() === $arguments[ $index ] ) {
					continue;
				}

				$argument = $arguments[ $index ];

				if ( 1 === count( $argument ) && is_array( $argument[0] ) && T_CONSTANT_ENCAPSED_STRING === $argument[0][0] ) {
					continue;
				}

				$problems[] = "{$relative}:{$line} {$name}() argument " . ( $index + 1 ) . ' must be a single literal string, so the extractor can find it -- not a variable, a concatenation, or a double-quoted string with something interpolated into it.';
			}
		}
	}

	return $problems;
}

/*
 * Runnable on its own as well as from the smoke suite, so a contributor
 * working on one branch can check their strings without running everything:
 *
 *   php tests/i18n-audit.php            scan this plugin
 *   php tests/i18n-audit.php /some/path scan another checkout
 */
if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$scan_root = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__ );
	$found     = kdc_wacr_recoveryflow_i18n_problems( $scan_root );
	$scanned   = count( kdc_wacr_recoveryflow_shipped_php( $scan_root ) );

	if ( array() === $found ) {
		echo "Every translatable string in {$scanned} shipped file(s) is correctly domained and extractable.\n";

		exit( 0 );
	}

	echo count( $found ) . " problem(s) in {$scan_root}:\n";

	foreach ( $found as $problem ) {
		echo "  {$problem}\n";
	}

	exit( 1 );
}
