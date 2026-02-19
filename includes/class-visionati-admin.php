<?php
/**
 * Visionati Admin Settings
 *
 * Handles the plugin settings page, Settings API registration,
 * script/style enqueuing, and API key verification AJAX.
 *
 * @package Visionati
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visionati_Admin {

	/**
	 * Settings page hook suffix.
	 *
	 * @var string
	 */
	private $settings_page_hook;

	/**
	 * Constructor. Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_visionati_verify_key', array( $this, 'ajax_verify_key' ) );
	}

	/**
	 * Register the settings page under Settings menu.
	 */
	public function register_settings_page() {
		$this->settings_page_hook = add_options_page(
			__( 'Visionati Settings', 'visionati' ),
			__( 'Visionati', 'visionati' ),
			'manage_options',
			'visionati',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register all plugin settings via the Settings API.
	 */
	public function register_settings() {
		// --- API Connection ---
		add_settings_section(
			'visionati_section_connection',
			__( 'API Connection', 'visionati' ),
			array( $this, 'render_section_connection' ),
			'visionati'
		);

		register_setting( 'visionati', 'visionati_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		add_settings_field(
			'visionati_api_key',
			__( 'API Key', 'visionati' ),
			array( $this, 'render_field_api_key' ),
			'visionati',
			'visionati_section_connection'
		);

		// --- API Settings ---
		add_settings_section(
			'visionati_section_defaults',
			__( 'API Settings', 'visionati' ),
			array( $this, 'render_section_defaults' ),
			'visionati'
		);

		register_setting( 'visionati', 'visionati_backends', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_backend' ),
			'default'           => 'gemini',
		) );

		add_settings_field(
			'visionati_backends',
			__( 'AI Model', 'visionati' ),
			array( $this, 'render_field_backend_select' ),
			'visionati',
			'visionati_section_defaults',
			array(
				'option_name' => 'visionati_backends',
				'default'     => 'gemini',
			)
		);

		register_setting( 'visionati', 'visionati_language', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'English',
		) );

		add_settings_field(
			'visionati_language',
			__( 'Language', 'visionati' ),
			array( $this, 'render_field_language' ),
			'visionati',
			'visionati_section_defaults'
		);

		// --- Context Settings ---
		add_settings_section(
			'visionati_section_context_roles',
			__( 'Context Settings', 'visionati' ),
			array( $this, 'render_section_context_roles' ),
			'visionati'
		);

		$contexts = array(
			'alt_text'    => array(
				'label'        => __( 'Alt Text', 'visionati' ),
				'role_default' => 'alttext',
			),
			'caption'     => array(
				'label'        => __( 'Caption', 'visionati' ),
				'role_default' => 'caption',
			),
			'description' => array(
				'label'        => __( 'Media Description', 'visionati' ),
				'role_default' => 'general',
			),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$contexts['woocommerce'] = array(
				'label'        => __( 'WooCommerce', 'visionati' ),
				'role_default' => 'ecommerce',
			);
		}

		foreach ( $contexts as $context_key => $config ) {
			$role_option    = 'visionati_role_' . $context_key;
			$backend_option = 'visionati_backend_' . $context_key;

			register_setting( 'visionati', $role_option, array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => $config['role_default'],
			) );

			register_setting( 'visionati', $backend_option, array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_backend_override' ),
				'default'           => '',
			) );

			add_settings_field(
				$role_option,
				$config['label'],
				array( $this, 'render_field_context_setting' ),
				'visionati',
				'visionati_section_context_roles',
				array(
					'role_option'    => $role_option,
					'backend_option' => $backend_option,
					'role_default'   => $config['role_default'],
				)
			);
		}

		// --- Custom Prompts ---
		add_settings_section(
			'visionati_section_prompts',
			__( 'Custom Prompts', 'visionati' ),
			array( $this, 'render_section_prompts' ),
			'visionati'
		);

		$prompt_fields = array(
			'visionati_prompt_alt_text'    => __( 'Alt Text Prompt', 'visionati' ),
			'visionati_prompt_caption'     => __( 'Caption Prompt', 'visionati' ),
			'visionati_prompt_description' => __( 'Media Description Prompt', 'visionati' ),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$prompt_fields['visionati_prompt_woocommerce'] = __( 'WooCommerce Prompt', 'visionati' );
		}

		foreach ( $prompt_fields as $option_name => $label ) {
			register_setting( 'visionati', $option_name, array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			) );

			add_settings_field(
				$option_name,
				$label,
				array( $this, 'render_field_prompt' ),
				'visionati',
				'visionati_section_prompts',
				array(
					'option_name' => $option_name,
					'label_for'   => $option_name,
				)
			);
		}

		// --- Automation ---
		add_settings_section(
			'visionati_section_automation',
			__( 'Automation', 'visionati' ),
			array( $this, 'render_section_automation' ),
			'visionati'
		);

		register_setting( 'visionati', 'visionati_auto_generate_fields', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_auto_generate_fields' ),
			'default'           => array(),
		) );

		add_settings_field(
			'visionati_auto_generate_fields',
			__( 'Auto-generate on Upload', 'visionati' ),
			array( $this, 'render_field_auto_generate' ),
			'visionati',
			'visionati_section_automation'
		);

		register_setting( 'visionati', 'visionati_overwrite_fields', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_overwrite_fields' ),
			'default'           => array(),
		) );

		add_settings_field(
			'visionati_overwrite_fields',
			__( 'Overwrite Existing', 'visionati' ),
			array( $this, 'render_field_overwrite' ),
			'visionati',
			'visionati_section_automation'
		);

		if ( class_exists( 'WooCommerce' ) ) {
			register_setting( 'visionati', 'visionati_woo_include_context', array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			) );

			add_settings_field(
				'visionati_woo_include_context',
				__( 'WooCommerce Product Context', 'visionati' ),
				array( $this, 'render_field_woo_include_context' ),
				'visionati',
				'visionati_section_automation'
			);
		}

		// --- Debug ---
		add_settings_section(
			'visionati_section_debug',
			__( 'Debug', 'visionati' ),
			array( $this, 'render_section_debug' ),
			'visionati'
		);

		register_setting( 'visionati', 'visionati_debug', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );

		add_settings_field(
			'visionati_debug',
			__( 'Debug Mode', 'visionati' ),
			array( $this, 'render_field_debug' ),
			'visionati',
			'visionati_section_debug'
		);
	}

	/**
	 * Enqueue admin scripts and styles on plugin pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		$plugin_pages = array(
			'settings_page_visionati',
			'media_page_visionati-bulk-generate',
			'product_page_visionati-woo-bulk',
		);

		$is_plugin_page = in_array( $hook_suffix, $plugin_pages, true );
		$is_media_page  = in_array( $hook_suffix, array( 'post.php', 'post-new.php', 'upload.php' ), true );
		$is_product_page = false;

		if ( $is_media_page || $is_plugin_page ) {
			// Load on plugin pages and media/post edit pages.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading admin URL params, not processing form data.
		} elseif ( 'edit.php' === $hook_suffix && isset( $_GET['post_type'] ) && 'product' === $_GET['post_type'] ) {
			$is_product_page = true;
		} elseif ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading admin URL params, not processing form data.
			$is_product_page = isset( $_GET['post'] ) && 'product' === get_post_type( absint( $_GET['post'] ) );
		} else {
			return;
		}

		$css_path = VISIONATI_PLUGIN_DIR . 'assets/css/admin.css';
		$js_path  = VISIONATI_PLUGIN_DIR . 'assets/js/admin.js';
		$is_debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		$css_ver  = $is_debug ? filemtime( $css_path ) : VISIONATI_VERSION;
		$js_ver   = $is_debug ? filemtime( $js_path ) : VISIONATI_VERSION;

		wp_enqueue_style(
			'visionati-admin',
			VISIONATI_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'visionati-admin',
			VISIONATI_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			$js_ver,
			true
		);

		$overwrite_fields = get_option( 'visionati_overwrite_fields', array() );
		if ( ! is_array( $overwrite_fields ) ) {
			$overwrite_fields = array();
		}

		wp_localize_script( 'visionati-admin', 'visionatiAdmin', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'adminUrl'         => admin_url(),
			'nonce'            => wp_create_nonce( 'visionati_nonce' ),
			'overwriteFields'  => $overwrite_fields,
			'debug'            => Visionati_API::is_debug(),
			'i18n'     => array(
				'verifying'       => __( 'Verifying...', 'visionati' ),
				'connected'       => __( 'Connected.', 'visionati' ),
				'connectionError' => __( 'Connection failed.', 'visionati' ),
				'generating'      => __( 'Generating...', 'visionati' ),
				'generated'       => __( 'Generated.', 'visionati' ),
				'error'           => __( 'Error', 'visionati' ),
				'processing'      => __( 'Processing...', 'visionati' ),
				'complete'        => __( 'Complete.', 'visionati' ),
				'apply'           => __( 'Apply', 'visionati' ),
				'discard'         => __( 'Discard', 'visionati' ),
				'applied'         => __( 'Applied.', 'visionati' ),
				'stopped'         => __( 'Stopped.', 'visionati' ),
				'noImages'        => __( 'No images found to process.', 'visionati' ),
				'noProducts'      => __( 'No products found to process.', 'visionati' ),
				'of'              => __( 'of', 'visionati' ),
				'skipped'         => __( 'Skipped', 'visionati' ),
				'failed'          => __( 'Failed', 'visionati' ),
				'noCredits'       => __( 'Out of credits. Add more at api.visionati.com to continue.', 'visionati' ),
				/* translators: %d: number of credits remaining */
				'creditsRemaining' => __( '%d credits remaining', 'visionati' ),
				'start'           => __( 'Start', 'visionati' ),
				'stop'            => __( 'Stop', 'visionati' ),
				'resume'          => __( 'Resume', 'visionati' ),
				'selectFields'    => __( 'Select at least one field to generate.', 'visionati' ),
				'selectStatuses'  => __( 'Select at least one product status.', 'visionati' ),
				/* translators: 1: number of products missing descriptions, 2: total products with images */
				'wooStats'        => __( '%1$d of %2$d products with images are missing descriptions.', 'visionati' ),
				/* translators: %d: number of images to process */
				'confirmBulk'     => __( 'Process %d images?', 'visionati' ),
				/* translators: %d: number of images to process */
				'confirmBulkOverwrite' => __( 'Process %d images? Overwrite is enabled — existing content will be replaced.', 'visionati' ),
				/* translators: %d: number of products to process */
				'confirmWooBulk'  => __( 'Process %d products?', 'visionati' ),
				/* translators: %d: number of products to process */
				'confirmWooBulkOverwrite' => __( 'Process %d products? Overwrite is enabled — existing descriptions will be replaced.', 'visionati' ),
				'fieldLabels'     => array(
					'alt_text'    => __( 'Alt Text', 'visionati' ),
					'caption'     => __( 'Caption', 'visionati' ),
					'description' => __( 'Description', 'visionati' ),
				),
			),
		) );

		// On the Bulk Generate page, check for pre-queued IDs from the Media Library bulk action.
		if ( 'media_page_visionati-bulk-generate' === $hook_suffix ) {
			$user_id = get_current_user_id();
			$queued  = get_transient( 'visionati_bulk_queue_' . $user_id );

			if ( ! empty( $queued ) && is_array( $queued ) ) {
				delete_transient( 'visionati_bulk_queue_' . $user_id );
				wp_add_inline_script(
					'visionati-admin',
					'var visionatiBulkQueue = ' . wp_json_encode( array_map( 'absint', $queued ) ) . ';',
					'before'
				);
			}
		}

		// On the WooCommerce Bulk Descriptions page, check for pre-queued IDs from the Products bulk action.
		if ( 'product_page_visionati-woo-bulk' === $hook_suffix ) {
			$user_id = get_current_user_id();
			$queued  = get_transient( 'visionati_woo_bulk_queue_' . $user_id );

			if ( ! empty( $queued ) && is_array( $queued ) ) {
				delete_transient( 'visionati_woo_bulk_queue_' . $user_id );
				wp_add_inline_script(
					'visionati-admin',
					'var visionatiWooBulkQueue = ' . wp_json_encode( array_map( 'absint', $queued ) ) . ';',
					'before'
				);
			}
		}
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'visionati' );
				do_settings_sections( 'visionati' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Section descriptions
	// -------------------------------------------------------------------------

	/**
	 * Render the API Connection section description.
	 */
	public function render_section_connection() {
		printf(
			'<p>%s <a href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_html__( 'Enter your Visionati API key.', 'visionati' ),
			esc_url( 'https://api.visionati.com/signup' ),
			esc_html__( 'Sign up for an account.', 'visionati' )
		);
	}

	/**
	 * Render the Defaults section description.
	 */
	public function render_section_defaults() {
		echo '<p>' . esc_html__( 'AI model, language, and role used for analyses.', 'visionati' ) . '</p>';
	}

	/**
	 * Render the Context Settings section description.
	 */
	public function render_section_context_roles() {
		echo '<p>' . esc_html__( 'Override the default role and AI model for each context.', 'visionati' ) . '</p>';
	}

	/**
	 * Render the Custom Prompts section description.
	 */
	public function render_section_prompts() {
		echo '<p>' . esc_html__( 'Optional custom prompts override the role for each context. Leave blank to use the selected role.', 'visionati' ) . '</p>';
	}

	/**
	 * Render the Automation section description.
	 */
	public function render_section_automation() {
		echo '<p>' . esc_html__( 'Control automatic generation behavior.', 'visionati' ) . '</p>';
	}

	/**
	 * Render the Debug section description.
	 */
	public function render_section_debug() {
		echo '<p>' . esc_html__( 'Diagnostic tools for troubleshooting.', 'visionati' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the API Key field with Verify button.
	 */
	public function render_field_api_key() {
		$value = get_option( 'visionati_api_key', '' );
		?>
		<div class="visionati-api-key-field">
			<input
				type="password"
				id="visionati_api_key"
				name="visionati_api_key"
				value="<?php echo esc_attr( $value ); ?>"
				class="regular-text"
				autocomplete="off"
			/>
			<button type="button" class="button" id="visionati-verify-key" aria-label="<?php esc_attr_e( 'Verify API key', 'visionati' ); ?>">
				<?php esc_html_e( 'Verify', 'visionati' ); ?>
			</button>
			<span id="visionati-verify-status" class="visionati-status"></span>
		</div>
		<?php
	}

	/**
	 * Render a backend dropdown. Reused for both AI Model and WooCommerce AI Model.
	 *
	 * @param array $args Field arguments including option_name and default.
	 */
	public function render_field_backend_select( $args ) {
		$option_name = $args['option_name'];
		$default     = $args['default'];
		$selected    = get_option( $option_name, $default );

		// Migrate from old array format.
		if ( is_array( $selected ) ) {
			$selected = ! empty( $selected ) ? $selected[0] : $default;
		}

		$backends = Visionati_API::get_description_backends();

		printf( '<select id="%s" name="%s">', esc_attr( $option_name ), esc_attr( $option_name ) );
		foreach ( $backends as $slug => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $slug ),
				selected( $selected, $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Render the language dropdown.
	 */
	public function render_field_language() {
		$selected = get_option( 'visionati_language', 'English' );
		$languages = Visionati_API::get_languages();

		echo '<select id="visionati_language" name="visionati_language">';
		foreach ( $languages as $lang ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $lang ),
				selected( $selected, $lang, false ),
				esc_html( $lang )
			);
		}
		echo '</select>';
	}

	/**
	 * Render a context-specific role + model pair.
	 *
	 * @param array $args Field arguments including role_option, backend_option, role_default.
	 */
	public function render_field_context_setting( $args ) {
		$role_option    = $args['role_option'];
		$backend_option = $args['backend_option'];
		$role_default   = $args['role_default'];

		$selected_role    = get_option( $role_option, $role_default );
		$selected_backend = get_option( $backend_option, '' );
		$roles            = Visionati_API::get_roles();
		$backends         = Visionati_API::get_description_backends();

		// Role dropdown.
		printf(
			'<label for="%s" class="visionati-context-label">%s</label> ',
			esc_attr( $role_option ),
			esc_html__( 'Role:', 'visionati' )
		);
		printf( '<select id="%s" name="%s">', esc_attr( $role_option ), esc_attr( $role_option ) );
		foreach ( $roles as $slug => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $slug ),
				selected( $selected_role, $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select> ';

		// Model dropdown.
		printf(
			'<label for="%s" class="visionati-context-label">%s</label> ',
			esc_attr( $backend_option ),
			esc_html__( 'Model:', 'visionati' )
		);
		printf( '<select id="%s" name="%s">', esc_attr( $backend_option ), esc_attr( $backend_option ) );
		printf(
			'<option value="" %s>%s</option>',
			selected( $selected_backend, '', false ),
			esc_html__( 'Default', 'visionati' )
		);
		foreach ( $backends as $slug => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $slug ),
				selected( $selected_backend, $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Render a custom prompt textarea.
	 *
	 * @param array $args Field arguments including option_name.
	 */
	public function render_field_prompt( $args ) {
		$option_name = $args['option_name'];
		$value       = get_option( $option_name, '' );

		printf(
			'<textarea id="%s" name="%s" rows="3" class="large-text" placeholder="%s">%s</textarea>',
			esc_attr( $option_name ),
			esc_attr( $option_name ),
			esc_attr__( 'Leave blank to use the selected role.', 'visionati' ),
			esc_textarea( $value )
		);

		if ( 'visionati_prompt_woocommerce' === $option_name ) {
			echo '<p class="description">';
			echo esc_html__( 'Available placeholders: {product_name}, {categories}, {price}', 'visionati' );
			echo '</p>';
		}
	}

	/**
	 * Render the auto-generate on upload checkboxes (per field).
	 */
	public function render_field_auto_generate() {
		$fields = get_option( 'visionati_auto_generate_fields', array() );

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$options = array(
			'alt_text'    => __( 'Alt Text', 'visionati' ),
			'caption'     => __( 'Caption', 'visionati' ),
			'description' => __( 'Description', 'visionati' ),
		);

		echo '<fieldset class="visionati-checkbox-group">';
		echo '<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">';
		foreach ( $options as $value => $label ) {
			printf(
				'<label style="white-space:nowrap"><input type="checkbox" name="visionati_auto_generate_fields[]" value="%s" %s /> %s</label>',
				esc_attr( $value ),
				checked( in_array( $value, $fields, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Automatically generate selected fields when images are uploaded.', 'visionati' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Render the overwrite existing checkboxes (per field).
	 */
	public function render_field_overwrite() {
		$fields = get_option( 'visionati_overwrite_fields', array() );

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$options = array(
			'alt_text'    => __( 'Alt Text', 'visionati' ),
			'caption'     => __( 'Caption', 'visionati' ),
			'description' => __( 'Description', 'visionati' ),
		);

		echo '<fieldset class="visionati-checkbox-group">';
		echo '<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">';
		foreach ( $options as $value => $label ) {
			printf(
				'<label style="white-space:nowrap"><input type="checkbox" name="visionati_overwrite_fields[]" value="%s" %s /> %s</label>',
				esc_attr( $value ),
				checked( in_array( $value, $fields, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Allow overwriting existing content for selected fields during bulk and auto generation.', 'visionati' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Render the Debug Mode checkbox.
	 */
	public function render_field_debug() {
		$value = get_option( 'visionati_debug', false );
		echo '<input type="hidden" name="visionati_debug" value="0" />';
		printf(
			'<label><input type="checkbox" id="visionati_debug" name="visionati_debug" value="1" %s /> %s</label>',
			checked( $value, true, false ),
			esc_html__( 'Log debug information to the browser console. Open your browser developer tools (F12) to view the trace.', 'visionati' )
		);
	}

	/**
	 * Render the WooCommerce include context checkbox.
	 */
	public function render_field_woo_include_context() {
		$value = get_option( 'visionati_woo_include_context', true );
		// Hidden input ensures a "0" is submitted when the checkbox is unchecked,
		// preventing WordPress from calling delete_option() and reverting to the default (true).
		echo '<input type="hidden" name="visionati_woo_include_context" value="0" />';
		printf(
			'<label><input type="checkbox" id="visionati_woo_include_context" name="visionati_woo_include_context" value="1" %s /> %s</label>',
			checked( $value, true, false ),
			esc_html__( 'Include product name, categories, and attributes in WooCommerce prompts for better descriptions.', 'visionati' )
		);
	}

	// -------------------------------------------------------------------------
	// Sanitization
	// -------------------------------------------------------------------------

	/**
	 * Sanitize the overwrite fields array.
	 *
	 * @param mixed $input Raw input value.
	 * @return array Sanitized array of field slugs.
	 */
	public function sanitize_overwrite_fields( $input ) {
		return $this->sanitize_field_list( $input );
	}

	/**
	 * Sanitize the auto-generate fields array.
	 *
	 * @param mixed $input Raw input value.
	 * @return array Sanitized array of field slugs.
	 */
	public function sanitize_auto_generate_fields( $input ) {
		return $this->sanitize_field_list( $input );
	}

	/**
	 * Sanitize an array of field slugs against the valid field list.
	 *
	 * @param mixed $input Raw input value.
	 * @return array Sanitized array of field slugs.
	 */
	private function sanitize_field_list( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$valid     = array( 'alt_text', 'caption', 'description' );
		$sanitized = array();

		foreach ( $input as $field ) {
			$field = sanitize_key( $field );
			if ( in_array( $field, $valid, true ) ) {
				$sanitized[] = $field;
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize the backend selection.
	 *
	 * @param mixed $input Raw input value.
	 * @return string Sanitized backend slug.
	 */
	public function sanitize_backend( $input ) {
		// Handle old array format during migration.
		if ( is_array( $input ) ) {
			$input = ! empty( $input ) ? $input[0] : 'gemini';
		}

		$input = sanitize_key( $input );
		$all_backends = array_keys( Visionati_API::get_description_backends() );

		if ( in_array( $input, $all_backends, true ) ) {
			return $input;
		}

		return 'gemini';
	}

	/**
	 * Sanitize a per-context backend override.
	 *
	 * Empty string means "use global default".
	 *
	 * @param mixed $input Raw input value.
	 * @return string Sanitized backend slug or empty string.
	 */
	public function sanitize_backend_override( $input ) {
		if ( empty( $input ) ) {
			return '';
		}

		$input = sanitize_key( $input );
		$all_backends = array_keys( Visionati_API::get_description_backends() );

		if ( in_array( $input, $all_backends, true ) ) {
			return $input;
		}

		return '';
	}


	// -------------------------------------------------------------------------
	// AJAX Handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: verify the API key.
	 */
	public function ajax_verify_key() {
		check_ajax_referer( 'visionati_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Permission denied.', 'visionati' ) ) );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( empty( $api_key ) ) {
			Visionati_API::send_json_error( array( 'message' => __( 'Please enter an API key.', 'visionati' ) ) );
		}

		$api    = new Visionati_API( $api_key );
		$result = $api->test_connection();

		if ( is_wp_error( $result ) ) {
			Visionati_API::send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Visionati_API::send_json_success( array( 'message' => __( 'Connected.', 'visionati' ) ) );
	}
}