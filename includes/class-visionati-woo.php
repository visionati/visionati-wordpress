<?php
/**
 * Visionati WooCommerce Integration
 *
 * Adds "Generate Description" meta box to product edit screens,
 * constructs context-aware prompts using product data, generates
 * short and long descriptions, and provides bulk actions.
 *
 * Only loaded when WooCommerce is active.
 *
 * @package Visionati
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visionati_Woo {

	/**
	 * Check if a content string is effectively empty.
	 *
	 * WooCommerce stores descriptions as post_excerpt/post_content which may
	 * contain invisible HTML from TinyMCE (e.g. <p></p>, <br>, &nbsp;) even
	 * when the editor appears blank. A plain empty() check sees these as
	 * non-empty, causing generation to be skipped for "blank" products.
	 *
	 * @param string $content The content to check.
	 * @return bool True if the content is empty or contains only HTML/whitespace.
	 */
	private static function is_content_empty( $content ) {
		if ( empty( $content ) ) {
			return true;
		}

		// Strip HTML tags, decode entities (&nbsp; → space), then trim whitespace.
		$stripped = trim( html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES, 'UTF-8' ) );

		return '' === $stripped;
	}

	/**
	 * Prompt boilerplate appended to all WooCommerce prompts.
	 *
	 * @var string
	 */
	const PROMPT_BOILERPLATE = 'The response should use affirmative language with no ambiguous words such as might, should, or may. Include relevant keywords for SEO. Do not attempt to name the product in the image.';

	/**
	 * Constructor. Register hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_menu', array( $this, 'register_bulk_page' ) );
		add_action( 'wp_ajax_visionati_woo_generate', array( $this, 'ajax_generate_description' ) );
		add_action( 'wp_ajax_visionati_woo_apply', array( $this, 'ajax_apply_descriptions' ) );
		add_action( 'wp_ajax_visionati_woo_bulk_generate', array( $this, 'ajax_bulk_generate_single' ) );
		add_action( 'wp_ajax_visionati_woo_get_products', array( $this, 'ajax_get_products' ) );
		add_action( 'wp_ajax_visionati_woo_get_stats', array( $this, 'ajax_get_stats' ) );
		add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_action_notice' ) );
	}

	/**
	 * Add the Visionati meta box to the product edit screen.
	 */
	public function add_meta_box() {
		$api_key = get_option( 'visionati_api_key', '' );
		if ( empty( $api_key ) ) {
			return;
		}

		add_meta_box(
			'visionati-woo-generate',
			__( 'Visionati', 'visionati' ),
			array( $this, 'render_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * @param WP_Post $post The product post object.
	 */
	public function render_meta_box( $post ) {
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		?>
		<div class="visionati-woo-meta-box">
			<?php if ( empty( $thumbnail_id ) ) : ?>
				<p class="visionati-woo-notice">
					<?php esc_html_e( 'Set a product image to generate descriptions.', 'visionati' ); ?>
				</p>
			<?php else : ?>
				<p class="visionati-woo-help">
					<?php esc_html_e( 'Generate short and long product descriptions from the featured image.', 'visionati' ); ?>
				</p>
				<div class="visionati-woo-actions">
					<button type="button" class="button button-primary visionati-woo-generate-btn" data-product-id="<?php echo absint( $post->ID ); ?>">
						<?php esc_html_e( 'Generate Descriptions', 'visionati' ); ?>
					</button>
					<span class="visionati-woo-status"></span>
				</div>
				<div class="visionati-woo-results" style="display: none;">
					<h4><?php esc_html_e( 'Preview', 'visionati' ); ?></h4>
					<div class="visionati-woo-preview-short">
						<strong><?php esc_html_e( 'Short description:', 'visionati' ); ?></strong>
						<div class="visionati-woo-preview-text" id="visionati-woo-short-preview"></div>
						<button type="button" class="button visionati-woo-apply-single-btn" data-product-id="<?php echo absint( $post->ID ); ?>" data-field="short">
							<?php esc_html_e( 'Apply', 'visionati' ); ?>
						</button>
						<span class="visionati-woo-field-status"></span>
					</div>
					<div class="visionati-woo-preview-long">
						<strong><?php esc_html_e( 'Long description:', 'visionati' ); ?></strong>
						<div class="visionati-woo-preview-text" id="visionati-woo-long-preview"></div>
						<button type="button" class="button visionati-woo-apply-single-btn" data-product-id="<?php echo absint( $post->ID ); ?>" data-field="long">
							<?php esc_html_e( 'Apply', 'visionati' ); ?>
						</button>
						<span class="visionati-woo-field-status"></span>
					</div>
					<div class="visionati-woo-apply-actions">
						<button type="button" class="button visionati-woo-apply-btn" data-product-id="<?php echo absint( $post->ID ); ?>">
							<?php esc_html_e( 'Apply to Product', 'visionati' ); ?>
						</button>
						<button type="button" class="button visionati-woo-discard-btn">
							<?php esc_html_e( 'Discard', 'visionati' ); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler: generate descriptions for a single product (preview only, no saving).
	 */
	public function ajax_generate_description() {
		check_ajax_referer( 'visionati_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Permission denied.', 'visionati' ) ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			Visionati_API::send_json_error( array( 'message' => __( 'No product ID provided.', 'visionati' ) ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Product not found.', 'visionati' ) ) );
		}

		$thumbnail_id = $product->get_image_id();
		if ( empty( $thumbnail_id ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Product has no featured image.', 'visionati' ) ) );
		}

		$result = $this->generate_product_descriptions( $product, $thumbnail_id, array(), true );

		if ( is_wp_error( $result ) ) {
			Visionati_API::send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Visionati_API::send_json_success( $result );
	}

	/**
	 * AJAX handler: apply previously previewed descriptions to a product.
	 *
	 * Saves the text that was already generated and previewed by the user.
	 * No API calls are made. This ensures the user gets exactly what they previewed.
	 */
	public function ajax_apply_descriptions() {
		check_ajax_referer( 'visionati_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Permission denied.', 'visionati' ) ) );
		}

		$product_id        = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$short_description = isset( $_POST['short_description'] ) ? sanitize_text_field( wp_unslash( $_POST['short_description'] ) ) : '';
		$long_description  = isset( $_POST['long_description'] ) ? wp_kses_post( wp_unslash( $_POST['long_description'] ) ) : '';

		Visionati_API::debug_log( 'ajax_apply_descriptions: received POST data', array(
			'product_id'       => $product_id,
			'short_length'     => mb_strlen( $short_description ),
			'long_length'      => mb_strlen( $long_description ),
			'short_empty'      => empty( $short_description ),
			'long_empty'       => empty( $long_description ),
			'raw_short_isset'  => isset( $_POST['short_description'] ),
			'raw_short_length' => isset( $_POST['short_description'] ) ? mb_strlen( $_POST['short_description'] ) : 'N/A',
		) );

		if ( ! $product_id ) {
			Visionati_API::send_json_error( array( 'message' => __( 'No product ID provided.', 'visionati' ) ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Product not found.', 'visionati' ) ) );
		}

		if ( empty( $short_description ) && empty( $long_description ) ) {
			Visionati_API::debug_log( 'ajax_apply_descriptions: both descriptions empty after sanitization, aborting' );
			Visionati_API::send_json_error( array( 'message' => __( 'No descriptions to apply.', 'visionati' ) ) );
		}

		$fields = $this->save_product_descriptions( $product, $short_description, $long_description );

		if ( is_wp_error( $fields ) ) {
			Visionati_API::send_json_error( array( 'message' => $fields->get_error_message() ) );
		}

		Visionati_API::send_json_success( array(
			'product_id'        => $product_id,
			'status'            => 'applied',
			'fields'            => $fields,
			'short_description' => $short_description,
			'long_description'  => $long_description,
		) );
	}

	/**
	 * AJAX handler: generate descriptions for a single product during bulk processing.
	 *
	 * Unlike the meta box flow (generate → preview → apply), bulk processing
	 * generates and saves in one step. Also generates alt text for the featured
	 * image if missing.
	 */
	public function ajax_bulk_generate_single() {
		check_ajax_referer( 'visionati_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Permission denied.', 'visionati' ) ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			Visionati_API::send_json_error( array( 'message' => __( 'No product ID provided.', 'visionati' ) ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			Visionati_API::send_json_error( array(
				'product_id' => $product_id,
				'message'    => __( 'Product not found.', 'visionati' ),
			) );
		}

		$meta = self::get_product_meta( $product );

		$thumbnail_id = $product->get_image_id();
		if ( empty( $thumbnail_id ) ) {
			Visionati_API::send_json_success( array_merge( $meta, array(
				'product_id' => $product_id,
				'status'     => 'skipped',
				'message'    => __( 'No featured image.', 'visionati' ),
			) ) );
			return;
		}

		$overwrite_fields = get_option( 'visionati_overwrite_fields', array() );
		if ( ! is_array( $overwrite_fields ) ) {
			$overwrite_fields = array();
		}

		// Check if both fields already have content.
		$overwrite_desc = in_array( 'description', $overwrite_fields, true );
		if ( ! $overwrite_desc ) {
			$has_short = ! self::is_content_empty( $product->get_short_description() );
			$has_long  = ! self::is_content_empty( $product->get_description() );

			if ( $has_short && $has_long ) {
				Visionati_API::send_json_success( array_merge( $meta, array(
					'product_id' => $product_id,
					'status'     => 'skipped',
					'message'    => __( 'Descriptions already exist.', 'visionati' ),
				) ) );
				return;
			}
		}

		// Build extra submissions for alt text if needed (included in same parallel batch).
		$extra = array();
		$overwrite_alt = in_array( 'alt_text', $overwrite_fields, true );
		$existing_alt  = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );

		if ( empty( $existing_alt ) || $overwrite_alt ) {
			$alt_options = array(
				'role'     => get_option( 'visionati_role_alt_text', 'alttext' ),
				'features' => array( 'descriptions' ),
			);

			$alt_prompt  = get_option( 'visionati_prompt_alt_text', '' );
			$alt_backend = get_option( 'visionati_backend_alt_text', '' );

			if ( ! empty( $alt_prompt ) ) {
				$alt_options['prompt'] = $alt_prompt;
			}
			if ( ! empty( $alt_backend ) ) {
				$alt_options['backends'] = $alt_backend;
			}

			$extra['alt_text_response'] = $alt_options;
		}

		// Generate descriptions + alt text in parallel (no saving).
		$result = $this->generate_product_descriptions( $product, $thumbnail_id, $extra );

		if ( is_wp_error( $result ) ) {
			Visionati_API::send_json_error( array_merge( $meta, array(
				'product_id' => $product_id,
				'message'    => $result->get_error_message(),
			) ) );
		}

		// Save descriptions to the product.
		$short = isset( $result['short_description'] ) ? $result['short_description'] : '';
		$long  = isset( $result['long_description'] ) ? $result['long_description'] : '';
		$fields = $this->save_product_descriptions( $product, $short, $long );

		if ( is_wp_error( $fields ) ) {
			Visionati_API::send_json_error( array_merge( $meta, array(
				'product_id' => $product_id,
				'message'    => $fields->get_error_message(),
			) ) );
		}

		$result['fields'] = $fields;

		// Save alt text if it was generated in the same batch.
		if ( isset( $result['alt_text_response'] ) ) {
			$alt_text = Visionati_API::get_first_description( $result['alt_text_response'] );
			if ( ! empty( $alt_text ) ) {
				$alt_text = Visionati_API::truncate( wp_strip_all_tags( $alt_text ), 125 );
				update_post_meta( $thumbnail_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
				$result['fields'][] = 'alt_text';
				$result['alt_text'] = $alt_text;
			}
			unset( $result['alt_text_response'] );
		}

		Visionati_API::send_json_success( array_merge( $meta, $result ) );
	}

	/**
	 * AJAX handler: get product IDs for bulk processing.
	 *
	 * Uses a single SQL query to return only products that need work.
	 * If overwrite is enabled for descriptions, returns all products
	 * with images. Otherwise returns only those missing short or long
	 * descriptions.
	 */
	public function ajax_get_products() {
		check_ajax_referer( 'visionati_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Permission denied.', 'visionati' ) ) );
		}

		$overwrite_fields = get_option( 'visionati_overwrite_fields', array() );
		if ( ! is_array( $overwrite_fields ) ) {
			$overwrite_fields = array();
		}
		$overwrite_desc = in_array( 'description', $overwrite_fields, true );

		$valid_statuses = array( 'publish', 'draft', 'pending', 'private' );
		$statuses       = isset( $_POST['statuses'] ) ? array_map( 'sanitize_key', (array) $_POST['statuses'] ) : $valid_statuses;
		$statuses       = array_intersect( $statuses, $valid_statuses );

		if ( empty( $statuses ) ) {
			$statuses = $valid_statuses;
		}

		$ids = $this->query_product_ids( $overwrite_desc, $statuses );

		Visionati_API::send_json_success( array(
			'ids'   => $ids,
			'total' => count( $ids ),
		) );
	}

	/**
	 * AJAX handler: return product stats filtered by status.
	 */
	public function ajax_get_stats() {
		check_ajax_referer( 'visionati_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Permission denied.', 'visionati' ) ) );
		}

		$valid_statuses = array( 'publish', 'draft', 'pending', 'private' );
		$statuses       = isset( $_POST['statuses'] ) ? array_map( 'sanitize_key', (array) $_POST['statuses'] ) : $valid_statuses;
		$statuses       = array_intersect( $statuses, $valid_statuses );

		if ( empty( $statuses ) ) {
			$statuses = $valid_statuses;
		}

		$counts = $this->count_product_stats( $statuses );

		Visionati_API::send_json_success( $counts );
	}

	/**
	 * Query product IDs that need description processing in a single SQL query.
	 *
	 * When $return_all is true, returns all published products with featured images.
	 * Otherwise, returns only products missing short or long descriptions.
	 * No per-product iteration or wc_get_product() calls.
	 *
	 * @param bool $return_all Whether to return all products regardless of existing content.
	 * @return array Array of product IDs.
	 */
	private function query_product_ids( $return_all = false, $statuses = array() ) {
		global $wpdb;

		if ( empty( $statuses ) ) {
			$statuses = array( 'publish', 'draft', 'pending', 'private' );
		}

		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		if ( $return_all ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm
						ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
					WHERE p.post_type = 'product'
						AND p.post_status IN ($status_placeholders)
					ORDER BY p.ID ASC",
					...$statuses
				)
			);
			// phpcs:enable
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm
						ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
					WHERE p.post_type = 'product'
						AND p.post_status IN ($status_placeholders)
						AND (p.post_excerpt IS NULL OR p.post_excerpt = '' OR p.post_content IS NULL OR p.post_content = '')
					ORDER BY p.ID ASC",
					...$statuses
				)
			);
			// phpcs:enable
		}

		return array_map( 'absint', $ids );
	}

	/**
	 * Register the Bulk Descriptions admin page under Products.
	 */
	public function register_bulk_page() {
		$api_key = get_option( 'visionati_api_key', '' );
		if ( empty( $api_key ) ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Visionati Bulk Descriptions', 'visionati' ),
			__( 'Bulk Descriptions', 'visionati' ),
			'edit_products',
			'visionati-woo-bulk',
			array( $this, 'render_woo_bulk_page' )
		);
	}

	/**
	 * Render the WooCommerce Bulk Descriptions admin page.
	 */
	public function render_woo_bulk_page() {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$api_key = get_option( 'visionati_api_key', '' );
		$counts  = $this->count_product_stats();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Visionati Bulk Descriptions', 'visionati' ); ?></h1>

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
					<p id="visionati-woo-bulk-stats">
						<?php
						printf(
							/* translators: 1: number of products missing descriptions, 2: total products with images */
							esc_html__( '%1$d of %2$d products with images are missing descriptions.', 'visionati' ),
							absint( $counts['missing'] ),
							absint( $counts['total'] )
						);
						?>
					</p>
				</div>

				<div class="visionati-bulk-controls">
					<fieldset class="visionati-bulk-fields">
						<legend class="screen-reader-text"><?php esc_html_e( 'Product statuses to include', 'visionati' ); ?></legend>
						<label style="display:inline-block !important;margin-right:16px !important">
							<input type="checkbox" name="visionati_woo_bulk_status" value="publish" checked />
							<?php esc_html_e( 'Published', 'visionati' ); ?>
						</label>
						<label style="display:inline-block !important;margin-right:16px !important">
							<input type="checkbox" name="visionati_woo_bulk_status" value="draft" checked />
							<?php esc_html_e( 'Draft', 'visionati' ); ?>
						</label>
						<label style="display:inline-block !important;margin-right:16px !important">
							<input type="checkbox" name="visionati_woo_bulk_status" value="pending" checked />
							<?php esc_html_e( 'Pending', 'visionati' ); ?>
						</label>
						<label style="display:inline-block !important;margin-right:16px !important">
							<input type="checkbox" name="visionati_woo_bulk_status" value="private" checked />
							<?php esc_html_e( 'Private', 'visionati' ); ?>
						</label>
					</fieldset>

					<div class="visionati-bulk-actions">
						<button type="button" class="button button-primary" id="visionati-woo-bulk-start">
							<?php esc_html_e( 'Start', 'visionati' ); ?>
						</button>
						<button type="button" class="button" id="visionati-woo-bulk-stop" disabled>
							<?php esc_html_e( 'Stop', 'visionati' ); ?>
						</button>
					</div>
				</div>

				<div class="visionati-woo-bulk-progress" style="display: none;">
					<div class="visionati-progress-bar-wrap">
						<div class="visionati-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Bulk generation progress', 'visionati' ); ?>" style="width: 0%;"></div>
					</div>
					<p class="visionati-progress-text">
						<span class="visionati-bulk-current">0</span>
						<?php esc_html_e( 'of', 'visionati' ); ?>
						<span class="visionati-bulk-total">0</span>
						&mdash;
						<span class="visionati-bulk-percent">0</span>%
					</p>
					<p class="visionati-progress-summary" tabindex="-1">
						<span class="visionati-summary-generated">0</span> <?php esc_html_e( 'generated', 'visionati' ); ?>,
						<span class="visionati-summary-skipped">0</span> <?php esc_html_e( 'skipped', 'visionati' ); ?>,
						<span class="visionati-summary-errors">0</span> <?php esc_html_e( 'errors', 'visionati' ); ?>
					</p>
					<p class="visionati-credits-remaining" style="display: none;"></p>
				</div>

				<div class="visionati-woo-bulk-log" style="display: none;">
					<h3><?php esc_html_e( 'Results', 'visionati' ); ?></h3>
					<div id="visionati-woo-bulk-log-entries" role="log" aria-live="polite"></div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Register a bulk action on the Products list table.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array Modified bulk actions.
	 */
	public function register_bulk_action( $actions ) {
		$api_key = get_option( 'visionati_api_key', '' );
		if ( ! empty( $api_key ) ) {
			$actions['visionati_woo_generate'] = __( 'Generate descriptions with Visionati', 'visionati' );
		}
		return $actions;
	}

	/**
	 * Handle the Products list bulk action.
	 *
	 * Instead of processing synchronously (which would timeout with more
	 * than a few products), store the selected IDs in a transient and
	 * redirect to the AJAX-powered Bulk Descriptions page.
	 *
	 * @param string $redirect_url The redirect URL.
	 * @param string $action       The action being taken.
	 * @param array  $post_ids     The selected post IDs.
	 * @return string Modified redirect URL.
	 */
	public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
		if ( 'visionati_woo_generate' !== $action ) {
			return $redirect_url;
		}

		// Filter to products with featured images using a single SQL query
		// instead of instantiating a full WC_Product object per selected ID.
		global $wpdb;
		$sanitized_ids = array_map( 'absint', $post_ids );
		$id_placeholders = implode( ', ', array_fill( 0, count( $sanitized_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm
					ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
				WHERE p.ID IN ($id_placeholders)
				ORDER BY p.ID ASC",
				...$sanitized_ids
			)
		);
		// phpcs:enable
		$product_ids = array_map( 'absint', $product_ids );

		if ( empty( $product_ids ) ) {
			return add_query_arg( array(
				'visionati_woo_processed' => 0,
				'visionati_woo_skipped'   => count( $post_ids ),
				'visionati_woo_errors'    => 0,
			), $redirect_url );
		}

		// Store IDs in a user-scoped transient for the Bulk Descriptions page to consume.
		$user_id = get_current_user_id();
		set_transient( 'visionati_woo_bulk_queue_' . $user_id, $product_ids, 5 * MINUTE_IN_SECONDS );

		// Redirect to the AJAX-powered Bulk Descriptions page.
		return admin_url( 'edit.php?post_type=product&page=visionati-woo-bulk&queued=1' );
	}

	/**
	 * Show a notice after the Products bulk action completes.
	 */
	public function bulk_action_notice() {
		if ( ! isset( $_GET['visionati_woo_processed'] ) ) {
			return;
		}

		$processed = absint( $_GET['visionati_woo_processed'] );
		$skipped   = isset( $_GET['visionati_woo_skipped'] ) ? absint( $_GET['visionati_woo_skipped'] ) : 0;
		$errors    = isset( $_GET['visionati_woo_errors'] ) ? absint( $_GET['visionati_woo_errors'] ) : 0;

		$parts = array();

		if ( $processed > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of products processed */
				_n( '%d product updated.', '%d products updated.', $processed, 'visionati' ),
				$processed
			);
		}
		if ( $skipped > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of products skipped */
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
	 * Count products with images and how many are missing descriptions in a single SQL query.
	 *
	 * Uses an INNER JOIN on _thumbnail_id to filter to products with images,
	 * and checks post_excerpt/post_content directly. One query, no iteration,
	 * no wc_get_product() calls.
	 *
	 * @param array $statuses Optional post statuses to filter by.
	 * @return array Associative array with keys: total, missing.
	 */
	private function count_product_stats( $statuses = array() ) {
		global $wpdb;

		if ( empty( $statuses ) ) {
			$statuses = array( 'publish', 'draft', 'pending', 'private' );
		}

		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM( CASE WHEN p.post_excerpt IS NULL OR p.post_excerpt = '' OR p.post_content IS NULL OR p.post_content = '' THEN 1 ELSE 0 END ) AS missing
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm
					ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
				WHERE p.post_type = 'product'
					AND p.post_status IN ($status_placeholders)",
				...$statuses
			)
		);
		// phpcs:enable

		return array(
			'total'   => $row ? (int) $row->total : 0,
			'missing' => $row ? (int) $row->missing : 0,
		);
	}

	/**
	 * Generate short and long descriptions for a product.
	 *
	 * Submits both requests in parallel using submit_attachment() +
	 * poll_multiple(), so two descriptions take roughly the same wall
	 * time as one (~8-10s instead of ~16-20s).
	 * Does NOT save anything to the product. Callers are responsible for saving.
	 *
	 * @param WC_Product $product          The WooCommerce product object.
	 * @param int        $thumbnail_id     The featured image attachment ID.
	 * @param array      $extra_submissions Optional additional submissions to include
	 *                                      in the same parallel poll. Associative array
	 *                                      of key => options array for submit_attachment().
	 * @param bool       $force_overwrite  Whether to generate regardless of existing content.
	 *                                      True for explicit meta box clicks, false for bulk.
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	private function generate_product_descriptions( $product, $thumbnail_id, $extra_submissions = array(), $force_overwrite = false ) {
		$api       = new Visionati_API();
		$overwrite_fields = get_option( 'visionati_overwrite_fields', array() );
		if ( ! is_array( $overwrite_fields ) ) {
			$overwrite_fields = array();
		}
		$overwrite_desc = in_array( 'description', $overwrite_fields, true );
		$context        = $this->get_product_context( $product );
		$result         = array(
			'product_id' => $product->get_id(),
			'status'     => 'generated',
			'fields'     => array(),
		);

		$need_short  = $force_overwrite || $overwrite_desc || self::is_content_empty( $product->get_short_description() );
		$need_long   = $force_overwrite || $overwrite_desc || self::is_content_empty( $product->get_description() );
		$woo_backend = get_option( 'visionati_backend_woocommerce', '' );
		$pending     = array();

		// Phase 1: Submit all requests. Each returns almost instantly.
		if ( $need_short ) {
			$short_prompt  = $this->build_prompt( 'short', $context );
			$short_options = array(
				'role'     => get_option( 'visionati_role_woocommerce', 'ecommerce' ),
				'prompt'   => $short_prompt,
				'features' => array( 'descriptions' ),
			);
			if ( ! empty( $woo_backend ) ) {
				$short_options['backends'] = $woo_backend;
			}

			$submitted = $api->submit_attachment( $thumbnail_id, $short_options );
			if ( is_wp_error( $submitted ) ) {
				return $submitted;
			}
			if ( ! empty( $submitted['response_uri'] ) ) {
				$pending['short'] = $submitted['response_uri'];
			} elseif ( ! empty( $submitted['all']['assets'] ) ) {
				$short_text = Visionati_API::get_first_description( $submitted );
				$result['short_description'] = wp_strip_all_tags( $short_text );
			}
		}

		if ( $need_long ) {
			$long_prompt  = $this->build_prompt( 'long', $context );
			$long_options = array(
				'role'     => get_option( 'visionati_role_woocommerce', 'ecommerce' ),
				'prompt'   => $long_prompt,
				'features' => array( 'descriptions' ),
			);
			if ( ! empty( $woo_backend ) ) {
				$long_options['backends'] = $woo_backend;
			}

			$submitted = $api->submit_attachment( $thumbnail_id, $long_options );
			if ( is_wp_error( $submitted ) ) {
				return $submitted;
			}
			if ( ! empty( $submitted['response_uri'] ) ) {
				$pending['long'] = $submitted['response_uri'];
			} elseif ( ! empty( $submitted['all']['assets'] ) ) {
				$long_text = Visionati_API::get_first_description( $submitted );
				$result['long_description'] = wp_kses_post( $long_text );
			}
		}

		// Submit any extra requests (e.g. alt text from bulk processing).
		foreach ( $extra_submissions as $key => $options ) {
			$submitted = $api->submit_attachment( $thumbnail_id, $options );
			if ( is_wp_error( $submitted ) ) {
				continue;
			}
			if ( ! empty( $submitted['response_uri'] ) ) {
				$pending[ $key ] = $submitted['response_uri'];
			}
		}

		// Phase 2: Poll all response URIs in one round-robin loop.
		if ( ! empty( $pending ) ) {
			$responses = $api->poll_multiple( $pending );

			if ( isset( $responses['short'] ) ) {
				if ( is_wp_error( $responses['short'] ) ) {
					return $responses['short'];
				}
				$short_text = Visionati_API::get_first_description( $responses['short'] );
				$result['short_description'] = wp_strip_all_tags( $short_text );
				$credits = Visionati_API::extract_credits( $responses['short'] );
				if ( null !== $credits ) {
					$result['credits'] = $credits;
				}
			}

			if ( isset( $responses['long'] ) ) {
				if ( is_wp_error( $responses['long'] ) ) {
					return $responses['long'];
				}
				$long_text = Visionati_API::get_first_description( $responses['long'] );
				$result['long_description'] = wp_kses_post( $long_text );
				$credits = Visionati_API::extract_credits( $responses['long'] );
				if ( null !== $credits ) {
					$result['credits'] = $credits;
				}
			}

			// Pass through any extra responses (keyed by their original key).
			foreach ( $extra_submissions as $key => $options ) {
				if ( isset( $responses[ $key ] ) && ! is_wp_error( $responses[ $key ] ) ) {
					$result[ $key ] = $responses[ $key ];
				}
			}
		}

		return $result;
	}

	/**
	 * Save short and long descriptions to a product.
	 *
	 * No API calls. Just saves the provided text.
	 *
	 * @param WC_Product $product           The WooCommerce product object.
	 * @param string     $short_description Short description text (plain text).
	 * @param string     $long_description  Long description text (may contain HTML).
	 * @return array|WP_Error List of field slugs that were saved, or WP_Error on failure.
	 */
	private function save_product_descriptions( $product, $short_description, $long_description ) {
		$fields = array();

		Visionati_API::debug_log( 'save_product_descriptions: starting', array(
			'product_id'   => $product->get_id(),
			'short_length' => mb_strlen( $short_description ),
			'short_empty'  => empty( $short_description ),
			'long_length'  => mb_strlen( $long_description ),
			'long_empty'   => empty( $long_description ),
		) );

		if ( ! empty( $short_description ) ) {
			$product->set_short_description( sanitize_text_field( $short_description ) );
			$fields[] = 'short_description';
			Visionati_API::debug_log( 'save_product_descriptions: set_short_description called', array(
				'product_id' => $product->get_id(),
				'length'     => mb_strlen( $short_description ),
			) );
		} else {
			Visionati_API::debug_log( 'save_product_descriptions: short_description is empty, skipping' );
		}

		if ( ! empty( $long_description ) ) {
			$product->set_description( wp_kses_post( $long_description ) );
			$fields[] = 'long_description';
			Visionati_API::debug_log( 'save_product_descriptions: set_description called', array(
				'product_id' => $product->get_id(),
				'length'     => mb_strlen( $long_description ),
			) );
		} else {
			Visionati_API::debug_log( 'save_product_descriptions: long_description is empty, skipping' );
		}

		if ( ! empty( $fields ) ) {
			Visionati_API::debug_log( 'save_product_descriptions: calling product->save()', array(
				'product_id' => $product->get_id(),
				'fields'     => $fields,
			) );
			try {
				$product->save();
				Visionati_API::debug_log( 'save_product_descriptions: product->save() succeeded' );
			} catch ( Exception $e ) {
				Visionati_API::debug_log( 'save_product_descriptions: product->save() FAILED', array(
					'error' => $e->getMessage(),
				) );
				return new WP_Error(
					'visionati_save_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Failed to save product: %s', 'visionati' ),
						$e->getMessage()
					)
				);
			}
		}

		return $fields;
	}

	/**
	 * Build a prompt for a product description.
	 *
	 * Priority:
	 * 1. User's custom WooCommerce prompt (with placeholders expanded)
	 * 2. Auto-constructed prompt from product data
	 * 3. Fallback prompt with no context
	 *
	 * @param string $type    Either 'short' or 'long'.
	 * @param array  $context Product context from get_product_context().
	 * @return string The constructed prompt.
	 */
	private function build_prompt( $type, $context ) {
		$custom_prompt = get_option( 'visionati_prompt_woocommerce', '' );

		if ( ! empty( $custom_prompt ) ) {
			$prompt = $this->expand_placeholders( $custom_prompt, $context );
		} else {
			$prompt = $this->build_auto_prompt( $type, $context );
		}

		// Append type-specific formatting instructions.
		if ( 'short' === $type ) {
			$prompt .= ' Write 2-3 sentences maximum. Use plain text with no HTML formatting, markdown, emojis, or special characters.';
		} else {
			$prompt .= ' Write a comprehensive description with multiple paragraphs covering features, materials, and use cases. Use HTML tags for formatting (bold, lists, paragraphs). Do not use markdown, emojis, or special characters.';
		}

		// Append boilerplate.
		$prompt .= ' ' . self::PROMPT_BOILERPLATE;

		return $prompt;
	}

	/**
	 * Build an automatic prompt from product context data.
	 *
	 * @param string $type    Either 'short' or 'long'.
	 * @param array  $context Product context.
	 * @return string The prompt.
	 */
	private function build_auto_prompt( $type, $context ) {
		$include_context = get_option( 'visionati_woo_include_context', true );
		$language        = get_option( 'visionati_language', 'English' );

		if ( 'short' === $type ) {
			$prompt = sprintf(
				'Write a brief, persuasive product description in %s.',
				$language
			);
		} else {
			$prompt = sprintf(
				'Write a detailed product description in %s for an ecommerce listing.',
				$language
			);
		}

		if ( $include_context && ! empty( $context['name'] ) ) {
			$prompt .= sprintf( ' Product: %s.', $context['name'] );
		}

		if ( $include_context && ! empty( $context['categories'] ) ) {
			$prompt .= sprintf( ' Categories: %s.', $context['categories'] );
		}

		if ( $include_context && ! empty( $context['attributes'] ) ) {
			$prompt .= sprintf( ' Attributes: %s.', $context['attributes'] );
		}

		if ( $include_context && ! empty( $context['price'] ) ) {
			$prompt .= sprintf( ' Price: %s.', $context['price'] );
		}

		return $prompt;
	}

	/**
	 * Expand placeholders in a custom prompt with product context.
	 *
	 * Supported placeholders: {product_name}, {categories}, {price}
	 *
	 * @param string $prompt  The prompt template with placeholders.
	 * @param array  $context Product context.
	 * @return string The prompt with placeholders replaced.
	 */
	private function expand_placeholders( $prompt, $context ) {
		$replacements = array(
			'{product_name}' => ! empty( $context['name'] ) ? $context['name'] : '',
			'{categories}'   => ! empty( $context['categories'] ) ? $context['categories'] : '',
			'{price}'        => ! empty( $context['price'] ) ? $context['price'] : '',
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $prompt );
	}

	/**
	 * Get display metadata for a product (name and thumbnail URL).
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @return array Associative array with 'filename' and 'thumb' keys.
	 */
	public static function get_product_meta( $product ) {
		$thumb_id = $product->get_image_id();
		$thumb    = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';

		return array(
			'filename' => $product->get_name(),
			'thumb'    => $thumb ? $thumb : '',
		);
	}

	/**
	 * Extract context data from a WooCommerce product.
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @return array Associative array with name, categories, attributes, price.
	 */
	private function get_product_context( $product ) {
		$context = array(
			'name'       => $product->get_name(),
			'categories' => '',
			'attributes' => '',
			'price'      => '',
		);

		// Categories.
		$category_ids = $product->get_category_ids();
		if ( ! empty( $category_ids ) ) {
			$category_names = array();
			foreach ( $category_ids as $cat_id ) {
				$term = get_term( $cat_id, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$category_names[] = $term->name;
				}
			}
			$context['categories'] = implode( ', ', $category_names );
		}

		// Attributes.
		$attributes = $product->get_attributes();
		if ( ! empty( $attributes ) ) {
			$attr_strings = array();
			foreach ( $attributes as $attribute ) {
				if ( is_a( $attribute, 'WC_Product_Attribute' ) ) {
					$name    = wc_attribute_label( $attribute->get_name() );
					$options = $attribute->get_options();

					if ( $attribute->is_taxonomy() ) {
						$values = array();
						foreach ( $options as $term_id ) {
							$term = get_term( $term_id );
							if ( $term && ! is_wp_error( $term ) ) {
								$values[] = $term->name;
							}
						}
					} else {
						$values = $options;
					}

					if ( ! empty( $values ) ) {
						$attr_strings[] = $name . ': ' . implode( ', ', $values );
					}
				}
			}
			$context['attributes'] = implode( '; ', $attr_strings );
		}

		// Price.
		$price = $product->get_price();
		if ( ! empty( $price ) ) {
			$context['price'] = html_entity_decode( wp_strip_all_tags( wc_price( $price ) ) );
		}

		return $context;
	}
}