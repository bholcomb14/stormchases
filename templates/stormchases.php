<?php
if (!defined('ABSPATH')) {
    exit;
}

global $post;
$data_handler = new StormChasesData();
$chase_data   = $data_handler->get_chase_data($post->ID);

// Determine whether this post is flagged as best chase for its year
$best_chases    = get_option('storm_chases_best_chases', []);
$chase_year     = substr($chase_data['chasedate'], 0, 4);
$is_best_chase  = isset($best_chases[$chase_year]) && $best_chases[$chase_year] == $post->ID;
?>

<table class="storm-chases-meta-box">
    <tr>
        <th><label for="chasedate"><?php esc_html_e('Chase Date (YYYYMMDD)', 'stormchases'); ?></label></th>
        <td>
            <input type="text" id="chasedate" name="chasedate" value="<?php echo esc_attr($chase_data['chasedate']); ?>" readonly style="background:#f0f0f0; cursor:default;">
            <p class="description"><?php esc_html_e('Always matches the Publish date and the URL slug. Change the Publish date in the sidebar and this — plus the slug — updates automatically.', 'stormchases'); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="chasestates"><?php esc_html_e('States Chased', 'stormchases'); ?></label></th>
        <td><input type="text" id="chasestates" name="chasestates" value="<?php echo esc_attr($chase_data['chasestates']); ?>" required></td>
    </tr>
    <tr>
        <th><label for="chasepartners"><?php esc_html_e('Chase Partners', 'stormchases'); ?></label></th>
        <td><input type="text" id="chasepartners" name="chasepartners" value="<?php echo esc_attr($chase_data['chasepartners']); ?>"></td>
    </tr>
    <tr>
        <th><label for="chasechasers"><?php esc_html_e('Chasers Encountered', 'stormchases'); ?></label></th>
        <td><input type="text" id="chasechasers" name="chasechasers" value="<?php echo esc_attr($chase_data['chasechasers']); ?>"></td>
    </tr>
    <tr>
        <th><label for="chasemiles"><?php esc_html_e('Miles Logged', 'stormchases'); ?></label></th>
        <td><input type="number" id="chasemiles" name="chasemiles" value="<?php echo esc_attr($chase_data['chasemiles']); ?>" min="0" max="9999"></td>
    </tr>
    <tr>
        <th><label for="chasehail"><?php esc_html_e('Largest Hail (in.)', 'stormchases'); ?></label></th>
        <td><input type="number" id="chasehail" name="chasehail" value="<?php echo esc_attr($chase_data['chasehail']); ?>" step="0.01" min="0" max="9.99"></td>
    </tr>
    <tr>
        <th><label for="chasewind"><?php esc_html_e('Highest Wind (mph)', 'stormchases'); ?></label></th>
        <td><input type="number" id="chasewind" name="chasewind" value="<?php echo esc_attr($chase_data['chasewind']); ?>" min="0" max="999"></td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Chase Milestones', 'stormchases'); ?></label></th>
        <td>
            <p class="description"><?php esc_html_e('Press Enter or click "+ Add Milestone" to add another bullet point.', 'stormchases'); ?></p>
            <div id="chasems-container" class="storm-chases-milestone-list">
                <?php if (empty($chase_data['chasems'])) : ?>
                    <input type="hidden" name="chasems" value="">
                <?php else : ?>
                    <?php foreach ($chase_data['chasems'] as $milestone) : ?>
                        <div class="chasems-item">
                            <span class="chasems-bullet" aria-hidden="true">&bull;</span>
                            <input type="text" name="chasems[]" value="<?php echo esc_attr($milestone); ?>">
                            <button type="button" class="button-link remove-milestone" title="<?php esc_attr_e('Remove', 'stormchases'); ?>"><span class="dashicons dashicons-no-alt"></span></button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" id="add-milestone" class="button"><?php esc_html_e('+ Add Milestone', 'stormchases'); ?></button>
        </td>
    </tr>
    <?php if (get_option('best_chase_enable')) : ?>
    <tr>
        <th><label for="is_best_chase"><?php esc_html_e('Best Chase of the Season', 'stormchases'); ?></label></th>
        <td>
            <input type="checkbox" id="is_best_chase" name="is_best_chase" value="1" <?php checked($is_best_chase); ?>>
            <label for="is_best_chase"><?php esc_html_e('Mark as the best/favorite chase of this season', 'stormchases'); ?></label>
            <p class="description"><?php echo esc_html(sprintf(__('Only one chase per season can be the best. Checking this will replace any previous selection for %s.', 'stormchases'), $chase_year ?: __('this year', 'stormchases'))); ?></p>
        </td>
    </tr>
    <?php endif; ?>
    <tr>
        <th><label><?php esc_html_e('Tornadoes Witnessed', 'stormchases'); ?></label></th>
        <td>
            <p class="description"><?php esc_html_e('Drag, or use the arrows, to reorder. The top entry displays first on the public chase page. Click an entry to expand it for editing.', 'stormchases'); ?></p>
            <div id="tornadoes-container" data-tornado-count="<?php echo esc_attr(count($chase_data['tornadoes'])); ?>">
                <?php foreach (array_values($chase_data['tornadoes']) as $index => $tornado) : ?>
                    <div class="tornado-entry">
                        <div class="tornado-entry-header">
                            <span class="dashicons dashicons-move tornado-drag-handle" title="<?php esc_attr_e('Drag to reorder', 'stormchases'); ?>"></span>
                            <button type="button" class="button-link tornado-move-up" title="<?php esc_attr_e('Move up', 'stormchases'); ?>"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
                            <button type="button" class="button-link tornado-move-down" title="<?php esc_attr_e('Move down', 'stormchases'); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
                            <button type="button" class="tornado-entry-summary"><?php echo esc_html(($tornado['name'] ?: __('Unnamed Tornado', 'stormchases')) . ' — ' . ($tornado['ef_rating'] ?? 'Unrated')); ?></button>
                            <button type="button" class="button-link tornado-toggle" title="<?php esc_attr_e('Expand or collapse', 'stormchases'); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
                            <button type="button" class="button-link remove-tornado" title="<?php esc_attr_e('Remove tornado', 'stormchases'); ?>"><span class="dashicons dashicons-no-alt"></span></button>
                        </div>
                        <div class="tornado-entry-body">
                            <label><?php esc_html_e('Name', 'stormchases'); ?> <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($tornado['name'] ?? ''); ?>"></label>
                            <label><?php esc_html_e('Latitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][lat]" value="<?php echo esc_attr($tornado['lat'] ?? 0); ?>"></label>
                            <label><?php esc_html_e('Longitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][lon]" value="<?php echo esc_attr($tornado['lon'] ?? 0); ?>"></label>
                            <button type="button" class="button tornado-pick-location" data-lat-field="lat" data-lon-field="lon"><?php esc_html_e('📍 Pick Location on Map', 'stormchases'); ?></button>
                            <label><?php esc_html_e('EF Rating', 'stormchases'); ?>
                                <select name="tornadoes[<?php echo esc_attr($index); ?>][ef_rating]">
                                    <?php
                                    $ratings = ['Unrated', 'EF-U', 'EF-0', 'EF-1', 'EF-2', 'EF-3', 'EF-4', 'EF-5'];
                                    foreach ($ratings as $rating) {
                                        echo '<option value="' . esc_attr($rating) . '" ' . selected($tornado['ef_rating'] ?? 'Unrated', $rating, false) . '>' . esc_html($rating) . '</option>';
                                    }
                                    ?>
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
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-tornado" class="button"><?php esc_html_e('Add Tornado', 'stormchases'); ?></button>
        </td>
    </tr>
    <tr>
        <th><label for="chasemap"><?php esc_html_e('Chase Map', 'stormchases'); ?></label></th>
        <td>
            <?php if ($chase_data['chasemaptype'] === '3' && !empty($chase_data['chasemap_track'])) : ?>
                <p class="sc-chasemap-status">
                    <span class="dashicons dashicons-location-alt" style="vertical-align:middle;"></span>
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of simplified track points */
                        __('GPS track loaded — %d points stored', 'stormchases'),
                        count($chase_data['chasemap_track'])
                    )); ?>
                </p>
            <?php elseif (!empty($chase_data['chasemap_id'])) : ?>
                <p><?php echo wp_get_attachment_image($chase_data['chasemap_id'], 'thumbnail'); ?></p>
            <?php endif; ?>

            <input type="file" id="chasemap" accept=".kml,.gpx,.nmea,.jpg,.jpeg,.png,.gif" multiple style="display:block;margin-bottom:6px;">
            <p class="description" style="margin-bottom:8px;">
                <?php esc_html_e('GPS track: .kml, .gpx, .nmea — select multiple files for the same day to merge them into one track. Image: .jpg, .png, .gif.', 'stormchases'); ?>
            </p>
            <button type="button" id="upload-chasemap-btn" class="button button-secondary"><?php esc_html_e('Upload Chase Map', 'stormchases'); ?></button>
            <div id="chasemap-progress" style="display:none; margin-top:8px; max-width:400px;">
                <div class="sc-progress-bar"><div class="sc-progress-fill" id="chasemap-progress-fill"></div></div>
                <div id="chasemap-progress-label" class="sc-progress-label"><?php esc_html_e('Uploading…', 'stormchases'); ?></div>
            </div>
            <div id="chasemap-message" style="margin-top:6px;" aria-live="polite"></div>

            <?php if ($chase_data['chasemaptype'] !== '0') : ?>
                <p style="margin-top:10px;">
                    <button type="button" id="remove-chasemap-btn" class="button button-small">
                        <?php esc_html_e('Remove Chase Map', 'stormchases'); ?>
                    </button>
                </p>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th><label for="spotter_reports"><?php esc_html_e('Spotter Network Reports', 'stormchases'); ?></label></th>
        <td>
            <?php
            $report_count = count($chase_data['spotter_reports']);
            echo esc_html(sprintf(_n('%d report submitted', '%d reports submitted', $report_count, 'stormchases'), $report_count));
            ?>
        </td>
    </tr>
</table>

<div id="sc-location-picker-modal" class="modal" style="display:none;">
    <div class="modal-overlay"></div>
    <div class="modal-content">
        <span class="modal-close" aria-label="<?php esc_attr_e('Close', 'stormchases'); ?>">&times;</span>
        <h3><?php esc_html_e('Pick Location on Map', 'stormchases'); ?></h3>
        <p class="description"><?php esc_html_e('Click the map to place a marker at the tornado location.', 'stormchases'); ?></p>
        <div id="sc-location-picker-map" class="sc-location-picker-map"></div>
        <p class="sc-location-picker-readout" id="sc-location-picker-readout"><?php esc_html_e('No location selected yet.', 'stormchases'); ?></p>
        <button type="button" class="button button-primary" id="sc-location-picker-confirm" disabled><?php esc_html_e('Use This Location', 'stormchases'); ?></button>
        <button type="button" class="button" id="sc-location-picker-cancel"><?php esc_html_e('Cancel', 'stormchases'); ?></button>
    </div>
</div>

<?php wp_nonce_field('storm_chases_save_post', 'storm_chases_nonce'); ?>
