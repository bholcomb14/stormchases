<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'stormchases'));
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Storm Chases Settings', 'stormchases'); ?></h1>
    <form method="post" action="options.php">
        <?php
        settings_fields('storm_chases_group');
        wp_nonce_field('storm_chases_settings', 'storm_chases_nonce');
        $options = wp_load_alloptions();
        ?>

        <table class="form-table" role="presentation">
            <?php
            $fields = [
                'chase_partners_enable' => __('Show Chase Partners', 'stormchases'),
                'chasers_encountered_enable' => __('Show Chasers Encountered', 'stormchases'),
                'miles_logged_enable' => __('Enable Miles Logged', 'stormchases'),
                'states_chased_enable' => __('Enable States Chased', 'stormchases'),
                'tornadoes_enable' => __('Enable Tornadoes', 'stormchases'),
                'wind_enable' => __('Enable Wind', 'stormchases'),
                'hail_enable' => __('Enable Hail', 'stormchases'),
                'milestones_enable' => __('Enable Milestones', 'stormchases'),
                'google_maps_enable' => __('Enable Maps', 'stormchases'),
                'spotter_reports_enable' => __('Enable Spotter Network Reports', 'stormchases'),
            ];
            ?>

            <?php foreach ($fields as $field => $label) : ?>
                <tr>
                    <th scope="row">
                        <label for="<?php echo esc_attr($field); ?>">
                            <?php echo esc_html($label); ?>
                        </label>
                    </th>
                    <td>
                        <input
                            type="checkbox"
                            class="storm-chases-toggle"
                            id="<?php echo esc_attr($field); ?>"
                            name="<?php echo esc_attr($field); ?>"
                            value="1"
                            <?php checked(1, isset($options[$field]) ? (int) $options[$field] : 0); ?>
                            aria-describedby="<?php echo esc_attr($field); ?>-description"
                        />
                        <span id="<?php echo esc_attr($field); ?>-description" class="screen-reader-text">
                            <?php echo esc_html($label); ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>

            <tr>
                <th scope="row">
                    <label for="google_maps_api_key">
                        <?php esc_html_e('Google Maps API Key', 'stormchases'); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="text"
                        id="google_maps_api_key"
                        name="google_maps_api_key"
                        value="<?php echo esc_attr($options['google_maps_api_key'] ?? ''); ?>"
                        class="regular-text"
                        aria-describedby="google_maps_api_key-description"
                    />
                    <p id="google_maps_api_key-description" class="description">
                        <?php esc_html_e('Enter a valid Google Maps API key (39 characters, alphanumeric with hyphens and underscores).', 'stormchases'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="storm_chases_max_file_size">
                        <?php esc_html_e('Maximum File Size (bytes)', 'stormchases'); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="number"
                        id="storm_chases_max_file_size"
                        name="storm_chases_max_file_size"
                        value="<?php echo esc_attr($options['storm_chases_max_file_size'] ?? 10 * 1024 * 1024); ?>"
                        min="1048576"
                        max="104857600"
                        class="regular-text"
                        aria-describedby="storm_chases_max_file_size-description"
                    />
                    <p id="storm_chases_max_file_size-description" class="description">
                        <?php esc_html_e('Set the maximum file size for uploads (1MB to 100MB).', 'stormchases'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="storm_chases_supported_file_types">
                        <?php esc_html_e('Supported File Types', 'stormchases'); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="text"
                        id="storm_chases_supported_file_types"
                        name="storm_chases_supported_file_types"
                        value="<?php echo esc_attr(implode(',', $options['storm_chases_supported_file_types'] ?? ['image/jpeg', 'image/png', 'image/gif', 'application/vnd.google-earth.kml+xml'])); ?>"
                        class="regular-text"
                        aria-describedby="storm_chases_supported_file_types-description"
                    />
                    <p id="storm_chases_supported_file_types-description" class="description">
                        <?php esc_html_e('Enter comma-separated MIME types (e.g., image/jpeg,image/png, test/ben).', 'stormchases'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <h2><?php esc_html_e('Upload Spotter Network Reports', 'stormchases'); ?></h2>
    <form id="storm-chases-settings-form" enctype="multipart/form-data" method="post">
        <table class="form-table">
            <tr>
                <th><label for="spotter_reports"><?php esc_html_e('Upload Spotter Network Reports (CSV)', 'stormchases'); ?></label></th>
                <td>
                    <input type="file" id="spotter_reports" name="spotter_reports" accept=".csv,.txt">
                    <label><input type="checkbox" name="overwrite_reports" value="1"> <?php esc_html_e('Overwrite existing reports', 'stormchases'); ?></label>
                    <p class="description"><?php esc_html_e('Upload a CSV file to populate Spotter Network reports for all relevant storm chase posts based on report dates.', 'stormchases'); ?></p>
                </td>
            </tr>
        </table>
        <p class="submit">
            <button type="button" id="upload-spotter-reports" class="button button-primary"><?php esc_html_e('Upload Reports', 'stormchases'); ?></button>
        </p>
    </form>
    <div id="upload-message"></div>
</div>