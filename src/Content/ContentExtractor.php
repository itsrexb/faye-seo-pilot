<?php
/**
 * Extracts post content and metadata for auditing.
 *
 * For Elementor pages the content is extracted as an array of individual
 * widget elements (each with its own Elementor element ID), so that the AI
 * can improve each widget in isolation and the result can be written back to
 * the exact element — no cross-widget content mixing.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Content;

use FayeSeoPilot\Integrations\SeoAdapterInterface;

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
			return new \WP_Error( 'seopilot_invalid_post', __( 'Post not found.', 'seo-pilot-pro-to-faye-seo-pilot' ) );
		}

		if ( ! in_array( $post->post_status, [ 'publish', 'draft', 'pending', 'private' ], true ) ) {
			return new \WP_Error( 'seopilot_invalid_status', __( 'Post status is not auditable.', 'seo-pilot-pro-to-faye-seo-pilot' ) );
		}

		$settings    = get_option( 'seopilot_settings', [] );
		$brand_voice = sanitize_text_field( $settings['brand_voice'] ?? '' );
		$tone        = sanitize_text_field( $settings['tone'] ?? 'professional' );

		// --- Elementor: extract per-element content (preserves element IDs) ---
		$elementor_elements = [];
		$elementor_raw      = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! empty( $elementor_raw ) && '[]' !== $elementor_raw ) {
			$tree = json_decode( $elementor_raw, true );
			if ( is_array( $tree ) ) {
				$this->collect_elementor_elements( $tree, $elementor_elements );
			}
		}

		// Build flat text for word count (from elements when available, else post_content).
		$flat_content = $this->build_flat_content( $post, $elementor_elements );
		$word_count   = str_word_count( wp_strip_all_tags( $flat_content ) );

		$categories     = wp_get_post_categories( $post_id, [ 'fields' => 'names' ] );
		$tags           = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
		$all_categories = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'names' ] );

		$plugin_locale = sanitize_text_field( $settings['locale'] ?? 'de' );
		[ 'code' => $lang_code, 'locale' => $lang_locale, 'source' => $lang_source ] = $this->detect_language( $post_id, $plugin_locale );

		return [
			'post_id'            => $post_id,
			'post_type'          => $post->post_type,
			'post_title'         => $post->post_title,
			'post_content'       => $flat_content,   // flat text for word count / non-Elementor
			'post_excerpt'       => $post->post_excerpt,
			'post_name'          => $post->post_name,
			'post_url'           => (string) get_permalink( $post_id ),
			'seo_title'          => $this->seo_adapter->get_seo_title( $post_id ),
			'meta_description'   => $this->seo_adapter->get_meta_description( $post_id ),
			'language'           => $lang_code,
			'language_locale'    => $lang_locale,
			'language_source'    => $lang_source,
			'categories'         => is_array( $categories ) ? $categories : [],
			'tags'               => is_array( $tags ) ? $tags : [],
			'word_count'         => $word_count,
			'tone_settings'      => "Brand voice: {$brand_voice}. Tone: {$tone}.",
			'site_pages'         => $this->get_site_pages( $post_id ),
			'site_categories'    => is_array( $all_categories ) ? $all_categories : [],
			'elementor_elements' => $elementor_elements,  // empty array for non-Elementor posts
		];
	}

	// -------------------------------------------------------------------------
	// Elementor per-element extraction
	// -------------------------------------------------------------------------

	/**
	 * Walk the Elementor element tree and collect every editable widget as a
	 * structured entry keyed by its Elementor element ID.
	 *
	 * Supported widget types and what we extract:
	 *   text-editor  → content (HTML)
	 *   heading      → content (plain text) + tag (h2/h3/…)
	 *   html         → content (HTML)
	 *   text         → content (plain text)
	 *   button       → content (button label) + url
	 *   accordion / toggle → items (array of {question, answer})
	 *   eael-faq / eael-accordion → items (array of {question, answer})
	 *
	 * @param array $tree   Elementor elements array (top level or nested).
	 * @param array $result Accumulated element list, passed by reference.
	 */
	private function collect_elementor_elements( array $tree, array &$result ): void {
		foreach ( $tree as $element ) {
			$el_type  = $element['elType']     ?? '';
			$widget   = $element['widgetType'] ?? '';
			$id       = $element['id']         ?? '';
			$settings = $element['settings']   ?? [];

			if ( 'widget' === $el_type && $id ) {
				$entry = null;

				switch ( $widget ) {
					case 'text-editor':
						$content = $settings['editor'] ?? '';
						if ( trim( wp_strip_all_tags( $content ) ) ) {
							$entry = [ 'id' => $id, 'type' => 'text-editor', 'content' => $content ];
						}
						break;

					case 'heading':
						$text = wp_strip_all_tags( $settings['title'] ?? '' );
						if ( trim( $text ) ) {
							$entry = [
								'id'      => $id,
								'type'    => 'heading',
								'tag'     => sanitize_key( $settings['header_size'] ?? 'h2' ),
								'content' => $text,
							];
						}
						break;

					case 'html':
						$content = $settings['html'] ?? '';
						if ( trim( wp_strip_all_tags( $content ) ) ) {
							$entry = [ 'id' => $id, 'type' => 'html', 'content' => $content ];
						}
						break;

					case 'text':
						$content = wp_strip_all_tags( $settings['text'] ?? '' );
						if ( trim( $content ) ) {
							$entry = [ 'id' => $id, 'type' => 'text', 'content' => $content ];
						}
						break;

					case 'button':
						$btn_text = wp_strip_all_tags( $settings['text'] ?? '' );
						if ( $btn_text ) {
							$entry = [
								'id'      => $id,
								'type'    => 'button',
								'content' => $btn_text,
								'url'     => $settings['link']['url'] ?? '',
							];
						}
						break;

					case 'accordion':
					case 'toggle':
						$items = [];
						foreach ( $settings['tabs'] ?? [] as $tab ) {
							$q = wp_strip_all_tags( $tab['tab_title']   ?? '' );
							$a = wp_strip_all_tags( $tab['tab_content'] ?? '' );
							if ( $q ) {
								$items[] = [ 'question' => $q, 'answer' => $a ];
							}
						}
						if ( $items ) {
							$entry = [ 'id' => $id, 'type' => $widget, 'items' => $items ];
						}
						break;

					case 'eael-faq':
					case 'eael-accordion':
						$items = [];
						foreach ( $settings['eael_faq_items'] ?? [] as $item ) {
							$q = wp_strip_all_tags( $item['eael_faq_title']   ?? '' );
							$a = wp_strip_all_tags( $item['eael_faq_content'] ?? '' );
							if ( $q ) {
								$items[] = [ 'question' => $q, 'answer' => $a ];
							}
						}
						if ( $items ) {
							$entry = [ 'id' => $id, 'type' => $widget, 'items' => $items ];
						}
						break;
				}

				if ( null !== $entry ) {
					$result[] = $entry;
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->collect_elementor_elements( $element['elements'], $result );
			}
		}
	}

	/**
	 * Build a flat text blob for word-count purposes.
	 * For Elementor posts, concatenates all element content/items.
	 * For classic posts, returns post_content.
	 */
	private function build_flat_content( \WP_Post $post, array $elementor_elements ): string {
		if ( empty( $elementor_elements ) ) {
			return $post->post_content;
		}

		$parts = [];
		foreach ( $elementor_elements as $el ) {
			if ( isset( $el['content'] ) ) {
				$parts[] = $el['content'];
			} elseif ( isset( $el['items'] ) ) {
				foreach ( $el['items'] as $item ) {
					$parts[] = $item['question'] . ' ' . $item['answer'];
				}
			}
		}
		return implode( "\n", $parts );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch published posts and pages for internal linking context.
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

		return [
			'code'   => $plugin_locale,
			'locale' => $plugin_locale,
			'source' => 'plugin_setting',
		];
	}
}
