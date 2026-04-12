=== SEO Pilot Pro ===
Contributors: rexbengil
Tags: seo, ai, content, meta-description, openai
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered SEO audit and content optimisation for WordPress. Review AI suggestions field by field — apply only what you approve.

== Description ==

SEO Pilot Pro connects your WordPress site to an AI API (Anthropic Claude or OpenAI) to audit posts and pages for SEO gaps and content quality, then generates improved suggestions for editors to review before publishing.

**Key features:**

* Audit any post or page with AI — one click or in bulk
* Get suggested SEO title, meta description, excerpt, and full body copy improvements
* Side-by-side review screen — compare current vs. suggested content per field
* Accept or reject each field individually, edit suggestions before accepting
* Apply only the fields you approve — nothing changes without your sign-off
* Rollback to the pre-edit snapshot at any time
* Full audit log with per-job history
* Yoast SEO integration — reads and writes Yoast meta fields automatically
* Polylang-aware — detects post language per post
* Works with posts and pages; safe handling for Elementor and Gutenberg content

**Privacy and remote data notice:**

This plugin sends post content (title, body, meta fields) to the configured AI provider for processing. No data is stored permanently by the provider beyond their standard API terms. You must have a valid API key and agree to the provider's usage policies. Do not audit posts containing sensitive personal data you do not have a right to process via third-party APIs.

== Installation ==

1. Upload the `seo-pilot-pro` folder to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Go to **SEO Pilot → Settings** and enter your API key.
4. Select a model and configure your brand voice and tone.
5. Go to **SEO Pilot → Content Queue** and click **Audit** on any post or page.
6. Review the suggestions and approve what you want to apply.

== Frequently Asked Questions ==

= Do I need an API key? =
Yes. You need an API key from Anthropic (Claude) or OpenAI. API usage is billed by the provider per token.

= Does the plugin publish changes automatically? =
No. Every change requires explicit approval in the review screen. Nothing is written to your post until you click "Apply Approved Fields".

= Does it work with Yoast SEO? =
Yes. If Yoast SEO is active, the plugin automatically reads and writes the Yoast SEO title and meta description fields.

= Does it work with Elementor? =
The plugin reads post content for auditing. For Elementor pages, meta and excerpt fields can be updated safely. Full body content rewriting is supported for standard content only — Elementor JSON structure is not modified.

= Can I roll back a change? =
Yes. A snapshot of the post is saved before any changes are applied. Use the Roll Back button in the review screen or history page.

= Is my API key stored securely? =
Yes. API keys are encrypted using libsodium before being stored in the database.

== Screenshots ==

1. Settings page — configure API key, model, and brand voice.
2. Content Queue — list posts and pages, run audits.
3. Review screen — side-by-side comparison with field-level approval.
4. Audit History — view past jobs and roll back applied changes.

== Changelog ==

= 1.0.0 =
* Initial release. Settings, content queue, review screen, history, Yoast integration, rollback.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
