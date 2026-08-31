<?php
if (!defined('ABSPATH')) {
    exit;
}

$year = isset($attributes['year']) ? sanitize_text_field($attributes['year']) : '';
if ($year && !preg_match('/^\d{4}$/', $year)) {
    echo '<p>' . esc_html__('Invalid year format.', 'stormchases') . '</p>';
    return;
}

echo sc_render_chase_stats([
    'year'               => $year,
    'heading'            => isset($attributes['heading']) ? sanitize_text_field($attributes['heading']) : '',
    'show_heading'       => $attributes['showHeading'] ?? true,
    'show_overall'       => $attributes['showOverall'] ?? true,
    'show_convective'    => $attributes['showConvective'] ?? true,
    'show_hurricane'     => $attributes['showHurricane'] ?? true,
    'show_winter'        => $attributes['showWinter'] ?? true,
    'show_longest_chase' => $attributes['showLongestChase'] ?? true,
    'show_ef_breakdown'  => $attributes['showEfBreakdown'] ?? true,
    'show_storm_modes'   => $attributes['showStormModes'] ?? true,
    'show_top_days'      => $attributes['showTopDays'] ?? false,
    'top_days_count'     => isset($attributes['topDaysCount']) ? (int) $attributes['topDaysCount'] : 10,
    'show_first_last'    => $attributes['showFirstLast'] ?? true,
    'show_new_states'    => $attributes['showNewStates'] ?? true,
    'show_streak'        => $attributes['showStreak'] ?? true,
    'show_top_people'    => $attributes['showTopPeople'] ?? false,
    'top_people_count'   => isset($attributes['topPeopleCount']) ? (int) $attributes['topPeopleCount'] : 10,
]);
