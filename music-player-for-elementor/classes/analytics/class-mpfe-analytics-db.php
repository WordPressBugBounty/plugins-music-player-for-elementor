<?php

if (!defined('ABSPATH')) {
	exit;
}

class MPFE_Analytics_DB {

	const SUMMARY_UPDATED_OPTION = 'mpfe_analytics_summary_updated_at';
	const SUMMARY_EVENT_ID_OPTION = 'mpfe_analytics_last_processed_event_id';
	const SUMMARY_DIRTY_EVENT_ID_OPTION = 'mpfe_analytics_summary_dirty_event_id';
	const DB_VERSION_OPTION = 'mpfe_db_version';
	const SUMMARY_BATCH_SIZE = 5000;
	const SUMMARY_MAX_BATCHES = 10;

	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'mpfe_analytics_events';
	}

	public static function summary_table() {
		global $wpdb;

		return $wpdb->prefix . 'mpfe_analytics_summary';
	}

	public static function table_exists($table_name) {
		global $wpdb;

		return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
	}

	public static function maybe_upgrade() {
		$installed_version = get_option(self::DB_VERSION_OPTION);

		if ($installed_version === MPFE_DB_VERSION && self::table_exists(self::events_table()) && self::table_exists(self::summary_table()) && self::has_required_schema()) {
			return;
		}

		self::create_tables();

		if (self::table_exists(self::events_table()) && self::table_exists(self::summary_table()) && self::has_required_schema()) {
			// Analytics is pre-release, so safely rebuilding the derived summary is simpler than migrating it.
			self::reset_summary_state();
			update_option(self::DB_VERSION_OPTION, MPFE_DB_VERSION);
		}
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$events_table = self::events_table();
		$summary_table = self::summary_table();

		$events_sql = "CREATE TABLE $events_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id varchar(32) NULL DEFAULT NULL,
			event_type varchar(30) NOT NULL,
			audio_url text NOT NULL,
			file_name varchar(255) NOT NULL DEFAULT '',
			page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_title varchar(255) NOT NULL DEFAULT '',
			page_url text NULL,
			listen_seconds int(10) unsigned NOT NULL DEFAULT 0,
			duration_seconds int(10) unsigned NOT NULL DEFAULT 0,
			completed tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY event_type (event_type),
			KEY event_type_created_at (event_type, created_at),
			KEY page_id (page_id),
			KEY created_at (created_at),
			KEY completed (completed)
		) ENGINE=InnoDB $charset_collate;";

		$summary_sql = "CREATE TABLE $summary_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			audio_url text NOT NULL,
			file_name varchar(255) NOT NULL DEFAULT '',
			page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_title varchar(255) NOT NULL DEFAULT '',
			page_url text NULL,
			plays bigint(20) unsigned NOT NULL DEFAULT 0,
			completed_plays bigint(20) unsigned NOT NULL DEFAULT 0,
			total_listen_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
			avg_listen_seconds int(10) unsigned NOT NULL DEFAULT 0,
			duration_seconds int(10) unsigned NOT NULL DEFAULT 0,
			last_played_at datetime NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY page_id (page_id),
			KEY plays (plays),
			KEY completed_plays (completed_plays),
			KEY total_listen_seconds (total_listen_seconds),
			KEY last_played_at (last_played_at)
		) ENGINE=InnoDB $charset_collate;";

		dbDelta($events_sql);
		self::ensure_event_id_schema();
		self::ensure_event_type_created_at_index();
		dbDelta($summary_sql);
		self::ensure_summary_key_schema();
	}

	public static function insert_event($event) {
		global $wpdb;

		$defaults = array(
			'event_type'       => '',
			'audio_url'        => '',
			'file_name'        => '',
			'page_id'          => 0,
			'page_title'       => '',
			'page_url'         => '',
			'listen_seconds'   => 0,
			'duration_seconds' => 0,
			'completed'        => 0,
			'event_id'         => null,
		);

		$event = wp_parse_args($event, $defaults);

		$event_id = strtolower(sanitize_text_field($event['event_id']));
		$event_id = preg_match('/^[a-f0-9]{32}$/', $event_id) ? $event_id : null;
		$table = self::events_table();
		$event_id_placeholder = null === $event_id ? 'NULL' : '%s';
		$values = array(
			sanitize_key($event['event_type']),
			self::truncate_text(esc_url_raw($event['audio_url']), MPFE_Analytics_Recorder::MAX_AUDIO_URL_LENGTH),
			self::truncate_text(sanitize_text_field($event['file_name']), 255),
			absint($event['page_id']),
			self::truncate_text(sanitize_text_field($event['page_title']), MPFE_Analytics_Recorder::MAX_PAGE_TITLE_LENGTH),
			self::truncate_text(esc_url_raw($event['page_url']), MPFE_Analytics_Recorder::MAX_PAGE_URL_LENGTH),
			absint($event['listen_seconds']),
			absint($event['duration_seconds']),
			!empty($event['completed']) ? 1 : 0,
			current_time('mysql'),
		);

		if (null !== $event_id) {
			array_unshift($values, $event_id);
		}

		$sql = $wpdb->prepare(
			"INSERT INTO $table
				(event_id, event_type, audio_url, file_name, page_id, page_title, page_url, listen_seconds, duration_seconds, completed, created_at)
			VALUES ($event_id_placeholder, %s, %s, %s, %d, %s, %s, %d, %d, %d, %s)
			ON DUPLICATE KEY UPDATE id = id",
			$values
		);

		$result = $wpdb->query($sql);

		if ($result > 0 && $wpdb->insert_id > 0) {
			update_option(self::SUMMARY_DIRTY_EVENT_ID_OPTION, $wpdb->insert_id, false);
		}

		return $result;
	}

	private static function has_required_schema() {
		return self::has_event_id_schema() && self::has_event_type_created_at_index() && self::has_summary_key_schema();
	}

	private static function has_event_id_schema() {
		global $wpdb;

		$column = $wpdb->get_var("SHOW COLUMNS FROM " . self::events_table() . " LIKE 'event_id'");
		$index = $wpdb->get_var("SHOW INDEX FROM " . self::events_table() . " WHERE Key_name = 'event_id' AND Non_unique = 0");

		return !empty($column) && !empty($index);
	}

	private static function has_event_type_created_at_index() {
		global $wpdb;

		$index = $wpdb->get_results("SHOW INDEX FROM " . self::events_table() . " WHERE Key_name = 'event_type_created_at' ORDER BY Seq_in_index ASC", ARRAY_A);

		return 2 === count($index) && 'event_type' === $index[0]['Column_name'] && 'created_at' === $index[1]['Column_name'];
	}

	private static function ensure_event_type_created_at_index() {
		global $wpdb;

		if (!self::has_event_type_created_at_index()) {
			$wpdb->query("ALTER TABLE " . self::events_table() . " ADD KEY event_type_created_at (event_type, created_at)");
		}
	}

	private static function has_summary_key_schema() {
		global $wpdb;

		$column = $wpdb->get_row("SHOW COLUMNS FROM " . self::summary_table() . " LIKE 'summary_key'", ARRAY_A);
		$index = $wpdb->get_var("SHOW INDEX FROM " . self::summary_table() . " WHERE Key_name = 'summary_key' AND Non_unique = 0");

		return !empty($column) && 'char(64)' === strtolower($column['Type']) && !empty($index);
	}

	private static function ensure_summary_key_schema() {
		global $wpdb;

		$table = self::summary_table();
		$summary_key_column = $wpdb->get_row("SHOW COLUMNS FROM $table LIKE 'summary_key'", ARRAY_A);
		$has_summary_key = !empty($summary_key_column);
		$has_sha256_summary_key = $has_summary_key && 'char(64)' === strtolower($summary_key_column['Type']);
		$has_unique_summary_key = !empty($wpdb->get_var("SHOW INDEX FROM $table WHERE Key_name = 'summary_key' AND Non_unique = 0"));

		// Existing pre-release summaries have no SHA-256 key. Clear this derived data before changing
		// the unique key, then let the normal incremental path reconstruct it from raw events.
		if (!$has_sha256_summary_key || !$has_unique_summary_key) {
			$wpdb->query("DELETE FROM $table");
		}

		if (!$has_summary_key) {
			$wpdb->query("ALTER TABLE $table ADD COLUMN summary_key char(64) NOT NULL DEFAULT '' AFTER id");
		} elseif (!$has_sha256_summary_key) {
			$wpdb->query("ALTER TABLE $table MODIFY COLUMN summary_key char(64) NOT NULL DEFAULT ''");
		}

		if (!$has_unique_summary_key) {
			$wpdb->query("ALTER TABLE $table ADD UNIQUE KEY summary_key (summary_key)");
		}
	}

	private static function ensure_event_id_schema() {
		global $wpdb;

		$table = self::events_table();

		if (empty($wpdb->get_var("SHOW COLUMNS FROM $table LIKE 'event_id'"))) {
			$wpdb->query("ALTER TABLE $table ADD COLUMN event_id varchar(32) NULL DEFAULT NULL AFTER id");
		}

		if (empty($wpdb->get_var("SHOW INDEX FROM $table WHERE Key_name = 'event_id' AND Non_unique = 0"))) {
			$wpdb->query("ALTER TABLE $table ADD UNIQUE KEY event_id (event_id)");
		}
	}

	private static function truncate_text($value, $maximum_length) {
		if (function_exists('mb_substr')) {
			return mb_substr($value, 0, $maximum_length, 'UTF-8');
		}

		return substr($value, 0, $maximum_length);
	}

	public static function maybe_rebuild_summary() {
		global $wpdb;

		$summary_event_id = absint(get_option(self::SUMMARY_EVENT_ID_OPTION));
		$latest_event_id = absint($wpdb->get_var("SELECT MAX(id) FROM " . self::events_table()));

		if (empty($latest_event_id) || $latest_event_id <= $summary_event_id) {
			update_option(self::SUMMARY_DIRTY_EVENT_ID_OPTION, $summary_event_id, false);
			return false;
		}

		return self::refresh_summary();
	}

	/**
	 * Incrementally aggregate up to ten bounded ID ranges. This is the normal dashboard path.
	 */
	public static function refresh_summary() {
		global $wpdb;

		$events_table = self::events_table();
		$lock_name = self::lock_name();
		$lock_acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));

		if (1 !== $lock_acquired) {
			return false;
		}

		try {
			// Read directly after acquiring the database lock so another admin request cannot reuse
			// a request-local cached option value from before it waited for this lock.
			$last_processed_event_id = self::get_last_processed_event_id();
			$snapshot_max_event_id = absint($wpdb->get_var("SELECT MAX(id) FROM $events_table"));

			if (!empty($wpdb->last_error) || $snapshot_max_event_id <= $last_processed_event_id) {
				return false;
			}

			$total_result = 0;

			for ($batch_number = 0; $batch_number < self::SUMMARY_MAX_BATCHES && $last_processed_event_id < $snapshot_max_event_id; $batch_number++) {
				$batch_max_event_id = min($snapshot_max_event_id, $last_processed_event_id + self::SUMMARY_BATCH_SIZE);
				$result = self::aggregate_range($last_processed_event_id, $batch_max_event_id);

				if (false === $result) {
					return false;
				}

				$total_result += (int) $result;
				$last_processed_event_id = $batch_max_event_id;
			}

			return $total_result;
		} finally {
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
		}
	}

	/**
	 * Full repair path. It is intentionally not used during normal dashboard requests.
	 */
	public static function rebuild_summary() {
		global $wpdb;

		$summary_table = self::summary_table();
		$events_table = self::events_table();
		$lock_name = self::lock_name();
		$lock_acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));

		if (1 !== $lock_acquired) {
			return false;
		}

		try {
			$latest_event_id_value = $wpdb->get_var("SELECT MAX(id) FROM $events_table");

			if (!empty($wpdb->last_error)) {
				return false;
			}

			$latest_event_id = absint($latest_event_id_value);
			if (false === $wpdb->query('START TRANSACTION')) {
				return false;
			}

			if (false === $wpdb->query("DELETE FROM $summary_table")) {
				$wpdb->query('ROLLBACK');
				return false;
			}

			if (empty($latest_event_id)) {
				return self::finish_summary_transaction(0, current_time('mysql'), 0);
			}

			return self::aggregate_range(0, $latest_event_id, true);
		} finally {
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
		}
	}

	private static function aggregate_range($from_event_id, $to_event_id, $transaction_started = false) {
		global $wpdb;

		$events_table = self::events_table();
		$summary_table = self::summary_table();
		$updated_at = current_time('mysql');

		if (!$transaction_started && false === $wpdb->query('START TRANSACTION')) {
			return false;
		}

		$sql = $wpdb->prepare(
			"INSERT INTO $summary_table
				(summary_key, audio_url, file_name, page_id, page_title, page_url, plays, completed_plays, total_listen_seconds, avg_listen_seconds, duration_seconds, last_played_at, updated_at)
			SELECT
				SHA2(CONCAT(CHAR_LENGTH(audio_url), ':', audio_url, ':', page_id), 256) AS summary_key,
				audio_url,
				MAX(file_name) AS file_name,
				page_id,
				MAX(page_title) AS page_title,
				MAX(page_url) AS page_url,
				SUM(CASE WHEN event_type = 'play' THEN 1 ELSE 0 END) AS plays,
				SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_plays,
				SUM(listen_seconds) AS total_listen_seconds,
				ROUND(SUM(listen_seconds) / NULLIF(SUM(CASE WHEN event_type = 'play' THEN 1 ELSE 0 END), 0)) AS avg_listen_seconds,
				MAX(duration_seconds) AS duration_seconds,
				MAX(created_at) AS last_played_at,
				%s AS updated_at
			FROM $events_table
			WHERE id > %d AND id <= %d
			GROUP BY audio_url, page_id
			ON DUPLICATE KEY UPDATE
				file_name = GREATEST(file_name, VALUES(file_name)),
				page_title = GREATEST(page_title, VALUES(page_title)),
				page_url = GREATEST(page_url, VALUES(page_url)),
				avg_listen_seconds = ROUND((total_listen_seconds + VALUES(total_listen_seconds)) / NULLIF(plays + VALUES(plays), 0)),
				plays = plays + VALUES(plays),
				completed_plays = completed_plays + VALUES(completed_plays),
				total_listen_seconds = total_listen_seconds + VALUES(total_listen_seconds),
				duration_seconds = GREATEST(duration_seconds, VALUES(duration_seconds)),
				last_played_at = CASE WHEN last_played_at IS NULL OR VALUES(last_played_at) > last_played_at THEN VALUES(last_played_at) ELSE last_played_at END,
				updated_at = VALUES(updated_at)",
			$updated_at,
			$from_event_id,
			$to_event_id
		);

		$result = $wpdb->query($sql);

		if (false === $result) {
			$wpdb->query('ROLLBACK');
			return false;
		}

		return self::finish_summary_transaction($to_event_id, $updated_at, $result);
	}

	private static function finish_summary_transaction($last_processed_event_id, $updated_at, $result) {
		global $wpdb;

		update_option(self::SUMMARY_EVENT_ID_OPTION, $last_processed_event_id, false);
		update_option(self::SUMMARY_UPDATED_OPTION, $updated_at, false);

		if ($last_processed_event_id !== self::get_last_processed_event_id() || $updated_at !== get_option(self::SUMMARY_UPDATED_OPTION)) {
			$wpdb->query('ROLLBACK');
			return false;
		}

		if (false === $wpdb->query('COMMIT')) {
			$wpdb->query('ROLLBACK');
			return false;
		}

		update_option(self::SUMMARY_DIRTY_EVENT_ID_OPTION, $last_processed_event_id, false);

		return $result;
	}

	private static function reset_summary_state() {
		global $wpdb;

		$summary_table = self::summary_table();
		$latest_event_id = absint($wpdb->get_var("SELECT MAX(id) FROM " . self::events_table()));

		$wpdb->query("DELETE FROM $summary_table");
		update_option(self::SUMMARY_EVENT_ID_OPTION, 0, false);
		update_option(self::SUMMARY_DIRTY_EVENT_ID_OPTION, $latest_event_id, false);
		update_option(self::SUMMARY_UPDATED_OPTION, '', false);
	}

	private static function lock_name() {
		global $wpdb;

		return 'mpfe_analytics_' . md5(home_url('/') . $wpdb->prefix);
	}

	private static function get_last_processed_event_id() {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM $wpdb->options WHERE option_name = %s",
				self::SUMMARY_EVENT_ID_OPTION
			)
		);

		return absint($value);
	}
}
