<?php
/**
 * Plugin settings page — provider, API keys, model, brand voice, etc.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Admin;

use FayeSeoPilot\Api\RequestFactory;
use FayeSeoPilot\Security\ApiKeyEncryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsPage {

	private string $hook = '';

	public function register(): void {
		$this->hook = add_menu_page(
			__( 'Faye SEO Pilot', 'faye-seo-pilot' ),
			__( 'Faye SEO Pilot', 'faye-seo-pilot' ),
			'manage_options',
			'faye-seo-pilot-settings',
			[ $this, 'render' ],
			'dashicons-editor-spellcheck',
			80
		);

		add_submenu_page(
			'faye-seo-pilot-settings',
			__( 'Settings', 'faye-seo-pilot' ),
			__( 'Settings', 'faye-seo-pilot' ),
			'manage_options',
			'faye-seo-pilot-settings',
			[ $this, 'render' ]
		);

		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function hook_suffix(): string {
		return $this->hook;
	}

	public function register_settings(): void {
		register_setting(
			'seopilot_settings_group',
			'seopilot_settings',
			[ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ]
		);
	}

	public function sanitize_settings( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$provider = sanitize_text_field( $input['provider'] ?? 'anthropic' );
		if ( ! in_array( $provider, [ 'anthropic', 'openai' ], true ) ) {
			$provider = 'anthropic';
		}

		// Load existing saved settings so we can preserve keys the user left blank.
		$existing = get_option( 'seopilot_settings', [] );

		// Anthropic API key — encrypt on save; keep existing if field left blank.
		$raw_api_key = trim( sanitize_text_field( $input['api_key'] ?? '' ) );
		if ( $raw_api_key !== '' ) {
			$api_key = ApiKeyEncryption::encrypt( $raw_api_key );
		} else {
			$api_key = $existing['api_key'] ?? '';
		}

		// OpenAI API key — same pattern.
		$raw_openai_key = trim( sanitize_text_field( $input['openai_api_key'] ?? '' ) );
		if ( $raw_openai_key !== '' ) {
			$openai_api_key = ApiKeyEncryption::encrypt( $raw_openai_key );
		} else {
			$openai_api_key = $existing['openai_api_key'] ?? '';
		}

		return [
			'provider'       => $provider,
			'api_key'        => $api_key,
			'model'          => sanitize_text_field( $input['model'] ?? 'claude-sonnet-4-6' ),
			'openai_api_key' => $openai_api_key,
			'openai_model'   => sanitize_text_field( $input['openai_model'] ?? 'gpt-4o' ),
			'brand_voice'    => sanitize_textarea_field( $input['brand_voice'] ?? '' ),
			'tone'           => sanitize_text_field( $input['tone'] ?? 'professional' ),
			'locale'         => sanitize_text_field( $input['locale'] ?? 'de' ),
			'system_prompt'  => sanitize_textarea_field( $input['system_prompt'] ?? '' ),
		];
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'faye-seo-pilot' ) );
		}

		$settings         = get_option( 'seopilot_settings', [] );
		$provider         = $settings['provider'] ?? 'anthropic';
		$has_api_key      = ! empty( $settings['api_key'] );
		$has_openai_key   = ! empty( $settings['openai_api_key'] );
		$model            = $settings['model'] ?? 'claude-sonnet-4-6';
		$openai_model     = $settings['openai_model'] ?? 'gpt-4o';
		$brand_voice      = $settings['brand_voice'] ?? '';
		$tone             = $settings['tone'] ?? 'professional';
		$locale           = $settings['locale'] ?? 'de';
		$sys_prompt       = ! empty( $settings['system_prompt'] ) ? $settings['system_prompt'] : RequestFactory::get_default_system_prompt();

		$claude_models = [
			'claude-opus-4-6'           => 'Claude Opus 4.6 (most capable)',
			'claude-sonnet-4-6'         => 'Claude Sonnet 4.6 (recommended)',
			'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (fastest)',
		];

		$openai_models = [
			'gpt-4o'       => 'GPT-4o (recommended)',
			'gpt-4o-mini'  => 'GPT-4o mini (fastest)',
			'gpt-4-turbo'  => 'GPT-4 Turbo',
			'o3-mini'      => 'o3-mini (reasoning)',
		];

		$tones = [
			'professional'   => __( 'Professional', 'faye-seo-pilot' ),
			'friendly'       => __( 'Friendly', 'faye-seo-pilot' ),
			'authoritative'  => __( 'Authoritative', 'faye-seo-pilot' ),
			'conversational' => __( 'Conversational', 'faye-seo-pilot' ),
			'empathetic'     => __( 'Empathetic', 'faye-seo-pilot' ),
		];

		?>
		<div class="wrap seopilot-wrap">
			<h1><?php esc_html_e( 'Faye SEO Pilot — Settings', 'faye-seo-pilot' ); ?></h1>

			<div class="seopilot-notice-privacy notice notice-info">
				<p>
					<?php esc_html_e( 'Privacy notice: This plugin sends post content (title, body, meta) to the selected AI provider\'s API for analysis. No content is stored by the provider beyond what their standard API terms permit. Review your site\'s privacy policy accordingly.', 'faye-seo-pilot' ); ?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'seopilot_settings_group' ); ?>

				<table class="form-table" role="presentation">

					<!-- Provider -->
					<tr>
						<th scope="row"><label for="seopilot_provider"><?php esc_html_e( 'AI Provider', 'faye-seo-pilot' ); ?></label></th>
						<td>
							<select id="seopilot_provider" name="seopilot_settings[provider]">
								<option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude)', 'faye-seo-pilot' ); ?></option>
								<option value="openai"    <?php selected( $provider, 'openai' ); ?>><?php esc_html_e( 'OpenAI (GPT)', 'faye-seo-pilot' ); ?></option>
							</select>
						</td>
					</tr>

					<!-- Anthropic section -->
					<tr class="seopilot-provider-section seopilot-provider--anthropic">
						<th scope="row"><label for="seopilot_api_key"><?php esc_html_e( 'Anthropic API Key', 'faye-seo-pilot' ); ?></label></th>
						<td>
							<input
								type="password"
								id="seopilot_api_key"
								name="seopilot_settings[api_key]"
								value=""
								class="regular-text"
								autocomplete="new-password"
								placeholder="<?php echo $has_api_key ? esc_attr__( 'Leave blank to keep saved key', 'faye-seo-pilot' ) : esc_attr__( 'Enter your Anthropic API key', 'faye-seo-pilot' ); ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'Your Anthropic API key from console.anthropic.com. Stored encrypted. Leave blank to keep the current key.', 'faye-seo-pilot' ); ?>
							</p>
							<?php if ( $has_api_key ) : ?>
								<span class="seopilot-badge seopilot-badge--ok">&#10003; <?php esc_html_e( 'Key saved (encrypted)', 'faye-seo-pilot' ); ?></span>
							<?php else : ?>
								<span class="seopilot-badge seopilot-badge--warn"><?php esc_html_e( 'No key set', 'faye-seo-pilot' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>

					<tr class="seopilot-provider-section seopilot-provider--anthropic">
						<th scope="row"><label for="seopilot_model"><?php esc_html_e( 'Claude Model', 'faye-seo-pilot' ); ?></label></th>
						<td>
							<select id="seopilot_model" name="seopilot_settings[model]">
								<?php foreach ( $claude_models as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $model, $id ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<!-- OpenAI section -->
					<tr class="seopilot-provider-section seopilot-provider--openai">
						<th scope="row"><label for="seopilot_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'faye-seo-pilot' ); ?></label></th>
						<td>
							<input
								type="password"
								id="seopilot_openai_api_key"
								name="seopilot_settings[openai_api_key]"
								value=""
								class="regular-text"
								autocomplete="new-password"
								placeholder="<?php echo $has_openai_key ? esc_attr__( 'Leave blank to keep saved key', 'faye-seo-pilot' ) : esc_attr__( 'Enter your OpenAI API key', 'faye-seo-pilot' ); ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'Your OpenAI API key from platform.openai.com. Stored encrypted. Leave blank to keep the current key.', 'faye-seo-pilot' ); ?>
							</p>
							<?php if ( $has_openai_key ) : ?>
								<span class="seopilot-badge seopilot-badge--ok">&#10003; <?php esc_html_e( 'Key saved (encrypted)', 'faye-seo-pilot' ); ?></span>
							<?php else : ?>
								<span class="seopilot-badge seopilot-badge--warn"><?php esc_html_e( 'No key set', 'faye-seo-pilot' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>

					<tr class="seopilot-provider-section seopilot-provider--openai">
						<th scope="row"><label for="seopilot_openai_model"><?php esc_html_e( 'OpenAI Model', 'faye-seo-pilot' ); ?></label></th>
						<td>
							<select id="seopilot_openai_model" name="seopilot_settings[openai_model]">
								<?php foreach ( $openai_models as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $openai_model, $id ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<!-- Shared settings -->
					<tr>
						<th scope="row"><label for="seopilot_tone"><?php esc_html_e( 'Content Tone', 'faye-seo-pilot' ); ?></label></th>
						<td>
							<select id="seopilot_tone" name="seopilot_settings[tone]">
								<?php foreach ( $tones as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $tone, $key ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="seopilot_locale"><?php esc_html_e( 'Default Language (ISO)', 'faye-seo-pilot' ); ?></label></th>
						<td>
							<input type="text" id="seopilot_locale" name="seopilot_settings[locale]" value="<?php echo esc_attr( $locale ); ?>" class="small-text" maxlength="10" />
							<p class="description"><?php esc_html_e( 'e.g. de, en, fr — used if language cannot be auto-detected.', 'faye-seo-pilot' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="seopilot_brand_voice"><?php esc_html_e( 'Brand Voice', 'faye-seo-pilot' ); ?></label></th>
						<td>
							<textarea id="seopilot_brand_voice" name="seopilot_settings[brand_voice]" rows="3" class="large-text"><?php echo esc_textarea( $brand_voice ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Describe your brand voice to guide the AI\'s writing style. E.g. "Friendly dental practice in Freienstein. Swiss German market. Reassuring, clear, never alarmist."', 'faye-seo-pilot' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="seopilot_system_prompt"><?php esc_html_e( 'Custom System Prompt', 'faye-seo-pilot' ); ?></label></th>
						<td>
							<textarea id="seopilot_system_prompt" name="seopilot_settings[system_prompt]" rows="20" class="large-text"><?php echo esc_textarea( $sys_prompt ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Customize the system prompt sent to the AI. The default above is pre-filled — edit it to match your site. The JSON output block at the bottom must stay intact so the plugin can parse the response.', 'faye-seo-pilot' ); ?>
								&nbsp;<button type="button" id="seopilot-reset-prompt-btn" class="button button-small"><?php esc_html_e( 'Use Default', 'faye-seo-pilot' ); ?></button>
							</p>
						</td>
					</tr>

				</table>

				<?php submit_button( __( 'Save Settings', 'faye-seo-pilot' ) ); ?>
			</form>
		</div>

		<script>
		( function () {
			var select = document.getElementById( 'seopilot_provider' );
			if ( ! select ) return;

			function applyVisibility() {
				var provider = select.value;
				document.querySelectorAll( '.seopilot-provider-section' ).forEach( function ( row ) {
					row.style.display = row.classList.contains( 'seopilot-provider--' + provider ) ? '' : 'none';
				} );
			}

			select.addEventListener( 'change', applyVisibility );
			applyVisibility();
		} )();

		( function () {
			var btn = document.getElementById( 'seopilot-reset-prompt-btn' );
			if ( ! btn ) return;
			btn.addEventListener( 'click', function () {
				if ( ! window.confirm( '<?php echo esc_js( __( 'Reset to the plugin default prompt?', 'faye-seo-pilot' ) ); ?>' ) ) {
					return;
				}
				if ( typeof SeoPilot !== 'undefined' && SeoPilot.default_system_prompt ) {
					document.getElementById( 'seopilot_system_prompt' ).value = SeoPilot.default_system_prompt;
				}
			} );
		} )();
		</script>
		<?php
	}
}
