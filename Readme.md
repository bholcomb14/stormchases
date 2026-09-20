# StormChases WordPress Plugin

![Plugin Version](https://img.shields.io/badge/version-2.0.0-blue.svg) ![License](https://img.shields.io/badge/license-GPLv2-blue.svg) ![WordPress](https://img.shields.io/badge/WordPress-6.1%2B-blue.svg) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)

### ⬇️ [Download the Latest Release](https://github.com/bholcomb14/stormchases/releases)

See it live at [benholcomb.com/tech/storm-chases-wordpress-plugin](https://www.benholcomb.com/tech/storm-chases-wordpress-plugin/).

A WordPress plugin designed for storm chasers by a storm chaser to create and manage detailed chase logs using a custom post type (`storm_chase`). Log chase details — tornadoes observed, Spotter Network reports, and weather conditions — with optional OpenStreetMap or Google Maps integration. Public-facing pages (an archive, aggregate stats, an interactive tornado map, and a Spotter Network reports map) are built entirely from Gutenberg blocks in the block editor. **As of 2.0.0, the plugin is blocks-only** — the equivalent shortcodes (`[scarchive]`, `[scstats]`, `[sc_tornado_map]`, `[sc_reports]`) have been removed now that every one of them has a block equivalent. If any existing page content still has one of those shortcodes typed in directly, swap it for the matching block before upgrading — the shortcode text will otherwise just show up literally on the page instead of rendering.

## Features

- **Custom Post Type**: Manage storm chase logs with the `storm_chase` post type, accessible at `/chases/YYYYMMDD/` (e.g., `/chases/20250608/`).
- **Guided "Add New Storm Chase" wizard**: Creating a chase walks through the required basics (date, chase type, states, miles, chase partners) first, then optional weather details and a chase map upload, before landing on the same edit screen used for every existing chase. Logging several chases in one sitting? "Add Another Chase" on the last step starts a fresh wizard right away — each one is already saved as a draft either way.
- **Meta Data Fields**: Capture detailed chase information:
  - **Chase Date** (auto-derived from publish date, YYYYMMDD)
  - **States Chased** (required, comma-separated state/province codes, e.g., `TX,OK,AB`)
  - **Chase Partners** (optional, defaults to `Solo`) — each name is automatically kept as a real, reusable person profile (autocomplete-as-you-type, plus an optional website and location) instead of just free text; see Chase People Profiles below.
  - **Chasers Encountered** (optional, defaults to `None`) — same shared profile list as Chase Partners, not a separate one. Someone tagged as both a partner and an encountered chaser (chased with them a few times, ran into them many more) shows one combined "other chases" popup, not two, labeled with which role they were in each time.
  - **Miles Logged** (optional, 0–9999)
  - **Largest Hail** (optional, 0–9.99 inches)
  - **Highest Wind** (optional, 0–300 mph — capped since anything higher is almost certainly a typo; flagged for a second look above 120)
  - **Chase Type** — Convective (default), Hurricane, Winter, or Other. Switching type shows/hides the relevant fields without discarding whatever was already entered for the others. Convective chases can tag **Storm Mode** (one or more of Supercell - LP, Supercell - Classic, Supercell - HP, Supercell - Hybrid, QLCS/Squall Line, Multicell Cluster, Multicell, Landspout/Non-supercell, Tropical/Landfalling Remnant, Other).
  - **Chase Milestones** (optional, bullet-point list — e.g., "First July tornado", "First Wisconsin tornado")
  - **Best Chase of the Season** flag (checkbox — one per year, shown when the feature is enabled)
  - **Chase Map** (optional): a JPEG/PNG/GIF image, or a GPS track (`.kml`, `.gpx`, `.nmea`). Multiple GPS track files for the same day (e.g., a log split by a device restart) are merged into a single chronological track, with gap markers where GPS signal was lost between files.
  - **Tornadoes Witnessed** (array, with name, coordinates, EF rating, start/end times, end coordinates, photo ID, and photogenic flag)
  - **Hurricane Landfalls** (array, shown only when Chase Type is Hurricane — name, coordinates, time, wind speed, Saffir-Simpson category, and lowest recorded pressure per landfall)
  - **Spotter Network Reports** (array, imported via CSV)
- **Blocks** (grouped under a **Storm Chases** category in the block inserter):
  - **Tornado Map** — interactive map with a marker for every tornado logged, colored by EF rating (purple EF-5 down to aqua EF-0, gray for unrated), each opening a detail modal, with a legend and a fullscreen toggle. Also overlays logged hurricane landfalls and Winter snowfall reports, plus optionally simple "memorable storm"/hurricane points (own icon, own click-through modal with an optional photo) sourced from the companion **Location Map** plugin's points table, if that plugin is active — an interim bridge for older hurricane chases not yet backfilled with native landfall data (automatically skipped once a matching native landfall exists, so the two sources don't double up). Sidebar controls: Year, EF-rating filter, independent on/off toggles for the storm/hurricane/snowfall overlays, a show/hide toggle for the legend, a toggle to show visitor-facing per-category checkboxes on the published page (off by default), a fixed zoom/center override (default: auto-fit to whatever's shown), and a map-height slider.
  - **Spotter Reports** — interactive map + sorted list of all Spotter Network reports, colored/shaped by report type (Tornado, Hail, Wind, Funnel Cloud, Wall Cloud, Damage), each opening a detail modal, with a legend and a fullscreen toggle. Sidebar controls: Year, report-type filter, map-height slider.
  - **Tornado List** — chronological list of every tornado logged, linked back to its chase.
  - **Chase Stats** — aggregated chase statistics for all time or a specific year, in up to four independently-toggleable sections (**Overall**, **Convective**, **Hurricane**, **Winter**) so a block instance can show just one type's numbers in isolation. Overall: Chase Days, Miles Driven, Average Miles per Chase Day, Longest Chase, States, Spotter Network Reports, "New States This Season," consecutive-year chase streak, Windshields Replaced, and (all-time only) Top Chase Partners/Most Encountered Chasers. Convective: Tornado Days, Tornadoes, Photogenic Tornadoes, Largest Hail, Highest Wind, Average Miles per Tornado, Tornado Day %, Tornadoes per Mile, EF Rating Breakdown, Storm Modes breakdown, first/last tornado of the season, **Busts**, **Blue Sky Busts**, **Kiss of Death Days** (each with an info tooltip), and Best Chase Day. Hurricane/Winter cover landfalls/snowfall respectively, plus first/last of the season and Best Chase Day. An optional sortable "Biggest Chase Days" table too. What a brand-new block starts with is configurable in Settings (see Chase Stats Block Defaults).
  - **Chase Archive** — list of past storm chases, with a Year dropdown populated from the years that actually have chases logged (instead of free text), a "Number of chases to show" field, and an optional icon toggle showing tornado/hail/wind stat badges per entry. Picking a year that hasn't happened yet shows a clear "hasn't happened yet" message instead of an empty list. Hurricane chases are labeled by storm name instead of just a date.
  - **Current Location** — block-only, no shortcode ever existed for this one. Embeds the live chaser-location tracker (`/files/location.html`) as a configurable iframe (URL, height), replacing what used to be a hand-placed raw HTML block.
- **Map Providers**: Choose between **OpenStreetMap** (Leaflet.js, self-hosted, no API key required) or **Google Maps** (API key required) in plugin settings.
- **GPS Track Playback**: Chase maps built from a GPS track include a start → end scrubber that redraws the route up to a point in time and shows the current percentage through the track, plus Play/Pause and speed controls. The map is displayed after the chase recap summary, just above the full write-up.
- **Historical Radar Overlay**: A "Show radar" toggle on tracks with timestamps overlays historical NEXRAD composite reflectivity, cropped to cover whatever's actually visible on screen by default (not just a box tightly hugging the track itself — a long, narrow track no longer shows radar as a thin strip bordered by bare map).
- **GPS Track Privacy Zones**: Configure named locations with a radius in plugin settings; points falling inside a zone are stripped from the start and end of any uploaded GPS track (e.g., your home or staging area) while points in the middle of the track are always kept.
- **Bust / Blue Sky Bust / Kiss of Death Tracking**: Counted from the `bust`, `blue sky bust`, and `kissofdeath` WordPress tags already used to mark chase recaps — no separate editor field, just keep tagging chases the way you already do and the Chase Stats block picks it up.
- **Best Chase of the Season**: Mark one chase per year as the best/favorite. Displays as *Best Chase Day: May 24* with a link in the Chase Stats block.
- **Windshield Replacements**: Track hail-damage windshield replacements by month/year directly in the settings page. Displays in the Chase Stats block as *Windshields Replaced: N*.
- **Legacy Meta Row Cleanup**: A Settings-page tool to find and remove stray individual postmeta rows left behind by an old save path, with a dry-run report before anything is deleted.
- **Spotter Network Import**: Drag-and-drop CSV upload UI with dry-run preview mode and clear progress feedback. Reports are automatically matched to chase logs by date. (No credential-based auto-fetch — this plugin never stores a Spotter Network password; CSV export/upload is the only import path.)
- **Chase Stats Block Defaults**: A Settings-page section controlling what a brand-new Chase Stats block starts with when added to a page — a block already placed somewhere is unaffected.
- **Dynamic Tornado Entries**: Add, remove, and reorder (drag or up/down arrows) tornado entries in the admin interface, with fields for coordinates, EF rating, media uploads, and photogenic flag. Entries collapse to a one-line summary when not being edited, expanding on click. Display order on the public chase page matches the order set in the editor (top entry first).
- **Customizable Settings**: Enable/disable individual features, choose map provider, set file size limits, and configure supported MIME types.
- **Responsive Design**: Styled for modern themes, optimized for mobile and desktop.
- **REST API Support**: Full Gutenberg/block editor compatibility for meta data and file uploads.
- **Transient Caching**: Every block's output is cached with automatic invalidation on post save.
- **Error Handling & Logging**: Debug logging via `WP_DEBUG_LOG`.
- **State/Province Display**: Converts state codes to full names with a Canadian flag icon for provinces.
- **Time Zone Conversion**: Spotter Network report timestamps displayed in Central Time.

## Installation

1. **Download the Plugin**:
   - Grab the latest tagged version's ZIP from the [Releases page](https://github.com/bholcomb14/stormchases/releases) — this is the recommended way to get a stable, versioned copy.
   - Or clone the repo directly: `git clone https://github.com/bholcomb14/stormchases.git` (this tracks the latest commit, not necessarily a tagged release).

2. **Install**:
   - Upload the `stormchases` folder to `/wp-content/plugins/`.
   - Or go to **Plugins > Add New > Upload Plugin** and select the ZIP.

3. **Activate**: Navigate to **Plugins** in the WordPress admin dashboard and activate **StormChases**.

4. **Configure** (optional):
   - Go to **Storm Chases > Settings** to enable features, choose a map provider, enter a Google Maps API key (if using Google Maps), and configure file upload limits.

## Usage

### Creating a Storm Chase
1. Navigate to **Storm Chases > Add New** — this opens the guided wizard, not the raw editor.
2. **Step 1 (required)**: Chase Date, Chase Type, States Chased, Chase Partners (type "Solo" or names), and Miles Logged (0 counts as answered). This creates the chase as a draft and sets its publish date/slug automatically.
3. **Step 2 (optional)**: weather details for the Chase Type you picked — tornadoes, wind, hail, storm mode, or landfalls/snowfall — plus milestones. Skip it if you don't have this yet.
4. **Step 3 (optional)**: upload a chase map (GPS track or image). Skip it too, if you'd rather add it later.
5. Click **Finish** to land on the normal edit screen for that chase (add photos, mark Best Chase of the Season, etc., then Publish when ready), or **Add Another Chase** to immediately start logging the next one — useful for getting a backlog of chases on the books in one sitting.
6. Once published, the chase is accessible at `/chases/YYYYMMDD/`.

### Blocks

Insert via the block editor and search for the block by name (all grouped under a **Storm Chases** category
in the inserter): **Tornado Map**, **Spotter Reports**, **Tornado List**, **Chase Stats**, **Chase Archive**,
or **Current Location**. Requires WordPress 6.1+.

- **Tornado Map**: Year, EF-rating filter, memorable-storm/hurricane/snowfall overlay toggles, legend
  show/hide, visitor-facing layer-toggle checkboxes, fixed zoom/center override, map-height slider,
  heading text.
- **Spotter Reports**: Year, report-type filter, map-height slider, heading text.
- **Tornado List**: Year, heading text.
- **Chase Stats**: Year, heading text, section toggles (Overall/Convective/Hurricane/Winter), Longest
  Chase, EF Rating Breakdown, Storm Modes, first/last tornado & landfall of the season, "new states"
  callout, consecutive-year streak, sortable "Biggest Chase Days" table (+ count), Top Chase Partners /
  Most Encountered Chasers (+ count, all-time only).
- **Chase Archive**: Year (a dropdown of years that actually have chases logged), number of chases to show, four independent stat-icon toggles (tornado/hail/wind/Spotter Network report count, each off by default), heading text.
- **Current Location**: Embed URL (defaults to `/files/location.html`), height.

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
5. Enable **Enable Windshields Replaced** in the main settings to show the count in the Chase Stats block.

### Marking Best Chase of the Season
1. Enable **Enable Best Chase of Season** in settings.
2. Open any storm chase in the editor.
3. Check **Best Chase of the Season** in the meta box.
4. Saving the post updates the best-chase record for that season. Only one chase per year can be marked — checking a new one automatically replaces the previous selection.
5. The best chase day appears in the Chase Stats block (filtered to that year) as a linked date.

### Chase People Profiles
1. Just type names into the Chase Partners and/or Chasers Encountered fields as usual — Chase Partners and
   Chasers Encountered draw from one shared list of people, not two separate ones, and saving the chase
   automatically keeps that list up to date. No separate tagging step needed.
2. Manage the full list of people (and give any of them an optional **Website** and **Location**) under
   **Chase People** in the admin menu.
3. On the public chase page, each named person links to a popup showing every other chase logged with
   them, plus their website/location if set. Someone who's both a Chase Partner on some chases and a
   Chaser Encountered on others is one person here, not two — their popup lists every chase they're on,
   labeled with which role they were in each time.
4. Already had chases logged before this existed? Go to **Storm Chases > Settings → Chase People
   Profiles**, click **Scan Chase Logs for Names** to preview every name it would turn into a profile,
   then **Migrate N Name(s)** to apply — the original text fields are never modified, so this is safe to
   run (or re-run) at any time. Confirmed spelling variants of the same person (or a nickname that should
   map to their real name) can be listed under **Name Corrections** on the same Settings page — "As Typed
   => Correct Spelling," one per line.

### Legacy Meta Row Cleanup
1. Go to **Storm Chases > Settings → Legacy Meta Row Cleanup**.
2. Click **Scan for Legacy Meta Rows** — reports any stray individual postmeta row found on a chase, and
   whether its value matches that chase's real data (safe to delete) or not (needs manual review).
3. If any are reported safe, click **Delete Safe Row(s)** to remove them. Rows that don't match are never
   auto-deleted.

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
| **Enable Best Chase of Season** | Show best-chase checkbox in editor and stat in the Chase Stats block |
| **Enable Windshields Replaced** | Show windshield count in the Chase Stats block |
| **Enable Busts / Blue Sky Busts** | Show Busts and Blue Sky Busts counts in the Chase Stats block, from the `bust`/`blue sky bust` tags |
| **Enable Kiss of Death Days** | Show Kiss of Death Days count in the Chase Stats block, from the `kissofdeath` tag |
| Enable Maps | Enable map rendering on individual chase pages |
| Enable Spotter Network Reports | Display report count and modals on chase pages |
| **Map Provider** | `OpenStreetMap` (default, no key needed) or `Google Maps` |
| Google Maps API Key | Required only when Google Maps is the selected provider |
| Maximum File Size | Chase map upload limit (1MB–100MB); GPS track files bypass this limit and are governed by PHP's `upload_max_filesize` |
| Supported File Types | MIME types for chase map image uploads (GPS tracks are always accepted regardless of this list) |
| **GPS Track Privacy Zones** | Named locations with a radius (miles); track points within a zone are stripped from the start/end of uploaded GPS tracks |
| **Legacy Meta Row Cleanup** | Scan for and remove stray individual postmeta rows left behind by an old save path |
| **Chase Stats Block Defaults** | What a brand-new Chase Stats block starts with (section toggles, "Biggest Chase Days", Top Chase Partners/Chasers, and their counts) — doesn't affect a block already placed on a page |
| **Name Corrections** | "As Typed => Correct Spelling" pairs for the Chase People profiles — one per line |

## Requirements

- **WordPress**: 6.1 or higher (tested up to 6.6) — raised from 5.0 for the Gutenberg blocks' server-side rendering support.
- **PHP**: 7.4 or higher.
- **Browser**: Modern browsers (Chrome, Firefox, Safari, Edge).
- **Google Maps API Key**: Required only when Google Maps is the selected map provider.

## Built With

- **PHP**: Plugin logic, custom post type, REST API, Gutenberg block rendering, data sanitization.
- **JavaScript**: jQuery for admin UI; Leaflet.js or Google Maps JS API for interactive maps.
- **CSS**: Custom admin and front-end styles.
- **WordPress APIs**: Custom post type, REST API, block editor, transients.
- **Leaflet.js**: v1.9.4, self-hosted (`assets/vendor/leaflet/`) rather than loaded from a CDN.

## Changelog

Full details for every release live in [CHANGELOG.md](CHANGELOG.md). Summary:

### 2.0.0
- **Guided "Add New Storm Chase" wizard** — required basics first, then optional weather details and a
  chase map upload, before landing on the normal edit screen. "Add Another Chase" for logging several in
  one sitting.
- **Chase Type** (Convective/Hurricane/Winter/Other) with hurricane landfalls, Winter snowfall reports,
  Convective storm mode (including LP/Classic/HP/Hybrid supercell subtypes), and per-type Best Chase of
  the Season.
- **Chase Stats** restructured into toggleable Overall/Convective/Hurricane/Winter sections (with
  Settings-page control over what a brand-new block starts with), plus a new sortable "Biggest Chase
  Days" table, EF rating breakdown, storm mode breakdown, and several new stats (Longest Chase,
  first/last tornado/landfall of the season, new-state callout, consecutive-year chase streak).
- **Chase People Profiles** — Chase Partners and Chasers Encountered draw from one shared list of real,
  reusable people (each with an optional website and location, and a combined "other chases" popup for
  someone who's been both a partner and an encountered chaser) instead of just free text — kept
  automatically up to date on every save, with a migration tool to backfill existing chase logs.
- Chase Archive per-icon toggles, hurricane naming, and all shortcodes removed in favor of their block
  equivalents. (Spotter Network import stays CSV-only — an auto-fetch feature was tried and removed rather
  than store a real account password for uncertain benefit.)

### 1.9.2
- Play/Pause and speed controls for GPS track playback, with automatic radar-frame buffering.
- Radar overlay now on by default for tracks that support it.

### 1.9.1
- Fixed three separate radar-overlay position bugs (GPU texture-size overflow, a stale-frame race
  condition, and a pre-2014 historical grid-size mismatch).
- Radar frames now cached in memory and debounced for smoother scrubbing.

### 1.9.0
- Tornado entries became reorderable/collapsible; GPS tracks gained outlier rejection and a historical
  radar overlay.
- Chase Date/slug/publish-date derivation unified into one source of truth; several bug fixes (a Settings
  page crash, an NMEA timestamp parsing bug, a duplicated hook).
- Tornado Map and Spotter Reports gained EF/type-colored icons, legends, and fullscreen; first Gutenberg
  blocks added (raised the WordPress minimum to 6.1).

### 1.8.0
- Interactive Tornado Map and Spotter Reports map/list added.
- Best Chase of the Season and Windshield Replacements tracking added.
- Spotter Network CSV upload UX improved (drag-and-drop, dry-run preview).

### 1.7.0
- Initial aggregated chase statistics, Spotter Network CSV upload, and dynamic tornado entry management.

## Author

**Ben Holcomb** — [benholcomb.com](https://www.benholcomb.com) | [GitHub](https://github.com/bholcomb14)

## License

[GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html)

## Contributing

Issues and pull requests welcome at the [GitHub repository](https://github.com/bholcomb14/stormchases). Please follow WordPress coding standards.
