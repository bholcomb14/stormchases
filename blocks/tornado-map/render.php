<?php
if (!defined('ABSPATH')) {
    exit;
}

$year = isset($attributes['year']) ? sanitize_text_field($attributes['year']) : '';
if ($year && !preg_match('/^\d{4}$/', $year)) {
    echo '<p>' . esc_html__('Invalid year format.', 'stormchases') . '</p>';
    return;
}

$ratings = [];
if (isset($attributes['ratings']) && is_array($attributes['ratings'])) {
    $ratings = array_map('sanitize_text_field', $attributes['ratings']);
}

echo sc_render_tornado_map([
    'year'               => $year,
    'heading'            => isset($attributes['heading']) ? sanitize_text_field($attributes['heading']) : '',
    'show_heading'       => $attributes['showHeading'] ?? true,
    'height'             => isset($attributes['height']) ? (int) $attributes['height'] : 500,
    'ratings'            => $ratings,
    'show_storms'        => $attributes['showStorms'] ?? true,
    'show_hurricanes'    => $attributes['showHurricanes'] ?? true,
    'show_snowfall'      => $attributes['showSnowfall'] ?? true,
    'show_legend'        => $attributes['showLegend'] ?? true,
    'show_layer_toggles' => $attributes['showLayerToggles'] ?? false,
    'zoom'               => isset($attributes['zoom']) ? (float) $attributes['zoom'] : 0,
    'center_lat'         => isset($attributes['centerLat']) ? (float) $attributes['centerLat'] : 39.8283,
    'center_lon'         => isset($attributes['centerLon']) ? (float) $attributes['centerLon'] : -98.5795,
]);
