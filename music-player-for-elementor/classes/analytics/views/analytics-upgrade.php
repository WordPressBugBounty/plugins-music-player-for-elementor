<?php

if (!defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap mpfe-analytics-wrap mpfe-analytics-upgrade">
	<div class="mpfe-analytics-upgrade-content">
		<img class="mpfe-analytics-upgrade-icon" src="<?php echo esc_url(MPFE_DIR_URL . 'img/icon-256x256.png'); ?>" alt="<?php echo esc_attr__('Music Player for Elementor', 'music-player-for-elementor'); ?>">
		<h1><?php echo esc_html__('Audio Analytics for Music Player for Elementor', 'music-player-for-elementor'); ?></h1>
		<p><?php echo esc_html__('Upgrade to Pro to see plays, listen time, completed plays, top tracks, and top pages directly inside WordPress Admin.', 'music-player-for-elementor'); ?></p>
		<a class="mpfe_adm_btn" href="<?php echo esc_url(admin_url('admin.php?page=mpfe-dashboard-pricing')); ?>"><?php echo esc_html__('Upgrade to Pro', 'music-player-for-elementor'); ?></a>
	</div>
</div>
