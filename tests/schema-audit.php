<?php
/**
 * Assert that every column a repository reads or writes is a column the schema defines.
 *
 * The smoke suite checks the DDL as text -- it greps the CREATE TABLE
 * statements for the column and index names it expects to find. That proves
 * the schema says what it meant to say. It does not prove that the code agrees
 * with it, because a column name in a repository is a string key in an array,
 * not a type, so PHPStan cannot see it either.
 *
 * The gap is not hypothetical. A migration that drops or renames a column
 * leaves every insert, update and WHERE clause still naming the old one, and
 * the whole suite stays green: the failure is a runtime SQL error on the first
 * checkout after release, silent until then.
 *
 * This is deliberately crude. It reads column names out of the insert(),
 * insert_ignore() and update() array literals, out of find_one_by(), and out
 * of unqualified comparisons in hand-written SQL, and asserts each one is a
 * column of the table the repository binds itself to. It cannot follow a
 * column name held in a variable, and it does not resolve JOIN aliases. What
 * it does catch is the mistake that actually happens: the schema moved and the
 * code did not.
 *
 * @package WAcr\RecoveryFlow
 */

$root = $argv[1] ?? dirname( __DIR__ );

/* ---------- 1. Column names per table, from the DDL ---------- */

$schema_src = file_get_contents( "{$root}/src/Database/Schema.php" );
$tables     = array();
if ( preg_match_all( '/CREATE TABLE \{\$p\}([a-z_]+) \((.*?)\n\t\t\) \{\$charset_collate\};/s', $schema_src, $m, PREG_SET_ORDER ) ) {
	foreach ( $m as $t ) {
		$cols = array();
		foreach ( explode( "\n", $t[2] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY|FULLTEXT|INDEX)\b/i', $line ) ) {
				continue;
			}
			if ( preg_match( '/^([a-z_][a-z0-9_]*)\s+[a-z]/i', $line, $c ) ) {
				$cols[] = $c[1];
			}
		}
		$tables[ $t[1] ] = $cols;
	}
}

/* ---------- 2. Table-key constant -> table name ---------- */

$names_src = file_get_contents( "{$root}/src/Database/Table_Names.php" );
$consts    = array();
if ( preg_match_all( "/public const ([A-Z_]+)\s*=\s*'([a-z_]+)'/", $names_src, $m, PREG_SET_ORDER ) ) {
	foreach ( $m as $c ) {
		$consts[ $c[1] ] = $c[2];
	}
}

/* ---------- 3. Columns each repository touches ---------- */

/** Extract the balanced text of the call starting at the '(' after $needle. */
function call_args( string $src, int $from ): string {
	$open = strpos( $src, '(', $from );
	if ( false === $open ) {
		return '';
	}
	$depth  = 0;
	$length = strlen( $src );
	for ( $i = $open; $i < $length; $i++ ) {
		if ( '(' === $src[ $i ] ) {
			++$depth;
		} elseif ( ')' === $src[ $i ] ) {
			--$depth;
			if ( 0 === $depth ) {
				return substr( $src, $open + 1, $i - $open - 1 );
			}
		}
	}
	return '';
}

$repos   = glob( "{$root}/src/*/*_Repository.php" );
$reports = array();

foreach ( $repos as $file ) {
	$src   = file_get_contents( $file );
	$short = str_replace( "{$root}/", '', $file );

	if ( ! preg_match( '/function table_key\(\).*?return Table_Names::([A-Z_]+)/s', $src, $m ) ) {
		continue;
	}
	$table = $consts[ $m[1] ] ?? null;
	if ( null === $table || ! isset( $tables[ $table ] ) ) {
		$reports[] = array( $short, '(table)', "table_key() names {$m[1]}, which has no DDL" );
		continue;
	}

	$used = array();

	// insert()/insert_ignore()/update() array literals.
	foreach ( array( 'insert', 'insert_ignore', 'update' ) as $method ) {
		$off = 0;
		$pos = strpos( $src, "\$this->{$method}(", $off );
		while ( false !== $pos ) {
			$args = call_args( $src, $pos );
			if ( preg_match_all( "/'([a-z_][a-z0-9_]*)'\s*=>/", $args, $k ) ) {
				foreach ( $k[1] as $col ) {
					$used[ $col ][] = $method . '()';
				}
			}
			$off = $pos + 1;
			$pos = strpos( $src, "\$this->{$method}(", $off );
		}
	}

	// Columns named as the first argument of a find_one_by lookup.
	if ( preg_match_all( "/find_one_by\(\s*'([a-z_][a-z0-9_]*)'/", $src, $k ) ) {
		foreach ( $k[1] as $col ) {
			$used[ $col ][] = 'find_one_by()';
		}
	}

	// Hand-written SQL: `col` = %s, col = %s, ORDER BY col, SET col =
	if ( preg_match_all( '/"([^"]*(?:SELECT|UPDATE|DELETE|INSERT)[^"]*)"/s', $src, $sqls ) ) {
		foreach ( $sqls[1] as $sql ) {
			/*
			 * Only UNQUALIFIED references are checked. A name carrying an alias
			 * ("j.customer_id") belongs to whichever table that alias was bound
			 * to in the JOIN, which this checker does not resolve -- guessing
			 * would report a correct join as a mismatch. Names followed by "("
			 * are SQL functions, not columns.
			 */
			if ( preg_match_all( '/(^|[^.\w`])`?([a-z_][a-z0-9_]*)`?\s*(?:=|<=|>=|<|>|IS)\s*(?:%[sdf]|NULL)/i', $sql, $k, PREG_SET_ORDER ) ) {
				foreach ( $k as $hit ) {
					$used[ $hit[2] ][] = 'SQL';
				}
			}
			if ( preg_match_all( '/ORDER BY\s+`?([a-z_][a-z0-9_]*)`?(?![\w.(])/i', $sql, $k ) ) {
				foreach ( $k[1] as $col ) {
					$used[ $col ][] = 'SQL';
				}
			}
		}
	}

	foreach ( $used as $col => $wheres ) {
		if ( in_array( $col, $tables[ $table ], true ) ) {
			continue;
		}
		$elsewhere = array();
		foreach ( $tables as $tname => $tcols ) {
			if ( in_array( $col, $tcols, true ) ) {
				$elsewhere[] = $tname;
			}
		}
		$hint = $elsewhere ? ' (exists in ' . implode( ', ', $elsewhere ) . ')' : ' (exists in NO table)';
		$reports[] = array( $short, $col, 'used by ' . implode( ', ', array_unique( $wheres ) ) . ", not a column of {$table}" . $hint );
	}
}

echo 'Tables parsed: ' . count( $tables ) . ', repositories checked: ' . count( $repos ) . "\n";
foreach ( $tables as $t => $c ) {
	echo "  {$t}: " . count( $c ) . " columns\n";
}
echo "\n";
if ( ! $reports ) {
	echo "OK: every column each repository reads or writes exists in the table it owns.\n";
	exit( 0 );
}
foreach ( $reports as $r ) {
	echo "MISMATCH {$r[0]}  {$r[1]}  -- {$r[2]}\n";
}
exit( 1 );
