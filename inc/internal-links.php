<?php
/**
 * The invocation/search-internal-links ability.
 *
 * Returns real internal URLs (pages, posts, custom post types, and taxonomy
 * terms) so the AI links to actual site content instead of hallucinating
 * internal links. Like search-media, this is offered to generate-layout as an
 * injected catalogue rather than a live tool call.
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
			'invocation/search-internal-links',
			array(
				'label'               => __( 'Search Internal Links', 'invocation' ),
				'description'         => __( 'Searches the site for real internal URLs (pages, posts, custom post types, and taxonomy terms like categories) so links point to existing content instead of invented URLs.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Search term. Leave empty to return the most recent content.',
						),
						'types' => array(
							'type'        => 'array',
							'description' => 'Content types to include: post type slugs (e.g. "page", "post") and/or taxonomy slugs (e.g. "category", "post_tag"). Defaults to pages and posts.',
							'items'       => array( 'type' => 'string' ),
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Maximum number of links to return.',
							'minimum'     => 1,
							'maximum'     => 50,
							'default'     => 20,
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
									'id'    => array( 'type' => 'integer' ),
									'title' => array( 'type' => 'string' ),
									'url'   => array( 'type' => 'string' ),
									'type'  => array( 'type' => 'string' ),
								),
							),
						),
						'total' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => 'invocation_ability_search_internal_links',
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
 * Execute callback for invocation/search-internal-links.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed> Matching internal links.
 */
function invocation_ability_search_internal_links( array $input = array() ): array {
	$query = trim( (string) ( $input['query'] ?? '' ) );
	$limit = max( 1, min( 50, (int) ( $input['limit'] ?? 20 ) ) );
	$types = $input['types'] ?? array();
	if ( ! is_array( $types ) || empty( $types ) ) {
		$types = array( 'page', 'post' );
	}

	$items = array();

	foreach ( $types as $type ) {
		$type = (string) $type;

		if ( taxonomy_exists( $type ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $type,
					'search'     => $query,
					'number'     => $limit,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( is_wp_error( $url ) ) {
					continue;
				}
				$items[] = array(
					'id'    => (int) $term->term_id,
					'title' => (string) $term->name,
					'url'   => (string) $url,
					'type'  => $type,
				);
			}
			continue;
		}

		if ( post_type_exists( $type ) ) {
			$posts = get_posts(
				array(
					'post_type'   => $type,
					'post_status' => 'publish',
					'numberposts' => $limit,
					's'           => $query,
					'orderby'     => '' !== $query ? 'relevance' : 'date',
					'order'       => 'DESC',
				)
			);
			foreach ( $posts as $post ) {
				$items[] = array(
					'id'    => (int) $post->ID,
					'title' => (string) get_the_title( $post ),
					'url'   => (string) get_permalink( $post ),
					'type'  => $type,
				);
			}
		}
	}

	$items = array_slice( $items, 0, $limit );

	return array(
		'items' => $items,
		'total' => count( $items ),
	);
}

/**
 * Repair internal links in generated markup against a catalogue of real links.
 *
 * Models tend to "tidy" ugly catalogue URLs (e.g. "?page_id=18" -> "/pricing/"),
 * which 404 on sites without pretty permalinks. This resolves any same-site href
 * back to a real URL by matching on slug or the page_id/p query var, leaving
 * external links, anchors, mailto/tel, and already-valid URLs untouched.
 *
 * @param string                           $markup Serialized block markup.
 * @param array<int, array<string, mixed>> $links  Catalogue from invocation_ability_search_internal_links().
 * @return array{0: string, 1: list<string>} The repaired markup and the list of original hrefs that were rewritten.
 */
function invocation_repair_internal_links( string $markup, array $links ): array {
	if ( empty( $links ) ) {
		return array( $markup, array() );
	}

	$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$valid   = array();
	$by_slug = array();
	$by_id   = array();

	foreach ( $links as $link ) {
		$url                           = (string) $link['url'];
		$valid[ $url ]                 = true;
		$by_id[ (string) $link['id'] ] = $url;

		$title_slug = sanitize_title( (string) $link['title'] );
		if ( '' !== $title_slug ) {
			$by_slug[ $title_slug ] = $url;
		}
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' !== $path ) {
			$path_slug = sanitize_title( basename( $path ) );
			if ( '' !== $path_slug ) {
				$by_slug[ $path_slug ] = $url;
			}
		}
	}

	$repaired = array();

	$result = preg_replace_callback(
		'/href="([^"]*)"/i',
		static function ( array $m ) use ( &$repaired, $valid, $by_slug, $by_id, $host ): string {
			$href = $m[1];

			if ( '' === $href || isset( $valid[ $href ] ) ) {
				return $m[0];
			}
			if ( preg_match( '#^(mailto:|tel:|#|https?://)#i', $href ) && ! str_contains( $href, $host ) ) {
				// External protocol or in-page anchor (and not our host): leave alone.
				if ( '#' === $href[0] || preg_match( '#^(mailto:|tel:)#i', $href ) ) {
					return $m[0];
				}
			}

			$parts = wp_parse_url( $href );
			if ( ! empty( $parts['host'] ) && $parts['host'] !== $host ) {
				return $m[0];
			}

			// Resolve by page_id / p query var.
			if ( ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $q );
				foreach ( array( 'page_id', 'p' ) as $key ) {
					if ( ! empty( $q[ $key ] ) && isset( $by_id[ (string) $q[ $key ] ] ) ) {
						$repaired[] = $href;
						return 'href="' . esc_url( $by_id[ (string) $q[ $key ] ] ) . '"';
					}
				}
			}

			// Resolve by slug from the path.
			$path = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';
			if ( '' !== $path ) {
				$slug = sanitize_title( basename( $path ) );
				if ( '' !== $slug && isset( $by_slug[ $slug ] ) ) {
					$repaired[] = $href;
					return 'href="' . esc_url( $by_slug[ $slug ] ) . '"';
				}
			}

			return $m[0];
		},
		$markup
	);

	return array( is_string( $result ) ? $result : $markup, $repaired );
}
