<?php
if (!defined('ABSPATH')) {
    exit;
}

class StormChasesData {
    private $meta_fields = [
        'chasedate' => ['sanitize' => 'sanitize_text_field', 'required' => true, 'type' => 'string'],
        'chasestates' => ['sanitize' => 'sanitize_text_field', 'required' => true, 'type' => 'string'],
        'chasepartners' => ['sanitize' => 'sanitize_text_field', 'required' => false, 'type' => 'string'],
        'chasechasers' => ['sanitize' => 'sanitize_text_field', 'required' => false, 'type' => 'string'],
        'chasemiles' => ['sanitize' => 'sanitize_int', 'required' => false, 'type' => 'integer'],
        'chasehail' => ['sanitize' => 'sanitize_float', 'required' => false, 'type' => 'number'],
        'chasewind' => ['sanitize' => 'sanitize_int', 'required' => false, 'type' => 'integer'],
        'chasems' => ['sanitize' => 'sanitize_textarea_field', 'required' => false, 'type' => 'string'],
        'chasemap_id' => ['sanitize' => 'sanitize_int', 'required' => false, 'type' => 'integer'],
        'chasemaptype' => ['sanitize' => 'sanitize_text_field', 'required' => false, 'type' => 'string'],
        'chasetornado' => ['sanitize' => 'sanitize_int', 'required' => false, 'type' => 'integer'],
        'tornadoes' => ['sanitize' => 'sanitize_tornado_data', 'required' => false, 'type' => 'array'],
        'spotter_reports' => ['sanitize' => 'sanitize_spotter_reports', 'required' => false, 'type' => 'array'],
    ];

    public function get_meta_fields() {
        return $this->meta_fields;
    }

    public function get_chase_data($post_id) {
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
            'chasems' => '',
            'chasemap_id' => 0,
            'chasemaptype' => '0',
            'chasetornado' => 0,
            'tornadoes' => [],
            'spotter_reports' => [],
        ];

        $chase_data = wp_parse_args($chase_data, $defaults);
        return $this->sanitize_chase_data($chase_data);
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

        return true;
    }

    public function sanitize_chase_data($data) {
        $sanitized = [];

        // Sanitize fields defined in meta_fields
        foreach ($this->meta_fields as $key => $config) {
            if ($key === 'tornadoes' || $key === 'spotter_reports') {
                // Skip tornadoes and spotter_reports here; handle them explicitly below
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

        return wp_parse_args($sanitized, [
            'chasedate' => '19700213',
            'chasestates' => '',
            'chasepartners' => 'Solo',
            'chasechasers' => 'None',
            'chasemiles' => 0,
            'chasehail' => 0,
            'chasewind' => 0,
            'chasems' => '',
            'chasemap_id' => 0,
            'chasemaptype' => '0',
            'chasetornado' => 0,
        ]);
    }

    public function validate_chase_data($data) {
        $errors = [];
        foreach ($this->meta_fields as $key => $config) {
            if ($config['required'] && empty($data[$key])) {
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