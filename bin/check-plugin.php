<?php
/**
 * Prolific plugin-standard checker.
 *
 * Validates a WordPress plugin checkout against the Prolific plugin standard
 * (header, version agreement, readme.txt, required files). Dependency-free;
 * requires PHP 8.4+.
 *
 * Usage: php check-plugin.php <plugin-dir> <slug> [--tag=vX.Y.Z]
 *
 * Exit codes: 0 = all checks passed (warnings allowed), 1 = one or more
 * checks failed, 2 = usage error.
 */

declare(strict_types=1);

if ( PHP_VERSION_ID < 80400 ) {
	fwrite( STDERR, 'check-plugin.php requires PHP 8.4+, running ' . PHP_VERSION . PHP_EOL );
	exit( 2 );
}

const PROLIFIC_SEMVER = '/^\d+\.\d+\.\d+$/';

/**
 * Required header fields in contract order. A value of null means "must be
 * present and non-empty"; a string means "must equal exactly". "{slug}" is
 * substituted. "Requires Plugins" is optional and handled separately.
 */
const PROLIFIC_HEADER = [
	'Plugin Name'       => null,
	'Plugin URI'        => 'https://prolificdigital.com',
	'Description'       => null,
	'Version'           => null,
	'Requires at least' => '6.5',
	'Requires PHP'      => '8.4',
	'Requires Plugins'  => null, // Optional.
	'Author'            => 'Prolific Digital',
	'Author URI'        => 'https://prolificdigital.com',
	'License'           => 'GPL-2.0-or-later',
	'License URI'       => 'https://www.gnu.org/licenses/gpl-2.0.html',
	'Text Domain'       => '{slug}',
	'Domain Path'       => '/languages',
	'Update URI'        => 'https://api.prolificdigital.io/api/update?plugin={slug}',
];

const PROLIFIC_OPTIONAL_HEADER = [ 'Requires Plugins' ];

/** Directories never scanned for PHP files. */
const PROLIFIC_SKIP_DIRS = [ '.git', '.github', '.prolific-ci', 'vendor', 'node_modules', 'build', 'dist', 'tests', 'test', 'bin' ];

final class Report {
	/** @var list<array{0:string,1:string,2:string}> */
	public array $rows = [];

	public function pass( string $section, string $msg ): void {
		$this->rows[] = [ 'PASS', $section, $msg ];
	}

	public function fail( string $section, string $msg ): void {
		$this->rows[] = [ 'FAIL', $section, $msg ];
	}

	public function warn( string $section, string $msg ): void {
		$this->rows[] = [ 'WARN', $section, $msg ];
	}

	public function check( bool $ok, string $section, string $pass_msg, string $fail_msg ): bool {
		$ok ? $this->pass( $section, $pass_msg ) : $this->fail( $section, $fail_msg );
		return $ok;
	}

	public function count( string $status ): int {
		return count( array_filter( $this->rows, static fn( array $r ): bool => $r[0] === $status ) );
	}

	public function render( string $slug, string $dir ): string {
		$color = stream_isatty( STDOUT ) && getenv( 'NO_COLOR' ) === false;
		$paint = static fn( string $s, string $code ): string => $color ? "\033[{$code}m{$s}\033[0m" : $s;
		$codes = [ 'PASS' => '32', 'FAIL' => '31', 'WARN' => '33' ];

		$out  = "Prolific plugin check: {$slug}  ({$dir})" . PHP_EOL;
		$last = '';
		foreach ( $this->rows as [ $status, $section, $msg ] ) {
			if ( $section !== $last ) {
				$out .= PHP_EOL . $section . PHP_EOL;
				$last = $section;
			}
			$out .= '  ' . $paint( str_pad( $status, 4 ), $codes[ $status ] ) . '  ' . $msg . PHP_EOL;
		}
		$fails = $this->count( 'FAIL' );
		$out  .= PHP_EOL . sprintf(
			'Result: %s  (%d passed, %d failed, %d warnings)',
			$fails ? $paint( 'FAILED', '31' ) : $paint( 'OK', '32' ),
			$this->count( 'PASS' ),
			$fails,
			$this->count( 'WARN' )
		) . PHP_EOL;
		return $out;
	}
}

/**
 * Emit GitHub Actions annotations for failures/warnings when running in CI.
 */
function prolific_annotate( Report $report ): void {
	if ( getenv( 'GITHUB_ACTIONS' ) !== 'true' ) {
		return;
	}
	foreach ( $report->rows as [ $status, $section, $msg ] ) {
		if ( 'PASS' === $status ) {
			continue;
		}
		$level = 'FAIL' === $status ? 'error' : 'warning';
		$text  = str_replace( [ '%', "\r", "\n" ], [ '%25', '%0D', '%0A' ], "{$section}: {$msg}" );
		echo "::{$level} title=Plugin standard::{$text}" . PHP_EOL;
	}
}

/**
 * Parse the first docblock of a PHP file into [ field => value ] preserving
 * order, plus the byte offset where the docblock ends.
 *
 * @return array{fields: array<string,string>, order: list<string>, dupes: list<string>, end: int}|null
 */
function prolific_parse_header( string $src ): ?array {
	if ( ! preg_match( '#/\*\*?(.*?)\*/#s', $src, $m, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	$fields = [];
	$order  = [];
	$dupes  = [];
	foreach ( preg_split( '/\R/', $m[1][0] ) as $line ) {
		if ( ! preg_match( '/^[\s*#@]*([A-Za-z][A-Za-z ]*?):\s*(.*?)\s*$/', $line, $lm ) ) {
			continue;
		}
		$key = $lm[1];
		if ( ! array_key_exists( $key, PROLIFIC_HEADER ) ) {
			continue; // Unknown keys (WC requires at least, etc.) are ignored.
		}
		if ( isset( $fields[ $key ] ) ) {
			$dupes[] = $key;
			continue;
		}
		$fields[ $key ] = $lm[2];
		$order[]        = $key;
	}
	return [
		'fields' => $fields,
		'order'  => $order,
		'dupes'  => $dupes,
		'end'    => $m[0][1] + strlen( $m[0][0] ),
	];
}

/**
 * Parse readme.txt header ("Key: value" lines before the first blank-line
 * separated section) and list its == Sections ==.
 *
 * @return array{fields: array<string,string>, sections: list<string>}
 */
function prolific_parse_readme( string $src ): array {
	$fields   = [];
	$sections = [];
	foreach ( preg_split( '/\R/', $src ) as $line ) {
		if ( preg_match( '/^==\s*(.+?)\s*==\s*$/', $line, $m ) && ! str_starts_with( $line, '===' ) ) {
			$sections[] = strtolower( $m[1] );
			continue;
		}
		if ( ! $sections && preg_match( '/^([A-Za-z][A-Za-z ]*?):\s*(.*?)\s*$/', $line, $m ) ) {
			$fields[ strtolower( $m[1] ) ] ??= $m[2];
		}
	}
	return [ 'fields' => $fields, 'sections' => $sections ];
}

/**
 * Collect *_VERSION constants defined in a PHP source.
 *
 * @return array<string,string> name => value
 */
function prolific_version_constants( string $src ): array {
	$found = [];
	$re_define = '/\bdefine\s*\(\s*[\'"]([A-Z][A-Z0-9_]*_VERSION)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/';
	$re_const  = '/\bconst\s+([A-Z][A-Z0-9_]*_VERSION)\s*=\s*[\'"]([^\'"]*)[\'"]/';
	foreach ( [ $re_define, $re_const ] as $re ) {
		if ( preg_match_all( $re, $src, $ms, PREG_SET_ORDER ) ) {
			foreach ( $ms as $m ) {
				$found[ $m[1] ] ??= $m[2];
			}
		}
	}
	return $found;
}

/**
 * Pick the plugin's own version constant: prefer X_VERSION where X_PLUGIN_FILE
 * (or X_FILE / X_PLUGIN_DIR) is also defined; else the only candidate; else
 * one whose name does not look like a schema/DB/API version.
 *
 * @param array<string,string> $consts
 */
function prolific_pick_version_constant( array $consts, string $src ): ?string {
	if ( ! $consts ) {
		return null;
	}
	foreach ( array_keys( $consts ) as $name ) {
		$prefix = substr( $name, 0, -strlen( '_VERSION' ) );
		if ( preg_match( '/[\'"]' . preg_quote( $prefix, '/' ) . '_(PLUGIN_FILE|FILE|PLUGIN_DIR|PATH|DIR)[\'"]/', $src ) ) {
			return $name;
		}
	}
	if ( count( $consts ) === 1 ) {
		return array_key_first( $consts );
	}
	foreach ( array_keys( $consts ) as $name ) {
		if ( ! preg_match( '/_(DB|SCHEMA|API|MIN|MINIMUM|REQUIRED|WC|WP)_VERSION$/', $name ) ) {
			return $name;
		}
	}
	return array_key_first( $consts );
}

/**
 * @return list<string> PHP files relative to $dir, skipping PROLIFIC_SKIP_DIRS.
 */
function prolific_php_files( string $dir ): array {
	$files = [];
	$it    = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			static fn( SplFileInfo $f ): bool => ! ( $f->isDir() && in_array( $f->getFilename(), PROLIFIC_SKIP_DIRS, true ) )
		)
	);
	foreach ( $it as $f ) {
		if ( $f->isFile() && strtolower( $f->getExtension() ) === 'php' ) {
			$files[] = ltrim( substr( $f->getPathname(), strlen( $dir ) ), '/' );
		}
	}
	sort( $files );
	return $files;
}

function prolific_main( array $argv ): int {
	$positional = [];
	$tag        = null;
	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( str_starts_with( $arg, '--tag=' ) ) {
			$tag = substr( $arg, 6 );
		} elseif ( in_array( $arg, [ '-h', '--help' ], true ) ) {
			echo 'Usage: php check-plugin.php <plugin-dir> <slug> [--tag=vX.Y.Z]' . PHP_EOL;
			return 0;
		} else {
			$positional[] = $arg;
		}
	}
	if ( count( $positional ) !== 2 ) {
		fwrite( STDERR, 'Usage: php check-plugin.php <plugin-dir> <slug> [--tag=vX.Y.Z]' . PHP_EOL );
		return 2;
	}
	[ $dir_arg, $slug ] = $positional;
	$dir = realpath( $dir_arg );
	if ( false === $dir || ! is_dir( $dir ) ) {
		fwrite( STDERR, "Not a directory: {$dir_arg}" . PHP_EOL );
		return 2;
	}

	$r = new Report();

	// --- Identity -----------------------------------------------------------
	$s = 'Identity';
	$r->check(
		(bool) preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ),
		$s,
		"Slug '{$slug}' is lowercase-hyphen",
		"Slug '{$slug}' must be lowercase letters, digits and single hyphens"
	);
	if ( basename( $dir ) !== $slug ) {
		$r->warn( $s, "Folder name '" . basename( $dir ) . "' differs from slug (fine for a local checkout; the release zip folder is always '{$slug}/')" );
	}
	$main_rel = "{$slug}.php";
	$main     = "{$dir}/{$main_rel}";
	if ( ! $r->check( is_file( $main ), $s, "Main file {$slug}/{$main_rel} found", "Main file {$slug}/{$main_rel} not found" ) ) {
		$others = array_map( 'basename', glob( "{$dir}/*.php" ) ?: [] );
		if ( $others ) {
			$r->warn( $s, 'Top-level PHP files present: ' . implode( ', ', $others ) );
		}
		echo $r->render( $slug, $dir );
		prolific_annotate( $r );
		return 1;
	}
	$src = (string) file_get_contents( $main );

	// --- Header -------------------------------------------------------------
	$s      = 'Main file header';
	$header = prolific_parse_header( $src );
	$version = null;
	if ( null === $header ) {
		$r->fail( $s, 'No header docblock found' );
	} else {
		$fields = $header['fields'];
		foreach ( PROLIFIC_HEADER as $key => $expected ) {
			$optional = in_array( $key, PROLIFIC_OPTIONAL_HEADER, true );
			if ( ! array_key_exists( $key, $fields ) || '' === $fields[ $key ] ) {
				if ( ! $optional ) {
					$r->fail( $s, "Missing '{$key}'" );
				}
				continue;
			}
			$value = $fields[ $key ];
			if ( null !== $expected ) {
				$want = str_replace( '{slug}', $slug, $expected );
				$r->check( $value === $want, $s, "{$key}: {$value}", "{$key}: expected '{$want}', found '{$value}'" );
			} elseif ( 'Version' === $key ) {
				$version = $value;
				$r->check( (bool) preg_match( PROLIFIC_SEMVER, $value ), $s, "Version: {$value}", "Version '{$value}' is not three-part semver (x.y.z)" );
			} elseif ( 'Requires Plugins' === $key ) {
				$ok = (bool) preg_match( '/^[a-z0-9-]+(\s*,\s*[a-z0-9-]+)*$/', $value );
				$r->check( $ok, $s, "Requires Plugins: {$value}", "Requires Plugins '{$value}' must be a comma list of wp.org slugs" );
			} else {
				$r->pass( $s, "{$key} present" );
			}
		}
		foreach ( array_unique( $header['dupes'] ) as $dupe ) {
			$r->fail( $s, "Duplicate header line '{$dupe}'" );
		}
		$want_order = array_values( array_filter( array_keys( PROLIFIC_HEADER ), static fn( string $k ): bool => in_array( $k, $header['order'], true ) ) );
		$r->check(
			$header['order'] === $want_order,
			$s,
			'Header fields are in contract order',
			'Header fields out of order; expected: ' . implode( ' / ', $want_order )
		);

		// ABSPATH guard immediately after the header.
		$after = substr( $src, $header['end'] );
		$r->check(
			(bool) preg_match( '/^\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\|\|\s*exit\s*;/', $after ),
			'ABSPATH guard',
			"{$main_rel}: `defined( 'ABSPATH' ) || exit;` immediately after header",
			"{$main_rel}: `defined( 'ABSPATH' ) || exit;` must be the first statement after the header"
		);
	}

	// Every other PHP file needs an ABSPATH guard (uninstall.php may use WP_UNINSTALL_PLUGIN).
	$missing_guard = [];
	foreach ( prolific_php_files( $dir ) as $rel ) {
		if ( $rel === $main_rel ) {
			continue;
		}
		$code  = (string) file_get_contents( "{$dir}/{$rel}" );
		$const = 'uninstall.php' === $rel ? '(ABSPATH|WP_UNINSTALL_PLUGIN)' : 'ABSPATH';
		if ( ! preg_match( '/defined\s*\(\s*[\'"]' . $const . '[\'"]\s*\)/', $code ) ) {
			$missing_guard[] = $rel;
		}
	}
	if ( $missing_guard ) {
		$r->fail( 'ABSPATH guard', count( $missing_guard ) . ' PHP file(s) without an ABSPATH guard: ' . implode( ', ', array_slice( $missing_guard, 0, 15 ) ) . ( count( $missing_guard ) > 15 ? ', ...' : '' ) );
	} else {
		$r->pass( 'ABSPATH guard', 'All other plugin PHP files have an ABSPATH guard' );
	}

	// --- Versions -----------------------------------------------------------
	$s      = 'Versions agree';
	$consts = prolific_version_constants( $src );
	$cname  = prolific_pick_version_constant( $consts, $src );
	if ( null === $cname ) {
		$r->fail( $s, "No {PREFIX}_VERSION constant defined in {$main_rel}" );
	} elseif ( null !== $version ) {
		$r->check( $consts[ $cname ] === $version, $s, "{$cname} = {$version}", "{$cname} is '{$consts[$cname]}', header Version is '{$version}'" );
	}

	$pkg_file = "{$dir}/package.json";
	if ( is_file( $pkg_file ) ) {
		$pkg = json_decode( (string) file_get_contents( $pkg_file ), true );
		if ( ! is_array( $pkg ) ) {
			$r->fail( $s, 'package.json is not valid JSON' );
		} elseif ( ! isset( $pkg['version'] ) ) {
			$r->fail( $s, 'package.json has no "version"' );
		} elseif ( null !== $version ) {
			$r->check( $pkg['version'] === $version, $s, "package.json version = {$version}", "package.json version is '{$pkg['version']}', header Version is '{$version}'" );
		}
	}

	if ( null !== $tag && null !== $version ) {
		$r->check( $tag === "v{$version}", $s, "Tag {$tag} = v{$version}", "Tag '{$tag}' does not equal 'v{$version}'" );
	}

	// --- readme.txt ---------------------------------------------------------
	$s           = 'readme.txt';
	$readme_file = "{$dir}/readme.txt";
	if ( $r->check( is_file( $readme_file ), $s, 'readme.txt exists', 'readme.txt missing' ) ) {
		$readme = prolific_parse_readme( (string) file_get_contents( $readme_file ) );
		$rf     = $readme['fields'];

		$stable = $rf['stable tag'] ?? null;
		if ( null === $stable ) {
			$r->fail( $s, 'Stable tag missing' );
		} elseif ( null !== $version ) {
			$r->check( $stable === $version, $s, "Stable tag = {$version}", "Stable tag is '{$stable}', header Version is '{$version}'" );
		}
		$tested = $rf['tested up to'] ?? '';
		$r->check( '' !== $tested, $s, "Tested up to: {$tested}", 'Tested up to missing' );
		$rphp = $rf['requires php'] ?? null;
		$r->check( '8.4' === $rphp, $s, 'Requires PHP: 8.4', "Requires PHP: expected '8.4', found '" . ( $rphp ?? '(missing)' ) . "'" );
		$rwp = $rf['requires at least'] ?? null;
		$r->check( '6.5' === $rwp, $s, 'Requires at least: 6.5', "Requires at least: expected '6.5', found '" . ( $rwp ?? '(missing)' ) . "'" );
		$r->check( isset( $rf['license'] ) && '' !== $rf['license'], $s, 'License present', 'License missing' );
		foreach ( [ 'description', 'changelog' ] as $section ) {
			$r->check( in_array( $section, $readme['sections'], true ), $s, "== " . ucfirst( $section ) . " == section present", "== " . ucfirst( $section ) . " == section missing" );
		}
	}

	// --- Required files -----------------------------------------------------
	$s = 'Required files';
	foreach ( [ 'CHANGELOG.md', 'LICENSE', '.distignore' ] as $file ) {
		$r->check( is_file( "{$dir}/{$file}" ), $s, "{$file} exists", "{$file} missing" );
	}
	if ( is_file( "{$dir}/LICENSE" ) ) {
		$lic = (string) file_get_contents( "{$dir}/LICENSE" );
		$r->check(
			str_contains( $lic, 'GNU GENERAL PUBLIC LICENSE' ) && (bool) preg_match( '/Version 2, June 1991/', $lic ),
			$s,
			'LICENSE is the GPL-2.0 text',
			'LICENSE does not look like the full GPL-2.0 text'
		);
	}
	if ( is_file( "{$dir}/CHANGELOG.md" ) && null !== $version ) {
		$has = (bool) preg_match( '/^##\s*\[?v?' . preg_quote( $version, '/' ) . '\]?(\s|$)/m', (string) file_get_contents( "{$dir}/CHANGELOG.md" ) );
		if ( $has ) {
			$r->pass( $s, "CHANGELOG.md has a section for {$version}" );
		} elseif ( null !== $tag ) {
			$r->fail( $s, "CHANGELOG.md has no '## [{$version}]' section (release notes come from it)" );
		} else {
			$r->warn( $s, "CHANGELOG.md has no '## [{$version}]' section yet (required at release time)" );
		}
	}
	// Advisory: the rest of the contract's file list.
	foreach ( [ 'README.md', 'composer.json', 'phpcs.xml.dist', 'languages/.gitkeep', 'includes/update-checker.php', '.github/workflows/ci.yml', '.github/workflows/release.yml' ] as $file ) {
		if ( ! file_exists( "{$dir}/{$file}" ) ) {
			$r->warn( $s, "{$file} missing (contract requires it)" );
		}
	}
	if ( is_dir( "{$dir}/vendor/plugin-update-checker" ) || is_dir( "{$dir}/plugin-update-checker" ) || is_dir( "{$dir}/lib/plugin-update-checker" ) ) {
		$r->warn( $s, 'Pasted-in plugin-update-checker copy found; use composer yahnis-elsts/plugin-update-checker instead' );
	}

	echo $r->render( $slug, $dir );
	prolific_annotate( $r );
	return $r->count( 'FAIL' ) > 0 ? 1 : 0;
}

exit( prolific_main( $argv ) );
