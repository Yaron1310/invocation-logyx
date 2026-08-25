<?php
/**
 * The invocation/search-pages ability.
 *
 * Lets the AI resolve a page/post named in conversation ("the About page")
 * to a real post id, status, and edit URL — so chat and MCP clients never
 * have to guess an id, and the user never has to look one up by hand.
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
			'invocation/search-pages',
			array(
				'label'               => __( 'Search Pages', 'invocation' ),
				'description'         => __( 'Searches pages/posts by title (draft, pending, private, and published) and returns their real id, status, and edit URL, so a page named in conversation can be resolved before editing it.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query'    => array(
							'type'        => 'string',
							'description' => 'Search term matched against the title. Leave empty to return the most recently modified pages.',
						),
						'postType' => array(
							'type'        => 'string',
							'description' => 'Post type to search. Defaults to "page".',
							'default'     => 'page',
						),
						'limit'    => array(
							'type'        => 'integer',
							'description' => 'Maximum number of results to return.',
							'minimum'     => 1,
							'maximum'     => 50,
							'default'     => 10,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'      => array( 'type' => 'integer' ),
									'title'   => array( 'type' => 'string' ),
									'status'  => array( 'type' => 'string' ),
									'url'     => array( 'type' => 'string' ),
									'editUrl' => array( 'type' => 'string' ),
								),
							),
						),
						'total' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => 'invocation_ability_search_pages',
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
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
 * Execute callback for invocation/search-pages.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed>
 */
function invocation_ability_search_pages( array $input = array() ): array {
	$query     = trim( (string) ( $input['query'] ?? '' ) );
	$post_type = (string) ( $input['postType'] ?? 'page' );
	$limit     = max( 1, min( 50, (int) ( $input['limit'] ?? 10 ) ) );

	if ( ! post_type_exists( $post_type ) ) {
		return array(
			'items' => array(),
			'total' => 0,
		);
	}

	// 'perm' => 'readable' restricts non-public statuses to what the current
	// user may actually see (their own posts, plus private posts only if
	// they can read_private_posts) — the same rule WP core search obeys.
	$query_args = array(
		'post_type'      => $post_type,
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'perm'           => 'readable',
		's'              => $query,
		'orderby'        => '' !== $query ? 'relevance' : 'modified',
		'order'          => 'DESC',
		'posts_per_page' => $limit,
		'no_found_rows'  => true,
	);

	$results = new WP_Query( $query_args );

	$items = array();
	foreach ( $results->posts as $post ) {
		$items[] = array(
			'id'      => (int) $post->ID,
			'title'   => (string) get_the_title( $post ),
			'status'  => (string) $post->post_status,
			'url'     => (string) get_permalink( $post ),
			'editUrl' => (string) ( get_edit_post_link( $post->ID, 'raw' ) ?? '' ),
		);
	}

	return array(
		'items' => $items,
		'total' => count( $items ),
	);
}
