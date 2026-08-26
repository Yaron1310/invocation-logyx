<?php
/**
 * The invocation/get-page ability.
 *
 * Returns a page/post's actual current content as Gutenberg block markup, so
 * an agent can ground an edit in what is really there instead of guessing —
 * refine-block requires the exact current markup of the block(s) being
 * changed, and update-page should start from the real content when only part
 * of a page is meant to change.
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
			'invocation/get-page',
			array(
				'label'               => __( 'Get Page', 'invocation' ),
				'description'         => __( 'Fetches a page/post\'s current title and content (as Gutenberg block markup) by id. Call this before refine-block (which needs the exact current markup of the block being changed) or before update-page when only part of a page should change.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'The id of the page/post to fetch.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string' ),
						'content' => array(
							'type'        => 'string',
							'description' => 'The page\'s current content as Gutenberg block markup.',
						),
					),
				),
				'execute_callback'    => 'invocation_ability_get_page',
				'permission_callback' => static function ( array $input = array() ): bool {
					$id = (int) ( $input['id'] ?? 0 );
					return $id > 0 && current_user_can( 'edit_post', $id );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}
);

/**
 * Execute callback for invocation/get-page.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed>|WP_Error
 */
function invocation_ability_get_page( array $input = array() ) {
	$id   = (int) ( $input['id'] ?? 0 );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post ) {
		return new WP_Error( 'invocation_not_found', __( 'Post not found.', 'invocation' ) );
	}

	return array(
		'id'      => (int) $post->ID,
		'title'   => (string) get_the_title( $post ),
		'status'  => (string) $post->post_status,
		'content' => (string) $post->post_content,
	);
}
