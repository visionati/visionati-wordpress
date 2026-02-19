<?php
/**
 * Visionati API Client
 *
 * Handles all communication with the Visionati API including
 * base64 encoding, submitting requests, and polling for async results.
 *
 * @package Visionati
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visionati_API {

	/**
	 * Maximum file size in bytes (20MB).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 20971520;

	/**
	 * Maximum polling attempts for async responses.
	 *
	 * @var int
	 */
	const MAX_POLL_ATTEMPTS = 150;

	/**
	 * Seconds between poll requests.
	 *
	 * @var int
	 */
	const POLL_INTERVAL = 2;

	/**
	 * HTTP timeout in seconds for API requests.
	 *
	 * @var int
	 */
	const HTTP_TIMEOUT = 30;

	/**
	 * Supported image MIME types.
	 *
	 * @var array
	 */
	const SUPPORTED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/bmp',
	);

	/**
	 * The API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Debug log entries collected during the current request.
	 *
	 * @var array
	 */
	private static $debug_entries = array();

	/**
	 * Cached debug-enabled flag (null = not yet checked).
	 *
	 * @var bool|null
	 */
	private static $debug_enabled = null;

	/**
	 * Check whether debug logging is enabled.
	 *
	 * Returns true if either the plugin's own Debug Mode setting is on
	 * (Settings → Visionati) or WP_DEBUG is true in wp-config.php.
	 *
	 * @return bool
	 */
	public static function is_debug() {
		if ( null === self::$debug_enabled ) {
			self::$debug_enabled = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
				|| (bool) get_option( 'visionati_debug', false );
		}
		return self::$debug_enabled;
	}

	/**
	 * Log a debug message.
	 *
	 * When debug is enabled (plugin setting or WP_DEBUG), the message is:
	 * 1. Collected in a static array so it can be attached to AJAX responses
	 *    and logged to the browser console — no server access needed.
	 * 2. Written to error_log() for wp-content/debug.log (if WP_DEBUG_LOG is on).
	 *
	 * @param string $message Human-readable description of what happened.
	 * @param array  $context Optional key-value pairs to include (IDs, values, etc.).
	 */
	public static function debug_log( $message, $context = array() ) {
		if ( ! self::is_debug() ) {
			return;
		}

		$entry = array(
			'message' => $message,
		);

		if ( ! empty( $context ) ) {
			$entry['context'] = $context;
		}

		self::$debug_entries[] = $entry;

		// Also write to error_log for server-side debug.log.
		$log_line = '[Visionati] ' . $message;
		if ( ! empty( $context ) ) {
			$log_line .= ' | ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging, gated by is_debug().
		error_log( $log_line );
	}

	/**
	 * Get all debug entries collected during this request.
	 *
	 * Intended to be attached to AJAX responses so the JS can log
	 * the full PHP-side trace to the browser console.
	 *
	 * @return array Array of debug entries, each with 'message' and optional 'context'.
	 */
	public static function get_debug_entries() {
		return self::$debug_entries;
	}

	/**
	 * Send a JSON success response, attaching debug entries when debug is on.
	 *
	 * Drop-in replacement for wp_send_json_success(). When debug mode is
	 * enabled, a '_debug' key is added to the response data so the JS can
	 * log the full PHP-side trace to the browser console.
	 *
	 * @param mixed $data Response data (typically an array).
	 */
	public static function send_json_success( $data = null ) {
		if ( self::is_debug() && is_array( $data ) ) {
			$data['_debug'] = self::$debug_entries;
		}
		wp_send_json_success( $data );
	}

	/**
	 * Send a JSON error response, attaching debug entries when debug is on.
	 *
	 * Drop-in replacement for wp_send_json_error(). When debug mode is
	 * enabled, a '_debug' key is added to the response data so the JS can
	 * log the full PHP-side trace to the browser console.
	 *
	 * @param mixed $data Response data (typically an array with 'message').
	 */
	public static function send_json_error( $data = null ) {
		if ( self::is_debug() && is_array( $data ) ) {
			$data['_debug'] = self::$debug_entries;
		}
		wp_send_json_error( $data );
	}

	/**
	 * Constructor.
	 *
	 * @param string|null $api_key Optional API key override. Reads from options if not provided.
	 */
	public function __construct( $api_key = null ) {
		$this->api_key = $api_key ? $api_key : get_option( 'visionati_api_key', '' );
	}

	/**
	 * Test the API connection by hitting the health endpoint.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function test_connection() {
		if ( empty( $this->api_key ) ) {
			return new WP_Error(
				'visionati_no_api_key',
				__( 'No API key configured.', 'visionati' )
			);
		}

		// Send an empty POST to /api/fetch to validate the key.
		// Valid key: returns "File/URL params are required." (key accepted, no work to do).
		// Invalid key: returns "Access denied." (auth failure).
		$response = wp_remote_post(
			VISIONATI_API_BASE . '/api/fetch',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json;charset=utf-8',
					'X-API-Key'    => 'Token ' . $this->api_key,
				),
				'body'    => wp_json_encode( new stdClass() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'visionati_connection_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Connection failed: %s', 'visionati' ),
					$response->get_error_message()
				)
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			return new WP_Error(
				'visionati_invalid_response',
				__( 'Invalid response from API.', 'visionati' )
			);
		}

		// "Access denied." means the key is bad.
		if ( ! empty( $decoded['error'] ) && false !== strpos( $decoded['error'], 'Access denied' ) ) {
			return new WP_Error(
				'visionati_invalid_key',
				$decoded['error']
			);
		}

		// "File/URL params are required." means the key is valid (request was authenticated).
		return true;
	}

	/**
	 * Submit an attachment for analysis without polling for results.
	 *
	 * Returns the raw API response which typically contains a response_uri
	 * for async polling. Use poll() or poll_multiple() to get results.
	 *
	 * @param int   $attachment_id The attachment post ID.
	 * @param array $options {
	 *     Optional. Analysis options.
	 *
	 *     @type string   $role     Role slug. Default from settings.
	 *     @type string   $prompt   Custom prompt. Overrides role if non-empty.
	 *     @type string   $language Output language. Default from settings.
	 *     @type string[] $backends Array of backend slugs. Default from settings.
	 *     @type string[] $features Array of feature slugs. Default ['descriptions'].
	 * }
	 * @return array|WP_Error Raw API response on success, WP_Error on failure.
	 */
	public function submit_attachment( $attachment_id, $options = array() ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error(
				'visionati_no_api_key',
				__( 'No API key configured.', 'visionati' )
			);
		}

		$validation = $this->validate_attachment( $attachment_id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$file_path = get_attached_file( $attachment_id );
		$file_name = basename( $file_path );

		$base64 = $this->base64_encode_file( $file_path );
		if ( is_wp_error( $base64 ) ) {
			return $base64;
		}

		$role     = isset( $options['role'] ) ? $options['role'] : 'general';
		$prompt   = isset( $options['prompt'] ) ? $options['prompt'] : '';
		$language = isset( $options['language'] ) ? $options['language'] : get_option( 'visionati_language', 'English' );
		$backend = isset( $options['backends'] ) ? $options['backends'] : get_option( 'visionati_backends', 'gemini' );

		// Migrate from old array format.
		if ( is_array( $backend ) ) {
			$backend = ! empty( $backend ) ? $backend[0] : 'gemini';
		}
		$features = isset( $options['features'] ) ? $options['features'] : array( 'descriptions' );

		$data = array(
			'file'      => array( $base64 ),
			'file_name' => array( $file_name ),
			'feature'   => $features,
			'role'      => $role,
			'language'  => $language,
		);

		if ( ! empty( $prompt ) ) {
			$data['prompt'] = $prompt;
		}

		if ( ! empty( $backend ) ) {
			$data['backend'] = array( $backend );
		}

		return $this->submit( $data );
	}

	/**
	 * Analyze a WordPress attachment image.
	 *
	 * Submits the attachment and polls for results. For parallel processing
	 * of multiple contexts, use submit_attachment() + poll_multiple() instead.
	 *
	 * @param int   $attachment_id The attachment post ID.
	 * @param array $options       Analysis options. See submit_attachment().
	 * @return array|WP_Error Parsed API response on success, WP_Error on failure.
	 */
	public function analyze_attachment( $attachment_id, $options = array() ) {
		$submit_response = $this->submit_attachment( $attachment_id, $options );
		if ( is_wp_error( $submit_response ) ) {
			return $submit_response;
		}

		// Single base64 file always returns async response with response_uri.
		if ( ! empty( $submit_response['response_uri'] ) ) {
			return $this->poll( $submit_response['response_uri'] );
		}

		// Direct response (shouldn't happen with base64, but handle it).
		if ( ! empty( $submit_response['all']['assets'] ) ) {
			return $submit_response;
		}

		return new WP_Error(
			'visionati_unexpected_response',
			__( 'Unexpected API response format.', 'visionati' )
		);
	}

	/**
	 * Submit a request to the Visionati API.
	 *
	 * @param array $data Request payload.
	 * @return array|WP_Error Decoded response body on success, WP_Error on failure.
	 */
	public function submit( $data ) {
		$response = wp_remote_post(
			VISIONATI_API_BASE . '/api/fetch',
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/json;charset=utf-8',
					'X-API-Key'    => 'Token ' . $this->api_key,
				),
				'body'    => wp_json_encode( $data ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'visionati_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'API request failed: %s', 'visionati' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			return new WP_Error(
				'visionati_invalid_json',
				__( 'Invalid JSON response from API.', 'visionati' )
			);
		}

		if ( ! empty( $decoded['error'] ) ) {
			return new WP_Error(
				'visionati_api_error',
				$decoded['error']
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'visionati_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'API returned HTTP %d.', 'visionati' ),
					$code
				)
			);
		}

		return $decoded;
	}

	/**
	 * Poll multiple async response URIs concurrently in a round-robin loop.
	 *
	 * All URIs are checked each round with a single sleep between rounds.
	 * This means N requests that each take ~8s to resolve will complete in
	 * ~8s total instead of ~8s × N when polled sequentially.
	 *
	 * @param array $uris          Associative array of key => response_uri.
	 * @param int   $max_attempts  Maximum number of poll rounds.
	 * @return array Associative array of key => (parsed response array | WP_Error).
	 */
	public function poll_multiple( $uris, $max_attempts = null ) {
		if ( null === $max_attempts ) {
			$max_attempts = self::MAX_POLL_ATTEMPTS;
		}

		$pending        = $uris;
		$results        = array();
		$last_errors    = array();
		$decode_failures = array();

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( self::POLL_INTERVAL );
			}

			foreach ( $pending as $key => $uri ) {
				$response = wp_remote_get(
					$uri,
					array(
						'timeout' => self::HTTP_TIMEOUT,
						'headers' => array(
							'X-API-Key' => 'Token ' . $this->api_key,
						),
					)
				);

				if ( is_wp_error( $response ) ) {
					$last_errors[ $key ] = $response->get_error_message();
					continue;
				}

				$body    = wp_remote_retrieve_body( $response );
				$decoded = json_decode( $body, true );

				if ( null === $decoded ) {
					if ( ! isset( $decode_failures[ $key ] ) ) {
						$decode_failures[ $key ] = 0;
					}
					$decode_failures[ $key ]++;
					$last_errors[ $key ] = __( 'API returned invalid JSON.', 'visionati' );

					// Bail early if the API consistently returns non-JSON (e.g. HTML error page from a proxy).
					if ( $decode_failures[ $key ] >= 5 ) {
						$results[ $key ] = new WP_Error(
							'visionati_invalid_response',
							__( 'API returned invalid responses 5 times in a row. A proxy or firewall may be interfering.', 'visionati' )
						);
						unset( $pending[ $key ] );
					}
					continue;
				}

				// Valid JSON resets the decode failure counter.
				$decode_failures[ $key ] = 0;

				if ( ! empty( $decoded['error'] ) ) {
					$results[ $key ] = new WP_Error( 'visionati_api_error', $decoded['error'] );
					unset( $pending[ $key ] );
					continue;
				}

				// Done: results are ready.
				if ( isset( $decoded['status'] ) && 'completed' === $decoded['status'] ) {
					if ( ! empty( $decoded['all']['assets'] ) ) {
						$results[ $key ] = $decoded;
					} else {
						$errors = ! empty( $decoded['all']['errors'] ) ? implode( ', ', $decoded['all']['errors'] ) : '';
						$results[ $key ] = new WP_Error(
							'visionati_no_results',
							! empty( $errors )
								/* translators: %s: error details from the API */
								? sprintf( __( 'Analysis returned no results: %s', 'visionati' ), $errors )
								: __( 'Analysis returned no results.', 'visionati' )
						);
					}
					unset( $pending[ $key ] );
					continue;
				}

				// Still waiting: keep polling.
				if ( isset( $decoded['status'] ) && in_array( $decoded['status'], array( 'queued', 'processing' ), true ) ) {
					continue;
				}

				// Unexpected response: treat as error.
				$results[ $key ] = new WP_Error(
					'visionati_unexpected_response',
					__( 'Unexpected API response during polling.', 'visionati' )
				);
				unset( $pending[ $key ] );
				continue;
			}

			// All done.
			if ( empty( $pending ) ) {
				break;
			}
		}

		// Anything still pending timed out. Include the last error if available.
		foreach ( $pending as $key => $uri ) {
			$detail = isset( $last_errors[ $key ] ) ? ' ' . $last_errors[ $key ] : '';
			$results[ $key ] = new WP_Error(
				'visionati_timeout',
				sprintf(
					/* translators: %s: last error detail or empty string */
					__( 'Analysis timed out. The image may be too large or the service is busy. Please try again.%s', 'visionati' ),
					$detail
				)
			);
		}

		return $results;
	}

	/**
	 * Poll an async response URI until results are ready.
	 *
	 * @param string $uri          The response URI to poll.
	 * @param int    $max_attempts Maximum number of poll attempts.
	 * @return array|WP_Error Parsed results on success, WP_Error on failure.
	 */
	public function poll( $uri, $max_attempts = null ) {
		if ( null === $max_attempts ) {
			$max_attempts = self::MAX_POLL_ATTEMPTS;
		}

		$last_error      = '';
		$decode_failures = 0;

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( self::POLL_INTERVAL );
			}

			$response = wp_remote_get(
				$uri,
				array(
					'timeout' => self::HTTP_TIMEOUT,
					'headers' => array(
						'X-API-Key' => 'Token ' . $this->api_key,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				continue;
			}

			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( null === $decoded ) {
				$decode_failures++;
				$last_error = __( 'API returned invalid JSON.', 'visionati' );

				if ( $decode_failures >= 5 ) {
					return new WP_Error(
						'visionati_invalid_response',
						__( 'API returned invalid responses 5 times in a row. A proxy or firewall may be interfering.', 'visionati' )
					);
				}
				continue;
			}

			$decode_failures = 0;

			if ( ! empty( $decoded['error'] ) ) {
				return new WP_Error(
					'visionati_api_error',
					$decoded['error']
				);
			}

			// Done: results are ready.
			if ( isset( $decoded['status'] ) && 'completed' === $decoded['status'] ) {
				if ( ! empty( $decoded['all']['assets'] ) ) {
					return $decoded;
				}

				// Completed but no assets (image may have failed).
				$errors = ! empty( $decoded['all']['errors'] ) ? implode( ', ', $decoded['all']['errors'] ) : '';
				return new WP_Error(
					'visionati_no_results',
					! empty( $errors )
						? sprintf(
							/* translators: %s: error details */
							__( 'Analysis returned no results: %s', 'visionati' ),
							$errors
						)
						: __( 'Analysis returned no results.', 'visionati' )
				);
			}

			// Still waiting: keep polling.
			if ( isset( $decoded['status'] ) && in_array( $decoded['status'], array( 'queued', 'processing' ), true ) ) {
				continue;
			}

			// Unexpected response: treat as error.
			return new WP_Error(
				'visionati_unexpected_response',
				__( 'Unexpected API response during polling.', 'visionati' )
			);
		}

		$detail = ! empty( $last_error ) ? ' ' . $last_error : '';
		return new WP_Error(
			'visionati_timeout',
			sprintf(
				/* translators: %s: last error detail or empty string */
				__( 'Analysis timed out. The image may be too large or the service is busy. Please try again.%s', 'visionati' ),
				$detail
			)
		);
	}

	/**
	 * Validate that an attachment is suitable for analysis.
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return true|WP_Error True if valid, WP_Error if not.
	 */
	public function validate_attachment( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'visionati_file_not_found',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'Image file not found for attachment #%d.', 'visionati' ),
					$attachment_id
				)
			);
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime_type, self::SUPPORTED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'visionati_unsupported_format',
				sprintf(
					/* translators: %s: MIME type */
					__( 'Unsupported image format: %s. Supported formats: JPEG, PNG, GIF, WebP, BMP.', 'visionati' ),
					$mime_type
				)
			);
		}

		$file_size = filesize( $file_path );
		if ( $file_size > self::MAX_FILE_SIZE ) {
			return new WP_Error(
				'visionati_file_too_large',
				sprintf(
					/* translators: %s: file size in MB */
					__( 'Image file is too large (%sMB). Maximum size is 20MB.', 'visionati' ),
					number_format( $file_size / 1048576, 1 )
				)
			);
		}

		return true;
	}

	/**
	 * Base64 encode a file.
	 *
	 * Uses a single-slot static cache so that generating multiple fields
	 * for the same image (e.g. auto-upload with alt text + caption + description)
	 * only reads and encodes the file once. The cache is replaced when a
	 * different file is requested, keeping memory bounded to one image.
	 *
	 * @param string $file_path Absolute path to the file.
	 * @return string|WP_Error Base64 encoded string on success, WP_Error on failure.
	 */
	public function base64_encode_file( $file_path ) {
		static $cached_path   = '';
		static $cached_result = null;

		if ( $file_path === $cached_path && null !== $cached_result ) {
			return $cached_result;
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error(
				'visionati_file_unreadable',
				sprintf(
					/* translators: %s: file name */
					__( 'Cannot read file: %s', 'visionati' ),
					basename( $file_path )
				)
			);
		}

		$contents = file_get_contents( $file_path );

		if ( false === $contents ) {
			return new WP_Error(
				'visionati_file_read_error',
				__( 'Failed to read image file.', 'visionati' )
			);
		}

		$cached_path   = $file_path;
		$cached_result = base64_encode( $contents );

		return $cached_result;
	}

	/**
	 * Extract the remaining credit balance from an API response.
	 *
	 * The Visionati API includes `credits` (remaining balance) and
	 * `credits_paid` in every successful response, both synchronous
	 * and async (polled).
	 *
	 * @param array $response Parsed API response.
	 * @return int|null Remaining credits, or null if not present.
	 */
	public static function extract_credits( $response ) {
		if ( isset( $response['credits'] ) && is_numeric( $response['credits'] ) ) {
			return (int) $response['credits'];
		}
		return null;
	}

	/**
	 * Extract descriptions from an API response.
	 *
	 * @param array $response Parsed API response.
	 * @return array Array of description strings, or empty array.
	 */
	public static function extract_descriptions( $response ) {
		$descriptions = array();

		if ( empty( $response['all']['assets'] ) ) {
			return $descriptions;
		}

		$asset = $response['all']['assets'][0];

		if ( empty( $asset['descriptions'] ) ) {
			return $descriptions;
		}

		foreach ( $asset['descriptions'] as $desc ) {
			if ( ! empty( $desc['description'] ) ) {
				$descriptions[] = array(
					'text'   => $desc['description'],
					'source' => isset( $desc['source'] ) ? $desc['source'] : '',
				);
			}
		}

		return $descriptions;
	}

	/**
	 * Get the first description text from an API response.
	 *
	 * @param array $response Parsed API response.
	 * @return string The first description, or empty string.
	 */
	public static function get_first_description( $response ) {
		$descriptions = self::extract_descriptions( $response );

		if ( empty( $descriptions ) ) {
			return '';
		}

		return $descriptions[0]['text'];
	}

	/**
	 * Truncate text to a maximum length at a word boundary.
	 *
	 * @param string $text       The text to truncate.
	 * @param int    $max_length Maximum character length.
	 * @return string Truncated text.
	 */
	public static function truncate( $text, $max_length = 125 ) {
		$text = trim( $text );

		if ( mb_strlen( $text ) <= $max_length ) {
			return $text;
		}

		$truncated = mb_substr( $text, 0, $max_length );

		// Cut at last space to avoid breaking words.
		$last_space = mb_strrpos( $truncated, ' ' );
		if ( false !== $last_space && $last_space > $max_length * 0.6 ) {
			$truncated = mb_substr( $truncated, 0, $last_space );
		}

		return rtrim( $truncated, '.,;:!? ' );
	}

	/**
	 * Get supported MIME types.
	 *
	 * @return array
	 */
	public static function get_supported_mime_types() {
		return self::SUPPORTED_MIME_TYPES;
	}

	/**
	 * Check if an attachment is a supported image type.
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return bool
	 */
	public static function is_supported_image( $attachment_id ) {
		$mime_type = get_post_mime_type( $attachment_id );
		return in_array( $mime_type, self::SUPPORTED_MIME_TYPES, true );
	}

	/**
	 * Get all available roles.
	 *
	 * @return array Associative array of role_slug => display_label.
	 */
	public static function get_roles() {
		return array(
			'alttext'    => __( 'Alt Text', 'visionati' ),
			'artist'     => __( 'Artist', 'visionati' ),
			'caption'    => __( 'Caption', 'visionati' ),
			'comedian'   => __( 'Comedian', 'visionati' ),
			'critic'     => __( 'Critic', 'visionati' ),
			'ecommerce'  => __( 'Ecommerce', 'visionati' ),
			'general'    => __( 'General', 'visionati' ),
			'inspector'  => __( 'Inspector', 'visionati' ),
			'promoter'   => __( 'Promoter', 'visionati' ),
			'prompt'     => __( 'Prompt', 'visionati' ),
			'realtor'    => __( 'Realtor', 'visionati' ),
			'tweet'      => __( 'Tweet', 'visionati' ),
		);
	}

	/**
	 * Get all available description backends.
	 *
	 * @return array Associative array of backend_slug => display_label.
	 */
	public static function get_description_backends() {
		return array(
			'bakllava' => 'BakLLaVA',
			'claude'   => 'Claude',
			'gemini'   => 'Gemini',
			'grok'     => 'Grok',
			'jinaai'   => 'Jina AI',
			'llava'    => 'LLaVA',
			'openai'   => 'OpenAI',
		);
	}

	/**
	 * Get supported languages.
	 *
	 * @return array Array of language names.
	 */
	public static function get_languages() {
		return array(
			'Abkhazian', 'Afar', 'Afrikaans', 'Albanian', 'Amharic', 'Arabic',
			'Aragonese', 'Armenian', 'Assamese', 'Aymara', 'Azerbaijani', 'Bashkir',
			'Basque', 'Bengali (Bangla)', 'Bhutani', 'Bihari', 'Bislama', 'Breton',
			'Bulgarian', 'Burmese', 'Byelorussian (Belarusian)', 'Cambodian', 'Catalan',
			'Cherokee', 'Chewa', 'Chinese', 'Chinese (Simplified)',
			'Chinese (Traditional)', 'Corsican', 'Croatian', 'Czech', 'Danish',
			'Divehi', 'Dutch', 'Edo', 'English', 'Esperanto', 'Estonian', 'Faeroese',
			'Farsi', 'Fiji', 'Finnish', 'French', 'Frisian', 'Fulfulde', 'Galician',
			'Gaelic (Scottish)', 'Gaelic (Manx)', 'Georgian', 'German', 'Greek',
			'Greenlandic', 'Guarani', 'Gujarati', 'Haitian Creole', 'Hausa', 'Hawaiian',
			'Hebrew', 'Hindi', 'Hungarian', 'Icelandic', 'Ido', 'Igbo', 'Indonesian',
			'Interlingua', 'Interlingue', 'Inuktitut', 'Inupiak', 'Irish', 'Italian',
			'Japanese', 'Javanese', 'Kannada', 'Kanuri', 'Kashmiri', 'Kazakh',
			'Kinyarwanda (Ruanda)', 'Kirghiz', 'Kirundi (Rundi)', 'Konkani', 'Korean',
			'Kurdish', 'Laothian', 'Latin', 'Latvian (Lettish)',
			'Limburgish (Limburger)', 'Lingala', 'Lithuanian', 'Macedonian', 'Malagasy',
			'Malay', 'Malayalam', 'Maltese', 'Maori', 'Marathi', 'Moldavian',
			'Mongolian', 'Nauru', 'Nepali', 'Norwegian', 'Occitan', 'Oriya',
			'Oromo (Afaan Oromo)', 'Papiamentu', 'Pashto (Pushto)', 'Polish',
			'Portuguese', 'Punjabi', 'Quechua', 'Rhaeto-Romance', 'Romanian', 'Russian',
			'Samoan', 'Sangro', 'Sanskrit', 'Serbian', 'Serbo-Croatian', 'Sesotho',
			'Setswana', 'Shona', 'Sichuan Yi', 'Sindhi', 'Sinhalese', 'Siswati',
			'Slovak', 'Slovenian', 'Somali', 'Spanish', 'Sundanese',
			'Swahili (Kiswahili)', 'Swedish', 'Syriac', 'Tagalog', 'Tajik', 'Tamazight',
			'Tamil', 'Tatar', 'Telugu', 'Thai', 'Tibetan', 'Tigrinya', 'Tonga',
			'Tsonga', 'Turkish', 'Turkmen', 'Twi', 'Uighur', 'Ukrainian', 'Urdu',
			'Uzbek', 'Venda', 'Vietnamese', 'Volapük', 'Wallon', 'Welsh', 'Wolof',
			'Xhosa', 'Yiddish yi', 'Yoruba', 'Zulu',
		);
	}
}