<?php
/**
 * Extracts post content and metadata for auditing.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Content;

use SeoPilotPro\Integrations\SeoAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ContentExtractor {

	public function __construct( private readonly SeoAdapterInterface $seo_adapter ) {}

	/**
	 * Extract all relevant data from a post for the audit prompt.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array|\WP_Error
	 */
	public function extract( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'seopilot_invalid_post', __( 'Post not found.', 'seo-pilot-pro' ) );
		}

		if ( ! in_array( $post->post_status, [ 'publish', 'draft', 'pending', 'private' ], true ) ) {
			return new \WP_Error( 'seopilot_invalid_status', __( 'Post status is not auditable.', 'seo-pilot-pro' ) );
		}

		$settings      = get_option( 'seopilot_settings', [] );
		$brand_voice   = sanitize_text_field( $settings['brand_voice'] ?? '' );
		$tone          = sanitize_text_field( $settings['tone'] ?? 'professional' );

		$content    = $this->get_content( $post );
		$word_count = str_word_count( wp_strip_all_tags( $content ) );

		$categories = wp_get_post_categories( $post_id, [ 'fields' => 'names' ] );
		$tags       = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );

		$all_categories = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'names' ] );

		$plugin_locale = sanitize_text_field( $settings['locale'] ?? 'de' );
		[ 'code' => $lang_code, 'locale' => $lang_locale, 'source' => $lang_source ] = $this->detect_language( $post_id, $plugin_locale );

		return [
			'post_id'          => $post_id,
			'post_type'        => $post->post_type,
			'post_title'       => $post->post_title,
			'post_content'     => $content,
			'post_excerpt'     => $post->post_excerpt,
			'post_name'        => $post->post_name,
			'post_url'         => (string) get_permalink( $post_id ),
			'seo_title'        => $this->seo_adapter->get_seo_title( $post_id ),
			'meta_description' => $this->seo_adapter->get_meta_description( $post_id ),
			'language'         => $lang_code,
			'language_locale'  => $lang_locale,
			'language_source'  => $lang_source,
			'categories'       => is_array( $categories ) ? $categories : [],
			'tags'             => is_array( $tags ) ? $tags : [],
			'word_count'       => $word_count,
			'tone_settings'    => "Brand voice: {$brand_voice}. Tone: {$tone}.",
			'site_pages'       => $this->get_site_pages( $post_id ),
			'site_categories'  => is_array( $all_categories ) ? $all_categories : [],
		];
	}

	/**
	 * Return the renderable content for a post.
	 * For Elementor pages, reconstruct HTML from the widget tree stored in _elementor_data.
	 * Falls back to post_content for classic/block-editor posts.
	 */
	private function get_content( \WP_Post $post ): string {
		$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );

		if ( empty( $elementor_data ) || '[]' === $elementor_data ) {
			return $post->post_content;
		}

		$elements = json_decode( wp_unslash( $elementor_data ), true );
		if ( ! is_array( $elements ) ) {
			return $post->post_content;
		}

		$html = '';
		$this->walk_elementor_elements( $elements, $html );

		return $html !== '' ? $html : $post->post_content;
	}

	/**
	 * Recursively walk Elementor elements and reconstruct HTML from widget content fields.
	 *
	 * @param array  $elements Elementor elements array.
	 * @param string $html     Accumulated HTML, passed by reference.
	 */
	private function walk_elementor_elements( array $elements, string &$html ): void {
		foreach ( $elements as $element ) {
			$type       = $element['elType']    ?? '';
			$widget     = $element['widgetType'] ?? '';
			$settings   = $element['settings']  ?? [];

			if ( $type === 'widget' ) {
				switch ( $widget ) {
					case 'heading':
						$tag  = sanitize_key( $settings['header_size'] ?? 'h2' );
						$text = wp_kses_post( $settings['title'] ?? '' );
						if ( $text ) {
							$html .= "<{$tag}>{$text}</{$tag}>\n";
						}
						break;

					case 'text-editor':
						$content = $settings['editor'] ?? '';
						if ( $content ) {
							$html .= $content . "\n";
						}
						break;

					case 'html':
						$content = $settings['html'] ?? '';
						if ( $content ) {
							$html .= $content . "\n";
						}
						break;

					case 'text':
						$content = $settings['text'] ?? '';
						if ( $content ) {
							$html .= '<p>' . wp_kses_post( $content ) . "</p>\n";
						}
						break;

					case 'button':
						$btn_text = $settings['text'] ?? '';
						$btn_url  = $settings['link']['url'] ?? '#';
						if ( $btn_text ) {
							$html .= '<p><a href="' . esc_url( $btn_url ) . '">' . esc_html( $btn_text ) . "</a></p>\n";
						}
						break;

					case 'accordion':
					case 'toggle':
						$items = $settings['tabs'] ?? [];
						foreach ( $items as $item ) {
							$q = $item['tab_title']   ?? '';
							$a = $item['tab_content'] ?? '';
							if ( $q ) {
								$html .= '<h3>' . esc_html( $q ) . "</h3>\n";
							}
							if ( $a ) {
								$html .= '<p>' . wp_kses_post( $a ) . "</p>\n";
							}
						}
						break;
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk_elementor_elements( $element['elements'], $html );
			}
		}
	}

	/**
	 * Fetch published posts and pages for internal linking context.
	 * Returns up to 50 entries sorted by last-modified, excluding the current post.
	 *
	 * @param int $exclude_post_id The post being audited (exclude from the list).
	 * @return array<array{title:string,url:string,type:string}>
	 */
	private function get_site_pages( int $exclude_post_id ): array {
		$ids = get_posts( [
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'exclude'        => [ $exclude_post_id ], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- small site, needed for internal linking context
			'fields'         => 'ids',
		] );

		$pages = [];
		foreach ( $ids as $id ) {
			$url = get_permalink( $id );
			if ( ! $url ) {
				continue;
			}
			$pages[] = [
				'title' => get_the_title( $id ),
				'url'   => $url,
				'type'  => (string) get_post_type( $id ),
			];
		}

		return $pages;
	}

	/**
	 * Detect the post language via Polylang, or fall back to the plugin locale setting.
	 *
	 * @param string $plugin_locale The locale configured in the plugin settings (e.g. "de").
	 * @return array{code: string, locale: string, source: string}
	 */
	private function detect_language( int $post_id, string $plugin_locale ): array {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$locale = pll_get_post_language( $post_id, 'locale' );
			$slug   = pll_get_post_language( $post_id, 'slug' );
			if ( $locale && $slug ) {
				return [
					'code'   => $slug,
					'locale' => $locale,
					'source' => 'polylang',
				];
			}
		}

		// Polylang not active — use the locale from plugin settings.
		return [
			'code'   => $plugin_locale,
			'locale' => $plugin_locale,
			'source' => 'plugin_setting',
		];
	}
}
