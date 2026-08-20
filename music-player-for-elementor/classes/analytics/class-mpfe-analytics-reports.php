<?php

if (!defined('ABSPATH')) {
	exit;
}

class MPFE_Analytics_Reports {

	public static function get_stats() {
		global $wpdb;

		$table = MPFE_Analytics_DB::summary_table();
		$stats = $wpdb->get_row(
			"SELECT
				COALESCE(SUM(plays), 0) AS plays,
				COALESCE(SUM(completed_plays), 0) AS completed_plays,
				COALESCE(SUM(total_listen_seconds), 0) AS total_listen_seconds
			FROM $table",
			ARRAY_A
		);

		if (empty($stats)) {
			return self::empty_stats();
		}

		$plays = absint($stats['plays']);
		$completed_plays = absint($stats['completed_plays']);
		$total_listen_seconds = absint($stats['total_listen_seconds']);

		return array(
			'plays'                => $plays,
			'completed_plays'      => $completed_plays,
			'total_listen_seconds' => $total_listen_seconds,
			'avg_listen_seconds'   => $plays > 0 ? absint(round($total_listen_seconds / $plays)) : 0,
			'completion_rate'      => $plays > 0 ? absint(round(($completed_plays / $plays) * 100)) : 0,
		);
	}

	public static function get_top_tracks($limit = 10) {
		global $wpdb;

		$limit = self::sanitize_limit($limit);

		if ($limit < 1) {
			return array();
		}

		$table = MPFE_Analytics_DB::summary_table();
		$sql = $wpdb->prepare(
			"SELECT
				audio_url,
				MAX(file_name) AS file_name,
				SUM(plays) AS plays,
				SUM(completed_plays) AS completed_plays,
				SUM(total_listen_seconds) AS total_listen_seconds,
				CASE WHEN SUM(plays) > 0 THEN ROUND(SUM(total_listen_seconds) / SUM(plays)) ELSE 0 END AS avg_listen_seconds,
				MAX(duration_seconds) AS duration_seconds,
				MAX(last_played_at) AS last_played_at
			FROM $table
			GROUP BY audio_url
			ORDER BY plays DESC
			LIMIT %d",
			$limit
		);

		return self::cast_rows($wpdb->get_results($sql, ARRAY_A));
	}

	public static function get_top_pages($limit = 10) {
		global $wpdb;

		$limit = self::sanitize_limit($limit);

		if ($limit < 1) {
			return array();
		}

		$table = MPFE_Analytics_DB::summary_table();
		$sql = $wpdb->prepare(
			"SELECT
				MAX(page_id) AS page_id,
				MAX(page_title) AS page_title,
				MAX(page_url) AS page_url,
				SUM(plays) AS plays,
				SUM(completed_plays) AS completed_plays,
				SUM(total_listen_seconds) AS total_listen_seconds,
				CASE WHEN SUM(plays) > 0 THEN ROUND(SUM(total_listen_seconds) / SUM(plays)) ELSE 0 END AS avg_listen_seconds,
				MAX(last_played_at) AS last_played_at
			FROM $table
			GROUP BY CASE
				WHEN page_id <> 0 THEN CONCAT('id:', page_id)
				ELSE CONCAT('url:', COALESCE(page_url, ''))
			END
			ORDER BY plays DESC
			LIMIT %d",
			$limit
		);

		return self::cast_rows($wpdb->get_results($sql, ARRAY_A));
	}

	public static function get_chart_data($period = '30_days') {
		global $wpdb;

		$period = self::normalize_period($period);
		$table = MPFE_Analytics_DB::events_table();
		$timezone = self::get_wordpress_timezone();
		$today = new DateTimeImmutable(current_time('mysql'), $timezone);
		$today = $today->setTime(0, 0, 0);
		$is_monthly = '12_months' === $period;

		if ($is_monthly) {
			$start_time = $today->modify('first day of this month')->modify('-11 months');
			$end_time = $today->modify('first day of next month');
			$bucket_format = 'Y-m';
			$sql_bucket_format = '%Y-%m';
			$bucket_modifier = '+1 month';
		} else {
			$days = 'today' === $period ? 1 : ('7_days' === $period ? 7 : 30);
			$start_time = $today->modify('-' . ($days - 1) . ' days');
			$end_time = $today->modify('+1 day');
			$bucket_format = 'Y-m-d';
			$sql_bucket_format = '%Y-%m-%d';
			$bucket_modifier = '+1 day';
		}

		$sql = $wpdb->prepare(
			"SELECT
				DATE_FORMAT(created_at, %s) AS bucket_key,
				COUNT(*) AS plays
			FROM $table
			WHERE event_type = %s
				AND created_at >= %s
				AND created_at < %s
			GROUP BY DATE_FORMAT(created_at, %s)
			ORDER BY bucket_key ASC",
			$sql_bucket_format,
			'play',
			$start_time->format('Y-m-d H:i:s'),
			$end_time->format('Y-m-d H:i:s'),
			$sql_bucket_format
		);

		$plays_by_bucket = array();
		$rows = $wpdb->get_results($sql, ARRAY_A);

		foreach ($rows as $row) {
			if (isset($row['bucket_key'], $row['plays'])) {
				$plays_by_bucket[$row['bucket_key']] = absint($row['plays']);
			}
		}

		$chart_data = array();

		for ($bucket = $start_time; $bucket < $end_time; $bucket = $bucket->modify($bucket_modifier)) {
			$key = $bucket->format($bucket_format);
			$chart_data[] = array(
				'key'   => $key,
				'label' => self::format_chart_bucket_label($bucket, $is_monthly, $timezone),
				'plays' => isset($plays_by_bucket[$key]) ? $plays_by_bucket[$key] : 0,
			);
		}

		return $chart_data;
	}

	private static function format_chart_bucket_label($bucket, $is_monthly, $timezone) {
		$format = $is_monthly ? 'M Y' : 'M j';

		return $bucket->setTimezone($timezone)->format($format);
	}

	private static function get_wordpress_timezone() {
		$timezone_string = get_option('timezone_string');

		if (!empty($timezone_string)) {
			try {
				return new DateTimeZone($timezone_string);
			} catch (Exception $exception) {
				// Fall back to the configured UTC offset below.
			}
		}

		$offset = (float) get_option('gmt_offset', 0);
		$sign = $offset < 0 ? '-' : '+';
		$offset = abs($offset);
		$hours = (int) $offset;
		$minutes = (int) round(($offset - $hours) * 60);

		return new DateTimeZone(sprintf('%s%02d:%02d', $sign, $hours, $minutes));
	}

	public static function format_seconds($seconds) {
		$seconds = absint($seconds);

		if ($seconds >= HOUR_IN_SECONDS) {
			$hours = floor($seconds / HOUR_IN_SECONDS);
			$minutes = floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);

			return sprintf('%dh %02dm', $hours, $minutes);
		}

		$minutes = floor($seconds / MINUTE_IN_SECONDS);
		$remaining_seconds = $seconds % MINUTE_IN_SECONDS;

		return sprintf('%dm %02ds', $minutes, $remaining_seconds);
	}

	private static function empty_stats() {
		return array(
			'plays'                => 0,
			'completed_plays'      => 0,
			'total_listen_seconds' => 0,
			'avg_listen_seconds'   => 0,
			'completion_rate'      => 0,
		);
	}

	private static function sanitize_limit($limit) {
		return min(100, absint($limit));
	}

	public static function normalize_period($period) {
		$period = sanitize_key($period);
		$allowed_periods = array('today', '7_days', '30_days', '12_months');

		if (!in_array($period, $allowed_periods, true)) {
			return '30_days';
		}

		return $period;
	}

	private static function cast_rows($rows) {
		if (empty($rows) || !is_array($rows)) {
			return array();
		}

		$integer_keys = array(
			'page_id',
			'plays',
			'completed_plays',
			'total_listen_seconds',
			'avg_listen_seconds',
			'duration_seconds',
		);

		foreach ($rows as $row_index => $row) {
			foreach ($integer_keys as $key) {
				if (isset($row[$key])) {
					$rows[$row_index][$key] = absint($row[$key]);
				}
			}
		}

		return $rows;
	}
}
