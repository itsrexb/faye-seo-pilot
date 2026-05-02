<?php
/**
 * Builds an HTML inline diff between two strings.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DiffBuilder {

	/**
	 * Return an HTML string highlighting differences between $old and $new.
	 * Deleted text is wrapped in <del>, added text in <ins>.
	 */
	public function inline_diff( string $old, string $new ): string {
		if ( $old === $new ) {
			return esc_html( $old );
		}

		// For HTML content, do a paragraph-level diff.
		if ( $this->looks_like_html( $old ) || $this->looks_like_html( $new ) ) {
			return $this->html_block_diff( $old, $new );
		}

		return $this->word_diff( $old, $new );
	}

	/**
	 * Word-level diff for plain text.
	 */
	private function word_diff( string $old, string $new ): string {
		$old_words = preg_split( '/(\s+)/', $old, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: [];
		$new_words = preg_split( '/(\s+)/', $new, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: [];

		$ops = $this->lcs_diff( $old_words, $new_words );
		$out = '';

		foreach ( $ops as $op ) {
			[ $type, $text ] = $op;
			$escaped = esc_html( $text );

			if ( $type === '=' ) {
				$out .= $escaped;
			} elseif ( $type === '-' ) {
				$out .= '<del>' . $escaped . '</del>';
			} else {
				$out .= '<ins>' . $escaped . '</ins>';
			}
		}

		return $out;
	}

	/**
	 * Side-by-side comparison for HTML content — too complex to inline diff safely,
	 * so we return a labelled old/new block.
	 */
	private function html_block_diff( string $old, string $new ): string {
		return '<div class="seopilot-diff-html">'
			. '<div class="seopilot-diff-old"><strong>' . esc_html__( 'Current:', 'seo-pilot-pro-to-faye-seo-pilot' ) . '</strong><div class="seopilot-diff-content">' . wp_kses_post( $old ) . '</div></div>'
			. '<div class="seopilot-diff-new"><strong>' . esc_html__( 'Suggested:', 'seo-pilot-pro-to-faye-seo-pilot' ) . '</strong><div class="seopilot-diff-content">' . wp_kses_post( $new ) . '</div></div>'
			. '</div>';
	}

	/**
	 * Compute diff operations using LCS.
	 * Returns array of [type, text] where type is '=', '-', or '+'.
	 */
	private function lcs_diff( array $old, array $new ): array {
		$m     = count( $old );
		$n     = count( $new );
		$table = array_fill( 0, $m + 1, array_fill( 0, $n + 1, 0 ) );

		for ( $i = 1; $i <= $m; $i++ ) {
			for ( $j = 1; $j <= $n; $j++ ) {
				if ( $old[ $i - 1 ] === $new[ $j - 1 ] ) {
					$table[ $i ][ $j ] = $table[ $i - 1 ][ $j - 1 ] + 1;
				} else {
					$table[ $i ][ $j ] = max( $table[ $i - 1 ][ $j ], $table[ $i ][ $j - 1 ] );
				}
			}
		}

		$ops = [];
		$i   = $m;
		$j   = $n;

		while ( $i > 0 || $j > 0 ) {
			if ( $i > 0 && $j > 0 && $old[ $i - 1 ] === $new[ $j - 1 ] ) {
				array_unshift( $ops, [ '=', $old[ $i - 1 ] ] );
				$i--;
				$j--;
			} elseif ( $j > 0 && ( $i === 0 || $table[ $i ][ $j - 1 ] >= $table[ $i - 1 ][ $j ] ) ) {
				array_unshift( $ops, [ '+', $new[ $j - 1 ] ] );
				$j--;
			} else {
				array_unshift( $ops, [ '-', $old[ $i - 1 ] ] );
				$i--;
			}
		}

		return $ops;
	}

	private function looks_like_html( string $text ): bool {
		return (bool) preg_match( '/<(p|h[1-6]|ul|ol|li|div|span|strong|em|a)[^>]*>/i', $text );
	}
}
