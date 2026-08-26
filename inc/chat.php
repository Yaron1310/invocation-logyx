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
		'invocation/get-theme-context'   => 'Look up the site\'s theme.json design tokens. Input is always {} (no fields).',
		'invocation/list-blocks'         => 'List the block types actually registered on this site. Input is always {} (no fields).',
		'invocation/search-pages'        => 'Find a page/post by title and get its real id, status, and edit URL. Call this first whenever the user names a page (e.g. "the About page") and you do not already have its id from this conversation — never guess an id. Input example: {"query": "about"}.',
		'invocation/get-page'            => 'Fetch a page/post\'s current title and content (real block markup) by id. Call this before refine-block, and before update-page whenever only part of an existing page should change — never invent or guess what is currently on a page. Input is a JSON object with the id as a NUMBER field, never a bare number: {"id": 123}.',
		'invocation/search-media'        => 'Find an existing image in the media library by keyword. Input example: {"query": "hero"}.',
		'invocation/upload-media'        => 'Insert an image the user attached in chat into the media library. Only propose this when the user\'s message includes an attached image; its id is given in the conversation as ATTACHMENT_ID. Input example: {"data": "...base64...", "filename": "photo.jpg"}.',
		'invocation/generate-layout'     => 'Generate a whole new section or page of block markup from a text brief. Input example: {"prompt": "a pricing section with three tiers"}.',
		'invocation/refine-block'        => 'Rewrite one existing block in place (e.g. change a heading or a paragraph). Requires the block\'s exact current markup as blockMarkup — get it from get-page first, never invent it. Input example: {"blockMarkup": "<!-- wp:heading -->...<!-- /wp:heading -->", "instruction": "make it punchier"}.',
		'invocation/duplicate-page'      => 'Clone an existing page/post into a new draft, e.g. before editing a copy. Input is a JSON object, id as a NUMBER field, never a bare number: {"id": 123}.',
		'invocation/update-page'         => 'Change an existing page/post\'s title, content, status, or template by id. content, if given, REPLACES the entire page — it is never merged or patched server-side. To change only part of a page, first get-page, then build the complete new content yourself (the fetched content with only the relevant part edited) and pass that whole string as content. Calling this with only an id and no title/content/status/template changes nothing and errors. Input example: {"id": 123, "title": "New title"}.',
		'invocation/create-page'         => 'Create a brand-new page/post from a title and block markup. Input example: {"title": "New Page", "content": "<!-- wp:paragraph -->...<!-- /wp:paragraph -->"}.',
		'invocation/list-templates'      => 'List the page templates available on this theme. Input is always {} (no fields).',
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
 * Deliberately narrower than invocation_chat_response_schema(): a "type"
 * union like ["object", "null"] is JSON Schema, but Gemini's structured
 * output only accepts the OpenAPI 3.0-style single `type` plus a sibling
 * boolean `nullable` field — a bare type array 400s with "Proto field is
 * not repeating, cannot start list". This uses `nullable` instead.
 *
 * (An earlier version of this schema worked around the same error with a
 * hasAction boolean and an empty-string entry in the ability enum — that
 * traded one 400 for another, since Gemini also rejects an empty string
 * in `enum`. `nullable` is the documented, correct fix.)
 *
 * @return array<string, mixed>
 */
function invocation_chat_model_schema(): array {
	return array(
		'type'                 => 'object',
		'properties'           => array(
			'reply'  => array(
				'type'        => 'string',
				'description' => 'What to say to the user this turn: a question, an explanation, or a summary of what you are about to do.',
			),
			'action' => array(
				'type'                 => 'object',
				'nullable'             => true,
				'description'          => 'At most one ability call to propose this turn, or null to just reply. Wait for the tool result (given back to you as the next turn) before proposing another action.',
				'properties'           => array(
					'ability' => array(
						'type' => 'string',
						'enum' => array_keys( invocation_chat_available_abilities() ),
					),
					'input'   => array(
						'type'        => 'object',
						'description' => 'The input object for that ability, matching its own input schema.',
					),
				),
				'required'             => array( 'ability', 'input' ),
				'additionalProperties' => false,
			),
		),
		'required'             => array( 'reply', 'action' ),
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
		'You are the Invocation chat assistant for a WordPress site. You help the user find, draft, and edit pages by proposing one ability call at a time; you never invent block types, image URLs, block markup, or page ids that were not given to you or returned by a previous tool result. If the user names a page instead of giving you an id, call invocation/search-pages first and, if more than one result matches, ask the user which one before proposing any change to it. Before calling refine-block, or before update-page when only part of an existing page should change, call invocation/get-page first to get its real current content — never write blockMarkup from imagination. update-page\'s content field REPLACES the whole page: after get-page, build the complete new content string yourself by editing only the relevant part of what you fetched, and pass that entire string — a call to update-page with an id but no content/title/status/template changes nothing and errors. action.input is ALWAYS a JSON object ({...}), even for an ability that takes a single field — e.g. {"id": 123}, never the bare value 123 or the string "123" on its own.',
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

	$action   = null;
	$proposed = $decoded['action'] ?? null;
	if ( is_array( $proposed ) && array_key_exists( (string) ( $proposed['ability'] ?? '' ), invocation_chat_available_abilities() ) ) {
		// Refuse to hand the client an action it can't or shouldn't call.
		$action = array(
			'ability' => (string) $proposed['ability'],
			'input'   => is_array( $proposed['input'] ?? null ) ? $proposed['input'] : array(),
		);
	}

	return array(
		'reply'  => (string) $decoded['reply'],
		'action' => $action,
		'debug'  => $debug,
	);
}
