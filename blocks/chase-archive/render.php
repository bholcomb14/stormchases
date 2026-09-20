<?php
if (!defined('ABSPATH')) {
    exit;
}

$year = isset($attributes['year']) ? sanitize_text_field($attributes['year']) : '';
if ($year && !preg_match('/^\d{4}$/', $year)) {
    echo '<p>' . esc_html__('Invalid year format.', 'stormchases') . '</p>';
    return;
}

// A year past the current one can never have chases yet — say so plainly instead of just
// showing an empty "No storm chases found." list, per the block's whole point of giving a
// clear embed no matter what year gets picked (including one still in the future).
if ($year && (int) $year > (int) current_time('Y')) {
    printf(
        '<p>%s</p>',
        esc_html(sprintf(
            /* translators: %s: a future year */
            __('%s hasn\'t happened yet — check back once chases from that year are logged.', 'stormchases'),
            $year
        ))
    );
    return;
}

echo sc_render_chase_archive([
    'year'         => $year,
    'show'         => isset($attributes['show']) ? (int) $attributes['show'] : 2000,
    'heading'      => isset($attributes['heading']) ? sanitize_text_field($attributes['heading']) : '',
    'show_heading' => $attributes['showHeading'] ?? true,
    'show_tornado_icon' => $attributes['showTornadoIcon'] ?? false,
    'show_hail_icon'    => $attributes['showHailIcon'] ?? false,
    'show_wind_icon'    => $attributes['showWindIcon'] ?? false,
    'show_reports_icon' => $attributes['showReportsIcon'] ?? false,
]);
