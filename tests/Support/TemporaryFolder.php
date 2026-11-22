<?php
/**
 * TemporaryFolder: a folder of a test's own in the system's temporary directory, and its removal
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Support;

/**
 * Makes a folder of a test's own, under a name no other run shares, and removes it with everything in it.
 *
 * A test makes the folder, then works in a try whose finally removes it, so the folder is gone
 * whether the test passed or failed.
 *
 * @since 0.2.0
 */
final class TemporaryFolder {

	/**
	 * Makes a new, empty folder, readable by its owner alone, in the system's temporary directory.
	 *
	 * @since 0.2.0
	 *
	 * @param string $prefix What the folder's name begins with, before a random suffix.
	 * @return string The folder's path.
	 */
	public static function make( string $prefix ): string {
		$path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex( random_bytes( 8 ) );

		mkdir( $path, 0700 );

		return $path;
	}

	/**
	 * Removes a file or a folder of a test's own, and everything in it, without following links.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path The file or folder.
	 */
	public static function remove( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $name ) {
				self::remove( $path . '/' . $name );
			}

			rmdir( $path );
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}
}
