<?php
/**
 * Small helpers shared across classes.
 */

namespace AIHat\Hire;

/**
 * wp_json_encode when WordPress is loaded, json_encode otherwise (tests).
 */
function wp_json_encode_safe( $value ): string {
	if ( function_exists( 'wp_json_encode' ) ) {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * Decodes a JSON column to an array.
 */
function json_col( $value ): array {
	$decoded = json_decode( (string) $value, true );
	return is_array( $decoded ) ? $decoded : array();
}
