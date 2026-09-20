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
        'busts_enable' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'kiss_of_death_enable' => [
            'type' => 'boolean',
            'default' => 1,
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

        // Attribute defaults applied to every newly-inserted Chase Stats block (see
        // sc_register_blocks() in blocks.php and blocks/chase-stats/index.js's
        // blocks.registerBlockType filter) — a block already placed on a page keeps
        // whatever attributes it was saved with; this only changes what a brand new block
        // starts with. Mirrors blocks/chase-stats/block.json's attribute list, so a new
        // attribute added there should get a matching entry here too.
        'stats_default_show_heading' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'stats_default_show_overall' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'stats_default_show_convective' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'stats_default_show_hurricane' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'stats_default_show_winter' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'stats_default_show_longest_chase' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'stats_default_show_ef_breakdown' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'stats_default_show_storm_modes' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'stats_default_show_top_days' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'stats_default_top_days_count' => [
            'type' => 'integer',
            'default' => 10,
        ],
        'stats_default_show_first_last' => [
            'type' => 'boolean',
            'default' => 1,
        ],
        'stats_default_show_new_states' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'stats_default_show_streak' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'stats_default_show_top_people' => [
            'type' => 'boolean',
            'default' => 0,
        ],
        'stats_default_top_people_count' => [
            'type' => 'integer',
            'default' => 10,
        ],
    ];

    public function __construct() {
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_notices', [$this, 'display_notices']);
        add_action('wp_ajax_storm_chases_add_windshield', [$this, 'ajax_add_windshield']);
        add_action('wp_ajax_storm_chases_delete_windshield', [$this, 'ajax_delete_windshield']);
        add_action('wp_ajax_storm_chases_add_privacy_zone', [$this, 'ajax_add_privacy_zone']);
        add_action('wp_ajax_storm_chases_delete_privacy_zone', [$this, 'ajax_delete_privacy_zone']);
        add_action('admin_post_storm_chases_scan_legacy_meta', [$this, 'handle_scan_legacy_meta']);
        add_action('admin_post_storm_chases_delete_legacy_meta', [$this, 'handle_delete_legacy_meta']);
        add_action('admin_post_storm_chases_scan_person_migration', [$this, 'handle_scan_person_migration']);
        add_action('admin_post_storm_chases_save_person_aliases', [$this, 'handle_save_person_aliases']);
        add_action('admin_post_storm_chases_apply_person_migration', [$this, 'handle_apply_person_migration']);
        Storm_Chases::debug_log('Storm_Chases_Settings initialized', 'info');
    }

    // Individual postmeta keys a pre-chase_data-blob (or otherwise stray) save could have
    // left behind — every field chase_data actually stores today, except 'chasedate'
    // (that one's an intentional, still-current dual-write — see CLAUDE.md) and the
    // array-typed fields (tornadoes/spotter_reports/chasemap_track/chasems/chasemap_id),
    // none of which have ever been found stored individually. See CLAUDE.md's "Legacy
    // individual-meta-key cleanup" section for how this was scoped against the real DB.
    private function legacy_meta_keys(): array {
        return [
            'chasestates', 'chasepartners', 'chasechasers', 'chasemiles',
            'chasehail', 'chasewind', 'chasemaptype', 'chasetornado',
        ];
    }

    // Dry-run: finds every legacy individual meta row on a storm_chase post and reports
    // whether its value matches that post's chase_data (the authoritative source) —
    // never deletes anything itself. Result stored in a short-lived transient (keyed by
    // user, not global) for the settings page to render; see handle_delete_legacy_meta()
    // for the actual deletion, which re-verifies against live data rather than trusting
    // this cached report.
    public function handle_scan_legacy_meta(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'stormchases'), 403);
        }
        check_admin_referer('storm_chases_legacy_meta');

        global $wpdb;
        $keys = $this->legacy_meta_keys();
        // $placeholders is a fixed number of literal '%s' tokens (never user data) built to
        // match count($keys) — the standard pattern for a variable-length IN() clause with
        // $wpdb->prepare(); phpcs can't verify that statically, hence the ignore below.
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see comment above $placeholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key IN ($placeholders) AND p.post_type = %s
                 ORDER BY pm.post_id, pm.meta_key",
                array_merge($keys, ['storm_chase'])
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $data_handler = new StormChasesData();
        $report = [];
        foreach ($rows as $row) {
            $chase_data  = $data_handler->get_chase_data($row->post_id);
            $current     = $chase_data[$row->meta_key] ?? null;
            $stray       = maybe_unserialize($row->meta_value);
            $report[] = [
                'post_id'    => (int) $row->post_id,
                'post_title' => $row->post_title,
                'meta_key'   => $row->meta_key,
                'stray'      => $stray,
                'current'    => $current,
                'safe'       => ((string) $current === (string) $stray),
            ];
        }

        set_transient('storm_chases_legacy_meta_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(
            ['page' => 'storm-chases-settings', 'sc_legacy_scan' => '1'],
            admin_url('edit.php?post_type=storm_chase')
        ));
        exit;
    }

    // Deletes only the rows a fresh re-scan (not the possibly-stale dry-run report)
    // confirms still match chase_data — anything that doesn't match is left alone for
    // manual review, never auto-deleted and chase_data is never touched either way.
    public function handle_delete_legacy_meta(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'stormchases'), 403);
        }
        check_admin_referer('storm_chases_legacy_meta');

        global $wpdb;
        $keys = $this->legacy_meta_keys();
        // See the matching comment in handle_scan_legacy_meta() above.
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see comment above $placeholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_key, pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key IN ($placeholders) AND p.post_type = %s",
                array_merge($keys, ['storm_chase'])
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $data_handler = new StormChasesData();
        $deleted = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $chase_data = $data_handler->get_chase_data($row->post_id);
            $current    = $chase_data[$row->meta_key] ?? null;
            $stray      = maybe_unserialize($row->meta_value);
            if ((string) $current === (string) $stray) {
                delete_post_meta($row->post_id, $row->meta_key, $stray);
                $deleted++;
            } else {
                $skipped++;
            }
        }

        delete_transient('storm_chases_legacy_meta_report_' . get_current_user_id());
        wp_safe_redirect(add_query_arg(
            ['page' => 'storm-chases-settings', 'sc_legacy_deleted' => $deleted, 'sc_legacy_skipped' => $skipped],
            admin_url('edit.php?post_type=storm_chase')
        ));
        exit;
    }

    // "Scan" step of the Chase People profiles migration — parses every published chase's
    // legacy chasepartners/chasechasers text (Storm_Chases_People_Taxonomies::
    // parse_and_normalize_names(), which also applies any confirmed name-alias corrections
    // from the Settings page) and reports every distinct name that would become a
    // chase_person term, with how many chases mention them in each role and which posts.
    // Never writes anything; the report is stored in a short-lived per-user transient for the
    // "Apply" step to consume. Mostly a backfill tool now that save_post() auto-syncs
    // chase_person on every save (storm_chase.php) — this is still useful for any chase
    // that hasn't been re-saved since that existed. Mirrors the Legacy Meta Row Cleanup
    // scan-then-delete pattern — real historical data, so nothing gets touched until it's
    // been shown to the user first.
    public function handle_scan_person_migration(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'stormchases'), 403);
        }
        check_admin_referer('storm_chases_person_migration');

        $data_handler = new StormChasesData();
        $post_ids = get_posts([
            'post_type' => 'storm_chase',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        $report = [];
        foreach ($post_ids as $post_id) {
            $chase_data = $data_handler->get_chase_data($post_id);
            $partner_names = Storm_Chases_People_Taxonomies::parse_and_normalize_names($chase_data['chasepartners'] ?? '');
            $chaser_names = Storm_Chases_People_Taxonomies::parse_and_normalize_names($chase_data['chasechasers'] ?? '');

            foreach ([['names' => $partner_names, 'role' => 'as_partner'], ['names' => $chaser_names, 'role' => 'as_chaser']] as $set) {
                foreach ($set['names'] as $name) {
                    if (!isset($report[$name])) {
                        $report[$name] = ['post_ids' => [], 'as_partner' => 0, 'as_chaser' => 0];
                    }
                    $report[$name]['post_ids'][$post_id] = true;
                    $report[$name][$set['role']]++;
                }
            }
        }

        ksort($report, SORT_STRING | SORT_FLAG_CASE);
        foreach ($report as $name => $info) {
            $report[$name]['post_ids'] = array_keys($info['post_ids']);
            $report[$name]['count'] = count($report[$name]['post_ids']);
        }

        set_transient('storm_chases_person_migration_report_' . get_current_user_id(), $report, 15 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(
            ['page' => 'storm-chases-settings', 'sc_person_scan' => '1'],
            admin_url('edit.php?post_type=storm_chase')
        ));
        exit;
    }

    // "Apply" step — re-reads the scan report from the transient (never trusts a stale/forged
    // one blindly, same caution as Legacy Meta Row Cleanup, though here there's nothing to
    // re-verify against live data the way that tool does since this is additive, not
    // destructive) and assigns each parsed name as a chase_person term via
    // wp_set_object_terms(..., append: true) so a partial prior run or a manually-added term
    // is never clobbered. The legacy chasepartners/chasechasers text fields are left
    // completely untouched — this only ever adds taxonomy term relationships, never removes
    // or rewrites anything.
    public function handle_apply_person_migration(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'stormchases'), 403);
        }
        check_admin_referer('storm_chases_person_migration_apply');

        $result_key = 'storm_chases_person_migration_result_' . get_current_user_id();
        $report = get_transient('storm_chases_person_migration_report_' . get_current_user_id());
        if (!is_array($report)) {
            set_transient($result_key, ['success' => false, 'message' => __('Migration report expired or missing — please scan again.', 'stormchases')], 5 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg(['page' => 'storm-chases-settings', 'sc_person_migrated' => '1'], admin_url('edit.php?post_type=storm_chase')));
            exit;
        }

        $assigned = 0;
        $errors = 0;
        foreach ($report as $name => $info) {
            foreach ($info['post_ids'] as $post_id) {
                $result = wp_set_object_terms($post_id, $name, Storm_Chases_People_Taxonomies::PEOPLE_TAXONOMY, true);
                if (is_wp_error($result)) {
                    $errors++;
                } else {
                    $assigned++;
                }
            }
        }

        delete_transient('storm_chases_person_migration_report_' . get_current_user_id());
        set_transient($result_key, [
            'success' => true,
            'message' => sprintf(
                /* translators: 1: number of term assignments made, 2: number of errors */
                __('Migration complete — %1$d term assignments made. %2$d errors.', 'stormchases'),
                $assigned,
                $errors
            ),
        ], 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => 'storm-chases-settings', 'sc_person_migrated' => '1'], admin_url('edit.php?post_type=storm_chase')));
        exit;
    }

    // Saves confirmed name-alias corrections (Storm_Chases_People_Taxonomies::
    // normalize_person_name()) from a simple "Alias => Canonical" textarea, one pair per
    // line — deliberately not a dynamic repeater UI, since this is expected to be edited
    // rarely and a plain textarea is easy to paste multiple corrections into at once. Blank
    // lines and lines without "=>" are silently skipped rather than erroring, so a stray
    // trailing newline doesn't block saving the rest.
    public function handle_save_person_aliases(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'stormchases'), 403);
        }
        check_admin_referer('storm_chases_person_aliases');

        // $raw is unslashed above; each line is sanitize_text_field()'d below before use — the
        // sniff can't trace sanitization through the preg_split()/explode() steps in between.
        $raw = isset($_POST['sc_person_aliases']) ? wp_unslash($_POST['sc_person_aliases']) : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $aliases = [];
        foreach ($lines as $line) {
            if (strpos($line, '=>') === false) {
                continue;
            }
            [$alias, $canonical] = array_map('trim', explode('=>', $line, 2));
            $alias = sanitize_text_field($alias);
            $canonical = sanitize_text_field($canonical);
            if ($alias !== '' && $canonical !== '') {
                $aliases[] = ['alias' => $alias, 'canonical' => $canonical];
            }
        }

        update_option('storm_chases_person_name_aliases', $aliases);
        wp_safe_redirect(add_query_arg(
            ['page' => 'storm-chases-settings', 'sc_person_aliases_saved' => '1'],
            admin_url('edit.php?post_type=storm_chase')
        ));
        exit;
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
