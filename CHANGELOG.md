# Changelog

Full release notes for StormChases. `Readme.md` keeps a 1-3 bullet summary per version; this file has
the complete detail — what changed, why, and any migration/behavior notes worth knowing before updating.

## 2.0.0
- **Added**: **Chase People Profiles** — names typed into Chase Partners or Chasers Encountered are
  automatically kept as real, reusable people (one shared `chase_person` taxonomy for both fields, not
  two) instead of just free text — no separate tagging step, this happens on every save. Each person can
  have an optional Website and Location (free text — a whole state, or a specific city, whichever fits);
  on the public chase page, their name links to a popup listing every other chase they're on, labeled with
  which role (Chase Partner or Chaser Encountered) they were in each time — someone who's been both shows
  one combined popup, not two. A Settings-page tool (scan, then apply) backfills any chase saved before
  this existed — the original text fields are never modified, so it's safe to run anytime.
- **Added**: "Top Chase Partners" / "Most Encountered Chasers" in Chase Stats (all-time view only) — ranks
  people by how many chases they're named on in that specific role, each linking to their profile.
- **Added**: An **"Add New Storm Chase" wizard** — creating a chase now walks through the required basics
  (date, chase type, states, miles, chase partners) first, then optional weather details and a chase map
  upload, before landing on the exact same edit screen used for every other chase. "Add Another Chase" on
  the last step makes logging a backlog of several chases in one sitting quick. The old direct "Add New" →
  raw editor path is gone; every new chase goes through the wizard now.
- **Added**: A **"Chase Stats Block Defaults"** Settings section — controls what a brand-new Chase Stats
  block starts with when added to a page (a block already placed somewhere is unaffected). Ships with
  Hurricane, Winter, "Biggest Chase Days," and Top Chase Partners/Chasers off by default.
- **Added**: First/Last Tornado of the Season and First/Last Landfall of the Season, Average Miles per
  Chase Day/per Tornado, a "New States This Season" callout, and consecutive-year chase streak tracking,
  all in Chase Stats.
- **Changed**: Highest Wind is capped at 300 mph (anything higher is treated as a typo); the admin field
  flags in red above 120 mph as a "double check this" nudge without blocking the value.
- **Changed**: Chase Archive's single "Show stat icons" toggle split into four independent ones
  (tornado/hail/wind/Spotter Network report count icons), each off by default.
- **Fixed**: A storm logged both in the legacy `location_map_points` overlay table and as a native chase
  landfall no longer shows two duplicate markers on the Tornado Map — the bridge-table point is skipped
  once a same-named native landfall exists. Switching a chase's Chase Type after it was marked Best Chase
  of the Season for its old type now correctly clears that stale slot instead of leaving it stuck.
- **Added**: Winter snowfall reports are now also plotted on the Tornado Map (their own icon/legend entry),
  matching how hurricane landfalls already were.
- **Added**: **Storm Mode** — a Convective-chase checkbox group (Supercell - LP, Supercell - Classic,
  Supercell - HP, Supercell - Hybrid, QLCS/Squall Line, Multicell Cluster, Multicell,
  Landspout/Non-supercell, Tropical/Landfalling Remnant, Other; pick as many as applied), aggregated into a
  breakdown in Chase Stats' Convective section.
- **Added**: **Snowfall Reports** for Winter-type chases — repeatable entries (town/place name,
  coordinates, time, snowfall depth), same pattern as Hurricane Landfalls, shown on the chase page and
  aggregated into a new Winter section in Chase Stats.
- **Added**: **Best Chase of the Season is now tracked per Chase Type** — a Hurricane pick and a
  Convective pick for the same year no longer overwrite each other. Every chase marked best-of-season
  before this change is treated as Convective (the only type that existed at the time), migrated
  automatically the first time the site loads after updating.
- **Changed**: Chase Stats is restructured into up to four independently-toggleable sections — Overall,
  Convective, Hurricane, Winter — each with its own block-property switch, so a block instance can show
  just one type's numbers in isolation. Convective-specific stats (Tornado Days, Tornadoes, Hail, Wind,
  Busts, Kiss of Death, etc.) now count only Convective-type chases specifically, rather than blending in
  anything logged on a Hurricane or Winter chase.
- **Added**: Chase Stats gained a **Longest Chase** stat (miles + link, in the Overall section), an
  **EF Rating Breakdown**, and a **Storm Modes** breakdown (both in the Convective section) — each with
  its own on/off block property.
- **Added**: An optional **"Biggest Chase Days"** sortable table in Chase Stats (off by default) — click
  any column header to re-sort; shows your top N chase days by tornado count, with type/miles/hail/wind.
- **Changed**: Highest Wind is capped at 300 mph (anything higher is treated as a typo); the field flags
  in red above 120 mph as a "double check this" nudge without blocking the value.
- **Added**: **Chase Type** — every chase is now classified as Convective (the default — matches every
  existing chase's prior behavior exactly), Hurricane, Winter, or Other. Switching type shows/hides the
  relevant fields without ever discarding the hidden ones' data. Hurricane chases get a repeatable
  **Landfall** entry (name, coordinates, time, wind speed, Saffir-Simpson category, lowest pressure — one
  entry per landfall, since a storm can weaken and restrengthen between several), plotted on the Tornado
  Map alongside regular tornado markers. Chase Stats gains a separate Hurricanes breakdown (chase count,
  landfall count, storm names, highest category, lowest pressure, highest wind) whenever at least one
  hurricane chase is in scope, and the Chase Archive labels a hurricane chase by storm name instead of just
  its date.
- **Added**: Chase Archive gained an optional icon toggle — when on, each entry shows a tornado icon +
  count, a hail icon + size, and/or a wind icon + speed (from either the chase's own Highest Wind field or
  any Spotter Network report's logged wind speed), whichever apply to that chase. Off by default.
- **Added**: Info tooltips (hover/focus, matching the existing Kiss of Death one) on Busts, Blue Sky Busts,
  Tornado Day Percentage, and Tornadoes per Mile, explaining what each one means or how it's calculated.
- **Removed**: All shortcodes (`[scarchive]`, `[scstats]`, `[sc_tornado_map]`, `[sc_reports]`) — the
  matching block had been available for each one, so the plugin is now blocks-only. Any existing page
  content with one of these typed in directly needs to be swapped for the matching block; the shortcode
  text will otherwise just render literally on the page.
- **Changed**: The Plugins list page now shows a "Visit plugin site" link
  ([benholcomb.com/tech/storm-chases-wordpress-plugin](https://benholcomb.com/tech/storm-chases-wordpress-plugin/)),
  same as any other plugin with a homepage.
- **Added**: `[sc_tornado_map]`/Tornado Map (block) can now overlay simple "memorable storm" and hurricane
  points (name, coordinates, optional link/photo, own click-through modal, own icon distinct from the
  EF-rated tornado icons) alongside the regular per-chase tornado markers — sourced from the companion
  **Location Map** plugin's points table if that plugin is active (a soft read; degrades to no overlay,
  not an error, if it isn't). An interim bridge until hurricane landfalls and a "memorable storm" flag
  exist natively on `storm_chase` posts. New independent on/off toggles for storms and hurricanes, both on
  by default.
- **Added**: Tornado Map gained a legend show/hide toggle (previously always shown with no way to hide
  it), a toggle for visitor-facing per-category checkboxes on the published page (letting a reader turn EF
  ratings/storms/hurricanes on and off after the page loads — off by default so it doesn't change the
  appearance of any existing use of the block), and a fixed zoom/center override (default: auto-fit to
  whatever's shown, matching prior behavior).
- **Added**: **Current Location** block — embeds the live chaser-location tracker (`/files/location.html`)
  as a configurable iframe (URL, height), an editor-native replacement for a raw HTML block that had to be
  hand-placed before. No shortcode equivalent; block-only.
- **Added**: Chase Stats gained **Busts**, **Blue Sky Busts**, and **Kiss of Death Days**, counted from the
  `bust`/`blue sky bust`/`kissofdeath` WordPress tags already used to mark chase recaps — no new editor
  field. Kiss of Death Days has an info tooltip explaining the term. Both behind new Settings toggles, on
  by default.
- **Added**: **Chase Archive** block — a Year dropdown populated from the years that actually have chases
  logged, instead of free text, plus a "Number of chases to show" field (latest N overall, latest N within
  a chosen year, or all chases in a year — the two controls combine). Picking a year that hasn't happened
  yet shows a clear "hasn't happened yet" message instead of an empty list.
- **Added**: **Legacy Meta Row Cleanup** tool on the Settings page — finds stray individual postmeta rows
  left behind by an old save path, reports whether each matches the chase's real data, and only deletes
  the ones confirmed safe.
- **Fixed**: The block editor's live preview for the Tornado Map and Spotter Reports blocks was always a
  blank box — the outline, layer toggles, and legend all rendered correctly, but the interactive map itself
  never appeared (it always worked fine on the published page). Leaflet now loads into the editor canvas
  correctly and the preview re-initializes automatically on every attribute change.
- **Fixed**: The historical radar overlay on a GPS track could show as a generic-looking box with little or
  no relation to the actual track — the underlying map library animates its "fit to track" pan/zoom by
  default, so the crop calculation was reading the map's *pre-animation* state (its startup default view,
  which happens to cover most of the continental US) rather than where it had actually just moved to. Every
  track ended up with roughly the same coincidental crop instead of one centered on its own route. Fixed to
  read the map's real, settled position; also widened to cover at least what's actually visible on screen
  by default (not just a box drawn tightly around the track's own footprint), so a long, mostly
  north-south/east-west drive doesn't show radar as a narrow strip bordered by bare map either.
- **Changed**: Leaflet and the jQuery UI datepicker theme are now self-hosted (`assets/vendor/`) instead of
  loaded from a CDN on every admin/frontend page that needs them.
- **Fixed**: Two different templates rendered a tornado entry in the admin editor (already-saved entries,
  and the "Add Tornado" button's response) — now one shared template, so they can't drift out of sync
  again.
- **Changed**: Several internal performance improvements — the Tornado Map and Spotter Reports blocks are
  now cached like the rest of the blocks are (previously uncached, re-querying and re-processing every
  chase on every view), and a chase's data is no longer re-fetched from the database more than once per
  page load when multiple blocks need it.

## 1.9.2
- **Added**: Play/Pause and speed (+/-, 0.25x-8x) controls above the GPS track scrubber, animating the track (and radar, if shown) automatically instead of requiring manual dragging. Dragging the scrubber by hand stops playback. Pressing Play first buffers a short run of upcoming radar frames (shown as "Buffering radar… N/M" next to the controls) so a fast connection can play back smoothly instead of visibly lagging behind; playback also keeps one frame prefetching in the background while it plays, and will pause and re-buffer automatically if a slow connection falls behind rather than showing a stale frame. None of this downloads anything until Play is actually pressed.
- **Changed**: The "Show radar" toggle is now checked by default on tracks that support it. Radar frames are still only ever fetched one at a time as the scrubber/playback actually reaches them (never preloaded), so this only costs a single frame's download on page load, not the whole track's worth.

## 1.9.1
- **Fixed**: The historical radar overlay could show a storm 90-100 miles north of its true position, despite the underlying track/timestamp/imagery data all being correct. At a chase page's typical zoom level, Leaflet/Google Maps has to CSS-scale the full continental NEXRAD composite image (12,200x5,400px) up to render sizes exceeding 11,000x6,000px on screen — past the GPU's maximum texture size on some browser/driver combinations, which silently mis-composited the overflow instead of erroring, so the storm appeared shifted even though the browser's own DOM position and pixel data were both correct. The radar image is now cropped client-side (via canvas) down to just the chase track's bounding box, plus padding, *before* it's ever handed to the map as an overlay, so the on-screen render size stays small regardless of zoom and the GPU never approaches that limit.
- **Fixed**: Dragging the GPS track scrubber fires a burst of radar-frame image requests in quick succession, and nothing prevented a slow-to-load *earlier* frame from finishing after a faster *later* one and overwriting it — the on-screen overlay would end up showing an older frame's storm position while the "Radar: HH:MM UTC" label had already moved on to the correct, current time. Each radar frame request now carries a sequence number; only the most recently *issued* request is allowed to actually paint the overlay, regardless of which order the network responses arrive in.
- **Changed**: Each radar frame is now cached in memory (as a small cropped image) after its first load, so scrubbing back over a time you've already viewed redraws it instantly with no re-download. Fetching a new frame is also debounced by ~150ms, so dragging quickly across many frames only fetches the one you actually stop on instead of a discarded request for every frame crossed along the way.
- **Fixed**: On chases from before roughly late 2014, the radar overlay could show a storm shifted well east of its true position (e.g., the May 20, 2013 Moore, OK EF-5 showed clear over the actual tornado track with the storm displaced to the east) — IEM's `n0q` composite grid isn't fixed across history; it grew from 12,000x5,200px to 12,200x5,400px sometime between January and December 2014, and the overlay was computing each frame's geographic position from today's grid size regardless of the frame's actual date. Each radar frame's real bounds are now read from its own accompanying `.wld` world file instead of assumed, so this can't drift again even if IEM changes the grid a third time.

## 1.9.0
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
- **Fixed**: The Tornado Map always rendered "No location data available for this map" — its `WP_Query` filtered on a `chasetornado` post-meta key that doesn't actually exist as a standalone row (tornado data lives only inside the serialized `chase_data` blob), so the query silently matched zero posts. It now queries all published chases and filters in PHP.
- **Added**: Tornado Map markers are now colored by EF rating (purple EF-5 down to aqua EF-0, gray for unrated) instead of Leaflet's default blue pin, using a set of hand-generated icons; higher-rated tornadoes always draw on top of lower-rated ones when markers overlap at low zoom. A matching legend renders under the map. Works on both the OpenStreetMap and Google Maps providers.
- **Added**: A fullscreen toggle on both the Tornado Map and Spotter Reports maps.
- **Fixed**: Map marker popups couldn't be closed at all when opened from a map click, since the close-button/backdrop handlers were only ever bound on pages that also had certain unrelated link elements. Popups now always open positioned next to the clicked marker instead of centered on screen, and close on any click inside them (the X, the backdrop, or the content itself).
- **Changed**: Marker hover labels (tornado/report name) are bigger and bolder.
- **Added**: Spotter Reports markers are now colored/shaped by report type (Tornado, Hail, Wind, Funnel Cloud, Wall Cloud, Damage, or a generic fallback) instead of a plain default pin, with a matching legend. The report-type label itself (previously three slightly-drifted copies of the same derivation logic scattered across the codebase) is now computed in one shared place.
- **Added**: Gutenberg block equivalents of the map/list/stats shortcodes that existed at the time — Tornado Map, Spotter Reports, Tornado List, and Chase Stats — each with block-sidebar controls (year, heading text, show/hide heading) instead of shortcode attributes; the two map blocks additionally got a map-height slider, and a checkbox filter to show only chosen EF ratings or report types. **Raised the minimum WordPress version to 6.1** (from 5.0) for the blocks' server-side rendering support.

## 1.8.0
- **Added**: Tornado Map — interactive OpenStreetMap/Google Maps map of all tornadoes with modal popups. One modal open at a time; clicking another closes the current one.
- **Added**: Spotter Reports — interactive map + full list of all Spotter Network reports with modal popups.
- **Added**: **Map Provider** setting — choose between OpenStreetMap (Leaflet.js, no API key) or Google Maps.
- **Added**: **Best Chase of the Season** feature — checkbox in the chase editor, displayed as *Best Chase Day: [date]* in the Chase Stats block.
- **Added**: **Windshield Replacements** management in plugin settings — track hail-damage windshield replacements by month/year; count displayed in the Chase Stats block.
- **Improved**: Spotter Network CSV upload — drag-and-drop file zone, file size/name preview, **dry-run (preview) mode**, inline loading spinner, cleaner skip-reason reporting.
- **Improved**: Modals on all pages close when another is opened (one-at-a-time behavior).
- **Improved**: `stormChasesFrontend.mapProvider` passed to frontend JS for correct map initialization.
- **Fixed**: Admin `sanitize_callback` routing now handles `map_provider` correctly without falling back to `intval`.

## 1.7.0
- Added aggregated chase statistics.
- Added Spotter Network Reports CSV upload with validation, date matching, and overwrite option.
- Added dynamic tornado entry management with media uploads.
- Added state/province code conversion with Canadian flag.
- Added UTC → Central Time conversion for report timestamps.
- Improved Google Maps integration.
- Improved REST API endpoints for chasemap and spotter report uploads.
