<?php
/**
 * REST API for Farhat Video Q&A
 *
 * @package FarhatVideoQA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FVQA_REST {

	/**
	 * Bootstrap hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'force_json_no_compress' ), 11, 4 );
	}

	/**
	 * Register routes.
	 */
	public function routes() {

		// Public Q&A endpoint (front-end uses this).
		register_rest_route(
			'fvqa/v1',
			'/ask',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'ask' ),
					'permission_callback' => '__return_true', // public; we soft-protect via rate-limit + optional nonce.
					'args'                => array(
						'question'   => array( 'type' => 'string', 'required' => false ),
						'mode'       => array( 'type' => 'string', 'required' => false, 'enum' => array( 'chat', 'button' ) ),
						'button_id'  => array( 'type' => 'string', 'required' => false ),
						'video_id'   => array( 'type' => 'string', 'required' => false ),
						'time_hint'  => array(
							'required'           => false,
							'validate_callback'  => function ( $v ) {
								if ( null === $v || '' === $v ) {
									return true;
								}
								return is_numeric( $v ) && intval( $v ) >= 0;
							},
						),
						'want_audio' => array(
							'required'           => false,
							'validate_callback'  => function ( $v ) {
								return in_array( $v, array( true, false, 0, 1, '0', '1' ), true );
							},
						),
						'nonce'      => array( 'type' => 'string', 'required' => false ), // optional _wpnonce for wp_rest.
					),
				),
			)
		);

		// Pre-warm map cache (admin only).
		register_rest_route(
			'fvqa/v1',
			'/warm',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'warm' ),
					'permission_callback' => array( $this, 'admin_only' ),
					'args'                => array(
						'video_id' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);

		// Light chunk peek (admin only — exposes internals).
		register_rest_route(
			'fvqa/v1',
			'/chunks',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'chunks' ),
					'permission_callback' => array( $this, 'admin_only' ),
					'args'                => array(
						'video_id' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);

		// Diagnostics (admin only).
		register_rest_route(
			'fvqa/v1',
			'/diag',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'diag' ),
					'permission_callback' => array( $this, 'admin_only' ),
					'args'                => array(
						'video_id' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);

		// Force indexing now (admin only).
		register_rest_route(
			'fvqa/v1',
			'/force-index',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'force_index' ),
					'permission_callback' => array( $this, 'admin_only' ),
					'args'                => array(
						'video_id' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);

		// Installer repair (admin only).
		register_rest_route(
			'fvqa/v1',
			'/repair-install',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'repair_install' ),
					'permission_callback' => array( $this, 'admin_only' ),
				),
			)
		);
	}

	/**
	 * Admin-only permission callback.
	 *
	 * @return bool
	 */
	public function admin_only() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Serve JSON for our namespace; avoid proxy compression quirks.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Result to send.
	 * @param WP_REST_Request  $request Request object.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public function force_json_no_compress( $served, $result, $request, $server ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		try {
			if ( ! ( $request instanceof \WP_REST_Request ) ) {
				return $served;
			}
			$route = (string) $request->get_route(); // e.g. /fvqa/v1/ask
			if ( 0 !== strpos( $route, '/fvqa/v1/' ) ) {
				return $served;
			}

			$resp = rest_ensure_response( $result );
			$data = $server->response_to_data( $resp, false );

			if ( ! headers_sent() ) {
				nocache_headers();
				send_nosniff_header();
				header( 'Content-Type: application/json; charset=UTF-8' );
				header( 'X-Accel-Buffering: no' );
				header( 'Vary: Origin' );
			}
			@ini_set( 'zlib.output_compression', 'Off' );
			if ( function_exists( 'apache_setenv' ) ) {
				@apache_setenv( 'no-gzip', '1' );
			}
			@header_remove( 'Content-Encoding' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			echo wp_json_encode( $data );
			return true;
		} catch ( \Throwable $e ) { // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return $served;
		}
	}

	/* -------------------- DB helpers -------------------- */

	private function chunks_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'fvqa_chunks';
	}

	private function logs_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'fvqa_logs';
	}

	private function table_exists( &$last_error = '' ) {
		global $wpdb;
		$last_error = '';
		$table      = $this->chunks_table_name();
		$wpdb->hide_errors();
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$last_error = $wpdb->last_error;
		return ( $exists === $table );
	}

	private function chunks_count( $video_id ) {
		global $wpdb;
		if ( ! $video_id ) {
			return 0;
		}
		$table = $this->chunks_table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE video_id=%s", $video_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Try installer class; fallback to direct dbDelta.
	 *
	 * @param array $detail Filled with attempt/result/error.
	 * @return bool
	 */
	private function run_installer( &$detail ) {
		global $wpdb;
		$detail = array(
			'attempt' => '',
			'result'  => '',
			'error'   => '',
		);

		// Try to include the installer file if not loaded.
		if ( ! class_exists( 'FVQA_Install' ) ) {
			$file = trailingslashit( dirname( __DIR__ ) ) . 'includes/class-install.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		// Preferred path: use FVQA_Install::maybe_install().
		if ( class_exists( 'FVQA_Install' ) && method_exists( 'FVQA_Install', 'maybe_install' ) ) {
			try {
				FVQA_Install::maybe_install();
				$detail['attempt'] = 'installer_class';
				$detail['result']  = 'dbDelta_called';
				return true;
			} catch ( \Throwable $e ) {
				$detail['attempt'] = 'installer_class';
				$detail['result']  = 'exception';
				$detail['error']   = $e->getMessage();
				// fall through to fallback.
			}
		} else {
			$detail['attempt'] = 'installer_missing';
		}

		// Fallback: direct CREATE TABLE (idempotent).
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset_collate = $wpdb->get_charset_collate();
			$chunks          = $this->chunks_table_name();
			$logs            = $this->logs_table_name();

			$sql1 = "CREATE TABLE IF NOT EXISTS $chunks (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				video_id VARCHAR(20) NOT NULL,
				start_sec INT UNSIGNED NOT NULL,
				end_sec INT UNSIGNED NOT NULL,
				text LONGTEXT NOT NULL,
				embedding LONGTEXT NULL,
				PRIMARY KEY (id),
				KEY vid_idx (video_id),
				KEY time_idx (video_id, start_sec)
			) $charset_collate;";

			$sql2 = "CREATE TABLE IF NOT EXISTS $logs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				video_id VARCHAR(20) NOT NULL,
				user_id BIGINT UNSIGNED NULL,
				question LONGTEXT NOT NULL,
				answer LONGTEXT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY vidlog_idx (video_id, created_at)
			) $charset_collate;";

			dbDelta( $sql1 );
			dbDelta( $sql2 );

			$detail['attempt'] = 'fallback_sql';
			$detail['result']  = 'dbDelta_called';
			return true;
		} catch ( \Throwable $e ) {
			$detail['attempt'] = 'fallback_sql';
			$detail['result']  = 'exception';
			$detail['error']   = $e->getMessage();
			return false;
		}
	}

	/**
	 * Ensure tables exist; try self-repair once.
	 *
	 * @return array { ok, repaired, error }
	 */
	private function ensure_tables_ready() {
		$err = '';
		if ( $this->table_exists( $err ) ) {
			return array(
				'ok'       => true,
				'repaired' => false,
				'error'    => '',
			);
		}
		$detail = array();
		$ok     = $this->run_installer( $detail );
		$err2   = '';
		$ready  = $this->table_exists( $err2 );

		return array(
			'ok'       => $ready,
			'repaired' => $ok,
			'error'    => $ready ? '' : ( $err2 ? $err2 : ( $detail['error'] ?? 'unknown' ) ),
		);
	}

	/* -------------------- Utility -------------------- */

	private function sanitize_video_id( $raw ) {
		$raw = (string) $raw;
		$raw = sanitize_text_field( $raw );
		// Keep common safe chars (vimeo ids are usually numeric but allow fallback).
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', $raw );
	}

	private function client_ip() {
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		return sanitize_text_field( (string) $ip );
	}

	/**
	 * Basic rate limiter for public /ask endpoint.
	 *
	 * @param string $bucket_key Key part (e.g., video).
	 * @param int    $limit      Max requests per window.
	 * @param int    $window     Window in seconds.
	 * @return array { allowed(bool), remaining(int) }
	 */
	private function rate_limit( $bucket_key, $limit = 12, $window = 60 ) {
		$ip   = $this->client_ip();
		$key  = 'fvqa_rl_' . md5( $ip . '|' . $bucket_key );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$data = array( 'count' => 1, 'reset' => time() + $window );
			set_transient( $key, $data, $window );
			return array( 'allowed' => true, 'remaining' => $limit - 1 );
		}
		if ( time() > (int) $data['reset'] ) {
			$data = array( 'count' => 1, 'reset' => time() + $window );
			set_transient( $key, $data, $window );
			return array( 'allowed' => true, 'remaining' => $limit - 1 );
		}
		$data['count']++;
		set_transient( $key, $data, max( 1, (int) $data['reset'] - time() ) );
		return array( 'allowed' => ( $data['count'] <= $limit ), 'remaining' => max( 0, $limit - $data['count'] ) );
	}

	/* -------------------- Non-mutating endpoints -------------------- */

	public function chunks( \WP_REST_Request $req ) {
		global $wpdb;
		$video_id = $this->sanitize_video_id( (string) $req->get_param( 'video_id' ) );

		$repair = $this->ensure_tables_ready();

		$table = $this->chunks_table_name();
		$count = $repair['ok'] ? $this->chunks_count( $video_id ) : 0;

		$first = $repair['ok']
			? $wpdb->get_row(
				$wpdb->prepare(
					"SELECT start_sec, end_sec, LEFT(text,160) AS text FROM $table WHERE video_id=%s ORDER BY start_sec ASC LIMIT 1",
					$video_id
				),
				ARRAY_A
			)
			: null;

		$last = $repair['ok']
			? $wpdb->get_row(
				$wpdb->prepare(
					"SELECT start_sec, end_sec, LEFT(text,160) AS text FROM $table WHERE video_id=%s ORDER BY start_sec DESC LIMIT 1",
					$video_id
				),
				ARRAY_A
			)
			: null;

		return rest_ensure_response(
			array(
				'video_id'     => $video_id,
				'db_ok'        => $repair['ok'],
				'db_repaired'  => $repair['repaired'],
				'db_error'     => $repair['error'],
				'count'        => (int) $count,
				'first'        => $first ?: null,
				'last'         => $last ?: null,
			)
		);
	}

	public function diag( \WP_REST_Request $req ) {
		global $wpdb;
		$o        = fvqa_get_settings();
		$video_id = $this->sanitize_video_id( (string) $req->get_param( 'video_id' ) );

		$repair = $this->ensure_tables_ready();
		$count  = $repair['ok'] ? $this->chunks_count( $video_id ) : 0;

		// Probe Vimeo texttracks.
		$vimeo_status = null;
		$vimeo_items  = null;
		$vimeo_error  = null;
		$vimeo_link   = "https://api.vimeo.com/videos/{$video_id}/texttracks";
		try {
			$tok = trim( (string) ( $o['vimeo_token'] ?? '' ) );
			if ( '' !== $tok ) {
				$res = fvqa_http_with_retry(
					'GET',
					$vimeo_link,
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $tok,
							'Accept'        => 'application/vnd.vimeo.*+json;version=3.4',
						),
						'timeout' => 25,
					),
					1
				);
				if ( is_wp_error( $res ) ) {
					$vimeo_error = 'WP_Error: ' . $res->get_error_message();
				} else {
					$vimeo_status = wp_remote_retrieve_response_code( $res );
					$body         = json_decode( wp_remote_retrieve_body( $res ), true );
					if ( is_array( $body ) ) {
						$vimeo_items = ( isset( $body['data'] ) && is_array( $body['data'] ) ) ? count( $body['data'] ) : 0;
					} else {
						$vimeo_error = 'Invalid JSON from Vimeo';
					}
				}
			} else {
				$vimeo_error = 'Missing Vimeo token';
			}
		} catch ( \Throwable $e ) {
			$vimeo_error = $e->getMessage();
		}

		// Cache status.
		$notes_cache = 'n/a';
		if ( class_exists( 'FVQA_Retriever' ) && $repair['ok'] ) {
			try {
				$gen = array(
					'model'         => $o['openai_model'],
					'system_prompt' => $o['system_prompt'],
					'user_prompt'   => $o['user_prompt'],
					'temperature'   => $o['temperature'],
					'top_p'         => $o['top_p'],
					'max_tokens'    => min( 800, (int) ( $o['max_tokens'] ?? 1200 ) ),
					'map_model'     => 'gpt-4.1-mini',
				);
				$rtv         = new FVQA_Retriever( $o['openai_key'], $o['similarity_threshold'], $o['max_chunks'], $gen );
				$notes_cache = ( $count > 0 && method_exists( $rtv, 'warm' ) ) ? ( $rtv->warm( $video_id ) ? 'cached' : 'empty' ) : 'skipped';
			} catch ( \Throwable $e ) {
				$notes_cache = 'error: ' . $e->getMessage();
			}
		}

		return rest_ensure_response(
			array(
				'video_id'             => $video_id,
				'db_table_ok'          => $repair['ok'],
				'db_repaired'          => $repair['repaired'],
				'db_error'             => $repair['error'],
				'chunks_in_db'         => (int) $count,
				'openai_model'         => $o['openai_model'],
				'similarity_threshold' => $o['similarity_threshold'],
				'max_chunks'           => $o['max_chunks'],
				'have_openai_key'      => ! empty( $o['openai_key'] ),
				'have_vimeo_token'     => ! empty( $o['vimeo_token'] ),
				'vimeo_probe'          => array(
					'url'    => $vimeo_link,
					'status' => $vimeo_status,
					'items'  => $vimeo_items,
					'error'  => $vimeo_error,
				),
				'notes_cache'          => $notes_cache,
			)
		);
	}

	/* -------------------- Mutating endpoints -------------------- */

	public function force_index( \WP_REST_Request $req ) {
		$o        = fvqa_get_settings();
		$video_id = $this->sanitize_video_id( (string) $req->get_param( 'video_id' ) );

		$repair = $this->ensure_tables_ready();
		if ( ! $repair['ok'] ) {
			return rest_ensure_response(
				array(
					'ok'     => false,
					'video_id' => $video_id,
					'before' => 0,
					'after'  => 0,
					'delta'  => 0,
					'error'  => 'DB table missing and repair failed: ' . $repair['error'],
				)
			);
		}

		$before = $this->chunks_count( $video_id );
		$error  = null;

		try {
			$this->ensure_indexed_if_needed( $video_id, $o );
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
		}

		$after = $this->chunks_count( $video_id );

		return rest_ensure_response(
			array(
				'ok'       => ( $after > $before ),
				'video_id' => $video_id,
				'before'   => (int) $before,
				'after'    => (int) $after,
				'delta'    => (int) ( $after - $before ),
				'error'    => $error,
			)
		);
	}

	public function warm( \WP_REST_Request $req ) {
		$o        = fvqa_get_settings();
		$video_id = $this->sanitize_video_id( (string) ( $req->get_param( 'video_id' ) ?? '' ) );

		$repair = $this->ensure_tables_ready();
		if ( ! $repair['ok'] ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'DB table missing and repair failed: ' . $repair['error'] ) );
		}

		if ( '' === $video_id ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'Missing video_id' ) );
		}

		try {
			$before = $this->chunks_count( $video_id );
			$this->ensure_indexed_if_needed( $video_id, $o );
			$after = $this->chunks_count( $video_id );

			$notes_status = 'skipped';
			if ( class_exists( 'FVQA_Retriever' ) && $after > 0 ) {
				$gen = array(
					'model'         => $o['openai_model'],
					'system_prompt' => $o['system_prompt'],
					'user_prompt'   => $o['user_prompt'],
					'temperature'   => $o['temperature'],
					'top_p'         => $o['top_p'],
					'max_tokens'    => min( 800, (int) ( $o['max_tokens'] ?? 1200 ) ),
					'map_model'     => 'gpt-4.1-mini',
				);
				$rtv          = new FVQA_Retriever( $o['openai_key'], $o['similarity_threshold'], $o['max_chunks'], $gen );
				$notes_status = $rtv->warm( $video_id ) ? 'cached' : 'no-notes';
			}

			if ( $after <= 0 ) {
				return rest_ensure_response(
					array(
						'ok'          => false,
						'error'       => 'No transcript chunks found for this video. Confirm captions/subtitles exist and your Vimeo token can read them.',
						'before'      => (int) $before,
						'after'       => (int) $after,
						'notes_cache' => $notes_status,
					)
				);
			}

			return rest_ensure_response(
				array(
					'ok'          => true,
					'before'      => (int) $before,
					'after'       => (int) $after,
					'notes_cache' => $notes_status,
				)
			);
		} catch ( \Throwable $e ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => $e->getMessage() ) );
		}
	}

	public function ask( \WP_REST_Request $req ) {
		$o = fvqa_get_settings();

		// Optional nonce check (if provided by front-end).
		$nonce = (string) ( $req->get_param( 'nonce' ) ?? '' );
		if ( '' !== $nonce && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			// Don't hard-fail (keeps anonymous use possible), but tag response.
			$nonce_ok = false;
		} else {
			$nonce_ok = ( '' === $nonce ) ? null : true;
		}

		// Auto-repair if needed.
		$repair = $this->ensure_tables_ready();
		if ( ! $repair['ok'] ) {
			return rest_ensure_response(
				array(
					'answer'  => 'Error: database not initialized. ' . $repair['error'],
					'sources' => array(),
				)
			);
		}

		$question  = (string) ( $req->get_param( 'question' ) ?? '' );
		$mode      = (string) ( $req->get_param( 'mode' ) ?? 'chat' );
		$button_id = (string) ( $req->get_param( 'button_id' ) ?? '' );
		$video_id  = $this->sanitize_video_id( (string) ( $req->get_param( 'video_id' ) ?? '' ) );

		// Soft limits to prevent abuse.
		$question = wp_strip_all_tags( $question );
		if ( strlen( $question ) > 2000 ) {
			$question = substr( $question, 0, 2000 );
		}

		// IP-scoped rate limit per video.
		$rl = $this->rate_limit( $video_id ?: 'global', 12, 60 );
		if ( ! $rl['allowed'] ) {
			return rest_ensure_response(
				array(
					'answer'  => __( 'Rate limit: please wait a moment and try again.', 'farhat-video-qa' ),
					'sources' => array(),
				)
			);
		}

		$time_hint  = $req->get_param( 'time_hint' );
		$want_audio = $req->get_param( 'want_audio' );
		if ( '' === $time_hint || null === $time_hint ) {
			$time_hint = null;
		} else {
			$time_hint = (int) $time_hint;
		}

		$gen = array(
			'model'         => $o['openai_model'],
			'system_prompt' => $o['system_prompt'],
			'user_prompt'   => $o['user_prompt'],
			'temperature'   => $o['temperature'],
			'top_p'         => $o['top_p'],
			'max_tokens'    => $o['max_tokens'],
		);

		// Per-button overrides.
		if ( 'button' === $mode && ! empty( $button_id ) && ! empty( $o['action_buttons'] ) && is_array( $o['action_buttons'] ) ) {
			foreach ( $o['action_buttons'] as $btn ) {
				if ( ! empty( $btn['id'] ) && $btn['id'] === $button_id ) {
					if ( ! empty( $btn['model'] ) ) {
						$gen['model'] = $btn['model'];
					}
					if ( ! empty( $btn['system_prompt'] ) ) {
						$gen['system_prompt'] = $btn['system_prompt'];
					}
					if ( ! empty( $btn['user_prompt'] ) ) {
						$gen['user_prompt'] = $btn['user_prompt'];
					}
					if ( ! empty( $btn['audio'] ) ) {
						$want_audio = $want_audio || (bool) $btn['audio'];
					}
					break;
				}
			}
		}

		$this->normalize_prompts( $gen );

		// Ensure chunks exist (best-effort).
		try {
			if ( ! empty( $video_id ) ) {
				$this->ensure_indexed_if_needed( $video_id, $o );
			}
		} catch ( \Throwable $e ) {
			error_log( '[FVQA] ensure_indexing error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		try {
			$rtv = new FVQA_Retriever( $o['openai_key'], $o['similarity_threshold'], $o['max_chunks'], $gen );
			$res = $rtv->answer( $video_id, $question, $time_hint );
		} catch ( \Throwable $e ) {
			return rest_ensure_response( array( 'answer' => 'Error: ' . $e->getMessage(), 'sources' => array() ) );
		}

		$answer_text = (string) ( $res['answer'] ?? '' );
		$sources     = $res['sources'] ?? array();

		// Log Q&A (best-effort).
		if ( class_exists( 'FVQA_Logger' ) ) {
			try {
				$user_id = (int) get_current_user_id();
				FVQA_Logger::log( $video_id, $user_id, $question, $answer_text );
			} catch ( \Throwable $e ) {
				// Silent.
			}
		}

		if ( '' === $answer_text || "I couldn't find that in this video." === $answer_text ) {
			$cnt = ! empty( $video_id ) ? $this->chunks_count( $video_id ) : -1;
			error_log( "[FVQA] not-found. video_id={$video_id} chunks={$cnt} q='" . substr( $question, 0, 120 ) . "'" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$out = array(
			'answer'    => $answer_text,
			'sources'   => $sources,
			'nonce_ok'  => $nonce_ok, // null (not provided) | true | false.
		);

		// Optional TTS.
		if ( $want_audio && '' !== $answer_text && 0 !== stripos( $answer_text, 'Error:' ) ) {
			$tts = fvqa_tts_synthesize( $o['openai_key'], $answer_text, $o['tts_voice'], $o['tts_model'] );
			if ( is_wp_error( $tts ) ) {
				$out['audio_error'] = $tts->get_error_message();
			} else {
				$out['audio_url'] = $tts['url'];
			}
		}

		return rest_ensure_response( $out );
	}

	/* -------------------- Vimeo/indexing helpers -------------------- */

	private function ensure_indexed_if_needed( $video_id, $options ) {
		if ( ! $video_id ) {
			return;
		}
		$have = $this->chunks_count( $video_id );
		if ( $have > 0 ) {
			return;
		}

		$lock_key = 'fvqa_indexing_lock_' . $video_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 60 );

		try {
			if ( class_exists( 'FVQA_Indexer' ) ) {
				$idx = new FVQA_Indexer( $options['openai_key'], $options );
				$idx->ensure_indexed( $video_id );
			}
		} catch ( \Throwable $e ) {
			error_log( '[FVQA] Indexing failed for video ' . $video_id . ' : ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			throw $e;
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Ensure {sources} placeholder exists; normalize common variants.
	 *
	 * @param array $gen Generation config (by ref).
	 */
	private function normalize_prompts( &$gen ) {
		$map = array( '{s}', '{S}', '{source}', '{Source}', '{SOURCE}', '{Sources}' );
		if ( ! empty( $gen['user_prompt'] ) ) {
			$up = (string) $gen['user_prompt'];
			$up = str_replace( $map, '{sources}', $up );
			if ( false === stripos( $up, '{sources}' ) ) {
				$up = rtrim( $up ) . "\n\nUse these transcript excerpts:\n{sources}";
			}
			$gen['user_prompt'] = $up;
		}
	}

	/* -------------------- Repair endpoint -------------------- */

	public function repair_install( \WP_REST_Request $req ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$err       = '';
		$before_ok = $this->table_exists( $err );

		$detail = array();
		$ok     = $this->run_installer( $detail );

		$err2     = '';
		$after_ok = $this->table_exists( $err2 );

		return rest_ensure_response(
			array(
				'ok'            => $after_ok,
				'before_ok'     => $before_ok,
				'before_error'  => $err,
				'attempt'       => $detail['attempt'] ?? '',
				'result'        => $detail['result'] ?? '',
				'attempt_error' => $detail['error'] ?? '',
				'after_error'   => $err2,
			)
		);
	}
}
