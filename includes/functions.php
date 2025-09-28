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

    $transient_key = 'sc_archive_v2_' . md5($year . $show . $chasers . $states . $tornadoes);
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
        Storm_Chases::debug_log("Set transient for archive: $transient_key", 'info');
    }

    return $output;
}
add_shortcode('scarchive', 'chase_archive_shortcode');

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

    $transient_key = 'sc_stats_v12_' . ($year ? $year : 'all') . '_' . ($show_tornadoes ? 'tornadoes' : 'stats');
    $output = get_transient($transient_key);

    if (false === $output) {
        $args = [
            'post_type' => 'storm_chase',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_key' => 'chasedate',
            'orderby' => 'meta_value',
            'order' => 'DESC',
            'meta_query' => [],
        ];

        if ($year) {
            $args['meta_query'][] = [
                'key' => 'chasedate',
                'value' => [$year . '0101', $year . '1231'],
                'compare' => 'BETWEEN',
                'type' => 'CHAR',
            ];
        }

        Storm_Chases::debug_log("Shortcode query args: " . print_r($args, true), 'info');

        $query = new WP_Query($args);

        Storm_Chases::debug_log("Shortcode found posts: " . $query->found_posts . " for transient $transient_key", 'info');

        $data_handler = new StormChasesData();

        if ($show_tornadoes) {
            $tornado_list = [];
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $chase_data = $data_handler->get_chase_data($post_id);
                $tornado_count = count($chase_data['tornadoes']);
                Storm_Chases::debug_log("Post ID $post_id: chasetornado = " . ($chase_data['chasetornado'] ?? 0) . ", tornadoes count = " . $tornado_count, 'info');

                // Only include posts with tornadoes if show_tornadoes is true
                if ($show_tornadoes && $tornado_count == 0) {
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

            Storm_Chases::debug_log("Total tornadoes in list: " . count($tornado_list), 'info');

            usort($tornado_list, function($a, $b) {
                return strcmp($b['date'], $a['date']);
            });

            wp_reset_postdata();

            ob_start();
            ?>
            <div class="storm-chases-tornadoes">
                <h2><?php echo $year ? esc_html(sprintf(__('Tornadoes for %s', 'stormchases'), $year)) : esc_html__('All Tornadoes', 'stormchases'); ?></h2>
                <?php if (!empty($tornado_list)) : ?>
                    <ul>
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
                    </ul>
                <?php else : ?>
                    <p><?php esc_html_e('No tornadoes recorded.', 'stormchases'); ?></p>
                <?php endif; ?>
            </div>
            <?php
            $output = ob_get_clean();
        } else {
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

            $unique_states = array_unique(array_filter($stats['total_states']));
            sort($unique_states);
            $stats['total_states'] = $unique_states;
            $stats['total_states_count'] = count($unique_states);
            $stats['tornado_day_percentage'] = $stats['total_chases'] > 0 ? ($stats['tornado_days'] / $stats['total_chases']) * 100 : 0;
            $stats['tornadoes_per_mile'] = $stats['total_miles'] > 0 ? $stats['total_tornadoes'] / $stats['total_miles'] : 0;

            wp_reset_postdata();

            ob_start();
            ?>
            <div class="storm-chases-stats">
                <h2><?php echo $year ? esc_html(sprintf(__('Chase Statistics for %s', 'stormchases'), $year)) : esc_html__('All-Time Chase Statistics', 'stormchases'); ?></h2>
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
                </ul>
            </div>
            <?php
            $output = ob_get_clean();
        }

        set_transient($transient_key, $output, DAY_IN_SECONDS);
        Storm_Chases::debug_log("Set transient for stats: $transient_key", 'info');
    }

    return $output;
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
                return $name . ' <img src="' . esc_url(STORM_CHASES_URL . '/assets/images/canada-flag.png') . '" alt="Canadian Flag" style="width: 23px; height: auto; vertical-align: middle;">';
            }
            return $name;
        }
        return $code;
    }, $codes);

    return implode(', ', $full_names);
}