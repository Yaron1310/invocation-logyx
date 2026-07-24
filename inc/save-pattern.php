<?php
/**
 * Write ability: save block markup as a reusable pattern.
 *
 * Turns a generated section (or a whole page layout) into a WordPress user
 * pattern — the `wp_block` post type — so an agent over MCP can persist it
 * without the author manually clicking "Create pattern" in the editor. Saved
 * patterns show up in the inserter and, via the patterns context provider,
 * become grounding material for future generations.
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
			'invocation/save-pattern',
			array(
				'label'               => __( 'Save Pattern', 'invocation' ),
				'description'         => __( 'Saves Gutenberg block markup (a section or a full-page layout) as a reusable pattern (a wp_block post). Unsynced by default, so each reuse is an independent copy that can be refilled with new content. Returns the new pattern id and edit URL.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'      => array(
							'type'        => 'string',
							'description' => 'The pattern title (also used to derive its slug).',
							'minLength'   => 1,
						),
						'content'    => array(
							'type'        => 'string',
							'description' => 'Gutenberg block markup to save as the pattern body.',
							'minLength'   => 1,
						),
						'categories' => array(
							'type'        => 'array',
							'description' => 'Optional pattern category names (wp_pattern_category terms); created if they do not already exist.',
							'items'       => array( 'type' => 'string' ),
						),
						'synced'     => array(
							'type'        => 'boolean',
							'description' => 'Whether this is a synced (global) pattern. Default false: an unsynced pattern is copied independently on each use, which is what you want for layouts you will refill with new content.',
							'default'     => false,
						),
					),
					'required'             => array( 'title', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer' ),
						'title'      => array( 'type' => 'string' ),
						'slug'       => array( 'type' => 'string' ),
						'synced'     => array( 'type' => 'boolean' ),
						'categories' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'editUrl'    => array( 'type' => 'string' ),
						'warnings'   => array(
							'type'        => 'array',
							'description' => 'Names of any blocks in the markup that are not registered on this site.',
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => 'invocation_ability_save_pattern',
				'permission_callback' => static function ( array $input = array() ): bool {
					$type = get_post_type_object( 'wp_block' );
					if ( ! $type || ! current_user_can( $type->cap->create_posts ?? $type->cap->edit_posts ) ) {
						return false;
					}

					// Assigning (and possibly creating) wp_pattern_category terms
					// needs the taxonomy capability too, not just the post cap.
					if ( ! empty( $input['categories'] ) ) {
						$tax = get_taxonomy( 'wp_pattern_category' );
						if ( ! $tax || ! current_user_can( $tax->cap->assign_terms ) ) {
							return false;
						}
					}

					return true;
				},
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
 * Execute callback for invocation/save-pattern.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed>|WP_Error
 */
function invocation_ability_save_pattern( array $input = array() ) {
	$title      = trim( (string) ( $input['title'] ?? '' ) );
	$content    = (string) ( $input['content'] ?? '' );
	$synced     = (bool) ( $input['synced'] ?? false );
	$categories = array_values(
		array_filter(
			array_map(
				static fn ( $c ): string => trim( (string) $c ),
				(array) ( $input['categories'] ?? array() )
			),
			static fn ( string $c ): bool => '' !== $c
		)
	);

	if ( '' === $title ) {
		return new WP_Error( 'invocation_missing_title', __( 'A title is required.', 'invocation' ) );
	}

	$tax = get_taxonomy( 'wp_pattern_category' );
	if ( $categories && $tax ) {
		if ( ! current_user_can( $tax->cap->assign_terms ) ) {
			return new WP_Error(
				'invocation_cannot_assign_terms',
				__( 'Sorry, you are not allowed to assign pattern categories.', 'invocation' )
			);
		}

		// wp_set_object_terms() creates any missing terms for this non-hierarchical
		// taxonomy, so creating new categories additionally requires edit_terms.
		$new = array_filter(
			$categories,
			static fn ( string $name ): bool => ! term_exists( $name, 'wp_pattern_category' )
		);
		if ( $new && ! current_user_can( $tax->cap->edit_terms ) ) {
			return new WP_Error(
				'invocation_cannot_create_terms',
				__( 'Sorry, you are not allowed to create new pattern categories.', 'invocation' )
			);
		}
	}

	// Validate + normalise the markup the same way generated layouts are.
	$normalized = invocation_normalize_markup( $content );
	if ( is_wp_error( $normalized ) ) {
		return $normalized;
	}

	// Patterns must be published to appear in the inserter; the wp_block post
	// type is non-public, so nothing is exposed on the front end.
	$id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => $normalized['markup'],
			'post_status'  => 'publish',
			'post_type'    => 'wp_block',
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$id = (int) $id;

	// Core flags unsynced patterns with this meta; synced ones leave it empty.
	if ( ! $synced ) {
		update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );
	}

	$assigned = array();
	if ( $categories && $tax ) {
		wp_set_object_terms( $id, $categories, 'wp_pattern_category' );
		$names = wp_get_object_terms( $id, 'wp_pattern_category', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $names ) ) {
			$assigned = array_map( 'strval', $names );
		}
	}

	return array(
		'id'         => $id,
		'title'      => (string) get_the_title( $id ),
		'slug'       => (string) get_post_field( 'post_name', $id ),
		'synced'     => $synced,
		'categories' => $assigned,
		'editUrl'    => (string) ( get_edit_post_link( $id, 'raw' ) ?? '' ),
		'warnings'   => $normalized['warnings'],
	);
}
