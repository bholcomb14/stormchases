<?php
if (!defined('ABSPATH')) {
    exit;
}

// Rendered by Storm_Chases_Wizard::render_page() with $step, $post, $errors, $sticky already
// in scope. Three steps, one file (matches templates/admin/settings.php's single-file
// convention for this plugin's admin screens) — step 1 is the required-field gate, steps 2/3
// are optional and just re-render subsets of templates/stormchases.php's own field markup
// against the draft post step 1 already created, so admin.js's existing chase-type-visibility/
// repeater/milestone/chasemap-upload behavior works completely unmodified here.
$data_handler = $post ? new StormChasesData() : null;
$chase_data = $post ? $data_handler->get_chase_data($post->ID) : null;
?>
<div class="wrap storm-chases-wizard">
    <h1><?php esc_html_e('Add New Storm Chase', 'stormchases'); ?></h1>

    <p class="storm-chases-wizard-steps">
        <?php
        $step_labels = [
            1 => __('1. The Essentials', 'stormchases'),
            2 => __('2. Weather Details (optional)', 'stormchases'),
            3 => __('3. Chase Map (optional)', 'stormchases'),
        ];
        $parts = [];
        foreach ($step_labels as $num => $label) {
            $parts[] = $num === $step
                ? '<strong>' . esc_html($label) . '</strong>'
                : esc_html($label);
        }
        echo implode(' &rarr; ', $parts);
        ?>
    </p>

    <?php if (!empty($errors)) : ?>
        <div class="notice notice-error">
            <ul style="margin: 0.5em 0;">
                <?php foreach ($errors as $error) : ?>
                    <li><?php echo esc_html($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($step === 1) : ?>

        <p class="description">
            <?php esc_html_e('A few required basics to get this chase started. You\'ll be able to add tornadoes, wind/hail, landfalls, and a chase map next — none of that is required here.', 'stormchases'); ?>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="storm_chases_wizard_step1">
            <?php wp_nonce_field(Storm_Chases_Wizard::STEP1_NONCE_ACTION, 'storm_chases_wizard_nonce'); ?>
            <?php wp_nonce_field('storm_chases_save_post', 'storm_chases_nonce'); ?>

            <table class="storm-chases-meta-box form-table">
                <tr>
                    <th><label for="post_title"><?php esc_html_e('Title', 'stormchases'); ?></label></th>
                    <td>
                        <input type="text" id="post_title" name="post_title" value="<?php echo esc_attr($sticky['title'] ?? ''); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('Optional — you can add or change this on the next screen too.', 'stormchases'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wizard_chase_date"><?php esc_html_e('Chase Date', 'stormchases'); ?></label></th>
                    <td>
                        <input type="date" id="wizard_chase_date" name="wizard_chase_date" value="<?php echo esc_attr($sticky['chase_date'] ?? ''); ?>" max="<?php echo esc_attr(current_time('Y-m-d')); ?>" required>
                        <p class="description"><?php esc_html_e('This becomes the chase\'s publish date and URL — same as changing the Publish date on the edit screen.', 'stormchases'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="chase_type"><?php esc_html_e('Chase Type', 'stormchases'); ?></label></th>
                    <td>
                        <select id="chase_type" name="chase_type" required>
                            <option value=""><?php esc_html_e('— Select —', 'stormchases'); ?></option>
                            <?php foreach (StormChasesData::get_chase_types() as $type) : ?>
                                <option value="<?php echo esc_attr($type); ?>" <?php selected($sticky['chase_type'] ?? '', $type); ?>><?php echo esc_html($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="chasestates"><?php esc_html_e('States Chased', 'stormchases'); ?></label></th>
                    <td><input type="text" id="chasestates" name="chasestates" value="<?php echo esc_attr($sticky['chasestates'] ?? ''); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="chasepartners"><?php esc_html_e('Chase Partners', 'stormchases'); ?></label></th>
                    <td>
                        <input type="text" id="chasepartners" name="chasepartners" value="<?php echo esc_attr($sticky['chasepartners'] ?? ''); ?>" class="regular-text" placeholder="<?php esc_attr_e('Solo', 'stormchases'); ?>" required>
                        <p class="description"><?php esc_html_e('Type "Solo" if you chased alone, or list who you were with.', 'stormchases'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="chasechasers"><?php esc_html_e('Chasers Encountered', 'stormchases'); ?></label></th>
                    <td><input type="text" id="chasechasers" name="chasechasers" value="<?php echo esc_attr($sticky['chasechasers'] ?? ''); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="chasemiles"><?php esc_html_e('Miles Logged', 'stormchases'); ?></label></th>
                    <td>
                        <input type="number" id="chasemiles" name="chasemiles" value="<?php echo esc_attr($sticky['chasemiles'] ?? ''); ?>" min="0" max="9999" required>
                        <p class="description"><?php esc_html_e('Enter 0 if you didn\'t track mileage.', 'stormchases'); ?></p>
                    </td>
                </tr>
            </table>

            <p><button type="submit" class="button button-primary"><?php esc_html_e('Continue', 'stormchases'); ?></button></p>
        </form>

    <?php elseif ($step === 2) : ?>

        <p class="description">
            <?php echo esc_html(sprintf(
                /* translators: %s: chase type chosen in step 1 */
                __('Chase Type: %s. Add whatever weather details you have — tornadoes, wind, hail, landfalls, snowfall. All optional, all editable later.', 'stormchases'),
                $chase_data['chase_type'] ?? 'Convective'
            )); ?>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="storm_chases_wizard_step2">
            <input type="hidden" name="post_id" value="<?php echo esc_attr($post->ID); ?>">
            <input type="hidden" id="chase_type" value="<?php echo esc_attr($chase_data['chase_type'] ?? 'Convective'); ?>">
            <?php wp_nonce_field(Storm_Chases_Wizard::STEP2_NONCE_ACTION, 'storm_chases_wizard_nonce'); ?>
            <?php wp_nonce_field('storm_chases_save_post', 'storm_chases_nonce'); ?>

            <table class="storm-chases-meta-box form-table">
                <tr>
                    <th><label for="chasewind"><?php esc_html_e('Highest Wind (mph)', 'stormchases'); ?></label></th>
                    <td><input type="number" id="chasewind" name="chasewind" value="<?php echo esc_attr($chase_data['chasewind']); ?>" min="0" max="300"></td>
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
                    </td>
                </tr>
                <tr data-chase-type-group="Convective">
                    <th><label><?php esc_html_e('Tornadoes Witnessed', 'stormchases'); ?></label></th>
                    <td>
                        <div id="tornadoes-container" data-tornado-count="<?php echo esc_attr(count($chase_data['tornadoes'])); ?>">
                            <?php foreach (array_values($chase_data['tornadoes']) as $index => $tornado) : ?>
                                <?php echo sc_render_tornado_entry_html($index, $tornado, false); ?>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="add-tornado" class="button"><?php esc_html_e('Add Tornado', 'stormchases'); ?></button>
                    </td>
                </tr>
                <tr data-chase-type-group="Hurricane">
                    <th><label><?php esc_html_e('Hurricane Landfalls', 'stormchases'); ?></label></th>
                    <td>
                        <div id="landfalls-container" data-landfall-count="<?php echo esc_attr(count($chase_data['landfalls'])); ?>">
                            <?php foreach (array_values($chase_data['landfalls']) as $index => $landfall) : ?>
                                <?php echo sc_render_landfall_entry_html($index, $landfall, false); ?>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="add-landfall" class="button"><?php esc_html_e('Add Landfall', 'stormchases'); ?></button>
                    </td>
                </tr>
                <tr data-chase-type-group="Winter">
                    <th><label><?php esc_html_e('Snowfall Reports', 'stormchases'); ?></label></th>
                    <td>
                        <div id="snowfall-container" data-snowfall-count="<?php echo esc_attr(count($chase_data['snowfall_reports'])); ?>">
                            <?php foreach (array_values($chase_data['snowfall_reports']) as $index => $snowfall) : ?>
                                <?php echo sc_render_snowfall_entry_html($index, $snowfall, false); ?>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="add-snowfall" class="button"><?php esc_html_e('Add Snowfall Report', 'stormchases'); ?></button>
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
            </table>

            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Continue', 'stormchases'); ?></button>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=storm_chase&page=' . Storm_Chases_Wizard::PAGE_SLUG . '&step=3&post=' . $post->ID)); ?>" class="button"><?php esc_html_e('Skip this step', 'stormchases'); ?></a>
            </p>
        </form>

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

    <?php else : // step 3 ?>

        <p class="description">
            <?php esc_html_e('Upload a GPS track or a map image if you have one — optional, and you can add or change it any time from the edit screen.', 'stormchases'); ?>
        </p>

        <table class="storm-chases-meta-box form-table">
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
        </table>

        <p>
            <a href="<?php echo esc_url(admin_url('post.php?action=edit&post=' . $post->ID)); ?>" class="button button-primary">
                <?php esc_html_e('Finish — Go to Edit Screen', 'stormchases'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=storm_chase&page=' . Storm_Chases_Wizard::PAGE_SLUG)); ?>" class="button">
                <?php esc_html_e('Add Another Chase', 'stormchases'); ?>
            </a>
        </p>
        <p class="description">
            <?php esc_html_e('Logging several chases in one sitting? "Add Another Chase" starts a fresh wizard right away — this one is already saved as a draft either way, so nothing is lost by moving on.', 'stormchases'); ?>
        </p>

    <?php endif; ?>
</div>
