<?php
if (!defined('ABSPATH')) {
    exit;
}

class StormChaseTemplate {
    const POST_TYPE = 'storm_chase';

    private $data_handler;

    public function __construct() {
        $this->data_handler = new StormChasesData();
        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_filter('upload_mimes', [$this, 'add_mime_types']);
        add_filter('the_content', [$this, 'display_storm_chase_data']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'set_post_name_from_chasedate'], 20, 3);
    }

    public function init() {
        $this->create_post_type();
        add_action('save_post_' . self::POST_TYPE, [$this, 'save_post'], 10, 3);
        add_action('trashed_post', [$this, 'handle_trashed_post'], 10, 1);
        add_filter('pre_get_posts', 'sc_modify_pre_get_posts');
        add_shortcode('scarchive', 'chase_archive_shortcode');
        add_filter('body_class', 'stormchases_classes');
        $this->register_meta_fields();
        add_rewrite_rule(
            '^chases/([0-9]{8})/?$',
            'index.php?post_type=storm_chase&name=$matches[1]',
            'top'
        );
    }

    public function create_post_type() {
        $labels = [
            'name'               => __('Storm Chases', 'stormchases'),
            'singular_name'      => __('Storm Chase', 'stormchases'),
            'menu_name'          => __('Storm Chases', 'stormchases'),
            'name_admin_bar'     => __('Storm Chase', 'stormchases'),
            'add_new'            => __('Add New', 'stormchases'),
            'add_new_item'       => __('Add New Storm Chase', 'stormchases'),
            'new_item'           => __('New Storm Chase', 'stormchases'),
            'edit_item'          => __('Edit Storm Chase', 'stormchases'),
            'view_item'          => __('View Storm Chase', 'stormchases'),
            'all_items'          => __('All Storm Chases', 'stormchases'),
            'search_items'       => __('Search Storm Chases', 'stormchases'),
            'parent_item_colon'  => __('Parent Storm Chases:', 'stormchases'),
            'not_found'          => __('No storm chases found.', 'stormchases'),
            'not_found_in_trash' => __('No storm chases found in Trash.', 'stormchases'),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-cloud',
            'query_var'           => true,
            'rewrite'             => ['slug' => 'chases', 'with_front' => false],
            'capability_type'     => 'post',
            'has_archive'         => 'chases',
            'hierarchical'        => false,
            'menu_position'       => 5,
            'supports'            => ['title', 'editor', 'thumbnail', 'excerpt'],
            'taxonomies'          => ['category', 'post_tag'],
            'show_in_rest'        => true,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    public function enqueue_scripts() {
        if (is_singular(self::POST_TYPE) || is_post_type_archive(self::POST_TYPE)) {
            wp_enqueue_style(
                'storm-chases',
                STORM_CHASES_URL . 'assets/css/stormchase.css',
                [],
                STORM_CHASES_VERSION
            );

            wp_enqueue_script(
                'storm-chases-frontend',
                STORM_CHASES_URL . 'assets/js/frontend.js',
                ['jquery'],
                STORM_CHASES_VERSION,
                true
            );

            $chasemap_url = '';
            $chasemap_type = '';
            if (is_singular(self::POST_TYPE)) {
                $chase_data = $this->data_handler->get_chase_data(get_the_ID());
                if (!empty($chase_data['chasemap_id'])) {
                    $chasemap_url = wp_get_attachment_url($chase_data['chasemap_id']);
                    $chasemap_type = $chase_data['chasemaptype'] ?? '0';
                }
            }

            wp_localize_script(
                'storm-chases-frontend',
                'stormChasesFrontend',
                [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'chasemapUrl' => esc_url_raw($chasemap_url ?: ''),
                    'chasemapType' => esc_attr($chasemap_type ?: '0'),
                    'googleMapsEnabled' => get_option('google_maps_enable', false) ? true : false,
                ]
            );

            if (get_option('google_maps_enable')) {
                $api_key = get_option('google_maps_api_key', '');
                if ($api_key) {
                    wp_enqueue_script(
                        'google-maps',
                        'https://maps.googleapis.com/maps/api/js?key=' . esc_attr($api_key),
                        [],
                        null,
                        ['strategy' => 'defer']
                    );
                }
            }
        }

        if (is_admin()) {
            wp_enqueue_style(
                'storm-chases-admin',
                STORM_CHASES_URL . 'assets/css/admin.css',
                [],
                STORM_CHASES_VERSION
            );

            wp_enqueue_script(
                'storm-chases-admin',
                STORM_CHASES_URL . 'assets/js/admin.js',
                ['jquery', 'jquery-ui-datepicker'],
                STORM_CHASES_VERSION,
                true
            );

            wp_localize_script(
                'storm-chases-admin',
                'stormChasesSettings',
                [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('storm_chases_nonce'),
                    'maxFileSize' => absint(get_option('storm_chases_max_file_size', 10 * 1024 * 1024)),
                ]
            );

            wp_enqueue_media();
            wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css', [], '1.12.1');
        }
    }

    public function admin_init() {
        add_action('add_meta_boxes_' . self::POST_TYPE, [$this, 'add_meta_boxes']);
    }

    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __('Storm Chases Settings', 'stormchases'),
            __('Settings', 'stormchases'),
            'manage_options',
            'storm-chases-settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'stormchases'), 403);
        }
        include STORM_CHASES_DIR . 'templates/admin/settings.php';
    }

    public function register_meta_fields() {
        $meta_fields = $this->data_handler->get_meta_fields();
        
        foreach ($meta_fields as $key => $config) {
            $args = [
                'type' => $config['type'],
                'show_in_rest' => true,
                'single' => true,
                'sanitize_callback' => [$this->data_handler, $config['sanitize']],
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                }
            ];

            if ($config['type'] === 'array') {
                if ($key === 'tornadoes') {
                    $args['show_in_rest'] = [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => [
                                        'type' => 'string',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_text_field'],
                                    ],
                                    'lat' => [
                                        'type' => 'number',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_float'],
                                    ],
                                    'lon' => [
                                        'type' => 'number',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_float'],
                                    ],
                                    'ef_rating' => [
                                        'type' => 'string',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_text_field'],
                                    ],
                                    'start_time' => [
                                        'type' => 'string',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_text_field'],
                                    ],
                                    'end_time' => [
                                        'type' => 'string',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_text_field'],
                                    ],
                                    'end_lat' => [
                                        'type' => 'number',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_float'],
                                    ],
                                    'end_lon' => [
                                        'type' => 'number',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_float'],
                                    ],
                                    'media_url' => [
                                        'type' => 'string',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_text_field'],
                                    ],
                                    'photo_id' => [
                                        'type' => 'integer',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_int'],
                                    ],
                                    'photogenic' => [
                                        'type' => 'boolean',
                                        'sanitize_callback' => function($value) {
                                            return boolval($value);
                                        },
                                    ],
                                ],
                            ],
                        ],
                    ];
                } elseif ($key === 'spotter_reports') {
                    $args['show_in_rest'] = [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'report_id' => [
                                        'type' => 'string',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_text_field'],
                                    ],
                                    'type' => [
                                        'type' => 'string',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_text_field'],
                                    ],
                                    'timestamp' => [
                                        'type' => 'string',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_text_field'],
                                    ],
                                    'lat' => [
                                        'type' => 'number',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_float'],
                                    ],
                                    'lon' => [
                                        'type' => 'number',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_float'],
                                    ],
                                    'narrative' => [
                                        'type' => 'string',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_textarea_field'],
                                    ],
                                    'tornado' => [
                                        'type' => 'integer',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_int'],
                                    ],
                                    'funnelcloud' => [
                                        'type' => 'integer',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_int'],
                                    ],
                                    'wallcloud' => [
                                        'type' => 'integer',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_int'],
                                    ],
                                    'hail' => [
                                        'type' => 'integer',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_int'],
                                    ],
                                    'hailsize' => [
                                        'type' => 'number',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_float'],
                                    ],
                                    'windspeed' => [
                                        'type' => 'number',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_float'],
                                    ],
                                    'damage' => [
                                        'type' => 'integer',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_int'],
                                    ],
                                    'city' => [
                                        'type' => 'string',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_text_field'],
                                    ],
                                    'cwa' => [
                                        'type' => 'string',
                                        'sanitize_callback' => [$this->data_handler, 'sanitize_text_field'],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
            }

            register_post_meta(self::POST_TYPE, $key, $args);
        }
    }

    public function register_rest_routes() {
        register_rest_route('stormchases/v1', '/upload-chasemap/(?P<post_id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_chasemap_upload'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
        ]);
        register_rest_route('stormchases/v1', '/upload-spotter-reports', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_spotter_reports_upload'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
        register_rest_route('stormchases/v1', '/tornado-entry', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function(WP_REST_Request $request) {
                if (!wp_verify_nonce($request->get_header('X_WP_Nonce'), 'wp_rest')) {
                    return new WP_Error('invalid_nonce', __('Invalid nonce.', 'stormchases'), ['status' => 403]);
                }
                $index = absint($request->get_param('index'));
                ob_start();
                ?>
                <div class="tornado-entry">
                    <label><?php esc_html_e('Name', 'stormchases'); ?> <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][name]" value=""></label>
                    <label><?php esc_html_e('Latitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][lat]" value="0"></label>
                    <label><?php esc_html_e('Longitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][lon]" value="0"></label>
                    <label><?php esc_html_e('EF Rating', 'stormchases'); ?> 
                        <select name="tornadoes[<?php echo esc_attr($index); ?>][ef_rating]">
                            <?php
                            $ratings = ['Unrated', 'EF-U', 'EF-0', 'EF-1', 'EF-2', 'EF-3', 'EF-4', 'EF-5'];
                            foreach ($ratings as $rating) {
                                echo '<option value="' . esc_attr($rating) . '">' . esc_html($rating) . '</option>';
                            }
                            ?>
                        </select>
                    </label>
                    <label><?php esc_html_e('Start Time', 'stormchases'); ?> <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][start_time]" value=""></label>
                    <label><?php esc_html_e('End Time', 'stormchases'); ?> <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][end_time]" value=""></label>
                    <label><?php esc_html_e('End Latitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][end_lat]" value="0"></label>
                    <label><?php esc_html_e('End Longitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][end_lon]" value="0"></label>
                    <label><?php esc_html_e('Media URL', 'stormchases'); ?> <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][media_url]" value=""></label>
                    <label><?php esc_html_e('Photo', 'stormchases'); ?> 
                        <input type="hidden" class="tornado-photo-id" name="tornadoes[<?php echo esc_attr($index); ?>][photo_id]" value="0">
                        <input type="text" class="tornado-photo-url" value="" readonly>
                        <button type="button" class="upload-tornado-photo-button button"><?php esc_html_e('Upload Photo', 'stormchases'); ?></button>
                    </label>
                    <label><input type="checkbox" name="tornadoes[<?php echo esc_attr($index); ?>][photogenic]"> <?php esc_html_e('Photogenic', 'stormchases'); ?></label>
                    <button type="button" class="remove-tornado button"><?php esc_html_e('Remove Tornado', 'stormchases'); ?></button>
                </div>
                <?php
                return new WP_REST_Response(['success' => true, 'data' => ['entry' => ob_get_clean()]], 200);
            },
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
        ]);
    }

    public function handle_chasemap_upload(WP_REST_Request $request) {
        $post_id = absint($request->get_param('post_id'));
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('unauthorized', __('Unauthorized.', 'stormchases'), ['status' => 403]);
        }

        $file = $request->get_file_params()['chasemap'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('no_file', __('No file uploaded.', 'stormchases'), ['status' => 400]);
        }

        $supported_types = get_option('storm_chases_supported_file_types', ['image/jpeg', 'image/png', 'image/gif', 'application/vnd.google-earth.kml+xml']);
        $max_file_size = absint(get_option('storm_chases_max_file_size', 10 * 1024 * 1024));

        if ($file['size'] > $max_file_size) {
            return new WP_Error('file_too_large', __('File size exceeds limit.', 'stormchases'), ['status' => 400]);
        }

        $arr_file_type = wp_check_filetype(basename($file['name']));
        $uploaded_type = $arr_file_type['type'];

        if (!in_array($uploaded_type, $supported_types)) {
            return new WP_Error('invalid_file', __('Invalid file type.', 'stormchases'), ['status' => 400]);
        }

        $attachment_id = media_handle_sideload($file, $post_id);
        if (is_wp_error($attachment_id)) {
            return new WP_Error('upload_failed', __('Failed to upload chasemap.', 'stormchases'), ['status' => 500]);
        }

        $chase_data = $this->data_handler->get_chase_data($post_id);
        $chase_data['chasemap_id'] = absint($attachment_id);
        $chase_data['chasemaptype'] = $uploaded_type === 'application/vnd.google-earth.kml+xml' ? '1' : '2';
        $result = $this->data_handler->save_chase_data($post_id, $chase_data);

        if (is_wp_error($result)) {
            return $result;
        }

        $this->clear_transients($post_id);
        return rest_ensure_response(['message' => __('Chase map uploaded successfully.', 'stormchases'), 'success' => true, 'attachment_id' => $attachment_id]);
    }

    public function handle_spotter_reports_upload(WP_REST_Request $request) {
        if (!wp_verify_nonce($request->get_header('X_WP_Nonce'), 'wp_rest')) {
            return new WP_Error('invalid_nonce', __('Invalid nonce.', 'stormchases'), ['status' => 403]);
        }

        $file = $request->get_file_params()['spotter_reports'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('no_file', __('No file uploaded.', 'stormchases'), ['status' => 400]);
        }

        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, ['csv', 'txt'])) {
            return new WP_Error('invalid_file', __('Invalid file type. Only CSV or TXT files are supported.', 'stormchases'), ['status' => 400]);
        }

        $csv_content = @file_get_contents($file['tmp_name']);
        if ($csv_content === false) {
            return new WP_Error('read_failed', __('Unable to read CSV file.', 'stormchases'), ['status' => 500]);
        }
        $csv_content = preg_replace('/^\xEF\xBB\xBF/', '', $csv_content);

        $delimiters = [',', ';'];
        $delimiter = ',';
        $max_columns = 0;
        $first_line = strtok($csv_content, "\n");
        foreach ($delimiters as $d) {
            $columns = count(str_getcsv($first_line, $d, '"', '\\'));
            if ($columns > $max_columns) {
                $max_columns = $columns;
                $delimiter = $d;
            }
        }

        $csv = array_map(function($line) use ($delimiter) {
            return str_getcsv(trim($line), $delimiter, '"', '\\');
        }, file($file['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        if (empty($csv)) {
            return new WP_Error('read_failed', __('CSV file is empty or unreadable.', 'stormchases'), ['status' => 400]);
        }

        $header = array_map(function($h) { return trim(strtolower($h)); }, array_shift($csv));
        $required_headers = ['report', 'report_type', 'stamp', 'lat', 'lon', 'narrative', 'tornado', 'hailsize', 'windspeed', 'city1', 'cwa'];
        $missing_headers = array_diff($required_headers, $header);
        if (!empty($missing_headers)) {
            return new WP_Error('invalid_csv', __('Missing required CSV headers: ' . implode(', ', $missing_headers), 'stormchases'), ['status' => 400]);
        }

        $reports_by_date = [];
        $skipped = 0;
        $inserted = 0;
        $unmatched_dates = [];
        $skip_reasons = [];

        foreach ($csv as $index => $row) {
            $row_number = $index + 2;
            if (count($row) !== count($header)) {
                $skipped++;
                $skip_reasons[] = "Row $row_number: Incorrect number of columns.";
                continue;
            }

            $report = array_combine($header, array_map('trim', $row));
            if ($report === false) {
                $skipped++;
                $skip_reasons[] = "Row $row_number: Failed to parse row.";
                continue;
            }

            if ($report['report_type'] !== 'S') {
                $skipped++;
                $skip_reasons[] = "Row $row_number: Invalid report type.";
                continue;
            }

            $report_date = DateTime::createFromFormat('Y-m-d H:i:s', $report['stamp'] ?? '');
            if (!$report_date) {
                $skipped++;
                $skip_reasons[] = "Row $row_number: Invalid timestamp format.";
                continue;
            }

            $hour = (int)$report_date->format('H');
            if ($hour < 10) {
                $report_date->modify('-1 day');
            }
            $date_key = $report_date->format('Ymd');

            $chase_post = $this->get_chase_post_by_date($date_key);
            if (!$chase_post) {
                $unmatched_dates[$date_key] = ($unmatched_dates[$date_key] ?? 0) + 1;
                $skipped++;
                $skip_reasons[] = "Row $row_number: No storm chase post found for date $date_key.";
                continue;
            }

            $report_data = $this->data_handler->sanitize_spotter_report([
                'report_id' => $report['report'] ?? '',
                'type' => $report['report_type'] ?? 'S',
                'timestamp' => $report['stamp'] ?? '',
                'lat' => !empty($report['lat']) && is_numeric($report['lat']) ? floatval($report['lat']) : 0,
                'lon' => !empty($report['lon']) && is_numeric($report['lon']) ? floatval($report['lon']) : 0,
                'narrative' => $report['narrative'] ?? '',
                'tornado' => isset($report['tornado']) && $report['tornado'] !== '' ? (int)$report['tornado'] : 0,
                'funnelcloud' => isset($report['funnelcloud']) && $report['funnelcloud'] !== '' ? (int)$report['funnelcloud'] : 0,
                'wallcloud' => isset($report['wallcloud']) && $report['wallcloud'] !== '' ? (int)$report['wallcloud'] : 0,
                'hail' => isset($report['hail']) && $report['hail'] !== '' ? (int)$report['hail'] : 0,
                'hailsize' => !empty($report['hailsize']) && is_numeric($report['hailsize']) ? floatval($report['hailsize']) : 0,
                'windspeed' => !empty($report['windspeed']) && is_numeric($report['windspeed']) ? floatval($report['windspeed']) : 0,
                'damage' => isset($report['damage']) && $report['damage'] !== '' ? (int)$report['damage'] : 0,
                'city' => $report['city1'] ?? '',
                'cwa' => !empty($report['cwa']) ? $report['cwa'] : 'UNKNOWN',
            ]);

            if (empty($report_data)) {
                $skipped++;
                $skip_reasons[] = "Row $row_number: Invalid report data after sanitization.";
                continue;
            }
            $reports_by_date[$chase_post->ID][] = $report_data;
        }

        if (empty($reports_by_date)) {
            $message = __('No valid reports found in the CSV.', 'stormchases');
            if (!empty($unmatched_dates)) {
                $message .= ' Unmatched dates: ' . implode(', ', array_keys($unmatched_dates));
            }
            return new WP_Error('no_valid_reports', $message, [
                'status' => 400,
                'data' => [
                    'skipped' => $skipped,
                    'skip_reasons' => $skip_reasons,
                    'unmatched_dates' => $unmatched_dates
                ]
            ]);
        }

        $overwrite = !empty($request->get_param('overwrite_reports')) && $request->get_param('overwrite_reports') == '1';

        foreach ($reports_by_date as $post_id => $reports) {
            $chase_data = $this->data_handler->get_chase_data($post_id);

            if ($overwrite) {
                $chase_data['spotter_reports'] = [];
            }

            $existing_report_ids = array_column($chase_data['spotter_reports'], 'report_id');
            foreach ($reports as $report) {
                if (!$overwrite && in_array($report['report_id'], $existing_report_ids)) {
                    $skipped++;
                    $skip_reasons[] = "Row for report ID {$report['report_id']}: Already exists for post ID $post_id and overwrite not enabled.";
                    continue;
                }
                $chase_data['spotter_reports'][] = $report;
                $inserted++;
            }

            $result = $this->data_handler->save_chase_data($post_id, $chase_data);
            if (is_wp_error($result)) {
                return new WP_Error('save_failed', __('Failed to save reports: ' . $result->get_error_message(), 'stormchases'), [
                    'status' => 500,
                    'data' => [
                        'skipped' => $skipped,
                        'skip_reasons' => $skip_reasons,
                        'unmatched_dates' => $unmatched_dates
                    ]
                ]);
            }
        }

        $this->clear_transients(array_keys($reports_by_date));
        $message = sprintf(
            __('Uploaded %d reports successfully across %d posts. Skipped %d reports.', 'stormchases'),
            $inserted,
            count($reports_by_date),
            $skipped
        );
        if (!empty($unmatched_dates)) {
            $message .= ' Unmatched dates: ' . implode(', ', array_keys($unmatched_dates));
        }
        if (!empty($skip_reasons)) {
            $message .= '<br><strong>Skipped Rows:</strong><ul>';
            foreach ($skip_reasons as $reason) {
                $message .= '<li>' . esc_html($reason) . '</li>';
            }
            $message .= '</ul>';
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'message' => $message,
                'skipped' => $skipped,
                'skip_reasons' => $skip_reasons,
                'unmatched_dates' => $unmatched_dates
            ]
        ]);
    }

    public function add_mime_types($mimes) {
        $mimes['kml'] = 'application/vnd.google-earth.kml+xml';
        $mimes['csv'] = 'text/csv';
        $mimes['txt'] = 'text/plain';
        return $mimes;
    }

    public function display_storm_chase_data($content) {
        if (!is_singular(self::POST_TYPE) || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        static $processed = false;
        if ($processed) {
            return $content;
        }

        global $post;
        if (!$post instanceof WP_Post || $post->post_type !== self::POST_TYPE) {
            return $content . '<p>' . esc_html__('Error: No storm chase post found.', 'stormchases') . '</p>';
        }

        $processed = true;
        $chase_data = $this->data_handler->get_chase_data($post->ID);
        $chasemap_url = !empty($chase_data['chasemap_id']) ? wp_get_attachment_url($chase_data['chasemap_id']) : '';
        $chasemap_type = $chase_data['chasemaptype'] ?? '0';
        $google_maps_enabled = get_option('google_maps_enable', false);

        ob_start();
        ?>
        <div id="chase-details">
            <h2><?php esc_html_e('Storm Chase Details', 'stormchases'); ?></h2>
            <?php if ($google_maps_enabled && $chasemap_url && $chasemap_type === '1') : ?>
                <div id="chasemap" style="height: 400px; width: 100%; max-width: 800px; margin-bottom: 20px;"></div>
                <div id="chase-details-text">
            <?php elseif ($chasemap_url && $chasemap_type === '2') : ?>
                <img src="<?php echo esc_url($chasemap_url); ?>" style="max-width: 100%; height: auto; margin-bottom: 20px;" alt="<?php esc_attr_e('Chase Map', 'stormchases'); ?>" />
                <div id="chase-details-text">
            <?php else : ?>
                <div id="chase-details-text-nm">
            <?php endif; ?>
                <?php
                $chase_date = $chase_data['chasedate'] && preg_match('/^[0-9]{8}$/', $chase_data['chasedate'])
                    ? esc_html(date_i18n('F j, Y', strtotime($chase_data['chasedate'])))
                    : esc_html__('Invalid or missing date', 'stormchases');
                printf('<b>%s:</b> %s<br/>', esc_html__('Chase Date', 'stormchases'), $chase_date);
                if (get_option('miles_logged_enable') && $chase_data['chasemiles']) {
                    printf('<b>%s:</b> %s<br/>', esc_html__('Miles Logged', 'stormchases'), esc_html($chase_data['chasemiles']));
                }
                if (get_option('states_chased_enable') && $chase_data['chasestates']) {
                    printf('<b>%s:</b> %s<br/>', esc_html__('States Chased', 'stormchases'), esc_html($chase_data['chasestates']));
                }
                if (get_option('chase_partners_enable') && $chase_data['chasepartners']) {
                    printf('<b>%s:</b> %s<br/>', esc_html__('Chase Partners', 'stormchases'), esc_html($chase_data['chasepartners']));
                }
                if (get_option('chasers_encountered_enable') && $chase_data['chasechasers'] && $chase_data['chasechasers'] !== 'None') {
                    printf('<b>%s:</b> %s<br/>', esc_html__('Chasers Encountered', 'stormchases'), esc_html($chase_data['chasechasers']));
                }
                if (get_option('tornadoes_enable') && !empty($chase_data['tornadoes'])) {
                    printf('<b>%s:</b> %s<br/>', esc_html__('Tornadoes Witnessed', 'stormchases'), esc_html(count($chase_data['tornadoes'])));
                    echo '<ul>';
                    foreach ($chase_data['tornadoes'] as $index => $tornado) {
                        $modal_id = 'tornado-modal-' . $post->ID . '-' . $index;
                        ?>
                        <li>
                            <a href="#" class="tornado-link"
                               data-modal-id="<?php echo esc_attr($modal_id); ?>">
                                <?php echo esc_html($tornado['name'] ?: 'Tornado ' . ($index + 1)); ?>
                            </a>
                            <div id="<?php echo esc_attr($modal_id); ?>" class="modal tornado-modal" style="display: none;">
                                <div class="modal-overlay"></div>
                                <div class="modal-content tornado-modal-content">
                                    <span class="modal-close">&times;</span>
                                    <h3><?php echo esc_html($tornado['name'] ?: 'Tornado ' . ($index + 1)); ?></h3>
                                    <p><b><?php esc_html_e('Latitude', 'stormchases'); ?>:</b> <?php echo esc_html($tornado['lat'] ?? '0'); ?></p>
                                    <p><b><?php esc_html_e('Longitude', 'stormchases'); ?>:</b> <?php echo esc_html($tornado['lon'] ?? '0'); ?></p>
                                    <p><b><?php esc_html_e('EF Rating', 'stormchases'); ?>:</b> <?php echo esc_html($tornado['ef_rating'] ?? 'Unrated'); ?></p>
                                    <?php if (!empty($tornado['start_time'])) : ?>
                                        <p><b><?php esc_html_e('Start Time', 'stormchases'); ?>:</b> <?php echo esc_html($tornado['start_time']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($tornado['end_time'])) : ?>
                                        <p><b><?php esc_html_e('End Time', 'stormchases'); ?>:</b> <?php echo esc_html($tornado['end_time']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($tornado['end_lat']) && !empty($tornado['end_lon'])) : ?>
                                        <p><b><?php esc_html_e('End Latitude', 'stormchases'); ?>:</b> <?php echo esc_html($tornado['end_lat']); ?></p>
                                        <p><b><?php esc_html_e('End Longitude', 'stormchases'); ?>:</b> <?php echo esc_html($tornado['end_lon']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($tornado['photogenic'])) : ?>
                                        <p><b><?php esc_html_e('Photogenic', 'stormchases'); ?>:</b> <?php esc_html_e('Yes', 'stormchases'); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($tornado['media_url']) && preg_match('/^\/[\w\/-]+\.(mp4|webm|ogg)$/i', $tornado['media_url'])) : ?>
                                        <video controls style="max-width: 100%;">
                                            <source src="<?php echo esc_url($tornado['media_url']); ?>" type="video/<?php echo esc_attr(strtolower(pathinfo($tornado['media_url'], PATHINFO_EXTENSION))); ?>">
                                            <?php esc_html_e('Your browser does not support the video tag.', 'stormchases'); ?>
                                        </video>
                                    <?php endif; ?>
                                    <?php if (!empty($tornado['photo_id'])) : ?>
                                        <?php echo wp_get_attachment_image($tornado['photo_id'], 'medium', false, ['style' => 'max-width: 100%; height: auto;']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                        <?php
                    }
                    echo '</ul>';
                }
                if (get_option('hail_enable') && $chase_data['chasehail']) {
                    printf('<b>%s:</b> %s %s<br/>', esc_html__('Largest Hail Encountered', 'stormchases'), esc_html(number_format($chase_data['chasehail'], 2)), esc_html__('in.', 'stormchases'));
                }
                if (get_option('wind_enable') && $chase_data['chasewind']) {
                    printf('<b>%s:</b> %s %s<br/>', esc_html__('Highest Wind Encountered', 'stormchases'), esc_html($chase_data['chasewind']), esc_html__('MPH', 'stormchases'));
                }
                if (get_option('milestones_enable') && $chase_data['chasems']) {
                    printf('<b>%s:</b> %s<br/>', esc_html__('Milestones', 'stormchases'), esc_html($chase_data['chasems']));
                }
                if (get_option('spotter_reports_enable') && !empty($chase_data['spotter_reports'])) {
                    printf('<b>%s:</b> %s<br/>', esc_html__('Spotter Network Reports', 'stormchases'), esc_html(count($chase_data['spotter_reports'])));
                    echo '<ul>';
                    foreach ($chase_data['spotter_reports'] as $index => $report) {
                        $report_id = $report['report_id'] ?? $index;
                        $weather_type = $report['tornado'] ? 'Tornado' : ($report['hailsize'] ? 'Hail' : ($report['windspeed'] ? 'Wind' : ($report['funnelcloud'] ? 'Funnel Cloud' : ($report['wallcloud'] ? 'Wall Cloud' : 'Other'))));
                        $link_text = !empty($report['city']) ? esc_html($weather_type . ' ' . $report['city']) : esc_html__('Report ' . ($index + 1), 'stormchases');
                        $modal_id = 'report-modal-' . $post->ID . '-' . $report_id;
                        $timestamp = !empty($report['timestamp']) && strtotime($report['timestamp'])
                            ? esc_html(storm_chases_convert_utc_to_central($report['timestamp']))
                            : esc_html__('Invalid timestamp', 'stormchases');
                        $nws_office = esc_html(storm_chases_get_nws_office($report['cwa'] ?? ''));
                        ?>
                        <li>
                            <a href="#" class="report-link"
                               data-modal-id="<?php echo esc_attr($modal_id); ?>">
                                <?php echo $link_text; ?>
                            </a>
                            <div id="<?php echo esc_attr($modal_id); ?>" class="modal report-modal" style="display: none;">
                                <div class="modal-overlay"></div>
                                <div class="modal-content report-modal-content">
                                    <span class="modal-close">&times;</span>
                                    <h3><?php echo esc_html($link_text); ?></h3>
                                    <p><b><?php esc_html_e('Timestamp', 'stormchases'); ?>:</b> <?php echo esc_html($timestamp); ?></p>
                                    <p><b><?php esc_html_e('Latitude', 'stormchases'); ?>:</b> <?php echo esc_html($report['lat'] ?? '0'); ?></p>
                                    <p><b><?php esc_html_e('Longitude', 'stormchases'); ?>:</b> <?php echo esc_html($report['lon'] ?? '0'); ?></p>
                                    <p><b><?php esc_html_e('NWS Office', 'stormchases'); ?>:</b> <?php echo esc_html($nws_office); ?></p>
                                    <?php if (!empty($report['narrative'])) : ?>
                                        <p><b><?php esc_html_e('Narrative', 'stormchases'); ?>:</b> <?php echo esc_html($report['narrative']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($report['hailsize']) && $report['hailsize'] > 0) : ?>
                                        <p><b><?php esc_html_e('Hail Size', 'stormchases'); ?>:</b> <?php echo esc_html($report['hailsize']); ?> inches</p>
                                    <?php endif; ?>
                                    <?php if (!empty($report['windspeed']) && $report['windspeed'] > 0) : ?>
                                        <p><b><?php esc_html_e('Wind Speed', 'stormchases'); ?>:</b> <?php echo esc_html($report['windspeed']); ?> mph</p>
                                    <?php endif; ?>
                                    <?php if (!empty($report['tornado'])) : ?>
                                        <p><b><?php esc_html_e('Tornado', 'stormchases'); ?>:</b> <?php esc_html_e('Yes', 'stormchases'); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($report['funnelcloud'])) : ?>
                                        <p><b><?php esc_html_e('Funnel Cloud', 'stormchases'); ?>:</b> <?php esc_html_e('Yes', 'stormchases'); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($report['wallcloud'])) : ?>
                                        <p><b><?php esc_html_e('Wall Cloud', 'stormchases'); ?>:</b> <?php esc_html_e('Yes', 'stormchases'); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($report['damage'])) : ?>
                                        <p><b><?php esc_html_e('Damage', 'stormchases'); ?>:</b> <?php esc_html_e('Yes', 'stormchases'); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                        <?php
                    }
                    echo '</ul>';
                }
                printf('<b>%s:</b> <a target="_blank" href="%s">%s</a><br/>', esc_html__('Severe Risks', 'stormchases'), esc_url('https://www.spc.noaa.gov/cgi-bin-spc/getacrange.pl?date0=' . esc_attr($chase_data['chasedate']) . '&date1=' . esc_attr($chase_data['chasedate'])), esc_html__('SPC Outlooks', 'stormchases'));
                printf('<b>%s:</b> <a target="_blank" href="%s">%s</a><br/>', esc_html__('Severe Reports', 'stormchases'), esc_url('https://www.spc.noaa.gov/climo/reports/' . esc_attr(substr($chase_data['chasedate'], -6)) . '_rpts.html'), esc_html__('Storm Reports', 'stormchases'));
                ?>
                </div>
            </div>
        <?php
        return ob_get_clean() . $content;
    }

    public function get_storm_chase_navigation($post_id) {
        global $wpdb, $post;
        if (!$post instanceof WP_Post || $post->post_type !== self::POST_TYPE) {
            return '';
        }

        ob_start();
        $current_post_date = $post->post_date;

        $prev_query = $wpdb->prepare(
            "SELECT ID, post_title FROM $wpdb->posts WHERE post_type = %s AND post_status = 'publish' AND post_date < %s ORDER BY post_date DESC LIMIT 1",
            self::POST_TYPE,
            $current_post_date
        );
        $prev_post = $wpdb->get_row($prev_query);

        $next_query = $wpdb->prepare(
            "SELECT ID, post_title FROM $wpdb->posts WHERE post_type = %s AND post_status = 'publish' AND post_date > %s ORDER BY post_date ASC LIMIT 1",
            self::POST_TYPE,
            $current_post_date
        );
        $next_post = $wpdb->get_row($next_query);

        ?>
        <nav class="storm-chase-navigation">
            <div class="previous-post-link">
                <?php if ($prev_post) : ?>
                    <a href="<?php echo esc_url(get_permalink($prev_post->ID)); ?>">
                        <span class="nav-arrow">&larr;</span> <?php echo esc_html__('Previous Storm Chase: ', 'stormchases') . esc_html($prev_post->post_title); ?>
                    </a>
                <?php endif; ?>
            </div>
            <div class="next-post-link">
                <?php if ($next_post) : ?>
                    <a href="<?php echo esc_url(get_permalink($next_post->ID)); ?>">
                        <?php echo esc_html__('Next Storm Chase: ', 'stormchases') . esc_html($next_post->post_title); ?> <span class="nav-arrow">&rarr;</span>
                    </a>
                <?php endif; ?>
            </div>
        </nav>
        <?php
        return ob_get_clean();
    }

    public function set_post_name_from_chasedate($post_id, $post, $update) {
        if ($post->post_type !== self::POST_TYPE || defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $chase_data = $this->data_handler->get_chase_data($post_id);
        if ($chase_data['chasedate'] && preg_match('/^[0-9]{8}$/', $chase_data['chasedate'])) {
            remove_action('save_post_' . self::POST_TYPE, [$this, 'set_post_name_from_chasedate'], 20);
            $result = wp_update_post([
                'ID' => $post_id,
                'post_name' => $chase_data['chasedate'],
            ], true);
            add_action('save_post_' . self::POST_TYPE, [$this, 'set_post_name_from_chasedate'], 20, 3);

            if (is_wp_error($result)) {
                set_transient('storm_chases_errors_' . $post_id, [__('Failed to set post name.', 'stormchases')], 60);
            }
        }
    }

    public function save_post($post_id, $post, $update) {
        if (!isset($_POST['storm_chases_nonce']) || !wp_verify_nonce($_POST['storm_chases_nonce'], 'storm_chases_save_post')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $chase_data = $this->data_handler->get_chase_data($post_id);

        if (array_key_exists('tornadoes', $_POST)) {
            if (empty($_POST['tornadoes']) || $_POST['tornadoes'] === '' || (is_array($_POST['tornadoes']) && count($_POST['tornadoes']) === 0)) {
                $chase_data['tornadoes'] = [];
            } else {
                $tornadoes = [];
                foreach ($_POST['tornadoes'] as $index => $tornado) {
                    $tornadoes[$index] = [
                        'name' => isset($tornado['name']) ? sanitize_text_field($tornado['name']) : '',
                        'lat' => isset($tornado['lat']) ? floatval($tornado['lat']) : 0,
                        'lon' => isset($tornado['lon']) ? floatval($tornado['lon']) : 0,
                        'ef_rating' => isset($tornado['ef_rating']) ? sanitize_text_field($tornado['ef_rating']) : 'Unrated',
                        'start_time' => isset($tornado['start_time']) ? sanitize_text_field($tornado['start_time']) : '',
                        'end_time' => isset($tornado['end_time']) ? sanitize_text_field($tornado['end_time']) : '',
                        'end_lat' => isset($tornado['end_lat']) ? floatval($tornado['end_lat']) : 0,
                        'end_lon' => isset($tornado['end_lon']) ? floatval($tornado['end_lon']) : 0,
                        'media_url' => isset($tornado['media_url']) ? sanitize_text_field($tornado['media_url']) : '',
                        'photo_id' => isset($tornado['photo_id']) ? absint($tornado['photo_id']) : 0,
                        'photogenic' => isset($tornado['photogenic']) ? boolval($tornado['photogenic']) : false,
                    ];
                }
                $chase_data['tornadoes'] = $tornadoes;
            }
        } else {
            $chase_data['tornadoes'] = [];
        }

        $chase_data['chasetornado'] = !empty($chase_data['tornadoes']) ? 1 : 0;
        $chase_data['chasedate'] = isset($_POST['chasedate']) ? sanitize_text_field($_POST['chasedate']) : $chase_data['chasedate'];
        $chase_data['chasestates'] = isset($_POST['chasestates']) ? sanitize_text_field($_POST['chasestates']) : $chase_data['chasestates'];
        $chase_data['chasepartners'] = isset($_POST['chasepartners']) ? sanitize_text_field($_POST['chasepartners']) : $chase_data['chasepartners'];
        $chase_data['chasechasers'] = isset($_POST['chasechasers']) ? sanitize_text_field($_POST['chasechasers']) : $chase_data['chasechasers'];
        $chase_data['chasemiles'] = isset($_POST['chasemiles']) ? absint($_POST['chasemiles']) : $chase_data['chasemiles'];
        $chase_data['chasehail'] = isset($_POST['chasehail']) ? floatval($_POST['chasehail']) : $chase_data['chasehail'];
        $chase_data['chasewind'] = isset($_POST['chasewind']) ? absint($_POST['chasewind']) : $chase_data['chasewind'];
        $chase_data['chasems'] = isset($_POST['chasems']) ? sanitize_textarea_field($_POST['chasems']) : $chase_data['chasems'];

        if (isset($_POST['rmchasemap']) && $_POST['rmchasemap'] === '1') {
            $chase_data['chasemap_id'] = 0;
            $chase_data['chasemaptype'] = '0';
        }

        if (!empty($chase_data['chasedate']) && preg_match('/^\d{8}$/', $chase_data['chasedate'])) {
            update_post_meta($post_id, 'chasedate', $chase_data['chasedate']);
        } else {
            delete_post_meta($post_id, 'chasedate');
        }

        $result = $this->data_handler->save_chase_data($post_id, $chase_data);
        if (is_wp_error($result)) {
            Storm_Chases::debug_log('Failed to save chase data: ' . $result->get_error_message(), 'error');
            set_transient('storm_chases_errors_' . $post_id, [$result->get_error_message()], 60);
            return;
        }

        $this->clear_transients($post_id);
        $this->clear_stats_and_archive_transients();
    }

    public function add_meta_boxes() {
        add_meta_box(
            'storm_chases_meta',
            __('Storm Chase Details', 'stormchases'),
            [$this, 'add_inner_meta_boxes'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function clear_stats_and_archive_transients() {
        global $wpdb;

        // Clear transients with prefixes sc_archive_v2_ and sc_stats_v12_
        $prefixes = ['sc_archive_v2_%', 'sc_stats_v12_%'];
        foreach ($prefixes as $prefix) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
                    '_transient_' . $prefix,
                    '_transient_timeout_' . $prefix
                )
            );
            Storm_Chases::debug_log("Cleared transients with prefix: $prefix", 'info');
        }
    }

    public function add_inner_meta_boxes($post) {
        wp_nonce_field('storm_chases_save_post', 'storm_chases_nonce');
        include STORM_CHASES_DIR . 'templates/stormchases.php';
    }

    public function get_chase_post_by_date($date) {
        $args = [
            'post_type' => self::POST_TYPE,
            'meta_query' => [
                [
                    'key' => 'chasedate',
                    'value' => $date,
                    'compare' => '='
                ]
            ],
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ];

        $query = new WP_Query($args);
        return $query->have_posts() ? $query->posts[0] : null;
    }

    public function handle_trashed_post($post_id) {
        if (get_post_type($post_id) === self::POST_TYPE) {
            $this->clear_transients([$post_id]);
        }
    }

    private function clear_transients($post_ids = []) {
        global $wpdb;
        if (empty($post_ids)) {
            $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_sc_%' OR option_name LIKE '_transient_timeout_sc_%'");
        } else {
            $post_ids = array_map('absint', (array)$post_ids);
            $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $wpdb->options WHERE option_name IN (SELECT CONCAT('_transient_sc_', ID) FROM $wpdb->posts WHERE ID IN ($placeholders)) OR option_name IN (SELECT CONCAT('_transient_timeout_sc_', ID) FROM $wpdb->posts WHERE ID IN ($placeholders))",
                array_merge($post_ids, $post_ids)
            ));
        }
    }
}