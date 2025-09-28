<?php
if (!defined('ABSPATH')) {
    exit;
}

global $post;
if (!$post instanceof WP_Post || $post->post_type !== 'storm_chase') {
    Storm_Chases::debug_log('No valid storm chase post found.', 'error');
    get_header();
    echo '<p>' . esc_html__('Error: No storm chase post found.', 'stormchases') . '</p>';
    get_footer();
    return;
}

get_header();
?>

<div id="primary" class="content-area">
    <main id="main" class="site-main">
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <header class="entry-header">
                    <h1 class="entry-title"><?php the_title(); ?></h1>
                </header>
                <div class="entry-content">
                    <?php the_content(); ?>
                    <?php
                    $data_handler = new StormChasesData();
                    $chase_data = $data_handler->get_chase_data(get_the_ID());
                    ?>
                    <div class="storm-chase-details">
                        <h2><?php esc_html_e('Chase Details', 'stormchases'); ?></h2>
                        <ul>
                            <?php if (!empty($chase_data['chasedate'])) : ?>
                                <li><strong><?php esc_html_e('Chase Date', 'stormchases'); ?>:</strong> <?php echo esc_html(date('F j, Y', strtotime($chase_data['chasedate']))); ?></li>
                            <?php endif; ?>
                            <?php if (!empty($chase_data['chasestates']) && get_option('states_chased_enable')) : ?>
                                <li><strong><?php esc_html_e('States', 'stormchases'); ?>:</strong> <?php echo esc_html($chase_data['chasestates']); ?></li>
                            <?php endif; ?>
                            <?php if (!empty($chase_data['chasepartners']) && get_option('chase_partners_enable')) : ?>
                                <li><strong><?php esc_html_e('Partners', 'stormchases'); ?>:</strong> <?php echo esc_html($chase_data['chasepartners']); ?></li>
                            <?php endif; ?>
                            <?php if (!empty($chase_data['chasechasers']) && get_option('chasers_encountered_enable')) : ?>
                                <li><strong><?php esc_html_e('Chasers', 'stormchases'); ?>:</strong> <?php echo esc_html($chase_data['chasechasers']); ?></li>
                            <?php endif; ?>
                            <?php if (!empty($chase_data['chasemiles']) && get_option('miles_logged_enable')) : ?>
                                <li><strong><?php esc_html_e('Miles', 'stormchases'); ?>:</strong> <?php echo esc_html($chase_data['chasemiles']); ?></li>
                            <?php endif; ?>
                            <?php if (!empty($chase_data['chasehail']) && get_option('hail_enable')) : ?>
                                <li><strong><?php esc_html_e('Hail Size', 'stormchases'); ?>:</strong> <?php echo esc_html($chase_data['chasehail']); ?> inches</li>
                            <?php endif; ?>
                            <?php if (!empty($chase_data['chasewind']) && get_option('wind_enable')) : ?>
                                <li><strong><?php esc_html_e('Wind Speed', 'stormchases'); ?>:</strong> <?php echo esc_html($chase_data['chasewind']); ?> mph</li>
                            <?php endif; ?>
                            <?php if (!empty($chase_data['chasems']) && get_option('milestones_enable')) : ?>
                                <li><strong><?php esc_html_e('Milestones', 'stormchases'); ?>:</strong> <?php echo esc_html($chase_data['chasems']); ?></li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <?php if (get_option('google_maps_enable') && !empty($chase_data['chasemap_id']) && $chase_data['chasemaptype'] === '1') : ?>
                        <div id="chasemap" style="height: 400px; width: 100%; max-width: 800px; margin-bottom: 20px;"></div>
                    <?php elseif (!empty($chase_data['chasemap_id'])) : ?>
                        <img src="<?php echo esc_url(wp_get_attachment_url($chase_data['chasemap_id'])); ?>" style="max-width: 100%; height: auto; margin-bottom: 20px;" alt="<?php esc_attr_e('Chase Map', 'stormchases'); ?>" />
                    <?php endif; ?>

                    <?php if (!empty($chase_data['tornadoes']) && is_array($chase_data['tornadoes']) && get_option('tornadoes_enable')) : ?>
                        <div class="storm-chase-tornadoes">
                            <h2><?php esc_html_e('Tornadoes Witnessed', 'stormchases'); ?></h2>
                            <ul>
                                <?php foreach ($chase_data['tornadoes'] as $index => $tornado) : ?>
                                    <?php
                                    $modal_id = 'tornado-modal-' . get_the_ID() . '-' . $index;
                                    $tornado_name = esc_html($tornado['name'] ?: 'Tornado ' . ($index + 1));
                                    ?>
                                    <li>
                                        <a href="#" class="tornado-link" data-modal-id="<?php echo esc_attr($modal_id); ?>">
                                            <?php echo $tornado_name; ?>
                                        </a>
                                        <div id="<?php echo esc_attr($modal_id); ?>" class="modal tornado-modal">
                                            <div class="modal-overlay"></div>
                                            <div class="modal-content">
                                                <span class="modal-close">×</span>
                                                <h3><?php echo $tornado_name; ?></h3>
                                                <table class="tornado-details">
                                                    <tr>
                                                        <th><?php esc_html_e('Latitude', 'stormchases'); ?></th>
                                                        <td><?php echo esc_html($tornado['lat'] ?? '0'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th><?php esc_html_e('Longitude', 'stormchases'); ?></th>
                                                        <td><?php echo esc_html($tornado['lon'] ?? '0'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th><?php esc_html_e('EF Rating', 'stormchases'); ?></th>
                                                        <td><?php echo esc_html($tornado['ef_rating'] ?? 'Unrated'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th><?php esc_html_e('Start Time', 'stormchases'); ?></th>
                                                        <td><?php echo esc_html($tornado['start_time'] ?? 'Not specified'); ?></td>
                                                    </tr>
                                                    <?php if (!empty($tornado['end_time'])) : ?>
                                                        <tr>
                                                            <th><?php esc_html_e('End Time', 'stormchases'); ?></th>
                                                            <td><?php echo esc_html($tornado['end_time']); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php if (!empty($tornado['end_lat']) && !empty($tornado['end_lon'])) : ?>
                                                        <tr>
                                                            <th><?php esc_html_e('End Latitude', 'stormchases'); ?></th>
                                                            <td><?php echo esc_html($tornado['end_lat']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th><?php esc_html_e('End Longitude', 'stormchases'); ?></th>
                                                            <td><?php echo esc_html($tornado['end_lon']); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php if (!empty($tornado['photogenic'])) : ?>
                                                        <tr>
                                                            <th><?php esc_html_e('Photogenic', 'stormchases'); ?></th>
                                                            <td><?php esc_html_e('Yes', 'stormchases'); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                </table>
                                                <?php if (!empty($tornado['photo_id'])) : ?>
                                                    <?php echo wp_get_attachment_image($tornado['photo_id'], 'medium', false, ['class' => 'tornado-photo']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($chase_data['spotter_reports']) && is_array($chase_data['spotter_reports']) && get_option('spotter_reports_enable')) : ?>
                        <div class="storm-chase-reports">
                            <h2><?php esc_html_e('Spotter Network Reports', 'stormchases'); ?></h2>
                            <ul>
                                <?php foreach ($chase_data['spotter_reports'] as $index => $report) : ?>
                                    <?php
                                    $report_id = $report['report_id'] ?? $index;
                                    $modal_id = 'report-modal-' . get_the_ID() . '-' . $report_id;
                                    $timestamp = !empty($report['timestamp']) && strtotime($report['timestamp'])
                                        ? esc_html(storm_chases_convert_utc_to_central($report['timestamp']))
                                        : esc_html__('Invalid timestamp', 'stormchases');
                                    $nws_office = esc_html(storm_chases_get_nws_office($report['cwa'] ?? ''));
                                    $weather_type = $report['tornado'] ? 'Tornado' : ($report['hailsize'] ? 'Hail' : ($report['windspeed'] ? 'Wind' : ($report['funnelcloud'] ? 'Funnel Cloud' : ($report['wallcloud'] ? 'Wall Cloud' : 'Other'))));
                                    $link_text = !empty($report['city']) ? esc_html($weather_type . ' ' . $report['city']) : esc_html__('Report ' . ($index + 1), 'stormchases');
                                    ?>
                                    <li>
                                        <a href="#" class="report-link" data-modal-id="<?php echo esc_attr($modal_id); ?>">
                                            <?php echo $link_text; ?>
                                        </a>
                                        <div id="<?php echo esc_attr($modal_id); ?>" class="modal report-modal">
                                            <div class="modal-overlay"></div>
                                            <div class="modal-content">
                                                <span class="modal-close">×</span>
                                                <h3><?php echo esc_html($link_text); ?></h3>
                                                <table class="report-details">
                                                    <tr>
                                                        <th><?php esc_html_e('Timestamp', 'stormchases'); ?></th>
                                                        <td><?php echo esc_html($timestamp); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th><?php esc_html_e('Latitude', 'stormchases'); ?></th>
                                                        <td><?php echo esc_html($report['lat'] ?? '0'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th><?php esc_html_e('Longitude', 'stormchases'); ?></th>
                                                        <td><?php echo esc_html($report['lon'] ?? '0'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th><?php esc_html_e('NWS Office', 'stormchases'); ?></th>
                                                        <td><?php echo esc_html($nws_office); ?></td>
                                                    </tr>
                                                    <?php if (!empty($report['narrative'])) : ?>
                                                        <tr>
                                                            <th><?php esc_html_e('Narrative', 'stormchases'); ?></th>
                                                            <td><?php echo esc_html($report['narrative']); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php if (!empty($report['hailsize']) && $report['hailsize'] > 0) : ?>
                                                        <tr>
                                                            <th><?php esc_html_e('Hail Size', 'stormchases'); ?></th>
                                                            <td><?php echo esc_html($report['hailsize']); ?> inches</td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php if (!empty($report['windspeed']) && $report['windspeed'] > 0) : ?>
                                                        <tr>
                                                            <th><?php esc_html_e('Wind Speed', 'stormchases'); ?></th>
                                                            <td><?php echo esc_html($report['windspeed']); ?> mph</td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php if (!empty($report['tornado']) && $report['tornado'] > 0) : ?>
                                                        <tr>
                                                            <th><?php esc_html_e('Tornado', 'stormchases'); ?></th>
                                                            <td><?php esc_html_e('Yes', 'stormchases'); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php if (!empty($report['funnelcloud']) && $report['funnelcloud'] > 0) : ?>
                                                        <tr>
                                                            <th><?php esc_html_e('Funnel Cloud', 'stormchases'); ?></th>
                                                            <td><?php esc_html_e('Yes', 'stormchases'); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php if (!empty($report['wallcloud']) && $report['wallcloud'] > 0) : ?>
                                                        <tr>
                                                            <th><?php esc_html_e('Wall Cloud', 'stormchases'); ?></th>
                                                            <td><?php esc_html_e('Yes', 'stormchases'); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php if (!empty($report['damage']) && $report['damage'] > 0) : ?>
                                                        <tr>
                                                            <th><?php esc_html_e('Damage', 'stormchases'); ?></th>
                                                            <td><?php esc_html_e('Yes', 'stormchases'); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                </table>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else : ?>
                        <p><?php esc_html_e('No Spotter Network reports available.', 'stormchases'); ?></p>
                    <?php endif; ?>
                </div>
                <footer class="entry-footer">
                    <?php edit_post_link(__('Edit', 'stormchases'), '<span class="edit-link">', '</span>'); ?>
                </footer>
            </article>
        <?php endwhile; ?>
    </main>
</div>

<?php
get_sidebar();
get_footer();