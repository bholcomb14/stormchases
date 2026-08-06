<?php
if (!defined('ABSPATH')) {
    exit;
}

$year = isset($attributes['year']) ? sanitize_text_field($attributes['year']) : '';
if ($year && !preg_match('/^\d{4}$/', $year)) {
    echo '<p>' . esc_html__('Invalid year format.', 'stormchases') . '</p>';
    return;
}

$types = [];
if (isset($attributes['types']) && is_array($attributes['types'])) {
    $types = array_map('sanitize_text_field', $attributes['types']);
}

echo sc_render_spotter_reports([
    'year'         => $year,
    'heading'      => isset($attributes['heading']) ? sanitize_text_field($attributes['heading']) : '',
    'show_heading' => $attributes['showHeading'] ?? true,
    'height'       => isset($attributes['height']) ? (int) $attributes['height'] : 500,
    'types'        => $types,
]);
