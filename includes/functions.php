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

// Distinct 4-digit years that have at least one published storm_chase, newest first —
// drives the Chase Archive block's year dropdown (see blocks/chase-archive/index.js). Reads
// the individual 'chasedate' meta key (already relied on elsewhere for WP_Query ordering,
// see sc_modify_pre_get_posts()) rather than the serialized chase_data blob, so this can be
// one cheap DISTINCT query instead of loading every chase. Cached; invalidated alongside the
// other archive/stats transients in StormChaseTemplate::clear_transients().
function sc_get_chase_years(): array {
    $years = get_transient('sc_archive_years_v1');
    if (false !== $years) {
        return $years;
    }

    global $wpdb;
    $years = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT SUBSTRING(pm.meta_value, 1, 4) AS yr
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish'
             ORDER BY yr DESC",
            'chasedate',
            'storm_chase'
        )
    );
    $years = array_values(array_filter($years, fn($y) => preg_match('/^\d{4}$/', $y)));
    // An empty result is never cached — a site with any published chases at all finding zero
    // years is far more likely a transient DB inconsistency (e.g. mid-restore during a
    // database refresh — confirmed this actually happened once, silently emptying the Chase
    // Archive block's year dropdown for a full day until this cache expired) than a real
    // state worth trusting for the normal DAY_IN_SECONDS. A genuinely empty site just
    // re-runs this cheap query every time until it has a real chase to cache.
    if (!empty($years)) {
        set_transient('sc_archive_years_v1', $years, DAY_IN_SECONDS);
    }
    return $years;
}

// $args (used by the stormchases/chase-archive block — see includes/blocks.php): year,
// show, heading (custom override; '' = smart default), show_heading (bool). The block
// itself only ever exposes year/heading/showHeading (block.json) — show stays a real,
// separately-callable parameter (default 2000 — effectively unlimited in practice), not
// dead code.
//
// Only differentiates the transient key when heading/show_heading deviate from their
// defaults, matching sc_stats_transient_suffix()'s pattern below.
// StormChaseTemplate::clear_transients() wildcard-deletes sc_archive_v2_% regardless of
// any suffix, so this stays safe to extend.
function sc_archive_transient_suffix(array $args): string {
    $icon_defaults = ['show_tornado_icon' => false, 'show_hail_icon' => false, 'show_wind_icon' => false, 'show_reports_icon' => false];
    $icons_relevant = false;
    foreach ($icon_defaults as $key => $default_value) {
        if (($args[$key] ?? $default_value) !== $default_value) {
            $icons_relevant = true;
            break;
        }
    }
    if ($args['heading'] === '' && $args['show_heading'] === false && !$icons_relevant) {
        return '';
    }
    return '_' . md5(wp_json_encode([
        $args['heading'], $args['show_heading'],
        $args['show_tornado_icon'] ?? false, $args['show_hail_icon'] ?? false,
        $args['show_wind_icon'] ?? false, $args['show_reports_icon'] ?? false,
    ]));
}

function sc_render_chase_archive(array $args): string {
    $args = array_merge([
        'year'         => '',
        'show'         => 2000,
        'heading'      => '',
        'show_heading' => false,
        'show_tornado_icon' => false,
        'show_hail_icon'    => false,
        'show_wind_icon'    => false,
        'show_reports_icon' => false,
    ], $args);

    $year = $args['year'];
    $show = max(1, min(1000, (int) $args['show']));

    $transient_key = 'sc_archive_v2_' . ($year ?: 'all') . '_' . $show . sc_archive_transient_suffix($args);
    $output = get_transient($transient_key);

    if (false === $output) {
        $query_args = [
            'post_type' => 'storm_chase',
            'post_status' => 'publish',
            'posts_per_page' => $show,
            'meta_key' => 'chasedate',
            'orderby' => 'meta_value',
            'order' => 'DESC',
            'meta_query' => [],
        ];

        if ($year && preg_match('/^\d{4}$/', $year)) {
            $query_args['meta_query'][] = [
                'key' => 'chasedate',
                'value' => '^' . $year . '[0-1][0-9][0-3][0-9]',
                'compare' => 'REGEXP',
            ];
        }

        if (!empty($query_args['meta_query']) && count($query_args['meta_query']) > 1) {
            $query_args['meta_query']['relation'] = 'AND';
        }

        $query = new WP_Query($query_args);
        $data_handler = new StormChasesData();

        /* translators: %s: chase year */
        $heading = $args['heading'] !== ''
            ? esc_html($args['heading'])
            : ($year ? esc_html(sprintf(__('Storm Chases — %s', 'stormchases'), $year)) : esc_html__('Storm Chases', 'stormchases'));

        ob_start();
        ?>
        <div id="primary" class="content-area">
        <?php if ($args['show_heading']) : ?>
            <h2><?php echo $heading; ?></h2>
        <?php endif; ?>
        <ul class="storm-chase-archive">
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

                // Hurricane-type chases lead with the storm name(s) instead of the bare date —
                // "Hurricane Irma" reads far better in an archive list than "September 10, 2017"
                // on its own. The date is kept alongside it, just demoted, since it's still
                // useful context. Falls back to the plain date if a Hurricane-type chase somehow
                // has no landfalls logged yet (e.g. still being drafted).
                $storm_names = ('Hurricane' === ($chase_data['chase_type'] ?? 'Convective'))
                    ? array_values(array_unique(array_filter(array_map(
                        function($l) { return wp_unslash($l['name'] ?? ''); },
                        $chase_data['landfalls']
                    ))))
                    : [];
                if ($storm_names) {
                    $link_label = esc_html(implode(' / ', $storm_names))
                        . ' <span class="sc-archive-storm-date">(' . $chase_date . ')</span>';
                } else {
                    $link_label = $chase_date;
                }

                $icons_html = '';
                if ($args['show_tornado_icon'] || $args['show_hail_icon'] || $args['show_wind_icon'] || $args['show_reports_icon']) {
                    $icon_base = STORM_CHASES_URL . 'assets/images/';
                    $icon_items = [];

                    if ($args['show_tornado_icon'] && $tornado_count > 0) {
                        $icon_items[] = sprintf(
                            '<span class="sc-archive-icon" title="%1$s"><img src="%2$sreport-tornado.png" alt="%1$s" width="16" height="16">%3$d</span>',
                            esc_attr(sprintf(
                                /* translators: %d: number of tornadoes witnessed on this chase */
                                _n('%d tornado witnessed', '%d tornadoes witnessed', $tornado_count, 'stormchases'),
                                $tornado_count
                            )),
                            esc_url($icon_base),
                            $tornado_count
                        );
                    }

                    $hail = floatval($chase_data['chasehail'] ?? 0);
                    if ($args['show_hail_icon'] && $hail > 0) {
                        $icon_items[] = sprintf(
                            '<span class="sc-archive-icon" title="%1$s"><img src="%2$sreport-hail.png" alt="%1$s" width="16" height="16">%3$s"</span>',
                            esc_attr__('Largest hail observed', 'stormchases'),
                            esc_url($icon_base),
                            esc_html(number_format($hail, 2))
                        );
                    }

                    // Highest wind for the day: whichever is greater of the chase-level
                    // "Highest Wind Observed" field and any windspeed logged on a Spotter
                    // Network report for this chase.
                    $wind = floatval($chase_data['chasewind'] ?? 0);
                    foreach ($chase_data['spotter_reports'] as $report) {
                        $wind = max($wind, floatval($report['windspeed'] ?? 0));
                    }
                    if ($args['show_wind_icon'] && $wind > 0) {
                        $icon_items[] = sprintf(
                            '<span class="sc-archive-icon" title="%1$s"><img src="%2$sreport-wind.png" alt="%1$s" width="16" height="16">%3$s mph</span>',
                            esc_attr__('Highest wind observed', 'stormchases'),
                            esc_url($icon_base),
                            esc_html(number_format($wind))
                        );
                    }

                    $report_count = count($chase_data['spotter_reports']);
                    if ($args['show_reports_icon'] && $report_count > 0) {
                        $icon_items[] = sprintf(
                            '<span class="sc-archive-icon" title="%1$s"><img src="%2$sreport-generic.png" alt="%1$s" width="16" height="16">%3$d</span>',
                            esc_attr(sprintf(
                                /* translators: %d: number of Spotter Network reports logged on this chase */
                                _n('%d Spotter Network report', '%d Spotter Network reports', $report_count, 'stormchases'),
                                $report_count
                            )),
                            esc_url($icon_base),
                            $report_count
                        );
                    }

                    if ($icon_items) {
                        $icons_html = ' <span class="sc-archive-icons">' . implode('', $icon_items) . '</span>';
                    }
                }
                ?>
                <li<?php echo $storm_names ? ' class="sc-archive-hurricane"' : ''; ?>>
                    <a href="<?php echo esc_url(get_permalink()); ?>"><?php echo $link_label; ?></a> -
                    <?php echo $excerpt; ?> (<?php echo esc_html($miles); ?> <?php esc_html_e('Miles', 'stormchases'); ?>)<?php echo $icons_html; ?>
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

// $args (shared by both renderers below): year, heading (custom override; ''
// = smart default), show_heading (bool). Used by the stormchases/tornado-list
// and stormchases/chase-stats blocks — see includes/blocks.php.

// Only differentiates the transient key when heading/show_heading deviate
// from the defaults, so a default call's cache key stays byte-identical —
// StormChaseTemplate::clear_transients() wildcard-deletes storm_chases_stats_%
// regardless of any suffix, so this stays safe to extend per-block-instance.
function sc_stats_transient_suffix(array $args): string {
    $defaults = [
        'heading' => '', 'show_heading' => true, 'show_overall' => true, 'show_convective' => true,
        'show_hurricane' => true, 'show_winter' => true, 'show_longest_chase' => true,
        'show_ef_breakdown' => true, 'show_storm_modes' => true, 'show_top_days' => false, 'top_days_count' => 10,
        'show_first_last' => true, 'show_new_states' => true, 'show_streak' => true,
        'show_top_people' => false, 'top_people_count' => 10,
    ];
    $relevant = [];
    foreach ($defaults as $key => $default_value) {
        $relevant[$key] = $args[$key] ?? $default_value;
    }
    if ($relevant === $defaults) {
        return '';
    }
    return '_' . md5(wp_json_encode($relevant));
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

    /* translators: %s: chase year */
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

// Tag-based stat counting (Bust, Blue Sky Bust, Kiss of Death) — the user tags chase recap
// posts by hand with the WP post_tag taxonomy rather than a dedicated editor field, so this
// just checks whether any of the given candidate slugs/names is attached to the post.
// has_term() itself already matches against a term's slug, name, or ID for each candidate
// passed, so listing both the likely auto-slugified form (e.g. 'blue-sky-bust') and the
// literal typed-out name (e.g. 'blue sky bust') covers however the tag actually got created.
function sc_chase_has_tag($post_id, array $candidates): bool {
    return has_term($candidates, 'post_tag', $post_id);
}

// Renders a chase's Chase Partners/Chasers Encountered as clickable names (one modal per
// person — other chases they're on, labeled with which role each was, plus their website/
// location term-meta if set) when a name has a real chase_person term (see
// Storm_Chases_People_Taxonomies in includes/taxonomies.php — save_post() keeps that taxonomy
// auto-synced from chasepartners/chasechasers on every save). Names are read from
// $legacy_text (this chase's own chasepartners or chasechasers value), not get_the_terms() —
// chase_person is one shared taxonomy for both roles, so "who's on the Partners line vs. the
// Chasers line for *this* chase" can only come from which free-text field they're actually
// in, same as it always has. A name with no matching term yet (e.g. a chase saved before the
// taxonomy existed, never re-saved since) falls back to plain unlinked text for just that
// name, rather than failing the whole line.
function sc_render_person_list(int $post_id, string $legacy_text): string {
    $names = Storm_Chases_People_Taxonomies::parse_and_normalize_names($legacy_text);
    if (empty($names)) {
        return esc_html($legacy_text);
    }

    $data_handler = new StormChasesData();
    $items = [];
    $modal_html = '';

    foreach ($names as $name) {
        $term = get_term_by('name', $name, Storm_Chases_People_Taxonomies::PEOPLE_TAXONOMY);
        if (!$term || is_wp_error($term)) {
            $items[] = esc_html($name);
            continue;
        }

        $modal_id = esc_attr('sc-person-modal-' . $term->term_id . '-' . $post_id);
        $website = get_term_meta($term->term_id, 'website', true);
        $location = get_term_meta($term->term_id, 'location', true);

        $other_chases = get_posts([
            'post_type' => 'storm_chase',
            'post_status' => 'publish',
            'numberposts' => -1,
            'post__not_in' => [$post_id],
            'tax_query' => [['taxonomy' => Storm_Chases_People_Taxonomies::PEOPLE_TAXONOMY, 'field' => 'term_id', 'terms' => $term->term_id]],
            'meta_key' => 'chasedate',
            'orderby' => 'meta_value',
            'order' => 'DESC',
        ]);

        $items[] = '<a href="#" class="tornado-link" data-modal-id="' . $modal_id . '">' . esc_html($term->name) . '</a>';

        $modal_html .= '<div id="' . $modal_id . '" class="modal sc-map-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="' . $modal_id . '-title">';
        $modal_html .= '<div class="modal-overlay"></div><div class="modal-content">';
        $modal_html .= '<span class="modal-close" aria-label="' . esc_attr__('Close', 'stormchases') . '">&times;</span>';
        $modal_html .= '<h3 id="' . $modal_id . '-title">' . esc_html($term->name) . '</h3>';
        if ($location) {
            $modal_html .= '<p class="sc-person-location">' . esc_html($location) . '</p>';
        }
        if ($website) {
            $modal_html .= '<p><a href="' . esc_url($website) . '" target="_blank" rel="noopener">' . esc_html__('Visit Website', 'stormchases') . '</a></p>';
        }
        if ($other_chases) {
            $modal_html .= '<p><b>' . esc_html__('Other Chases:', 'stormchases') . '</b></p><ul>';
            foreach ($other_chases as $other) {
                $other_data = $data_handler->get_chase_data($other->ID);
                $date_label = !empty($other_data['chasedate']) && preg_match('/^\d{8}$/', $other_data['chasedate'])
                    ? date_i18n('F j, Y', strtotime($other_data['chasedate']))
                    : $other->post_title;

                // Which role(s) this particular other chase was logged under — derived from
                // that chase's own two free-text fields (a chase can carry both, if this
                // person was independently named in both fields for the same day), not from
                // the taxonomy — chase_person doesn't (and can't) encode role on its own.
                $roles = [];
                $other_partner_names = Storm_Chases_People_Taxonomies::parse_and_normalize_names($other_data['chasepartners'] ?? '');
                $other_chaser_names = Storm_Chases_People_Taxonomies::parse_and_normalize_names($other_data['chasechasers'] ?? '');
                if (in_array($term->name, $other_partner_names, true)) {
                    $roles[] = __('Chase Partner', 'stormchases');
                }
                if (in_array($term->name, $other_chaser_names, true)) {
                    $roles[] = __('Chaser Encountered', 'stormchases');
                }
                $role_suffix = $roles ? ' <span class="sc-person-role">(' . esc_html(implode(' & ', $roles)) . ')</span>' : '';

                $modal_html .= '<li><a href="' . esc_url(get_permalink($other)) . '">' . esc_html($date_label) . '</a>' . $role_suffix . '</li>';
            }
            $modal_html .= '</ul>';
        } else {
            $modal_html .= '<p>' . esc_html__('No other chases logged with this person yet.', 'stormchases') . '</p>';
        }
        $modal_html .= '</div></div>';
    }

    return implode(', ', $items) . $modal_html;
}

// Ordinal rank for "highest category reached" comparisons in sc_render_chase_stats() —
// higher number = stronger. Unknown/unrecognized values rank below everything (including
// 'Tropical Depression') so they never win a max() comparison against a real category.
function sc_landfall_category_rank($category): int {
    $rank = array_flip(['Tropical Depression', 'Tropical Storm', 'Category 1', 'Category 2', 'Category 3', 'Category 4', 'Category 5']);
    return $rank[$category] ?? -1;
}

// Reads storm_chases_best_chases, normalizing to year => [type => post_id]. Transparently
// migrates the pre-2.0.0 flat `year => post_id` shape (from before Chase Type existed, when
// every logged chase was implicitly Convective) to `year => ['Convective' => post_id]` the
// first time it's read after upgrading, persisting the migrated shape back so this only runs
// once. Use this everywhere storm_chases_best_chases is read; only save_post() in
// storm_chase.php writes to the option directly (it already has a normalized array in hand
// by the time it does).
function sc_get_best_chases(): array {
    $best_chases = get_option('storm_chases_best_chases', []);
    if (!is_array($best_chases)) {
        return [];
    }
    $needs_migration = false;
    $normalized = [];
    foreach ($best_chases as $year => $value) {
        if (is_array($value)) {
            $normalized[$year] = $value;
        } else {
            $normalized[$year] = ['Convective' => $value];
            $needs_migration = true;
        }
    }
    if ($needs_migration) {
        update_option('storm_chases_best_chases', $normalized);
    }
    return $normalized;
}

// Earliest chased year per state/province code, across every published chase regardless of
// year — backs the "new state" callout in sc_render_chase_stats() (a state whose earliest
// year equals the year currently being viewed is new-to-you that season). Single global-key
// transient (like sc_get_chase_years()'s sc_archive_years_v1), not per-post/wildcarded —
// StormChaseTemplate::clear_transients() deletes it directly.
function sc_get_first_chase_year_by_state(): array {
    $cached = get_transient('sc_first_state_year_v1');
    if (false !== $cached) {
        return $cached;
    }

    $query = new WP_Query([
        'post_type' => 'storm_chase',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]);
    $data_handler = new StormChasesData();
    $first_year = [];
    foreach ($query->posts as $post_id) {
        $chase_data = $data_handler->get_chase_data($post_id);
        if (empty($chase_data['chasedate']) || !preg_match('/^\d{8}$/', $chase_data['chasedate']) || empty($chase_data['chasestates'])) {
            continue;
        }
        $year = substr($chase_data['chasedate'], 0, 4);
        $states = array_map('trim', explode(',', wp_unslash($chase_data['chasestates'])));
        foreach ($states as $state) {
            if ($state === '') {
                continue;
            }
            if (!isset($first_year[$state]) || $year < $first_year[$state]) {
                $first_year[$state] = $year;
            }
        }
    }

    // Same reasoning as sc_get_chase_years() just above — don't cache an empty result for a
    // full day, since that's far more likely a transient DB inconsistency than a real site
    // with published chases but no state ever chased.
    if (!empty($first_year)) {
        set_transient('sc_first_state_year_v1', $first_year, DAY_IN_SECONDS);
    }
    return $first_year;
}

// Longest and current consecutive-year chase streaks from a list of chased years (any order,
// duplicates OK — sc_get_chase_years()'s output is the intended input). "Current" only counts
// if the most recent chased year is this year or last (so a streak that ended years ago
// doesn't misleadingly still read as "current").
function sc_calculate_chase_streaks(array $years): array {
    $years = array_values(array_unique(array_map('intval', $years)));
    sort($years);
    if (empty($years)) {
        return ['longest' => 0, 'current' => 0];
    }

    $longest = 1;
    $run = 1;
    for ($i = 1; $i < count($years); $i++) {
        if ($years[$i] === $years[$i - 1] + 1) {
            $run++;
        } else {
            $longest = max($longest, $run);
            $run = 1;
        }
    }
    $longest = max($longest, $run);

    $current = 0;
    $latest_year = end($years);
    if ($latest_year >= ((int) current_time('Y')) - 1) {
        $current = 1;
        for ($i = count($years) - 1; $i > 0; $i--) {
            if ($years[$i] === $years[$i - 1] + 1) {
                $current++;
            } else {
                break;
            }
        }
    }

    return ['longest' => $longest, 'current' => $current];
}

// Top N people for a specific role (chasepartners or chasechasers), by chase count — derived
// by scanning every published chase's own free-text field, not a taxonomy term count. This is
// necessary because chase_person is one shared taxonomy for both roles (see taxonomies.php):
// a term's own post count mixes "chased with" and "ran into" together and can't answer "top
// chase partners" specifically on its own. $meta_field is 'chasepartners' or 'chasechasers'.
// Cached (single global key per role, like sc_get_chase_years()); invalidated in
// sc_clear_transients(). Returns [] if nothing's been logged in that field yet — callers
// should treat that as "nothing to show here," not an error.
function sc_get_top_people_by_role(string $meta_field, int $limit = 10): array {
    $cache_key = 'sc_top_people_' . $meta_field . '_v1';
    $cached = get_transient($cache_key);
    if (false !== $cached) {
        return array_slice($cached, 0, max(1, $limit));
    }

    $post_ids = get_posts([
        'post_type' => 'storm_chase',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields' => 'ids',
    ]);
    $data_handler = new StormChasesData();
    $counts = [];
    foreach ($post_ids as $post_id) {
        $chase_data = $data_handler->get_chase_data($post_id);
        $names = array_unique(Storm_Chases_People_Taxonomies::parse_and_normalize_names($chase_data[$meta_field] ?? ''));
        foreach ($names as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
    }
    arsort($counts);

    $results = [];
    foreach ($counts as $name => $count) {
        $term = get_term_by('name', $name, Storm_Chases_People_Taxonomies::PEOPLE_TAXONOMY);
        $link = ($term && !is_wp_error($term)) ? get_term_link($term) : '';
        $results[] = [
            'name' => $name,
            'count' => $count,
            'link' => is_wp_error($link) ? '' : $link,
        ];
    }

    // Same reasoning as sc_get_chase_years()'s empty-result guard — don't cache "nothing
    // found" for a full day if it's possibly just a transient inconsistency.
    if (!empty($results)) {
        set_transient($cache_key, $results, DAY_IN_SECONDS);
    }
    return array_slice($results, 0, max(1, $limit));
}

function sc_render_chase_stats(array $args): string {
    $args = array_merge([
        'year' => '', 'heading' => '', 'show_heading' => true,
        'show_overall' => true, 'show_convective' => true, 'show_hurricane' => true, 'show_winter' => true,
        'show_longest_chase' => true, 'show_ef_breakdown' => true, 'show_storm_modes' => true,
        'show_top_days' => false, 'top_days_count' => 10,
        'show_first_last' => true, 'show_new_states' => true, 'show_streak' => true,
        'show_top_people' => false, 'top_people_count' => 10,
    ], $args);
    $year = $args['year'];
    $top_days_count = max(1, min(100, (int) $args['top_days_count']));
    $top_people_count = max(1, min(50, (int) $args['top_people_count']));

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
        // Overall — spans every chase type, since these aren't inherently convective-specific.
        'total_chases' => 0,
        'total_miles' => 0,
        'total_states' => [],
        'state_counts' => [],
        'spotter_reports' => 0,
        'longest_chase_miles' => 0,
        'longest_chase_permalink' => '',
        'longest_chase_date' => '',
        'new_states' => [],
        'all_chases' => [],
        // Convective — scoped to chase_type === 'Convective' only (see the comment in the
        // loop below for why).
        'convective_chases' => 0,
        'total_tornadoes' => 0,
        'photogenic_tornadoes' => 0,
        'tornado_days' => 0,
        'largest_hail' => 0,
        'highest_wind' => 0,
        'busts' => 0,
        'blue_sky_busts' => 0,
        'kiss_of_death_days' => 0,
        'ef_breakdown' => [],
        'storm_mode_counts' => [],
        'first_tornado_day' => '',
        'first_tornado_day_permalink' => '',
        'last_tornado_day' => '',
        'last_tornado_day_permalink' => '',
        // Hurricane
        'hurricane_chases' => 0,
        'hurricane_landfalls' => 0,
        'hurricane_names' => [],
        'hurricane_highest_category' => '',
        'hurricane_lowest_pressure' => null,
        'hurricane_highest_wind' => 0,
        'first_landfall_day' => '',
        'first_landfall_day_permalink' => '',
        'last_landfall_day' => '',
        'last_landfall_day_permalink' => '',
        // Winter
        'winter_chases' => 0,
        'snowfall_reports_count' => 0,
        'snowfall_total_depth' => 0,
        'snowfall_max_depth' => 0,
        'snowfall_max_location' => '',
        // Best Chase of the Season, per type — resolved after the loop, only when $year is set.
        'best_chase' => [],
    ];

    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $chase_data = $data_handler->get_chase_data($post_id);
        $tornado_count = count($chase_data['tornadoes']);
        $chase_type = $chase_data['chase_type'] ?? 'Convective';
        Storm_Chases::debug_log("Post ID $post_id: chasetornado = " . ($chase_data['chasetornado'] ?? 0) . ", tornadoes count = " . $tornado_count, 'info');

        $stats['total_chases']++;
        $stats['total_miles'] += $chase_data['chasemiles'];
        if ($chase_data['chasemiles'] > $stats['longest_chase_miles']) {
            $stats['longest_chase_miles'] = $chase_data['chasemiles'];
            $stats['longest_chase_permalink'] = get_permalink($post_id);
            $stats['longest_chase_date'] = $chase_data['chasedate'];
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

        if ($args['show_top_days']) {
            $stats['all_chases'][] = [
                'permalink' => get_permalink($post_id),
                'date' => $chase_data['chasedate'],
                'type' => $chase_type,
                'tornadoes' => $tornado_count,
                'miles' => (int) $chase_data['chasemiles'],
                'hail' => (float) $chase_data['chasehail'],
                'wind' => (int) $chase_data['chasewind'],
            ];
        }

        // Convective-specific stats are scoped to chase_type === 'Convective' rather than
        // blended across every type — a tornado, hail report, or wind gust logged during a
        // Hurricane- or Winter-type chase is real data, but counting it here would make the
        // Convective section's numbers (Tornado Day %, Busts, etc.) not actually mean what
        // their labels say. Mirrors the same per-type organizing principle already used for
        // Hurricane's landfalls and Winter's snowfall reports below.
        $has_valid_date = !empty($chase_data['chasedate']) && preg_match('/^\d{8}$/', $chase_data['chasedate']);

        if ($chase_type === 'Convective') {
            $stats['convective_chases']++;
            $stats['total_tornadoes'] += $tornado_count;
            if ($tornado_count > 0) {
                $stats['tornado_days']++;
                foreach ($chase_data['tornadoes'] as $tornado) {
                    if ($tornado['photogenic']) {
                        $stats['photogenic_tornadoes']++;
                    }
                    $ef = !empty($tornado['ef_rating']) ? $tornado['ef_rating'] : 'Unrated';
                    $stats['ef_breakdown'][$ef] = ($stats['ef_breakdown'][$ef] ?? 0) + 1;
                }
                // First/last tornado day of the season — chasedate strings compare correctly
                // as plain strings since they're always zero-padded 8-digit YYYYMMDD.
                if ($has_valid_date) {
                    if ($stats['first_tornado_day'] === '' || $chase_data['chasedate'] < $stats['first_tornado_day']) {
                        $stats['first_tornado_day'] = $chase_data['chasedate'];
                        $stats['first_tornado_day_permalink'] = get_permalink($post_id);
                    }
                    if ($stats['last_tornado_day'] === '' || $chase_data['chasedate'] > $stats['last_tornado_day']) {
                        $stats['last_tornado_day'] = $chase_data['chasedate'];
                        $stats['last_tornado_day_permalink'] = get_permalink($post_id);
                    }
                }
            }
            $stats['largest_hail'] = max($stats['largest_hail'], $chase_data['chasehail']);
            $stats['highest_wind'] = max($stats['highest_wind'], $chase_data['chasewind']);
            foreach ($chase_data['storm_mode'] as $mode) {
                $stats['storm_mode_counts'][$mode] = ($stats['storm_mode_counts'][$mode] ?? 0) + 1;
            }
            if (sc_chase_has_tag($post_id, ['bust'])) {
                $stats['busts']++;
            }
            if (sc_chase_has_tag($post_id, ['blue sky bust', 'blue-sky-bust'])) {
                $stats['blue_sky_busts']++;
            }
            if (sc_chase_has_tag($post_id, ['kissofdeath', 'kiss of death', 'kiss-of-death'])) {
                $stats['kiss_of_death_days']++;
            }
        }

        if ($chase_type === 'Hurricane') {
            $stats['hurricane_chases']++;
        }
        foreach ($chase_data['landfalls'] as $landfall) {
            $stats['hurricane_landfalls']++;
            if (!empty($landfall['name'])) {
                $stats['hurricane_names'][$landfall['name']] = true; // dedupe via key
            }
            $category = $landfall['category'] ?? 'Unknown';
            if (sc_landfall_category_rank($category) > sc_landfall_category_rank($stats['hurricane_highest_category'])) {
                $stats['hurricane_highest_category'] = $category;
            }
            if (!empty($landfall['pressure']) && ($stats['hurricane_lowest_pressure'] === null || $landfall['pressure'] < $stats['hurricane_lowest_pressure'])) {
                $stats['hurricane_lowest_pressure'] = $landfall['pressure'];
            }
            $stats['hurricane_highest_wind'] = max($stats['hurricane_highest_wind'], $landfall['wind_speed'] ?? 0);
        }
        if (!empty($chase_data['landfalls']) && $has_valid_date) {
            if ($stats['first_landfall_day'] === '' || $chase_data['chasedate'] < $stats['first_landfall_day']) {
                $stats['first_landfall_day'] = $chase_data['chasedate'];
                $stats['first_landfall_day_permalink'] = get_permalink($post_id);
            }
            if ($stats['last_landfall_day'] === '' || $chase_data['chasedate'] > $stats['last_landfall_day']) {
                $stats['last_landfall_day'] = $chase_data['chasedate'];
                $stats['last_landfall_day_permalink'] = get_permalink($post_id);
            }
        }

        if ($chase_type === 'Winter') {
            $stats['winter_chases']++;
        }
        foreach ($chase_data['snowfall_reports'] as $snow) {
            $stats['snowfall_reports_count']++;
            $depth = floatval($snow['depth'] ?? 0);
            $stats['snowfall_total_depth'] += $depth;
            if ($depth > $stats['snowfall_max_depth']) {
                $stats['snowfall_max_depth'] = $depth;
                $stats['snowfall_max_location'] = $snow['location'] ?? '';
            }
        }
    }

    // Resolve Best Chase of the Season for the requested year, per type.
    if ($year) {
        $best_chases = sc_get_best_chases();
        foreach (['Convective', 'Hurricane', 'Winter'] as $type) {
            $best_post_id = $best_chases[$year][$type] ?? null;
            if (!$best_post_id) {
                continue;
            }
            $best_post = get_post($best_post_id);
            if ($best_post && $best_post->post_status === 'publish') {
                $best_data = $data_handler->get_chase_data($best_post_id);
                $stats['best_chase'][$type] = [
                    'date' => $best_data['chasedate'] ?? '',
                    'permalink' => get_permalink($best_post_id),
                ];
            }
        }
    }

    // "New state" callout — only meaningful scoped to a single year (an all-time view has
    // nothing to compare against). sc_get_first_chase_year_by_state() is a cheap
    // day-cached lookup, not a second heavy query per state.
    if ($year && $args['show_new_states']) {
        $first_year_by_state = sc_get_first_chase_year_by_state();
        foreach (array_keys($stats['state_counts']) as $state) {
            if (($first_year_by_state[$state] ?? null) === $year) {
                $stats['new_states'][] = $state;
            }
        }
        sort($stats['new_states']);
    }

    // Consecutive-year chase streak — only meaningful all-time (a single-year view has no
    // "streak" of its own).
    $stats['chase_streak'] = ['longest' => 0, 'current' => 0];
    if (!$year && $args['show_streak']) {
        $stats['chase_streak'] = sc_calculate_chase_streaks(sc_get_chase_years());
    }

    $unique_states = array_unique(array_filter($stats['total_states']));
    sort($unique_states);
    $stats['total_states'] = $unique_states;
    $stats['total_states_count'] = count($unique_states);
    $stats['tornado_day_percentage'] = $stats['convective_chases'] > 0 ? ($stats['tornado_days'] / $stats['convective_chases']) * 100 : 0;
    $stats['tornadoes_per_mile'] = $stats['total_miles'] > 0 ? $stats['total_tornadoes'] / $stats['total_miles'] : 0;
    $stats['avg_miles_per_chase'] = $stats['total_chases'] > 0 ? $stats['total_miles'] / $stats['total_chases'] : 0;
    $stats['avg_miles_per_tornado'] = $stats['total_tornadoes'] > 0 ? $stats['total_miles'] / $stats['total_tornadoes'] : 0;

    $hurricane_names = array_keys($stats['hurricane_names']);
    sort($hurricane_names);
    $stats['hurricane_names'] = $hurricane_names;

    wp_reset_postdata();

    /* translators: %s: chase year */
    $heading = $args['heading'] !== ''
        ? esc_html($args['heading'])
        : ($year ? esc_html(sprintf(__('Chase Statistics for %s', 'stormchases'), $year)) : esc_html__('All-Time Chase Statistics', 'stormchases'));

    ob_start();
    ?>
    <div class="storm-chases-stats">
        <?php if ($args['show_heading']) : ?>
            <h2><img src="<?php echo esc_url(STORM_CHASES_URL . 'assets/images/tornado-icon.png'); ?>" alt="" class="sc-stats-heading-icon" width="20" height="18"> <?php echo $heading; ?></h2>
        <?php endif; ?>

        <?php if ($args['show_overall']) : ?>
        <ul>
            <li><strong><?php esc_html_e('Chase Days:', 'stormchases'); ?></strong> <?php echo esc_html($stats['total_chases']); ?></li>
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
            <?php if ($stats['total_chases'] > 0) : ?>
            <li><strong><?php esc_html_e('Average Miles per Chase Day:', 'stormchases'); ?></strong> <?php echo esc_html(number_format($stats['avg_miles_per_chase'], 1)); ?></li>
            <?php endif; ?>
            <?php if ($args['show_longest_chase'] && $stats['longest_chase_miles'] > 0) :
                $lc_date = $stats['longest_chase_date'] && preg_match('/^\d{8}$/', $stats['longest_chase_date'])
                    ? date_i18n('F j, Y', strtotime($stats['longest_chase_date']))
                    : '';
            ?>
            <li><strong><?php esc_html_e('Longest Chase:', 'stormchases'); ?></strong>
                <?php echo esc_html(number_format($stats['longest_chase_miles'])); ?> <?php esc_html_e('miles', 'stormchases'); ?>
                <?php if ($lc_date) : ?>
                    (<a href="<?php echo esc_url($stats['longest_chase_permalink']); ?>"><?php echo esc_html($lc_date); ?></a>)
                <?php endif; ?>
            </li>
            <?php endif; ?>
            <?php if ($args['show_new_states'] && !empty($stats['new_states'])) :
                $new_state_names = array_map('storm_chases_get_full_state_names', $stats['new_states']);
            ?>
            <li><strong><?php esc_html_e('New States This Season:', 'stormchases'); ?></strong> <?php echo esc_html(implode(', ', $new_state_names)); ?></li>
            <?php endif; ?>
            <?php if ($args['show_streak'] && $stats['chase_streak']['longest'] > 1) : ?>
            <li><strong><?php esc_html_e('Longest Chase Streak:', 'stormchases'); ?></strong>
                <?php /* translators: %d: number of consecutive years chased */ ?>
                <?php echo esc_html(sprintf(_n('%d year', '%d years', $stats['chase_streak']['longest'], 'stormchases'), $stats['chase_streak']['longest'])); ?>
                <?php if ($stats['chase_streak']['current'] > 1 && $stats['chase_streak']['current'] === $stats['chase_streak']['longest']) : ?>
                    <?php esc_html_e('(current)', 'stormchases'); ?>
                <?php elseif ($stats['chase_streak']['current'] > 1) : ?>
                    — <?php echo esc_html(sprintf(
                        /* translators: %d: number of consecutive years in the current active streak */
                        __('currently on a %d-year streak', 'stormchases'),
                        $stats['chase_streak']['current']
                    )); ?>
                <?php endif; ?>
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
        <?php endif; ?>

        <?php // All-time only — sc_get_top_people_by_role() scans every chase's own
              // chasepartners/chasechasers text, which has no year dimension to filter by.
              // Showing it inside a year-scoped block instance would be misleading (an
              // all-time count labeled as if it were that year's), so this only ever renders
              // in the all-time view, same reasoning as the streak stat. ?>
        <?php if (!$year && $args['show_top_people']) :
            $top_partners = sc_get_top_people_by_role('chasepartners', $top_people_count);
            $top_chasers = sc_get_top_people_by_role('chasechasers', $top_people_count);
        ?>
        <?php if ($top_partners || $top_chasers) : ?>
        <div class="storm-chases-stats-people">
            <?php if ($top_partners) : ?>
            <h3><?php esc_html_e('Top Chase Partners', 'stormchases'); ?></h3>
            <ul>
                <?php foreach ($top_partners as $person) : ?>
                <li>
                    <?php if ($person['link']) : ?>
                        <a href="<?php echo esc_url($person['link']); ?>"><?php echo esc_html($person['name']); ?></a>
                    <?php else : ?>
                        <?php echo esc_html($person['name']); ?>
                    <?php endif; ?>
                    <?php /* translators: %d: number of chases with this partner */ ?>
                    — <?php echo esc_html(sprintf(_n('%d chase', '%d chases', $person['count'], 'stormchases'), $person['count'])); ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($top_chasers) : ?>
            <h3><?php esc_html_e('Most Encountered Chasers', 'stormchases'); ?></h3>
            <ul>
                <?php foreach ($top_chasers as $person) : ?>
                <li>
                    <?php if ($person['link']) : ?>
                        <a href="<?php echo esc_url($person['link']); ?>"><?php echo esc_html($person['name']); ?></a>
                    <?php else : ?>
                        <?php echo esc_html($person['name']); ?>
                    <?php endif; ?>
                    <?php /* translators: %d: number of chases where this chaser was encountered */ ?>
                    — <?php echo esc_html(sprintf(_n('%d chase', '%d chases', $person['count'], 'stormchases'), $person['count'])); ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($args['show_convective'] && $stats['convective_chases'] > 0) : ?>
        <div class="storm-chases-stats-convective">
            <h3><img src="<?php echo esc_url(STORM_CHASES_URL . 'assets/images/tornado-icon.png'); ?>" alt="" class="sc-stats-heading-icon" width="18" height="16"> <?php esc_html_e('Convective', 'stormchases'); ?></h3>
            <ul>
                <li><strong><?php esc_html_e('Chase Days:', 'stormchases'); ?></strong> <?php echo esc_html($stats['convective_chases']); ?></li>
                <li><strong><?php esc_html_e('Tornado Days:', 'stormchases'); ?></strong> <?php echo esc_html($stats['tornado_days']); ?></li>
                <li><strong><?php esc_html_e('Tornadoes:', 'stormchases'); ?></strong> <?php echo esc_html($stats['total_tornadoes']); ?></li>
                <li><strong><?php esc_html_e('Photogenic Tornadoes:', 'stormchases'); ?></strong> <?php echo esc_html($stats['photogenic_tornadoes']); ?></li>
                <li><strong><?php esc_html_e('Largest Hail Observed:', 'stormchases'); ?></strong>
                    <?php echo $stats['largest_hail'] > 0 ? esc_html(number_format($stats['largest_hail'], 2) . ' in.') : esc_html__('None', 'stormchases'); ?>
                </li>
                <li><strong><?php esc_html_e('Highest Wind Observed:', 'stormchases'); ?></strong>
                    <?php echo $stats['highest_wind'] > 0 ? esc_html($stats['highest_wind'] . ' MPH') : esc_html__('None', 'stormchases'); ?>
                </li>
                <li><strong><?php esc_html_e('Tornado Day Percentage:', 'stormchases'); ?></strong>
                    <?php echo esc_html(number_format($stats['tornado_day_percentage'], 2) . '%'); ?>
                    <span class="sc-stat-info" tabindex="0" aria-label="<?php esc_attr_e('How is Tornado Day Percentage calculated?', 'stormchases'); ?>">&#9432;<span class="sc-stat-tooltip"><?php esc_html_e('Tornado Days divided by Convective Chase Days — the share of convective chase days that produced at least one witnessed tornado.', 'stormchases'); ?></span></span>
                </li>
                <li><strong><?php esc_html_e('Tornadoes per Mile:', 'stormchases'); ?></strong>
                    <?php echo esc_html(number_format($stats['tornadoes_per_mile'], 4)); ?>
                    <span class="sc-stat-info" tabindex="0" aria-label="<?php esc_attr_e('How is Tornadoes per Mile calculated?', 'stormchases'); ?>">&#9432;<span class="sc-stat-tooltip"><?php esc_html_e('Total tornadoes witnessed divided by total miles driven — a rough measure of chasing efficiency. Higher means more tornadoes for the driving effort.', 'stormchases'); ?></span></span>
                </li>
                <?php if ($stats['total_tornadoes'] > 0) : ?>
                <li><strong><?php esc_html_e('Average Miles per Tornado:', 'stormchases'); ?></strong>
                    <?php echo esc_html(number_format($stats['avg_miles_per_tornado'], 1)); ?>
                    <span class="sc-stat-info" tabindex="0" aria-label="<?php esc_attr_e('How is Average Miles per Tornado calculated?', 'stormchases'); ?>">&#9432;<span class="sc-stat-tooltip"><?php esc_html_e('Total miles driven divided by total tornadoes witnessed — the same ratio as Tornadoes per Mile, just flipped into "miles per tornado" instead of "tornadoes per mile."', 'stormchases'); ?></span></span>
                </li>
                <?php endif; ?>
                <?php if ($year && $args['show_first_last'] && $stats['first_tornado_day']) :
                    $ft_date = date_i18n('F j', strtotime($stats['first_tornado_day']));
                    $lt_date = date_i18n('F j', strtotime($stats['last_tornado_day']));
                ?>
                <li><strong><?php esc_html_e('First Tornado of the Season:', 'stormchases'); ?></strong> <a href="<?php echo esc_url($stats['first_tornado_day_permalink']); ?>"><?php echo esc_html($ft_date); ?></a></li>
                <?php if ($stats['last_tornado_day'] !== $stats['first_tornado_day']) : ?>
                <li><strong><?php esc_html_e('Last Tornado of the Season:', 'stormchases'); ?></strong> <a href="<?php echo esc_url($stats['last_tornado_day_permalink']); ?>"><?php echo esc_html($lt_date); ?></a></li>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (get_option('busts_enable', true)) : ?>
                <li><strong><?php esc_html_e('Busts:', 'stormchases'); ?></strong> <?php echo esc_html($stats['busts']); ?>
                    <span class="sc-stat-info" tabindex="0" aria-label="<?php esc_attr_e('What is a Bust?', 'stormchases'); ?>">&#9432;<span class="sc-stat-tooltip"><?php esc_html_e('A "Bust" day: storms were expected and something did fire, but the setup underperformed the forecast — a weaker, shorter-lived, or less organized event than anticipated.', 'stormchases'); ?></span></span>
                </li>
                <li><strong><?php esc_html_e('Blue Sky Busts:', 'stormchases'); ?></strong> <?php echo esc_html($stats['blue_sky_busts']); ?>
                    <span class="sc-stat-info" tabindex="0" aria-label="<?php esc_attr_e('What is a Blue Sky Bust?', 'stormchases'); ?>">&#9432;<span class="sc-stat-tooltip"><?php esc_html_e('A "Blue Sky Bust" day: nothing fired at all — clear skies despite a forecast that called for storms.', 'stormchases'); ?></span></span>
                </li>
                <?php endif; ?>
                <?php if (get_option('kiss_of_death_enable', true)) : ?>
                <li><strong><?php esc_html_e('Kiss of Death Days:', 'stormchases'); ?></strong>
                    <?php echo esc_html($stats['kiss_of_death_days']); ?>
                    <span class="sc-stat-info" tabindex="0" aria-label="<?php esc_attr_e('What is Kiss of Death?', 'stormchases'); ?>">&#9432;<span class="sc-stat-tooltip"><?php esc_html_e('A "Kiss of Death" day: the SPC tornado outlook included a 15% hatched (significant) tornado probability within a Slight, Enhanced, or Moderate risk (Categorical Risk 1–3) — a favorable-looking setup that historically often underperforms relative to the risk level.', 'stormchases'); ?></span></span>
                </li>
                <?php endif; ?>
                <?php if ($args['show_ef_breakdown'] && !empty($stats['ef_breakdown'])) :
                    $ef_order = ['EF-5', 'EF-4', 'EF-3', 'EF-2', 'EF-1', 'EF-0'];
                    $ef_display = [];
                    foreach ($ef_order as $ef) {
                        if (!empty($stats['ef_breakdown'][$ef])) {
                            $ef_display[] = $ef . ': ' . $stats['ef_breakdown'][$ef];
                        }
                    }
                    foreach ($stats['ef_breakdown'] as $ef => $count) {
                        if (!in_array($ef, $ef_order, true)) {
                            $ef_display[] = $ef . ': ' . $count;
                        }
                    }
                ?>
                <li><strong><?php esc_html_e('EF Rating Breakdown:', 'stormchases'); ?></strong> <?php echo esc_html(implode(', ', $ef_display)); ?></li>
                <?php endif; ?>
                <?php if ($args['show_storm_modes'] && !empty($stats['storm_mode_counts'])) :
                    $mode_display = [];
                    foreach (StormChasesData::get_storm_modes() as $mode) {
                        if (!empty($stats['storm_mode_counts'][$mode])) {
                            $mode_display[] = $mode . ': ' . $stats['storm_mode_counts'][$mode];
                        }
                    }
                ?>
                <li><strong><?php esc_html_e('Storm Modes:', 'stormchases'); ?></strong> <?php echo esc_html(implode(', ', $mode_display)); ?></li>
                <?php endif; ?>
                <?php if (get_option('best_chase_enable') && !empty($stats['best_chase']['Convective'])) :
                    $bc_date = date_i18n('F j', strtotime($stats['best_chase']['Convective']['date']));
                ?>
                <li><strong><?php esc_html_e('Best Chase Day:', 'stormchases'); ?></strong>
                    <a href="<?php echo esc_url($stats['best_chase']['Convective']['permalink']); ?>"><?php echo esc_html($bc_date); ?></a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($args['show_hurricane'] && $stats['hurricane_chases'] > 0) : ?>
        <div class="storm-chases-stats-hurricanes">
            <h3><img src="<?php echo esc_url(STORM_CHASES_URL . 'assets/images/map_hurricane.png'); ?>" alt="" class="sc-stats-heading-icon" width="18" height="18"> <?php esc_html_e('Hurricanes', 'stormchases'); ?></h3>
            <ul>
                <li><strong><?php esc_html_e('Hurricane Chases:', 'stormchases'); ?></strong> <?php echo esc_html($stats['hurricane_chases']); ?></li>
                <li><strong><?php esc_html_e('Landfalls Witnessed:', 'stormchases'); ?></strong> <?php echo esc_html($stats['hurricane_landfalls']); ?></li>
                <li><strong><?php esc_html_e('Storms:', 'stormchases'); ?></strong> <?php echo $stats['hurricane_names'] ? esc_html(implode(', ', $stats['hurricane_names'])) : esc_html__('None named', 'stormchases'); ?></li>
                <?php if ($stats['hurricane_highest_category']) : ?>
                <li><strong><?php esc_html_e('Highest Category Witnessed:', 'stormchases'); ?></strong> <?php echo esc_html($stats['hurricane_highest_category']); ?></li>
                <?php endif; ?>
                <?php if ($stats['hurricane_lowest_pressure'] !== null) : ?>
                <li><strong><?php esc_html_e('Lowest Pressure Recorded:', 'stormchases'); ?></strong> <?php echo esc_html($stats['hurricane_lowest_pressure']); ?> mb</li>
                <?php endif; ?>
                <?php if ($stats['hurricane_highest_wind'] > 0) : ?>
                <li><strong><?php esc_html_e('Highest Landfall Wind:', 'stormchases'); ?></strong> <?php echo esc_html($stats['hurricane_highest_wind']); ?> MPH</li>
                <?php endif; ?>
                <?php if ($year && $args['show_first_last'] && $stats['first_landfall_day']) :
                    $fl_date = date_i18n('F j', strtotime($stats['first_landfall_day']));
                    $ll_date = date_i18n('F j', strtotime($stats['last_landfall_day']));
                ?>
                <li><strong><?php esc_html_e('First Landfall of the Season:', 'stormchases'); ?></strong> <a href="<?php echo esc_url($stats['first_landfall_day_permalink']); ?>"><?php echo esc_html($fl_date); ?></a></li>
                <?php if ($stats['last_landfall_day'] !== $stats['first_landfall_day']) : ?>
                <li><strong><?php esc_html_e('Last Landfall of the Season:', 'stormchases'); ?></strong> <a href="<?php echo esc_url($stats['last_landfall_day_permalink']); ?>"><?php echo esc_html($ll_date); ?></a></li>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (get_option('best_chase_enable') && !empty($stats['best_chase']['Hurricane'])) :
                    $bhc_date = date_i18n('F j', strtotime($stats['best_chase']['Hurricane']['date']));
                ?>
                <li><strong><?php esc_html_e('Best Hurricane Chase Day:', 'stormchases'); ?></strong>
                    <a href="<?php echo esc_url($stats['best_chase']['Hurricane']['permalink']); ?>"><?php echo esc_html($bhc_date); ?></a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($args['show_winter'] && $stats['winter_chases'] > 0) : ?>
        <div class="storm-chases-stats-winter">
            <h3><img src="<?php echo esc_url(STORM_CHASES_URL . 'assets/images/snowflake-icon.png'); ?>" alt="" class="sc-stats-heading-icon" width="18" height="18"> <?php esc_html_e('Winter', 'stormchases'); ?></h3>
            <ul>
                <li><strong><?php esc_html_e('Winter Chase Days:', 'stormchases'); ?></strong> <?php echo esc_html($stats['winter_chases']); ?></li>
                <li><strong><?php esc_html_e('Snowfall Reports:', 'stormchases'); ?></strong> <?php echo esc_html($stats['snowfall_reports_count']); ?></li>
                <?php if ($stats['snowfall_total_depth'] > 0) : ?>
                <li><strong><?php esc_html_e('Total Snowfall Recorded:', 'stormchases'); ?></strong> <?php echo esc_html(number_format($stats['snowfall_total_depth'], 1)); ?> in.</li>
                <?php endif; ?>
                <?php if ($stats['snowfall_max_depth'] > 0) : ?>
                <li><strong><?php esc_html_e('Biggest Single Report:', 'stormchases'); ?></strong>
                    <?php echo esc_html(number_format($stats['snowfall_max_depth'], 1)); ?> in.<?php echo $stats['snowfall_max_location'] ? ' — ' . esc_html($stats['snowfall_max_location']) : ''; ?>
                </li>
                <?php endif; ?>
                <?php if (get_option('best_chase_enable') && !empty($stats['best_chase']['Winter'])) :
                    $bwc_date = date_i18n('F j', strtotime($stats['best_chase']['Winter']['date']));
                ?>
                <li><strong><?php esc_html_e('Best Winter Chase Day:', 'stormchases'); ?></strong>
                    <a href="<?php echo esc_url($stats['best_chase']['Winter']['permalink']); ?>"><?php echo esc_html($bwc_date); ?></a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($args['show_top_days'] && !empty($stats['all_chases'])) :
            $top_days = $stats['all_chases'];
            usort($top_days, function($a, $b) { return $b['tornadoes'] <=> $a['tornadoes']; });
            $top_days = array_slice($top_days, 0, $top_days_count);
        ?>
        <div class="storm-chases-stats-top-days">
            <h3><?php esc_html_e('Biggest Chase Days', 'stormchases'); ?></h3>
            <div class="sc-sortable-table-wrap">
            <table class="sc-sortable-table">
                <thead>
                    <tr>
                        <th data-sort-key="date" data-sort-type="text"><?php esc_html_e('Date', 'stormchases'); ?></th>
                        <th data-sort-key="type" data-sort-type="text"><?php esc_html_e('Type', 'stormchases'); ?></th>
                        <th data-sort-key="tornadoes" data-sort-type="number" data-sort-dir="desc"><?php esc_html_e('Tornadoes', 'stormchases'); ?></th>
                        <th data-sort-key="miles" data-sort-type="number"><?php esc_html_e('Miles', 'stormchases'); ?></th>
                        <th data-sort-key="hail" data-sort-type="number"><?php esc_html_e('Hail (in.)', 'stormchases'); ?></th>
                        <th data-sort-key="wind" data-sort-type="number"><?php esc_html_e('Wind (MPH)', 'stormchases'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_days as $day) :
                        $day_label = $day['date'] && preg_match('/^\d{8}$/', $day['date'])
                            ? date_i18n('M j, Y', strtotime($day['date']))
                            : esc_html__('Unknown', 'stormchases');
                    ?>
                    <tr data-date="<?php echo esc_attr($day['date']); ?>" data-type="<?php echo esc_attr($day['type']); ?>" data-tornadoes="<?php echo esc_attr($day['tornadoes']); ?>" data-miles="<?php echo esc_attr($day['miles']); ?>" data-hail="<?php echo esc_attr($day['hail']); ?>" data-wind="<?php echo esc_attr($day['wind']); ?>">
                        <td><a href="<?php echo esc_url($day['permalink']); ?>"><?php echo esc_html($day_label); ?></a></td>
                        <td><?php echo esc_html($day['type']); ?></td>
                        <td><?php echo esc_html($day['tornadoes']); ?></td>
                        <td><?php echo esc_html(number_format($day['miles'])); ?></td>
                        <td><?php echo $day['hail'] > 0 ? esc_html(number_format($day['hail'], 2)) : '—'; ?></td>
                        <td><?php echo $day['wind'] > 0 ? esc_html($day['wind']) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <script>
        (function() {
            if (window.scSortableTablesWired) { return; }
            window.scSortableTablesWired = true;
            document.addEventListener('click', function(e) {
                var th = e.target.closest('th[data-sort-key]');
                if (!th) { return; }
                var table = th.closest('table.sc-sortable-table');
                if (!table) { return; }
                var key = th.getAttribute('data-sort-key');
                var type = th.getAttribute('data-sort-type') || 'text';
                var dir = th.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';
                Array.prototype.forEach.call(table.querySelectorAll('th[data-sort-key]'), function(h) {
                    h.removeAttribute('data-sort-dir');
                });
                th.setAttribute('data-sort-dir', dir);
                var tbody = table.querySelector('tbody');
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                rows.sort(function(a, b) {
                    var av = a.getAttribute('data-' + key) || '';
                    var bv = b.getAttribute('data-' + key) || '';
                    if (type === 'number') {
                        av = parseFloat(av) || 0;
                        bv = parseFloat(bv) || 0;
                        return dir === 'asc' ? av - bv : bv - av;
                    }
                    return dir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
                });
                rows.forEach(function(row) { tbody.appendChild(row); });
            });
        })();
        </script>
        <?php endif; ?>
    </div>
    <?php
    $output = ob_get_clean();
    set_transient($transient_key, $output, DAY_IN_SECONDS);
    return $output;
}

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
// Shared admin-editor partial: one tornado entry's markup
// ---------------------------------------------------------------------------

// Renders one tornado entry's admin-editor markup — used both for already-saved entries
// (templates/stormchases.php, on page load) and for a freshly AJAX-added blank entry
// (Storm_Chases::get_tornado_entry() in stormchases.php, the "Add Tornado" button's
// response). These two call sites used to be hand-duplicated copies and drifted out of
// sync once already (the AJAX-added version missed the drag/collapse UI for a full
// release cycle before being caught) — this is the shared partial that replaces both.
//
// $tornado empty (a brand-new entry) makes every field fall back to its default via
// `?? `/`selected()`/`checked()`'s own falsy handling — no separate "is this new" branch
// needed. $expanded controls only the initial collapse-state affordance (the toggle
// icon's direction): saved entries load collapsed, a freshly-added entry starts expanded,
// per this plugin's documented UX (admin.js is what actually drives collapse/expand
// interactively; this only sets the icon's starting direction to match).
function sc_render_tornado_entry_html(int $index, array $tornado = [], bool $expanded = false): string {
    $name = $tornado['name'] ?? '';
    $summary = ($name !== '' ? $name : __('Unnamed Tornado', 'stormchases')) . ' — ' . ($tornado['ef_rating'] ?? 'Unrated');
    $toggle_icon = $expanded ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2';
    $ratings = ['Unrated', 'EF-U', 'EF-0', 'EF-1', 'EF-2', 'EF-3', 'EF-4', 'EF-5'];

    ob_start();
    ?>
    <div class="tornado-entry">
        <div class="tornado-entry-header">
            <span class="dashicons dashicons-move tornado-drag-handle" title="<?php esc_attr_e('Drag to reorder', 'stormchases'); ?>"></span>
            <button type="button" class="button-link tornado-move-up" title="<?php esc_attr_e('Move up', 'stormchases'); ?>"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
            <button type="button" class="button-link tornado-move-down" title="<?php esc_attr_e('Move down', 'stormchases'); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
            <button type="button" class="tornado-entry-summary"><?php echo esc_html($summary); ?></button>
            <button type="button" class="button-link tornado-toggle" title="<?php esc_attr_e('Expand or collapse', 'stormchases'); ?>"><span class="dashicons <?php echo esc_attr($toggle_icon); ?>"></span></button>
            <button type="button" class="button-link remove-tornado" title="<?php esc_attr_e('Remove tornado', 'stormchases'); ?>"><span class="dashicons dashicons-no-alt"></span></button>
        </div>
        <div class="tornado-entry-body">
            <label><?php esc_html_e('Name', 'stormchases'); ?> <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($tornado['name'] ?? ''); ?>"></label>
            <label><?php esc_html_e('Latitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][lat]" value="<?php echo esc_attr($tornado['lat'] ?? 0); ?>"></label>
            <label><?php esc_html_e('Longitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][lon]" value="<?php echo esc_attr($tornado['lon'] ?? 0); ?>"></label>
            <button type="button" class="button tornado-pick-location" data-lat-field="lat" data-lon-field="lon"><?php esc_html_e('📍 Pick Location on Map', 'stormchases'); ?></button>
            <label><?php esc_html_e('EF Rating', 'stormchases'); ?>
                <select name="tornadoes[<?php echo esc_attr($index); ?>][ef_rating]">
                    <?php foreach ($ratings as $rating) : ?>
                        <option value="<?php echo esc_attr($rating); ?>" <?php selected($tornado['ef_rating'] ?? 'Unrated', $rating); ?>><?php echo esc_html($rating); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e('Start Time', 'stormchases'); ?> <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][start_time]" value="<?php echo esc_attr($tornado['start_time'] ?? ''); ?>"></label>
            <label><?php esc_html_e('End Time', 'stormchases'); ?> <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][end_time]" value="<?php echo esc_attr($tornado['end_time'] ?? ''); ?>"></label>
            <label><?php esc_html_e('End Latitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][end_lat]" value="<?php echo esc_attr($tornado['end_lat'] ?? 0); ?>"></label>
            <label><?php esc_html_e('End Longitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][end_lon]" value="<?php echo esc_attr($tornado['end_lon'] ?? 0); ?>"></label>
            <button type="button" class="button tornado-pick-location" data-lat-field="end_lat" data-lon-field="end_lon"><?php esc_html_e('📍 Pick End Location on Map', 'stormchases'); ?></button>
            <label><?php esc_html_e('Photo', 'stormchases'); ?>
                <input type="hidden" class="tornado-photo-id" name="tornadoes[<?php echo esc_attr($index); ?>][photo_id]" value="<?php echo esc_attr($tornado['photo_id'] ?? 0); ?>">
                <input type="text" class="tornado-photo-url" value="<?php echo esc_attr(wp_get_attachment_url($tornado['photo_id'] ?? 0)); ?>" readonly>
                <button type="button" class="upload-tornado-photo-button button"><?php esc_html_e('Upload Photo', 'stormchases'); ?></button>
            </label>
            <label><input type="checkbox" name="tornadoes[<?php echo esc_attr($index); ?>][photogenic]" <?php checked($tornado['photogenic'] ?? false); ?>> <?php esc_html_e('Photogenic', 'stormchases'); ?></label>
            <button type="button" class="button tornado-done"><?php esc_html_e('Done', 'stormchases'); ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Same pattern as sc_render_tornado_entry_html() above — one shared partial for both the
// saved-entries loop (templates/stormchases.php, Hurricane-type chases only) and the
// AJAX "Add Landfall" response (Storm_Chases::get_landfall_entry() in stormchases.php).
// Deliberately reuses the tornado-entry-* CSS classes/markup shape (drag handle, move
// up/down, collapse/expand, remove) rather than introducing parallel landfall-entry-*
// rules in admin.css purely for visual styling — the DOM classes below (landfall-entry,
// remove-landfall, etc.) are what admin.js actually hooks for behavior, scoped to
// #landfalls-container the same way tornado's are scoped to #tornadoes-container, so
// there's no risk of the two entry types' delegated handlers colliding despite sharing
// visual CSS.
function sc_render_landfall_entry_html(int $index, array $landfall = [], bool $expanded = false): string {
    $name = $landfall['name'] ?? '';
    $summary = ($name !== '' ? $name : __('Unnamed Landfall', 'stormchases')) . ' — ' . ($landfall['category'] ?? 'Unknown');
    $toggle_icon = $expanded ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2';
    $categories = StormChasesData::get_landfall_categories();

    ob_start();
    ?>
    <div class="tornado-entry landfall-entry">
        <div class="tornado-entry-header">
            <span class="dashicons dashicons-move tornado-drag-handle" title="<?php esc_attr_e('Drag to reorder', 'stormchases'); ?>"></span>
            <button type="button" class="button-link landfall-move-up" title="<?php esc_attr_e('Move up', 'stormchases'); ?>"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
            <button type="button" class="button-link landfall-move-down" title="<?php esc_attr_e('Move down', 'stormchases'); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
            <button type="button" class="landfall-entry-summary"><?php echo esc_html($summary); ?></button>
            <button type="button" class="button-link landfall-toggle" title="<?php esc_attr_e('Expand or collapse', 'stormchases'); ?>"><span class="dashicons <?php echo esc_attr($toggle_icon); ?>"></span></button>
            <button type="button" class="button-link remove-landfall" title="<?php esc_attr_e('Remove landfall', 'stormchases'); ?>"><span class="dashicons dashicons-no-alt"></span></button>
        </div>
        <div class="tornado-entry-body">
            <label><?php esc_html_e('Hurricane Name', 'stormchases'); ?> <input type="text" name="landfalls[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($landfall['name'] ?? ''); ?>" placeholder="<?php esc_attr_e('e.g. Hurricane Irma', 'stormchases'); ?>"></label>
            <label><?php esc_html_e('Latitude', 'stormchases'); ?> <input type="number" step="0.0001" name="landfalls[<?php echo esc_attr($index); ?>][lat]" value="<?php echo esc_attr($landfall['lat'] ?? 0); ?>"></label>
            <label><?php esc_html_e('Longitude', 'stormchases'); ?> <input type="number" step="0.0001" name="landfalls[<?php echo esc_attr($index); ?>][lon]" value="<?php echo esc_attr($landfall['lon'] ?? 0); ?>"></label>
            <button type="button" class="button landfall-pick-location" data-lat-field="lat" data-lon-field="lon"><?php esc_html_e('📍 Pick Location on Map', 'stormchases'); ?></button>
            <label><?php esc_html_e('Time', 'stormchases'); ?> <input type="text" name="landfalls[<?php echo esc_attr($index); ?>][time]" value="<?php echo esc_attr($landfall['time'] ?? ''); ?>"></label>
            <label><?php esc_html_e('Category', 'stormchases'); ?>
                <select name="landfalls[<?php echo esc_attr($index); ?>][category]">
                    <?php foreach ($categories as $category) : ?>
                        <option value="<?php echo esc_attr($category); ?>" <?php selected($landfall['category'] ?? 'Unknown', $category); ?>><?php echo esc_html($category); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e('Wind Speed (mph)', 'stormchases'); ?> <input type="number" min="0" name="landfalls[<?php echo esc_attr($index); ?>][wind_speed]" value="<?php echo esc_attr($landfall['wind_speed'] ?? 0); ?>"></label>
            <label><?php esc_html_e('Lowest Pressure (mb)', 'stormchases'); ?> <input type="number" min="0" name="landfalls[<?php echo esc_attr($index); ?>][pressure]" value="<?php echo esc_attr($landfall['pressure'] ?? 0); ?>"></label>
            <button type="button" class="button landfall-done"><?php esc_html_e('Done', 'stormchases'); ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Mirrors sc_render_landfall_entry_html() above — same shared-partial pattern (one function
// backs both the saved-entries loop in templates/stormchases.php and the AJAX "Add Snowfall
// Report" endpoint). 'location' is a free-text town/place name, not derived from lat/lon —
// see the comment on StormChasesData::sanitize_snowfall_data() for why.
function sc_render_snowfall_entry_html(int $index, array $snowfall = [], bool $expanded = false): string {
    $location = $snowfall['location'] ?? '';
    $depth = $snowfall['depth'] ?? 0;
    $summary = ($location !== '' ? $location : __('Unnamed Location', 'stormchases')) . ' — ' . number_format((float) $depth, 1) . '"';
    $toggle_icon = $expanded ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2';

    ob_start();
    ?>
    <div class="tornado-entry snowfall-entry">
        <div class="tornado-entry-header">
            <span class="dashicons dashicons-move tornado-drag-handle" title="<?php esc_attr_e('Drag to reorder', 'stormchases'); ?>"></span>
            <button type="button" class="button-link snowfall-move-up" title="<?php esc_attr_e('Move up', 'stormchases'); ?>"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
            <button type="button" class="button-link snowfall-move-down" title="<?php esc_attr_e('Move down', 'stormchases'); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
            <button type="button" class="snowfall-entry-summary"><?php echo esc_html($summary); ?></button>
            <button type="button" class="button-link snowfall-toggle" title="<?php esc_attr_e('Expand or collapse', 'stormchases'); ?>"><span class="dashicons <?php echo esc_attr($toggle_icon); ?>"></span></button>
            <button type="button" class="button-link remove-snowfall" title="<?php esc_attr_e('Remove snowfall report', 'stormchases'); ?>"><span class="dashicons dashicons-no-alt"></span></button>
        </div>
        <div class="tornado-entry-body">
            <label><?php esc_html_e('Town / Location', 'stormchases'); ?> <input type="text" name="snowfall_reports[<?php echo esc_attr($index); ?>][location]" value="<?php echo esc_attr($location); ?>" placeholder="<?php esc_attr_e('e.g. Lansing, MI', 'stormchases'); ?>"></label>
            <label><?php esc_html_e('Latitude', 'stormchases'); ?> <input type="number" step="0.0001" name="snowfall_reports[<?php echo esc_attr($index); ?>][lat]" value="<?php echo esc_attr($snowfall['lat'] ?? 0); ?>"></label>
            <label><?php esc_html_e('Longitude', 'stormchases'); ?> <input type="number" step="0.0001" name="snowfall_reports[<?php echo esc_attr($index); ?>][lon]" value="<?php echo esc_attr($snowfall['lon'] ?? 0); ?>"></label>
            <button type="button" class="button snowfall-pick-location" data-lat-field="lat" data-lon-field="lon"><?php esc_html_e('📍 Pick Location on Map', 'stormchases'); ?></button>
            <label><?php esc_html_e('Time', 'stormchases'); ?> <input type="text" name="snowfall_reports[<?php echo esc_attr($index); ?>][time]" value="<?php echo esc_attr($snowfall['time'] ?? ''); ?>"></label>
            <label><?php esc_html_e('Snowfall Depth (in.)', 'stormchases'); ?> <input type="number" step="0.1" min="0" name="snowfall_reports[<?php echo esc_attr($index); ?>][depth]" value="<?php echo esc_attr($depth); ?>"></label>
            <button type="button" class="button snowfall-done"><?php esc_html_e('Done', 'stormchases'); ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ---------------------------------------------------------------------------
// Helpers shared by the Tornado Map and Spotter Reports blocks
// ---------------------------------------------------------------------------

// Single source of truth for enqueuing Leaflet — was previously copy-pasted with a
// hardcoded unpkg.com URL+version in four separate places (this file, stormchases.php x2,
// storm_chase.php), a real drift risk if the version ever needs bumping. Self-hosted (as
// of 2.0.0) from assets/vendor/leaflet/ instead of the CDN, removing an external
// DNS/TLS round-trip and third-party-availability dependency from every page that needs
// it — see assets/vendor/leaflet/LICENSE for its BSD-2-Clause license.
function sc_enqueue_leaflet() {
    wp_enqueue_style('leaflet', STORM_CHASES_URL . 'assets/vendor/leaflet/leaflet.css', [], '1.9.4');
    wp_enqueue_script('leaflet', STORM_CHASES_URL . 'assets/vendor/leaflet/leaflet.js', [], '1.9.4', true);
}

function sc_enqueue_map_assets() {
    $map_provider = get_option('map_provider', 'openstreetmap');
    wp_enqueue_style('storm-chases', STORM_CHASES_URL . 'assets/css/stormchase.css', [], STORM_CHASES_VERSION);

    if ($map_provider !== 'google') {
        sc_enqueue_leaflet();
        wp_enqueue_script('storm-chases-frontend', STORM_CHASES_URL . 'assets/js/frontend.js', ['jquery', 'leaflet'], STORM_CHASES_VERSION, true);
    } else {
        wp_enqueue_script('storm-chases-frontend', STORM_CHASES_URL . 'assets/js/frontend.js', ['jquery'], STORM_CHASES_VERSION, true);
        $api_key = get_option('google_maps_api_key', '');
        if ($api_key) {
            wp_enqueue_script('google-maps', 'https://maps.googleapis.com/maps/api/js?key=' . esc_attr($api_key), [], null, ['strategy' => 'defer']);
        }
    }

    // wp_add_inline_script() + wp_json_encode() here, not wp_localize_script() with a
    // "only once" guard (what this used to be) — confirmed via a real headless-browser check
    // of the block editor that the guard was the actual cause of the map staying blank there:
    // enqueue_block_assets fires sc_enqueue_editor_preview_assets() twice per page load (once
    // for the top-level admin document, once more when WP mirrors the handle's markup into
    // the editor's iframe canvas), and wp_localize_script()'s own "already done" bookkeeping
    // — plus a static guard here doing the same thing a second way — meant only the *first*
    // (top-level) pass ever actually got the inline `stormChasesFrontend = {...}` data
    // attached. The iframe got frontend.js itself, just never its data, so the very first
    // line of that file (`stormChasesFrontend.googleMapsEnabled`) threw immediately and the
    // rest of the file — including the map init and the polling loop that redraws it on
    // every ServerSideRender update — never ran. wp_add_inline_script() has no such "done"
    // tracking; it just appends to the handle's inline-script queue, so every context that
    // ends up printing this handle also gets the data, at the cost of it appearing twice in
    // the (rare) case a single context prints the handle after two enqueue passes — harmless,
    // just a redundant `var` re-assignment.
    wp_add_inline_script('storm-chases-frontend', 'var stormChasesFrontend = ' . wp_json_encode([
        'ajaxUrl'           => admin_url('admin-ajax.php'),
        'chasemapUrl'       => '',
        'chasemapType'      => '0',
        'googleMapsEnabled' => get_option('google_maps_enable', false) ? true : false,
        'mapProvider'       => esc_attr($map_provider),
        'tornadoIconBase'   => esc_url(STORM_CHASES_URL . 'assets/images/'),
    ]) . ';', 'before');
}

// Same reasoning as location-map's location_map_enqueue_editor_preview_assets()
// (wordpress-plugins/location-map/includes/functions.php): ServerSideRender's
// preview markup for tornado-map/spotter-reports renders inside the block
// editor's iframed canvas, but its own REST sub-request can't load Leaflet/
// Google Maps into that request — so without this, the editor preview is a
// correctly-shaped empty box. enqueue_block_assets is the one hook WP mirrors
// into that iframe; only force it here for the admin/editor case, since the
// frontend case is already handled by each shortcode/block's own
// sc_enqueue_map_assets() call at render time.
add_action('enqueue_block_assets', 'sc_enqueue_editor_preview_assets');
function sc_enqueue_editor_preview_assets() {
    if (is_admin()) {
        sc_enqueue_map_assets();
    }
}

// ---------------------------------------------------------------------------
// Tornado map renderer, used by the stormchases/tornado-map block
// — see includes/blocks.php
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
// (bool), height (px), ratings (array of ef slugs to include; empty = all),
// show_storms/show_hurricanes (bool — overlay "memorable storm"/hurricane
// points alongside logged tornadoes; see the Legacy storm points note below),
// show_legend (bool), show_layer_toggles (bool — visitor-facing per-category
// checkboxes, like location-map/points-map's; separate from the ratings/
// show_storms/show_hurricanes args above, which only control what's queried
// at all, not whether a viewer can toggle it after the page loads), zoom
// (0 = auto-fit to whatever points are shown, matching today's behavior;
// >0 = fixed zoom/center, skipping auto-fit entirely), center_lat/center_lon
// (only meaningful when zoom > 0).
function sc_render_tornado_map(array $args): string {
    $args = array_merge([
        'year'               => '',
        'heading'            => '',
        'show_heading'       => true,
        'height'             => 500,
        'ratings'            => [],
        'show_storms'        => true,
        'show_hurricanes'    => true,
        'show_snowfall'      => true,
        'show_legend'        => true,
        'show_layer_toggles' => false,
        'zoom'               => 0,
        'center_lat'         => 39.8283,
        'center_lon'         => -98.5795,
    ], $args);

    $year    = $args['year'];
    $height  = max(150, min(2000, (int) $args['height']));
    $ratings = array_values(array_filter((array) $args['ratings']));

    sc_enqueue_map_assets();

    // Added in 2.0.0 — this renderer previously ran its WP_Query and unserialized every
    // matching chase's chase_data on every single page view, uncached (unlike the
    // stats/list/archive renderers). Real dev-database numbers: 434 chases averaging
    // ~57KB of chase_data each. Cache key includes every arg that affects output;
    // StormChaseTemplate::clear_transients() wildcard-deletes sc_tornado_map_v1_%. Note:
    // this doesn't invalidate on a location_map_points change (the storm/hurricane
    // overlay's source table, owned by a different plugin) — matches this cache's normal
    // DAY_IN_SECONDS expiry, but a same-day edit there won't show until the cache clears.
    $transient_key = 'sc_tornado_map_v1_' . ($year ?: 'all') . '_'
        . md5(wp_json_encode([
            $args['heading'], $args['show_heading'], $height, $ratings,
            $args['show_storms'], $args['show_hurricanes'], $args['show_snowfall'], $args['show_legend'],
            $args['show_layer_toggles'], $args['zoom'], $args['center_lat'], $args['center_lon'],
        ]));
    $output = get_transient($transient_key);
    if (false !== $output) {
        return $output;
    }

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
    // Names of native landfalls already added to $map_points below, normalized (trimmed,
    // lowercased) for matching against location_map_points' bridge-overlay rows further down
    // — see the dedup skip there for why.
    $native_hurricane_names = [];
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
                'kind'     => 'tornado',
                'groupKey' => sc_tornado_ef_slug($ef_raw),
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

        // Native per-chase hurricane landfalls (chase_type 'Hurricane', added 2.0.0) — same
        // 'hurricane' kind/groupKey and map_hurricane.png icon as the location_map_points
        // overlay below, so both sources unify under one legend/toggle entry. A chase can
        // log landfalls without every landfall needing show_hurricanes on, same as tornado
        // entries aren't gated by a ratings filter matching every rating.
        if ($args['show_hurricanes']) {
            foreach ($chase_data['landfalls'] as $idx => $landfall) {
                $lat = floatval($landfall['lat'] ?? 0);
                $lon = floatval($landfall['lon'] ?? 0);
                if ($lat == 0 && $lon == 0) {
                    continue;
                }
                $modal_id = esc_attr('sc-tmap-landfall-modal-' . $post_id . '-' . $idx);
                $name     = esc_html($landfall['name'] ?: __('Unnamed Landfall', 'stormchases'));
                if (!empty($landfall['name'])) {
                    $native_hurricane_names[strtolower(trim($landfall['name']))] = true;
                }

                $map_points[] = [
                    'lat'      => $lat,
                    'lon'      => $lon,
                    'modalId'  => $modal_id,
                    'label'    => wp_strip_all_tags($name),
                    'kind'     => 'hurricane',
                    'groupKey' => 'hurricane',
                ];

                $modal_html .= '<div id="' . $modal_id . '" class="modal sc-map-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="' . $modal_id . '-title">';
                $modal_html .= '<div class="modal-overlay"></div>';
                $modal_html .= '<div class="modal-content">';
                $modal_html .= '<span class="modal-close" aria-label="' . esc_attr__('Close', 'stormchases') . '">&times;</span>';
                $modal_html .= '<h3 id="' . $modal_id . '-title">' . $name . '</h3>';
                $modal_html .= '<p><b>' . esc_html__('Date', 'stormchases') . ':</b> <a href="' . esc_url($permalink) . '">' . esc_html(!empty($chase_data['chasedate']) && preg_match('/^\d{8}$/', $chase_data['chasedate']) ? date_i18n('F j, Y', strtotime($chase_data['chasedate'])) : '') . '</a></p>';
                if (!empty($landfall['time'])) {
                    $modal_html .= '<p><b>' . esc_html__('Time', 'stormchases') . ':</b> ' . esc_html($landfall['time']) . '</p>';
                }
                $modal_html .= '<p><b>' . esc_html__('Category', 'stormchases') . ':</b> ' . esc_html($landfall['category'] ?? 'Unknown') . '</p>';
                if (!empty($landfall['wind_speed'])) {
                    $modal_html .= '<p><b>' . esc_html__('Wind Speed', 'stormchases') . ':</b> ' . esc_html($landfall['wind_speed']) . ' mph</p>';
                }
                if (!empty($landfall['pressure'])) {
                    $modal_html .= '<p><b>' . esc_html__('Lowest Pressure', 'stormchases') . ':</b> ' . esc_html($landfall['pressure']) . ' mb</p>';
                }
                $modal_html .= '<p><b>' . esc_html__('Location', 'stormchases') . ':</b> ' . esc_html(number_format($lat, 4)) . ', ' . esc_html(number_format($lon, 4)) . '</p>';
                $modal_html .= '</div></div>';
            }
        }

        // Native per-chase snowfall reports (chase_type 'Winter', added 2.0.0) — same pattern
        // as the landfall block above, own 'snow'/'snow' kind/groupKey and snowflake-icon.png.
        if ($args['show_snowfall']) {
            foreach ($chase_data['snowfall_reports'] as $idx => $snowfall) {
                $lat = floatval($snowfall['lat'] ?? 0);
                $lon = floatval($snowfall['lon'] ?? 0);
                if ($lat == 0 && $lon == 0) {
                    continue;
                }
                $modal_id = esc_attr('sc-tmap-snowfall-modal-' . $post_id . '-' . $idx);
                $location = esc_html($snowfall['location'] ?: __('Unnamed Location', 'stormchases'));
                $depth    = number_format((float) ($snowfall['depth'] ?? 0), 1);

                $map_points[] = [
                    'lat'      => $lat,
                    'lon'      => $lon,
                    'modalId'  => $modal_id,
                    'label'    => wp_strip_all_tags($location) . ' — ' . $depth . '"',
                    'kind'     => 'snow',
                    'groupKey' => 'snow',
                ];

                $modal_html .= '<div id="' . $modal_id . '" class="modal sc-map-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="' . $modal_id . '-title">';
                $modal_html .= '<div class="modal-overlay"></div>';
                $modal_html .= '<div class="modal-content">';
                $modal_html .= '<span class="modal-close" aria-label="' . esc_attr__('Close', 'stormchases') . '">&times;</span>';
                $modal_html .= '<h3 id="' . $modal_id . '-title">' . $location . '</h3>';
                $modal_html .= '<p><b>' . esc_html__('Date', 'stormchases') . ':</b> <a href="' . esc_url($permalink) . '">' . esc_html(!empty($chase_data['chasedate']) && preg_match('/^\d{8}$/', $chase_data['chasedate']) ? date_i18n('F j, Y', strtotime($chase_data['chasedate'])) : '') . '</a></p>';
                $modal_html .= '<p><b>' . esc_html__('Snowfall Depth', 'stormchases') . ':</b> ' . esc_html($depth) . ' in.</p>';
                if (!empty($snowfall['time'])) {
                    $modal_html .= '<p><b>' . esc_html__('Time', 'stormchases') . ':</b> ' . esc_html($snowfall['time']) . '</p>';
                }
                $modal_html .= '<p><b>' . esc_html__('Location', 'stormchases') . ':</b> ' . esc_html(number_format($lat, 4)) . ', ' . esc_html(number_format($lon, 4)) . '</p>';
                $modal_html .= '</div></div>';
            }
        }
    }
    wp_reset_postdata();

    // Overlay simple "memorable storm"/hurricane points from the shared
    // legacy points table (owned by the location-map plugin — see the
    // "Memorable storm & hurricane overlay" note in CLAUDE.md). Same modal
    // pattern as the tornado entries above (name/photo/link), just less
    // data — no EF rating, no chase permalink, and the photo comes from
    // location-map's own photo_id column (its admin UI has a media picker
    // for it) rather than a stormchases attachment field.
    $overlay_types = [];
    if ($args['show_storms']) {
        $overlay_types[] = 'storm';
    }
    if ($args['show_hurricanes']) {
        $overlay_types[] = 'hurricane';
    }
    if (!empty($overlay_types)) {
        global $wpdb;
        $table = $wpdb->prefix . 'location_map_points';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            // $table is $wpdb->prefix (WP's own configured table prefix, not user input) plus a
            // hardcoded literal — table/column names can't go through %s placeholders anyway
            // (prepare() would quote them, breaking the query). $placeholders is a fixed number
            // of literal '%s' tokens (never user data), the standard pattern for a
            // variable-length IN() clause. phpcs can't verify either statically.
            // The %s tokens above are interpolated into the query text before prepare() sees it
            // (an IN() clause needs a variable number of placeholders, which prepare() can't
            // generate on its own), so the sniff can't find them via its own static scan and
            // reports the query as having no placeholders at all. It does have them.
            $placeholders = implode(',', array_fill(0, count($overlay_types), '%s'));
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- see comments above
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name, lat, lon, type, url, photo_id FROM {$table} WHERE type IN ({$placeholders}) ORDER BY type, name",
                $overlay_types
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            foreach ($rows as $row) {
                // Skip a bridge-table hurricane point once a native landfall of the same name
                // has already been added above — otherwise the same storm shows two markers
                // (one from each source). Only hurricane-type bridge rows can collide this way;
                // 'storm' rows have no native equivalent yet. See the "Hurricane marker
                // duplication risk" note in CLAUDE.md/ROADMAP.md — this is the fix for it.
                if ($row->type === 'hurricane' && isset($native_hurricane_names[strtolower(trim($row->name))])) {
                    continue;
                }
                $modal_id = esc_attr('sc-tmap-overlay-modal-' . $row->id);
                $name     = esc_html($row->name);
                $url      = $row->url ? esc_url_raw($row->url) : '';

                $map_points[] = [
                    'lat'     => (float) $row->lat,
                    'lon'     => (float) $row->lon,
                    'modalId' => $modal_id,
                    'label'   => wp_strip_all_tags($name),
                    'kind'    => $row->type,
                    'groupKey' => $row->type,
                ];

                $type_label = $row->type === 'hurricane' ? __('Hurricane', 'stormchases') : __('Memorable Storm', 'stormchases');
                $modal_html .= '<div id="' . $modal_id . '" class="modal sc-map-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="' . $modal_id . '-title">';
                $modal_html .= '<div class="modal-overlay"></div>';
                $modal_html .= '<div class="modal-content">';
                $modal_html .= '<span class="modal-close" aria-label="' . esc_attr__('Close', 'stormchases') . '">&times;</span>';
                $modal_html .= '<h3 id="' . $modal_id . '-title">' . $name . '</h3>';
                $modal_html .= '<p><b>' . esc_html__('Type', 'stormchases') . ':</b> ' . esc_html($type_label) . '</p>';
                $modal_html .= '<p><b>' . esc_html__('Location', 'stormchases') . ':</b> ' . esc_html(number_format((float) $row->lat, 4)) . ', ' . esc_html(number_format((float) $row->lon, 4)) . '</p>';
                if ($url) {
                    $modal_html .= '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('More Info', 'stormchases') . '</a></p>';
                }
                if ($row->photo_id && wp_attachment_is_image((int) $row->photo_id)) {
                    $modal_html .= wp_get_attachment_image((int) $row->photo_id, 'medium', false, ['style' => 'max-width:100%;height:auto;margin-top:10px;']);
                }
                $modal_html .= '</div></div>';
            }
        }
    }

    if ($args['heading'] !== '') {
        $heading = esc_html($args['heading']);
    } else {
        /* translators: %s: chase year */
        $heading = $year
            ? sprintf(esc_html__('Tornado Map — %s', 'stormchases'), $year)
            : esc_html__('All Tornadoes Map', 'stormchases');
    }

    // Shared category list backing both the legend and the (optional)
    // visitor-facing layer toggles — groupKey matches what each point in
    // $map_points was tagged with above, icon paths match markerIconSpec()/
    // STORM_POINT_ICON in frontend.js.
    $category_defs = [
        'ef5'    => ['label' => __('EF-5', 'stormchases'), 'icon' => 'tornado-ef5.png'],
        'ef4'    => ['label' => __('EF-4', 'stormchases'), 'icon' => 'tornado-ef4.png'],
        'ef3'    => ['label' => __('EF-3', 'stormchases'), 'icon' => 'tornado-ef3.png'],
        'ef2'    => ['label' => __('EF-2', 'stormchases'), 'icon' => 'tornado-ef2.png'],
        'ef1'    => ['label' => __('EF-1', 'stormchases'), 'icon' => 'tornado-ef1.png'],
        'ef0'    => ['label' => __('EF-0', 'stormchases'), 'icon' => 'tornado-ef0.png'],
        'unrated' => ['label' => __('Unrated', 'stormchases'), 'icon' => 'tornado-unrated.png'],
    ];
    if ($args['show_storms']) {
        $category_defs['storm'] = ['label' => __('Memorable Storm', 'stormchases'), 'icon' => 'map_storm.png'];
    }
    if ($args['show_hurricanes']) {
        $category_defs['hurricane'] = ['label' => __('Hurricane', 'stormchases'), 'icon' => 'map_hurricane.png'];
    }
    if ($args['show_snowfall']) {
        $category_defs['snow'] = ['label' => __('Snowfall Report', 'stormchases'), 'icon' => 'snowflake-icon.png'];
    }

    $present_group_keys = array_unique(array_column($map_points, 'groupKey'));

    $legend_html = '<div class="sc-map-legend">';
    foreach ($category_defs as $slug => $def) {
        if (in_array($slug, ['ef5', 'ef4', 'ef3', 'ef2', 'ef1', 'ef0', 'unrated'], true) && !empty($ratings) && !in_array($slug, $ratings, true)) {
            continue;
        }
        $legend_html .= '<span class="sc-map-legend-item">';
        $legend_html .= '<img src="' . esc_url(STORM_CHASES_URL . 'assets/images/' . $def['icon']) . '" alt="" class="sc-map-legend-icon">';
        $legend_html .= '<span>' . esc_html($def['label']) . '</span>';
        $legend_html .= '</span>';
    }
    $legend_html .= '</div>';

    $toggles_html = '';
    if ($args['show_layer_toggles']) {
        $toggles_html = '<div class="sc-map-controls" data-map-uid="' . esc_attr($map_uid) . '">';
        $toggles_html .= '<h4>' . esc_html__('Toggle Layers', 'stormchases') . '</h4>';
        foreach ($category_defs as $slug => $def) {
            if (!in_array($slug, $present_group_keys, true)) {
                continue;
            }
            $toggles_html .= '<label><input type="checkbox" class="sc-map-toggle" data-layer="' . esc_attr($slug) . '" checked> ' . esc_html($def['label']) . '</label>';
        }
        $toggles_html .= '</div>';
    }

    ob_start();
    ?>
    <div class="sc-map-shortcode">
        <?php if ($args['show_heading']) : ?>
            <h2><?php echo $heading; ?></h2>
        <?php endif; ?>
        <div id="<?php echo esc_attr($map_uid); ?>" class="sc-leaflet-map" data-map-type="tornado" style="height:<?php echo esc_attr($height); ?>px;width:100%;margin-bottom:10px;"></div>
        <?php echo $toggles_html; // Already escaped above ?>
        <?php if ($args['show_legend']) : ?>
            <?php echo $legend_html; // Already escaped above ?>
        <?php endif; ?>
        <script>
        (function(){
            window.scMapData = window.scMapData || {};
            window.scMapData[<?php echo wp_json_encode($map_uid, JSON_HEX_TAG); ?>] = <?php echo wp_json_encode($map_points, JSON_HEX_TAG); ?>;
            window.scMapOptions = window.scMapOptions || {};
            window.scMapOptions[<?php echo wp_json_encode($map_uid, JSON_HEX_TAG); ?>] = <?php echo wp_json_encode([
                'zoom'      => (float) $args['zoom'],
                'centerLat' => (float) $args['center_lat'],
                'centerLon' => (float) $args['center_lon'],
            ], JSON_HEX_TAG); ?>;
        })();
        </script>
        <?php echo $modal_html; // Already escaped above ?>
    </div>
    <?php
    $output = ob_get_clean();
    set_transient($transient_key, $output, DAY_IN_SECONDS);
    return $output;
}

// ---------------------------------------------------------------------------
// Spotter Network reports list + map renderer, used by the
// stormchases/spotter-reports block
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

    // Same caching rationale as sc_render_tornado_map() above — added in 2.0.0.
    $transient_key = 'sc_reports_v1_' . ($year ?: 'all') . '_'
        . md5(wp_json_encode([$args['heading'], $args['show_heading'], $height, $types]));
    $output = get_transient($transient_key);
    if (false !== $output) {
        return $output;
    }

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
        /* translators: %s: chase year */
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
            <?php /* translators: 1: number of reports, 2: chase year (year variant); %d: number of reports (all-time variant) */ ?>
            <h3><?php echo $year ? esc_html(sprintf(__('%1$d Reports in %2$s', 'stormchases'), count($all_reports), $year)) : esc_html(sprintf(__('%d Total Reports', 'stormchases'), count($all_reports))); ?></h3>
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
    $output = ob_get_clean();
    set_transient($transient_key, $output, DAY_IN_SECONDS);
    return $output;
}

// Moved out of StormChaseTemplate so it's callable without instantiating a second
// StormChaseTemplate (which would double-register its constructor's action/filter hooks for
// the rest of the request — every add_action()/add_filter() call in its __construct() would
// fire twice). StormChaseTemplate::clear_transients() now just delegates here; same reason
// sc_get_chase_post_by_date() below was extracted.
function sc_clear_transients($post_ids = []) {
    global $wpdb;

    $post_ids = (array) $post_ids;
    $post_ids = array_filter(array_map('absint', $post_ids));

    $prefixes = [
        'storm_chases_stats_%',
        'storm_chases_archive_%',
        'sc_archive_v2_%',
        'sc_stats_v12_%',
        'sc_tornado_map_v1_%',
        'sc_reports_v1_%',
    ];

    foreach ($post_ids as $post_id) {
        delete_transient('storm_chases_stats_' . $post_id);
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

    // Single global key (not per-post/wildcarded), backs sc_get_chase_years() for the
    // Chase Archive block's year dropdown — must be invalidated whenever a chase's publish
    // status or chasedate could change which years have chases.
    delete_transient('sc_archive_years_v1');

    // Same shape as above, backs sc_get_first_chase_year_by_state()'s "new state" callout —
    // invalidated for the same reason (a chase's states/chasedate/publish status changing
    // could change which year a state was first chased in).
    delete_transient('sc_first_state_year_v1');

    // Same shape again, backs sc_get_top_people_by_role()'s "Top Chase Partners"/"Most
    // Encountered Chasers" — invalidated whenever a chase's chasepartners/chasechasers text
    // or publish status could change either ranking.
    delete_transient('sc_top_people_chasepartners_v1');
    delete_transient('sc_top_people_chasechasers_v1');

    Storm_Chases::debug_log('Cleared storm chase transients for posts: ' . (empty($post_ids) ? 'all' : implode(', ', $post_ids)), 'info');
}

function sc_get_chase_post_by_date($date) {
    $args = [
        'post_type' => 'storm_chase',
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

// Shared by StormChaseTemplate::handle_spotter_reports_upload() (manual CSV upload, REST) and
// the Spotter Network auto-fetch flow (Storm_Chases_Settings, admin-post) — same parsing/import
// logic regardless of where the CSV text came from. Returns a WP_Error for structural problems
// (bad headers, empty file, nothing importable) or a result array on success/dry-run.
function sc_import_spotter_reports_csv(string $csv_content, bool $overwrite, bool $dry_run) {
    $data_handler = new StormChasesData();

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

    $lines = preg_split('/\r\n|\r|\n/', $csv_content, -1, PREG_SPLIT_NO_EMPTY);
    $csv = array_map(function($line) use ($delimiter) {
        return str_getcsv(trim($line), $delimiter, '"', '\\');
    }, $lines);
    if (empty($csv)) {
        return new WP_Error('read_failed', __('CSV file is empty or unreadable.', 'stormchases'), ['status' => 400]);
    }

    $header = array_map(function($h) { return trim(strtolower($h)); }, array_shift($csv));
    $required_headers = ['report', 'report_type', 'stamp', 'lat', 'lon', 'narrative', 'tornado', 'hailsize', 'windspeed', 'city1', 'cwa'];
    $missing_headers = array_diff($required_headers, $header);
    if (!empty($missing_headers)) {
        return new WP_Error('invalid_csv', sprintf(__('Missing required CSV headers: %s', 'stormchases'), implode(', ', $missing_headers)), ['status' => 400]);
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

        $hour = (int) $report_date->format('H');
        if ($hour < 10) {
            $report_date->modify('-1 day');
        }
        $date_key = $report_date->format('Ymd');

        $chase_post = sc_get_chase_post_by_date($date_key);
        if (!$chase_post) {
            $unmatched_dates[$date_key] = ($unmatched_dates[$date_key] ?? 0) + 1;
            $skipped++;
            $skip_reasons[] = "Row $row_number: No storm chase post found for date $date_key.";
            continue;
        }

        $report_data = $data_handler->sanitize_spotter_report([
            'report_id' => $report['report'] ?? '',
            'type' => $report['report_type'] ?? 'S',
            'timestamp' => $report['stamp'] ?? '',
            'lat' => !empty($report['lat']) && is_numeric($report['lat']) ? floatval($report['lat']) : 0,
            'lon' => !empty($report['lon']) && is_numeric($report['lon']) ? floatval($report['lon']) : 0,
            'narrative' => $report['narrative'] ?? '',
            'tornado' => isset($report['tornado']) && $report['tornado'] !== '' ? (int) $report['tornado'] : 0,
            'funnelcloud' => isset($report['funnelcloud']) && $report['funnelcloud'] !== '' ? (int) $report['funnelcloud'] : 0,
            'wallcloud' => isset($report['wallcloud']) && $report['wallcloud'] !== '' ? (int) $report['wallcloud'] : 0,
            'hail' => isset($report['hail']) && $report['hail'] !== '' ? (int) $report['hail'] : 0,
            'hailsize' => !empty($report['hailsize']) && is_numeric($report['hailsize']) ? floatval($report['hailsize']) : 0,
            'windspeed' => !empty($report['windspeed']) && is_numeric($report['windspeed']) ? floatval($report['windspeed']) : 0,
            'damage' => isset($report['damage']) && $report['damage'] !== '' ? (int) $report['damage'] : 0,
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
                'unmatched_dates' => $unmatched_dates,
            ],
        ]);
    }

    foreach ($reports_by_date as $post_id => $reports) {
        $chase_data = $data_handler->get_chase_data($post_id);

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

        $result = $data_handler->save_chase_data($post_id, $chase_data);
        if (is_wp_error($result)) {
            return new WP_Error('save_failed', sprintf(__('Failed to save reports: %s', 'stormchases'), $result->get_error_message()), [
                'status' => 500,
                'data' => [
                    'skipped' => $skipped,
                    'skip_reasons' => $skip_reasons,
                    'unmatched_dates' => $unmatched_dates,
                ],
            ]);
        }
    }

    if (!$dry_run) {
        sc_clear_transients(array_keys($reports_by_date));
    }

    $message = $dry_run
        ? sprintf(
            __('[DRY RUN] Would import %1$d reports across %2$d chase dates. %3$d would be skipped.', 'stormchases'),
            $inserted,
            count($reports_by_date),
            $skipped
          )
        : sprintf(
            __('Uploaded %1$d reports successfully across %2$d posts. Skipped %3$d reports.', 'stormchases'),
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

    return [
        'message' => $message,
        'dry_run' => $dry_run,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'skip_reasons' => $skip_reasons,
        'unmatched_dates' => $unmatched_dates,
    ];
}

