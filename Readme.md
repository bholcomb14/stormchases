# StormChases WordPress Plugin

![Plugin Version](https://img.shields.io/badge/version-1.7.0-blue.svg) ![License](https://img.shields.io/badge/license-GPLv2-blue.svg)

A WordPress plugin for storm chasers to create detailed chase logs using a custom post type (`storm_chase`). Log comprehensive chase details, including tornado sightings, spotter network reports, and weather conditions, with optional Google Maps integration for KML files or images. The plugin offers a customizable `[scarchive]` shortcode for listing chases and robust admin settings for tailored display.

## Features

- **Custom Post Type**: Manage storm chase logs with the `storm_chase` post type.
- **Meta Data Fields**: Capture detailed chase information:
  - Chase Date (required, YYYYMMDD format)
  - States Chased (required)
  - Chase Partners
  - Chasers Encountered
  - Miles Logged (0–9999)
  - Largest Hail (0–9.99 inches)
  - Highest Wind (0–999 mph)
  - Chase Milestones (free-text)
  - Chase Map (JPEG, PNG, GIF, or KML)
  - Tornadoes Witnessed (with name, coordinates, EF rating, times, media, and photogenic flag)
  - Spotter Network Reports (via CSV upload with fields like report ID, timestamp, coordinates, narrative, and weather phenomena)
- **Shortcode Support**: Use `[scarchive]` to list storm chases, filterable by year or post count.
- **Google Maps Integration**: Display chase routes and tornado/report locations using KML files or images, configurable with a Google Maps API key.
- **Spotter Network Reports**: Upload CSV files to populate reports, with validation and overwrite options.
- **Dynamic Tornado Entries**: Add/remove tornadoes in the admin interface with media uploads and coordinates.
- **Customizable Settings**: Enable/disable meta fields, set file size limits (1MB–100MB), and configure supported file types (e.g., image/jpeg, application/vnd.google-earth.kml+xml).
- **Responsive Design**: Styled for compatibility with modern themes, optimized for mobile and desktop.
- **REST API Support**: Full Gutenberg compatibility for meta data editing and file uploads.
- **Transient Caching**: Optimized `[scarchive]` performance with automatic cache clearing on post updates.
- **Error Handling & Logging**: Robust debugging with server-side logging for troubleshooting.

## Installation

1. **Download the Plugin**:
   - Clone the repository: `git clone https://github.com/bholcomb14/stormchases.git`
   - Or download the ZIP file from the [GitHub repository](https://github.com/bholcomb14/stormchases).

2. **Install the Plugin**:
   - Upload the `stormchases` folder to `/wp-content/plugins/` on your WordPress site.
   - Alternatively, go to Plugins > Add New > Upload Plugin in the WordPress admin and select the ZIP file.

3. **Activate the Plugin**:
   - Navigate to Plugins in the WordPress admin dashboard and activate **StormChases**.

4. **Configure Settings** (optional):
   - Go to Storm Chases > Settings to enable/disable features, set a Google Maps API key, and configure file upload settings.

## Usage

### Creating a Storm Chase
1. Navigate to **Storm Chases > Add New** (`/wp-admin/post-new.php?post_type=storm_chase`).
2. Enter chase details:
   - **Title**: Descriptive chase title.
   - **Chase Date**: Required, YYYYMMDD (e.g., `20250608` for June 8, 2025).
   - **States Chased**: Required, e.g., `TX, OK`.
   - **Chase Partners**: Optional, defaults to `Solo`.
   - **Chasers Encountered**: Optional, defaults to `None`.
   - **Miles Logged**: Optional, numeric (0–9999).
   - **Largest Hail**: Optional, decimal (0–9.99 inches).
   - **Highest Wind**: Optional, numeric (0–999 mph).
   - **Chase Milestones**: Optional, free-text notes.
   - **Tornadoes**: Add multiple entries with name, coordinates, EF rating, start/end times, media URL, photo, and photogenic flag.
   - **Chase Map**: Upload JPEG, PNG, GIF, or KML.
   - **Spotter Reports**: View count of uploaded reports (CSV upload via settings).
3. Add content in the editor (e.g., chase narrative) and set a featured image.
4. Publish the post. The chase will be accessible at `/chases/YYYYMMDD/` (e.g., `/chases/20250608/`).

### Displaying Storm Chases
- **Single Chase**: Displayed using `single-storm_chase.php` (or theme’s `single.php`), with meta data, tornadoes, and spotter reports in styled modals (see `assets/css/stormchase.css`).
- **Archive Shortcode**: Use `[scarchive]` in pages, posts, or widgets to list chases:
  - `[scarchive show=14]`: Show latest 14 chases.
  - `[scarchive year=2025]`: Show chases from 2025.
  - `[scarchive show=10 year=2023]`: Show 10 chases from 2023.

### Settings
- Access **Storm Chases > Settings** (`/wp-admin/edit.php?post_type=storm_chase&page=storm-chases-settings`) to:
  - Toggle visibility of meta fields (e.g., Tornadoes, Spotter Reports).
  - Set Google Maps API key for map rendering.
  - Configure maximum file size (1MB–100MB) and supported MIME types.
  - Upload Spotter Network reports via CSV with an option to overwrite existing reports.

## Available Shortcodes

- **Basic Usage**: `[scarchive]` – Lists all published storm chases, ordered by chase date (descending).
- **Limit Posts**: `[scarchive show=X]` – Displays the latest `X` posts (e.g., `[scarchive show=14]`).
- **Filter by Year**: `[scarchive year=YYYY]` – Shows chases from a specific year (e.g., `[scarchive year=2025]`).
- **Combined**: `[scarchive show=10 year=2023]` – Shows up to 10 chases from 2023.
- **Output**: A styled `<ul>` with chase links, including date, excerpt, and miles logged.

**Example Output**:
- June 8, 2025 - Intense HP Supercell in Texas Panhandle (573 Miles)
- May 5, 2023 - [Excerpt] (400 Miles)

## Requirements

- **WordPress**: 5.0 or higher (tested up to 6.5).
- **PHP**: 7.4 or higher.
- **Browser**: Modern browsers for admin and front-end interfaces.
- **Google Maps API Key**: Required for KML map rendering (optional).

## Built With

- **PHP**: Core plugin logic, custom post type, and REST API endpoints.
- **JavaScript**: jQuery and jQuery UI for datepicker, media uploads, and modal/map functionality (`assets/js/admin.js`, `assets/js/frontend.js`).
- **HTML**: Admin forms and front-end templates for chase details and modals.
- **CSS**: Custom styles for admin (`assets/css/admin.css`) and front-end (`assets/css/stormchase.css`).

## Known Issues

- All known issues from version 1.6.0, including map rendering and file upload bugs, have been resolved in 1.7.0.
- Report new issues on the [GitHub Issues page](https://github.com/bholcomb14/stormchases/issues).

## Future Updates

- [ ] Enhance map customization (e.g., custom markers, zoom levels).
- [ ] Add support for additional weather data visualizations.

## Development

To contribute or customize:
1. Clone the repository: `git clone https://github.com/bholcomb14/stormchases.git`.
2. Install WordPress and activate the plugin in a staging environment.
3. Enable debugging in `wp-config.php`:

```
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('STORM_CHASES_VERBOSE_LOG', true);
```

4. Test changes and submit pull requests via GitHub.

## Changelog

### 1.7.0
- **Added**: Spotter Network Reports CSV upload with validation and overwrite option.
- **Added**: Dynamic tornado entry management in admin with media uploads and coordinates.
- **Improved**: Enhanced Google Maps integration for tornado and report locations in modals.
- **Improved**: Robust error handling and logging for AJAX and REST API operations.
- **Fixed**: Minor bugs in modal initialization and file upload validation.
- **Updated**: Admin and front-end styles for better usability and responsiveness.
