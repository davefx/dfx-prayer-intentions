<?php
/**
 * Tests for the singleton contract shared by every plugin class.
 *
 * @package DFXPRAY
 */

/**
 * Singleton behaviour.
 */
class SingletonTest extends DFXPRAY_Test_Case {

	/**
	 * Every plugin service class, which must all be singletons.
	 *
	 * @return array<string, array{string}>
	 */
	public function provide_service_classes(): array {
		$classes = array();
		$root    = dirname( __DIR__, 2 );

		foreach ( array( 'includes', 'includes/channels', 'admin' ) as $directory ) {
			foreach ( glob( $root . '/' . $directory . '/class-*.php' ) as $file ) {
				$slug  = substr( basename( $file, '.php' ), strlen( 'class-' ) );
				$class = 'DFXPRAY_' . implode( '_', array_map( 'ucfirst', explode( '-', $slug ) ) );

				// The autoloader runs before any class can be loaded, so it is static by necessity.
				if ( 'DFXPRAY_Autoloader' !== $class ) {
					$classes[ $class ] = array( $class );
				}
			}
		}

		return $classes;
	}

	/**
	 * Every class file of the plugin is covered, and each one uses the trait.
	 */
	public function test_every_plugin_class_is_a_singleton(): void {
		$classes = array_keys( $this->provide_service_classes() );

		$this->assertGreaterThan( 25, count( $classes ) );
		foreach ( $classes as $class_name ) {
			$this->assertTrue( class_exists( $class_name ), "{$class_name} does not load" );
			$this->assertContains( 'DFXPRAY_Singleton', class_uses( $class_name ), "{$class_name} is not a singleton" );
		}
	}

	/**
	 * The same instance comes back every time.
	 *
	 * @dataProvider provide_service_classes
	 *
	 * @param string $class_name Class under test.
	 */
	public function test_get_instance_returns_the_same_object( string $class_name ): void {
		$this->assertSame( $class_name::get_instance(), $class_name::get_instance() );
		$this->assertInstanceOf( $class_name, $class_name::get_instance() );
	}

	/**
	 * Nobody outside the class can build a second one.
	 *
	 * @dataProvider provide_service_classes
	 *
	 * @param string $class_name Class under test.
	 */
	public function test_constructor_is_not_public( string $class_name ): void {
		$constructor = ( new ReflectionClass( $class_name ) )->getConstructor();

		$this->assertNotNull( $constructor );
		$this->assertFalse( $constructor->isPublic() );
	}

	/**
	 * Cloning would defeat the pattern.
	 *
	 * @dataProvider provide_service_classes
	 *
	 * @param string $class_name Class under test.
	 */
	public function test_cannot_be_cloned( string $class_name ): void {
		$this->expectException( Error::class );
		$instance = $class_name::get_instance();
		$copy     = clone $instance; // phpcs:ignore
	}

	/**
	 * Unserializing would defeat the pattern too.
	 *
	 * @dataProvider provide_service_classes
	 *
	 * @param string $class_name Class under test.
	 */
	public function test_cannot_be_unserialized( string $class_name ): void {
		$this->expectException( LogicException::class );
		$instance = $class_name::get_instance();
		$instance->__wakeup();
	}
}
