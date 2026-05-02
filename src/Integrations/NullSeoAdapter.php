<?php
/**
 * No-op SEO adapter used when no SEO plugin is active.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NullSeoAdapter implements SeoAdapterInterface {

	public function get_seo_title( int $post_id ): string {
		return '';
	}

	public function set_seo_title( int $post_id, string $value ): void {
		// No SEO plugin active — store as standard post meta as fallback.
		update_post_meta( $post_id, '_seopilot_seo_title', sanitize_text_field( $value ) );
	}

	public function get_meta_description( int $post_id ): string {
		return '';
	}

	public function set_meta_description( int $post_id, string $value ): void {
		update_post_meta( $post_id, '_seopilot_meta_description', sanitize_text_field( $value ) );
	}

	public function name(): string {
		return __( 'None (no SEO plugin detected)', 'seo-pilot-pro-to-faye-seo-pilot' );
	}
}
