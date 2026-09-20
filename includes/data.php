<?php
if (!defined('ABSPATH')) {
    exit;
}

class StormChasesData {
    // Per-request cache, keyed by post_id — deliberately static (class-level, shared
    // across every `new StormChasesData()` instance, since this class isn't a singleton
    // and gets instantiated fresh all over the codebase). A single page can render more
    // than one block/shortcode over the same chases (e.g. a Chase Stats block and a
    // Tornado Map block together), and without this every one of them independently
    // re-fetches and re-unserializes the same post's chase_data from scratch — real
    // savings given real chase_data blobs run tens of KB each. Cleared per-request only
    // (never persisted), and kept in sync by save_chase_data() below so a save followed
    // by an immediate re-read in the same request never sees stale data.
    private static $request_cache = [];

    private $meta_fields = [
        'chasedate' => ['sanitize' => 'sanitize_text_field', 'required' => true, 'type' => 'string'],
        'chasestates' => ['sanitize' => 'sanitize_text_field', 'required' => true, 'type' => 'string'],
        'chasepartners' => ['sanitize' => 'sanitize_text_field', 'required' => false, 'type' => 'string'],
        'chasechasers' => ['sanitize' => 'sanitize_text_field', 'required' => false, 'type' => 'string'],
        'chasemiles' => ['sanitize' => 'sanitize_int', 'required' => false, 'type' => 'integer'],
        'chasehail' => ['sanitize' => 'sanitize_float', 'required' => false, 'type' => 'number'],
        'chasewind' => ['sanitize' => 'sanitize_int', 'required' => false, 'type' => 'integer'],
        'chasems' => ['sanitize' => 'sanitize_milestones', 'required' => false, 'type' => 'array', 'default' => []],
        'chasemap_id' => ['sanitize' => 'sanitize_int', 'required' => false, 'type' => 'integer'],
        'chasemaptype' => ['sanitize' => 'sanitize_text_field', 'required' => false, 'type' => 'string'],
        'chasetornado' => ['sanitize' => 'sanitize_int', 'required' => false, 'type' => 'integer'],
        'tornadoes' => ['sanitize' => 'sanitize_tornado_data', 'required' => false, 'type' => 'array'],
        'spotter_reports' => ['sanitize' => 'sanitize_spotter_reports', 'required' => false, 'type' => 'array'],
        'chasemap_track' => ['sanitize' => 'sanitize_track', 'required' => false, 'type' => 'array'],
        'chase_type' => ['sanitize' => 'sanitize_chase_type', 'required' => false, 'type' => 'string', 'default' => 'Convective'],
        'landfalls' => ['sanitize' => 'sanitize_landfall_data', 'required' => false, 'type' => 'array'],
        'storm_mode' => ['sanitize' => 'sanitize_storm_mode', 'required' => false, 'type' => 'array', 'default' => []],
        'snowfall_reports' => ['sanitize' => 'sanitize_snowfall_data', 'required' => false, 'type' => 'array'],
    ];

    public function get_meta_fields() {
        return $this->meta_fields;
    }

    // Single source of truth for valid Chase Type values — a hardcoded, code-extensible
    // enum (matching the ef_rating/report-type pattern elsewhere in this plugin), not a
    // Settings-page-configurable list. Adding a future type (e.g. Fire, Monsoon) is a code
    // change with its own field set, same as any other type here. Convective is the
    // default for both new and every pre-2.0.0 chase (which had no concept of chase type).
    public static function get_chase_types(): array {
        return ['Convective', 'Hurricane', 'Winter', 'Other'];
    }

    // Saffir-Simpson category plus the two sub-hurricane-strength stages a landfall can
    // also be logged at. Kept as a method (not a class const) to match get_chase_types()'s
    // pattern, and because sanitize_landfall_data() below needs it as a lookup.
    public static function get_landfall_categories(): array {
        return ['Tropical Depression', 'Tropical Storm', 'Category 1', 'Category 2', 'Category 3', 'Category 4', 'Category 5', 'Unknown'];
    }

    // Storm mode/structure classification for Convective chases — checkbox group, not a
    // single select, since a chase day can transition between modes (e.g. discrete
    // supercells congealing into a QLCS later in the evening).
    public static function get_storm_modes(): array {
        return ['Supercell - LP', 'Supercell - Classic', 'Supercell - HP', 'Supercell - Hybrid', 'QLCS / Squall Line', 'Multicell Cluster', 'Multicell', 'Landspout / Non-supercell', 'Tropical / Landfalling Remnant', 'Other'];
    }

    public function get_chase_data($post_id) {
        $post_id = (int) $post_id;
        if (isset(self::$request_cache[$post_id])) {
            return self::$request_cache[$post_id];
        }

        $chase_data = get_post_meta($post_id, 'chase_data', true);

        // Handle both JSON and PHP-serialized data
        if (is_string($chase_data) && !empty($chase_data)) {
            // Try JSON first
            $json_decoded = json_decode($chase_data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json_decoded)) {
                $chase_data = $json_decoded;
            } else {
                // Try PHP unserialize
                $unserialized = @unserialize($chase_data);
                if ($unserialized !== false && is_array($unserialized)) {
                    $chase_data = $unserialized;
                } else {
                    $chase_data = [];
                }
            }
        } elseif (!is_array($chase_data)) {
            $chase_data = [];
        }

        $defaults = [
            'chasedate' => '19700213',
            'chasestates' => '',
            'chasepartners' => 'Solo',
            'chasechasers' => 'None',
            'chasemiles' => 0,
            'chasehail' => 0,
            'chasewind' => 0,
            'chasems' => [],
            'chasemap_id' => 0,
            'chasemaptype' => '0',
            'chasetornado' => 0,
            'tornadoes' => [],
            'spotter_reports' => [],
            'chasemap_track' => [],
            'chase_type' => 'Convective',
            'landfalls' => [],
            'storm_mode' => [],
            'snowfall_reports' => [],
        ];

        $chase_data = wp_parse_args($chase_data, $defaults);
        $result = $this->sanitize_chase_data($chase_data);
        self::$request_cache[$post_id] = $result;
        return $result;
    }

    public function save_chase_data($post_id, $chase_data) {
        if (!is_array($chase_data)) {
            return new WP_Error('invalid_data', __('Invalid chase data format.', 'stormchases'));
        }

        if (!isset($chase_data['tornadoes'])) {
            $chase_data['tornadoes'] = [];
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'storm_chase') {
            return new WP_Error('invalid_post', __('Invalid post ID or post type.', 'stormchases'));
        }

        $sanitized_data = $this->sanitize_chase_data($chase_data);
        
        $serialized_data = serialize($sanitized_data);
        if ($serialized_data === false) {
            return new WP_Error('serialization_failed', __('Failed to serialize chase data.', 'stormchases'));
        }

        $result = update_post_meta($post_id, 'chase_data', $serialized_data);

        // Keep the per-request cache in sync so an immediate re-read (elsewhere in the
        // same request) after a save doesn't see stale data.
        self::$request_cache[(int) $post_id] = $sanitized_data;

        return true;
    }

    public function sanitize_chase_data($data) {
        $sanitized = [];

        // Sanitize fields defined in meta_fields
        foreach ($this->meta_fields as $key => $config) {
            if ($key === 'tornadoes' || $key === 'spotter_reports' || $key === 'landfalls' || $key === 'storm_mode' || $key === 'snowfall_reports') {
                // Array fields — handled explicitly below, bypassing the generic
                // string-sanitization branch (same pattern as tornadoes/spotter_reports).
                continue;
            }

            if (isset($data[$key])) {
                $value = $data[$key];
                if ($config['type'] === 'string') {
                    $value = is_string($value) ? wp_strip_all_tags(wp_unslash($value)) : '';
                    $value = str_replace(["\r", "\n"], ' ', $value);
                    $value = call_user_func([$this, $config['sanitize']], $value);
                } else {
                    $value = call_user_func([$this, $config['sanitize']], $value);
                }
                $sanitized[$key] = $value;
            } else {
                // Use default value from config if not set
                $sanitized[$key] = isset($config['default']) ? $config['default'] : '';
            }
        }

        $sanitized['tornadoes'] = isset($data['tornadoes']) && is_array($data['tornadoes']) ? $this->sanitize_tornado_data($data['tornadoes']) : [];
        $sanitized['spotter_reports'] = isset($data['spotter_reports']) && is_array($data['spotter_reports']) ? $this->sanitize_spotter_reports($data['spotter_reports']) : [];
        $sanitized['chasemap_track'] = isset($data['chasemap_track']) && is_array($data['chasemap_track']) ? $this->sanitize_track($data['chasemap_track']) : [];
        $sanitized['landfalls'] = isset($data['landfalls']) && is_array($data['landfalls']) ? $this->sanitize_landfall_data($data['landfalls']) : [];
        $sanitized['storm_mode'] = isset($data['storm_mode']) && is_array($data['storm_mode']) ? $this->sanitize_storm_mode($data['storm_mode']) : [];
        $sanitized['snowfall_reports'] = isset($data['snowfall_reports']) && is_array($data['snowfall_reports']) ? $this->sanitize_snowfall_data($data['snowfall_reports']) : [];

        return wp_parse_args($sanitized, [
            'chasedate' => '19700213',
            'chasestates' => '',
            'chasepartners' => 'Solo',
            'chasechasers' => 'None',
            'chasemiles' => 0,
            'chasehail' => 0,
            'chasewind' => 0,
            'chasems' => [],
            'chasemap_id' => 0,
            'chasemaptype' => '0',
            'chasetornado' => 0,
            'chase_type' => 'Convective',
        ]);
    }

    public function validate_chase_data($data) {
        $errors = [];
        foreach ($this->meta_fields as $key => $config) {
            if ($config['required'] && empty($data[$key])) {
                /* translators: %s: name of the required field */
                $errors[] = sprintf(__('%s is required.', 'stormchases'), ucfirst(str_replace('chase', '', $key)));
            }
        }
        if (!empty($data['chasedate']) && !preg_match('/^\d{8}$/', $data['chasedate'])) {
            $errors[] = __('Chase date must be in YYYYMMDD format.', 'stormchases');
        }
        return $errors;
    }

    public function sanitize_tornado_data($tornadoes) {
        if (!is_array($tornadoes)) {
            return [];
        }
        $sanitized = [];
        foreach ($tornadoes as $index => $tornado) {
            $sanitized[$index] = [
                'name' => isset($tornado['name']) ? $this->sanitize_for_json(wp_unslash($tornado['name'])) : '',
                'lat' => isset($tornado['lat']) ? floatval($tornado['lat']) : 0,
                'lon' => isset($tornado['lon']) ? floatval($tornado['lon']) : 0,
                'ef_rating' => isset($tornado['ef_rating']) ? sanitize_text_field(wp_unslash($tornado['ef_rating'])) : 'Unrated',
                'start_time' => isset($tornado['start_time']) ? $this->sanitize_for_json(wp_unslash($tornado['start_time'])) : '',
                'end_time' => isset($tornado['end_time']) ? $this->sanitize_for_json(wp_unslash($tornado['end_time'])) : '',
                'end_lat' => isset($tornado['end_lat']) ? floatval($tornado['end_lat']) : 0,
                'end_lon' => isset($tornado['end_lon']) ? floatval($tornado['end_lon']) : 0,
                'photo_id' => isset($tornado['photo_id']) ? absint($tornado['photo_id']) : 0,
                'photogenic' => !empty($tornado['photogenic']),
            ];
        }
        return array_values($sanitized);
    }

    // Falls back to 'Convective' for anything not in get_chase_types() — this is a real
    // branch point for admin-UI field visibility and public display logic (unlike most
    // string fields here, which are just display text), so unlike ef_rating (trusted to
    // the <select> options and left as free text) this one is strictly validated rather
    // than just sanitized.
    public function sanitize_chase_type($value) {
        $value = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
        return in_array($value, self::get_chase_types(), true) ? $value : 'Convective';
    }

    // Mirrors sanitize_tornado_data() — one array entry per hurricane landfall logged on
    // this chase (a storm can weaken/restrengthen between multiple landfalls on one
    // chase, hence wind_speed/category/pressure are per-landfall, not chase-level).
    public function sanitize_landfall_data($landfalls) {
        if (!is_array($landfalls)) {
            return [];
        }
        $sanitized = [];
        foreach ($landfalls as $index => $landfall) {
            $category = isset($landfall['category']) ? sanitize_text_field(wp_unslash($landfall['category'])) : 'Unknown';
            $sanitized[$index] = [
                'name' => isset($landfall['name']) ? $this->sanitize_for_json(wp_unslash($landfall['name'])) : '',
                'lat' => isset($landfall['lat']) ? floatval($landfall['lat']) : 0,
                'lon' => isset($landfall['lon']) ? floatval($landfall['lon']) : 0,
                'time' => isset($landfall['time']) ? $this->sanitize_for_json(wp_unslash($landfall['time'])) : '',
                'wind_speed' => isset($landfall['wind_speed']) ? absint($landfall['wind_speed']) : 0,
                'category' => in_array($category, self::get_landfall_categories(), true) ? $category : 'Unknown',
                'pressure' => isset($landfall['pressure']) ? absint($landfall['pressure']) : 0,
            ];
        }
        return array_values($sanitized);
    }

    // Checkbox-group field, not a single select — values are validated against
    // get_storm_modes() and deduped, silently dropping anything unrecognized rather than
    // rejecting the whole save (matches the general tolerance of this sanitization layer).
    public function sanitize_storm_mode($modes): array {
        if (!is_array($modes)) {
            return [];
        }
        $valid = self::get_storm_modes();
        $sanitized = [];
        foreach ($modes as $mode) {
            $mode = is_string($mode) ? sanitize_text_field(wp_unslash($mode)) : '';
            if ($mode !== '' && in_array($mode, $valid, true) && !in_array($mode, $sanitized, true)) {
                $sanitized[] = $mode;
            }
        }
        return $sanitized;
    }

    // Mirrors sanitize_landfall_data() — one array entry per snowfall report logged on a
    // Winter-type chase. 'location' is a free-text town/place name (deliberately not
    // reverse-geocoded from lat/lon — this plugin makes no live third-party geocoding
    // calls anywhere else, and a manually-typed name matches the existing tornado/landfall
    // 'name' field precedent) since a bare lat/lon means little to a reader at a glance.
    public function sanitize_snowfall_data($entries): array {
        if (!is_array($entries)) {
            return [];
        }
        $sanitized = [];
        foreach ($entries as $index => $entry) {
            $sanitized[$index] = [
                'location' => isset($entry['location']) ? $this->sanitize_for_json(wp_unslash($entry['location'])) : '',
                'lat' => isset($entry['lat']) ? floatval($entry['lat']) : 0,
                'lon' => isset($entry['lon']) ? floatval($entry['lon']) : 0,
                'time' => isset($entry['time']) ? $this->sanitize_for_json(wp_unslash($entry['time'])) : '',
                'depth' => isset($entry['depth']) ? floatval($entry['depth']) : 0,
            ];
        }
        return array_values($sanitized);
    }

    public function sanitize_spotter_reports($reports) {
        if (!is_array($reports)) {
            return [];
        }

        $sanitized_reports = [];
        foreach ($reports as $index => $report) {
            $sanitized = $this->sanitize_spotter_report($report);
            if (!empty($sanitized)) {
                $sanitized_reports[] = $sanitized;
            }
        }
        return array_values($sanitized_reports);
    }

    public function sanitize_spotter_report($report) {
        if (!is_array($report)) {
            return [];
        }

        $defaults = [
            'report_id' => '',
            'type' => 'S',
            'timestamp' => '',
            'lat' => 0,
            'lon' => 0,
            'narrative' => '',
            'tornado' => 0,
            'funnelcloud' => 0,
            'wallcloud' => 0,
            'hail' => 0,
            'hailsize' => 0,
            'windspeed' => 0,
            'damage' => 0,
            'city' => '',
            'cwa' => 'UNKNOWN',
        ];

        $report = wp_parse_args($report, $defaults);

        $sanitized = [
            'report_id' => sanitize_text_field(wp_unslash($report['report_id'])),
            'type' => sanitize_text_field(wp_unslash($report['type'])),
            'timestamp' => $this->is_valid_date($report['timestamp']) ? sanitize_text_field(wp_unslash($report['timestamp'])) : '',
            'lat' => is_numeric($report['lat']) && $report['lat'] != 0 ? floatval($report['lat']) : 0,
            'lon' => is_numeric($report['lon']) && $report['lon'] != 0 ? floatval($report['lon']) : 0,
            'narrative' => sanitize_textarea_field(wp_unslash($report['narrative'])),
            'tornado' => absint($report['tornado']),
            'funnelcloud' => absint($report['funnelcloud']),
            'wallcloud' => absint($report['wallcloud']),
            'hail' => absint($report['hail']),
            'hailsize' => is_numeric($report['hailsize']) ? floatval($report['hailsize']) : 0,
            'windspeed' => is_numeric($report['windspeed']) ? floatval($report['windspeed']) : 0,
            'damage' => absint($report['damage']),
            'city' => sanitize_text_field(wp_unslash($report['city'])),
            'cwa' => !empty($report['cwa']) ? sanitize_text_field(wp_unslash($report['cwa'])) : 'UNKNOWN',
        ];

        if (empty($sanitized['report_id']) || empty($sanitized['timestamp'])) {
            return [];
        }

        return $sanitized;
    }

    public function sanitize_track($track): array {
        if (!is_array($track)) {
            return [];
        }
        $out = [];
        foreach ($track as $pt) {
            if ($pt === null) {
                // Preserve gap sentinel, but skip consecutive or leading nulls
                if (!empty($out) && end($out) !== null) {
                    $out[] = null;
                }
                continue;
            }
            if (!is_array($pt) || count($pt) < 2) {
                continue;
            }
            $lat = round(floatval($pt[0]), 5);
            $lon = round(floatval($pt[1]), 5);
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                continue;
            }
            if ($lat === 0.0 && $lon === 0.0) {
                continue;
            }
            // Timestamp (Unix seconds, UTC) if the source had one — used to sync the
            // historical radar overlay to where the track was at a given moment.
            $ts = isset($pt[2]) && is_numeric($pt[2]) ? (int) $pt[2] : null;
            $out[] = [$lat, $lon, $ts];
        }
        // Strip trailing null
        while (!empty($out) && end($out) === null) {
            array_pop($out);
        }
        return $out;
    }

    // Accepts either a new-format array of bullet strings or a legacy single
    // freeform string (pre-1.9.0), migrating the latter into a one-item list
    // rather than losing or auto-splitting existing content.
    public function sanitize_milestones($value): array {
        if (is_string($value)) {
            $value = trim($value) !== '' ? [$value] : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = is_string($item) ? wp_unslash($item) : (string) $item;
            $item = str_replace(["\r", "\n"], ' ', $item);
            $item = sanitize_text_field($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    public function sanitize_int($value) {
        return absint($value);
    }

    public function sanitize_float($value) {
        return is_numeric($value) ? floatval($value) : 0;
    }

    public function sanitize_text_field($value) {
        return sanitize_text_field(wp_unslash($value));
    }

    public function sanitize_textarea_field($value) {
        return sanitize_textarea_field(wp_unslash($value));
    }

    private function sanitize_for_json($value) {
        $value = wp_strip_all_tags(wp_unslash($value));
        $value = str_replace(["\r", "\n"], ' ', $value);
        return sanitize_text_field($value);
    }

    private function is_valid_date($date) {
        if (empty($date)) {
            return false;
        }
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        return $d && $d->format('Y-m-d H:i:s') === $date;
    }
}
