<?php
/**
 * The invocation/chat ability — the model behind the built-in Chat admin tab.
 *
 * This does not execute anything itself. It is a single turn of a
 * plan-then-act loop: given the conversation so far, it asks the configured
 * AI provider (via the WP AI Client) to either reply in plain text, or
 * propose exactly one call to an existing, already-permission-checked
 * ability (invocation/generate-layout, refine-block, duplicate-page,
 * update-page, search-media, upload-media, ...). The Chat app then makes
 * that ability call itself over the normal Abilities REST endpoint — with
 * its own permission_callback enforced as usual — and feeds the result back
 * as the next turn. Keeping execution out of this ability means every
 * mutation still goes through the same reviewed, capability-gated code path
 * as the editor sidebar and MCP, however it was reached.
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The abilities the chat model is allowed to propose calling, with a short
 * description of when to use each. Kept separate from `invocation_mcp_abilities()`
 * — this list is prose for the model's system instruction, not an API surface.
 *
 * @return array<string, string>
 */
function invocation_chat_available_abilities(): array {
	return array(
		'invocation/get-theme-context'   => 'Look up the site\'s theme.json design tokens. Takes no fields.',
		'invocation/list-blocks'         => 'List the block types actually registered on this site. Takes no fields.',
		'invocation/list-templates'      => 'List the page templates available on this theme. Takes no fields.',
		'invocation/search-pages'        => 'Find a page/post by title and get its real id, status, and edit URL. Call this first whenever the user names a page (e.g. "the About page") and you do not already have its id from this conversation — never guess an id. Uses: query.',
		'invocation/get-page'            => 'Fetch a page/post\'s current title and content (real block markup) by id. Call this before refine-block, and before update-page whenever only part of an existing page should change — never invent or guess what is currently on a page. Uses: id.',
		'invocation/search-media'        => 'Find an existing image in the media library by keyword. Uses: query.',
		'invocation/upload-media'        => 'Insert an image the user attached in chat into the media library. Only propose this when the user\'s message includes an attached image; its id is given in the conversation as ATTACHMENT_ID. Uses: data, filename.',
		'invocation/generate-layout'     => 'Generate a whole new section or page of block markup from a text brief. Uses: prompt (required), tone, audience.',
		'invocation/refine-block'        => 'Rewrite one existing block in place (e.g. change a heading or a paragraph). Requires the block\'s exact current markup — get it from get-page first, never invent it. Uses: blockMarkup (required, the exact current markup), instruction (required), tone.',
		'invocation/duplicate-page'      => 'Clone an existing page/post into a new draft, e.g. before editing a copy. Uses: id (required), title.',
		'invocation/update-page'         => 'Change an existing page/post\'s title, content, status, or template by id. content, if given, REPLACES the entire page — it is never merged or patched server-side. To change only part of a page, first get-page, then build the complete new content yourself (the fetched content with only the relevant part edited) and put that whole string in content. Calling this with only an id and nothing else changes nothing and errors. Uses: id (required), title, content, status, template.',
		'invocation/create-page'         => 'Create a brand-new page/post from a title and block markup. Uses: title (required), content, status, template.',
	);
}

/**
 * Which of the flat model-schema fields (see invocation_chat_model_schema())
 * each ability actually accepts, in the order they should be checked. Used
 * to reassemble a real {ability, input} action from the model's flat
 * response, picking only the fields relevant to whichever ability it chose.
 *
 * @return array<string, list<string>>
 */
function invocation_chat_ability_fields(): array {
	return array(
		'invocation/get-theme-context' => array(),
		'invocation/list-blocks'       => array(),
		'invocation/list-templates'    => array(),
		'invocation/search-pages'      => array( 'query' ),
		'invocation/get-page'          => array( 'id' ),
		'invocation/search-media'      => array( 'query' ),
		'invocation/upload-media'      => array( 'data', 'filename' ),
		'invocation/generate-layout'   => array( 'prompt', 'tone', 'audience' ),
		'invocation/refine-block'      => array( 'blockMarkup', 'instruction', 'tone' ),
		'invocation/duplicate-page'    => array( 'id', 'title' ),
		'invocation/update-page'       => array( 'id', 'title', 'content', 'status', 'template' ),
		'invocation/create-page'       => array( 'title', 'content', 'status', 'template' ),
	);
}

/**
 * JSON schema for one chat turn's structured response, as returned by the
 * invocation/chat ability (its public output_schema): a reply plus at most
 * one proposed action, or null if this turn is just a reply.
 *
 * @return array<string, mixed>
 */
function invocation_chat_response_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'reply'  => array(
				'type'        => 'string',
				'description' => 'What to say to the user this turn.',
			),
			'action' => array(
				'type'       => array( 'object', 'null' ),
				'properties' => array(
					'ability' => array( 'type' => 'string' ),
					'input'   => array( 'type' => 'object' ),
				),
			),
			'debug'  => array(
				'type'        => 'object',
				'description' => 'What was actually sent to and received from the AI provider this turn, for the Chat tab\'s debug panel.',
			),
		),
	);
}

/**
 * JSON schema actually sent to the AI provider for one chat turn.
 *
 * Deliberately flat, not the nested {ability, input} shape the ability
 * publicly returns (see invocation_chat_response_schema()). A nested
 * "input" field with no defined `properties` gives Gemini's structured
 * output nothing to target, so it reliably emitted a bare {} regardless of
 * prose instructions saying otherwise — observed directly in this ability's
 * own debug output across get-page AND update-page calls, so it is
 * structural, not a compliance slip a better prompt can fix. Every possible
 * argument across every proposable ability is instead its own explicitly
 * typed, `nullable: true` top-level field; invocation_ability_chat() below
 * reassembles the real {ability, input} action from whichever fields the
 * chosen ability actually uses (see invocation_chat_ability_fields()).
 *
 * A "type" union like ["object", "null"] is JSON Schema but not what
 * Gemini's structured output accepts (its proto-based schema rejects a
 * bare type array: "Proto field is not repeating, cannot start list") —
 * `nullable: true` alongside a single `type` is the correct, documented
 * way to express it instead.
 *
 * @return array<string, mixed>
 */
function invocation_chat_model_schema(): array {
	$string_field = static fn ( string $description ): array => array(
		'type'        => 'string',
		'nullable'    => true,
		'description' => $description,
	);

	return array(
		'type'                 => 'object',
		'properties'           => array(
			'reply'       => array(
				'type'        => 'string',
				'description' => 'What to say to the user this turn: a question, an explanation, or a summary of what you are about to do.',
			),
			'ability'     => array(
				'type'        => 'string',
				'nullable'    => true,
				'enum'        => array_keys( invocation_chat_available_abilities() ),
				'description' => 'The one ability to propose calling this turn, or null to just reply. Wait for the tool result (given back to you as the next turn) before proposing another.',
			),
			'id'          => array(
				'type'        => 'integer',
				'nullable'    => true,
				'description' => 'Post id — for get-page, refine-block context, duplicate-page, update-page.',
			),
			'title'       => $string_field( 'Page/post title — for duplicate-page, update-page, create-page.' ),
			'content'     => $string_field( 'Complete Gutenberg block markup for the WHOLE page — for update-page (replaces everything) and create-page.' ),
			'status'      => array(
				'type'        => 'string',
				'nullable'    => true,
				'enum'        => INVOCATION_PAGE_STATUSES,
				'description' => 'Post status — for update-page, create-page.',
			),
			'template'    => $string_field( 'Page template slug — for update-page, create-page.' ),
			'query'       => $string_field( 'Search term — for search-pages, search-media.' ),
			'data'        => $string_field( 'Base64 image data — for upload-media.' ),
			'filename'    => $string_field( 'Image filename — for upload-media.' ),
			'prompt'      => $string_field( 'What to generate, in natural language — for generate-layout.' ),
			'audience'    => $string_field( 'Target audience — for generate-layout.' ),
			'tone'        => array(
				'type'        => 'string',
				'nullable'    => true,
				'enum'        => array( 'professional', 'casual', 'creative', 'minimal', 'bold' ),
				'description' => 'Writing tone — for generate-layout, refine-block.',
			),
			'blockMarkup' => $string_field( 'The EXACT current markup of the block being changed (from get-page) — for refine-block. Never invented.' ),
			'instruction' => $string_field( 'How to change the block — for refine-block.' ),
		),
		'required'             => array( 'reply', 'ability' ),
		'additionalProperties' => false,
	);
}

/**
 * Build the system instruction for the chat model: its role, the abilities it
 * may propose, and the current editing context (if any).
 *
 * @param array<string, mixed> $input Ability input (may include pageId).
 * @return string
 */
function invocation_chat_system_instruction( array $input ): string {
	$lines = array(
		'You are the Invocation chat assistant for a WordPress site. You help the user find, draft, and edit pages by proposing one ability call at a time, using the fields given to you (id, title, content, status, template, query, data, filename, prompt, audience, tone, blockMarkup, instruction) — set "ability" to the one you are calling and fill in only the fields it actually uses (see the list below), leaving the rest null. You never invent block types, image URLs, block markup, or page ids that were not given to you or returned by a previous tool result. If the user names a page instead of giving you an id, call invocation/search-pages first and, if more than one result matches, ask the user which one before proposing any change to it. Before calling refine-block, or before update-page when only part of an existing page should change, call invocation/get-page first to get its real current content — never write blockMarkup from imagination. update-page\'s content field REPLACES the whole page: after get-page, build the complete new content string yourself by editing only the relevant part of what you fetched, and put that entire string in content — proposing update-page with only id and nothing else changes nothing and errors.',
		'Always make destructive or publishing changes as a draft first and describe it back to the user before proposing a status change to "publish" — let them approve in chat.',
		'Available abilities:',
	);

	foreach ( invocation_chat_available_abilities() as $ability => $desc ) {
		$lines[] = "- {$ability}: {$desc}";
	}

	$page_id = (int) ( $input['pageId'] ?? 0 );
	if ( $page_id > 0 ) {
		$lines[] = sprintf(
			'SELECTED_PAGE_ID = %1$d (title "%2$s"). Whenever the user is referring to this page — including a bare instruction like "change the header" with no page named — use exactly %1$d as the id for get-page/refine-block/update-page/duplicate-page. Never use 0 and never invent a different id.',
			$page_id,
			get_the_title( $page_id )
		);
	}

	return implode( "\n", $lines );
}

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'invocation/chat',
			array(
				'label'               => __( 'Chat', 'invocation' ),
				'description'         => __( 'One turn of the Invocation chat assistant: given the conversation so far, returns a reply and at most one proposed ability call for the client to execute.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'message' => array(
							'type'        => 'string',
							'description' => 'The user\'s latest chat message.',
						),
						'history' => array(
							'type'        => 'array',
							'description' => 'Prior turns, oldest first, as {role: "user"|"assistant"|"tool", content: string}.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'role'    => array(
										'type' => 'string',
										'enum' => array( 'user', 'assistant', 'tool' ),
									),
									'content' => array( 'type' => 'string' ),
								),
								'required'   => array( 'role', 'content' ),
							),
							'default'     => array(),
						),
						'pageId'  => array(
							'type'        => 'integer',
							'description' => 'The post id currently open in the editor, if any.',
						),
					),
					'required'             => array( 'message' ),
					'additionalProperties' => false,
				),
				'output_schema'       => invocation_chat_response_schema(),
				'execute_callback'    => 'invocation_ability_chat',
				'permission_callback' => static fn (): bool => current_user_can( invocation_generation_capability() ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						// Not marked readonly even though it never writes to
						// WordPress: the Abilities REST API forces readonly
						// abilities onto GET, and this ability's payload
						// (conversation history, which can include a whole
						// page's fetched content) is unbounded — cramming
						// that into a URL query string can exceed a host's
						// URL-length or WAF rules (observed: a hosting
						// firewall intercepting the request as abuse before
						// it reaches WordPress at all, well upstream of any
						// PHP or CORS behavior this plugin controls). POST
						// keeps the payload in the request body instead.
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
 * Execute callback for invocation/chat.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed>|WP_Error
 */
function invocation_ability_chat( array $input = array() ) {
	$message = trim( (string) ( $input['message'] ?? '' ) );
	if ( '' === $message ) {
		return new WP_Error( 'invocation_missing_message', __( 'A message is required.', 'invocation' ), array( 'status' => 400 ) );
	}

	$history = is_array( $input['history'] ?? null ) ? $input['history'] : array();

	$transcript = array();
	foreach ( $history as $turn ) {
		$role    = (string) ( $turn['role'] ?? '' );
		$content = (string) ( $turn['content'] ?? '' );
		if ( '' !== $role && '' !== $content ) {
			$transcript[] = strtoupper( $role ) . ': ' . $content;
		}
	}
	$transcript[] = 'USER: ' . $message;

	$user_prompt = implode( "\n", $transcript );
	$system      = invocation_chat_system_instruction( $input );
	$raw         = invocation_generate_text( $user_prompt, $system, invocation_chat_model_schema() );

	// Exposed to the client's debug panel either way, so "what did the model
	// actually see and say" is inspectable without server log access.
	$debug = array(
		'systemInstruction' => $system,
		'userPrompt'        => $user_prompt,
		'rawResponse'       => is_wp_error( $raw ) ? null : (string) $raw,
	);

	if ( is_wp_error( $raw ) ) {
		$raw->add_data( array_merge( (array) $raw->get_error_data(), array( 'debug' => $debug ) ) );
		return $raw;
	}

	$decoded = json_decode( (string) $raw, true );
	if ( ! is_array( $decoded ) || ! isset( $decoded['reply'] ) ) {
		return new WP_Error(
			'invocation_chat_bad_response',
			__( 'The AI provider returned an unexpected response.', 'invocation' ),
			array(
				'status' => 502,
				'debug'  => $debug,
			)
		);
	}

	$action  = null;
	$ability = (string) ( $decoded['ability'] ?? '' );
	$fields  = invocation_chat_ability_fields();
	if ( '' !== $ability && array_key_exists( $ability, $fields ) ) {
		// Reassemble a real {ability, input} action from whichever of the
		// flat fields this ability actually uses and the model filled in
		// (see invocation_chat_model_schema() for why the model is never
		// asked to build a nested "input" object itself).
		$action_input = array();
		foreach ( $fields[ $ability ] as $field ) {
			if ( array_key_exists( $field, $decoded ) && null !== $decoded[ $field ] ) {
				$action_input[ $field ] = $decoded[ $field ];
			}
		}
		$action = array(
			'ability' => $ability,
			'input'   => $action_input,
		);
	}

	return array(
		'reply'  => (string) $decoded['reply'],
		'action' => $action,
		'debug'  => $debug,
	);
}
