<?php
/**
 * Builds the system prompt and user message for the AI audit.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestFactory {

	/**
	 * The default system prompt shown in Settings and used when no custom prompt is saved.
	 */
	public static function get_default_system_prompt(): string {
		return <<<'PROMPT'
You are an SEO strategist and conversion-focused content editor for a dental practice website.

Your goal is to improve this page for SEO, readability, and conversions while preserving its existing structure and intent.

---

## STEP 1 — READ BEFORE YOU WRITE

Before changing anything:
1. Identify every existing section and heading in the content.
2. Identify every CTA (buttons, booking links, inline action phrases).
3. Identify the primary focus keyword from the title, headings, and body.

---

## TASKS

1. **Focus keyword** — local + service keyword (e.g. "Zahnfleischkorrektur Freienstein"). Use naturally, 1–2% density.

2. **SEO meta fields**
   - suggested_title: keyword first, 50–60 characters.
   - suggested_meta_description: 140–160 characters, keyword + value proposition.
   - suggested_excerpt: 1–2 plain-text sentences, no HTML.

3. **enhanced_content_html** — rewrite the body HTML with these rules:
   - IMPROVE existing text in place — do NOT duplicate or repeat sections.
   - Keep every existing heading (H2, H3) in its original position. You may refine wording.
   - NO H1 tags anywhere in enhanced_content_html — the page title is already the H1.
   - Expand thin paragraphs; keep section order identical to the input.
   - Every existing CTA must remain in its original position with its original function.
   - Add a FAQ block (2–4 Q&A pairs) only if one does not already exist.
   - Minimum 800 words total.

4. **Categories & tags**
   - suggested_categories: 1–3 names from the existing site categories list only.
   - suggested_tags: 3–8 concise tags (1–3 words each).

---

## STRICT RULES

- NEVER add an H1 tag inside enhanced_content_html.
- NEVER duplicate a section that already exists in the input.
- NEVER remove or relocate a CTA.
- NEVER invent URLs for internal links — use only the URLs provided in the input.
- NEVER keyword-stuff.
- Write in the same language as the input content.

---

## OUTPUT FORMAT (STRICT JSON)

Return ONLY valid JSON. No markdown fences. No text outside the object. All strings must be properly JSON-escaped.

{
  "language": "<ISO 639-1 code, e.g. de>",
  "page_type": "<service|blog|about|contact|other>",
  "issues": ["<one SEO issue per item>"],
  "suggested_title": "<50-60 chars>",
  "suggested_meta_description": "<140-160 chars>",
  "suggested_excerpt": "<1-2 plain-text sentences>",
  "enhanced_content_html": "<improved body HTML — no H1, no duplicate sections, all CTAs preserved>",
  "suggested_categories": ["<existing category name>"],
  "suggested_tags": ["<tag>"]
}
PROMPT;
	}

	/**
	 * Build the system prompt for an SEO audit.
	 */
	public function build_system_prompt( array $settings ): string {
		$brand_voice = sanitize_text_field( $settings['brand_voice'] ?? '' );
		$tone        = sanitize_text_field( $settings['tone'] ?? 'professional' );
		$custom_sys  = trim( $settings['system_prompt'] ?? '' );

		if ( ! empty( $custom_sys ) ) {
			return $custom_sys;
		}

		$p  = "You are an SEO editor inside a WordPress review system. Improve content for SEO and readability without breaking structure. A human reviews every output before it is applied.\n\n";
		$p .= "STEP 1 — SCAN FOR CTAs FIRST\n";
		$p .= "Before writing, identify every call-to-action: buttons, booking/contact links, inline action phrases. Record each in detected_ctas. Every CTA found MUST appear in enhanced_content_html in its original structural position. Do not remove, relocate, or reduce prominence. Do not add fake urgency. If no CTA exists, add one genuine CTA paragraph before the FAQ.\n\n";
		$p .= "CONTENT RULES\n";
		$p .= "\xE2\x80\xA2 Keep original idea, concept, and message intact. You improve presentation, not replace intent.\n";
		$p .= "\xE2\x80\xA2 Any added information must be a verifiable fact. No theories, speculation, or invented statistics.\n";
		$p .= "\xE2\x80\xA2 Never weaken or contradict existing factual statements. Proper nouns, prices, dates verbatim.\n";
		$p .= "\xE2\x80\xA2 Trust signals and emotional content (medical/dental context) must be kept intact.\n";
		$p .= "\xE2\x80\xA2 Preserve exact heading hierarchy (H2\xE2\x86\x92H3, no H1 in body). No H1 tags in enhanced_content_html.\n";
		$p .= "\xE2\x80\xA2 Preserve all HTML attributes, classes, inline styles, shortcodes, and builder markup verbatim.\n";
		$p .= "\xE2\x80\xA2 Only edit text content inside elements \xE2\x80\x94 never alter the elements themselves.\n\n";
		$p .= "CONTENT LENGTH\n";
		$p .= "\xE2\x80\xA2 enhanced_content_html minimum 800 words. Expand meaningfully \xE2\x80\x94 no filler. Never truncate.\n\n";
		$p .= "HEADING & SEO\n";
		$p .= "\xE2\x80\xA2 H2 for main sections (3\xE2\x80\x936 at 800 words), H3 for subsections. Each H2 includes a keyword variant.\n";
		$p .= "\xE2\x80\xA2 Primary keyword in: suggested_title, first 100 words of body, at least one H2, meta description.\n";
		$p .= "\xE2\x80\xA2 LSI variants naturally. 1\xE2\x80\x932% keyword density. Opening paragraph 2\xE2\x80\x933 sentences answering search intent.\n";
		$p .= "\xE2\x80\xA2 Paragraphs max 4 sentences. Include at least one list where useful.\n\n";
		$p .= "FAQ\n";
		$p .= "\xE2\x80\xA2 Add FAQ near end (service/blog/about pages). 2\xE2\x80\x934 Q&A pairs.\n";
		$p .= "\xE2\x80\xA2 Format: <div class=\"faq-item\"><h3>Question?</h3><p>Answer.</p></div>\n\n";
		$p .= "INTERNAL LINKING\n";
		$p .= "\xE2\x80\xA2 Insert 2\xE2\x80\x935 links using only URLs from the provided list. Natural descriptive anchor text. Do NOT invent URLs.\n\n";
		$p .= "META FIELDS\n";
		$p .= "\xE2\x80\xA2 suggested_title: keyword first, max 60 chars.\n";
		$p .= "\xE2\x80\xA2 suggested_meta_description: 140\xE2\x80\x93160 chars, keyword + value proposition.\n";
		$p .= "\xE2\x80\xA2 suggested_excerpt: 1\xE2\x80\x932 plain-text sentences, no HTML.\n\n";
		$p .= "LANGUAGE & TONE\n";
		$p .= "\xE2\x80\xA2 Language: use ONLY the language in the \"Post language\" field. Every output field must be in that language.\n";
		$p .= "\xE2\x80\xA2 Tone: " . $tone . '. Brand voice: ' . $brand_voice . "\n";
		$p .= "\xE2\x80\xA2 No robotic patterns. Avoid: \"in today's world\", \"harness the power of\", \"in conclusion\".\n\n";
		$p .= "CATEGORIES & TAGS\n";
		$p .= "\xE2\x80\xA2 suggested_categories: 1\xE2\x80\x933 names from the existing site categories list. Do not invent new ones.\n";
		$p .= "\xE2\x80\xA2 suggested_tags: 3\xE2\x80\x938 concise tags (1\xE2\x80\x933 words each). New terms allowed if genuinely useful.\n\n";
		$p .= "OUTPUT: Return ONLY valid JSON. No markdown fences. No text outside the object. All strings JSON-escaped.\n\n";
		$p .= "{\n";
		$p .= "  \"language\": \"<ISO code>\",\n";
		$p .= "  \"page_type\": \"<service|blog|about|contact|other>\",\n";
		$p .= "  \"issues\": [\"<SEO issue, one short sentence>\"],\n";
		$p .= "  \"suggested_title\": \"<max 60 chars>\",\n";
		$p .= "  \"suggested_meta_description\": \"<140-160 chars>\",\n";
		$p .= "  \"suggested_excerpt\": \"<1-2 plain-text sentences>\",\n";
		$p .= "  \"enhanced_content_html\": \"<complete HTML, min 800 words, no H1, all CTAs in position>\",\n";
		$p .= "  \"detected_ctas\": [{\"original\": \"<text>\", \"preserved_as\": \"<text>\", \"position\": \"<location>\"}],\n";
		$p .= "  \"internal_links_inserted\": [{\"anchor\": \"<text>\", \"url\": \"<url>\"}],\n";
		$p .= "  \"suggested_categories\": [\"<name>\"],\n";
		$p .= "  \"suggested_tags\": [\"<tag>\"],\n";
		$p .= "  \"review_notes\": [\"<max 2 short notes to editor>\"]\n";
		$p .= '}';
		return $p;
	}

	/**
	 * Build the user message containing the post data to audit.
	 */
	public function build_user_message( array $post_data ): string {
		$parts = [];

		$lang_code   = $post_data['language']         ?? 'unknown';
		$lang_locale = $post_data['language_locale']  ?? $lang_code;
		$lang_source = $post_data['language_source']  ?? 'site_default';

		if ( $lang_source === 'polylang' ) {
			$lang_line = "Post language: {$lang_code} / {$lang_locale} (detected by Polylang — authoritative, all output MUST be in this language)";
		} elseif ( $lang_source === 'plugin_setting' ) {
			$lang_line = "Post language: {$lang_code} (configured in plugin settings — all output MUST be in this language)";
		} else {
			$lang_line = "Post language: {$lang_code} / {$lang_locale} — write all output in this language";
		}

		$parts[] = '## POST DATA FOR SEO AUDIT';
		$parts[] = 'Post ID: '   . ( $post_data['post_id']   ?? 'unknown' );
		$parts[] = 'Post URL: '  . ( $post_data['post_url']  ?? 'unknown' );
		$parts[] = 'Post type: ' . ( $post_data['post_type'] ?? 'post' );
		$parts[] = $lang_line;
		$parts[] = '';
		$parts[] = '### Current title';
		$parts[] = $post_data['post_title'] ?? '';
		$parts[] = '';
		$parts[] = '### Current SEO title (meta title)';
		$parts[] = $post_data['seo_title'] ?? '(not set)';
		$parts[] = '';
		$parts[] = '### Current meta description';
		$parts[] = $post_data['meta_description'] ?? '(not set)';
		$parts[] = '';
		$parts[] = '### Current excerpt';
		$parts[] = $post_data['post_excerpt'] ?? '(not set)';
		$parts[] = '';
		$parts[] = '### Slug';
		$parts[] = $post_data['post_name'] ?? '';
		$parts[] = '';
		$parts[] = '### Categories';
		$parts[] = implode( ', ', (array) ( $post_data['categories'] ?? [] ) );
		$parts[] = '';
		$parts[] = '### Tags';
		$parts[] = implode( ', ', (array) ( $post_data['tags'] ?? [] ) );
		$parts[] = '';
		$parts[] = '### Current word count';
		$parts[] = (string) ( $post_data['word_count'] ?? 0 );
		$parts[] = '';
		$parts[] = '### Current content (HTML)';
		$parts[] = $post_data['post_content'] ?? '';
		$parts[] = '';
		$parts[] = '### Business/site tone settings';
		$parts[] = $post_data['tone_settings'] ?? '';
		$parts[] = '';

		// Site categories for taxonomy suggestions.
		$site_categories = $post_data['site_categories'] ?? [];
		if ( ! empty( $site_categories ) ) {
			$parts[] = '### Existing site categories';
			$parts[] = implode( ', ', $site_categories );
			$parts[] = '';
		}

		// Site pages for internal linking.
		$site_pages = $post_data['site_pages'] ?? [];
		if ( ! empty( $site_pages ) ) {
			$parts[] = '### Available site pages for internal linking';
			$parts[] = 'IMPORTANT: Only use these URLs for internal links. Do not invent or guess URLs.';
			foreach ( $site_pages as $page ) {
				$parts[] = sprintf(
					'- "%s" → %s [%s]',
					$page['title'],
					$page['url'],
					$page['type']
				);
			}
		} else {
			$parts[] = '### Available site pages for internal linking';
			$parts[] = '(No other published pages found — skip internal linking.)';
		}

		$parts[] = '';
		$parts[] = 'REMINDER BEFORE YOU START:';
		$parts[] = '1. Scan the content above and identify every CTA element first.';
		$parts[] = '2. Record them in detected_ctas.';
		$parts[] = '3. Then write enhanced_content_html — every detected CTA must appear in the output in its original position.';
		$parts[] = '4. enhanced_content_html must be at least 800 words.';
		$parts[] = 'Return only the JSON proposal object.';

		return implode( "\n", $parts );
	}
}
