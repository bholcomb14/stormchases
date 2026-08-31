<?php
if (!defined('ABSPATH')) {
    exit;
}

$url = isset($attributes['url']) ? esc_url($attributes['url']) : '';
if (!$url) {
    echo '<p>' . esc_html__('No embed URL configured.', 'stormchases') . '</p>';
    return;
}

$height = isset($attributes['height']) ? max(200, min(2000, (int) $attributes['height'])) : 600;
?>
<iframe src="<?php echo esc_url($url); ?>" width="100%" height="<?php echo esc_attr($height); ?>" scrolling="no" style="border:none;" title="<?php echo esc_attr__('Current Location', 'stormchases'); ?>"></iframe>
