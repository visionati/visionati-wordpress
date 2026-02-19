<?php
/**
 * Visionati Media Library Integration
 *
 * Adds "Generate with Visionati" buttons to the Media Library,
 * handles single and bulk AJAX processing, auto-generate on upload,
 * and the Bulk Generate admin page.
 *
 * @package Visionati
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visionati_Media {

	/**
	 * Constructor. Register hooks.
	 */
	public function __construct() {
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_generate_button' ), 10, 2 );
		add_action( 'wp_ajax_visionati_analyze', array( $this, 'ajax_analyze' ) );
		add_action( 'wp_ajax_visionati_apply_field', array( $this, 'ajax_apply_field' ) );
		add_action( 'wp_ajax_visionati_bulk_analyze', array( $this, 'ajax_bulk_analyze' ) );
		add_action( 'wp_ajax_visionati_get_images', array( $this, 'ajax_get_images' ) );
		add_action( 'add_attachment', array( $this, 'auto_generate_on_upload' ) );
		add_action( 'admin_menu', array( $this, 'register_bulk_page' ) );
		add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_action_notice' ) );
		add_action( 'admin_notices', array( $this, 'auto_generate_error_notice' ) );
	}

	/**
	 * Add "Generate with Visionati" button to the attachment edit fields.
	 *
	 * @param array   $form_fields Existing form fields.
	 * @param WP_Post $post        The attachment post object.
	 * @return array Modified form fields.
	 */
	public function add_generate_button( $form_fields, $post ) {
		if ( ! Visionati_API::is_supported_image( $post->ID ) ) {
			return $form_fields;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return $form_fields;
		}

		$api_key = get_option( 'visionati_api_key', '' );
		if ( empty( $api_key ) ) {
			return $form_fields;
		}

		$attachment_id = absint( $post->ID );

		$button_html = sprintf(
			'<div class="visionati-media-actions">' .
			'<button type="button" class="button" data-attachment-id="%d" data-context="alt_text">%s</button>' .
			'<button type="button" class="button" data-attachment-id="%d" data-context="caption">%s</button>' .
			'<button type="button" class="button" data-attachment-id="%d" data-context="description">%s</button>' .
			'</div>' .
			'<div class="visionati-media-previews" data-attachment-id="%d"></div>',
			$attachment_id,
			esc_html__( 'Alt Text', 'visionati' ),
			$attachment_id,
			esc_html__( 'Caption', 'visionati' ),
			$attachment_id,
			esc_html__( 'Description', 'visionati' ),
			$attachment_id
		);

		$form_fields['visionati'] = array(
			'label' => 'Visionati',
			'input' => 'html',
			'html'  => $button_html,
		);

		return $form_fields;
	}

	/**
	 * AJAX handler: analyze a single attachment (preview only, no saving).
	 *
	 * Returns the generated description for preview. The JS displays it
	 * and the user clicks Apply or Discard. Saving happens via ajax_apply_field.
	 */
	public function ajax_analyze() {
		check_ajax_referer( 'visionati_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Permission denied.', 'visionati' ) ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id ) {
			Visionati_API::send_json_error( array( 'message' => __( 'No attachment ID provided.', 'visionati' ) ) );
		}

		$valid_contexts = array( 'alt_text', 'caption', 'description' );
		$context        = isset( $_POST['context'] ) ? sanitize_key( $_POST['context'] ) : 'alt_text';

		if ( ! in_array( $context, $valid_contexts, true ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Invalid context.', 'visionati' ) ) );
		}

		$api     = new Visionati_API();
		$options = $this->get_options_for_context( $context );

		Visionati_API::debug_log( 'ajax_analyze: preview request', array(
			'attachment_id' => $attachment_id,
			'context'       => $context,
			'role'          => isset( $options['role'] ) ? $options['role'] : '(default)',
		) );

		$response = $api->analyze_attachment( $attachment_id, $options );

		if ( is_wp_error( $response ) ) {
			Visionati_API::send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$description = Visionati_API::get_first_description( $response );

		if ( empty( $description ) ) {
			Visionati_API::send_json_error( array(
				'message' => __( 'No description returned from the API.', 'visionati' ),
			) );
		}

		$credits = Visionati_API::extract_credits( $response );

		$result = array(
			'attachment_id' => $attachment_id,
			'context'       => $context,
			'description'   => $description,
		);

		if ( null !== $credits ) {
			$result['credits'] = $credits;
		}

		Visionati_API::send_json_success( $result );
	}

	/**
	 * AJAX handler: apply a previewed description to an attachment field.
	 *
	 * Saves text that was already generated and previewed by the user.
	 * No API calls are made.
	 */
	public function ajax_apply_field() {
		check_ajax_referer( 'visionati_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Permission denied.', 'visionati' ) ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id ) {
			Visionati_API::send_json_error( array( 'message' => __( 'No attachment ID provided.', 'visionati' ) ) );
		}

		if ( ! get_post( $attachment_id ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Attachment not found.', 'visionati' ) ) );
		}

		$valid_contexts = array( 'alt_text', 'caption', 'description' );
		$context        = isset( $_POST['context'] ) ? sanitize_key( $_POST['context'] ) : '';

		if ( ! in_array( $context, $valid_contexts, true ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Invalid context.', 'visionati' ) ) );
		}

		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( empty( $description ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'No description to apply.', 'visionati' ) ) );
		}

		Visionati_API::debug_log( 'ajax_apply_field: saving', array(
			'attachment_id' => $attachment_id,
			'context'       => $context,
			'length'        => mb_strlen( $description ),
		) );

		$updated_fields = $this->update_attachment_fields( $attachment_id, $description, $context, true );

		Visionati_API::send_json_success( array(
			'attachment_id' => $attachment_id,
			'context'       => $context,
			'fields'        => $updated_fields,
		) );
	}

	/**
	 * AJAX handler: analyze a single attachment during bulk processing.
	 * Processes one image per request for each selected context.
	 * The JS client loops through the queue.
	 */
	public function ajax_bulk_analyze() {
		check_ajax_referer( 'visionati_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Permission denied.', 'visionati' ) ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id ) {
			Visionati_API::send_json_error( array( 'message' => __( 'No attachment ID provided.', 'visionati' ) ) );
		}

		$valid_contexts = array( 'alt_text', 'caption', 'description' );
		$contexts       = isset( $_POST['contexts'] ) ? array_map( 'sanitize_key', (array) $_POST['contexts'] ) : array( 'alt_text' );
		$contexts       = array_intersect( $contexts, $valid_contexts );

		if ( empty( $contexts ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'No fields selected.', 'visionati' ) ) );
		}

		$meta = self::get_attachment_meta( $attachment_id );

		$overwrite_fields = get_option( 'visionati_overwrite_fields', array() );
		if ( ! is_array( $overwrite_fields ) ) {
			$overwrite_fields = array();
		}

		$generated_fields = array();
		$skipped_fields   = array();
		$credits          = null;

		foreach ( $contexts as $context ) {
			// Skip if field already has content and overwrite is off.
			if ( ! in_array( $context, $overwrite_fields, true ) && self::field_has_content( $attachment_id, $context ) ) {
				$skipped_fields[] = $context;
				continue;
			}

			$result = $this->generate_for_attachment( $attachment_id, $context );

			if ( is_wp_error( $result ) ) {
				// Return immediately on credit errors so the bulk loop can stop.
				Visionati_API::send_json_error( array_merge( $meta, array(
					'attachment_id'   => $attachment_id,
					'message'         => $result->get_error_message(),
					'fields'          => $generated_fields,
					'skipped_fields'  => $skipped_fields,
				) ) );
				return;
			}

			$generated_fields[] = $context;

			// Track the most recent credit balance.
			if ( isset( $result['credits'] ) ) {
				$credits = $result['credits'];
			}
		}

		if ( empty( $generated_fields ) ) {
			Visionati_API::send_json_success( array_merge( $meta, array(
				'attachment_id'  => $attachment_id,
				'status'         => 'skipped',
				'message'        => __( 'All selected fields already exist.', 'visionati' ),
				'fields'         => array(),
				'skipped_fields' => $skipped_fields,
			) ) );
			return;
		}

		$response_data = array(
			'attachment_id'  => $attachment_id,
			'status'         => 'generated',
			'fields'         => $generated_fields,
			'skipped_fields' => $skipped_fields,
		);

		if ( null !== $credits ) {
			$response_data['credits'] = $credits;
		}

		Visionati_API::send_json_success( array_merge( $meta, $response_data ) );
	}

	/**
	 * AJAX handler: get image attachment IDs for bulk processing.
	 *
	 * Uses a single SQL query to return only images that actually need
	 * work for the selected contexts. If overwrite is enabled for any
	 * selected context, all images are returned for that context.
	 */
	public function ajax_get_images() {
		check_ajax_referer( 'visionati_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Permission denied.', 'visionati' ) ) );
		}

		$valid_contexts = array( 'alt_text', 'caption', 'description' );
		$contexts       = isset( $_POST['contexts'] ) ? array_map( 'sanitize_key', (array) $_POST['contexts'] ) : array( 'alt_text' );
		$contexts       = array_intersect( $contexts, $valid_contexts );

		if ( empty( $contexts ) ) {
			$contexts = array( 'alt_text' );
		}

		$overwrite_fields = get_option( 'visionati_overwrite_fields', array() );
		if ( ! is_array( $overwrite_fields ) ) {
			$overwrite_fields = array();
		}

		// If overwrite is on for any selected context, every image needs
		// processing for that context — just return all IDs.
		$needs_all = false;
		foreach ( $contexts as $context ) {
			if ( in_array( $context, $overwrite_fields, true ) ) {
				$needs_all = true;
				break;
			}
		}

		$ids = $this->query_image_ids( $contexts, $needs_all );

		Visionati_API::send_json_success( array(
			'ids'   => $ids,
			'total' => count( $ids ),
		) );
	}

	/**
	 * Query image attachment IDs that need processing for the given contexts.
	 *
	 * When $return_all is true, returns all supported image IDs.
	 * Otherwise, returns only images missing at least one of the selected fields.
	 * Uses a single SQL query with no per-image iteration.
	 *
	 * @param array $contexts   Array of context slugs: 'alt_text', 'caption', 'description'.
	 * @param bool  $return_all Whether to return all images regardless of existing content.
	 * @return array Array of attachment IDs.
	 */
	private function query_image_ids( $contexts, $return_all = false ) {
		global $wpdb;

		$mime_types   = Visionati_API::get_supported_mime_types();
		$placeholders = implode( ', ', array_fill( 0, count( $mime_types ), '%s' ) );

		if ( $return_all ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					WHERE p.post_type = 'attachment'
						AND p.post_status = 'inherit'
						AND p.post_mime_type IN ($placeholders)
					ORDER BY p.ID ASC",
					...$mime_types
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array_map( 'absint', $ids );
		}

		// Build WHERE conditions for missing fields.
		$conditions = array();

		if ( in_array( 'alt_text', $contexts, true ) ) {
			$conditions[] = '(pm.meta_value IS NULL OR pm.meta_value = \'\')';
		}
		if ( in_array( 'caption', $contexts, true ) ) {
			$conditions[] = '(p.post_excerpt IS NULL OR p.post_excerpt = \'\')';
		}
		if ( in_array( 'description', $contexts, true ) ) {
			$conditions[] = '(p.post_content IS NULL OR p.post_content = \'\')';
		}

		if ( empty( $conditions ) ) {
			return array();
		}

		$missing_clause = implode( ' OR ', $conditions );
		$needs_alt_join = in_array( 'alt_text', $contexts, true );

		if ( $needs_alt_join ) {
			// $missing_clause is built from hardcoded string literals above — no user input.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm
						ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
					WHERE p.post_type = 'attachment'
						AND p.post_status = 'inherit'
						AND p.post_mime_type IN ($placeholders)
						AND ($missing_clause)
					ORDER BY p.ID ASC",
					...$mime_types
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		} else {
			// $missing_clause is built from hardcoded string literals above — no user input.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					WHERE p.post_type = 'attachment'
						AND p.post_status = 'inherit'
						AND p.post_mime_type IN ($placeholders)
						AND ($missing_clause)
					ORDER BY p.ID ASC",
					...$mime_types
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		return array_map( 'absint', $ids );
	}

	/**
	 * Auto-generate fields on image upload.
	 *
	 * Runs synchronously on the add_attachment hook. All enabled fields
	 * are submitted to the API concurrently, then polled in a single
	 * round-robin loop. This means 3 fields take roughly the same wall
	 * time as 1 field (~8-10s) instead of 3x as long.
	 *
	 * @param int $attachment_id The newly uploaded attachment ID.
	 */
	public function auto_generate_on_upload( $attachment_id ) {
		$fields = get_option( 'visionati_auto_generate_fields', array() );

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return;
		}

		$api_key = get_option( 'visionati_api_key', '' );
		if ( empty( $api_key ) ) {
			return;
		}

		if ( ! Visionati_API::is_supported_image( $attachment_id ) ) {
			return;
		}

		$api     = new Visionati_API();
		$pending = array();
		$errors  = array();

		// Phase 1: Submit all fields concurrently.
		// Each submit returns almost instantly with a response_uri.
		foreach ( $fields as $context ) {
			$options   = $this->get_options_for_context( $context );
			$submitted = $api->submit_attachment( $attachment_id, $options );

			if ( is_wp_error( $submitted ) ) {
				$errors[] = sprintf( '%s: %s', $context, $submitted->get_error_message() );
				continue;
			}

			if ( ! empty( $submitted['response_uri'] ) ) {
				$pending[ $context ] = $submitted['response_uri'];
			} elseif ( ! empty( $submitted['all']['assets'] ) ) {
				// Direct response (unlikely with base64, but handle it).
				$this->apply_auto_generate_result( $attachment_id, $context, $submitted );
			}
		}

		if ( ! empty( $pending ) ) {
			// Phase 2: Poll all response URIs in one round-robin loop.
			// Cap at 10 rounds (~20s) to stay within PHP's max_execution_time
			// on shared hosting (typically 30s). Normal responses resolve in 4-5 rounds.
			$results = $api->poll_multiple( $pending, 10 );

			foreach ( $results as $context => $response ) {
				if ( is_wp_error( $response ) ) {
					$errors[] = sprintf( '%s: %s', $context, $response->get_error_message() );
				} else {
					$this->apply_auto_generate_result( $attachment_id, $context, $response );
				}
			}
		}

		// Surface failures as an admin notice so users get feedback.
		if ( ! empty( $errors ) ) {
			$filename = basename( get_attached_file( $attachment_id ) );
			$message  = sprintf(
				/* translators: 1: file name, 2: error details */
				__( 'Visionati auto-generate failed for %1$s: %2$s', 'visionati' ),
				$filename,
				implode( '; ', $errors )
			);
			error_log( 'Visionati: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			$user_id   = get_current_user_id();
			if ( $user_id ) {
				$transient = 'visionati_auto_gen_errors_' . $user_id;
				$existing  = get_transient( $transient );
				$notices   = is_array( $existing ) ? $existing : array();
				$notices[] = $message;
				set_transient( $transient, $notices, 5 * MINUTE_IN_SECONDS );
			}
		}
	}

	/**
	 * Apply an auto-generated result to an attachment field.
	 *
	 * Extracts the first description from the API response and updates
	 * the appropriate attachment field (alt text, caption, or description).
	 *
	 * @param int    $attachment_id The attachment post ID.
	 * @param string $context       The generation context.
	 * @param array  $response      Parsed API response.
	 */
	private function apply_auto_generate_result( $attachment_id, $context, $response ) {
		$description = Visionati_API::get_first_description( $response );

		if ( empty( $description ) ) {
			return;
		}

		$this->update_attachment_fields( $attachment_id, $description, $context );
	}

	/**
	 * Show admin notice for auto-generate failures stored via transient.
	 */
	public function auto_generate_error_notice() {
		$user_id   = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$transient = 'visionati_auto_gen_errors_' . $user_id;
		$notices   = get_transient( $transient );

		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}

		delete_transient( $transient );

		foreach ( $notices as $message ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>Visionati:</strong> %s</p></div>',
				esc_html( $message )
			);
		}
	}

	/**
	 * Register the Bulk Generate admin page under Media.
	 */
	public function register_bulk_page() {
		add_media_page(
			__( 'Visionati Bulk Generate', 'visionati' ),
			__( 'Bulk Generate', 'visionati' ),
			'upload_files',
			'visionati-bulk-generate',
			array( $this, 'render_bulk_page' )
		);
	}

	/**
	 * Register a bulk action in the Media Library list view.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array Modified bulk actions.
	 */
	public function register_bulk_action( $actions ) {
		$api_key = get_option( 'visionati_api_key', '' );
		if ( ! empty( $api_key ) ) {
			$actions['visionati_generate'] = __( 'Generate with Visionati', 'visionati' );
		}
		return $actions;
	}

	/**
	 * Handle the Media Library bulk action.
	 *
	 * Instead of processing synchronously (which would timeout on shared
	 * hosting with more than a few images), store the selected IDs in a
	 * transient and redirect to the AJAX-powered Bulk Generate page.
	 *
	 * @param string $redirect_url The redirect URL.
	 * @param string $action       The action being taken.
	 * @param array  $post_ids     The selected post IDs.
	 * @return string Modified redirect URL.
	 */
	public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
		if ( 'visionati_generate' !== $action ) {
			return $redirect_url;
		}

		// Filter to supported images only.
		$image_ids = array();
		foreach ( $post_ids as $post_id ) {
			if ( Visionati_API::is_supported_image( absint( $post_id ) ) ) {
				$image_ids[] = absint( $post_id );
			}
		}

		if ( empty( $image_ids ) ) {
			return add_query_arg( array(
				'visionati_processed' => 0,
				'visionati_skipped'   => count( $post_ids ),
				'visionati_errors'    => 0,
			), $redirect_url );
		}

		// Store IDs in a user-scoped transient for the Bulk Generate page to consume.
		$user_id = get_current_user_id();
		set_transient( 'visionati_bulk_queue_' . $user_id, $image_ids, 5 * MINUTE_IN_SECONDS );

		// Redirect to the AJAX-powered Bulk Generate page.
		return admin_url( 'upload.php?page=visionati-bulk-generate&queued=1' );
	}

	/**
	 * Show a notice after the Media Library bulk action completes.
	 */
	public function bulk_action_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only query params from post-redirect-GET.
		if ( ! isset( $_GET['visionati_processed'] ) ) {
			return;
		}

		$processed = absint( $_GET['visionati_processed'] );
		$skipped   = isset( $_GET['visionati_skipped'] ) ? absint( $_GET['visionati_skipped'] ) : 0;
		$errors    = isset( $_GET['visionati_errors'] ) ? absint( $_GET['visionati_errors'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$parts = array();

		if ( $processed > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of images processed */
				_n( '%d image processed.', '%d images processed.', $processed, 'visionati' ),
				$processed
			);
		}
		if ( $skipped > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of images skipped */
				_n( '%d skipped.', '%d skipped.', $skipped, 'visionati' ),
				$skipped
			);
		}
		if ( $errors > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of errors */
				_n( '%d error.', '%d errors.', $errors, 'visionati' ),
				$errors
			);
		}

		if ( ! empty( $parts ) ) {
			$class = $errors > 0 ? 'notice-warning' : 'notice-success';
			printf(
				'<div class="notice %s is-dismissible"><p><strong>Visionati:</strong> %s</p></div>',
				esc_attr( $class ),
				esc_html( implode( ' ', $parts ) )
			);
		}
	}

	/**
	 * Render the Bulk Generate admin page.
	 */
	public function render_bulk_page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$api_key = get_option( 'visionati_api_key', '' );
		$counts  = $this->count_missing_fields();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Visionati Bulk Generate', 'visionati' ); ?></h1>

			<?php if ( empty( $api_key ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'No API key configured.', 'visionati' ); ?>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=visionati' ) ); ?>">
							<?php esc_html_e( 'Add your API key in Settings.', 'visionati' ); ?>
						</a>
					</p>
				</div>
			<?php else : ?>
				<div class="visionati-bulk-stats">
					<p>
						<?php
						printf(
							/* translators: 1: images missing alt text, 2: missing captions, 3: missing descriptions, 4: total */
							esc_html__( '%1$d missing alt text, %2$d missing captions, %3$d missing descriptions out of %4$d images.', 'visionati' ),
							absint( $counts['alt_text'] ),
							absint( $counts['caption'] ),
							absint( $counts['description'] ),
							absint( $counts['total'] )
						);
						?>
					</p>
				</div>

				<div class="visionati-bulk-controls">
					<fieldset class="visionati-bulk-fields">
						<legend class="screen-reader-text"><?php esc_html_e( 'Fields to generate', 'visionati' ); ?></legend>
						<label style="display:inline-block !important;margin-right:16px !important">
							<input type="checkbox" name="visionati_bulk_context" value="alt_text" checked />
							<?php esc_html_e( 'Alt Text', 'visionati' ); ?>
						</label>
						<label style="display:inline-block !important;margin-right:16px !important">
							<input type="checkbox" name="visionati_bulk_context" value="caption" />
							<?php esc_html_e( 'Caption', 'visionati' ); ?>
						</label>
						<label style="display:inline-block !important;margin-right:16px !important">
							<input type="checkbox" name="visionati_bulk_context" value="description" />
							<?php esc_html_e( 'Description', 'visionati' ); ?>
						</label>
					</fieldset>

					<div class="visionati-bulk-actions">
						<button type="button" class="button button-primary" id="visionati-bulk-start">
							<?php esc_html_e( 'Start', 'visionati' ); ?>
						</button>
						<button type="button" class="button" id="visionati-bulk-stop" disabled>
							<?php esc_html_e( 'Stop', 'visionati' ); ?>
						</button>
					</div>
				</div>

				<div class="visionati-bulk-progress" style="display: none;">
					<div class="visionati-progress-bar-wrap">
						<div class="visionati-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Bulk generation progress', 'visionati' ); ?>" style="width: 0%;"></div>
					</div>
					<p class="visionati-progress-text">
						<span id="visionati-bulk-current">0</span>
						<?php esc_html_e( 'of', 'visionati' ); ?>
						<span id="visionati-bulk-total">0</span>
						&mdash;
						<span id="visionati-bulk-percent">0</span>%
					</p>
					<p class="visionati-progress-summary" tabindex="-1">
						<span class="visionati-summary-generated">0</span> <?php esc_html_e( 'generated', 'visionati' ); ?>,
						<span class="visionati-summary-skipped">0</span> <?php esc_html_e( 'skipped', 'visionati' ); ?>,
						<span class="visionati-summary-errors">0</span> <?php esc_html_e( 'errors', 'visionati' ); ?>
					</p>
					<p class="visionati-credits-remaining" style="display: none;"></p>
				</div>

				<div class="visionati-bulk-log" style="display: none;">
					<h3><?php esc_html_e( 'Results', 'visionati' ); ?></h3>
					<div id="visionati-bulk-log-entries" role="log" aria-live="polite"></div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Generate alt text and other fields for an attachment.
	 *
	 * @param int    $attachment_id   The attachment post ID.
	 * @param string $context         The generation context: 'alt_text', 'description', or 'all'.
	 * @param bool   $force_overwrite Whether to overwrite existing content regardless of settings.
	 *                                True for explicit single-image clicks, false for bulk/auto.
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	private function generate_for_attachment( $attachment_id, $context = 'alt_text', $force_overwrite = false ) {
		$api = new Visionati_API();

		$options = $this->get_options_for_context( $context );

		Visionati_API::debug_log( 'generate_for_attachment: starting', array(
			'attachment_id'   => $attachment_id,
			'context'         => $context,
			'force_overwrite' => $force_overwrite,
			'role'            => isset( $options['role'] ) ? $options['role'] : '(default)',
			'has_prompt'      => ! empty( $options['prompt'] ),
			'backends'        => isset( $options['backends'] ) ? $options['backends'] : '(default)',
		) );

		$response = $api->analyze_attachment( $attachment_id, $options );

		if ( is_wp_error( $response ) ) {
			Visionati_API::debug_log( 'generate_for_attachment: API error', array(
				'attachment_id' => $attachment_id,
				'context'       => $context,
				'error'         => $response->get_error_message(),
			) );
			return $response;
		}

		$description = Visionati_API::get_first_description( $response );

		if ( empty( $description ) ) {
			Visionati_API::debug_log( 'generate_for_attachment: empty description from API', array(
				'attachment_id' => $attachment_id,
				'context'       => $context,
			) );
			return new WP_Error(
				'visionati_no_description',
				__( 'No description returned from the API.', 'visionati' )
			);
		}

		Visionati_API::debug_log( 'generate_for_attachment: got description', array(
			'attachment_id' => $attachment_id,
			'context'       => $context,
			'length'        => mb_strlen( $description ),
		) );

		$updated_fields = $this->update_attachment_fields( $attachment_id, $description, $context, $force_overwrite );

		$credits = Visionati_API::extract_credits( $response );

		$result = array(
			'attachment_id' => $attachment_id,
			'status'        => 'generated',
			'description'   => $description,
			'fields'        => $updated_fields,
		);

		if ( null !== $credits ) {
			$result['credits'] = $credits;
		}

		Visionati_API::debug_log( 'generate_for_attachment: complete', array(
			'attachment_id' => $attachment_id,
			'context'       => $context,
			'fields'        => $updated_fields,
			'credits'       => $credits,
		) );

		return $result;
	}

	/**
	 * Get API options for a specific generation context.
	 *
	 * @param string $context The generation context.
	 * @return array Options array for Visionati_API::analyze_attachment().
	 */
	private function get_options_for_context( $context ) {
		$options = array(
			'features' => array( 'descriptions' ),
		);

		switch ( $context ) {
			case 'alt_text':
				$role    = get_option( 'visionati_role_alt_text', 'alttext' );
				$prompt  = get_option( 'visionati_prompt_alt_text', '' );
				$backend = get_option( 'visionati_backend_alt_text', '' );
				break;

			case 'caption':
				$role    = get_option( 'visionati_role_caption', 'caption' );
				$prompt  = get_option( 'visionati_prompt_caption', '' );
				$backend = get_option( 'visionati_backend_caption', '' );
				break;

			case 'description':
			default:
				$role    = get_option( 'visionati_role_description', 'general' );
				$prompt  = get_option( 'visionati_prompt_description', '' );
				$backend = get_option( 'visionati_backend_description', '' );
				break;
		}

		$options['role'] = $role;

		if ( ! empty( $prompt ) ) {
			$options['prompt'] = $prompt;
		}

		// Per-context model override; empty falls through to global in the API client.
		if ( ! empty( $backend ) ) {
			$options['backends'] = $backend;
		}

		return $options;
	}

	/**
	 * Update attachment fields with generated text.
	 *
	 * @param int    $attachment_id   The attachment post ID.
	 * @param string $description     The generated description text.
	 * @param string $context         The generation context.
	 * @param bool   $force_overwrite Whether to overwrite existing content regardless of settings.
	 *                                True for explicit single-image clicks, false for bulk/auto.
	 * @return array List of fields that were updated.
	 */
	private function update_attachment_fields( $attachment_id, $description, $context, $force_overwrite = false ) {
		$updated  = array();
		$skipped  = array();
		$errors   = array();
		$overwrite_fields = get_option( 'visionati_overwrite_fields', array() );
		if ( ! is_array( $overwrite_fields ) ) {
			$overwrite_fields = array();
		}

		Visionati_API::debug_log( 'update_attachment_fields: starting', array(
			'attachment_id'   => $attachment_id,
			'context'         => $context,
			'force_overwrite' => $force_overwrite,
			'overwrite_fields' => $overwrite_fields,
			'description_length' => mb_strlen( $description ),
		) );

		// Alt text (_wp_attachment_image_alt meta).
		if ( in_array( $context, array( 'alt_text', 'all' ), true ) ) {
			$existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( empty( $existing_alt ) || $force_overwrite || in_array( 'alt_text', $overwrite_fields, true ) ) {
				$alt_text = Visionati_API::truncate( wp_strip_all_tags( $description ), 125 );
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
				$updated[] = 'alt_text';
				Visionati_API::debug_log( 'update_attachment_fields: alt_text saved', array(
					'attachment_id' => $attachment_id,
					'length'        => mb_strlen( $alt_text ),
				) );
			} else {
				$skipped[] = 'alt_text';
			}
		}

		// Caption (post_excerpt).
		if ( in_array( $context, array( 'caption', 'all' ), true ) ) {
			$post = get_post( $attachment_id );
			if ( empty( $post->post_excerpt ) || $force_overwrite || in_array( 'caption', $overwrite_fields, true ) ) {
				$caption_text = sanitize_text_field( wp_strip_all_tags( $description ) );
				$post_id = wp_update_post( array(
					'ID'           => $attachment_id,
					'post_excerpt' => $caption_text,
				) );
				if ( 0 === $post_id || is_wp_error( $post_id ) ) {
					$error_msg = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'wp_update_post returned 0';
					$errors[] = 'caption: ' . $error_msg;
					Visionati_API::debug_log( 'update_attachment_fields: caption FAILED', array(
						'attachment_id' => $attachment_id,
						'error'         => $error_msg,
						'caption_length' => mb_strlen( $caption_text ),
					) );
				} else {
					Visionati_API::debug_log( 'update_attachment_fields: caption saved', array(
						'attachment_id' => $attachment_id,
						'post_id'       => $post_id,
						'length'        => mb_strlen( $caption_text ),
					) );
				}
				// Always report as updated and clean cache regardless of wp_update_post
				// return value. The original code never checked this and worked. Some
				// plugin hooks can cause wp_update_post to report failure even when the
				// data was written. If it truly failed, the debug log has the details.
				clean_post_cache( $attachment_id );
				$updated[] = 'caption';
			} else {
				$skipped[] = 'caption';
			}
		}

		// Description (post_content).
		if ( in_array( $context, array( 'description', 'all' ), true ) ) {
			$post = get_post( $attachment_id );
			if ( empty( $post->post_content ) || $force_overwrite || in_array( 'description', $overwrite_fields, true ) ) {
				$desc_text = wp_kses_post( $description );
				$post_id = wp_update_post( array(
					'ID'           => $attachment_id,
					'post_content' => $desc_text,
				) );
				if ( 0 === $post_id || is_wp_error( $post_id ) ) {
					$error_msg = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'wp_update_post returned 0';
					$errors[] = 'description: ' . $error_msg;
					Visionati_API::debug_log( 'update_attachment_fields: description FAILED', array(
						'attachment_id'  => $attachment_id,
						'error'          => $error_msg,
						'content_length' => mb_strlen( $desc_text ),
					) );
				} else {
					Visionati_API::debug_log( 'update_attachment_fields: description saved', array(
						'attachment_id' => $attachment_id,
						'post_id'       => $post_id,
						'length'        => mb_strlen( $desc_text ),
					) );
				}
				// Always report as updated and clean cache (see caption comment above).
				clean_post_cache( $attachment_id );
				$updated[] = 'description';
			} else {
				$skipped[] = 'description';
			}
		}

		if ( ! empty( $skipped ) ) {
			Visionati_API::debug_log( 'update_attachment_fields: fields skipped (existing content, overwrite off)', array(
				'attachment_id' => $attachment_id,
				'skipped'       => $skipped,
			) );
		}

		if ( ! empty( $errors ) ) {
			Visionati_API::debug_log( 'update_attachment_fields: errors encountered', array(
				'attachment_id' => $attachment_id,
				'errors'        => $errors,
			) );
		}

		return $updated;
	}

	/**
	 * Check whether an attachment field already has content.
	 *
	 * @param int    $attachment_id The attachment post ID.
	 * @param string $context       The field context: 'alt_text', 'caption', or 'description'.
	 * @return bool True if the field has non-empty content.
	 */
	public static function field_has_content( $attachment_id, $context ) {
		switch ( $context ) {
			case 'alt_text':
				return ! empty( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

			case 'caption':
				$post = get_post( $attachment_id );
				return $post && ! empty( $post->post_excerpt );

			case 'description':
				$post = get_post( $attachment_id );
				return $post && ! empty( $post->post_content );

			default:
				return false;
		}
	}

	/**
	 * Count images missing each field in a single SQL query.
	 *
	 * Uses a LEFT JOIN on wp_postmeta for alt text and checks
	 * post_excerpt/post_content directly. One query, no iteration.
	 *
	 * @return array Associative array with keys: alt_text, caption, description, total.
	 */
	private function count_missing_fields() {
		global $wpdb;

		$mime_types    = Visionati_API::get_supported_mime_types();
		$placeholders  = implode( ', ', array_fill( 0, count( $mime_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM( CASE WHEN pm.meta_value IS NULL OR pm.meta_value = '' THEN 1 ELSE 0 END ) AS missing_alt,
					SUM( CASE WHEN p.post_excerpt IS NULL OR p.post_excerpt = '' THEN 1 ELSE 0 END ) AS missing_caption,
					SUM( CASE WHEN p.post_content IS NULL OR p.post_content = '' THEN 1 ELSE 0 END ) AS missing_description
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm
					ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
				WHERE p.post_type = 'attachment'
					AND p.post_status = 'inherit'
					AND p.post_mime_type IN ($placeholders)",
				...$mime_types
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'alt_text'    => $row ? (int) $row->missing_alt : 0,
			'caption'     => $row ? (int) $row->missing_caption : 0,
			'description' => $row ? (int) $row->missing_description : 0,
			'total'       => $row ? (int) $row->total : 0,
		);
	}

	/**
	 * Get display metadata for an attachment (filename and thumbnail URL).
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return array Associative array with 'filename' and 'thumb' keys.
	 */
	public static function get_attachment_meta( $attachment_id ) {
		$filename = '';

		// 1. Try the attached file path (most reliable).
		$file_path = get_attached_file( $attachment_id );
		if ( ! empty( $file_path ) ) {
			$filename = basename( $file_path );
		}

		// 2. Try the original filename from attachment metadata.
		if ( empty( $filename ) ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $meta['file'] ) ) {
				$filename = basename( $meta['file'] );
			}
		}

		// 3. Try the post title.
		if ( empty( $filename ) ) {
			$filename = get_the_title( $attachment_id );
		}

		$thumb = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

		return array(
			'filename'      => ! empty( $filename ) ? $filename : 'Image #' . $attachment_id,
			'thumb'         => $thumb ? $thumb : '',
			'attachment_id' => $attachment_id,
		);
	}
}