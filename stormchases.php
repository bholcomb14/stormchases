<?php
/*
Plugin Name: StormChases
Description: Plugin to allow a storm chaser to create chase logs.
Version:     1.7.0
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
define('STORM_CHASES_VERSION', '1.7.0');

if (!class_exists('Storm_Chases')) {
    class Storm_Chases {
        public function __construct() {
            require_once STORM_CHASES_DIR . 'includes/data.php';
            require_once STORM_CHASES_DIR . 'includes/functions.php';
            require_once STORM_CHASES_DIR . 'includes/settings.php';
            require_once STORM_CHASES_DIR . 'includes/post-types/storm_chase.php';

            new Storm_Chases_Settings();
            new StormChaseTemplate();

            $plugin = plugin_basename(__FILE__);
            add_filter("plugin_action_links_$plugin", [$this, 'plugin_settings_link']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
            add_action('wp_ajax_storm_chases_get_tornado_entry', [$this, 'get_tornado_entry']);
        }

        public function enqueue_admin_scripts($hook) {
            $allowed_hooks = ['post.php', 'post-new.php', 'storm_chase_page_storm-chases-settings'];
            if (!in_array($hook, $allowed_hooks) || (in_array($hook, ['post.php', 'post-new.php']) && get_post_type() !== 'storm_chase')) {
                return;
            }

            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui', '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/base/jquery-ui.css', [], '1.12.1');

            wp_enqueue_script(
                'storm-chases-admin',
                STORM_CHASES_URL . 'assets/js/admin.js',
                ['jquery', 'jquery-ui-datepicker', 'wp-mediaelement'],
                STORM_CHASES_VERSION,
                true
            );

            $localize_data = [
                'nonce' => wp_create_nonce('wp_rest'),
                'restUrl' => esc_url_raw(rest_url()),
                'ajaxUrl' => esc_url_raw(admin_url('admin-ajax.php')),
                'maxFileSize' => absint(get_option('storm_chases_max_file_size', 10 * 1024 * 1024)),
                'supportedFileTypes' => array_map('esc_attr', get_option('storm_chases_supported_file_types', ['image/jpeg', 'image/png', 'image/gif', 'application/vnd.google-earth.kml+xml'])),
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

            ob_start();
            ?>
            <div class="tornado-entry">
                <label><?php esc_html_e('Name', 'stormchases'); ?> <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][name]"></label>
                <label><?php esc_html_e('Latitude', 'stormchases'); ?> <input type="number" name="tornadoes[<?php echo esc_attr($index); ?>][lat]" step="0.0001"></label>
                <label><?php esc_html_e('Longitude', 'stormchases'); ?> <input type="number" name="tornadoes[<?php echo esc_attr($index); ?>][lon]" step="0.0001"></label>
                <label><?php esc_html_e('Media URL', 'stormchases'); ?> <input type="url" name="tornadoes[<?php echo esc_attr($index); ?>][media_url]" pattern="^\/[\w\/-]+\.(mp4|webm|ogg)$"></label>
                <label><input type="checkbox" name="tornadoes[<?php echo esc_attr($index); ?>][photogenic]"> <?php esc_html_e('Photogenic', 'stormchases'); ?></label>
                <label>
                    <?php esc_html_e('Tornado Photo', 'stormchases'); ?>
                    <input type="hidden" name="tornadoes[<?php echo esc_attr($index); ?>][photo_id]" class="tornado-photo-id">
                    <input type="text" class="tornado-photo-url" disabled>
                    <button type="button" class="upload-tornado-photo-button"><?php esc_html_e('Select Tornado Image', 'stormchases'); ?></button>
                </label>
                <label>
                    <?php esc_html_e('EF Rating', 'stormchases'); ?>
                    <select name="tornadoes[<?php echo esc_attr($index); ?>][ef_rating]">
                        <?php
                        $ratings = ['Unrated', 'EF-U', 'EF-0', 'EF-1', 'EF-2', 'EF-3', 'EF-4', 'EF-5'];
                        foreach ($ratings as $rating) {
                            echo '<option value="' . esc_attr($rating) . '">' . esc_html($rating) . '</option>';
                        }
                        ?>
                    </select>
                </label>
                <label>
                    <?php esc_html_e('Start Time (HH:MM)', 'stormchases'); ?>
                    <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][start_time]" pattern="^\d{2}:\d{2}$" required>
                </label>
                <label>
                    <?php esc_html_e('End Time (HH:MM)', 'stormchases'); ?>
                    <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][end_time]" pattern="^\d{2}:\d{2}$">
                </label>
                <label>
                    <?php esc_html_e('End Latitude', 'stormchases'); ?>
                    <input type="number" name="tornadoes[<?php echo esc_attr($index); ?>][end_lat]" step="0.0001">
                </label>
                <label>
                    <?php esc_html_e('End Longitude', 'stormchases'); ?>
                    <input type="number" name="tornadoes[<?php echo esc_attr($index); ?>][end_lon]" step="0.0001">
                </label>
                <button type="button" class="remove-tornado"><?php esc_html_e('Remove', 'stormchases'); ?></button>
            </div>
            <?php
            wp_send_json_success(['entry' => ob_get_clean()]);
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