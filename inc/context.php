<?php
/**
 * Shared generation context for Invocation abilities — a small provider system.
 *
 * Each "context provider" knows how to (a) gather some grounding data and (b)
 * render it into system-instruction lines. generate-layout and refine-block both
 * build on this: gather everything once, render it, and finalise model output.
 * New providers (patterns, a site brief, …) register in one place and are
 * filterable via `invocation_context_providers`, including for premium add-ons.
 *
 * A provider is an array:
 *   'enabled' => fn( array $input ): bool      // whether to include it this run
 *   'gather'  => fn( array $args ): mixed       // $args = [ 'query' => string, 'input' => array ]
 *   'render'  => callable( mixed $data, array $input ): list<string>
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability required to run the generative abilities (generate-layout,
 * refine-block). Filterable so site owners can raise the floor — e.g. to limit
 * who can spend the configured AI provider's budget.
 */
function invocation_generation_capability(): string {
	/**
	 * Filters the capability required to run Invocation's generative abilities.
	 *
	 * @param string $capability Default 'edit_posts'.
	 */
	return (string) apply_filters( 'invocation_generation_capability', 'edit_posts' );
}

/**
 * The registered context providers, in render order.
 *
 * @return array<string, array<string, mixed>>
 */
function invocation_context_providers(): array {
	$providers = array(
		'theme'    => array(
			'enabled' => static fn ( array $input ): bool => true,
			'gather'  => static fn ( array $args ) => invocation_ability_get_theme_context(),
			'render'  => 'invocation_render_theme_context',
		),
		'blocks'   => array(
			'enabled' => static fn ( array $input ): bool => true,
			'gather'  => static fn ( array $args ) => invocation_ability_list_blocks()['blocks'],
			'render'  => 'invocation_render_blocks_context',
		),
		'patterns' => array(
			'enabled' => static fn ( array $input ): bool => ! array_key_exists( 'usePatterns', $input ) || (bool) $input['usePatterns'],
			'gather'  => static function ( array $args ) {
				if ( ! function_exists( 'invocation_get_patterns_for_context' ) ) {
					return array();
				}
				// Rank a pool of patterns by relevance to the prompt and keep the top few.
				return invocation_rank_patterns( invocation_get_patterns_for_context( 200 ), (string) $args['query'], 12 );
			},
			'render'  => 'invocation_render_patterns_context',
		),
		'media'    => array(
			'enabled' => static fn ( array $input ): bool => ( ! array_key_exists( 'useMedia', $input ) || (bool) $input['useMedia'] ) && current_user_can( 'upload_files' ),
			'gather'  => static fn ( array $args ) => function_exists( 'invocation_ability_search_media' )
				? invocation_ability_search_media(
					array(
						'query' => $args['query'],
						'limit' => 8,
					)
				)['items']
				: array(),
			'render'  => 'invocation_render_media_context',
		),
		'links'    => array(
			'enabled' => static fn ( array $input ): bool => ! array_key_exists( 'useInternalLinks', $input ) || (bool) $input['useInternalLinks'],
			'gather'  => static fn ( array $args ) => function_exists( 'invocation_ability_search_internal_links' )
				? invocation_ability_search_internal_links(
					array(
						'query' => '',
						'limit' => 15,
					)
				)['items']
				: array(),
			'render'  => 'invocation_render_links_context',
		),
	);

	/**
	 * Filters the Invocation context providers.
	 *
	 * @param array<string, array<string, mixed>> $providers Provider definitions keyed by slug.
	 */
	return apply_filters( 'invocation_context_providers', $providers );
}

/**
 * Gather grounding context by running every enabled provider's gatherer.
 *
 * @param string               $query Query used for relevance (usually the prompt/instruction).
 * @param array<string, mixed> $input Ability input (read for provider enable flags).
 * @return array{input: array<string, mixed>, query: string, data: array<string, mixed>, enabled: list<string>}
 */
function invocation_gather_context( string $query, array $input ): array {
	$data    = array();
	$enabled = array();

	foreach ( invocation_context_providers() as $key => $provider ) {
		if ( isset( $provider['enabled'] ) && ! ( $provider['enabled'] )( $input ) ) {
			continue;
		}
		$enabled[]    = $key;
		$data[ $key ] = isset( $provider['gather'] )
			? ( $provider['gather'] )(
				array(
					'query' => $query,
					'input' => $input,
				)
			)
			: null;
	}

	return array(
		'input'   => $input,
		'query'   => $query,
		'data'    => $data,
		'enabled' => $enabled,
	);
}

/**
 * Render all enabled providers into system-instruction lines.
 *
 * @param array<string, mixed> $ctx Output of invocation_gather_context().
 * @return list<string>
 */
function invocation_context_grounding_lines( array $ctx ): array {
	$providers = invocation_context_providers();
	$lines     = array();

	foreach ( (array) ( $ctx['enabled'] ?? array() ) as $key ) {
		if ( empty( $providers[ $key ]['render'] ) ) {
			continue;
		}
		$section = call_user_func( $providers[ $key ]['render'], $ctx['data'][ $key ] ?? null, $ctx['input'] ?? array() );
		if ( ! empty( $section ) ) {
			$lines = array_merge( $lines, $section, array( '' ) );
		}
	}

	return $lines;
}

/**
 * Run a structured-JSON generation through the WP AI client.
 *
 * Centralises the prompt-builder chain and raises the request timeout, since
 * full-page generations routinely exceed the 30s core default.
 *
 * @param string               $user_prompt System will receive $system; this is the user message.
 * @param string               $system      System instruction.
 * @param array<string, mixed> $schema      JSON schema for the response.
 * @return string|WP_Error Raw JSON text or an error.
 */
function invocation_generate_text( string $user_prompt, string $system, array $schema ) {
	$raise_timeout = static fn (): float => 120.0;
	add_filter( 'wp_ai_client_default_request_timeout', $raise_timeout );
	try {
		return wp_ai_client_prompt( $user_prompt )
			->using_system_instruction( $system )
			->as_json_response( $schema )
			->generate_text();
	} finally {
		remove_filter( 'wp_ai_client_default_request_timeout', $raise_timeout );
	}
}

/**
 * Validate and normalise block markup: ensure it parses to real blocks and
 * re-serialise it, collecting the names of any blocks not registered here.
 *
 * Shared by generated layouts and the save-pattern ability, so both apply the
 * same validation. No context is required — this is the transport-agnostic half
 * of finalisation, without the internal-link repair.
 *
 * @param string $markup Raw block markup.
 * @return array{markup: string, warnings: list<string>}|WP_Error
 */
function invocation_normalize_markup( string $markup ) {
	$parsed = parse_blocks( $markup );
	if ( empty( array_filter( $parsed, static fn ( array $b ): bool => ! empty( $b['blockName'] ) ) ) ) {
		return new WP_Error( 'invocation_no_blocks', __( 'The markup did not contain any valid blocks.', 'invocation' ) );
	}

	$warnings = array();
	invocation_collect_unregistered_blocks( $parsed, WP_Block_Type_Registry::get_instance(), $warnings );

	return array(
		'markup'   => serialize_blocks( $parsed ),
		'warnings' => array_values( array_unique( $warnings ) ),
	);
}

/**
 * Validate, normalise and repair model-produced block markup.
 *
 * @param string               $markup Raw block markup from the model.
 * @param array<string, mixed> $ctx    Output of invocation_gather_context().
 * @return array{blockMarkup: string, warnings: list<string>}|WP_Error
 */
function invocation_finalize_markup( string $markup, array $ctx ) {
	$normalized = invocation_normalize_markup( $markup );
	if ( is_wp_error( $normalized ) ) {
		return $normalized;
	}

	$out   = $normalized['markup'];
	$links = $ctx['data']['links'] ?? array();
	if ( ! empty( $links ) && function_exists( 'invocation_repair_internal_links' ) ) {
		list( $out ) = invocation_repair_internal_links( $out, $links );
	}

	return array(
		'blockMarkup' => $out,
		'warnings'    => $normalized['warnings'],
	);
}

/**
 * Render: theme design tokens.
 *
 * @param array<string, mixed> $theme Theme context.
 * @param array<string, mixed> $input Ability input.
 * @return list<string>
 */
function invocation_render_theme_context( $theme, array $input ): array {
	$theme       = is_array( $theme ) ? $theme : array();
	$color_slugs = wp_list_pluck( $theme['colors'] ?? array(), 'slug' );
	$font_slugs  = wp_list_pluck( $theme['fontFamilies'] ?? array(), 'slug' );

	$lines = array();
	if ( $color_slugs ) {
		$lines[] = 'Theme color slugs (use for color attributes, e.g. {"backgroundColor":"' . $color_slugs[0] . '"}): ' . implode( ', ', $color_slugs ) . '.';
	}
	if ( $font_slugs ) {
		$lines[] = 'Theme font family slugs (e.g. {"fontFamily":"' . $font_slugs[0] . '"}): ' . implode( ', ', $font_slugs ) . '.';
	}
	if ( ! empty( $theme['layout']['contentSize'] ) ) {
		$lines[] = 'Theme content width: ' . $theme['layout']['contentSize'] . ' (wide: ' . (string) ( $theme['layout']['wideSize'] ?? '' ) . ').';
	}
	if ( $lines ) {
		array_unshift( $lines, 'Theme design tokens — stay on-theme:' );
	}
	return $lines;
}

/**
 * Render: available blocks, separating site-specific (custom) blocks from core.
 *
 * @param array<int, array<string, mixed>> $blocks Block list from list-blocks.
 * @param array<string, mixed>             $input  Ability input.
 * @return list<string>
 */
function invocation_render_blocks_context( $blocks, array $input ): array {
	$blocks          = is_array( $blocks ) ? $blocks : array();
	$registered_core = array();
	$custom          = array();

	foreach ( $blocks as $block ) {
		$name = (string) ( $block['name'] ?? '' );
		if ( '' === $name ) {
			continue;
		}
		if ( str_starts_with( $name, 'core/' ) ) {
			$registered_core[ $name ] = true;
		} else {
			$title    = (string) ( $block['title'] ?? $name );
			$custom[] = $name . ( '' !== $title ? ' (' . $title . ')' : '' );
		}
	}

	// The model already knows core blocks, so we don't enumerate all ~110 — we
	// only surface the layout/content ones worth preferring (filtered to those
	// actually registered, in case a site has disabled some). Custom blocks, which
	// the model can't know, are always listed in full.
	$preferred_core = array(
		'core/group',
		'core/columns',
		'core/column',
		'core/cover',
		'core/media-text',
		'core/heading',
		'core/paragraph',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/pullquote',
		'core/image',
		'core/gallery',
		'core/buttons',
		'core/button',
		'core/separator',
		'core/spacer',
		'core/details',
		'core/table',
		'core/video',
		'core/audio',
		'core/embed',
		'core/social-links',
		'core/social-link',
		'core/search',
		'core/code',
		'core/html',
		'core/site-logo',
		'core/navigation',
		'core/more',
		'core/nextpage',
	);
	$preferred      = array_values( array_filter( $preferred_core, static fn ( string $n ): bool => isset( $registered_core[ $n ] ) ) );

	$lines = array( 'Blocks — use only blocks registered on this site; do not invent block types.' );
	if ( $custom ) {
		$lines[] = 'Site-specific / custom blocks — unique to this site; PREFER them when they fit the content: ' . implode( ', ', $custom ) . '.';
	}
	if ( $preferred ) {
		$lines[] = 'Standard WordPress core blocks are available; prefer these for layout & content: ' . implode( ', ', $preferred ) . '.';
	}
	return $lines;
}

/**
 * Render: available section patterns.
 *
 * @param array<int, array<string, mixed>> $patterns Pattern catalogue.
 * @param array<string, mixed>             $input    Ability input.
 * @return list<string>
 */
function invocation_render_patterns_context( $patterns, array $input ): array {
	$patterns = is_array( $patterns ) ? $patterns : array();
	if ( empty( $patterns ) ) {
		return array();
	}

	$lines = array( 'Available section patterns — these are designed, reusable sections for this site. PREFER composing the layout from patterns that fit the request, adapting their structure and filling them with relevant content:' );
	foreach ( $patterns as $pattern ) {
		$title   = '' !== (string) ( $pattern['title'] ?? '' ) ? (string) $pattern['title'] : (string) ( $pattern['name'] ?? '' );
		$cats    = ! empty( $pattern['categories'] ) ? ' [' . implode( ', ', (array) $pattern['categories'] ) . ']' : '';
		$used    = ! empty( $pattern['blocks'] ) ? ' — blocks: ' . implode( ', ', (array) $pattern['blocks'] ) : '';
		$lines[] = sprintf( '- %s%s%s', $title, $cats, $used );
	}
	return $lines;
}

/**
 * Render: available media images.
 *
 * @param array<int, array<string, mixed>> $media Media catalogue.
 * @param array<string, mixed>             $input Ability input.
 * @return list<string>
 */
function invocation_render_media_context( $media, array $input ): array {
	$media = is_array( $media ) ? $media : array();
	if ( empty( $media ) ) {
		return array( 'No media library images are available. Do not add image blocks; never invent image URLs.' );
	}

	$lines = array( 'Available images — never invent image URLs; preserve any image already present; if you add an image use core/image with ONLY one of these (exact id + url + alt):' );
	foreach ( $media as $item ) {
		$lines[] = sprintf(
			'- id %d | %dx%d | "%s" | url: %s',
			(int) $item['id'],
			(int) $item['width'],
			(int) $item['height'],
			'' !== (string) $item['alt'] ? (string) $item['alt'] : (string) $item['title'],
			(string) $item['url']
		);
	}
	return $lines;
}

/**
 * Render: available internal links.
 *
 * @param array<int, array<string, mixed>> $links Internal-link catalogue.
 * @param array<string, mixed>             $input Ability input.
 * @return list<string>
 */
function invocation_render_links_context( $links, array $input ): array {
	$links = is_array( $links ) ? $links : array();
	if ( empty( $links ) ) {
		return array();
	}

	$lines = array( 'Available internal links — never invent internal URLs; preserve existing links; for new links to this site use ONLY these:' );
	foreach ( $links as $link ) {
		$lines[] = sprintf( '- "%s" (%s): %s', (string) $link['title'], (string) $link['type'], (string) $link['url'] );
	}
	return $lines;
}

/**
 * Recursively collect block names that are not registered on this site.
 *
 * @param array<int, array<string, mixed>> $blocks   Parsed blocks.
 * @param WP_Block_Type_Registry           $registry Block registry.
 * @param list<string>                     $warnings Accumulator (by reference).
 */
function invocation_collect_unregistered_blocks( array $blocks, WP_Block_Type_Registry $registry, array &$warnings ): void {
	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;
		if ( is_string( $name ) && '' !== $name && ! $registry->is_registered( $name ) ) {
			$warnings[] = $name;
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			invocation_collect_unregistered_blocks( $block['innerBlocks'], $registry, $warnings );
		}
	}
}

/**
 * Recursively collect the distinct block names used in parsed blocks.
 *
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 * @param list<string>                     $names  Accumulator (by reference).
 */
function invocation_collect_block_names( array $blocks, array &$names ): void {
	foreach ( $blocks as $block ) {
		if ( ! empty( $block['blockName'] ) ) {
			$names[] = (string) $block['blockName'];
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			invocation_collect_block_names( $block['innerBlocks'], $names );
		}
	}
}
