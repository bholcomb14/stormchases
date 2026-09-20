<?php
if (!defined('ABSPATH')) {
    exit;
}

global $post;
$data_handler = new StormChasesData();
$chase_data   = $data_handler->get_chase_data($post->ID);

// Determine whether this post is flagged as best chase for its year AND its own type —
// Best Chase of the Season is tracked per type (Convective/Hurricane/Winter each get their
// own pick for a given year), see sc_get_best_chases() in functions.php.
$best_chases    = sc_get_best_chases();
$chase_year     = substr($chase_data['chasedate'], 0, 4);
$is_best_chase  = isset($best_chases[$chase_year][$chase_data['chase_type']]) && $best_chases[$chase_year][$chase_data['chase_type']] == $post->ID;
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
        <th><label for="chase_type"><?php esc_html_e('Chase Type', 'stormchases'); ?></label></th>
        <td>
            <select id="chase_type" name="chase_type">
                <?php foreach (StormChasesData::get_chase_types() as $type) : ?>
                    <option value="<?php echo esc_attr($type); ?>" <?php selected($chase_data['chase_type'], $type); ?>><?php echo esc_html($type); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Changes which fields below apply. Switching away from a type hides its fields without deleting their data — switch back and it\'s still there.', 'stormchases'); ?></p>
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
    <tr data-chase-type-group="Convective">
        <th><label for="chasehail"><?php esc_html_e('Largest Hail (in.)', 'stormchases'); ?></label></th>
        <td><input type="number" id="chasehail" name="chasehail" value="<?php echo esc_attr($chase_data['chasehail']); ?>" step="0.01" min="0" max="9.99"></td>
    </tr>
    <tr data-chase-type-group="Convective">
        <th><label><?php esc_html_e('Storm Mode', 'stormchases'); ?></label></th>
        <td>
            <fieldset>
                <?php foreach (StormChasesData::get_storm_modes() as $mode) : ?>
                    <label style="display:block;margin-bottom:4px;">
                        <input type="checkbox" name="storm_mode[]" value="<?php echo esc_attr($mode); ?>" <?php checked(in_array($mode, $chase_data['storm_mode'], true)); ?>>
                        <?php echo esc_html($mode); ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <p class="description"><?php esc_html_e('Select every mode that applied — a chase day can transition between them (e.g. discrete supercells congealing into a squall line later on).', 'stormchases'); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="chasewind"><?php esc_html_e('Highest Wind (mph)', 'stormchases'); ?></label></th>
        <td>
            <input type="number" id="chasewind" name="chasewind" value="<?php echo esc_attr($chase_data['chasewind']); ?>" min="0" max="300">
            <p class="description"><?php esc_html_e('Capped at 300 — anything higher is almost certainly a typo. Values over 120 are flagged for a second look but still accepted.', 'stormchases'); ?></p>
        </td>
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
            <?php /* translators: 1: chase type (e.g. Convective, Hurricane), 2: chase year or "this year" */ ?>
            <p class="description"><?php echo esc_html(sprintf(__('Only one chase per season, per Chase Type, can be the best — a Hurricane pick and a Convective pick for the same year don\'t conflict. Checking this will replace any previous %1$s selection for %2$s.', 'stormchases'), $chase_data['chase_type'], $chase_year ?: __('this year', 'stormchases'))); ?></p>
        </td>
    </tr>
    <?php endif; ?>
    <tr>
        <th><label><?php esc_html_e('Tornadoes Witnessed', 'stormchases'); ?></label></th>
        <td>
            <p class="description"><?php esc_html_e('Drag, or use the arrows, to reorder. The top entry displays first on the public chase page. Click an entry to expand it for editing.', 'stormchases'); ?></p>
            <div id="tornadoes-container" data-tornado-count="<?php echo esc_attr(count($chase_data['tornadoes'])); ?>">
                <?php foreach (array_values($chase_data['tornadoes']) as $index => $tornado) : ?>
                    <?php echo sc_render_tornado_entry_html($index, $tornado, false); // collapsed — see sc_render_tornado_entry_html() ?>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-tornado" class="button"><?php esc_html_e('Add Tornado', 'stormchases'); ?></button>
        </td>
    </tr>
    <tr data-chase-type-group="Hurricane">
        <th><label><?php esc_html_e('Hurricane Landfalls', 'stormchases'); ?></label></th>
        <td>
            <p class="description"><?php esc_html_e('One entry per landfall — a storm can weaken and restrengthen between multiple landfalls on the same chase, so wind speed, category, and pressure are logged per landfall.', 'stormchases'); ?></p>
            <div id="landfalls-container" data-landfall-count="<?php echo esc_attr(count($chase_data['landfalls'])); ?>">
                <?php foreach (array_values($chase_data['landfalls']) as $index => $landfall) : ?>
                    <?php echo sc_render_landfall_entry_html($index, $landfall, false); // collapsed — see sc_render_landfall_entry_html() ?>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-landfall" class="button"><?php esc_html_e('Add Landfall', 'stormchases'); ?></button>
        </td>
    </tr>
    <tr data-chase-type-group="Winter">
        <th><label><?php esc_html_e('Snowfall Reports', 'stormchases'); ?></label></th>
        <td>
            <p class="description"><?php esc_html_e('One entry per location/time a snowfall depth was recorded. A town/place name is included since a bare coordinate means little at a glance.', 'stormchases'); ?></p>
            <div id="snowfall-container" data-snowfall-count="<?php echo esc_attr(count($chase_data['snowfall_reports'])); ?>">
                <?php foreach (array_values($chase_data['snowfall_reports']) as $index => $snowfall) : ?>
                    <?php echo sc_render_snowfall_entry_html($index, $snowfall, false); // collapsed — see sc_render_snowfall_entry_html() ?>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-snowfall" class="button"><?php esc_html_e('Add Snowfall Report', 'stormchases'); ?></button>
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
            /* translators: %d: number of Spotter Network reports attached to this chase */
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
