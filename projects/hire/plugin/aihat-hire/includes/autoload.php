<?php
/**
 * Class autoloader. AIHat\Hire\Foo_Bar lives in includes/class-foo-bar.php.
 */

namespace AIHat\Hire;

require_once __DIR__ . '/functions.php';

spl_autoload_register(
	static function ( $class ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$name = substr( $class, strlen( $prefix ) );
		$file = __DIR__ . '/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
