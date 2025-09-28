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
            'default' => 0,
        ],
        'states_chased_enable' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'tornadoes_enable' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'hail_enable' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'wind_enable' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'milestones_enable' => [
            'type' => 'boolean',
            'default' => 0,
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
            'default' => 0,
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
        Storm_Chases::debug_log('Storm_Chases_Settings initialized', 'info');
    }

    public function admin_init(): void {
        foreach ($this->settings as $setting => $config) {
            $sanitize_callback = $setting === 'google_maps_api_key' ? [$this, 'sanitize_api_key'] :
                                ($setting === 'storm_chases_supported_file_types' ? [$this, 'sanitize_file_types'] : 'intval');
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
        if (strlen($value) > 0 && !preg_match('/^[A-Za-z0-9\-_]{39}$/', $value)) {
            add_settings_error(
                'google_maps_api_key',
                'invalid_api_key',
                __('Google Maps API key must be 39 characters long and contain only alphanumeric characters, hyphens, and underscores. Maps will not work until a valid key is provided.', 'stormchases'),
                'error'
            );
            Storm_Chases::debug_log('Invalid Google Maps API key provided: ' . $value, 'error');
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

    public function sanitize_file_types($value): array {
        if (!is_array($value)) {
            $value = array_map('sanitize_text_field', explode(',', $value));
        }
        $value = array_filter(array_map('trim', $value));
        $valid_mimes = array_keys(wp_get_mime_types());
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
}