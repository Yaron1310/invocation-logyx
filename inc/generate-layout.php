<?php
/**
 * The invocation/generate-layout ability.
 *
 * This is the core of Invocation: it grounds the model in the active theme's
 * design tokens and the site's registered blocks, asks for a structured JSON
 * response, then validates and normalises the result through WordPress' native
 * block parser/serialiser. Nothing is persisted — the caller (editor sidebar,
 * REST, or an MCP agent) decides what to do with the returned markup.
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'invocation/generate-layout',
			array(
				'label'               => __( 'Generate Layout', 'invocation' ),
				'description'         => __( 'Generates a complete, on-theme Gutenberg block layout from a natural-language prompt, using only the blocks registered on this site and the active theme\'s design tokens. Returns block markup ready to insert into the editor.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'prompt'           => array(
							'type'        => 'string',
							'description' => 'What to build, in natural language (e.g. "a hero section with a heading, intro paragraph and a call-to-action button, followed by a three-column features list").',
							'minLength'   => 1,
						),
						'postTitle'        => array(
							'type'        => 'string',
							'description' => 'Optional title of the page being authored, for additional context.',
						),
						'tone'             => array(
							'type'        => 'string',
							'description' => 'Optional writing tone.',
							'enum'        => array( 'professional', 'casual', 'creative', 'minimal', 'bold' ),
						),
						'audience'         => array(
							'type'        => 'string',
							'description' => 'Optional description of the target audience.',
						),
						'useMedia'         => array(
							'type'        => 'boolean',
							'description' => 'Whether to include real images from the media library. Default true.',
							'default'     => true,
						),
						'useInternalLinks' => array(
							'type'        => 'boolean',
							'description' => 'Whether to offer real internal links (pages/posts) for the AI to link to. Default true.',
							'default'     => true,
						),
						'usePatterns'      => array(
							'type'        => 'boolean',
							'description' => 'Whether to offer the site\'s registered section patterns as composition material. Default true.',
							'default'     => true,
						),
						'scope'            => array(
							'type'        => 'string',
							'description' => 'Generation scope: "section" (one cohesive section, default), "full-page" (a complete multi-section page), or "fill-from-pattern" (fill a chosen pattern — requires patternName).',
							'enum'        => array( 'section', 'full-page', 'fill-from-pattern' ),
							'default'     => 'section',
						),
						'patternName'      => array(
							'type'        => 'string',
							'description' => 'Slug of a registered pattern to fill (e.g. "twentytwentyfive/cta-centered-heading"). Implies scope "fill-from-pattern".',
						),
					),
					'required'             => array( 'prompt' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'blockMarkup' => array(
							'type'        => 'string',
							'description' => 'Gutenberg block markup, ready to pass to wp.blocks.parse / parse_blocks.',
						),
						'summary'     => array(
							'type'        => 'string',
							'description' => 'Short human-readable summary of what was generated.',
						),
						'warnings'    => array(
							'type'        => 'array',
							'description' => 'Names of any blocks the model used that are not registered on this site.',
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => 'invocation_ability_generate_layout',
				'permission_callback' => static fn (): bool => current_user_can( invocation_generation_capability() ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						// Generative action: takes input and is non-idempotent, so it is
						// exposed over REST as POST (the Abilities REST layer maps
						// readonly:true to GET-only). It still modifies nothing in WordPress.
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}
);

/**
 * Execute callback for invocation/generate-layout.
 *
 * @param array<string, mixed> $input Validated input (prompt required).
 * @return array<string, mixed>|WP_Error Structured layout or an error.
 */
function invocation_ability_generate_layout( array $input = array() ) {
	$prompt = trim( (string) ( $input['prompt'] ?? '' ) );
	if ( '' === $prompt ) {
		return new WP_Error( 'invocation_missing_prompt', __( 'A prompt is required.', 'invocation' ) );
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return new WP_Error( 'invocation_no_ai_client', __( 'The WordPress AI Client is not available.', 'invocation' ) );
	}

	$scope        = (string) ( $input['scope'] ?? 'section' );
	$pattern_name = trim( (string) ( $input['patternName'] ?? '' ) );
	$fill         = 'fill-from-pattern' === $scope || '' !== $pattern_name;

	$pattern = null;
	if ( $fill ) {
		if ( '' === $pattern_name ) {
			return new WP_Error( 'invocation_missing_pattern', __( 'A patternName is required to fill from a pattern.', 'invocation' ) );
		}
		$pattern = function_exists( 'invocation_get_pattern_by_name' ) ? invocation_get_pattern_by_name( $pattern_name ) : null;
		if ( null === $pattern ) {
			return new WP_Error( 'invocation_pattern_not_found', __( 'The requested pattern was not found.', 'invocation' ) );
		}
		$scope = 'fill-from-pattern';
	}

	// When filling a specific pattern, don't also inject the whole pattern
	// catalogue — the one pattern is the scaffold, so this trims context too.
	$ctx_input = $input;
	if ( $fill ) {
		$ctx_input['usePatterns'] = false;
	}

	$ctx    = invocation_gather_context( $prompt, $ctx_input );
	$system = invocation_build_layout_system_instruction( $ctx, $input, $scope );

	$user_prompt = $prompt;
	if ( $fill ) {
		$user_prompt = "Request:\n" . $prompt
			. "\n\nFill the following section pattern. Keep its block structure and layout exactly; replace placeholder text and headings with original content for the request. Return the COMPLETE filled markup.\n\n--- PATTERN: " . $pattern['title'] . " ---\n"
			. $pattern['content']
			. "\n---";
	}

	$json_schema = array(
		'type'                 => 'object',
		'properties'           => array(
			'blockMarkup' => array(
				'type'        => 'string',
				'description' => 'The complete Gutenberg block markup for the requested layout, using HTML block comment delimiters (e.g. <!-- wp:heading --> ... <!-- /wp:heading -->).',
			),
			'summary'     => array(
				'type'        => 'string',
				'description' => 'One or two sentences describing the generated layout.',
			),
		),
		// Strict structured-output providers (e.g. OpenAI) require every key in
		// `properties` to be listed in `required`, so both are mandatory here.
		'required'             => array( 'blockMarkup', 'summary' ),
		'additionalProperties' => false,
	);

	$response = invocation_generate_text( $user_prompt, $system, $json_schema );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = json_decode( (string) $response, true );
	if ( ! is_array( $data ) || empty( $data['blockMarkup'] ) ) {
		return new WP_Error( 'invocation_invalid_response', __( 'The AI response did not contain usable block markup.', 'invocation' ) );
	}

	$final = invocation_finalize_markup( (string) $data['blockMarkup'], $ctx );
	if ( is_wp_error( $final ) ) {
		return $final;
	}

	return array(
		'blockMarkup' => $final['blockMarkup'],
		'summary'     => (string) ( $data['summary'] ?? '' ),
		'warnings'    => $final['warnings'],
	);
}

/**
 * Build the system instruction for generating a layout.
 *
 * Task-specific framing on top of the shared grounding context.
 *
 * @param array<string, mixed> $ctx   Output of invocation_gather_context().
 * @param array<string, mixed> $input Ability input.
 * @param string               $scope Generation scope (section|full-page|fill-from-pattern).
 * @return string
 */
function invocation_build_layout_system_instruction( array $ctx, array $input, string $scope = 'section' ): string {
	$tone     = (string) ( $input['tone'] ?? 'professional' );
	$audience = (string) ( $input['audience'] ?? 'a general audience' );
	$title    = (string) ( $input['postTitle'] ?? '' );

	$lines = array(
		'You are an expert WordPress content designer who builds beautiful, accessible page layouts using Gutenberg blocks.',
		'',
		'Output ONLY valid Gutenberg block markup (HTML comment delimiters such as <!-- wp:heading {"level":2} --><h2>...</h2><!-- /wp:heading -->), placed in the "blockMarkup" field of your JSON response. Do not include explanations or code fences in that field.',
		'',
	);

	$lines = array_merge( $lines, invocation_layout_scope_lines( $scope ), array( '' ) );

	$lines[] = 'Layout guidance:';
	$lines[] = '- Prefer core layout blocks (core/group, core/columns, core/column, core/cover, core/buttons) to create structured, responsive sections.';
	$lines[] = '- Establish a clear heading hierarchy (a single h1 or h2 lead, then subsections).';
	$lines[] = '';

	$lines = array_merge( $lines, invocation_context_grounding_lines( $ctx ) );

	$lines[] = '';
	$lines[] = 'Writing tone: ' . $tone . '. Target audience: ' . $audience . '.';
	if ( '' !== $title ) {
		$lines[] = 'The page title is: "' . $title . '".';
	}

	return implode( "\n", $lines );
}

/**
 * Scope-specific guidance lines.
 *
 * @param string $scope Generation scope.
 * @return list<string>
 */
function invocation_layout_scope_lines( string $scope ): array {
	switch ( $scope ) {
		case 'full-page':
			return array( 'Scope: build a COMPLETE page composed of several distinct sections (e.g. a hero, supporting sections, and a call to action) in a sensible order.' );
		case 'fill-from-pattern':
			return array(
				'Scope: you are FILLING the section pattern provided in the user message.',
				'- Keep the pattern\'s block structure, block types, and layout intact; do not add or remove sections.',
				'- Replace placeholder/sample text and headings with original content for the request.',
			);
		case 'section':
		default:
			return array( 'Scope: build a single, cohesive section (usually one top-level group block) unless the request explicitly asks for a full page.' );
	}
}
