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
        add_filter('preview_post_link', [$this, 'fix_preview_post_link'], 10, 2);
        add_action('admin_notices', [$this, 'display_chase_errors']);
    }

    // Surfaces errors queued via set_transient('storm_chases_errors_{$post_id}', ...)
    // (e.g. a failed save, or a slug that couldn't be set to the derived chase date).
    public function display_chase_errors() {
        global $post;
        if (!$post instanceof WP_Post || $post->post_type !== self::POST_TYPE) {
            return;
        }
        $errors = get_transient('storm_chases_errors_' . $post->ID);
        if (empty($errors) || !is_array($errors)) {
            return;
        }
        delete_transient('storm_chases_errors_' . $post->ID);
        foreach ($errors as $error) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($error));
        }
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

            $map_provider = get_option('map_provider', 'openstreetmap');
            $deps = ['jquery'];

            if ($map_provider === 'openstreetmap') {
                wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
                wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
                $deps[] = 'leaflet';
            }

            wp_enqueue_script(
                'storm-chases-frontend',
                STORM_CHASES_URL . 'assets/js/frontend.js',
                $deps,
                STORM_CHASES_VERSION,
                true
            );

            $chasemap_url = '';
            $chasemap_type = '0';
            $chasemap_track = [];
            if (is_singular(self::POST_TYPE)) {
                $chase_data = $this->data_handler->get_chase_data(get_the_ID());
                $chasemap_type = $chase_data['chasemaptype'] ?? '0';
                if ($chasemap_type === '3') {
                    $chasemap_track = $chase_data['chasemap_track'] ?? [];
                    // Defensive cap: tracks saved by very old versions of this plugin
                    // (before simplify_track()'s point cap existed) may still be
                    // unbounded. Re-simplify rather than embedding tens of thousands
                    // of points into every page load.
                    if (count($chasemap_track) > 5000) {
                        $chasemap_track = self::simplify_track($chasemap_track);
                        $chasemap_track = self::sparsify_track_timestamps($chasemap_track);
                    }
                } elseif (!empty($chase_data['chasemap_id'])) {
                    $chasemap_url = wp_get_attachment_url($chase_data['chasemap_id']);
                }
            }

            wp_localize_script(
                'storm-chases-frontend',
                'stormChasesFrontend',
                [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'chasemapUrl' => esc_url_raw($chasemap_url ?: ''),
                    'chasemapType' => esc_attr($chasemap_type),
                    'chasemapTrack' => $chasemap_track,
                    'googleMapsEnabled' => get_option('google_maps_enable', false) ? true : false,
                    'mapProvider' => esc_attr($map_provider),
                ]
            );

            if ($map_provider === 'google' && get_option('google_maps_enable')) {
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
                            'type'  => 'array',
                            'items' => ['type' => 'object'],
                        ],
                    ];
                } elseif ($key === 'chasemap_track') {
                    // Internal GPS track data — expose schema but not full item detail
                    $args['show_in_rest'] = [
                        'schema' => [
                            'type'  => 'array',
                            'items' => [
                                'type'     => 'array',
                                'items'    => ['type' => 'number'],
                                'nullable' => true,
                            ],
                        ],
                    ];
                } elseif ($key === 'chasems') {
                    $args['show_in_rest'] = [
                        'schema' => [
                            'type'  => 'array',
                            'items' => ['type' => 'string'],
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
        register_rest_route('stormchases/v1', '/remove-chasemap/(?P<post_id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_chasemap_remove'],
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
    }

    public function handle_chasemap_upload(WP_REST_Request $request) {
        $post_id = absint($request->get_param('post_id'));
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('unauthorized', __('Unauthorized.', 'stormchases'), ['status' => 403]);
        }

        $chasemap_raw = $request->get_file_params()['chasemap'] ?? null;
        if (!$chasemap_raw) {
            return new WP_Error('no_file', __('No file uploaded.', 'stormchases'), ['status' => 400]);
        }

        $max_file_size = absint(get_option('storm_chases_max_file_size', 10 * 1024 * 1024));
        $file_list     = self::normalize_file_params($chasemap_raw);
        $gps_exts      = ['kml', 'gpx', 'nmea'];
        $gps_tracks    = [];
        $image_file    = null;

        foreach ($file_list as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                continue;
            }
            $ext    = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $is_gps = in_array($ext, $gps_exts, true);

            // GPS files bypass the image size limit — PHP's upload_max_filesize governs them
            if (!$is_gps && $file['size'] > $max_file_size) {
                return new WP_Error('file_too_large', __('Image file exceeds the configured size limit.', 'stormchases'), ['status' => 400]);
            }

            if ($is_gps) {
                @set_time_limit(300);       // large files can take a while
                wp_raise_memory_limit('image');
                $parsed = self::parse_gps_file($file['tmp_name'], $ext);
                if (!empty($parsed)) {
                    $gps_tracks[] = $parsed;
                }
            } else {
                $image_file = $file;
            }
        }

        if (!empty($gps_tracks)) {
            try {
                $track = self::merge_tracks($gps_tracks);
                if (empty($track)) {
                    return new WP_Error('no_track', __('Could not extract any track coordinates from the uploaded file(s).', 'stormchases'), ['status' => 422]);
                }

                $pre_outlier_count = count(array_filter($track, fn($p) => $p !== null));
                $track = self::remove_distance_outliers($track);
                if (empty($track)) {
                    return new WP_Error('no_track', __('Could not extract any track coordinates from the uploaded file(s).', 'stormchases'), ['status' => 422]);
                }
                $outliers_removed = $pre_outlier_count - count(array_filter($track, fn($p) => $p !== null));

                $raw_count  = count($track);
                $track      = self::apply_privacy_zones($track);
                if (empty($track)) {
                    return new WP_Error('privacy_zones', __('All track points fall within your privacy zones. No track data was stored.', 'stormchases'), ['status' => 422]);
                }
                $trimmed    = $raw_count - count($track);
                $simplified = self::simplify_track($track);
                $simplified = self::sparsify_track_timestamps($simplified);

                $chase_data = $this->data_handler->get_chase_data($post_id);
                $chase_data['chasemap_track'] = $simplified;
                $chase_data['chasemaptype']   = '3';
                $chase_data['chasemap_id']    = 0;
                $result = $this->data_handler->save_chase_data($post_id, $chase_data);
                if (is_wp_error($result)) {
                    return $result;
                }
                $this->clear_transients([$post_id]);

                $null_count    = count(array_filter($simplified, fn($x) => $x === null));
                $point_count   = count($simplified) - $null_count;
                $segment_count = $null_count + 1;
            $file_count    = count($gps_tracks);

            if ($file_count > 1 && $null_count > 0) {
                $msg = sprintf(
                    /* translators: 1: files, 2: segments, 3: points */
                    __('GPS tracks merged from %1$d files into %2$d segments (%3$d points stored). Gap markers show where GPS signal was lost.', 'stormchases'),
                    $file_count, $segment_count, $point_count
                );
            } elseif ($file_count > 1) {
                $msg = sprintf(
                    __('GPS track merged from %1$d files: %2$d points stored.', 'stormchases'),
                    $file_count, $point_count
                );
            } else {
                $msg = sprintf(__('GPS track loaded: %d points stored.', 'stormchases'), $point_count);
            }
            if ($trimmed > 0) {
                $msg .= ' ' . sprintf(__('%d point(s) trimmed by privacy zones.', 'stormchases'), $trimmed);
            }
            if ($outliers_removed > 0) {
                $msg .= ' ' . sprintf(
                    /* translators: %d: number of GPS points discarded for an implausible jump */
                    __('%d point(s) discarded as GPS errors (implausible jump in distance).', 'stormchases'),
                    $outliers_removed
                );
            }

            return rest_ensure_response([
                'success'       => true,
                'track'         => true,
                'track_points'  => $point_count,
                'segment_count' => $segment_count,
                'trimmed'       => $trimmed,
                'message'       => $msg,
            ]);
            } catch (Throwable $e) {
                Storm_Chases::debug_log('GPS upload processing error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 'error');
                return new WP_Error('processing_error', 'GPS processing failed: ' . $e->getMessage(), ['status' => 500]);
            }
        }

        if ($image_file) {
            $supported_types = get_option('storm_chases_supported_file_types', ['image/jpeg', 'image/png', 'image/gif']);
            $arr_file_type   = wp_check_filetype(basename($image_file['name']));
            $uploaded_type   = $arr_file_type['type'];
            if (!in_array($uploaded_type, $supported_types, true)) {
                return new WP_Error(
                    'invalid_file',
                    __('Unsupported file type. Upload a GPS track file (.kml, .gpx, .nmea) or an image (.jpg, .png, .gif).', 'stormchases'),
                    ['status' => 400]
                );
            }
            $attachment_id = media_handle_sideload($image_file, $post_id);
            if (is_wp_error($attachment_id)) {
                return new WP_Error('upload_failed', __('Failed to upload chase map image.', 'stormchases'), ['status' => 500]);
            }
            $chase_data = $this->data_handler->get_chase_data($post_id);
            $chase_data['chasemap_id']    = absint($attachment_id);
            $chase_data['chasemaptype']   = '2';
            $chase_data['chasemap_track'] = [];
            $result = $this->data_handler->save_chase_data($post_id, $chase_data);
            if (is_wp_error($result)) {
                return $result;
            }
            $this->clear_transients([$post_id]);
            return rest_ensure_response([
                'success'       => true,
                'track'         => false,
                'attachment_id' => $attachment_id,
                'thumb_url'     => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
                'message'       => __('Chase map image uploaded.', 'stormchases'),
            ]);
        }

        return new WP_Error('no_valid_files', __('No valid GPS track or image files were uploaded.', 'stormchases'), ['status' => 400]);
    }

    public function handle_chasemap_remove(WP_REST_Request $request) {
        $post_id = absint($request->get_param('post_id'));
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('unauthorized', __('Unauthorized.', 'stormchases'), ['status' => 403]);
        }

        $chase_data = $this->data_handler->get_chase_data($post_id);
        $chase_data['chasemap_id']    = 0;
        $chase_data['chasemaptype']   = '0';
        $chase_data['chasemap_track'] = [];
        $result = $this->data_handler->save_chase_data($post_id, $chase_data);
        if (is_wp_error($result)) {
            return $result;
        }
        $this->clear_transients([$post_id]);

        return rest_ensure_response([
            'success' => true,
            'message' => __('Chase map removed.', 'stormchases'),
        ]);
    }

    private static function parse_gps_file(string $filepath, string $ext): array {
        switch ($ext) {
            case 'kml':  return self::parse_kml($filepath);
            case 'gpx':  return self::parse_gpx($filepath);
            case 'nmea': return self::parse_nmea($filepath);
        }
        return [];
    }

    private static function parse_kml(string $filepath): array {
        // Refuse to load very large XML files entirely into memory
        if ((@filesize($filepath) ?: 0) > 50 * 1024 * 1024) {
            Storm_Chases::debug_log('KML file too large for XML parsing (> 50 MB): ' . $filepath, 'warning');
            return [];
        }
        $content = @file_get_contents($filepath);
        if ($content === false) {
            return [];
        }
        $content = preg_replace('/<\s*!DOCTYPE[^>]*>/i', '', $content);
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        if (!$xml) {
            return [];
        }

        $points = [];

        // gx:Track — parallel <when> timestamps and <gx:coord> lon lat [alt]
        foreach ($xml->xpath('//*[local-name()="Track"]') as $track_el) {
            $whens  = $track_el->xpath('*[local-name()="when"]');
            $coords = $track_el->xpath('*[local-name()="coord"]');
            foreach ($coords as $i => $coord) {
                $parts = preg_split('/\s+/', trim((string) $coord));
                if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                    continue;
                }
                $lon = (float) $parts[0];
                $lat = (float) $parts[1];
                if ($lat === 0.0 && $lon === 0.0) {
                    continue;
                }
                $ts = null;
                if (isset($whens[$i])) {
                    $dt = date_create(trim((string) $whens[$i]));
                    if ($dt) {
                        $ts = $dt->getTimestamp();
                    }
                }
                $points[] = [$lat, $lon, $ts];
            }
        }

        // <LineString><coordinates> lon,lat[,alt] — no timestamps
        if (empty($points)) {
            foreach ($xml->xpath('//*[local-name()="LineString"]/*[local-name()="coordinates"]') as $el) {
                foreach (preg_split('/\s+/', trim((string) $el)) as $triplet) {
                    $parts = explode(',', trim($triplet));
                    if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                        $lon = (float) $parts[0];
                        $lat = (float) $parts[1];
                        if ($lat !== 0.0 || $lon !== 0.0) {
                            $points[] = [$lat, $lon, null];
                        }
                    }
                }
            }
        }

        return $points;
    }

    private static function parse_gpx(string $filepath): array {
        if ((@filesize($filepath) ?: 0) > 50 * 1024 * 1024) {
            Storm_Chases::debug_log('GPX file too large for XML parsing (> 50 MB): ' . $filepath, 'warning');
            return [];
        }
        $content = @file_get_contents($filepath);
        if ($content === false) {
            return [];
        }
        $content = preg_replace('/<\s*!DOCTYPE[^>]*>/i', '', $content);
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        if (!$xml) {
            return [];
        }

        $points = [];
        foreach ($xml->xpath('//*[local-name()="trkpt"]') as $trkpt) {
            $attrs = $trkpt->attributes();
            $lat   = (float) ($attrs['lat'] ?? 0);
            $lon   = (float) ($attrs['lon'] ?? 0);
            if ($lat === 0.0 && $lon === 0.0) {
                continue;
            }
            $ts       = null;
            $time_els = $trkpt->xpath('*[local-name()="time"]');
            if (!empty($time_els)) {
                $dt = date_create(trim((string) $time_els[0]));
                if ($dt) {
                    $ts = $dt->getTimestamp();
                }
            }
            $points[] = [$lat, $lon, $ts];
        }

        return $points;
    }

    private static function parse_nmea(string $filepath): array {
        $handle = @fopen($filepath, 'r');
        if (!$handle) {
            return [];
        }

        // Pass 1: count valid RMC sentences (u-blox outputs $GNRMC, older devices $GPRMC)
        $rmc_total = 0;
        while (($line = fgets($handle)) !== false) {
            $c = substr(ltrim($line), 0, 7);
            if ($c === '$GNRMC,' || $c === '$GPRMC,') {
                $rmc_total++;
            }
        }
        rewind($handle);

        if ($rmc_total === 0) {
            fclose($handle);
            return [];
        }

        // Target at most 5000 raw points (simplify_track will reduce further to 1500).
        $stride  = max(1, (int) ceil($rmc_total / 5000));
        $n       = 0;
        $points  = [];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (!preg_match('/^\$(GP|GN)RMC,/', $line)) {
                continue;
            }
            $n++;
            if ($stride > 1 && ($n % $stride) !== 1) {
                continue;
            }
            $line  = preg_replace('/\*[0-9A-Fa-f]{2}$/', '', $line);
            $parts = explode(',', $line);
            if (count($parts) < 10 || $parts[2] !== 'A') {
                continue;
            }
            $lat = self::nmea_to_decimal((string) $parts[3], (string) $parts[4]);
            $lon = self::nmea_to_decimal((string) $parts[5], (string) $parts[6]);
            if ($lat === 0.0 && $lon === 0.0) {
                continue;
            }
            $ts       = self::nmea_timestamp($parts[1] ?? '', $parts[9] ?? '');
            $points[] = [$lat, $lon, $ts];
        }
        fclose($handle);

        return $points;
    }

    private static function nmea_timestamp(string $time_str, string $date_str): ?int {
        if (strlen($time_str) < 6 || strlen($date_str) !== 6) {
            return null;
        }
        $hh   = substr($time_str, 0, 2);
        $mi   = substr($time_str, 2, 2);
        $ss   = substr($time_str, 4, 2);
        $dd   = substr($date_str, 0, 2);
        $mo   = substr($date_str, 2, 2);
        $yy   = (int) substr($date_str, 4, 2);
        $year = $yy + ($yy >= 80 ? 1900 : 2000);
        $dt   = DateTime::createFromFormat('Y-m-d H:i:s', "$year-$mo-$dd $hh:$mi:$ss", new DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : null;
    }

    private static function nmea_to_decimal(string $value, string $direction): float {
        if ($value === '' || !is_numeric(str_replace('.', '', $value))) {
            return 0.0;
        }
        $dot = strpos($value, '.');
        if ($dot === false || $dot < 3) {
            return 0.0;
        }
        // NMEA format: DDDMM.MMMM — degrees are everything left of the last two pre-decimal digits
        $degrees = (int) substr($value, 0, $dot - 2);
        $minutes = (float) substr($value, $dot - 2);
        $decimal = $degrees + $minutes / 60.0;
        if ($direction === 'S' || $direction === 'W') {
            $decimal = -$decimal;
        }
        return round($decimal, 6);
    }

    // Normalize PHP multi-file upload params to a flat list of individual file entries
    private static function normalize_file_params(array $file_param): array {
        if (is_array($file_param['name'])) {
            $files = [];
            foreach (array_keys($file_param['name']) as $i) {
                $files[] = [
                    'name'     => $file_param['name'][$i],
                    'tmp_name' => $file_param['tmp_name'][$i],
                    'error'    => $file_param['error'][$i],
                    'size'     => $file_param['size'][$i],
                    'type'     => $file_param['type'][$i],
                ];
            }
            return $files;
        }
        return [$file_param];
    }

    /**
     * Merge N parsed GPS tracks (each an array of [lat, lon, ts|null]) into one [lat, lon] array.
     * Sorts by timestamp when available; otherwise uses geographic proximity to order tracks.
     */
    // A GPS receiver that loses satellite lock can report one wildly wrong fix, or
    // get stuck repeating the same stale fix for the rest of a recording (both seen
    // in the wild — e.g. a receiver losing lock at the end of a log and then
    // reporting the same frozen coordinate, tens of miles away, for every remaining
    // second). Reject any point that implies an impossible jump from the last
    // accepted point in its segment, rather than drawing a line to/through it.
    // Segments are the null-separated pieces merge_tracks() already produced for
    // genuine multi-file gaps — nulls themselves are left untouched.
    private static function remove_distance_outliers(array $points, float $max_jump_miles = 10.0): array {
        $segments = [];
        $cur = [];
        foreach ($points as $pt) {
            if ($pt === null) {
                if (!empty($cur)) { $segments[] = $cur; $cur = []; }
            } else {
                $cur[] = $pt;
            }
        }
        if (!empty($cur)) { $segments[] = $cur; }

        $result = [];
        foreach ($segments as $seg) {
            $cleaned = [];
            $last = null;
            foreach ($seg as $pt) {
                if ($last === null || self::haversine_miles($last[0], $last[1], $pt[0], $pt[1]) <= $max_jump_miles) {
                    $cleaned[] = $pt;
                    $last = $pt;
                }
                // else: implausible jump from the last accepted point — drop it
            }
            if (empty($cleaned)) {
                continue;
            }
            if (!empty($result)) {
                $result[] = null;
            }
            foreach ($cleaned as $pt) {
                $result[] = $pt;
            }
        }
        return $result;
    }

    private static function merge_tracks(array $tracks): array {
        $tracks = array_values(array_filter($tracks));
        if (empty($tracks)) {
            return [];
        }
        if (count($tracks) === 1) {
            return array_map(fn($pt) => [$pt[0], $pt[1], $pt[2] ?? null], $tracks[0]);
        }

        // Detect whether any point has a usable timestamp
        $has_ts = false;
        foreach ($tracks as $track) {
            foreach ($track as $pt) {
                if (isset($pt[2]) && $pt[2] !== null) {
                    $has_ts = true;
                    break 2;
                }
            }
        }

        if ($has_ts) {
            $all = array_merge(...$tracks);
            usort($all, fn($a, $b) => ($a[2] ?? PHP_INT_MAX) <=> ($b[2] ?? PHP_INT_MAX));

            // Estimate typical interval from the first 200 consecutive pairs to set gap threshold
            $sample = [];
            for ($i = 1; $i < min(201, count($all)); $i++) {
                $ta = $all[$i - 1][2] ?? null;
                $tb = $all[$i][2] ?? null;
                if ($ta !== null && $tb !== null && $tb > $ta) {
                    $sample[] = $tb - $ta;
                }
            }
            sort($sample);
            $median        = !empty($sample) ? $sample[(int) (count($sample) / 2)] : 10;
            $gap_threshold = max(120, $median * 15); // ≥ 2 min or 15× stride interval

            $result  = [];
            $prev_ts = null;
            foreach ($all as $pt) {
                $ts = $pt[2] ?? null;
                if ($ts !== null && $ts === $prev_ts) {
                    continue; // exact duplicate timestamp — drop
                }
                if ($prev_ts !== null && $ts !== null && ($ts - $prev_ts) > $gap_threshold) {
                    $result[] = null; // gap sentinel: do not draw across this jump
                }
                $result[] = [$pt[0], $pt[1], $ts];
                $prev_ts  = $ts;
            }
            return $result;
        }

        return self::order_tracks_geographically($tracks);
    }

    // Find the concatenation order that minimises total end→start gap distance between tracks
    private static function order_tracks_geographically(array $tracks): array {
        $n = count($tracks);
        if ($n === 1) {
            return array_map(fn($pt) => [$pt[0], $pt[1], $pt[2] ?? null], $tracks[0]);
        }

        $indices    = range(0, $n - 1);
        $best_order = $indices;
        $best_dist  = PHP_FLOAT_MAX;

        foreach (self::permutations($indices) as $perm) {
            $dist = 0.0;
            for ($i = 0; $i < $n - 1; $i++) {
                $last  = end($tracks[$perm[$i]]);
                $first = $tracks[$perm[$i + 1]][0];
                $dist += self::haversine_miles((float)$last[0], (float)$last[1], (float)$first[0], (float)$first[1]);
            }
            if ($dist < $best_dist) {
                $best_dist  = $dist;
                $best_order = $perm;
            }
        }

        $result = [];
        foreach ($best_order as $idx) {
            foreach ($tracks[$idx] as $pt) {
                $result[] = [$pt[0], $pt[1], $pt[2] ?? null];
            }
        }
        return $result;
    }

    private static function permutations(array $items): array {
        if (count($items) <= 1) {
            return [$items];
        }
        $result = [];
        foreach ($items as $key => $item) {
            $rest = array_values(array_diff_key($items, [$key => null]));
            foreach (self::permutations($rest) as $perm) {
                $result[] = array_merge([$item], $perm);
            }
        }
        return $result;
    }

    /**
     * Strip points from the head and tail of a track that fall within any configured privacy zone.
     * Points in the middle of the track are never removed.
     */
    private static function apply_privacy_zones(array $points): array {
        $zones = get_option('storm_chases_privacy_zones', []);
        if (empty($zones) || empty($points)) {
            return $points;
        }

        $in_zone = function($pt) use ($zones): bool {
            if ($pt === null) return false;
            foreach ($zones as $zone) {
                $radius = floatval($zone['radius'] ?? 5.0);
                if (self::haversine_miles(
                    $pt[0], $pt[1],
                    floatval($zone['lat']),
                    floatval($zone['lon'])
                ) <= $radius) {
                    return true;
                }
            }
            return false;
        };

        $count = count($points);

        // Skip nulls and zone-matching points from the start
        $start = 0;
        while ($start < $count && ($points[$start] === null || $in_zone($points[$start]))) {
            $start++;
        }
        // Skip nulls and zone-matching points from the end
        $end = $count - 1;
        while ($end > $start && ($points[$end] === null || $in_zone($points[$end]))) {
            $end--;
        }
        if ($start > $end) {
            return [];
        }
        return array_slice($points, $start, $end - $start + 1);
    }

    private static function haversine_miles(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $R    = 3958.8;
        $dLat = ($lat2 - $lat1) * M_PI / 180.0;
        $dLon = ($lon2 - $lon1) * M_PI / 180.0;
        $a    = sin($dLat / 2) ** 2 +
                cos($lat1 * M_PI / 180.0) * cos($lat2 * M_PI / 180.0) * sin($dLon / 2) ** 2;
        return $R * 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
    }

    /**
     * Stride-decimate a track to at most $target points while always keeping first and last.
     */
    private static function simplify_track(array $points, int $target = 1500): array {
        // Split into segments on null sentinels, simplify each proportionally, rejoin
        $segments = [];
        $cur      = [];
        foreach ($points as $pt) {
            if ($pt === null) {
                if (!empty($cur)) { $segments[] = $cur; $cur = []; }
            } else {
                $cur[] = $pt;
            }
        }
        if (!empty($cur)) { $segments[] = $cur; }

        if (empty($segments)) { return []; }

        $total_non_null = array_sum(array_map('count', $segments));
        if ($total_non_null <= $target) { return $points; }

        $result = [];
        foreach ($segments as $seg_idx => $seg) {
            // Allocate proportional share of target to this segment (minimum 2 points)
            $seg_target = max(2, (int) round($target * count($seg) / $total_non_null));
            $stride     = max(1, (int) ceil(count($seg) / $seg_target));
            $simplified = [];
            for ($i = 0; $i < count($seg); $i += $stride) {
                $simplified[] = $seg[$i];
            }
            $last = end($seg);
            if (end($simplified) !== $last) { $simplified[] = $last; }
            if ($seg_idx > 0) { $result[] = null; } // re-insert gap between segments
            foreach ($simplified as $pt) { $result[] = $pt; }
        }
        return $result;
    }

    // chasemap_track is embedded directly into the public page for the historical
    // radar overlay's JS to read — a timestamp on every point would expose precise
    // time-of-day for the whole track. Keeps a real timestamp only roughly every
    // $interval_seconds (always including the first and last point of each
    // segment), rounded to the nearest 5 minutes, and nulls the rest; the frontend
    // interpolates the gaps from point position for radar-frame selection only —
    // the interpolated values are never stored.
    private static function sparsify_track_timestamps(array $points, int $interval_seconds = 600): array {
        $segments = [];
        $cur = [];
        foreach ($points as $pt) {
            if ($pt === null) {
                if (!empty($cur)) { $segments[] = $cur; $cur = []; }
            } else {
                $cur[] = $pt;
            }
        }
        if (!empty($cur)) { $segments[] = $cur; }

        $result = [];
        foreach ($segments as $seg) {
            if (!empty($result)) {
                $result[] = null;
            }
            $last_checkpoint_ts = null;
            $n = count($seg);
            foreach ($seg as $i => $pt) {
                $ts = $pt[2] ?? null;
                $is_checkpoint = $ts !== null && (
                    $last_checkpoint_ts === null
                    || ($ts - $last_checkpoint_ts) >= $interval_seconds
                    || $i === $n - 1
                );
                if ($is_checkpoint) {
                    $ts = (int) round($ts / 300) * 300; // round to nearest 5 minutes
                    $last_checkpoint_ts = $ts;
                    $result[] = [$pt[0], $pt[1], $ts];
                } else {
                    $result[] = [$pt[0], $pt[1], null];
                }
            }
        }
        return $result;
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
        $dry_run   = !empty($request->get_param('dry_run')) && $request->get_param('dry_run') == '1';

        foreach ($reports_by_date as $post_id => $reports) {
            $chase_data = $this->data_handler->get_chase_data($post_id);

            if ($overwrite) {
                $existing_count_for_post = count($chase_data['spotter_reports']);
            } else {
                $existing_count_for_post = 0;
            }

            if ($dry_run) {
                $existing_report_ids = array_column($chase_data['spotter_reports'], 'report_id');
                foreach ($reports as $report) {
                    if (!$overwrite && in_array($report['report_id'], $existing_report_ids)) {
                        $skipped++;
                    } else {
                        $inserted++;
                    }
                }
                continue;
            }

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

        if (!$dry_run) {
            $this->clear_transients(array_keys($reports_by_date));
        }
        $message = $dry_run
            ? sprintf(
                __('[DRY RUN] Would import %d reports across %d chase dates. %d would be skipped.', 'stormchases'),
                $inserted,
                count($reports_by_date),
                $skipped
              )
            : sprintf(
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
                'dry_run' => $dry_run,
                'inserted' => $inserted,
                'skipped' => $skipped,
                'skip_reasons' => $skip_reasons,
                'unmatched_dates' => $unmatched_dates
            ]
        ]);
    }

    public function add_mime_types($mimes) {
        $mimes['kml']  = 'application/vnd.google-earth.kml+xml';
        $mimes['gpx']  = 'application/gpx+xml';
        $mimes['nmea'] = 'text/plain';
        $mimes['csv']  = 'text/csv';
        $mimes['txt']  = 'text/plain';
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
        $chase_data    = $this->data_handler->get_chase_data($post->ID);
        $chasemap_url  = !empty($chase_data['chasemap_id']) ? wp_get_attachment_url($chase_data['chasemap_id']) : '';
        $chasemap_type = $chase_data['chasemaptype'] ?? '0';
        $has_track     = ($chasemap_type === '3') && !empty($chase_data['chasemap_track']);

        ob_start();
        ?>
        <div id="chase-details">
            <h2><?php esc_html_e('Storm Chase Details', 'stormchases'); ?></h2>
            <div id="chase-details-text">
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
                if (get_option('milestones_enable') && !empty($chase_data['chasems'])) {
                    printf('<b>%s:</b>', esc_html__('Milestones', 'stormchases'));
                    echo '<ul class="sc-milestones-list">';
                    foreach ($chase_data['chasems'] as $milestone) {
                        echo '<li>' . esc_html($milestone) . '</li>';
                    }
                    echo '</ul>';
                }
                if (get_option('spotter_reports_enable') && !empty($chase_data['spotter_reports'])) {
                    printf('<b>%s:</b> %s<br/>', esc_html__('Spotter Network Reports', 'stormchases'), esc_html(count($chase_data['spotter_reports'])));
                    echo '<ul>';
                    foreach ($chase_data['spotter_reports'] as $index => $report) {
                        $report_id = $report['report_id'] ?? $index;
                        $weather_type = sc_report_weather_type($report)['label'];
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
            <?php if ($has_track) : ?>
                <div id="chasemap" class="sc-leaflet-map sc-track-map" style="height: 400px; width: 100%; max-width: 800px;"></div>
                <div class="sc-track-timeline" id="sc-track-timeline" style="display:none; max-width: 800px; margin-bottom: 20px;">
                    <input type="range" id="sc-track-slider" min="1" max="100" value="100">
                    <div class="sc-track-timeline-labels">
                        <span><?php esc_html_e('Start', 'stormchases'); ?></span>
                        <span id="sc-track-position" class="sc-track-position"></span>
                        <span><?php esc_html_e('End', 'stormchases'); ?></span>
                    </div>
                    <div class="sc-radar-control" id="sc-radar-control" style="display:none;">
                        <label>
                            <input type="checkbox" id="sc-radar-toggle">
                            <?php esc_html_e('Show radar (NEXRAD composite)', 'stormchases'); ?>
                        </label>
                        <span id="sc-radar-label" class="sc-radar-label"></span>
                    </div>
                </div>
            <?php elseif ($chasemap_url && $chasemap_type === '2') : ?>
                <img src="<?php echo esc_url($chasemap_url); ?>" style="max-width: 100%; height: auto; margin-bottom: 20px;" alt="<?php esc_attr_e('Chase Map', 'stormchases'); ?>" />
            <?php endif; ?>
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

    // Single source of truth for deriving the YYYYMMDD chase date from a post's
    // post_date — used to keep the slug and the chasedate meta from ever being
    // able to disagree with each other or with the publish date. Returns null
    // for an empty/unset post_date or a value that isn't an actual calendar date.
    private static function derive_chasedate_from_post_date(string $post_date): ?string {
        if (empty($post_date) || $post_date === '0000-00-00 00:00:00') {
            return null;
        }
        $timestamp = strtotime($post_date);
        if ($timestamp === false) {
            return null;
        }
        $ymd = date('Ymd', $timestamp);
        $year  = (int) substr($ymd, 0, 4);
        $month = (int) substr($ymd, 4, 2);
        $day   = (int) substr($ymd, 6, 2);
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        return $ymd;
    }

    public function set_post_name_from_chasedate($post_id, $post, $update) {
        if ($post->post_type !== self::POST_TYPE || defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Slug always mirrors the post's publish date
        $chasedate = self::derive_chasedate_from_post_date($post->post_date);
        if ($chasedate === null) {
            return;
        }

        remove_action('save_post_' . self::POST_TYPE, [$this, 'set_post_name_from_chasedate'], 20);
        $result = wp_update_post([
            'ID' => $post_id,
            'post_name' => $chasedate,
        ], true);
        add_action('save_post_' . self::POST_TYPE, [$this, 'set_post_name_from_chasedate'], 20, 3);

        if (is_wp_error($result)) {
            set_transient('storm_chases_errors_' . $post_id, [__('Failed to set post name.', 'stormchases')], 60);
            return;
        }

        // wp_update_post() silently appends a suffix (e.g. "-2") if another post
        // already owns this exact slug — surface that instead of leaving the
        // slug, chase date, and publish date quietly out of sync.
        $saved_post = get_post($post_id);
        if ($saved_post && $saved_post->post_name !== $chasedate) {
            set_transient('storm_chases_errors_' . $post_id, [
                sprintf(
                    /* translators: 1: intended YYYYMMDD slug, 2: slug actually saved */
                    __('Could not set the URL slug to %1$s — another chase may already use this date. Current slug: %2$s.', 'stormchases'),
                    $chasedate,
                    $saved_post->post_name
                ),
            ], 60);
        }
    }

    public function fix_preview_post_link($link, $post) {
        if ($post->post_type !== self::POST_TYPE) {
            return $link;
        }
        $preview_nonce = wp_create_nonce('post_preview_' . $post->ID);
        return add_query_arg(
            [
                'preview'        => 'true',
                'preview_id'     => $post->ID,
                'preview_nonce'  => $preview_nonce,
            ],
            home_url('/')
        );
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
                foreach ($_POST['tornadoes'] as $tornado) {
                    // Re-indexed sequentially (not by the posted key) so storage always matches
                    // submission order — the admin UI's drag-to-reorder relies on this.
                    $tornadoes[] = [
                        'name' => isset($tornado['name']) ? sanitize_text_field($tornado['name']) : '',
                        'lat' => isset($tornado['lat']) ? floatval($tornado['lat']) : 0,
                        'lon' => isset($tornado['lon']) ? floatval($tornado['lon']) : 0,
                        'ef_rating' => isset($tornado['ef_rating']) ? sanitize_text_field($tornado['ef_rating']) : 'Unrated',
                        'start_time' => isset($tornado['start_time']) ? sanitize_text_field($tornado['start_time']) : '',
                        'end_time' => isset($tornado['end_time']) ? sanitize_text_field($tornado['end_time']) : '',
                        'end_lat' => isset($tornado['end_lat']) ? floatval($tornado['end_lat']) : 0,
                        'end_lon' => isset($tornado['end_lon']) ? floatval($tornado['end_lon']) : 0,
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
        // chasedate always follows the post's publish date — same derivation used
        // for the slug in set_post_name_from_chasedate(), so the two can't diverge.
        $derived_chasedate = self::derive_chasedate_from_post_date($post->post_date);
        if ($derived_chasedate !== null) {
            $chase_data['chasedate'] = $derived_chasedate;
        } elseif (isset($_POST['chasedate'])) {
            $posted = sanitize_text_field($_POST['chasedate']);
            if (preg_match('/^\d{8}$/', $posted) && $posted !== '19700213') {
                $chase_data['chasedate'] = $posted;
            }
        }
        $chase_data['chasestates'] = isset($_POST['chasestates']) ? sanitize_text_field($_POST['chasestates']) : $chase_data['chasestates'];
        $chase_data['chasepartners'] = isset($_POST['chasepartners']) ? sanitize_text_field($_POST['chasepartners']) : $chase_data['chasepartners'];
        $chase_data['chasechasers'] = isset($_POST['chasechasers']) ? sanitize_text_field($_POST['chasechasers']) : $chase_data['chasechasers'];
        $chase_data['chasemiles'] = isset($_POST['chasemiles']) ? absint($_POST['chasemiles']) : $chase_data['chasemiles'];
        $chase_data['chasehail'] = isset($_POST['chasehail']) ? floatval($_POST['chasehail']) : $chase_data['chasehail'];
        $chase_data['chasewind'] = isset($_POST['chasewind']) ? absint($_POST['chasewind']) : $chase_data['chasewind'];
        // chasems is submitted as chasems[] (one bullet per entry); sanitize_milestones()
        // does the real cleanup when this is stored via save_chase_data() below.
        $chase_data['chasems'] = isset($_POST['chasems']) ? (array) $_POST['chasems'] : $chase_data['chasems'];

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

        // Update best-chase option for this year
        if (!empty($chase_data['chasedate']) && preg_match('/^\d{8}$/', $chase_data['chasedate'])) {
            $year = substr($chase_data['chasedate'], 0, 4);
            $best_chases = get_option('storm_chases_best_chases', []);
            if (!empty($_POST['is_best_chase']) && $_POST['is_best_chase'] == '1') {
                $best_chases[$year] = $post_id;
            } elseif (isset($best_chases[$year]) && $best_chases[$year] == $post_id) {
                unset($best_chases[$year]);
            }
            update_option('storm_chases_best_chases', $best_chases);
        }

        $this->clear_transients([$post_id]);
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

    public function clear_transients($post_ids = []) {
        global $wpdb;

        // Ensure $post_ids is an array
        $post_ids = (array) $post_ids;
        $post_ids = array_filter(array_map('absint', $post_ids));

        // Define all transient prefixes to clear
        $prefixes = [
            'storm_chases_stats_%',
            'storm_chases_archive_%',
            'sc_archive_v2_%',
            'sc_stats_v12_%'
        ];

        // Delete transients for specific post IDs
        foreach ($post_ids as $post_id) {
            // Delete specific post transient
            delete_transient('storm_chases_stats_' . $post_id);
            // Delete transients matching patterns for this post
            foreach ($prefixes as $prefix) {
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                        '_transient_' . str_replace('%', $post_id . '%', $prefix),
                        '_transient_timeout_' . str_replace('%', $post_id . '%', $prefix)
                    )
                );
            }
        }

        foreach ($prefixes as $prefix) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    '_transient_' . $prefix,
                    '_transient_timeout_' . $prefix
                )
            );
        }

        Storm_Chases::debug_log('Cleared storm chase transients for posts: ' . (empty($post_ids) ? 'all' : implode(', ', $post_ids)), 'info');
    }
}
