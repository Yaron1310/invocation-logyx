<?php
/**
 * The invocation/refine-block ability.
 *
 * Takes existing Gutenberg block markup plus a natural-language instruction and
 * returns a revised version. Shares all grounding/validation with generate-layout
 * via inc/context.php, so it stays on-theme, uses real media and internal links,
 * and repairs any guessed links.
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
			'invocation/refine-block',
			array(
				'label'               => __( 'Refine Block', 'invocation' ),
				'description'         => __( 'Refines existing Gutenberg block markup according to a natural-language instruction, staying on-theme and using only registered blocks, real media, and real internal links. Returns the revised block markup.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'blockMarkup'      => array(
							'type'        => 'string',
							'description' => 'The current Gutenberg block markup to refine.',
							'minLength'   => 1,
						),
						'instruction'      => array(
							'type'        => 'string',
							'description' => 'How the block(s) should be changed (e.g. "make the heading punchier and shorten the paragraph").',
							'minLength'   => 1,
						),
						'tone'             => array(
							'type'        => 'string',
							'description' => 'Optional writing tone.',
							'enum'        => array( 'professional', 'casual', 'creative', 'minimal', 'bold' ),
						),
						'useMedia'         => array(
							'type'        => 'boolean',
							'description' => 'Whether to offer real images from the media library. Default true.',
							'default'     => true,
						),
						'useInternalLinks' => array(
							'type'        => 'boolean',
							'description' => 'Whether to offer real internal links (pages/posts). Default true.',
							'default'     => true,
						),
						'usePatterns'      => array(
							'type'        => 'boolean',
							'description' => 'Whether to offer the site\'s registered section patterns. Default true.',
							'default'     => true,
						),
					),
					'required'             => array( 'blockMarkup', 'instruction' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'blockMarkup' => array(
							'type'        => 'string',
							'description' => 'The revised Gutenberg block markup.',
						),
						'summary'     => array(
							'type'        => 'string',
							'description' => 'Short summary of what changed.',
						),
						'warnings'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => 'invocation_ability_refine_block',
				'permission_callback' => static fn (): bool => current_user_can( invocation_generation_capability() ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
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
 * Execute callback for invocation/refine-block.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed>|WP_Error Revised markup or an error.
 */
function invocation_ability_refine_block( array $input = array() ) {
	$markup      = trim( (string) ( $input['blockMarkup'] ?? '' ) );
	$instruction = trim( (string) ( $input['instruction'] ?? '' ) );

	if ( '' === $markup ) {
		return new WP_Error( 'invocation_missing_markup', __( 'Block markup is required.', 'invocation' ), array( 'status' => 400 ) );
	}
	if ( '' === $instruction ) {
		return new WP_Error( 'invocation_missing_instruction', __( 'An instruction is required.', 'invocation' ), array( 'status' => 400 ) );
	}
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return new WP_Error( 'invocation_no_ai_client', __( 'The WordPress AI Client is not available.', 'invocation' ) );
	}

	$ctx         = invocation_gather_context( $instruction, $input );
	$system      = invocation_build_refine_system_instruction( $ctx, $input );
	$user_prompt = "Instruction:\n" . $instruction
		. "\n\nRefine the following Gutenberg block markup accordingly and return the COMPLETE revised markup.\n\n---\n"
		. $markup
		. "\n---";

	$json_schema = array(
		'type'                 => 'object',
		'properties'           => array(
			'blockMarkup' => array(
				'type'        => 'string',
				'description' => 'The complete revised Gutenberg block markup, using HTML block comment delimiters.',
			),
			'summary'     => array(
				'type'        => 'string',
				'description' => 'One sentence describing what changed.',
			),
		),
		'required'             => array( 'blockMarkup', 'summary' ),
		'additionalProperties' => false,
	);

	$response = invocation_generate_text( $user_prompt, $system, $json_schema );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = json_decode( (string) $response, true );
	if ( ! is_array( $data ) || empty( $data['blockMarkup'] ) ) {
		return new WP_Error( 'invocation_invalid_response', __( 'The AI response did not contain usable block markup.', 'invocation' ), array( 'status' => 502 ) );
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
 * Build the system instruction for refining blocks.
 *
 * Task-specific framing on top of the shared grounding context.
 *
 * @param array<string, mixed> $ctx   Output of invocation_gather_context().
 * @param array<string, mixed> $input Ability input.
 * @return string
 */
function invocation_build_refine_system_instruction( array $ctx, array $input ): string {
	$tone = (string) ( $input['tone'] ?? 'professional' );

	$lines = array(
		'You are an expert WordPress content designer who refines existing Gutenberg block markup.',
		'',
		'Output ONLY valid Gutenberg block markup (HTML comment delimiters) in the "blockMarkup" field of your JSON response — no explanations or code fences in that field.',
		'',
		'Refine guidance:',
		'- Apply the user\'s instruction precisely; do not make unrelated changes.',
		'- Preserve the overall block structure and types unless the instruction requires otherwise.',
		'',
	);

	$lines = array_merge( $lines, invocation_context_grounding_lines( $ctx ) );

	$lines[] = '';
	$lines[] = 'Writing tone: ' . $tone . '.';

	return implode( "\n", $lines );
}
