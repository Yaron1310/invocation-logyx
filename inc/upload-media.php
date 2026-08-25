<?php
/**
 * The invocation/upload-media ability.
 *
 * Lets the AI chat put a user-supplied image straight into the WordPress media
 * library, so it can then be used as real media (via invocation/search-media or
 * directly in generated markup) instead of being invented.
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MIME types the upload ability accepts, keyed by the file extension used when
 * none is supplied. Deliberately images only — this ability exists for the
 * "insert an image from chat" flow, not general file upload.
 */
const INVOCATION_UPLOAD_MEDIA_MIME_TYPES = array(
	'image/jpeg' => 'jpg',
	'image/png'  => 'png',
	'image/gif'  => 'gif',
	'image/webp' => 'webp',
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'invocation/upload-media',
			array(
				'label'               => __( 'Upload Media', 'invocation' ),
				'description'         => __( 'Uploads a base64-encoded image into the WordPress media library and returns its real attachment id and URL, so it can be used in a page instead of an invented one.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'data'     => array(
							'type'        => 'string',
							'description' => 'The image contents, base64-encoded. A data: URL prefix (e.g. "data:image/png;base64,") is stripped automatically if present.',
						),
						'filename' => array(
							'type'        => 'string',
							'description' => 'Original filename, used to name the attachment and infer its type when mimeType is omitted.',
						),
						'mimeType' => array(
							'type'        => 'string',
							'description' => 'Image MIME type (image/jpeg, image/png, image/gif, or image/webp). Inferred from filename if omitted.',
							'enum'        => array_keys( INVOCATION_UPLOAD_MEDIA_MIME_TYPES ),
						),
						'alt'      => array(
							'type'        => 'string',
							'description' => 'Alt text for the new attachment.',
						),
					),
					'required'             => array( 'data', 'filename' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'url'      => array( 'type' => 'string' ),
						'title'    => array( 'type' => 'string' ),
						'alt'      => array( 'type' => 'string' ),
						'mimeType' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => 'invocation_ability_upload_media',
				'permission_callback' => static fn (): bool => current_user_can( 'upload_files' ),
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
 * Execute callback for invocation/upload-media.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed>|WP_Error
 */
function invocation_ability_upload_media( array $input = array() ) {
	$raw      = (string) ( $input['data'] ?? '' );
	$filename = sanitize_file_name( (string) ( $input['filename'] ?? '' ) );
	$alt      = (string) ( $input['alt'] ?? '' );
	$mime     = (string) ( $input['mimeType'] ?? '' );

	if ( '' === $raw || '' === $filename ) {
		return new WP_Error( 'invocation_missing_data', __( 'Both image data and a filename are required.', 'invocation' ) );
	}

	// Strip a data: URL prefix if the caller sent one whole, e.g. from a
	// browser FileReader result ("data:image/png;base64,....").
	if ( preg_match( '/^data:([^;]+);base64,(.+)$/s', $raw, $matches ) ) {
		if ( '' === $mime ) {
			$mime = $matches[1];
		}
		$raw = $matches[2];
	}

	if ( '' === $mime ) {
		$ext_type = wp_check_filetype( $filename );
		$mime     = (string) ( $ext_type['type'] ?? '' );
	}

	if ( ! isset( INVOCATION_UPLOAD_MEDIA_MIME_TYPES[ $mime ] ) ) {
		return new WP_Error( 'invocation_unsupported_type', __( 'Only JPEG, PNG, GIF, and WebP images are supported.', 'invocation' ) );
	}

	$decoded = base64_decode( $raw, true );
	if ( false === $decoded || '' === $decoded ) {
		return new WP_Error( 'invocation_invalid_data', __( 'Image data could not be decoded; expected base64.', 'invocation' ) );
	}

	// Cap at WordPress's own upload size limit rather than trusting the client.
	$max_bytes = wp_max_upload_size();
	if ( $max_bytes > 0 && strlen( $decoded ) > $max_bytes ) {
		return new WP_Error( 'invocation_too_large', __( 'Image exceeds the maximum upload size for this site.', 'invocation' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	if ( '' === pathinfo( $filename, PATHINFO_EXTENSION ) ) {
		$filename .= '.' . INVOCATION_UPLOAD_MEDIA_MIME_TYPES[ $mime ];
	}

	$tmp_file = wp_tempnam( $filename );
	if ( ! $tmp_file ) {
		return new WP_Error( 'invocation_tmp_failed', __( 'Could not create a temporary file for the upload.', 'invocation' ) );
	}

	if ( false === file_put_contents( $tmp_file, $decoded ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_delete_file( $tmp_file );
		return new WP_Error( 'invocation_write_failed', __( 'Could not write the uploaded image to disk.', 'invocation' ) );
	}

	$attachment_id = media_handle_sideload(
		array(
			'name'     => $filename,
			'type'     => $mime,
			'tmp_name' => $tmp_file,
		),
		0
	);

	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $tmp_file );
		return $attachment_id;
	}

	if ( '' !== $alt ) {
		update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $alt );
	}

	return array(
		'id'       => (int) $attachment_id,
		'url'      => (string) wp_get_attachment_url( (int) $attachment_id ),
		'title'    => (string) get_the_title( (int) $attachment_id ),
		'alt'      => $alt,
		'mimeType' => $mime,
	);
}
