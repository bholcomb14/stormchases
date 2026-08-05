<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    wp_die('Access denied.');
}

class Storm_Chases_Settings {
    private $settings = [
        'chase_partners_enable' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'chasers_encountered_enable' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'miles_logged_enable' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'states_chased_enable' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'tornadoes_enable' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'hail_enable' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'wind_enable' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'milestones_enable' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'google_maps_enable' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'google_maps_api_key' => [
            'type' => 'string',
            'default' => '',
        ],
        'spotter_reports_enable' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'best_chase_enable' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'windshields_enable' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'map_provider' => [
            'type' => 'string',
            'default' => 'openstreetmap',
        ],
        'storm_chases_max_file_size' => [
            'type' => 'integer',
            'default' => 10 * 1024 * 1024, // 10 MB
        ],
        'storm_chases_supported_file_types' => [
            'type' => 'array',
            'default' => ['image/jpeg', 'image/png', 'image/gif', 'application/vnd.google-earth.kml+xml'],
        ],
    ];

    public function __construct() {
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_notices', [$this, 'display_notices']);
        add_action('wp_ajax_storm_chases_add_windshield', [$this, 'ajax_add_windshield']);
        add_action('wp_ajax_storm_chases_delete_windshield', [$this, 'ajax_delete_windshield']);
        add_action('wp_ajax_storm_chases_add_privacy_zone', [$this, 'ajax_add_privacy_zone']);
        add_action('wp_ajax_storm_chases_delete_privacy_zone', [$this, 'ajax_delete_privacy_zone']);
        Storm_Chases::debug_log('Storm_Chases_Settings initialized', 'info');
    }

    public function admin_init(): void {
        foreach ($this->settings as $setting => $config) {
            if ($setting === 'google_maps_api_key') {
                $sanitize_callback = [$this, 'sanitize_api_key'];
            } elseif ($setting === 'storm_chases_supported_file_types') {
                $sanitize_callback = [$this, 'sanitize_file_types'];
            } elseif ($setting === 'map_provider') {
                $sanitize_callback = [$this, 'sanitize_map_provider'];
            } else {
                $sanitize_callback = 'intval';
            }
            register_setting(
                'storm_chases_group',
                $setting,
                [
                    'type' => $config['type'],
                    'sanitize_callback' => $sanitize_callback,
                    'default' => $config['default'],
                ]
            );
        }
        // Validate Google Maps API key on settings save
        add_action('update_option_google_maps_api_key', [$this, 'validate_google_maps_api_key'], 10, 2);
        Storm_Chases::debug_log('Settings registered for storm_chases_group', 'info');
    }

    public function sanitize_api_key($value): string {
        $value = sanitize_text_field($value);
        if (strlen($value) > 0 && !preg_match('/^[A-Za-z0-9\-_]{16,128}$/', $value)) {
            add_settings_error(
                'google_maps_api_key',
                'invalid_api_key',
                __('Google Maps API key appears invalid. Provide a valid key (alphanumeric, -, _), or leave blank to disable maps.', 'stormchases'),
                'error'
            );
            Storm_Chases::debug_log('Invalid Google Maps API key provided: ' . $value, 'warning');
            return '';
        }
        return $value;
    }

    public function validate_google_maps_api_key($old_value, $new_value) {
        if ($new_value && get_option('google_maps_enable')) {
            // Perform a test request to Google Maps API to validate key
            $response = wp_remote_get('https://maps.googleapis.com/maps/api/js?key=' . $new_value);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                add_settings_error(
                    'google_maps_api_key',
                    'invalid_api_key_response',
                    __('Google Maps API key appears invalid or is not enabled for the Maps JavaScript API. Please check your Google Cloud Console and ensure the key is valid.', 'stormchases'),
                    'error'
                );
                Storm_Chases::debug_log('Google Maps API key validation failed: ' . ($response instanceof WP_Error ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response)), 'error');
            } else {
                Storm_Chases::debug_log('Google Maps API key validated successfully.', 'info');
            }
        }
    }

    public function sanitize_map_provider($value): string {
        return in_array($value, ['openstreetmap', 'google'], true) ? $value : 'openstreetmap';
    }

    public function sanitize_file_types($value): array {
        if (!is_array($value)) {
            $value = array_map('sanitize_text_field', explode(',', $value));
        }
        $value = array_filter(array_map('trim', $value));
        // wp_get_mime_types() returns extension => mime_type; we compare against the MIME type values
        $valid_mimes = array_values(wp_get_mime_types());
        $value = array_intersect($value, $valid_mimes);
        if (empty($value)) {
            add_settings_error(
                'storm_chases_supported_file_types',
                'invalid_file_types',
                __('Invalid file types provided.', 'stormchases'),
                'error'
            );
            return $this->settings['storm_chases_supported_file_types']['default'];
        }
        return $value;
    }

    public function add_menu(): void {
        add_submenu_page(
            'edit.php?post_type=storm_chases',
            __('Storm Chases Settings', 'stormchases'),
            __('Settings', 'stormchases'),
            'manage_options',
            'storm_chases',
            [$this, 'render_settings_page'] 
        );
        Storm_Chases::debug_log('Added settings page at edit.php?post_type=storm_chases&page=storm_chases', 'info');
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'stormchases'), 403);
        }
        include STORM_CHASES_DIR . 'templates/admin/settings.php';
    }

    public function display_notices(): void {
        settings_errors('google_maps_api_key');
        settings_errors('storm_chases_supported_file_types');
    }

    public function ajax_add_windshield(): void {
        check_ajax_referer('storm_chases_windshield_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'stormchases')], 403);
        }
        $month = absint($_POST['month'] ?? 0);
        $year  = absint($_POST['year'] ?? 0);
        if ($month < 1 || $month > 12 || $year < 1990 || $year > 2100) {
            wp_send_json_error(['message' => __('Invalid month or year.', 'stormchases')]);
        }
        $windshields   = get_option('storm_chases_windshields', []);
        $windshields[] = ['month' => $month, 'year' => $year];
        update_option('storm_chases_windshields', $windshields);
        wp_send_json_success([
            'index' => count($windshields) - 1,
            'month' => $month,
            'year'  => $year,
        ]);
    }

    public function ajax_delete_windshield(): void {
        check_ajax_referer('storm_chases_windshield_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'stormchases')], 403);
        }
        $index       = absint($_POST['index'] ?? -1);
        $windshields = get_option('storm_chases_windshields', []);
        if (!isset($windshields[$index])) {
            wp_send_json_error(['message' => __('Entry not found.', 'stormchases')]);
        }
        array_splice($windshields, $index, 1);
        update_option('storm_chases_windshields', $windshields);
        wp_send_json_success(['message' => __('Deleted.', 'stormchases')]);
    }

    public function ajax_add_privacy_zone(): void {
        check_ajax_referer('storm_chases_privacy_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'stormchases')], 403);
        }
        $lat    = floatval($_POST['lat']    ?? 0);
        $lon    = floatval($_POST['lon']    ?? 0);
        $radius = floatval($_POST['radius'] ?? 5.0);
        $label  = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));

        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            wp_send_json_error(['message' => __('Invalid latitude or longitude.', 'stormchases')]);
        }
        if ($radius < 0.5 || $radius > 50) {
            wp_send_json_error(['message' => __('Radius must be between 0.5 and 50 miles.', 'stormchases')]);
        }
        $zones   = get_option('storm_chases_privacy_zones', []);
        $zones[] = ['label' => $label, 'lat' => round($lat, 6), 'lon' => round($lon, 6), 'radius' => round($radius, 1)];
        update_option('storm_chases_privacy_zones', $zones);
        wp_send_json_success([
            'index'  => count($zones) - 1,
            'label'  => $label,
            'lat'    => round($lat, 6),
            'lon'    => round($lon, 6),
            'radius' => round($radius, 1),
        ]);
    }

    public function ajax_delete_privacy_zone(): void {
        check_ajax_referer('storm_chases_privacy_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'stormchases')], 403);
        }
        $index = absint($_POST['index'] ?? -1);
        $zones = get_option('storm_chases_privacy_zones', []);
        if (!isset($zones[$index])) {
            wp_send_json_error(['message' => __('Zone not found.', 'stormchases')]);
        }
        array_splice($zones, $index, 1);
        update_option('storm_chases_privacy_zones', $zones);
        wp_send_json_success(['message' => __('Deleted.', 'stormchases')]);
    }
}
