<?php
if (!defined('ABSPATH')) {
    exit;
}

global $post;
$data_handler = new StormChasesData();
$chase_data = $data_handler->get_chase_data($post->ID);
?>

<table class="storm-chases-meta-box">
    <tr>
        <th><label for="chasedate"><?php esc_html_e('Chase Date (YYYYMMDD)', 'stormchases'); ?></label></th>
        <td><input type="text" id="chasedate" name="chasedate" value="<?php echo esc_attr($chase_data['chasedate']); ?>" required></td>
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
        <th><label for="chasems"><?php esc_html_e('Chase Milestones', 'stormchases'); ?></label></th>
        <td><textarea id="chasems" name="chasems"><?php echo esc_textarea($chase_data['chasems']); ?></textarea></td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Tornadoes Witnessed', 'stormchases'); ?></label></th>
        <td>
            <div id="tornadoes-container" data-tornado-count="<?php echo esc_attr(count($chase_data['tornadoes'])); ?>">
                <?php foreach ($chase_data['tornadoes'] as $index => $tornado) : ?>
                    <div class="tornado-entry">
                        <label><?php esc_html_e('Name', 'stormchases'); ?> <input type="text" name="tornadoes[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($tornado['name'] ?? ''); ?>"></label>
                        <label><?php esc_html_e('Latitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][lat]" value="<?php echo esc_attr($tornado['lat'] ?? 0); ?>"></label>
                        <label><?php esc_html_e('Longitude', 'stormchases'); ?> <input type="number" step="0.0001" name="tornadoes[<?php echo esc_attr($index); ?>][lon]" value="<?php echo esc_attr($tornado['lon'] ?? 0); ?>"></label>
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
                        <label><?php esc_html_e('Photo', 'stormchases'); ?> 
                            <input type="hidden" class="tornado-photo-id" name="tornadoes[<?php echo esc_attr($index); ?>][photo_id]" value="<?php echo esc_attr($tornado['photo_id'] ?? 0); ?>">
                            <input type="text" class="tornado-photo-url" value="<?php echo esc_attr(wp_get_attachment_url($tornado['photo_id'] ?? 0)); ?>" readonly>
                            <button type="button" class="upload-tornado-photo-button button"><?php esc_html_e('Upload Photo', 'stormchases'); ?></button>
                        </label>
                        <label><input type="checkbox" name="tornadoes[<?php echo esc_attr($index); ?>][photogenic]" <?php checked($tornado['photogenic'] ?? false); ?>> <?php esc_html_e('Photogenic', 'stormchases'); ?></label>
                        <button type="button" class="remove-tornado button"><?php esc_html_e('Remove Tornado', 'stormchases'); ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-tornado" class="button"><?php esc_html_e('Add Tornado', 'stormchases'); ?></button>
        </td>
    </tr>
    <tr>
        <th><label for="chasemap"><?php esc_html_e('Chase Map', 'stormchases'); ?></label></th>
        <td>
            <input type="file" id="chasemap" name="chasemap" accept="<?php echo esc_attr(implode(',', get_option('storm_chases_supported_file_types', ['image/jpeg', 'image/png', 'image/gif', 'application/vnd.google-earth.kml+xml']))); ?>">
            <?php if ($chasemap_id = get_post_meta($post->ID, 'chasemap_id', true)) : ?>
                <p><?php echo wp_get_attachment_image($chasemap_id, 'thumbnail'); ?>
                <label><input type="checkbox" name="rmchasemap" value="1"> <?php esc_html_e('Remove Chase Map', 'stormchases'); ?></label></p>
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
<?php wp_nonce_field('storm_chases_save_post', 'storm_chases_nonce'); ?>