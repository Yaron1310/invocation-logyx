<?php
/**
 * Site Brief: a structured, user-editable summary of the site that grounds every
 * generation in the site's purpose, audience, voice and offerings.
 *
 * Stored in the `invocation_site_brief` option (exposed via /wp/v2/settings so
 * the admin app can read/write it), produced by the invocation/gather-site-context
 * ability, and injected into prompts as a context provider.
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INVOCATION_SITE_BRIEF_OPTION = 'invocation_site_brief';

/**
 * Default (empty) brief shape.
 *
 * @return array<string, mixed>
 */
function invocation_default_site_brief(): array {
	return array(
		'purpose'     => '',
		'audience'    => '',
		'toneVoice'   => '',
		'offerings'   => array(),
		'keyTerms'    => array(),
		'avoid'       => array(),
		'generatedAt' => '',
	);
}

/**
 * Read the saved site brief.
 *
 * @return array<string, mixed>
 */
function invocation_get_site_brief(): array {
	$brief = get_option( INVOCATION_SITE_BRIEF_OPTION, array() );
	return wp_parse_args( is_array( $brief ) ? $brief : array(), invocation_default_site_brief() );
}

/**
 * Whether the brief has any meaningful content.
 */
function invocation_has_site_brief(): bool {
	$brief = invocation_get_site_brief();
	foreach ( array( 'purpose', 'audience', 'toneVoice' ) as $key ) {
		if ( '' !== trim( (string) $brief[ $key ] ) ) {
			return true;
		}
	}
	return ! empty( $brief['offerings'] ) || ! empty( $brief['keyTerms'] );
}

/**
 * Sanitize a site brief before it is stored (defense in depth for direct
 * update_option writes as well as REST writes).
 *
 * @param mixed $value Incoming value.
 * @return array<string, mixed>
 */
function invocation_sanitize_site_brief( $value ): array {
	$value    = is_array( $value ) ? $value : array();
	$defaults = invocation_default_site_brief();
	$clean    = array();

	foreach ( array( 'purpose', 'audience', 'toneVoice', 'generatedAt' ) as $key ) {
		$clean[ $key ] = isset( $value[ $key ] ) ? sanitize_textarea_field( (string) $value[ $key ] ) : $defaults[ $key ];
	}

	foreach ( array( 'offerings', 'keyTerms', 'avoid' ) as $key ) {
		$items         = ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) ? $value[ $key ] : array();
		$clean[ $key ] = array_values(
			array_filter(
				array_map( static fn ( $item ): string => sanitize_text_field( (string) $item ), $items )
			)
		);
	}

	return $clean;
}

/**
 * Register the brief option (also exposing it via the REST settings endpoint).
 */
add_action(
	'init',
	static function (): void {
		register_setting(
			'options',
			INVOCATION_SITE_BRIEF_OPTION,
			array(
				'type'              => 'object',
				'default'           => invocation_default_site_brief(),
				'sanitize_callback' => 'invocation_sanitize_site_brief',
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'properties'           => array(
							'purpose'     => array( 'type' => 'string' ),
							'audience'    => array( 'type' => 'string' ),
							'toneVoice'   => array( 'type' => 'string' ),
							'offerings'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'keyTerms'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'avoid'       => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'generatedAt' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
			)
		);
	}
);

/**
 * Register the gather-site-context ability.
 */
add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'invocation/gather-site-context',
			array(
				'label'               => __( 'Gather Site Context', 'invocation' ),
				'description'         => __( 'Analyzes the site (identity plus a sample of published pages and posts) and produces a structured Site Brief — purpose, audience, brand voice, offerings, key terms and things to avoid — saving it for use in future generations.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'maxPages' => array(
							'type'        => 'integer',
							'description' => 'How many recent published pages/posts to analyze.',
							'minimum'     => 1,
							'maximum'     => 50,
							'default'     => 15,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'purpose'     => array( 'type' => 'string' ),
						'audience'    => array( 'type' => 'string' ),
						'toneVoice'   => array( 'type' => 'string' ),
						'offerings'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'keyTerms'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'avoid'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'generatedAt' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => 'invocation_ability_gather_site_context',
				'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						// Persists the brief option, so it modifies the environment.
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
 * Execute callback for invocation/gather-site-context.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed>|WP_Error The generated (and saved) brief.
 */
function invocation_ability_gather_site_context( array $input = array() ) {
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return new WP_Error( 'invocation_no_ai_client', __( 'The WordPress AI Client is not available.', 'invocation' ) );
	}

	$max    = max( 1, min( 50, (int) ( $input['maxPages'] ?? 15 ) ) );
	$corpus = invocation_build_site_corpus( $max );

	$system = implode(
		"\n",
		array(
			'You analyze a WordPress site and produce a concise brand/content brief as JSON.',
			'Be specific and grounded in the provided content — do not invent facts. Keep each text field to 1-3 sentences; keep list items short (a few words each). Leave a field empty if the content does not support it.',
		)
	);

	$schema = array(
		'type'                 => 'object',
		'properties'           => array(
			'purpose'   => array(
				'type'        => 'string',
				'description' => 'What the site is for, in 1-2 sentences.',
			),
			'audience'  => array(
				'type'        => 'string',
				'description' => 'Who the site is for.',
			),
			'toneVoice' => array(
				'type'        => 'string',
				'description' => 'The brand voice / writing tone.',
			),
			'offerings' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Key products, services, or topics.',
			),
			'keyTerms'  => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Preferred terminology / vocabulary to use.',
			),
			'avoid'     => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Words, claims, or styles to avoid.',
			),
		),
		// OpenAI strict mode requires every property in `required`.
		'required'             => array( 'purpose', 'audience', 'toneVoice', 'offerings', 'keyTerms', 'avoid' ),
		'additionalProperties' => false,
	);

	$user_prompt = 'Site name: ' . get_bloginfo( 'name' ) . "\nTagline: " . get_bloginfo( 'description' ) . "\n\nContent sample:\n" . $corpus;

	$response = invocation_generate_text( $user_prompt, $system, $schema );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = json_decode( (string) $response, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'invocation_invalid_response', __( 'The AI response could not be parsed.', 'invocation' ) );
	}

	$brief                = wp_parse_args( $data, invocation_default_site_brief() );
	$brief['generatedAt'] = current_time( 'mysql' );

	update_option( INVOCATION_SITE_BRIEF_OPTION, $brief );

	return $brief;
}

/**
 * Build a compact text corpus from recent published content.
 *
 * @param int $max Maximum posts/pages to include.
 * @return string
 */
function invocation_build_site_corpus( int $max ): string {
	$posts = get_posts(
		array(
			'post_type'   => array( 'page', 'post' ),
			'post_status' => 'publish',
			'numberposts' => $max,
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);

	$parts = array();
	$total = 0;
	foreach ( $posts as $post ) {
		$text   = wp_strip_all_tags( do_blocks( $post->post_content ) );
		$text   = trim( preg_replace( '/\s+/', ' ', $text ) );
		$text   = wp_trim_words( $text, 60, '…' );
		$line   = '- ' . get_the_title( $post ) . ': ' . $text;
		$total += strlen( $line );
		if ( $total > 6000 ) {
			break;
		}
		$parts[] = $line;
	}

	return implode( "\n", $parts );
}

/**
 * Register the Site Brief as a context provider (demonstrates the filter seam).
 */
add_filter(
	'invocation_context_providers',
	static function ( array $providers ): array {
		$providers['brief'] = array(
			'enabled' => static fn ( array $input ): bool =>
				( ! array_key_exists( 'useSiteBrief', $input ) || (bool) $input['useSiteBrief'] ) && invocation_has_site_brief(),
			'gather'  => static fn ( array $args ) => invocation_get_site_brief(),
			'render'  => 'invocation_render_brief_context',
		);
		return $providers;
	}
);

/**
 * Render the Site Brief into system-instruction lines.
 *
 * @param mixed                $brief Site brief.
 * @param array<string, mixed> $input Ability input.
 * @return list<string>
 */
function invocation_render_brief_context( $brief, array $input ): array {
	$brief = is_array( $brief ) ? $brief : array();
	$lines = array( 'Site brief — keep all content consistent with this:' );

	if ( '' !== trim( (string) ( $brief['purpose'] ?? '' ) ) ) {
		$lines[] = '- Purpose: ' . $brief['purpose'];
	}
	if ( '' !== trim( (string) ( $brief['audience'] ?? '' ) ) ) {
		$lines[] = '- Audience: ' . $brief['audience'];
	}
	if ( '' !== trim( (string) ( $brief['toneVoice'] ?? '' ) ) ) {
		$lines[] = '- Voice/tone: ' . $brief['toneVoice'];
	}
	if ( ! empty( $brief['offerings'] ) ) {
		$lines[] = '- Offerings: ' . implode( ', ', (array) $brief['offerings'] ) . '.';
	}
	if ( ! empty( $brief['keyTerms'] ) ) {
		$lines[] = '- Preferred terms: ' . implode( ', ', (array) $brief['keyTerms'] ) . '.';
	}
	if ( ! empty( $brief['avoid'] ) ) {
		$lines[] = '- Avoid: ' . implode( ', ', (array) $brief['avoid'] ) . '.';
	}

	return count( $lines ) > 1 ? $lines : array();
}
