<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'stormchases'));
}

$windshields   = get_option('storm_chases_windshields', []);
$privacy_zones = get_option('storm_chases_privacy_zones', []);
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
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
                'chase_partners_enable'      => __('Show Chase Partners', 'stormchases'),
                'chasers_encountered_enable' => __('Show Chasers Encountered', 'stormchases'),
                'miles_logged_enable'        => __('Enable Miles Logged', 'stormchases'),
                'states_chased_enable'       => __('Enable States Chased', 'stormchases'),
                'tornadoes_enable'           => __('Enable Tornadoes', 'stormchases'),
                'wind_enable'                => __('Enable Wind', 'stormchases'),
                'hail_enable'                => __('Enable Hail', 'stormchases'),
                'milestones_enable'          => __('Enable Milestones', 'stormchases'),
                'best_chase_enable'          => __('Enable Best Chase of Season', 'stormchases'),
                'windshields_enable'         => __('Enable Windshields Replaced', 'stormchases'),
                'google_maps_enable'         => __('Enable Maps', 'stormchases'),
                'spotter_reports_enable'     => __('Enable Spotter Network Reports', 'stormchases'),
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
                            <?php
                            // get_option() (no explicit default arg) respects whatever default
                            // was registered via register_setting() for a not-yet-saved option —
                            // reading the raw $options blob here would always show 0/unchecked
                            // for a fresh install regardless of the registered default.
                            checked(1, (int) get_option($field));
                            ?>
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
                    <label for="map_provider"><?php esc_html_e('Map Provider', 'stormchases'); ?></label>
                </th>
                <td>
                    <select id="map_provider" name="map_provider" aria-describedby="map_provider-description">
                        <option value="openstreetmap" <?php selected($options['map_provider'] ?? 'openstreetmap', 'openstreetmap'); ?>>
                            <?php esc_html_e('OpenStreetMap (no API key required)', 'stormchases'); ?>
                        </option>
                        <option value="google" <?php selected($options['map_provider'] ?? 'openstreetmap', 'google'); ?>>
                            <?php esc_html_e('Google Maps (requires API key below)', 'stormchases'); ?>
                        </option>
                    </select>
                    <p id="map_provider-description" class="description">
                        <?php esc_html_e('Used for tornado maps, Spotter Network report maps, and individual chase KML maps.', 'stormchases'); ?>
                    </p>
                </td>
            </tr>

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
                        <?php esc_html_e('Required only when Google Maps is the selected map provider.', 'stormchases'); ?>
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
                        value="<?php echo esc_attr(implode(',', get_option('storm_chases_supported_file_types', ['image/jpeg', 'image/png', 'image/gif', 'application/vnd.google-earth.kml+xml']))); ?>"
                        class="regular-text"
                        aria-describedby="storm_chases_supported_file_types-description"
                    />
                    <p id="storm_chases_supported_file_types-description" class="description">
                        <?php esc_html_e('Comma-separated MIME types for chase map uploads (e.g., image/jpeg,image/png).', 'stormchases'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <hr>

    <!-- =====================================================================
         SPOTTER NETWORK CSV IMPORT
         ===================================================================== -->
    <h2><?php esc_html_e('Import Spotter Network Reports', 'stormchases'); ?></h2>

    <?php
    $php_upload_mb = (int) ini_get('upload_max_filesize');
    $php_post_mb   = (int) ini_get('post_max_size');
    $low_limit     = min($php_upload_mb, $php_post_mb);
    if ($low_limit < 50) : ?>
    <div class="notice notice-warning inline" style="margin-bottom:12px;">
        <p>
            <strong><?php esc_html_e('PHP upload limit is low for GPS track files.', 'stormchases'); ?></strong>
            <?php printf(
                /* translators: 1: upload_max_filesize, 2: post_max_size */
                esc_html__('Current limits: upload_max_filesize = %1$sMB, post_max_size = %2$sMB. NMEA files from a 1 Hz GPS logger can reach 5–90 MB depending on recording duration and sentence density. Increase these values in php.ini or .htaccess (php_value upload_max_filesize 200M).', 'stormchases'),
                esc_html($php_upload_mb),
                esc_html($php_post_mb)
            ); ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="sc-upload-help">
        <p><?php esc_html_e('Upload your complete Spotter Network reports history (CSV export) to automatically attach reports to the correct chase logs by date.', 'stormchases'); ?></p>
        <details class="sc-upload-details">
            <summary><?php esc_html_e('How to export from Spotter Network &amp; expected CSV format', 'stormchases'); ?></summary>
            <div class="sc-upload-details-body">
                <ol>
                    <li><?php esc_html_e('Log in to your Spotter Network account and open your reports history page.', 'stormchases'); ?></li>
                    <li><?php esc_html_e('Export / download your reports as a CSV file.', 'stormchases'); ?></li>
                    <li><?php esc_html_e('Upload that file here. Reports are matched to chase logs by date (adjusting for reports filed before 10:00 UTC, which are attributed to the previous calendar day).', 'stormchases'); ?></li>
                </ol>
                <p><strong><?php esc_html_e('Required CSV column headers (case-insensitive):', 'stormchases'); ?></strong></p>
                <code>report, report_type, stamp, lat, lon, narrative, tornado, hailsize, windspeed, city1, cwa</code>
                <p class="description"><?php esc_html_e('Only rows with report_type "S" are imported. Rows without a matching chase log are listed in the results summary.', 'stormchases'); ?></p>
            </div>
        </details>
    </div>

    <form id="storm-chases-settings-form" enctype="multipart/form-data" method="post">
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="spotter_reports"><?php esc_html_e('Reports CSV File', 'stormchases'); ?></label>
                </th>
                <td>
                    <div class="sc-file-drop-zone" id="sc-file-drop-zone">
                        <input type="file" id="spotter_reports" name="spotter_reports" accept=".csv,.txt" class="sc-file-input">
                        <div class="sc-file-drop-label">
                            <span class="dashicons dashicons-upload sc-upload-icon"></span>
                            <span class="sc-file-drop-text"><?php esc_html_e('Click to choose a CSV file, or drag and drop it here', 'stormchases'); ?></span>
                            <span class="sc-file-name" id="sc-file-name" style="display:none;"></span>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Options', 'stormchases'); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" id="overwrite_reports" name="overwrite_reports" value="1">
                            <?php esc_html_e('Overwrite existing reports for matched dates', 'stormchases'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When checked, all existing reports for any matched chase date are replaced with the CSV data. When unchecked, only new report IDs are added.', 'stormchases'); ?></p>
                        <br>
                        <label>
                            <input type="checkbox" id="dry_run" name="dry_run" value="1">
                            <?php esc_html_e('Preview only — do not save (dry run)', 'stormchases'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('See a summary of what would be imported without making any changes to your chase logs.', 'stormchases'); ?></p>
                    </fieldset>
                </td>
            </tr>
        </table>
        <p class="submit">
            <button type="button" id="upload-spotter-reports" class="button button-primary">
                <span class="sc-btn-text"><?php esc_html_e('Upload Reports', 'stormchases'); ?></span>
                <span class="sc-btn-spinner" style="display:none;">
                    <span class="spinner is-active" style="float:none; margin:0 4px -4px;"></span>
                    <?php esc_html_e('Processing&hellip;', 'stormchases'); ?>
                </span>
            </button>
        </p>
    </form>
    <div id="upload-message" aria-live="polite"></div>

    <hr>

    <!-- =====================================================================
         WINDSHIELD REPLACEMENTS
         ===================================================================== -->
    <h2><?php esc_html_e('Windshield Replacements', 'stormchases'); ?></h2>
    <p class="description">
        <?php esc_html_e('Track each windshield replacement. Multiple entries in the same month are allowed (e.g., two hail events one week apart). The total appears in your chase statistics when "Enable Windshields Replaced" is turned on.', 'stormchases'); ?>
    </p>

    <table class="wp-list-table widefat fixed striped" id="sc-windshield-table" style="max-width:600px;">
        <thead>
            <tr>
                <th><?php esc_html_e('Month', 'stormchases'); ?></th>
                <th><?php esc_html_e('Year', 'stormchases'); ?></th>
                <th style="width:80px;"><?php esc_html_e('Action', 'stormchases'); ?></th>
            </tr>
        </thead>
        <tbody id="sc-windshield-list">
            <?php if (empty($windshields)) : ?>
                <tr id="sc-windshield-empty">
                    <td colspan="3"><?php esc_html_e('No windshield replacements recorded yet.', 'stormchases'); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ($windshields as $i => $ws) : ?>
                    <tr data-index="<?php echo esc_attr($i); ?>">
                        <td><?php echo esc_html($months[(int) $ws['month']] ?? $ws['month']); ?></td>
                        <td><?php echo esc_html($ws['year']); ?></td>
                        <td>
                            <button type="button" class="button button-small sc-delete-windshield" data-index="<?php echo esc_attr($i); ?>">
                                <?php esc_html_e('Delete', 'stormchases'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h3><?php esc_html_e('Add New Entry', 'stormchases'); ?></h3>
    <table class="form-table" style="max-width:400px;">
        <tr>
            <th scope="row"><label for="sc-ws-month"><?php esc_html_e('Month', 'stormchases'); ?></label></th>
            <td>
                <select id="sc-ws-month">
                    <?php foreach ($months as $num => $name) : ?>
                        <option value="<?php echo esc_attr($num); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="sc-ws-year"><?php esc_html_e('Year', 'stormchases'); ?></label></th>
            <td>
                <input type="number" id="sc-ws-year" value="<?php echo esc_attr(date('Y')); ?>" min="1990" max="<?php echo esc_attr((int) date('Y') + 1); ?>" class="small-text">
            </td>
        </tr>
    </table>
    <p>
        <button type="button" id="sc-add-windshield" class="button button-secondary">
            <?php esc_html_e('Add Entry', 'stormchases'); ?>
        </button>
    </p>
    <div id="sc-windshield-message" aria-live="polite"></div>

    <hr>

    <!-- =====================================================================
         GPS TRACK PRIVACY ZONES
         ===================================================================== -->
    <h2><?php esc_html_e('GPS Track Privacy Zones', 'stormchases'); ?></h2>
    <p class="description">
        <?php esc_html_e('Points that fall within a zone radius are stripped from the start and end of any uploaded GPS track before it is stored — so your home address, garage, or staging area never appears. Points in the middle of the track (e.g., driving through your home town during a chase) are kept.', 'stormchases'); ?>
    </p>

    <table class="wp-list-table widefat fixed striped" id="sc-privacy-table" style="max-width:680px;">
        <thead>
            <tr>
                <th><?php esc_html_e('Label', 'stormchases'); ?></th>
                <th><?php esc_html_e('Latitude', 'stormchases'); ?></th>
                <th><?php esc_html_e('Longitude', 'stormchases'); ?></th>
                <th><?php esc_html_e('Radius (mi)', 'stormchases'); ?></th>
                <th style="width:80px;"><?php esc_html_e('Action', 'stormchases'); ?></th>
            </tr>
        </thead>
        <tbody id="sc-privacy-list">
            <?php if (empty($privacy_zones)) : ?>
                <tr id="sc-privacy-empty">
                    <td colspan="5"><?php esc_html_e('No privacy zones defined yet.', 'stormchases'); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ($privacy_zones as $i => $zone) : ?>
                    <tr data-index="<?php echo esc_attr($i); ?>">
                        <td><?php echo esc_html($zone['label'] ?? ''); ?></td>
                        <td><?php echo esc_html($zone['lat']); ?></td>
                        <td><?php echo esc_html($zone['lon']); ?></td>
                        <td><?php echo esc_html($zone['radius']); ?></td>
                        <td>
                            <button type="button" class="button button-small sc-delete-privacy-zone" data-index="<?php echo esc_attr($i); ?>">
                                <?php esc_html_e('Delete', 'stormchases'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h3><?php esc_html_e('Add Zone', 'stormchases'); ?></h3>
    <table class="form-table" style="max-width:500px;">
        <tr>
            <th scope="row"><label for="sc-pz-label"><?php esc_html_e('Label', 'stormchases'); ?></label></th>
            <td><input type="text" id="sc-pz-label" class="regular-text" placeholder="<?php esc_attr_e('e.g. Home', 'stormchases'); ?>"></td>
        </tr>
        <tr>
            <th scope="row"><label for="sc-pz-lat"><?php esc_html_e('Latitude', 'stormchases'); ?></label></th>
            <td><input type="number" id="sc-pz-lat" step="0.000001" min="-90" max="90" class="small-text" placeholder="35.210000"></td>
        </tr>
        <tr>
            <th scope="row"><label for="sc-pz-lon"><?php esc_html_e('Longitude', 'stormchases'); ?></label></th>
            <td><input type="number" id="sc-pz-lon" step="0.000001" min="-180" max="180" class="small-text" placeholder="-97.490000"></td>
        </tr>
        <tr>
            <th scope="row"><label for="sc-pz-radius"><?php esc_html_e('Radius (miles)', 'stormchases'); ?></label></th>
            <td>
                <input type="number" id="sc-pz-radius" value="5" min="0.5" max="50" step="0.5" class="small-text">
                <p class="description"><?php esc_html_e('0.5 – 50 miles. 5 miles is a good default for a home zone.', 'stormchases'); ?></p>
            </td>
        </tr>
    </table>
    <p>
        <button type="button" id="sc-add-privacy-zone" class="button button-secondary">
            <?php esc_html_e('Add Zone', 'stormchases'); ?>
        </button>
    </p>
    <div id="sc-privacy-message" aria-live="polite"></div>
</div>
