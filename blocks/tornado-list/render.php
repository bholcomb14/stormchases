<?php
if (!defined('ABSPATH')) {
    exit;
}

$year = isset($attributes['year']) ? sanitize_text_field($attributes['year']) : '';
if ($year && !preg_match('/^\d{4}$/', $year)) {
    echo '<p>' . esc_html__('Invalid year format.', 'stormchases') . '</p>';
    return;
}

echo sc_render_tornado_list([
    'year'         => $year,
    'heading'      => isset($attributes['heading']) ? sanitize_text_field($attributes['heading']) : '',
    'show_heading' => $attributes['showHeading'] ?? true,
]);
