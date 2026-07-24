<?php
/**
 * The invocation/list-patterns ability + pattern context helper.
 *
 * Patterns are designed, reusable *sections* — the unit most authors actually
 * think in. Surfacing the site's registered patterns (theme + core + custom)
 * lets the AI compose from real sections and learn which (often custom) blocks
 * each section uses.
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
			'invocation/list-patterns',
			array(
				'label'               => __( 'List Patterns', 'invocation' ),
				'description'         => __( 'Lists the block patterns (reusable sections) registered on this site, with their categories and the block types they use. Optionally includes each pattern\'s block markup.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query'          => array(
							'type'        => 'string',
							'description' => 'Optional search term matched against pattern title and description.',
						),
						'category'       => array(
							'type'        => 'string',
							'description' => 'Optional pattern category slug to filter by.',
						),
						'includeContent' => array(
							'type'        => 'boolean',
							'description' => 'Whether to include each pattern\'s full block markup. Default false (catalog only).',
							'default'     => false,
						),
						'limit'          => array(
							'type'        => 'integer',
							'description' => 'Maximum number of patterns to return.',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 40,
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
									'name'        => array( 'type' => 'string' ),
									'title'       => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'categories'  => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									'blocks'      => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									'content'     => array( 'type' => 'string' ),
								),
							),
						),
						'total' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => 'invocation_ability_list_patterns',
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
 * Execute callback for invocation/list-patterns.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed> Patterns.
 */
function invocation_ability_list_patterns( array $input = array() ): array {
	$query           = trim( (string) ( $input['query'] ?? '' ) );
	$category        = trim( (string) ( $input['category'] ?? '' ) );
	$include_content = (bool) ( $input['includeContent'] ?? false );
	$limit           = max( 1, min( 100, (int) ( $input['limit'] ?? 40 ) ) );

	$items = invocation_get_patterns_for_context( 1000, $include_content );

	if ( '' !== $query ) {
		$needle = strtolower( $query );
		$items  = array_values(
			array_filter(
				$items,
				static fn ( array $p ): bool =>
					str_contains( strtolower( $p['title'] ), $needle )
					|| str_contains( strtolower( $p['description'] ), $needle )
			)
		);
	}

	if ( '' !== $category ) {
		$items = array_values(
			array_filter(
				$items,
				static fn ( array $p ): bool => in_array( $category, $p['categories'], true )
			)
		);
	}

	$total = count( $items );
	$items = array_slice( $items, 0, $limit );

	return array(
		'items' => $items,
		'total' => $total,
	);
}

/**
 * Build a compact catalogue of patterns for context injection.
 *
 * Combines the site's own saved (user) patterns — surfaced first, since they are
 * this site's designed layouts — with registered theme/core patterns.
 *
 * @param int  $limit           Maximum patterns to return.
 * @param bool $include_content Whether to include each pattern's block markup.
 * @return array<int, array<string, mixed>>
 */
function invocation_get_patterns_for_context( int $limit = 40, bool $include_content = false ): array {
	$out = invocation_get_user_patterns( $include_content );

	if ( class_exists( 'WP_Block_Patterns_Registry' ) ) {
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			// Skip patterns hidden from the inserter (utility/hidden patterns).
			if ( isset( $pattern['inserter'] ) && false === $pattern['inserter'] ) {
				continue;
			}
			$out[] = invocation_pattern_context_item(
				(string) ( $pattern['name'] ?? '' ),
				(string) ( $pattern['title'] ?? '' ),
				(string) ( $pattern['description'] ?? '' ),
				array_values( array_map( 'strval', (array) ( $pattern['categories'] ?? array() ) ) ),
				(string) ( $pattern['content'] ?? '' ),
				$include_content
			);
		}
	}

	return array_slice( $out, 0, $limit );
}

/**
 * Build a single pattern catalogue entry, deriving the block names it uses.
 *
 * @param string       $name            Pattern reference (slug, or "user:{id}").
 * @param string       $title           Pattern title.
 * @param string       $description     Pattern description.
 * @param list<string> $categories      Category slugs/names.
 * @param string       $content         Block markup.
 * @param bool         $include_content Whether to include the markup in the entry.
 * @return array<string, mixed>
 */
function invocation_pattern_context_item( string $name, string $title, string $description, array $categories, string $content, bool $include_content ): array {
	$blocks = array();
	if ( '' !== $content ) {
		$names = array();
		invocation_collect_block_names( parse_blocks( $content ), $names );
		$blocks = array_slice( array_values( array_unique( $names ) ), 0, 12 );
	}

	$item = array(
		'name'        => $name,
		'title'       => $title,
		'description' => $description,
		'categories'  => $categories,
		'blocks'      => $blocks,
	);
	if ( $include_content ) {
		$item['content'] = $content;
	}

	return $item;
}

/**
 * The site's own saved patterns (wp_block posts), including any saved via
 * invocation/save-pattern. Referenced as "user:{id}" so they can be filled by
 * invocation/generate-layout like registered patterns.
 *
 * @param bool $include_content Whether to include each pattern's block markup.
 * @return array<int, array<string, mixed>>
 */
function invocation_get_user_patterns( bool $include_content = false ): array {
	$posts = get_posts(
		array(
			'post_type'        => 'wp_block',
			'post_status'      => 'publish',
			// Deliberately high: the full set of saved patterns is grounding data for
			// generation, and a truncated list would let the model miss real patterns.
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts
			'numberposts'      => 200,
			'suppress_filters' => false,
		)
	);

	$out = array();
	foreach ( $posts as $post ) {
		$categories = wp_get_object_terms( $post->ID, 'wp_pattern_category', array( 'fields' => 'names' ) );
		$out[]      = invocation_pattern_context_item(
			'user:' . $post->ID,
			(string) $post->post_title,
			'',
			is_wp_error( $categories ) ? array() : array_map( 'strval', $categories ),
			(string) $post->post_content,
			$include_content
		);
	}

	return $out;
}

/**
 * Rank patterns by relevance to a query and return the top N.
 *
 * Scores by how many query terms appear in the pattern's title, categories and
 * description. With no query (or no matches) it falls back to the existing order.
 *
 * @param array<int, array<string, mixed>> $patterns Pattern catalogue.
 * @param string                           $query    Prompt/query to rank against.
 * @param int                              $limit    Maximum to return.
 * @return array<int, array<string, mixed>>
 */
function invocation_rank_patterns( array $patterns, string $query, int $limit ): array {
	$words = preg_split( '/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $words ) ) {
		$words = array();
	}

	$terms = array_values(
		array_filter(
			array_map( 'strtolower', $words ),
			static fn ( string $t ): bool => strlen( $t ) > 2
		)
	);

	if ( empty( $terms ) ) {
		return array_slice( $patterns, 0, $limit );
	}

	$scored = array();
	foreach ( $patterns as $index => $pattern ) {
		$haystack = strtolower(
			(string) ( $pattern['title'] ?? '' ) . ' '
			. implode( ' ', (array) ( $pattern['categories'] ?? array() ) ) . ' '
			. (string) ( $pattern['description'] ?? '' )
		);
		$score    = 0;
		foreach ( $terms as $term ) {
			if ( str_contains( $haystack, $term ) ) {
				++$score;
			}
		}
		$scored[] = array(
			'pattern' => $pattern,
			'score'   => $score,
			'index'   => $index,
		);
	}

	// Highest score first; stable on original order for ties (PHP 8 sort is stable).
	usort( $scored, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

	return array_map(
		static fn ( array $entry ) => $entry['pattern'],
		array_slice( $scored, 0, $limit )
	);
}

/**
 * Get a single registered pattern (with content) by its slug.
 *
 * @param string $name Pattern slug, e.g. "twentytwentyfive/cta-centered-heading".
 * @return array{name: string, title: string, content: string}|null
 */
function invocation_get_pattern_by_name( string $name ): ?array {
	// Saved (user) patterns are referenced as "user:{id}".
	if ( str_starts_with( $name, 'user:' ) ) {
		$id   = (int) substr( $name, 5 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return null;
		}
		return array(
			'name'    => $name,
			'title'   => '' !== (string) $post->post_title ? (string) $post->post_title : $name,
			'content' => (string) $post->post_content,
		);
	}

	if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
		return null;
	}
	$registry = WP_Block_Patterns_Registry::get_instance();
	if ( ! $registry->is_registered( $name ) ) {
		return null;
	}
	$pattern = $registry->get_registered( $name );

	return array(
		'name'    => $name,
		'title'   => (string) ( $pattern['title'] ?? $name ),
		'content' => (string) ( $pattern['content'] ?? '' ),
	);
}
