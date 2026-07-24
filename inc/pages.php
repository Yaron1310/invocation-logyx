<?php
/**
 * Write abilities: create and update pages/posts.
 *
 * These let an agent (over MCP) or the editor persist a generated layout
 * end-to-end. They default to creating drafts and never publish unless the
 * acting user actually has the capability, so automation stays safe-by-default.
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INVOCATION_PAGE_STATUSES = array( 'draft', 'pending', 'publish', 'private' );

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'invocation/create-page',
			array(
				'label'               => __( 'Create Page', 'invocation' ),
				'description'         => __( 'Creates a new page (or post) from a title and Gutenberg block markup. Creates a draft by default; only publishes if the current user can publish. Returns the new post id and edit URL.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'    => array(
							'type'        => 'string',
							'description' => 'The page title.',
							'minLength'   => 1,
						),
						'content'  => array(
							'type'        => 'string',
							'description' => 'Gutenberg block markup for the page body.',
						),
						'status'   => array(
							'type'        => 'string',
							'description' => 'Post status. Defaults to draft; downgraded to draft if the user cannot publish.',
							'enum'        => INVOCATION_PAGE_STATUSES,
							'default'     => 'draft',
						),
						'postType' => array(
							'type'        => 'string',
							'description' => 'Post type to create. Defaults to "page".',
							'default'     => 'page',
						),
						'template' => array(
							'type'        => 'string',
							'description' => 'Optional page template slug from invocation/list-templates (e.g. "page-no-title" for a title-less marketing layout). Omit for the theme default.',
						),
					),
					'required'             => array( 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => invocation_page_output_schema(),
				'execute_callback'    => 'invocation_ability_create_page',
				'permission_callback' => static function ( array $input = array() ): bool {
					$type = get_post_type_object( (string) ( $input['postType'] ?? 'page' ) );
					return $type ? current_user_can( $type->cap->create_posts ?? $type->cap->edit_posts ) : false;
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

		wp_register_ability(
			'invocation/update-page',
			array(
				'label'               => __( 'Update Page', 'invocation' ),
				'description'         => __( 'Updates an existing page or post (title, content, and/or status) by id. Overwrites the given fields. Returns the post id and edit URL.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'description' => 'The id of the page/post to update.',
						),
						'title'    => array(
							'type'        => 'string',
							'description' => 'New title (omit to leave unchanged).',
						),
						'content'  => array(
							'type'        => 'string',
							'description' => 'New Gutenberg block markup (omit to leave unchanged).',
						),
						'status'   => array(
							'type'        => 'string',
							'description' => 'New status (omit to leave unchanged). Status change to publish/private is ignored if the user cannot publish.',
							'enum'        => INVOCATION_PAGE_STATUSES,
						),
						'template' => array(
							'type'        => 'string',
							'description' => 'Page template slug from invocation/list-templates (omit to leave unchanged; pass "default" or "" to clear back to the theme default).',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => invocation_page_output_schema(),
				'execute_callback'    => 'invocation_ability_update_page',
				'permission_callback' => static function ( array $input = array() ): bool {
					$id = (int) ( $input['id'] ?? 0 );
					return $id > 0 && current_user_can( 'edit_post', $id );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
	}
);

/**
 * Shared output schema for the page write abilities.
 *
 * @return array<string, mixed>
 */
function invocation_page_output_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'id'       => array( 'type' => 'integer' ),
			'title'    => array( 'type' => 'string' ),
			'status'   => array( 'type' => 'string' ),
			'template' => array( 'type' => 'string' ),
			'url'      => array( 'type' => 'string' ),
			'editUrl'  => array( 'type' => 'string' ),
		),
	);
}

/**
 * Build the response payload describing a post.
 *
 * @param int $id Post id.
 * @return array<string, mixed>
 */
function invocation_page_response( int $id ): array {
	return array(
		'id'       => $id,
		'title'    => (string) get_the_title( $id ),
		'status'   => (string) get_post_status( $id ),
		'template' => (string) get_post_meta( $id, '_wp_page_template', true ),
		'url'      => (string) get_permalink( $id ),
		'editUrl'  => (string) ( get_edit_post_link( $id, 'raw' ) ?? '' ),
	);
}

/**
 * Execute callback for invocation/create-page.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed>|WP_Error
 */
function invocation_ability_create_page( array $input = array() ) {
	$title     = trim( (string) ( $input['title'] ?? '' ) );
	$content   = (string) ( $input['content'] ?? '' );
	$status    = (string) ( $input['status'] ?? 'draft' );
	$post_type = (string) ( $input['postType'] ?? 'page' );

	if ( '' === $title ) {
		return new WP_Error( 'invocation_missing_title', __( 'A title is required.', 'invocation' ) );
	}

	$type = get_post_type_object( $post_type );
	if ( ! $type ) {
		return new WP_Error( 'invocation_invalid_post_type', __( 'Unknown post type.', 'invocation' ) );
	}

	if ( ! in_array( $status, INVOCATION_PAGE_STATUSES, true ) ) {
		$status = 'draft';
	}
	// Never publish on behalf of a user who lacks the capability.
	if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
		$status = 'draft';
	}

	// Validate the template before creating anything, so a bad slug fails cleanly.
	$template = null;
	if ( array_key_exists( 'template', $input ) ) {
		$template = invocation_resolve_template( (string) $input['template'], $post_type );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
	}

	$id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => $post_type,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	if ( null !== $template ) {
		invocation_apply_template( (int) $id, $template );
	}

	return invocation_page_response( (int) $id );
}

/**
 * Execute callback for invocation/update-page.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed>|WP_Error
 */
function invocation_ability_update_page( array $input = array() ) {
	$id   = (int) ( $input['id'] ?? 0 );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post ) {
		return new WP_Error( 'invocation_not_found', __( 'Post not found.', 'invocation' ) );
	}

	$data = array( 'ID' => $id );
	if ( array_key_exists( 'title', $input ) ) {
		$data['post_title'] = (string) $input['title'];
	}
	if ( array_key_exists( 'content', $input ) ) {
		$data['post_content'] = (string) $input['content'];
	}
	if ( array_key_exists( 'status', $input ) && in_array( (string) $input['status'], INVOCATION_PAGE_STATUSES, true ) ) {
		$status  = (string) $input['status'];
		$type    = get_post_type_object( $post->post_type );
		$blocked = in_array( $status, array( 'publish', 'private' ), true ) && $type && ! current_user_can( $type->cap->publish_posts );
		if ( ! $blocked ) {
			$data['post_status'] = $status;
		}
	}

	// Validate the template up front (a template-only update is valid).
	$template = null;
	if ( array_key_exists( 'template', $input ) ) {
		$template = invocation_resolve_template( (string) $input['template'], $post->post_type );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
	}

	if ( count( $data ) === 1 && null === $template ) {
		return new WP_Error( 'invocation_nothing_to_update', __( 'Provide a title, content, status, or template to update.', 'invocation' ) );
	}

	// Only touch post fields when there is something beyond the ID to change.
	if ( count( $data ) > 1 ) {
		$result = wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	if ( null !== $template ) {
		invocation_apply_template( $id, $template );
	}

	return invocation_page_response( $id );
}
