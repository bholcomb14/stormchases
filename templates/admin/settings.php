<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'stormchases'));
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
                'busts_enable'               => __('Enable Busts / Blue Sky Busts', 'stormchases'),
                'kiss_of_death_enable'       => __('Enable Kiss of Death Days', 'stormchases'),
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

        <h2><?php esc_html_e('Chase Stats Block Defaults', 'stormchases'); ?></h2>
        <p class="description">
            <?php esc_html_e('What a brand new Chase Stats block starts with when added to a page. A block already placed somewhere keeps whatever it was set to — this only applies going forward.', 'stormchases'); ?>
        </p>
        <table class="form-table" role="presentation">
            <?php
            $stats_default_fields = [
                'stats_default_show_heading'        => __('Show heading', 'stormchases'),
                'stats_default_show_overall'         => __('Show overall stats (chase days, miles, states…)', 'stormchases'),
                'stats_default_show_convective'      => __('Show Convective section', 'stormchases'),
                'stats_default_show_hurricane'       => __('Show Hurricane section', 'stormchases'),
                'stats_default_show_winter'          => __('Show Winter section', 'stormchases'),
                'stats_default_show_longest_chase'   => __('Show longest chase (in overall stats)', 'stormchases'),
                'stats_default_show_ef_breakdown'    => __('Show EF rating breakdown (in Convective)', 'stormchases'),
                'stats_default_show_storm_modes'     => __('Show storm mode breakdown (in Convective)', 'stormchases'),
                'stats_default_show_top_days'        => __('Show "Biggest Chase Days" sortable table', 'stormchases'),
                'stats_default_show_first_last'      => __('Show first/last tornado & landfall of the season', 'stormchases'),
                'stats_default_show_new_states'      => __('Show "new states this season" callout', 'stormchases'),
                'stats_default_show_streak'          => __('Show consecutive-year chase streak', 'stormchases'),
                'stats_default_show_top_people'      => __('Show Top Chase Partners / Most Encountered Chasers (all-time only)', 'stormchases'),
            ];
            ?>
            <?php foreach ($stats_default_fields as $field => $label) : ?>
                <tr>
                    <th scope="row">
                        <label for="<?php echo esc_attr($field); ?>"><?php echo esc_html($label); ?></label>
                    </th>
                    <td>
                        <input
                            type="checkbox"
                            class="storm-chases-toggle"
                            id="<?php echo esc_attr($field); ?>"
                            name="<?php echo esc_attr($field); ?>"
                            value="1"
                            <?php checked(1, (int) get_option($field)); ?>
                            aria-describedby="<?php echo esc_attr($field); ?>-description"
                        />
                        <span id="<?php echo esc_attr($field); ?>-description" class="screen-reader-text">
                            <?php echo esc_html($label); ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th scope="row"><label for="stats_default_top_days_count"><?php esc_html_e('Biggest Chase Days: number of rows', 'stormchases'); ?></label></th>
                <td><input type="number" id="stats_default_top_days_count" name="stats_default_top_days_count" value="<?php echo esc_attr($options['stats_default_top_days_count'] ?? 10); ?>" min="1" max="50" class="small-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="stats_default_top_people_count"><?php esc_html_e('Top Chase Partners / Chasers: number of people', 'stormchases'); ?></label></th>
                <td><input type="number" id="stats_default_top_people_count" name="stats_default_top_people_count" value="<?php echo esc_attr($options['stats_default_top_people_count'] ?? 10); ?>" min="1" max="50" class="small-text"></td>
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
                <input type="number" id="sc-ws-year" value="<?php echo esc_attr(current_time('Y')); ?>" min="1990" max="<?php echo esc_attr((int) current_time('Y') + 1); ?>" class="small-text">
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

    <h2><?php esc_html_e('Chase People Profiles', 'stormchases'); ?></h2>
    <p class="description">
        <?php esc_html_e('Names typed into Chase Partners or Chasers Encountered are automatically kept as real, reusable people (each with an optional website and location, and a popup showing every other chase they\'re logged on — labeled with which role they were in each time) — no separate step needed for a chase saved from here on. Manage the list of people directly under the "Chase People" menu item, or backfill any chase logs saved before this existed with the tool below.', 'stormchases'); ?>
    </p>
    <?php
    $sn_person_result = get_transient('storm_chases_person_migration_result_' . get_current_user_id());
    if (isset($_GET['sc_person_migrated']) && is_array($sn_person_result)) {
        delete_transient('storm_chases_person_migration_result_' . get_current_user_id());
        printf(
            '<div class="notice %1$s inline"><p>%2$s</p></div>',
            $sn_person_result['success'] ? 'notice-success' : 'notice-error',
            esc_html($sn_person_result['message'])
        );
    }

    $person_report = get_transient('storm_chases_person_migration_report_' . get_current_user_id());
    if (isset($_GET['sc_person_scan']) && is_array($person_report)) {
        $total_names = count($person_report);
        if ($total_names === 0) {
            echo '<p>' . esc_html__('No names found in the legacy text fields — nothing to migrate.', 'stormchases') . '</p>';
        } else {
            ?>
            <table class="widefat striped" style="max-width:700px;">
                <thead><tr>
                    <th><?php esc_html_e('Name (as it would become a term)', 'stormchases'); ?></th>
                    <th><?php esc_html_e('Chases', 'stormchases'); ?></th>
                    <th><?php esc_html_e('As Chase Partner', 'stormchases'); ?></th>
                    <th><?php esc_html_e('As Chaser Encountered', 'stormchases'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($person_report as $name => $info) : ?>
                    <tr>
                        <td><?php echo esc_html($name); ?></td>
                        <td><?php echo esc_html($info['count']); ?></td>
                        <td><?php echo esc_html($info['as_partner']); ?></td>
                        <td><?php echo esc_html($info['as_chaser']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e('One shared list — someone who\'s both a Chase Partner on some chases and a Chaser Encountered on others is one name here, not two; their "other chases" popup shows which role they were in each time. Review the list above — a name that isn\'t really a person (e.g. a stray descriptive phrase from an old chase log) or an inconsistent spelling of someone already listed elsewhere is safe to leave; migrating just creates/reuses a term for it. Nothing here is destructive — the original text fields are never modified or cleared.', 'stormchases'); ?></p>
            <p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="storm_chases_apply_person_migration">
                    <?php wp_nonce_field('storm_chases_person_migration_apply'); ?>
                    <?php /* translators: %d: number of distinct names found to migrate */ ?>
                    <button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js(sprintf(__('Create/assign %d name(s) as Chase People terms across your chase logs?', 'stormchases'), $total_names)); ?>');">
                        <?php /* translators: %d: number of distinct names found to migrate */ ?>
                        <?php echo esc_html(sprintf(__('Migrate %d Name(s)', 'stormchases'), $total_names)); ?>
                    </button>
                </form>
            </p>
            <?php
        }
    }
    ?>
    <p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="storm_chases_scan_person_migration">
            <?php wp_nonce_field('storm_chases_person_migration'); ?>
            <button type="submit" class="button button-secondary"><?php esc_html_e('Scan Chase Logs for Names', 'stormchases'); ?></button>
        </form>
    </p>

    <h3><?php esc_html_e('Name Corrections', 'stormchases'); ?></h3>
    <p class="description">
        <?php esc_html_e('The same person sometimes appears spelled differently across different chases (e.g. a typo, or with/without a middle initial). List confirmed corrections here, one per line, as "As Typed => Correct Spelling" — applied the next time you scan, so both variants merge into one profile under the correct name. Only add a pair here once you\'re sure it\'s really the same person; two similar-looking names can just as easily be two different people.', 'stormchases'); ?>
    </p>
    <?php if (isset($_GET['sc_person_aliases_saved'])) : ?>
        <div class="notice notice-success inline"><p><?php esc_html_e('Name corrections saved.', 'stormchases'); ?></p></div>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="storm_chases_save_person_aliases">
        <?php wp_nonce_field('storm_chases_person_aliases'); ?>
        <p>
            <textarea name="sc_person_aliases" rows="4" style="max-width:500px;width:100%;font-family:monospace;" placeholder="Dick Mcgowan => Dick McGowan"><?php
                $aliases = Storm_Chases_People_Taxonomies::get_name_aliases();
                $lines = array_map(function($pair) {
                    return ($pair['alias'] ?? '') . ' => ' . ($pair['canonical'] ?? '');
                }, $aliases);
                echo esc_textarea(implode("\n", $lines));
            ?></textarea>
        </p>
        <button type="submit" class="button button-secondary"><?php esc_html_e('Save Corrections', 'stormchases'); ?></button>
    </form>

    <h2><?php esc_html_e('Legacy Meta Row Cleanup', 'stormchases'); ?></h2>
    <p class="description">
        <?php esc_html_e('Chase data lives in a single chase_data field per post. Scan for stray individual meta rows left behind by an older save path (e.g. chasewind, chasemiles as their own rows) — these are never read by anything, but can be safely deleted once confirmed to match chase_data.', 'stormchases'); ?>
    </p>
    <?php
    if (isset($_GET['sc_legacy_deleted'])) {
        $deleted = absint($_GET['sc_legacy_deleted']);
        $skipped = isset($_GET['sc_legacy_skipped']) ? absint($_GET['sc_legacy_skipped']) : 0;
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: number of rows deleted, 2: number of rows skipped */
                __('Deleted %1$d legacy meta row(s). %2$d skipped (value did not match chase_data — left in place for manual review).', 'stormchases'),
                $deleted,
                $skipped
            ))
        );
    }

    $legacy_report = get_transient('storm_chases_legacy_meta_report_' . get_current_user_id());
    if (isset($_GET['sc_legacy_scan']) && is_array($legacy_report)) {
        if (empty($legacy_report)) {
            echo '<p>' . esc_html__('No legacy individual meta rows found.', 'stormchases') . '</p>';
        } else {
            $safe_count = count(array_filter($legacy_report, fn($r) => $r['safe']));
            ?>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Post', 'stormchases'); ?></th>
                        <th><?php esc_html_e('Meta Key', 'stormchases'); ?></th>
                        <th><?php esc_html_e('Stray Value', 'stormchases'); ?></th>
                        <th><?php esc_html_e('chase_data Value', 'stormchases'); ?></th>
                        <th><?php esc_html_e('Status', 'stormchases'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($legacy_report as $row) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url(get_edit_post_link($row['post_id'])); ?>"><?php echo esc_html($row['post_title'] ?: $row['post_id']); ?></a></td>
                            <td><code><?php echo esc_html($row['meta_key']); ?></code></td>
                            <td><?php echo esc_html(is_scalar($row['stray']) ? $row['stray'] : wp_json_encode($row['stray'])); ?></td>
                            <td><?php echo esc_html(is_scalar($row['current']) ? $row['current'] : wp_json_encode($row['current'])); ?></td>
                            <td>
                                <?php if ($row['safe']) : ?>
                                    <span style="color:#2271b1;"><?php esc_html_e('Matches — safe to delete', 'stormchases'); ?></span>
                                <?php else : ?>
                                    <strong style="color:#d63638;"><?php esc_html_e('Mismatch — needs manual review', 'stormchases'); ?></strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($safe_count > 0) : ?>
                <p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="storm_chases_delete_legacy_meta">
                        <?php wp_nonce_field('storm_chases_legacy_meta'); ?>
                        <?php /* translators: %d: number of legacy meta rows safe to delete */ ?>
                        <button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js(sprintf(__('Delete %d confirmed-matching legacy meta row(s)? This cannot be undone.', 'stormchases'), $safe_count)); ?>');">
                            <?php /* translators: %d: number of legacy meta rows safe to delete */ ?>
                            <?php echo esc_html(sprintf(__('Delete %d Safe Row(s)', 'stormchases'), $safe_count)); ?>
                        </button>
                    </form>
                </p>
            <?php endif; ?>
            <?php
        }
    }
    ?>
    <p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="storm_chases_scan_legacy_meta">
            <?php wp_nonce_field('storm_chases_legacy_meta'); ?>
            <button type="submit" class="button button-secondary"><?php esc_html_e('Scan for Legacy Meta Rows', 'stormchases'); ?></button>
        </form>
    </p>
</div>
