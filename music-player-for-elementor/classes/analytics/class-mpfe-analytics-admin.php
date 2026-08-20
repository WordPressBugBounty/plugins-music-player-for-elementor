<?php

if (!defined('ABSPATH')) {
	exit;
}

class MPFE_Analytics_Admin {
	private static $chart_data = array();
	private static $period = '30_days';
	private static $page_hook = '';

	public static function set_page_hook($hook) {
		self::$page_hook = is_string($hook) ? $hook : '';
	}

	public static function enqueue_assets($hook) {
		if (empty(self::$page_hook) || self::$page_hook !== $hook) {
			return;
		}

		wp_enqueue_style(
			'mpfe-analytics-admin',
			MPFE_DIR_URL . 'css/mpfe-analytics-admin.css',
			array(),
			MPFE_VERSION
		);

		if (!function_exists('mpfe_fs') || !mpfe_fs()->can_use_premium_code__premium_only()) {
			return;
		}

		MPFE_Analytics_DB::maybe_rebuild_summary();
		$requested_period = isset($_GET['period']) && !is_array($_GET['period']) ? wp_unslash($_GET['period']) : '';
		self::$period = MPFE_Analytics_Reports::normalize_period($requested_period);
		$chart_data = MPFE_Analytics_Reports::get_chart_data(self::$period);
		self::$chart_data = is_array($chart_data) ? $chart_data : array();

		wp_enqueue_script(
			'mpfe-chart-js',
			MPFE_DIR_URL . 'assets/vendor/chartjs/chart.umd.min.js',
			array(),
			'4.4.1',
			true
		);
		wp_enqueue_script(
			'mpfe-analytics-admin',
			MPFE_DIR_URL . 'js/mpfe-analytics-admin.js',
			array('jquery', 'mpfe-chart-js'),
			MPFE_VERSION,
			true
		);

		wp_localize_script(
			'mpfe-analytics-admin',
			'mpfeAnalyticsAdminData',
			array(
				'chartData'    => self::$chart_data,
				'datasetLabel' => __('Plays', 'music-player-for-elementor'),
			)
		);
	}

	public static function render_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'music-player-for-elementor'));
		}

		if (!function_exists('mpfe_fs') || !mpfe_fs()->can_use_premium_code__premium_only()) {
			self::render_upgrade_page();
			return;
		}

		$stats = MPFE_Analytics_Reports::get_stats();
		$top_tracks = MPFE_Analytics_Reports::get_top_tracks();
		$top_pages = MPFE_Analytics_Reports::get_top_pages();
		$chart_data = is_array(self::$chart_data) ? self::$chart_data : array();
		$period = self::$period;

		include MPFE_DIR_PATH . 'classes/analytics/views/analytics-dashboard.php';
	}

	private static function render_upgrade_page() {
		include MPFE_DIR_PATH . 'classes/analytics/views/analytics-upgrade.php';
	}
}
