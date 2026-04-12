<?php
/**
 * Yoast SEO adapter — reads and writes Yoast meta fields.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YoastAdapter implements SeoAdapterInterface {

	public function get_seo_title( int $post_id ): string {
		return (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
	}

	public function set_seo_title( int $post_id, string $value ): void {
		update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $value ) );
	}

	public function get_meta_description( int $post_id ): string {
		return (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
	}

	public function set_meta_description( int $post_id, string $value ): void {
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $value ) );
	}

	public function name(): string {
		return 'Yoast SEO';
	}
}
