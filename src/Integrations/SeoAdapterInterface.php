<?php
/**
 * SEO plugin adapter interface.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SeoAdapterInterface {

	/** Get the SEO title for a post. */
	public function get_seo_title( int $post_id ): string;

	/** Set the SEO title for a post. */
	public function set_seo_title( int $post_id, string $value ): void;

	/** Get the meta description for a post. */
	public function get_meta_description( int $post_id ): string;

	/** Set the meta description for a post. */
	public function set_meta_description( int $post_id, string $value ): void;

	/** Return the adapter name for display. */
	public function name(): string;
}
