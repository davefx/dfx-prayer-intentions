<?php
/**
 * Tests for the release package built by bin/build-zip.sh.
 *
 * @package DFXPRAY
 */

/**
 * Release packaging.
 */
class ReleasePackageTest extends DFXPRAY_Test_Case {

	/**
	 * Plugin root.
	 *
	 * @var string
	 */
	private static string $root;

	/**
	 * Built zip, shared by the tests (building takes a few seconds).
	 *
	 * @var string
	 */
	private static string $zip = '';

	/**
	 * Where the test asked for the zip.
	 *
	 * @var string
	 */
	private static string $dist = '';

	/**
	 * Why packaging cannot be tested here, if it cannot.
	 *
	 * @var string
	 */
	private static string $unavailable = '';

	/**
	 * Build once.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$root = dirname( __DIR__, 2 );

		// Packaging happens on the host; the PHP-version containers of
		// tests/bin/php-matrix.sh have neither the tools nor the extension.
		foreach ( array( 'rsync', 'zip', 'unzip' ) as $tool ) {
			if ( '' === trim( (string) shell_exec( 'command -v ' . $tool ) ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
				self::$unavailable = "{$tool} is not installed";
			}
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			self::$unavailable = 'the zip extension is not loaded';
		}
		if ( '' !== self::$unavailable ) {
			return;
		}

		$dist       = get_temp_dir() . 'dfxpray-dist-' . getmypid();
		self::$dist = $dist;

		exec( 'DFXPRAY_DIST_DIR=' . escapeshellarg( $dist ) . ' ' . escapeshellarg( self::$root . '/bin/build-zip.sh' ) . ' 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		if ( 0 === $status ) {
			self::$zip = trim( (string) end( $output ) );
		} else {
			fwrite( STDERR, implode( "\n", $output ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
	}

	/**
	 * Skip the packaging tests where packaging cannot run.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( '' !== self::$unavailable && 'test_versions_agree' !== $this->getName( false ) ) {
			$this->markTestSkipped( 'Cannot build the package here: ' . self::$unavailable . '.' );
		}
	}

	/**
	 * Every place that states the version agrees, and it follows the scheme:
	 * MAJOR.MINOR.YYMMDDN (SemVer, with the release date and the number of the
	 * release that day as the patch, e.g. 1.0.2611170).
	 */
	public function test_versions_agree(): void {
		$root    = self::$root;
		$version = DFXPRAY_VERSION;
		$header  = get_file_data( $root . '/dfx-prayer-intentions.php', array( 'Version' => 'Version' ) )['Version'];
		$block   = json_decode( (string) file_get_contents( $root . '/blocks/form/block.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$asset   = require $root . '/blocks/form/editor.asset.php';
		$readme  = (string) file_get_contents( $root . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertMatchesRegularExpression( '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.[1-9]\d(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d$/', $version );
		$this->assertSame( $version, $header );
		$this->assertSame( $version, $block['version'] );
		$this->assertSame( $version, $asset['version'] );
		$this->assertStringContainsString( "Stable tag: {$version}", $readme );
		$this->assertStringContainsString( "= {$version} =", $readme, 'The changelog needs an entry for this version.' );
	}

	/**
	 * The build succeeds and names the zip after the version.
	 */
	public function test_build_produces_versioned_zip(): void {
		$this->assertNotSame( '', self::$zip, 'bin/build-zip.sh failed' );
		$this->assertFileExists( self::$zip );
		$this->assertSame( 'dfx-prayer-intentions-' . DFXPRAY_VERSION . '.zip', basename( self::$zip ) );
	}

	/**
	 * The zip lands where DFXPRAY_DIST_DIR says, and the script prints its absolute path.
	 */
	public function test_zip_goes_to_requested_directory(): void {
		$this->assertStringStartsWith( self::$dist . '/', self::$zip );
	}

	/**
	 * One command runs the whole release: checks, tests, build.
	 */
	public function test_composer_release_script(): void {
		$composer = json_decode( (string) file_get_contents( self::$root . '/composer.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertSame( 'bin/release.sh', $composer['scripts']['release'] ?? null );
		$this->assertSame( 'bin/build-zip.sh', $composer['scripts']['build'] ?? null );
		$this->assertFileIsReadable( self::$root . '/bin/release.sh' );
		$this->assertTrue( is_executable( self::$root . '/bin/release.sh' ) );
	}

	/**
	 * Everything the plugin needs ships, inside a dfx-prayer-intentions/ folder.
	 */
	public function test_zip_contains_the_plugin(): void {
		$files = $this->zip_files();

		foreach ( array(
			'dfx-prayer-intentions/dfx-prayer-intentions.php',
			'dfx-prayer-intentions/uninstall.php',
			'dfx-prayer-intentions/readme.txt',
			'dfx-prayer-intentions/LICENSE',
			'dfx-prayer-intentions/includes/class-plugin.php',
			'dfx-prayer-intentions/includes/channels/interface-channel.php',
			'dfx-prayer-intentions/admin/class-admin.php',
			'dfx-prayer-intentions/admin/views/moderation.php',
			'dfx-prayer-intentions/templates/index.php',
			'dfx-prayer-intentions/blocks/form/block.json',
			'dfx-prayer-intentions/assets/js/admin.js',
			'dfx-prayer-intentions/languages/dfx-prayer-intentions.pot',
			'dfx-prayer-intentions/languages/dfx-prayer-intentions-es_ES.mo',
		) as $expected ) {
			$this->assertContains( $expected, $files );
		}

		foreach ( $files as $file ) {
			$this->assertStringStartsWith( 'dfx-prayer-intentions/', $file );
		}
	}

	/**
	 * No development tooling, tests or dependencies ship.
	 */
	public function test_zip_excludes_development_files(): void {
		foreach ( $this->zip_files() as $file ) {
			$this->assertDoesNotMatchRegularExpression(
				'#^dfx-prayer-intentions/(tests|vendor|node_modules|bin|dist|\.git|composer\.|package|phpunit|phpcs|playwright|AGENTS\.md|CLAUDE\.md|README\.md|\.gitignore|\.distignore|\.claude)#',
				$file
			);
			$this->assertStringNotContainsString( '__pycache__', $file );
		}
	}

	/**
	 * The compiled translations are up to date with the .po.
	 */
	public function test_zip_translations_are_current(): void {
		$zip = new ZipArchive();
		$zip->open( self::$zip );
		$mo = $zip->getFromName( 'dfx-prayer-intentions/languages/dfx-prayer-intentions-es_ES.mo' );
		$zip->close();

		$tmp = wp_tempnam( 'dfxpray-mo' );
		file_put_contents( $tmp, $mo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$translations = new MO();
		$translations->import_from_file( $tmp );
		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$po = new PO();
		$po->import_from_file( self::$root . '/languages/dfx-prayer-intentions-es_ES.po' );

		$this->assertCount( count( $po->entries ), $translations->entries );
	}

	/**
	 * Files in the zip.
	 *
	 * @return string[]
	 */
	private function zip_files(): array {
		$this->assertNotSame( '', self::$zip, 'bin/build-zip.sh failed' );

		$zip   = new ZipArchive();
		$files = array();
		$zip->open( self::$zip );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$name = $zip->getNameIndex( $i );
			if ( '/' !== substr( $name, -1 ) ) {
				$files[] = $name;
			}
		}
		$zip->close();

		return $files;
	}
}
