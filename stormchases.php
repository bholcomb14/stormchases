<?php
/*
Plugin Name: StormChases
Plugin URI:  https://benholcomb.com/tech/storm-chases-wordpress-plugin/
Description: Plugin to allow a storm chaser to create chase logs.
Version:     2.0.0
Author:      Ben Holcomb
Author URI:  https://www.benholcomb.com
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: stormchases
*/

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('STORM_CHASES_DIR', plugin_dir_path(__FILE__));
define('STORM_CHASES_URL', plugin_dir_url(__FILE__));
define('STORM_CHASES_VERSION', '2.0.0');

if (!class_exists('Storm_Chases')) {
    class Storm_Chases {
        public function __construct() {
            require_once STORM_CHASES_DIR . 'includes/data.php';
            require_once STORM_CHASES_DIR . 'includes/functions.php';
            require_once STORM_CHASES_DIR . 'includes/settings.php';
            require_once STORM_CHASES_DIR . 'includes/post-types/storm_chase.php';
            require_once STORM_CHASES_DIR . 'includes/taxonomies.php';
            require_once STORM_CHASES_DIR . 'includes/blocks.php';
            require_once STORM_CHASES_DIR . 'includes/wizard.php';

            new Storm_Chases_Settings();
            new StormChaseTemplate();
            new Storm_Chases_People_Taxonomies();
            new Storm_Chases_Wizard();

            $plugin = plugin_basename(__FILE__);
            add_filter("plugin_action_links_$plugin", [$this, 'plugin_settings_link']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
            add_action('wp_ajax_storm_chases_get_tornado_entry', [$this, 'get_tornado_entry']);
            add_action('wp_ajax_storm_chases_get_landfall_entry', [$this, 'get_landfall_entry']);
            add_action('wp_ajax_storm_chases_get_snowfall_entry', [$this, 'get_snowfall_entry']);
        }

        public function enqueue_admin_scripts($hook) {
            $post_edit_hooks = ['post.php', 'post-new.php'];

            // The Tornado Map / Spotter Reports blocks' editor-preview assets (Leaflet +
            // frontend.js) are handled separately, via sc_enqueue_editor_preview_assets()
            // in functions.php (hooked on enqueue_block_assets — the one hook WP mirrors
            // into the block editor's iframed canvas, unlike admin_enqueue_scripts here).

            $allowed_hooks = array_merge($post_edit_hooks, ['storm_chase_page_storm-chases-settings']);
            if (!in_array($hook, $allowed_hooks) || (in_array($hook, $post_edit_hooks) && get_post_type() !== 'storm_chase')) {
                return;
            }

            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_script('jquery-ui-sortable');
            // Self-hosted (as of 2.0.0) from assets/vendor/jquery-ui/ instead of the
            // ajax.googleapis.com CDN — see assets/vendor/jquery-ui/LICENSE.
            wp_enqueue_style('jquery-ui', STORM_CHASES_URL . 'assets/vendor/jquery-ui/jquery-ui.css', [], '1.12.1');

            $admin_deps = ['jquery', 'jquery-ui-datepicker', 'jquery-ui-sortable', 'wp-mediaelement', 'wp-data'];

            // Leaflet powers the "pick location on map" tornado lat/lon picker — only
            // needed on the post edit screen, not the settings page.
            if (in_array($hook, ['post.php', 'post-new.php'])) {
                sc_enqueue_leaflet();
                $admin_deps[] = 'leaflet';
            }

            wp_enqueue_script(
                'storm-chases-admin',
                STORM_CHASES_URL . 'assets/js/admin.js',
                $admin_deps,
                STORM_CHASES_VERSION,
                true
            );

            $localize_data = [
                'nonce' => wp_create_nonce('wp_rest'),
                'restUrl' => esc_url_raw(rest_url()),
                'ajaxUrl' => esc_url_raw(admin_url('admin-ajax.php')),
                'maxFileSize' => absint(get_option('storm_chases_max_file_size', 10 * 1024 * 1024)),
                'supportedFileTypes' => array_map('esc_attr', get_option('storm_chases_supported_file_types', ['image/jpeg', 'image/png', 'image/gif', 'application/vnd.google-earth.kml+xml'])),
                'windshieldNonce' => wp_create_nonce('storm_chases_windshield_nonce'),
                'privacyNonce'    => wp_create_nonce('storm_chases_privacy_nonce'),
            ];

            if (in_array($hook, ['post.php', 'post-new.php'])) {
                $data_handler = new StormChasesData();
                $chase_data = $data_handler->get_chase_data(get_the_ID());
                $localize_data['postId'] = absint(get_the_ID());
                $localize_data['tornadoCount'] = count($chase_data['tornadoes'] ?? []);
            }

            wp_localize_script(
                'storm-chases-admin',
                'stormChasesSettings',
                $localize_data
            );

            wp_enqueue_style(
                'storm-chases-admin',
                STORM_CHASES_URL . 'assets/css/admin.css',
                [],
                STORM_CHASES_VERSION
            );
        }

        public function plugin_settings_link($links) {
            $settings_link = '<a href="edit.php?post_type=storm_chase&page=storm-chases-settings">' . esc_html__('Settings', 'stormchases') . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        }

        public function get_tornado_entry() {
            check_ajax_referer('wp_rest', 'nonce');
            $index = isset($_POST['index']) ? absint($_POST['index']) : 0;

            // Shared with the saved-entries loop in templates/stormchases.php — see
            // sc_render_tornado_entry_html() in includes/functions.php. A blank $tornado
            // array makes every field fall back to its default; expanded=true since a
            // freshly-added entry starts expanded (saved entries load collapsed instead).
            wp_send_json_success(['entry' => sc_render_tornado_entry_html($index, [], true)]);
        }

        public function get_landfall_entry() {
            check_ajax_referer('wp_rest', 'nonce');
            $index = isset($_POST['index']) ? absint($_POST['index']) : 0;

            // See the matching comment in get_tornado_entry() above.
            wp_send_json_success(['entry' => sc_render_landfall_entry_html($index, [], true)]);
        }

        public function get_snowfall_entry() {
            check_ajax_referer('wp_rest', 'nonce');
            $index = isset($_POST['index']) ? absint($_POST['index']) : 0;

            // See the matching comment in get_tornado_entry() above.
            wp_send_json_success(['entry' => sc_render_snowfall_entry_html($index, [], true)]);
        }

        public static function activate() {
            require_once STORM_CHASES_DIR . 'includes/post-types/storm_chase.php';
            $storm_chase = new StormChaseTemplate();
            $storm_chase->create_post_type();
            flush_rewrite_rules();
        }

        public static function deactivate() {
            flush_rewrite_rules();
        }

        public static function debug_log($message, $level = 'info') {
            if (!defined('WP_DEBUG') || !WP_DEBUG || !defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
                return;
            }
            $levels = ['info', 'warning', 'error'];
            $level = in_array($level, $levels) ? $level : 'info';
            $log_message = sprintf('[StormChases] [%s] %s', strtoupper($level), is_string($message) ? $message : print_r($message, true));
            error_log($log_message);
        }
    }
}

if (class_exists('Storm_Chases')) {
    register_activation_hook(__FILE__, ['Storm_Chases', 'activate']);
    register_deactivation_hook(__FILE__, ['Storm_Chases', 'deactivate']);
    $storm_chases = new Storm_Chases();
}
