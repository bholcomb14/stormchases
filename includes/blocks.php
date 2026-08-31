<?php
if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Gutenberg block registration — Tornado Map, Spotter Reports, Tornado List,
// Chase Stats, and Chase Archive, each backed by a shared render function in
// functions.php (sc_render_tornado_map(), sc_render_tornado_list(),
// sc_render_chase_stats(), sc_render_spotter_reports(), sc_render_chase_archive()).
// As of 2.0.0 these blocks are the only public-facing interface — the
// equivalent shortcodes ([sc_tornado_map], [sc_reports], [scstats],
// [scarchive]) were removed once every one of them had a block equivalent;
// the render functions themselves are unchanged and still do all the work.
// current-location is block-only, with no shortcode equivalent — see CLAUDE.md.
//
// No build step in this plugin (see CLAUDE.md), so block editor scripts are
// plain wp.element.createElement calls (no JSX) with dependencies declared
// by hand via wp_register_script() rather than an auto-generated
// index.asset.php. Each block's block.json references its editorScript by
// the handle registered here, not a file: path, so WP doesn't try to
// re-register it from scratch.
// ---------------------------------------------------------------------------

add_action('init', 'sc_register_blocks');

function sc_register_blocks() {
    wp_register_script(
        'stormchases-block-editor-common',
        STORM_CHASES_URL . 'blocks/shared/editor-common.js',
        ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'],
        STORM_CHASES_VERSION,
        true
    );

    foreach (['tornado-map', 'tornado-list', 'chase-stats', 'spotter-reports', 'chase-archive', 'current-location'] as $block) {
        wp_register_script(
            "stormchases-{$block}-editor",
            STORM_CHASES_URL . "blocks/{$block}/index.js",
            ['stormchases-block-editor-common'],
            STORM_CHASES_VERSION,
            true
        );
        register_block_type(STORM_CHASES_DIR . "blocks/{$block}");
    }

    // Chase Archive's year dropdown needs the list of years that actually have chases —
    // computed server-side (sc_get_chase_years(), includes/functions.php) and handed to the
    // editor script rather than queried over REST, since it's small and rarely changes.
    wp_localize_script('stormchases-chase-archive-editor', 'stormChasesArchiveYears', sc_get_chase_years());

    // Chase Stats Block Defaults (Settings page) — the attribute defaults a brand new Chase
    // Stats block starts with. Doesn't touch a block already placed on a page (its attributes
    // are already saved in the post content); applied client-side via registerBlockVariation()
    // in blocks/chase-stats/index.js, since attribute defaults are a block-registration
    // concept, not something render.php (which only ever sees a block's already-resolved
    // attributes) has any say over.
    //
    // wp_add_inline_script() + wp_json_encode() here, not wp_localize_script() — the latter
    // stringifies every value ("1"/""), which for a type:"boolean" attribute default doesn't
    // mean what an actual false does; this keeps real booleans/numbers.
    wp_add_inline_script(
        'stormchases-chase-stats-editor',
        'var stormChasesStatsDefaults = ' . wp_json_encode([
            'showHeading' => (bool) get_option('stats_default_show_heading', true),
            'showOverall' => (bool) get_option('stats_default_show_overall', true),
            'showConvective' => (bool) get_option('stats_default_show_convective', true),
            'showHurricane' => (bool) get_option('stats_default_show_hurricane', false),
            'showWinter' => (bool) get_option('stats_default_show_winter', false),
            'showLongestChase' => (bool) get_option('stats_default_show_longest_chase', true),
            'showEfBreakdown' => (bool) get_option('stats_default_show_ef_breakdown', true),
            'showStormModes' => (bool) get_option('stats_default_show_storm_modes', true),
            'showTopDays' => (bool) get_option('stats_default_show_top_days', false),
            'topDaysCount' => absint(get_option('stats_default_top_days_count', 10)),
            'showFirstLast' => (bool) get_option('stats_default_show_first_last', true),
            'showNewStates' => (bool) get_option('stats_default_show_new_states', false),
            'showStreak' => (bool) get_option('stats_default_show_streak', false),
            'showTopPeople' => (bool) get_option('stats_default_show_top_people', false),
            'topPeopleCount' => absint(get_option('stats_default_top_people_count', 10)),
        ]) . ';',
        'before'
    );
}

add_filter('block_categories_all', 'sc_register_block_category', 10, 2);

function sc_register_block_category($categories, $editor_context) {
    return array_merge(
        [
            [
                'slug'  => 'stormchases',
                'title' => __('Storm Chases', 'stormchases'),
                'icon'  => null,
            ],
        ],
        $categories
    );
}
