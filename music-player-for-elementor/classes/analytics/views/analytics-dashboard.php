<?php

if (!defined('ABSPATH')) {
	exit;
}

$plays = isset($stats['plays']) ? absint($stats['plays']) : 0;
$total_listen_seconds = isset($stats['total_listen_seconds']) ? absint($stats['total_listen_seconds']) : 0;
$completed_plays = isset($stats['completed_plays']) ? absint($stats['completed_plays']) : 0;
$avg_listen_seconds = isset($stats['avg_listen_seconds']) ? absint($stats['avg_listen_seconds']) : 0;
$tracks = isset($top_tracks) && is_array($top_tracks) ? $top_tracks : array();
$pages = isset($top_pages) && is_array($top_pages) ? $top_pages : array();
$chart_rows = isset($chart_data) && is_array($chart_data) ? $chart_data : array();
$selected_period = MPFE_Analytics_Reports::normalize_period(isset($period) ? $period : '30_days');
$periods = array(
	'today'     => __('Today', 'music-player-for-elementor'),
	'7_days'    => __('Last 7 Days', 'music-player-for-elementor'),
	'30_days'   => __('Last 30 Days', 'music-player-for-elementor'),
	'12_months' => __('Last 12 Months', 'music-player-for-elementor'),
);
?>
<div class="wrap mpfe-analytics-wrap">
	<h1><?php echo esc_html__('Analytics', 'music-player-for-elementor'); ?></h1>

	<div class="mpfe-analytics-cards">
		<div class="mpfe-analytics-card">
			<span><?php echo esc_html__('Total Plays', 'music-player-for-elementor'); ?></span>
			<strong><?php echo esc_html(number_format_i18n($plays)); ?></strong>
		</div>
		<div class="mpfe-analytics-card">
			<span><?php echo esc_html__('Total Listen Time', 'music-player-for-elementor'); ?></span>
			<strong><?php echo esc_html(MPFE_Analytics_Reports::format_seconds($total_listen_seconds)); ?></strong>
		</div>
		<div class="mpfe-analytics-card">
			<span><?php echo esc_html__('Completed Plays', 'music-player-for-elementor'); ?></span>
			<strong><?php echo esc_html(number_format_i18n($completed_plays)); ?></strong>
		</div>
		<div class="mpfe-analytics-card">
			<span><?php echo esc_html__('Average Listen Time', 'music-player-for-elementor'); ?></span>
			<strong><?php echo esc_html(MPFE_Analytics_Reports::format_seconds($avg_listen_seconds)); ?></strong>
		</div>
	</div>

	<section class="mpfe-analytics-chart">
		<div class="mpfe-analytics-chart-header">
			<h2><?php echo esc_html__('Number of Plays', 'music-player-for-elementor'); ?></h2>
			<nav class="mpfe-analytics-periods" aria-label="<?php echo esc_attr__('Chart period', 'music-player-for-elementor'); ?>">
				<?php foreach ($periods as $period_key => $period_label) : ?>
					<?php $period_url = add_query_arg(array('page' => 'mpfe-analytics', 'period' => $period_key), admin_url('admin.php')); ?>
					<a href="<?php echo esc_url($period_url); ?>"<?php echo $selected_period === $period_key ? ' aria-current="page"' : ''; ?>><?php echo esc_html($period_label); ?></a>
				<?php endforeach; ?>
			</nav>
		</div>
		<div class="mpfe-analytics-chart-container">
			<canvas id="mpfe-analytics-plays-chart" role="img" aria-label="<?php echo esc_attr__('Number of Plays chart', 'music-player-for-elementor'); ?>"><?php echo esc_html__('Your browser does not support the analytics chart.', 'music-player-for-elementor'); ?></canvas>
			<table class="screen-reader-text">
				<caption><?php echo esc_html__('Number of Plays chart data', 'music-player-for-elementor'); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__('Date', 'music-player-for-elementor'); ?></th>
						<th scope="col"><?php echo esc_html__('Plays', 'music-player-for-elementor'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($chart_rows)) : ?>
						<tr>
							<td colspan="2"><?php echo esc_html__('No chart data available.', 'music-player-for-elementor'); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ($chart_rows as $chart_row) : ?>
							<tr>
								<td><?php echo esc_html(isset($chart_row['label']) ? $chart_row['label'] : ''); ?></td>
								<td><?php echo esc_html(number_format_i18n(isset($chart_row['plays']) ? absint($chart_row['plays']) : 0)); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</section>

	<div class="mpfe-analytics-tables">
	<section class="mpfe-analytics-table">
		<h2><?php echo esc_html__('Top Tracks', 'music-player-for-elementor'); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__('File Name', 'music-player-for-elementor'); ?></th>
					<th scope="col"><?php echo esc_html__('Plays', 'music-player-for-elementor'); ?></th>
					<th scope="col"><?php echo esc_html__('Listen Time', 'music-player-for-elementor'); ?></th>
					<th scope="col"><?php echo esc_html__('Completion Rate', 'music-player-for-elementor'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($tracks as $track) : ?>
					<?php
					$track_plays = isset($track['plays']) ? absint($track['plays']) : 0;
					$track_completed_plays = isset($track['completed_plays']) ? absint($track['completed_plays']) : 0;
					$completion_rate = $track_plays > 0 ? round(($track_completed_plays / $track_plays) * 100) : 0;
					?>
					<tr>
						<td><?php echo esc_html(isset($track['file_name']) ? $track['file_name'] : ''); ?></td>
						<td><?php echo esc_html(number_format_i18n($track_plays)); ?></td>
						<td><?php echo esc_html(MPFE_Analytics_Reports::format_seconds(isset($track['total_listen_seconds']) ? $track['total_listen_seconds'] : 0)); ?></td>
						<td><?php echo esc_html(number_format_i18n($completion_rate) . '%'); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<section class="mpfe-analytics-table">
		<h2><?php echo esc_html__('Top Pages', 'music-player-for-elementor'); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__('Page', 'music-player-for-elementor'); ?></th>
					<th scope="col"><?php echo esc_html__('Plays', 'music-player-for-elementor'); ?></th>
					<th scope="col"><?php echo esc_html__('Listen Time', 'music-player-for-elementor'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($pages as $page) : ?>
					<?php
					$page_url = isset($page['page_url']) ? $page['page_url'] : '';
					$escaped_page_url = esc_url($page_url);
					$page_label = !empty($page['page_title']) ? $page['page_title'] : $page_url;
					?>
					<tr>
						<td>
							<?php if (!empty($escaped_page_url)) : ?>
								<a href="<?php echo esc_url($escaped_page_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($page_label); ?> <span class="screen-reader-text"><?php echo esc_html__('(opens in a new tab)', 'music-player-for-elementor'); ?></span></a>
							<?php else : ?>
								<?php echo esc_html($page_label); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html(number_format_i18n(isset($page['plays']) ? absint($page['plays']) : 0)); ?></td>
						<td><?php echo esc_html(MPFE_Analytics_Reports::format_seconds(isset($page['total_listen_seconds']) ? $page['total_listen_seconds'] : 0)); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	</div>
</div>
