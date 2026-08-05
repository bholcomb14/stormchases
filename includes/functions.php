<?php
if (!defined('ABSPATH')) {
    exit;
}

function sc_modify_pre_get_posts($query) {
    if (!is_admin() && $query->is_main_query()) {
        $post_types = (array) $query->get('post_type');

        if ($query->is_front_page() || $query->is_home()) {
            #$post_types[] = StormChaseTemplate::POST_TYPE;
            $post_types = ['post', StormChaseTemplate::POST_TYPE];
            $query->set('post_type', array_unique($post_types));
            Storm_Chases::debug_log('Modified main feed query to include storm_chase', 'info');
        } elseif ($query->is_category() || $query->is_tag()) {
            $post_types = ['post', StormChaseTemplate::POST_TYPE];
            $query->set('post_type', $post_types);
            Storm_Chases::debug_log('Modified tag/category query to include storm_chase', 'info');
        } elseif ($query->is_post_type_archive('storm_chase')) {
            $query->set('posts_per_page', 20);
            $query->set('meta_key', 'chasedate');
            $query->set('orderby', 'meta_value');
            $query->set('order', 'DESC');
            Storm_Chases::debug_log('Modified storm_chase archive query', 'info');
        }
    }
    return $query;
}
add_filter('pre_get_posts', 'sc_modify_pre_get_posts');

function sc_modify_request($query_vars) {
    if (isset($query_vars['feed']) && !isset($query_vars['post_type'])) {
        $query_vars['post_type'] = ['post', StormChaseTemplate::POST_TYPE];
        Storm_Chases::debug_log('Modified feed query to include storm_chase', 'info');
    }
    return $query_vars;
}
add_filter('request', 'sc_modify_request');

function chase_archive_shortcode($atts) {
    $atts = shortcode_atts(
        [
            'year' => '',
            'show' => 2000,
            'chasers' => '',
            'states' => '',
            'tornadoes' => ''
        ],
        $atts,
        'scarchive'
    );

    $year = sanitize_text_field($atts['year'] ?? '');
    $show = max(1, min(1000, (int) ($atts['show'] ?? 2000)));
    $chasers = sanitize_text_field($atts['chasers'] ?? '');
    $states = sanitize_text_field($atts['states'] ?? '');
    $tornadoes = sanitize_text_field($atts['tornadoes'] ?? '');

    $transient_key = 'sc_archive_v2_' . ($year ?: 'all') . '_' . $show;
    $output = get_transient($transient_key);

    if (false === $output) {
        $args = [
            'post_type' => 'storm_chase',
            'post_status' => 'publish',
            'posts_per_page' => $show,
            'meta_key' => 'chasedate',
            'orderby' => 'meta_value',
            'order' => 'DESC',
            'meta_query' => [],
        ];

        if ($year && preg_match('/^\d{4}$/', $year)) {
            $args['meta_query'][] = [
                'key' => 'chasedate',
                'value' => '^' . $year . '[0-1][0-9][0-3][0-9]',
                'compare' => 'REGEXP',
            ];
        }

        if (!empty($chasers)) {
            $args['meta_query'][] = [
                'key' => 'chasechasers',
                'value' => $chasers,
                'compare' => 'LIKE',
            ];
        }

        if (!empty($states)) {
            $args['meta_query'][] = [
                'key' => 'chasestates',
                'value' => $states,
                'compare' => 'LIKE',
            ];
        }

        if ($tornadoes === 'yes') {
            $args['meta_query'][] = [
                'key' => 'chasetornado',
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ];
        }

        if (!empty($args['meta_query']) && count($args['meta_query']) > 1) {
            $args['meta_query']['relation'] = 'AND';
        }

        $query = new WP_Query($args);
        $data_handler = new StormChasesData();
        ob_start();
        ?>
        <div id="primary" class="content-area"><ul class="storm-chase-archive">
        <?php
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $chase_data = $data_handler->get_chase_data(get_the_ID());
                $chase_date = $chase_data['chasedate'] && preg_match('/^\d{8}$/', $chase_data['chasedate'])
                    ? esc_html(date_i18n('F j, Y', strtotime($chase_data['chasedate'])))
                    : esc_html__('Invalid or missing date', 'stormchases');
                $excerpt = esc_html(wp_unslash(get_the_excerpt() ?? ''));
                $miles = $chase_data['chasemiles'];
                $tornado_count = count($chase_data['tornadoes']);
                $tornado_names = implode(', ', array_map(function($t) { return wp_unslash($t['name'] ?? __('Unnamed', 'stormchases')); }, $chase_data['tornadoes']));
                ?>
                <li>
                    <a href="<?php echo esc_url(get_permalink()); ?>"><?php echo $chase_date; ?></a> - 
                    <?php echo $excerpt; ?> (<?php echo esc_html($miles); ?> <?php esc_html_e('Miles', 'stormchases'); ?>)
                </li>
                <?php
            }
        } else {
            echo '<li>' . esc_html__('No storm chases found.', 'stormchases') . '</li>';
        }
        ?>
        </ul></div>
        <?php
        $output = ob_get_clean();
        wp_reset_postdata();
        set_transient($transient_key, $output, DAY_IN_SECONDS);
    }

    return $output;
}
add_shortcode('scarchive', 'chase_archive_shortcode');

// $args (shared by both renderers below): year, heading (custom override; ''
// = smart default), show_heading (bool). Also used by the
// stormchases/tornado-list and stormchases/chase-stats blocks — see
// includes/blocks.php.

// Only differentiates the transient key when heading/show_heading deviate
// from shortcode defaults, so default (shortcode) cache keys are byte-
// identical to before this refactor — StormChaseTemplate::clear_transients()
// wildcard-deletes storm_chases_stats_% regardless of any suffix, so this
// stays safe to extend per-block-instance.
function sc_stats_transient_suffix(array $args): string {
    if ($args['heading'] === '' && $args['show_heading'] === true) {
        return '';
    }
    return '_' . md5(wp_json_encode([$args['heading'], $args['show_heading']]));
}

function sc_render_tornado_list(array $args): string {
    $args = array_merge(['year' => '', 'heading' => '', 'show_heading' => true], $args);
    $year = $args['year'];

    $transient_key = 'storm_chases_stats_' . ($year ?: 'all') . '_tornadoes' . sc_stats_transient_suffix($args);
    $output = get_transient($transient_key);
    if (false !== $output) {
        return $output;
    }

    $query_args = [
        'post_type' => 'storm_chase',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_key' => 'chasedate',
        'orderby' => 'meta_value',
        'order' => 'DESC',
        'meta_query' => [],
    ];
    if ($year) {
        $query_args['meta_query'][] = [
            'key' => 'chasedate',
            'value' => [$year . '0101', $year . '1231'],
            'compare' => 'BETWEEN',
            'type' => 'CHAR',
        ];
    }

    $query = new WP_Query($query_args);
    $data_handler = new StormChasesData();

    $tornado_list = [];
    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $chase_data = $data_handler->get_chase_data($post_id);
        if (count($chase_data['tornadoes']) === 0) {
            continue;
        }

        $post_permalink = get_permalink();
        foreach ($chase_data['tornadoes'] as $tornado) {
            $formatted_date = 'Unknown Date';
            if (
                !empty($chase_data['chasedate']) &&
                preg_match('/^([12])\d{3}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])$/', $chase_data['chasedate']) &&
                $chase_data['chasedate'] >= '19500101' &&
                $chase_data['chasedate'] <= '21000101'
            ) {
                $date = DateTime::createFromFormat('Ymd', $chase_data['chasedate']);
                if ($date && $date->format('Ymd') === $chase_data['chasedate']) {
                    $formatted_date = date_i18n('F j, Y', strtotime($chase_data['chasedate']));
                }
            }
            $time = !empty($tornado['start_time']) ? ' Time: ' . esc_html(wp_unslash($tornado['start_time'])) : '';
            $location = 'Unknown';
            $map_url = '';
            if (isset($tornado['lat'], $tornado['lon']) && is_numeric($tornado['lat']) && is_numeric($tornado['lon'])) {
                $lat = (float) $tornado['lat'];
                $lon = (float) $tornado['lon'];
                $location = sprintf('%.2f, %.2f', $lat, $lon);
                if ($lat != 0.0 || $lon != 0.0) {
                    $map_url = esc_url('https://www.google.com/maps?q=' . sprintf('%.2f,%.2f', $lat, $lon));
                }
            }
            $tornado_list[] = [
                'date' => $chase_data['chasedate'],
                'formatted_date' => $formatted_date,
                'permalink' => $post_permalink,
                'name' => wp_unslash($tornado['name'] ?? 'Unnamed Tornado'),
                'location' => $location,
                'map_url' => $map_url,
                'time' => $time,
                'ef_rating' => esc_html($tornado['ef_rating'] ?? 'Unknown'),
            ];
        }
    }

    usort($tornado_list, function($a, $b) {
        return strcmp($a['date'], $b['date']);
    });

    wp_reset_postdata();

    $heading = $args['heading'] !== ''
        ? esc_html($args['heading'])
        : ($year ? esc_html(sprintf(__('Tornadoes for %s', 'stormchases'), $year)) : esc_html__('All Tornadoes', 'stormchases'));

    ob_start();
    ?>
    <div class="storm-chases-tornadoes">
        <?php if ($args['show_heading']) : ?>
            <h2><?php echo $heading; ?></h2>
        <?php endif; ?>
        <?php if (!empty($tornado_list)) : ?>
            <ol>
                <?php foreach ($tornado_list as $tornado) : ?>
                    <li>
                        <a href="<?php echo esc_url($tornado['permalink']); ?>">
                            <?php echo esc_html($tornado['name']); ?>
                        </a> -
                        <?php echo esc_html($tornado['formatted_date']); ?> -
                        (<?php
                            if ($tornado['map_url']) {
                                echo wp_kses_post('<a href="' . $tornado['map_url'] . '" target="_blank" rel="noopener noreferrer">' . esc_html($tornado['location']) . '</a>');
                            } else {
                                echo esc_html($tornado['location']);
                            }
                        ?>)
                        (<?php echo esc_html($tornado['ef_rating']); ?>)
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php else : ?>
            <p><?php esc_html_e('No tornadoes recorded.', 'stormchases'); ?></p>
        <?php endif; ?>
    </div>
    <?php
    $output = ob_get_clean();
    set_transient($transient_key, $output, DAY_IN_SECONDS);
    return $output;
}

function sc_render_chase_stats(array $args): string {
    $args = array_merge(['year' => '', 'heading' => '', 'show_heading' => true], $args);
    $year = $args['year'];

    $transient_key = 'storm_chases_stats_' . ($year ?: 'all') . sc_stats_transient_suffix($args);
    $output = get_transient($transient_key);
    if (false !== $output) {
        return $output;
    }

    $query_args = [
        'post_type' => 'storm_chase',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_key' => 'chasedate',
        'orderby' => 'meta_value',
        'order' => 'DESC',
        'meta_query' => [],
    ];
    if ($year) {
        $query_args['meta_query'][] = [
            'key' => 'chasedate',
            'value' => [$year . '0101', $year . '1231'],
            'compare' => 'BETWEEN',
            'type' => 'CHAR',
        ];
    }

    $query = new WP_Query($query_args);
    $data_handler = new StormChasesData();

    $stats = [
        'total_chases' => 0,
        'total_miles' => 0,
        'total_tornadoes' => 0,
        'total_states' => [],
        'state_counts' => [],
        'largest_hail' => 0,
        'highest_wind' => 0,
        'spotter_reports' => 0,
        'photogenic_tornadoes' => 0,
        'tornado_days' => 0,
        'best_chase_post_id' => null,
        'best_chase_date' => '',
        'best_chase_permalink' => '',
    ];

    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $chase_data = $data_handler->get_chase_data($post_id);
        $tornado_count = count($chase_data['tornadoes']);
        Storm_Chases::debug_log("Post ID $post_id: chasetornado = " . ($chase_data['chasetornado'] ?? 0) . ", tornadoes count = " . $tornado_count, 'info');

        $stats['total_chases']++;
        $stats['total_miles'] += $chase_data['chasemiles'];
        $stats['total_tornadoes'] += $tornado_count;
        if ($tornado_count > 0) {
            $stats['tornado_days']++;
            foreach ($chase_data['tornadoes'] as $tornado) {
                if ($tornado['photogenic']) {
                    $stats['photogenic_tornadoes']++;
                }
            }
        }
        $stats['spotter_reports'] += count($chase_data['spotter_reports']);
        if (!empty($chase_data['chasestates'])) {
            $states = array_map('trim', explode(',', wp_unslash($chase_data['chasestates'])));
            $stats['total_states'] = array_merge($stats['total_states'], $states);
            foreach ($states as $state) {
                $state = trim($state);
                if (!empty($state)) {
                    $stats['state_counts'][$state] = ($stats['state_counts'][$state] ?? 0) + 1;
                }
            }
        }
        $stats['largest_hail'] = max($stats['largest_hail'], $chase_data['chasehail']);
        $stats['highest_wind'] = max($stats['highest_wind'], $chase_data['chasewind']);
    }

    // Resolve best chase for the requested year
    if ($year) {
        $best_chases = get_option('storm_chases_best_chases', []);
        $best_post_id = $best_chases[$year] ?? null;
        if ($best_post_id) {
            $best_post = get_post($best_post_id);
            if ($best_post && $best_post->post_status === 'publish') {
                $best_data = (new StormChasesData())->get_chase_data($best_post_id);
                $stats['best_chase_post_id']  = $best_post_id;
                $stats['best_chase_date']     = $best_data['chasedate'] ?? '';
                $stats['best_chase_permalink'] = get_permalink($best_post_id);
            }
        }
    }

    $unique_states = array_unique(array_filter($stats['total_states']));
    sort($unique_states);
    $stats['total_states'] = $unique_states;
    $stats['total_states_count'] = count($unique_states);
    $stats['tornado_day_percentage'] = $stats['total_chases'] > 0 ? ($stats['tornado_days'] / $stats['total_chases']) * 100 : 0;
    $stats['tornadoes_per_mile'] = $stats['total_miles'] > 0 ? $stats['total_tornadoes'] / $stats['total_miles'] : 0;

    wp_reset_postdata();

    $heading = $args['heading'] !== ''
        ? esc_html($args['heading'])
        : ($year ? esc_html(sprintf(__('Chase Statistics for %s', 'stormchases'), $year)) : esc_html__('All-Time Chase Statistics', 'stormchases'));

    ob_start();
    ?>
    <div class="storm-chases-stats">
        <?php if ($args['show_heading']) : ?>
            <h2><?php echo $heading; ?></h2>
        <?php endif; ?>
        <ul>
            <li><strong><?php esc_html_e('Chase Days:', 'stormchases'); ?></strong> <?php echo esc_html($stats['total_chases']); ?></li>
            <li><strong><?php esc_html_e('Tornado Days:', 'stormchases'); ?></strong> <?php echo esc_html($stats['tornado_days']); ?></li>
            <li><strong><?php esc_html_e('Tornadoes:', 'stormchases'); ?></strong> <?php echo esc_html($stats['total_tornadoes']); ?></li>
            <li><strong><?php esc_html_e('Photogenic Tornadoes:', 'stormchases'); ?></strong> <?php echo esc_html($stats['photogenic_tornadoes']); ?></li>
            <li><strong><?php esc_html_e('Miles Driven:', 'stormchases'); ?></strong> <?php echo esc_html(number_format($stats['total_miles'])); ?></li>
            <li><strong><?php esc_html_e('Number of States Chased:', 'stormchases'); ?></strong> <?php echo esc_html($stats['total_states_count']); ?></li>
            <li><strong><?php esc_html_e('Chase Days by State:', 'stormchases'); ?></strong>
                <?php
                if (!empty($stats['state_counts'])) {
                    $state_list = [];
                    foreach ($stats['state_counts'] as $state => $count) {
                        $full_state_name = storm_chases_get_full_state_names($state);
                        $state_list[] = ['name' => $full_state_name, 'count' => $count];
                    }
                    usort($state_list, function($a, $b) {
                        if ($a['count'] === $b['count']) {
                            return strcmp($a['name'], $b['name']);
                        }
                        return $b['count'] - $a['count'];
                    });
                    $formatted_list = array_map(function($item) {
                        return $item['name'] . ': ' . $item['count'];
                    }, $state_list);
                    echo wp_kses_post(implode(', ', $formatted_list));
                } else {
                    echo esc_html__('None', 'stormchases');
                }
                ?>
            </li>
            <li><strong><?php esc_html_e('Spotter Network Reports:', 'stormchases'); ?></strong> <?php echo esc_html($stats['spotter_reports']); ?></li>
            <li><strong><?php esc_html_e('Largest Hail Observed:', 'stormchases'); ?></strong>
                <?php echo $stats['largest_hail'] > 0 ? esc_html(number_format($stats['largest_hail'], 2) . ' in.') : esc_html__('None', 'stormchases'); ?>
            </li>
            <li><strong><?php esc_html_e('Highest Wind Observed:', 'stormchases'); ?></strong>
                <?php echo $stats['highest_wind'] > 0 ? esc_html($stats['highest_wind'] . ' MPH') : esc_html__('None', 'stormchases'); ?>
            </li>
            <li><strong><?php esc_html_e('Tornado Day Percentage:', 'stormchases'); ?></strong>
                <?php echo esc_html(number_format($stats['tornado_day_percentage'], 2) . '%'); ?>
            </li>
            <li><strong><?php esc_html_e('Tornadoes per Mile:', 'stormchases'); ?></strong>
                <?php echo esc_html(number_format($stats['tornadoes_per_mile'], 4)); ?>
            </li>
            <?php if (get_option('best_chase_enable') && !empty($stats['best_chase_date'])) :
                $bc_date = date_i18n('F j', strtotime($stats['best_chase_date']));
            ?>
            <li><strong><?php esc_html_e('Best Chase Day:', 'stormchases'); ?></strong>
                <a href="<?php echo esc_url($stats['best_chase_permalink']); ?>"><?php echo esc_html($bc_date); ?></a>
            </li>
            <?php endif; ?>
            <?php if (get_option('windshields_enable')) :
                $windshields = get_option('storm_chases_windshields', []);
                if ($year) {
                    $ws_count = count(array_filter($windshields, fn($w) => (string)$w['year'] === $year));
                } else {
                    $ws_count = count($windshields);
                }
            ?>
            <li><strong><?php esc_html_e('Windshields Replaced:', 'stormchases'); ?></strong> <?php echo esc_html($ws_count); ?></li>
            <?php endif; ?>
        </ul>
    </div>
    <?php
    $output = ob_get_clean();
    set_transient($transient_key, $output, DAY_IN_SECONDS);
    return $output;
}

function sc_stats_shortcode($atts) {
    $atts = shortcode_atts(
        [
            'year' => '',
            'tornadoes' => 'false',
        ],
        $atts,
        'scstats'
    );

    $year = sanitize_text_field($atts['year']);
    $show_tornadoes = filter_var($atts['tornadoes'], FILTER_VALIDATE_BOOLEAN);
    if ($year && !preg_match('/^\d{4}$/', $year)) {
        return '<p>' . esc_html__('Invalid year format.', 'stormchases') . '</p>';
    }

    $render_args = ['year' => $year, 'heading' => '', 'show_heading' => true];
    return $show_tornadoes ? sc_render_tornado_list($render_args) : sc_render_chase_stats($render_args);
}
add_shortcode('scstats', 'sc_stats_shortcode');

function stormchases_classes($classes) {
    if (is_singular('storm_chase')) {
        $classes[] = 'single-storm-chase';
    } elseif (is_post_type_archive('storm_chase')) {
        $classes[] = 'archive-storm-chase';
    }
    return array_unique($classes);
}
add_filter('body_class', 'stormchases_classes');

add_filter('the_content', function($content) {
    if (is_singular('storm_chase') && in_the_loop() && is_main_query()) {
        global $post;
        if (class_exists('StormChaseTemplate') && $post instanceof WP_Post && $post->post_type === 'storm_chase') {
            $storm_chase = new StormChaseTemplate();
            $navigation = $storm_chase->get_storm_chase_navigation($post->ID);
            $content .= '<div class="storm-chase-navigation-wrapper">' . $navigation . '</div>';
        }
    }
    return $content;
}, 8);

function storm_chases_convert_utc_to_central($utc_timestamp) {
    try {
        $utc_datetime = new DateTime($utc_timestamp, new DateTimeZone('UTC'));
        $central_tz = new DateTimeZone('America/Chicago');
        $utc_datetime->setTimezone($central_tz);
        return $utc_datetime->format('F j, Y, g:i A T');
    } catch (Exception $e) {
        Storm_Chases::debug_log('Error converting UTC to Central Time: ' . $e->getMessage(), 'error');
        return esc_html__('Invalid timestamp', 'stormchases');
    }
}

function storm_chases_get_nws_office($cwa) {
    $nws_offices = [
        'ABQ' => 'Albuquerque, NM',
        'ALR' => 'Birmingham, AL',
        'AMA' => 'Amarillo, TX',
        'ARX' => 'La Crosse, WI',
        'BGM' => 'Binghamton, NY',
        'BIS' => 'Bismarck, ND',
        'BMX' => 'Birmingham, AL',
        'BOI' => 'Boise, ID',
        'BOU' => 'Boulder, CO',
        'BOX' => 'Boston, MA',
        'BRO' => 'Brownsville, TX',
        'BTV' => 'Burlington, VT',
        'BUF' => 'Buffalo, NY',
        'BYZ' => 'Billings, MT',
        'CAE' => 'Columbia, SC',
        'CAR' => 'Caribou, ME',
        'CHS' => 'Charleston, SC',
        'CLE' => 'Cleveland, OH',
        'CRP' => 'Corpus Christi, TX',
        'CTP' => 'State College, PA',
        'CYS' => 'Cheyenne, WY',
        'DDC' => 'Dodge City, KS',
        'DLH' => 'Duluth, MN',
        'DMX' => 'Des Moines, IA',
        'DTX' => 'Detroit/Pontiac, MI',
        'DVN' => 'Davenport, IA',
        'EAX' => 'Kansas City/Pleasant Hill, MO',
        'EPZ' => 'El Paso, TX',
        'EKA' => 'Eureka, CA',
        'EWX' => 'Austin/San Antonio, TX',
        'FFC' => 'Peachtree City, GA',
        'FGF' => 'Grand Forks, ND',
        'FGZ' => 'Flagstaff, AZ',
        'FSD' => 'Sioux Falls, SD',
        'FWD' => 'Fort Worth, TX',
        'GID' => 'Hastings, NE',
        'GJT' => 'Grand Junction, CO',
        'GLD' => 'Goodland, KS',
        'GRB' => 'Green Bay, WI',
        'GRR' => 'Grand Rapids, MI',
        'GSP' => 'Greenville-Spartanburg, SC',
        'GYX' => 'Gray, ME',
        'HGX' => 'Houston/Galveston, TX',
        'HNX' => 'Hanford, CA',
        'HUN' => 'Huntsville, AL',
        'ICT' => 'Wichita, KS',
        'ILM' => 'Wilmington, NC',
        'ILN' => 'Wilmington, OH',
        'ILX' => 'Lincoln, IL',
        'IND' => 'Indianapolis, IN',
        'IWX' => 'Northern Indiana',
        'JAN' => 'Jackson, MS',
        'JAX' => 'Jacksonville, FL',
        'JKL' => 'Jackson, KY',
        'KEY' => 'Key West, FL',
        'LBF' => 'North Platte, NE',
        'LCH' => 'Lake Charles, LA',
        'LIX' => 'New Orleans, LA',
        'LKN' => 'Elko, NV',
        'LMK' => 'Louisville, KY',
        'LOT' => 'Chicago/Romeoville, IL',
        'LOX' => 'Los Angeles/Oxnard, CA',
        'LSX' => 'St. Louis, MO',
        'LUB' => 'Lubbock, TX',
        'LWX' => 'Sterling, VA',
        'MAF' => 'Midland, TX',
        'MEG' => 'Memphis, TN',
        'MFL' => 'Miami, FL',
        'MHX' => 'Morehead City, NC',
        'MKX' => 'Milwaukee/Sullivan, WI',
        'MLB' => 'Melbourne, FL',
        'MOB' => 'Mobile, AL',
        'MPX' => 'Minneapolis/Chanhassen, MN',
        'MQT' => 'Marquette, MI',
        'MRX' => 'Morristown/Knoxville, TN',
        'MSO' => 'Missoula, MT',
        'MTR' => 'San Francisco/Monterey, CA',
        'OAX' => 'Omaha/Valley, NE',
        'OHX' => 'Nashville, TN',
        'OKX' => 'New York City/Upton, NY',
        'OTX' => 'Spokane, WA',
        'OUN' => 'Norman, OK',
        'PAH' => 'Paducah, KY',
        'PBZ' => 'Pittsburgh, PA',
        'PDT' => 'Pendleton, OR',
        'PHI' => 'Philadelphia/Mt. Holly, NJ',
        'PIH' => 'Pocatello, ID',
        'PQR' => 'Portland, OR',
        'PSR' => 'Phoenix, AZ',
        'PUB' => 'Pueblo, CO',
        'RAH' => 'Raleigh, NC',
        'REV' => 'Reno, NV',
        'RIW' => 'Riverton, WY',
        'RLX' => 'Charleston, WV',
        'RNK' => 'Blacksburg, VA',
        'SEW' => 'Seattle, WA',
        'SGX' => 'San Diego, CA',
        'SHV' => 'Shreveport, LA',
        'SJT' => 'San Angelo, TX',
        'SLC' => 'Salt Lake City, UT',
        'STO' => 'Sacramento, CA',
        'TAE' => 'Tallahassee, FL',
        'TBW' => 'Tampa Bay/Ruskin, FL',
        'TFX' => 'Great Falls, MT',
        'TOP' => 'Topeka, KS',
        'TSA' => 'Tulsa, OK',
        'TWC' => 'Tucson, AZ',
        'UNR' => 'Rapid City, SD',
        'VEF' => 'Las Vegas, NV'
    ];
    return isset($nws_offices[$cwa]) ? $nws_offices[$cwa] : (!empty($cwa) ? $cwa : 'Unknown');
}

function storm_chases_get_full_state_names($state_codes) {
    $state_map = [
        // U.S. States
        'AL' => ['name' => 'Alabama', 'country' => 'US'],
        'AK' => ['name' => 'Alaska', 'country' => 'US'],
        'AZ' => ['name' => 'Arizona', 'country' => 'US'],
        'AR' => ['name' => 'Arkansas', 'country' => 'US'],
        'CA' => ['name' => 'California', 'country' => 'US'],
        'CO' => ['name' => 'Colorado', 'country' => 'US'],
        'CT' => ['name' => 'Connecticut', 'country' => 'US'],
        'DE' => ['name' => 'Delaware', 'country' => 'US'],
        'FL' => ['name' => 'Florida', 'country' => 'US'],
        'GA' => ['name' => 'Georgia', 'country' => 'US'],
        'HI' => ['name' => 'Hawaii', 'country' => 'US'],
        'ID' => ['name' => 'Idaho', 'country' => 'US'],
        'IL' => ['name' => 'Illinois', 'country' => 'US'],
        'IN' => ['name' => 'Indiana', 'country' => 'US'],
        'IA' => ['name' => 'Iowa', 'country' => 'US'],
        'KS' => ['name' => 'Kansas', 'country' => 'US'],
        'KY' => ['name' => 'Kentucky', 'country' => 'US'],
        'LA' => ['name' => 'Louisiana', 'country' => 'US'],
        'ME' => ['name' => 'Maine', 'country' => 'US'],
        'MD' => ['name' => 'Maryland', 'country' => 'US'],
        'MA' => ['name' => 'Massachusetts', 'country' => 'US'],
        'MI' => ['name' => 'Michigan', 'country' => 'US'],
        'MN' => ['name' => 'Minnesota', 'country' => 'US'],
        'MS' => ['name' => 'Mississippi', 'country' => 'US'],
        'MO' => ['name' => 'Missouri', 'country' => 'US'],
        'MT' => ['name' => 'Montana', 'country' => 'US'],
        'NE' => ['name' => 'Nebraska', 'country' => 'US'],
        'NV' => ['name' => 'Nevada', 'country' => 'US'],
        'NH' => ['name' => 'New Hampshire', 'country' => 'US'],
        'NJ' => ['name' => 'New Jersey', 'country' => 'US'],
        'NM' => ['name' => 'New Mexico', 'country' => 'US'],
        'NY' => ['name' => 'New York', 'country' => 'US'],
        'NC' => ['name' => 'North Carolina', 'country' => 'US'],
        'ND' => ['name' => 'North Dakota', 'country' => 'US'],
        'OH' => ['name' => 'Ohio', 'country' => 'US'],
        'OK' => ['name' => 'Oklahoma', 'country' => 'US'],
        'OR' => ['name' => 'Oregon', 'country' => 'US'],
        'PA' => ['name' => 'Pennsylvania', 'country' => 'US'],
        'RI' => ['name' => 'Rhode Island', 'country' => 'US'],
        'SC' => ['name' => 'South Carolina', 'country' => 'US'],
        'SD' => ['name' => 'South Dakota', 'country' => 'US'],
        'TN' => ['name' => 'Tennessee', 'country' => 'US'],
        'TX' => ['name' => 'Texas', 'country' => 'US'],
        'UT' => ['name' => 'Utah', 'country' => 'US'],
        'VT' => ['name' => 'Vermont', 'country' => 'US'],
        'VA' => ['name' => 'Virginia', 'country' => 'US'],
        'WA' => ['name' => 'Washington', 'country' => 'US'],
        'WV' => ['name' => 'West Virginia', 'country' => 'US'],
        'WI' => ['name' => 'Wisconsin', 'country' => 'US'],
        'WY' => ['name' => 'Wyoming', 'country' => 'US'],
        // Canadian Provinces
        'AB' => ['name' => 'Alberta', 'country' => 'CA'],
        'BC' => ['name' => 'British Columbia', 'country' => 'CA'],
        'MB' => ['name' => 'Manitoba', 'country' => 'CA'],
        'NB' => ['name' => 'New Brunswick', 'country' => 'CA'],
        'NL' => ['name' => 'Newfoundland and Labrador', 'country' => 'CA'],
        'NS' => ['name' => 'Nova Scotia', 'country' => 'CA'],
        'ON' => ['name' => 'Ontario', 'country' => 'CA'],
        'PE' => ['name' => 'Prince Edward Island', 'country' => 'CA'],
        'QC' => ['name' => 'Quebec', 'country' => 'CA'],
        'SK' => ['name' => 'Saskatchewan', 'country' => 'CA'],
        'NT' => ['name' => 'Northwest Territories', 'country' => 'CA'],
        'NU' => ['name' => 'Nunavut', 'country' => 'CA'],
        'YT' => ['name' => 'Yukon', 'country' => 'CA']
    ];

    $codes = array_filter(array_map('trim', explode(',', strtoupper($state_codes))));
    $full_names = array_map(function($code) use ($state_map) {
        if (isset($state_map[$code])) {
            $name = $state_map[$code]['name'];
            if ($state_map[$code]['country'] === 'CA') {
                return $name . ' <img src="' . esc_url(STORM_CHASES_URL . 'assets/images/canada-flag.png') . '" alt="Canadian Flag" style="width: 23px; height: auto; vertical-align: middle;">';
            }
            return $name;
        }
        return $code;
    }, $codes);

    return implode(', ', $full_names);
}

// ---------------------------------------------------------------------------
// Helpers shared by map shortcodes
// ---------------------------------------------------------------------------

function sc_enqueue_map_assets() {
    $map_provider = get_option('map_provider', 'openstreetmap');
    wp_enqueue_style('storm-chases', STORM_CHASES_URL . 'assets/css/stormchase.css', [], STORM_CHASES_VERSION);

    if ($map_provider !== 'google') {
        wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_enqueue_script('storm-chases-frontend', STORM_CHASES_URL . 'assets/js/frontend.js', ['jquery', 'leaflet'], STORM_CHASES_VERSION, true);
    } else {
        wp_enqueue_script('storm-chases-frontend', STORM_CHASES_URL . 'assets/js/frontend.js', ['jquery'], STORM_CHASES_VERSION, true);
        $api_key = get_option('google_maps_api_key', '');
        if ($api_key) {
            wp_enqueue_script('google-maps', 'https://maps.googleapis.com/maps/api/js?key=' . esc_attr($api_key), [], null, ['strategy' => 'defer']);
        }
    }

    // Only localize if the script was freshly registered here (not already done by the per-chase enqueue)
    static $sc_frontend_localized = false;
    if (!$sc_frontend_localized && !wp_script_is('storm-chases-frontend', 'done')) {
        $sc_frontend_localized = true;
        wp_localize_script('storm-chases-frontend', 'stormChasesFrontend', [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'chasemapUrl'       => '',
            'chasemapType'      => '0',
            'googleMapsEnabled' => get_option('google_maps_enable', false) ? true : false,
            'mapProvider'       => esc_attr($map_provider),
            'tornadoIconBase'   => esc_url(STORM_CHASES_URL . 'assets/images/'),
        ]);
    }
}

// ---------------------------------------------------------------------------
// [sc_tornado_map] shortcode + shared renderer (also used by the
// stormchases/tornado-map block — see includes/blocks.php)
// ---------------------------------------------------------------------------

// Maps a tornado's stored ef_rating value (e.g. 'EF-5', 'EF-U', 'Unrated') to
// the icon/legend slug used for both filenames and rating filters. Keep in
// sync with EF_ICON_SLUG in assets/js/frontend.js.
function sc_tornado_ef_slug($ef_rating) {
    $map = [
        'EF-5' => 'ef5',
        'EF-4' => 'ef4',
        'EF-3' => 'ef3',
        'EF-2' => 'ef2',
        'EF-1' => 'ef1',
        'EF-0' => 'ef0',
    ];
    return $map[$ef_rating] ?? 'unrated';
}

// $args: year, heading (custom override; '' = smart default), show_heading
// (bool), height (px), ratings (array of ef slugs to include; empty = all).
function sc_render_tornado_map(array $args): string {
    $args = array_merge([
        'year'         => '',
        'heading'      => '',
        'show_heading' => true,
        'height'       => 500,
        'ratings'      => [],
    ], $args);

    $year    = $args['year'];
    $height  = max(150, min(2000, (int) $args['height']));
    $ratings = array_values(array_filter((array) $args['ratings']));

    sc_enqueue_map_assets();

    $query_args = [
        'post_type'      => 'storm_chase',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    ];
    if ($year) {
        $query_args['meta_query'] = [
            [
                'key'     => 'chasedate',
                'value'   => [$year . '0101', $year . '1231'],
                'compare' => 'BETWEEN',
                'type'    => 'CHAR',
            ],
        ];
    }

    $query        = new WP_Query($query_args);
    $data_handler = new StormChasesData();
    $map_points   = [];
    $modal_html   = '';
    $map_uid      = 'sc-tornado-map-' . uniqid();

    while ($query->have_posts()) {
        $query->the_post();
        $post_id    = get_the_ID();
        $chase_data = $data_handler->get_chase_data($post_id);
        $permalink  = get_permalink();

        foreach ($chase_data['tornadoes'] as $idx => $tornado) {
            $lat = floatval($tornado['lat'] ?? 0);
            $lon = floatval($tornado['lon'] ?? 0);
            if ($lat == 0 && $lon == 0) {
                continue;
            }
            $ef_raw = $tornado['ef_rating'] ?? 'Unrated';
            if (!empty($ratings) && !in_array(sc_tornado_ef_slug($ef_raw), $ratings, true)) {
                continue;
            }

            $modal_id   = esc_attr('sc-tmap-modal-' . $post_id . '-' . $idx);
            $name       = esc_html($tornado['name'] ?: 'Tornado ' . ($idx + 1));
            $ef         = esc_html($ef_raw);
            $date_label = '';
            if (!empty($chase_data['chasedate']) && preg_match('/^\d{8}$/', $chase_data['chasedate'])) {
                $date_label = date_i18n('F j, Y', strtotime($chase_data['chasedate']));
            }

            $map_points[] = [
                'lat'      => $lat,
                'lon'      => $lon,
                'modalId'  => $modal_id,
                'label'    => wp_strip_all_tags($name),
                'ef'       => wp_strip_all_tags($ef),
            ];

            $modal_html .= '<div id="' . $modal_id . '" class="modal sc-map-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="' . $modal_id . '-title">';
            $modal_html .= '<div class="modal-overlay"></div>';
            $modal_html .= '<div class="modal-content">';
            $modal_html .= '<span class="modal-close" aria-label="' . esc_attr__('Close', 'stormchases') . '">&times;</span>';
            $modal_html .= '<h3 id="' . $modal_id . '-title">' . $name . '</h3>';
            $modal_html .= '<p><b>' . esc_html__('Date', 'stormchases') . ':</b> <a href="' . esc_url($permalink) . '">' . esc_html($date_label) . '</a></p>';
            $modal_html .= '<p><b>' . esc_html__('EF Rating', 'stormchases') . ':</b> ' . $ef . '</p>';
            $modal_html .= '<p><b>' . esc_html__('Start Location', 'stormchases') . ':</b> ' . esc_html(number_format($lat, 4)) . ', ' . esc_html(number_format($lon, 4)) . '</p>';
            if (!empty($tornado['start_time'])) {
                $modal_html .= '<p><b>' . esc_html__('Start Time', 'stormchases') . ':</b> ' . esc_html($tornado['start_time']) . '</p>';
            }
            if (!empty($tornado['end_time'])) {
                $modal_html .= '<p><b>' . esc_html__('End Time', 'stormchases') . ':</b> ' . esc_html($tornado['end_time']) . '</p>';
            }
            if (!empty($tornado['photogenic'])) {
                $modal_html .= '<p><b>' . esc_html__('Photogenic', 'stormchases') . ':</b> ' . esc_html__('Yes', 'stormchases') . '</p>';
            }
            if (!empty($tornado['photo_id'])) {
                $modal_html .= wp_get_attachment_image($tornado['photo_id'], 'medium', false, ['style' => 'max-width:100%;height:auto;margin-top:10px;']);
            }
            $modal_html .= '</div></div>';
        }
    }
    wp_reset_postdata();

    if ($args['heading'] !== '') {
        $heading = esc_html($args['heading']);
    } else {
        $heading = $year
            ? sprintf(esc_html__('Tornado Map — %s', 'stormchases'), $year)
            : esc_html__('All Tornadoes Map', 'stormchases');
    }

    $ef_legend = [
        'EF-5'    => 'ef5',
        'EF-4'    => 'ef4',
        'EF-3'    => 'ef3',
        'EF-2'    => 'ef2',
        'EF-1'    => 'ef1',
        'EF-0'    => 'ef0',
        'Unrated' => 'unrated',
    ];
    $legend_html = '<div class="sc-map-legend">';
    foreach ($ef_legend as $label => $slug) {
        if (!empty($ratings) && !in_array($slug, $ratings, true)) {
            continue;
        }
        $legend_html .= '<span class="sc-map-legend-item">';
        $legend_html .= '<img src="' . esc_url(STORM_CHASES_URL . "assets/images/tornado-$slug.png") . '" alt="" class="sc-map-legend-icon">';
        $legend_html .= '<span>' . esc_html($label) . '</span>';
        $legend_html .= '</span>';
    }
    $legend_html .= '</div>';

    ob_start();
    ?>
    <div class="sc-map-shortcode">
        <?php if ($args['show_heading']) : ?>
            <h2><?php echo $heading; ?></h2>
        <?php endif; ?>
        <div id="<?php echo esc_attr($map_uid); ?>" class="sc-leaflet-map" data-map-type="tornado" style="height:<?php echo esc_attr($height); ?>px;width:100%;margin-bottom:10px;"></div>
        <?php echo $legend_html; // Already escaped above ?>
        <script>
        (function(){
            window.scMapData = window.scMapData || {};
            window.scMapData[<?php echo wp_json_encode($map_uid, JSON_HEX_TAG); ?>] = <?php echo wp_json_encode($map_points, JSON_HEX_TAG); ?>;
        })();
        </script>
        <?php echo $modal_html; // Already escaped above ?>
    </div>
    <?php
    return ob_get_clean();
}

function sc_tornado_map_shortcode($atts) {
    $atts = shortcode_atts(['year' => '', 'ratings' => ''], $atts, 'sc_tornado_map');
    $year = sanitize_text_field($atts['year']);
    if ($year && !preg_match('/^\d{4}$/', $year)) {
        return '<p>' . esc_html__('Invalid year format.', 'stormchases') . '</p>';
    }

    $ratings = [];
    if (!empty($atts['ratings'])) {
        $labels = array_filter(array_map('trim', explode(',', strtoupper(sanitize_text_field($atts['ratings'])))));
        foreach ($labels as $label) {
            $ratings[] = sc_tornado_ef_slug($label);
        }
        $ratings = array_values(array_unique($ratings));
    }

    return sc_render_tornado_map([
        'year'         => $year,
        'show_heading' => true,
        'height'       => 500,
        'ratings'      => $ratings,
    ]);
}
add_shortcode('sc_tornado_map', 'sc_tornado_map_shortcode');

// ---------------------------------------------------------------------------
// [sc_reports] shortcode — public Spotter Network reports list + map
// ---------------------------------------------------------------------------

// A spotter report has no real "type" field on the wire — Spotter Network's
// own report_type CSV column is hardcoded to 'S' at import (see
// handle_spotter_reports_upload()) and never read again. The human-facing
// weather-type label/icon is instead derived here from the independent
// tornado/hailsize/windspeed/funnelcloud/wallcloud/damage fields, in priority
// order (a report can be flagged for more than one). Single source of truth
// for both the label text and the marker icon slug — keep in sync with
// REPORT_ICON_SLUG in assets/js/frontend.js if the priority order changes.
function sc_report_weather_type(array $report): array {
    if (!empty($report['tornado'])) {
        return ['slug' => 'tornado', 'label' => __('Tornado', 'stormchases')];
    }
    if (!empty($report['hailsize']) && $report['hailsize'] > 0) {
        return ['slug' => 'hail', 'label' => __('Hail', 'stormchases')];
    }
    if (!empty($report['windspeed']) && $report['windspeed'] > 0) {
        return ['slug' => 'wind', 'label' => __('Wind', 'stormchases')];
    }
    if (!empty($report['funnelcloud'])) {
        return ['slug' => 'funnel', 'label' => __('Funnel Cloud', 'stormchases')];
    }
    if (!empty($report['wallcloud'])) {
        return ['slug' => 'wallcloud', 'label' => __('Wall Cloud', 'stormchases')];
    }
    if (!empty($report['damage'])) {
        return ['slug' => 'damage', 'label' => __('Damage', 'stormchases')];
    }
    return ['slug' => 'generic', 'label' => __('Report', 'stormchases')];
}

// $args: year, heading (custom override; '' = smart default), show_heading
// (bool), height (px), types (array of report-type slugs to include —
// tornado/hail/wind/funnel/wallcloud/damage/generic; empty = show all). Also
// used by the stormchases/spotter-reports block — see includes/blocks.php.
function sc_render_spotter_reports(array $args): string {
    $args = array_merge([
        'year'         => '',
        'heading'      => '',
        'show_heading' => true,
        'height'       => 500,
        'types'        => [],
    ], $args);

    $year   = $args['year'];
    $height = max(150, min(2000, (int) $args['height']));
    $types  = array_values(array_filter((array) $args['types']));

    sc_enqueue_map_assets();

    $query_args = [
        'post_type'      => 'storm_chase',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => 'chasedate',
        'orderby'        => 'meta_value',
        'order'          => 'DESC',
        'meta_query'     => [],
    ];
    if ($year) {
        $query_args['meta_query'][] = [
            'key'     => 'chasedate',
            'value'   => [$year . '0101', $year . '1231'],
            'compare' => 'BETWEEN',
            'type'    => 'CHAR',
        ];
    }

    $query        = new WP_Query($query_args);
    $data_handler = new StormChasesData();
    $all_reports  = [];
    $map_points   = [];
    $modal_html   = '';
    $map_uid      = 'sc-reports-map-' . uniqid();

    while ($query->have_posts()) {
        $query->the_post();
        $post_id    = get_the_ID();
        $chase_data = $data_handler->get_chase_data($post_id);
        $permalink  = get_permalink();

        foreach ($chase_data['spotter_reports'] as $report) {
            $all_reports[] = array_merge($report, ['permalink' => $permalink, 'chasedate' => $chase_data['chasedate']]);
        }
    }
    wp_reset_postdata();

    usort($all_reports, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));

    if (!empty($types)) {
        $all_reports = array_values(array_filter($all_reports, function ($report) use ($types) {
            return in_array(sc_report_weather_type($report)['slug'], $types, true);
        }));
    }

    foreach ($all_reports as $i => $report) {
        $lat      = floatval($report['lat'] ?? 0);
        $lon      = floatval($report['lon'] ?? 0);
        $modal_id = esc_attr('sc-rep-modal-' . $i . '-' . substr(md5($report['report_id'] . $report['timestamp']), 0, 8));
        $timestamp_label = !empty($report['timestamp']) && strtotime($report['timestamp'])
            ? storm_chases_convert_utc_to_central($report['timestamp'])
            : __('Unknown time', 'stormchases');
        $weather = sc_report_weather_type($report);
        $city_label = !empty($report['city']) ? $report['city'] : '';
        $link_text  = trim($weather['label'] . ' ' . $city_label);
        $nws_office = esc_html(storm_chases_get_nws_office($report['cwa'] ?? ''));

        if ($lat != 0 || $lon != 0) {
            $map_points[] = [
                'lat'        => $lat,
                'lon'        => $lon,
                'modalId'    => $modal_id,
                'label'      => wp_strip_all_tags($link_text),
                'reportType' => $weather['slug'],
            ];
        }

        $modal_html .= '<div id="' . $modal_id . '" class="modal sc-map-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="' . $modal_id . '-title">';
        $modal_html .= '<div class="modal-overlay"></div>';
        $modal_html .= '<div class="modal-content">';
        $modal_html .= '<span class="modal-close" aria-label="' . esc_attr__('Close', 'stormchases') . '">&times;</span>';
        $modal_html .= '<h3 id="' . $modal_id . '-title">' . esc_html($link_text) . '</h3>';
        $modal_html .= '<p><b>' . esc_html__('Time (Central)', 'stormchases') . ':</b> ' . esc_html($timestamp_label) . '</p>';
        $modal_html .= '<p><b>' . esc_html__('Location', 'stormchases') . ':</b> ' . esc_html(number_format($lat, 4)) . ', ' . esc_html(number_format($lon, 4)) . '</p>';
        $modal_html .= '<p><b>' . esc_html__('NWS Office', 'stormchases') . ':</b> ' . $nws_office . '</p>';
        if (!empty($report['narrative'])) {
            $modal_html .= '<p><b>' . esc_html__('Narrative', 'stormchases') . ':</b> ' . esc_html($report['narrative']) . '</p>';
        }
        if (!empty($report['hailsize']) && $report['hailsize'] > 0) {
            $modal_html .= '<p><b>' . esc_html__('Hail Size', 'stormchases') . ':</b> ' . esc_html($report['hailsize']) . ' ' . esc_html__('in.', 'stormchases') . '</p>';
        }
        if (!empty($report['windspeed']) && $report['windspeed'] > 0) {
            $modal_html .= '<p><b>' . esc_html__('Wind Speed', 'stormchases') . ':</b> ' . esc_html($report['windspeed']) . ' mph</p>';
        }
        if (!empty($report['tornado'])) {
            $modal_html .= '<p><b>' . esc_html__('Tornado', 'stormchases') . ':</b> ' . esc_html__('Yes', 'stormchases') . '</p>';
        }
        if (!empty($report['funnelcloud'])) {
            $modal_html .= '<p><b>' . esc_html__('Funnel Cloud', 'stormchases') . ':</b> ' . esc_html__('Yes', 'stormchases') . '</p>';
        }
        if (!empty($report['wallcloud'])) {
            $modal_html .= '<p><b>' . esc_html__('Wall Cloud', 'stormchases') . ':</b> ' . esc_html__('Yes', 'stormchases') . '</p>';
        }
        if (!empty($report['damage'])) {
            $modal_html .= '<p><b>' . esc_html__('Damage', 'stormchases') . ':</b> ' . esc_html__('Yes', 'stormchases') . '</p>';
        }
        $modal_html .= '<p><a href="' . esc_url($report['permalink']) . '">' . esc_html__('View Chase Log', 'stormchases') . '</a></p>';
        $modal_html .= '</div></div>';
    }

    if ($args['heading'] !== '') {
        $heading = esc_html($args['heading']);
    } else {
        $heading = $year
            ? sprintf(esc_html__('Spotter Network Reports — %s', 'stormchases'), $year)
            : esc_html__('All Spotter Network Reports', 'stormchases');
    }

    $report_legend = [
        __('Tornado', 'stormchases')      => 'tornado',
        __('Hail', 'stormchases')         => 'hail',
        __('Wind', 'stormchases')         => 'wind',
        __('Funnel Cloud', 'stormchases') => 'funnel',
        __('Wall Cloud', 'stormchases')   => 'wallcloud',
        __('Damage', 'stormchases')       => 'damage',
        __('Report', 'stormchases')       => 'generic',
    ];
    $legend_html = '<div class="sc-map-legend">';
    foreach ($report_legend as $label => $slug) {
        if (!empty($types) && !in_array($slug, $types, true)) {
            continue;
        }
        $legend_html .= '<span class="sc-map-legend-item">';
        $legend_html .= '<img src="' . esc_url(STORM_CHASES_URL . "assets/images/report-$slug.png") . '" alt="" class="sc-map-legend-icon">';
        $legend_html .= '<span>' . esc_html($label) . '</span>';
        $legend_html .= '</span>';
    }
    $legend_html .= '</div>';

    ob_start();
    ?>
    <div class="sc-map-shortcode sc-reports-shortcode">
        <?php if ($args['show_heading']) : ?>
            <h2><?php echo $heading; ?></h2>
        <?php endif; ?>
        <div id="<?php echo esc_attr($map_uid); ?>" class="sc-leaflet-map" data-map-type="reports" style="height:<?php echo esc_attr($height); ?>px;width:100%;margin-bottom:10px;"></div>
        <?php echo $legend_html; // Already escaped above ?>
        <script>
        (function(){
            window.scMapData = window.scMapData || {};
            window.scMapData[<?php echo wp_json_encode($map_uid, JSON_HEX_TAG); ?>] = <?php echo wp_json_encode($map_points, JSON_HEX_TAG); ?>;
        })();
        </script>
        <?php if (!empty($all_reports)) : ?>
        <div class="sc-reports-list">
            <h3><?php echo $year ? esc_html(sprintf(__('%d Reports in %s', 'stormchases'), count($all_reports), $year)) : esc_html(sprintf(__('%d Total Reports', 'stormchases'), count($all_reports))); ?></h3>
            <ol>
                <?php foreach ($all_reports as $i => $report) :
                    $modal_id_list = 'sc-rep-modal-' . $i . '-' . substr(md5($report['report_id'] . $report['timestamp']), 0, 8);
                    $city_label = !empty($report['city']) ? $report['city'] : '';
                    $link_text  = trim(sc_report_weather_type($report)['label'] . ' ' . $city_label);
                    $ts_label   = !empty($report['timestamp']) && strtotime($report['timestamp'])
                        ? storm_chases_convert_utc_to_central($report['timestamp'])
                        : __('Unknown time', 'stormchases');
                ?>
                <li>
                    <a href="#" class="sc-report-link" data-modal-id="<?php echo esc_attr($modal_id_list); ?>">
                        <?php echo esc_html($link_text); ?>
                    </a>
                    &mdash; <?php echo esc_html($ts_label); ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php else : ?>
        <p><?php esc_html_e('No Spotter Network reports found.', 'stormchases'); ?></p>
        <?php endif; ?>
        <?php echo $modal_html; // Already escaped above ?>
    </div>
    <?php
    return ob_get_clean();
}

function sc_reports_shortcode($atts) {
    $atts = shortcode_atts(['year' => '', 'types' => ''], $atts, 'sc_reports');
    $year = sanitize_text_field($atts['year']);
    if ($year && !preg_match('/^\d{4}$/', $year)) {
        return '<p>' . esc_html__('Invalid year format.', 'stormchases') . '</p>';
    }

    $types = array_filter(array_map('trim', explode(',', strtolower(sanitize_text_field($atts['types'])))));

    return sc_render_spotter_reports([
        'year'         => $year,
        'show_heading' => true,
        'height'       => 500,
        'types'        => $types,
    ]);
}
add_shortcode('sc_reports', 'sc_reports_shortcode');
