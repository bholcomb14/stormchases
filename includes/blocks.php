<?php
if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Gutenberg block registration — server-rendered equivalents of
// [sc_tornado_map], [sc_reports], and [scstats] (its tornado-list and
// chase-stats modes), sharing the exact same render functions as the
// shortcodes (functions.php: sc_render_tornado_map(), sc_render_tornado_list(),
// sc_render_chase_stats(), sc_render_spotter_reports()). Shortcodes are
// unaffected and stay registered as-is.
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

    foreach (['tornado-map', 'tornado-list', 'chase-stats', 'spotter-reports'] as $block) {
        wp_register_script(
            "stormchases-{$block}-editor",
            STORM_CHASES_URL . "blocks/{$block}/index.js",
            ['stormchases-block-editor-common'],
            STORM_CHASES_VERSION,
            true
        );
        register_block_type(STORM_CHASES_DIR . "blocks/{$block}");
    }
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
