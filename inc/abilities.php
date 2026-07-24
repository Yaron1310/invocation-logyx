<?php
/**
 * Invocation abilities.
 *
 * Abilities are the unit of capability in WordPress 7.0. By registering here we
 * get three things for free: server-side validation against the JSON schemas,
 * REST exposure (`meta.show_in_rest`), and automatic surfacing through the core
 * MCP Adapter — which is what lets external agents (e.g. Claude Code) drive
 * Invocation without any extra transport code.
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INVOCATION_ABILITY_CATEGORY = 'invocation';

/**
 * Register the category that groups all Invocation abilities.
 */
add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		wp_register_ability_category(
			INVOCATION_ABILITY_CATEGORY,
			array(
				'label'       => __( 'Invocation', 'invocation' ),
				'description' => __( 'AI-assisted Gutenberg layout generation grounded in the active block theme.', 'invocation' ),
			)
		);
	}
);

/**
 * Register Invocation abilities.
 */
add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'invocation/get-theme-context',
			array(
				'label'               => __( 'Get Theme Context', 'invocation' ),
				'description'         => __( 'Returns the active theme\'s design tokens (color palette, typography, layout sizes) derived from theme.json, so generated content stays on-theme.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'colors'       => array(
							'type'        => 'array',
							'description' => 'Theme color palette.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'name'  => array( 'type' => 'string' ),
									'slug'  => array( 'type' => 'string' ),
									'color' => array( 'type' => 'string' ),
								),
							),
						),
						'fontFamilies' => array(
							'type'        => 'array',
							'description' => 'Theme font families.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'name' => array( 'type' => 'string' ),
									'slug' => array( 'type' => 'string' ),
								),
							),
						),
						'layout'       => array(
							'type'        => 'object',
							'description' => 'Content and wide layout sizes.',
							'properties'  => array(
								'contentSize' => array( 'type' => 'string' ),
								'wideSize'    => array( 'type' => 'string' ),
							),
						),
					),
				),
				'execute_callback'    => 'invocation_ability_get_theme_context',
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

		wp_register_ability(
			'invocation/list-blocks',
			array(
				'label'               => __( 'List Blocks', 'invocation' ),
				'description'         => __( 'Lists the block types registered on this site (core, theme, and plugin blocks) that AI may use when composing a layout.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'blocks' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'        => array( 'type' => 'string' ),
									'title'       => array( 'type' => 'string' ),
									'category'    => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => 'invocation_ability_list_blocks',
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
 * Execute callback: extract on-theme design tokens from the merged theme.json.
 *
 * @param array<string, mixed> $input Validated (empty) input.
 * @return array<string, mixed> Theme context.
 */
function invocation_ability_get_theme_context( array $input = array() ): array {
	unset( $input );

	$settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings() : array();

	// theme.json nests palettes/font families under default/theme/custom origins.
	$colors        = $settings['color']['palette']['theme'] ?? ( $settings['color']['palette'] ?? array() );
	$font_families = $settings['typography']['fontFamilies']['theme'] ?? ( $settings['typography']['fontFamilies'] ?? array() );

	return array(
		'colors'       => array_values(
			array_map(
				static fn ( array $c ): array => array(
					'name'  => (string) ( $c['name'] ?? '' ),
					'slug'  => (string) ( $c['slug'] ?? '' ),
					'color' => (string) ( $c['color'] ?? '' ),
				),
				is_array( $colors ) ? $colors : array()
			)
		),
		'fontFamilies' => array_values(
			array_map(
				static fn ( array $f ): array => array(
					'name' => (string) ( $f['name'] ?? '' ),
					'slug' => (string) ( $f['slug'] ?? '' ),
				),
				is_array( $font_families ) ? $font_families : array()
			)
		),
		'layout'       => array(
			'contentSize' => (string) ( $settings['layout']['contentSize'] ?? '' ),
			'wideSize'    => (string) ( $settings['layout']['wideSize'] ?? '' ),
		),
	);
}

/**
 * Execute callback: list registered block types relevant to content authoring.
 *
 * @param array<string, mixed> $input Validated (empty) input.
 * @return array<string, mixed> Registered blocks.
 */
function invocation_ability_list_blocks( array $input = array() ): array {
	unset( $input );

	$registry = WP_Block_Type_Registry::get_instance();

	$blocks = array_values(
		array_map(
			static fn ( WP_Block_Type $block ): array => array(
				'name'        => (string) $block->name,
				'title'       => (string) ( $block->title ?? '' ),
				'category'    => (string) ( $block->category ?? '' ),
				'description' => (string) ( $block->description ?? '' ),
			),
			$registry->get_all_registered()
		)
	);

	return array( 'blocks' => $blocks );
}
