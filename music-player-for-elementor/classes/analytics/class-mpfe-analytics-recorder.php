<?php

if (!defined('ABSPATH')) {
	exit;
}

class MPFE_Analytics_Recorder {
	const REGULAR_LISTEN_LIMIT = 30;
	const UNLOAD_LISTEN_LIMIT = 300;
	const MAX_AUDIO_URL_LENGTH = 2048;
	const MAX_PAGE_URL_LENGTH = 2048;
	const MAX_PAGE_TITLE_LENGTH = 255;
	const CLIENT_RATE_LIMIT = 40;
	const IP_RATE_LIMIT = 1200;
	const RATE_LIMIT_WINDOW = 60;

	public static function register_hooks() {
		add_action('wp_ajax_mpfe_record_analytics_event', array(__CLASS__, 'record_event'));
		add_action('wp_ajax_nopriv_mpfe_record_analytics_event', array(__CLASS__, 'record_event'));
		add_action('wp_ajax_mpfe_get_analytics_nonce', array(__CLASS__, 'get_nonce'));
		add_action('wp_ajax_nopriv_mpfe_get_analytics_nonce', array(__CLASS__, 'get_nonce'));
	}

	public static function get_nonce() {
		if (!self::is_pro_enabled()) {
			wp_send_json_error(array('message' => esc_html__('Analytics recording requires Pro.', 'music-player-for-elementor')), 403);
		}

		wp_send_json_success(array('nonce' => wp_create_nonce('mpfe_analytics_nonce')));
	}

	public static function record_event() {
		// This nonce prevents casual cross-site requests, but logged-out nonces are not visitor authentication.
		if (!check_ajax_referer('mpfe_analytics_nonce', 'nonce', false)) {
			wp_send_json_error(array('message' => esc_html__('Invalid analytics request.', 'music-player-for-elementor')), 403);
		}

		if (!self::is_pro_enabled()) {
			wp_send_json_error(array('message' => esc_html__('Analytics recording requires Pro.', 'music-player-for-elementor')), 403);
		}

		$event_type = sanitize_key(self::get_post_value('event_type'));
		$allowed_events = array('play', 'pause', 'completed', 'listen_time');

		if (!in_array($event_type, $allowed_events, true)) {
			wp_send_json_error(array('message' => esc_html__('Invalid analytics event type.', 'music-player-for-elementor')), 400);
		}

		$audio_url = self::get_url_value('audio_url', self::MAX_AUDIO_URL_LENGTH);

		if (empty($audio_url)) {
			wp_send_json_error(array('message' => esc_html__('Missing audio URL.', 'music-player-for-elementor')), 400);
		}

		$page_id = self::get_non_negative_integer('page_id');
		$page_title = self::truncate_text(sanitize_text_field(self::get_post_value('page_title')), self::MAX_PAGE_TITLE_LENGTH);
		$page_url = self::get_url_value('page_url', self::MAX_PAGE_URL_LENGTH);
		$listen_seconds = self::get_non_negative_integer('listen_seconds');
		$duration_seconds = self::get_non_negative_integer('duration_seconds');
		$unload = '1' === sanitize_text_field(self::get_post_value('unload'));
		$listen_limit = $unload ? self::UNLOAD_LISTEN_LIMIT : self::REGULAR_LISTEN_LIMIT;
		$client_id = self::get_client_id();
		$event_id = strtolower(self::get_post_value('event_id'));

		if (empty($client_id) || null === $page_id || null === $listen_seconds || null === $duration_seconds || !preg_match('/^[a-f0-9]{32}$/', $event_id)) {
			wp_send_json_error(array('message' => esc_html__('Invalid analytics event data.', 'music-player-for-elementor')), 400);
		}

		if (empty($page_url) || !self::is_current_site_url($page_url)) {
			wp_send_json_error(array('message' => esc_html__('Invalid analytics page URL.', 'music-player-for-elementor')), 400);
		}

		if ($duration_seconds > DAY_IN_SECONDS) {
			wp_send_json_error(array('message' => esc_html__('Audio duration exceeds the analytics limit.', 'music-player-for-elementor')), 400);
		}

		if ('listen_time' !== $event_type) {
			$listen_seconds = 0;
		} elseif ($listen_seconds < 1 || $listen_seconds > $listen_limit) {
			wp_send_json_error(array('message' => sprintf(esc_html__('Listen time must be between 1 and %d seconds.', 'music-player-for-elementor'), $listen_limit)), 400);
		} elseif ($duration_seconds > 0 && $listen_seconds > $duration_seconds) {
			wp_send_json_error(array('message' => esc_html__('Listen time cannot exceed audio duration.', 'music-player-for-elementor')), 400);
		}

		$event = array(
			'event_type'       => $event_type,
			'audio_url'        => $audio_url,
			'file_name'        => self::get_file_name($audio_url),
			'page_id'          => $page_id,
			'page_title'       => $page_title,
			'page_url'         => $page_url,
			'listen_seconds'   => $listen_seconds,
			'duration_seconds' => $duration_seconds,
			'completed'        => 'completed' === $event_type ? 1 : 0,
			'event_id'         => $event_id,
		);

		$rate_window = (int) floor(time() / self::RATE_LIMIT_WINDOW);

		if (!self::allow_rate($client_id, self::CLIENT_RATE_LIMIT, $rate_window) || !self::allow_rate(self::get_ip_identifier(), self::IP_RATE_LIMIT, $rate_window)) {
			wp_send_json_error(array('message' => esc_html__('Analytics request limit reached.', 'music-player-for-elementor')), 429);
		}

		$result = MPFE_Analytics_DB::insert_event($event);

		if (false === $result) {
			wp_send_json_error(array('message' => esc_html__('Could not record analytics event.', 'music-player-for-elementor')), 500);
		}

		wp_send_json_success();
	}

	private static function get_file_name($audio_url) {
		$path = parse_url($audio_url, PHP_URL_PATH);

		if (empty($path)) {
			return '';
		}

		return self::truncate_text(sanitize_file_name(rawurldecode(basename($path))), 255);
	}

	private static function get_url_value($key, $maximum_length) {
		$value = trim(self::get_post_value($key));

		if (empty($value) || strlen($value) > $maximum_length) {
			return '';
		}

		return esc_url_raw($value);
	}

	private static function get_non_negative_integer($key) {
		$value = self::get_post_value($key);

		if (!preg_match('/^\d{1,19}$/', $value)) {
			return null;
		}

		$integer = (int) $value;
		$normalized_value = ltrim($value, '0');
		$normalized_value = '' === $normalized_value ? '0' : $normalized_value;

		if ($integer < 0 || (string) $integer !== $normalized_value) {
			return null;
		}

		return $integer;
	}

	private static function truncate_text($value, $maximum_length) {
		if (function_exists('mb_substr')) {
			return mb_substr($value, 0, $maximum_length, 'UTF-8');
		}

		return substr($value, 0, $maximum_length);
	}

	private static function is_current_site_url($url) {
		$parts = wp_parse_url($url);

		if (empty($parts['scheme']) || empty($parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
			return false;
		}

		$submitted_host = strtolower($parts['host']);
		$submitted_port = isset($parts['port']) ? (int) $parts['port'] : 0;
		$site_urls = array(home_url('/'), site_url('/'));

		foreach ($site_urls as $site_url) {
			$site_parts = wp_parse_url($site_url);

			if (empty($site_parts['host']) || $submitted_host !== strtolower($site_parts['host'])) {
				continue;
			}

			$site_port = isset($site_parts['port']) ? (int) $site_parts['port'] : 0;

			if ($site_port === $submitted_port || (0 === $site_port && (0 === $submitted_port || 80 === $submitted_port || 443 === $submitted_port))) {
				return true;
			}
		}

		return false;
	}

	private static function get_client_id() {
		$client_id = strtolower(self::get_post_value('client_id'));

		if (!preg_match('/^[a-f0-9]{32}$/', $client_id)) {
			return '';
		}

		return hash_hmac('sha256', $client_id, wp_salt('nonce'));
	}

	private static function get_ip_identifier() {
		$ip_address = isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '';

		if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
			$ip_address = 'unknown';
		}

		// The IP is only represented by this site-secret hash in a short-lived transient, never in analytics data.
		return hash_hmac('sha256', $ip_address, wp_salt('auth'));
	}

	private static function allow_rate($identifier, $limit, $window) {
		$key = 'mpfe_analytics_rate_' . $window . '_' . substr(hash_hmac('sha256', $identifier, wp_salt('nonce')), 0, 32);
		$expiration = max(1, (($window + 1) * self::RATE_LIMIT_WINDOW) - time());

		return self::allow_transient_rate($key, $limit, $expiration);
	}

	private static function allow_transient_rate($key, $limit, $expiration) {
		global $wpdb;

		$lock_name = 'mpfe_rate_' . substr(hash_hmac('sha256', $key, wp_salt('auth')), 0, 32);
		$lock_acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 0));

		if (1 !== $lock_acquired) {
			if (function_exists('usleep')) {
				usleep(25000);
			}
			$lock_acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 0));
		}

		if (1 !== $lock_acquired) {
			return false;
		}

		try {
			$count = (int) get_transient($key);

			if ($count >= $limit) {
				return false;
			}

			$count++;

			if (1 === $count) {
				set_transient($key, $count, $expiration);
			} else {
				// Keep the original expiry so rate windows stay fixed rather than sliding on every event.
				set_transient($key, $count);
			}

			return true;
		} finally {
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
		}
	}

	private static function get_post_value($key) {
		if (!isset($_POST[$key]) || is_array($_POST[$key])) {
			return '';
		}

		return wp_unslash($_POST[$key]);
	}

	private static function is_pro_enabled() {
		return function_exists('mpfe_fs') && mpfe_fs()->can_use_premium_code__premium_only();
	}
}
