# StormChases WordPress Plugin

![Plugin Version](https://img.shields.io/badge/version-1.9.2-blue.svg) ![License](https://img.shields.io/badge/license-GPLv2-blue.svg) ![WordPress](https://img.shields.io/badge/WordPress-6.1%2B-blue.svg) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)

A WordPress plugin designed for storm chasers by a storm chaser to create and manage detailed chase logs using a custom post type (`storm_chase`). Log chase details — tornadoes observed, Spotter Network reports, and weather conditions — with optional OpenStreetMap or Google Maps integration. Provides `[scarchive]`, `[scstats]`, `[sc_tornado_map]`, and `[sc_reports]` shortcodes for public-facing pages, plus Gutenberg block equivalents of the map/list/stats shortcodes (Tornado Map, Spotter Reports, Tornado List, Chase Stats) for use in the block editor. The minimum WordPress version was raised from 5.0 to 6.1 for the blocks' server-side rendering support; shortcodes remain the primary path and are unaffected.

## Features

- **Custom Post Type**: Manage storm chase logs with the `storm_chase` post type, accessible at `/chases/YYYYMMDD/` (e.g., `/chases/20250608/`).
- **Meta Data Fields**: Capture detailed chase information:
  - **Chase Date** (auto-derived from publish date, YYYYMMDD)
  - **States Chased** (required, comma-separated state/province codes, e.g., `TX,OK,AB`)
  - **Chase Partners** (optional, defaults to `Solo`)
  - **Chasers Encountered** (optional, defaults to `None`)
  - **Miles Logged** (optional, 0–9999)
  - **Largest Hail** (optional, 0–9.99 inches)
  - **Highest Wind** (optional, 0–999 mph)
  - **Chase Milestones** (optional, bullet-point list — e.g., "First July tornado", "First Wisconsin tornado")
  - **Best Chase of the Season** flag (checkbox — one per year, shown when the feature is enabled)
  - **Chase Map** (optional): a JPEG/PNG/GIF image, or a GPS track (`.kml`, `.gpx`, `.nmea`). Multiple GPS track files for the same day (e.g., a log split by a device restart) are merged into a single chronological track, with gap markers where GPS signal was lost between files.
  - **Tornadoes Witnessed** (array, with name, coordinates, EF rating, start/end times, end coordinates, photo ID, and photogenic flag)
  - **Spotter Network Reports** (array, imported via CSV)
- **Shortcodes**:
  - `[scarchive]` — list storm chases, filterable by year or post count
  - `[scstats]` — aggregated chase statistics for all time or a specific year
  - `[sc_tornado_map]` — interactive map of all tornadoes with clickable modals, marker icons colored by
    EF rating (purple EF-5 down to aqua EF-0, gray for unrated), with a legend and a fullscreen toggle
  - `[sc_reports]` — interactive map + list of all Spotter Network reports with clickable modals, marker
    icons colored/shaped by report type (Tornado, Hail, Wind, Funnel Cloud, Wall Cloud, Damage), with a
    legend and a fullscreen toggle
- **Blocks**: Gutenberg block equivalents of four of the shortcodes above, with settings exposed as
  block-sidebar controls instead of shortcode attributes:
  - **Tornado Map** — equivalent of `[sc_tornado_map]`, adds an EF-rating filter (show only a chosen
    subset of severities) and a map-height slider not available via the shortcode
  - **Spotter Reports** — equivalent of `[sc_reports]`, adds a report-type filter and a map-height slider
  - **Tornado List** — equivalent of `[scstats tornadoes="true"]`
  - **Chase Stats** — equivalent of `[scstats]`
- **Map Providers**: Choose between **OpenStreetMap** (Leaflet.js, no API key required) or **Google Maps** (API key required) in plugin settings.
- **GPS Track Playback**: Chase maps built from a GPS track include a start → end scrubber that redraws the route up to a point in time and shows the current percentage through the track. The map is displayed after the chase recap summary, just above the full write-up.
- **GPS Track Privacy Zones**: Configure named locations with a radius in plugin settings; points falling inside a zone are stripped from the start and end of any uploaded GPS track (e.g., your home or staging area) while points in the middle of the track are always kept.
- **Best Chase of the Season**: Mark one chase per year as the best/favorite. Displays as *Best Chase Day: May 24* with a link in the `[scstats]` output.
- **Windshield Replacements**: Track hail-damage windshield replacements by month/year directly in the settings page. Displays in `[scstats]` as *Windshields Replaced: N*.
- **Spotter Network Import**: Improved CSV upload UI with drag-and-drop, dry-run preview mode, and clear progress feedback. Reports are automatically matched to chase logs by date.
- **Dynamic Tornado Entries**: Add, remove, and reorder (drag or up/down arrows) tornado entries in the admin interface, with fields for coordinates, EF rating, media uploads, and photogenic flag. Entries collapse to a one-line summary when not being edited, expanding on click. Display order on the public chase page matches the order set in the editor (top entry first).
- **Customizable Settings**: Enable/disable individual features, choose map provider, set file size limits, and configure supported MIME types.
- **Responsive Design**: Styled for modern themes, optimized for mobile and desktop.
- **REST API Support**: Full Gutenberg/block editor compatibility for meta data and file uploads.
- **Transient Caching**: `[scarchive]` and `[scstats]` are cached with automatic invalidation on post save.
- **Error Handling & Logging**: Debug logging via `WP_DEBUG_LOG`.
- **State/Province Display**: Converts state codes to full names with a Canadian flag icon for provinces.
- **Time Zone Conversion**: Spotter Network report timestamps displayed in Central Time.

## Installation

1. **Download the Plugin**:
   - Clone: `git clone https://github.com/bholcomb14/stormchases.git`
   - Or download the ZIP from the [GitHub repository](https://github.com/bholcomb14/stormchases).

2. **Install**:
   - Upload the `stormchases` folder to `/wp-content/plugins/`.
   - Or go to **Plugins > Add New > Upload Plugin** and select the ZIP.

3. **Activate**: Navigate to **Plugins** in the WordPress admin dashboard and activate **StormChases**.

4. **Configure** (optional):
   - Go to **Storm Chases > Settings** to enable features, choose a map provider, enter a Google Maps API key (if using Google Maps), and configure file upload limits.

## Usage

### Creating a Storm Chase
1. Navigate to **Storm Chases > Add New**.
2. Set the **Publish Date** to the chase date — the post slug and internal chase date field are set automatically.
3. Fill in the meta box fields (states, partners, miles, hail, wind, etc.).
4. Optionally mark **Best Chase of the Season** if the feature is enabled.
5. Add tornado entries and/or upload a chase map as needed.
6. Publish. The chase is accessible at `/chases/YYYYMMDD/`.

### Shortcodes

#### `[scarchive]`
Lists published storm chases ordered by date descending.

| Attribute | Description | Example |
|---|---|---|
| `show` | Max number of chases to display | `show=20` |
| `year` | Filter to a specific year | `year=2025` |
| `chasers` | Filter by chasers encountered | `chasers=Smith` |
| `states` | Filter by state code | `states=TX` |
| `tornadoes` | Show only tornado days | `tornadoes=yes` |

**Examples:**
```
[scarchive show=14]
[scarchive year=2025]
[scarchive show=10 year=2023 tornadoes=yes]
```

#### `[scstats]`
Displays aggregated statistics. Optionally filter to a specific year.

| Attribute | Description | Example |
|---|---|---|
| `year` | Filter stats to this year | `year=2025` |
| `tornadoes` | Show tornado list instead of stats | `tornadoes=true` |

**Examples:**
```
[scstats]
[scstats year=2025]
[scstats tornadoes=true year=2024]
```

Outputs (when enabled): Chase Days, Tornado Days, Tornadoes, Photogenic Tornadoes, Miles Driven, States, Spotter Network Reports, Largest Hail, Highest Wind, Tornado Day Percentage, Tornadoes per Mile, **Best Chase Day**, **Windshields Replaced**.

#### `[sc_tornado_map]`
Interactive map with a marker for every tornado logged, colored by EF rating, each opening a detail modal. Includes a fullscreen toggle and an EF-rating legend.

| Attribute | Description | Example |
|---|---|---|
| `year` | Filter to a specific year | `year=2025` |
| `ratings` | Comma-separated EF ratings to show (default: all) | `ratings=EF-4,EF-5` |

```
[sc_tornado_map]
[sc_tornado_map year=2011]
[sc_tornado_map ratings=EF-4,EF-5]
```

#### `[sc_reports]`
Interactive map + sorted list of all Spotter Network reports, colored/shaped by report type, each opening a detail modal showing time, location, narrative, and reported weather. Includes a fullscreen toggle and a report-type legend.

| Attribute | Description | Example |
|---|---|---|
| `year` | Filter to a specific year | `year=2024` |
| `types` | Comma-separated report types to show — `tornado`, `hail`, `wind`, `funnel`, `wallcloud`, `damage`, `generic` (default: all) | `types=tornado,hail` |

```
[sc_reports]
[sc_reports year=2024]
[sc_reports types=tornado,hail]
```

### Blocks

Gutenberg block equivalents of the map/list/stats shortcodes above — insert via the block editor and search
"Tornado Map", "Spotter Reports", "Tornado List", or "Chase Stats" (grouped under a **Storm Chases** category
in the inserter). Settings that are shortcode attributes above become sidebar controls instead: a Year field,
editable/hideable heading text on all four, and on the two map blocks, a height slider and a checkbox filter
(EF rating or report type). Requires WordPress 6.1+.

### Importing Spotter Network Reports
1. Go to **Storm Chases > Settings → Import Spotter Network Reports**.
2. Use the drag-and-drop file zone to choose your CSV export from Spotter Network.
3. Optionally enable **Overwrite existing reports** to replace stored data for matched dates.
4. Optionally enable **Preview only (dry run)** to see what would be imported without saving anything.
5. Click **Upload Reports**. A results summary lists matched dates, skipped rows, and any unmatched dates.

Required CSV columns: `report`, `report_type`, `stamp`, `lat`, `lon`, `narrative`, `tornado`, `hailsize`, `windspeed`, `city1`, `cwa`.

### Tracking Windshield Replacements
1. Go to **Storm Chases > Settings → Windshield Replacements**.
2. Click **Add Entry** with the month and year of each replacement.
3. Multiple entries in the same month are allowed.
4. Delete entries with the **Delete** button.
5. Enable **Enable Windshields Replaced** in the main settings to show the count in `[scstats]`.

### Marking Best Chase of the Season
1. Enable **Enable Best Chase of Season** in settings.
2. Open any storm chase in the editor.
3. Check **Best Chase of the Season** in the meta box.
4. Saving the post updates the best-chase record for that season. Only one chase per year can be marked — checking a new one automatically replaces the previous selection.
5. The best chase day appears in `[scstats year=YYYY]` output as a linked date.

## Settings Reference

| Setting | Description |
|---|---|
| Show Chase Partners | Display chase partners on front-end |
| Show Chasers Encountered | Display encountered chasers |
| Enable Miles Logged | Display miles in chase details and stats |
| Enable States Chased | Display states in chase details and stats |
| Enable Tornadoes | Display tornado section |
| Enable Wind | Display wind data |
| Enable Hail | Display hail data |
| Enable Milestones | Display milestones field |
| **Enable Best Chase of Season** | Show best-chase checkbox in editor and stat in `[scstats]` |
| **Enable Windshields Replaced** | Show windshield count in `[scstats]` |
| Enable Maps | Enable map rendering on individual chase pages |
| Enable Spotter Network Reports | Display report count and modals on chase pages |
| **Map Provider** | `OpenStreetMap` (default, no key needed) or `Google Maps` |
| Google Maps API Key | Required only when Google Maps is the selected provider |
| Maximum File Size | Chase map upload limit (1MB–100MB); GPS track files bypass this limit and are governed by PHP's `upload_max_filesize` |
| Supported File Types | MIME types for chase map image uploads (GPS tracks are always accepted regardless of this list) |
| **GPS Track Privacy Zones** | Named locations with a radius (miles); track points within a zone are stripped from the start/end of uploaded GPS tracks |

## Requirements

- **WordPress**: 6.1 or higher (tested up to 6.6) — raised from 5.0 for the Gutenberg blocks' server-side rendering support.
- **PHP**: 7.4 or higher.
- **Browser**: Modern browsers (Chrome, Firefox, Safari, Edge).
- **Google Maps API Key**: Required only when Google Maps is the selected map provider.

## Built With

- **PHP**: Plugin logic, custom post type, REST API, shortcodes, data sanitization.
- **JavaScript**: jQuery for admin UI; Leaflet.js or Google Maps JS API for interactive maps.
- **CSS**: Custom admin and front-end styles.
- **WordPress APIs**: Custom post type, REST API, shortcodes, transients.
- **Leaflet.js** (OpenStreetMap mode): v1.9.4, loaded from CDN.

## Changelog

### 1.9.2
- **Added**: Play/Pause and speed (+/-, 0.25x-8x) controls above the GPS track scrubber, animating the track (and radar, if shown) automatically instead of requiring manual dragging. Dragging the scrubber by hand stops playback. Pressing Play first buffers a short run of upcoming radar frames (shown as "Buffering radar… N/M" next to the controls) so a fast connection can play back smoothly instead of visibly lagging behind; playback also keeps one frame prefetching in the background while it plays, and will pause and re-buffer automatically if a slow connection falls behind rather than showing a stale frame. None of this downloads anything until Play is actually pressed.
- **Changed**: The "Show radar" toggle is now checked by default on tracks that support it. Radar frames are still only ever fetched one at a time as the scrubber/playback actually reaches them (never preloaded), so this only costs a single frame's download on page load, not the whole track's worth.

### 1.9.1
- **Fixed**: The historical radar overlay could show a storm 90-100 miles north of its true position, despite the underlying track/timestamp/imagery data all being correct. At a chase page's typical zoom level, Leaflet/Google Maps has to CSS-scale the full continental NEXRAD composite image (12,200x5,400px) up to render sizes exceeding 11,000x6,000px on screen — past the GPU's maximum texture size on some browser/driver combinations, which silently mis-composited the overflow instead of erroring, so the storm appeared shifted even though the browser's own DOM position and pixel data were both correct. The radar image is now cropped client-side (via canvas) down to just the chase track's bounding box, plus padding, *before* it's ever handed to the map as an overlay, so the on-screen render size stays small regardless of zoom and the GPU never approaches that limit.
- **Fixed**: Dragging the GPS track scrubber fires a burst of radar-frame image requests in quick succession, and nothing prevented a slow-to-load *earlier* frame from finishing after a faster *later* one and overwriting it — the on-screen overlay would end up showing an older frame's storm position while the "Radar: HH:MM UTC" label had already moved on to the correct, current time. Each radar frame request now carries a sequence number; only the most recently *issued* request is allowed to actually paint the overlay, regardless of which order the network responses arrive in.
- **Changed**: Each radar frame is now cached in memory (as a small cropped image) after its first load, so scrubbing back over a time you've already viewed redraws it instantly with no re-download. Fetching a new frame is also debounced by ~150ms, so dragging quickly across many frames only fetches the one you actually stop on instead of a discarded request for every frame crossed along the way.
- **Fixed**: On chases from before roughly late 2014, the radar overlay could show a storm shifted well east of its true position (e.g., the May 20, 2013 Moore, OK EF-5 showed clear over the actual tornado track with the storm displaced to the east) — IEM's `n0q` composite grid isn't fixed across history; it grew from 12,000x5,200px to 12,200x5,400px sometime between January and December 2014, and the overlay was computing each frame's geographic position from today's grid size regardless of the frame's actual date. Each radar frame's real bounds are now read from its own accompanying `.wld` world file instead of assumed, so this can't drift again even if IEM changes the grid a third time.

### 1.9.0
- **Fixed**: A leading-zero bug in NMEA timestamp parsing (`nmea_timestamp()`) could cause `DateTime::createFromFormat()` to silently fail for any GPS fix recorded on a `:00`–`:09` second (~1 in 6 points). Those points lost their timestamp and were sorted to the end of the track out of chronological order, causing multi-file GPS track merges to visually "retrace" earlier parts of the route instead of drawing one clean, continuous line.
- **Fixed**: "Remove Chase Map" now calls a REST endpoint instead of submitting the post-edit form, so it no longer triggers the browser's "leave site?" unsaved-changes prompt and reliably removes the map in place.
- **Fixed**: A critical error on the Settings page ("Supported File Types") caused by reading a saved array-type option through `wp_load_alloptions()`, which returns raw serialized values instead of the unserialized array `get_option()` provides.
- **Fixed**: The Google Maps API key validation request fired twice on every settings save due to a duplicated hook registration.
- **Removed**: Dead front-end enqueue code that could never run (an `is_admin()` branch inside a `wp_enqueue_scripts` callback, which never fires in `wp-admin`) — the real admin script/style enqueue already lives in `Storm_Chases::enqueue_admin_scripts()`.
- **Removed**: `templates/storm_chase_template.php`, an orphaned single-post template left over from before the plugin switched to rendering chase details via the `the_content` filter (June 2025). Nothing in the plugin had loaded it since; single chase pages have always been rendered by `StormChaseTemplate::display_storm_chase_data()`.
- **Changed**: The GPS track scrubber now shows only a percentage-through-track indicator (e.g., `37%`); latitude/longitude are no longer displayed.
- **Changed**: On individual chase pages, the chase map now renders after the recap summary and the Severe Risk/Reports links, directly above the full write-up, instead of at the top of the page.
- **Added**: Tornado entries in the chase editor can now be reordered — drag by the handle or use the up/down arrows — with the top entry always shown first on the public chase page. Previously-saved entries load collapsed to a one-line summary (name + EF rating); click an entry to expand it for editing, and click **Done** (or the entry again) to collapse it back. New entries added via **Add Tornado** start expanded for data entry. Also fixes a latent bug where removing a tornado from the middle of the list, then adding a new one, could silently overwrite an existing entry due to reused/duplicate array indices.
- **Changed**: Chase Milestones is now a bullet-point list instead of a single free-text field — press Enter or click **+ Add Milestone** to add another bullet. Existing milestone text (previously a single string, and previously silently flattened to one line on every save since newlines were being stripped) is migrated automatically into a one-item list the next time the chase loads; split it into more bullets as needed.
- **Fixed**: The Publish date, the URL slug, and the Chase Date field could drift out of sync in edge cases because they were derived by two separate, slightly different code paths. Both now go through one shared, validated derivation function, and the block editor's Chase Date field updates live as you change the Publish date (no more waiting for a save to see it reflected). If WordPress ever can't set the slug to match (e.g., another chase already uses that date), you'll now see an admin notice explaining why — previously that error was captured but never actually shown anywhere.
- **Fixed**: A GPS receiver losing satellite lock can report one wildly wrong fix, or get stuck repeating the same stale fix for the rest of a recording — both were previously drawn straight into the track as a long spurious line. Any point that implies a jump of more than 10 miles from the last accepted point in its segment is now discarded before the track is stored, and the upload result reports how many points were dropped this way.
- **Hardened**: Chase pages now re-simplify (cap) an unexpectedly large stored GPS track before embedding it in the page, in case any chase was saved by a much older version of the plugin before `simplify_track()`'s point cap existed.
- **Changed**: On a fresh install, Miles Logged, States Chased, Tornadoes, Wind, Hail, Milestones, and Spotter Network Reports are now enabled by default (previously all feature toggles defaulted to off). Map Provider continues to default to OpenStreetMap. Also fixed the Settings page checkboxes themselves, which were reading raw option data with a hardcoded "unchecked" fallback and so would have displayed as off even once the real default was on.
- **Removed**: The tornado "Media URL" video-embed field, which never actually worked — the value was captured on save but silently dropped before storage by the sanitizer's field whitelist, so the video player it was meant to feed could never fire.
- **Added**: A **📍 Pick Location on Map** button next to a tornado's Latitude/Longitude and End Latitude/End Longitude fields, opening a click-to-place map (OpenStreetMap via Leaflet) that fills in the coordinates (rounded to 4 decimal places) instead of requiring them to be typed by hand.
- **Added**: A **Show radar** toggle on the GPS track scrubber overlays historical NEXRAD composite reflectivity (via the Iowa Environmental Mesonet archive) synced to wherever the scrubber is positioned, updating automatically as you drag it. Fetched directly in the browser at the archive's native 5-minute cadence — no server-side processing or storage involved. To keep precise time-of-day from being exposed on the public chase page, only a sparse checkpoint (roughly every 10 minutes, rounded to the nearest 5) is stored per track segment rather than a timestamp on every point; the gaps are estimated in the browser from point position, just to pick a radar frame, and that estimate is never saved. Only available for tracks that include at least one timestamp checkpoint; **GPS tracks must be re-uploaded to gain this** (timestamps weren't previously kept in storage at all — only latitude/longitude survived past the merge step). New uploads get it automatically.
- **Fixed**: `[sc_tornado_map]` always rendered "No location data available for this map" — its `WP_Query` filtered on a `chasetornado` post-meta key that doesn't actually exist as a standalone row (tornado data lives only inside the serialized `chase_data` blob), so the query silently matched zero posts. It now queries all published chases and filters in PHP.
- **Added**: `[sc_tornado_map]` markers are now colored by EF rating (purple EF-5 down to aqua EF-0, gray for unrated) instead of Leaflet's default blue pin, using a set of hand-generated icons; higher-rated tornadoes always draw on top of lower-rated ones when markers overlap at low zoom. A matching legend renders under the map. Works on both the OpenStreetMap and Google Maps providers.
- **Added**: A fullscreen toggle on both `[sc_tornado_map]` and `[sc_reports]`.
- **Fixed**: Map marker popups (on `[sc_tornado_map]`/`[sc_reports]`) couldn't be closed at all when opened from a map click, since the close-button/backdrop handlers were only ever bound on pages that also had certain unrelated link elements. Popups now always open positioned next to the clicked marker instead of centered on screen, and close on any click inside them (the X, the backdrop, or the content itself).
- **Changed**: Marker hover labels (tornado/report name) are bigger and bolder.
- **Added**: `[sc_reports]` markers are now colored/shaped by report type (Tornado, Hail, Wind, Funnel Cloud, Wall Cloud, Damage, or a generic fallback) instead of a plain default pin, with a matching legend. The report-type label itself (previously three slightly-drifted copies of the same derivation logic scattered across the codebase) is now computed in one shared place.
- **Added**: Gutenberg block equivalents of `[sc_tornado_map]`, `[sc_reports]`, and `[scstats]` (both its stats and tornado-list modes) — **Tornado Map**, **Spotter Reports**, **Tornado List**, and **Chase Stats** blocks, each with block-sidebar controls (year, heading text, show/hide heading) instead of shortcode attributes; the two map blocks additionally get a map-height slider, and a checkbox filter to show only chosen EF ratings or report types. Purely additive — shortcodes are unchanged and remain fully supported. **Raises the minimum WordPress version to 6.1** (from 5.0) for the blocks' server-side rendering support.

### 1.8.0
- **Added**: `[sc_tornado_map]` shortcode — interactive OpenStreetMap/Google Maps map of all tornadoes with modal popups. One modal open at a time; clicking another closes the current one.
- **Added**: `[sc_reports]` shortcode — interactive map + full list of all Spotter Network reports with modal popups.
- **Added**: **Map Provider** setting — choose between OpenStreetMap (Leaflet.js, no API key) or Google Maps.
- **Added**: **Best Chase of the Season** feature — checkbox in the chase editor, displayed as *Best Chase Day: [date]* in `[scstats year=YYYY]`.
- **Added**: **Windshield Replacements** management in plugin settings — track hail-damage windshield replacements by month/year; count displayed in `[scstats]`.
- **Improved**: Spotter Network CSV upload — drag-and-drop file zone, file size/name preview, **dry-run (preview) mode**, inline loading spinner, cleaner skip-reason reporting.
- **Improved**: Modals on all pages close when another is opened (one-at-a-time behavior).
- **Improved**: `stormChasesFrontend.mapProvider` passed to frontend JS for correct map initialization.
- **Fixed**: Admin `sanitize_callback` routing now handles `map_provider` correctly without falling back to `intval`.

### 1.7.0
- Added `[scstats]` shortcode for aggregated chase statistics.
- Added Spotter Network Reports CSV upload with validation, date matching, and overwrite option.
- Added dynamic tornado entry management with media uploads.
- Added state/province code conversion with Canadian flag.
- Added UTC → Central Time conversion for report timestamps.
- Improved Google Maps integration.
- Improved REST API endpoints for chasemap and spotter report uploads.

## Author

**Ben Holcomb** — [benholcomb.com](https://www.benholcomb.com) | [GitHub](https://github.com/bholcomb14)

## License

[GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html)

## Contributing

Issues and pull requests welcome at the [GitHub repository](https://github.com/bholcomb14/stormchases). Please follow WordPress coding standards.


