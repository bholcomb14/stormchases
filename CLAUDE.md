# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

StormChases is a WordPress plugin (no external framework) that adds a `storm_chase` custom post type for
logging storm-chase days: date, states, partners, miles, hail, wind, tornadoes witnessed, a GPS track or map
image, Spotter Network reports, and free-form milestones. It renders individual chase pages, an archive, and
aggregate stats via shortcodes, with either Leaflet/OpenStreetMap or Google Maps for mapping.

Single author, single production site (benholcomb.com), no other installs to support compatibility for.

## Development Commands

This is a plain WordPress plugin — no npm/composer, no build step, no bundler, no test suite. Verification is:

```bash
# PHP syntax check every file (catches typos before they ever hit a live site)
find . -name "*.php" -not -path "./.git/*" -print0 | xargs -0 -n1 php -l

# JS syntax check
node --check assets/js/admin.js
node --check assets/js/frontend.js
```

There is no local WordPress environment in this repo. To actually exercise a change, it has to be tested
against a real WordPress install (the author runs dev and prod sites behind nginx→Apache/PHP containers under
Podman). When a change needs functional verification beyond syntax — GPS parsing/merge logic, sanitization,
date math — write a throwaway PHP script that `require`s the relevant file with minimal WP-function stubs
(`define('ABSPATH', ...)`, stub `__()`, `add_action()`, etc.) and calls the target method via
`ReflectionClass` if it's private. This has repeatedly caught real bugs (NMEA timestamp parsing, GPS outlier
rejection, milestone migration) before deployment — prefer it over reasoning from source alone whenever the
logic is non-trivial (date/time math, array reindexing, sanitization pipelines).

### Release workflow

Version lives in two places in `stormchases.php` — the `Version:` header comment and the
`STORM_CHASES_VERSION` constant — both must be bumped together. Releases are cut on a branch named
`release-X.Y.Z`, committed incrementally while testing, then squashed and merged into `main` (historically via
a `Merge branch 'release-X.Y.Z' into 'main'` merge commit). Don't bump the version or merge to main unless
asked — mid-development fixes land as commits on the release branch first.

## Architecture

### Storage: one serialized blob per post, not individual meta keys

All chase fields live in a **single post meta key, `chase_data`**, holding a PHP-serialized associative array
(`StormChasesData::save_chase_data()` / `get_chase_data()` in `includes/data.php`). `get_chase_data()` accepts
JSON or PHP-serialized input (tries JSON first, falls back to `unserialize()`, defaults to `[]` on failure) —
this exists because the data has passed through multiple encoding schemes over the plugin's life. `chasedate`
is the one field that *also* gets written to its own individual `chasedate` post meta key (in
`StormChaseTemplate::save_post()`), purely so `WP_Query` can filter/sort chases by date with `meta_key` /
`orderby => meta_value`.

`StormChasesData::$meta_fields` is a config array (field → sanitize method, required, type) that drives both
`sanitize_chase_data()`'s generic per-field sanitization loop and `register_post_meta()` calls in
`StormChaseTemplate::register_meta_fields()`. That REST meta registration exists for Gutenberg/REST API
compatibility (`show_in_rest`) — it does **not** back the actual read/write path, which always goes through
`get_chase_data()`/`save_chase_data()`. Don't assume a field is unused just because you don't see
`get_post_meta($id, 'fieldname', true)` calls for it; look for `$chase_data['fieldname']` instead.

`tornadoes`, `spotter_reports`, and `chasemap_track` are array fields handled by dedicated sanitize methods
(`sanitize_tornado_data()`, `sanitize_spotter_reports()`, `sanitize_track()`) called explicitly in
`sanitize_chase_data()`, bypassing the generic string-sanitization branch. When adding a new array-typed
field, follow this pattern rather than the generic loop.

### GPS track pipeline (`includes/post-types/storm_chase.php`, `handle_chasemap_upload()`)

Order matters and each stage is a separate, independently-testable static method:

1. `parse_gps_file()` → `parse_nmea()` / `parse_kml()` / `parse_gpx()` — extracts `[lat, lon, timestamp|null]`
   tuples per uploaded file. NMEA timestamps are built from `$GPRMC`/`$GNRMC` sentences; seconds must be kept
   as a zero-padded 2-char string when building the date string passed to `DateTime::createFromFormat()` —
   casting to `(int)` first drops the leading zero for `:00`–`:09` and silently fails parsing for ~1 in 6
   points (this exact bug shipped once; don't reintroduce it).
2. `merge_tracks()` — combines multiple uploaded files into one track. If every point has a timestamp, sorts
   by time and inserts a `null` gap sentinel wherever the gap exceeds `max(120s, 15× the median sample
   interval)` — this is what lets a chase split across two files (e.g., a device reboot mid-chase) render as
   two separate line segments instead of one line jumping across the gap. If no timestamps exist (older
   KML/GPX), falls back to `order_tracks_geographically()`, which brute-forces all `N!` orderings of the
   input files to minimize total end-to-start distance — fine for 2-3 files, but don't feed it many
   (untimed) files without addressing the factorial blowup first.
3. `remove_distance_outliers()` — rejects any point more than 10 miles from the last *accepted* point within
   its segment (nulls from step 2 are segment boundaries and are left alone). Catches a GPS receiver losing
   lock and reporting one wild fix, or getting stuck repeating one stale fix for the rest of a recording.
4. `apply_privacy_zones()` — strips points within a configured radius of a privacy zone, but only from the
   *start and end* of the track (a chase driving through your home town mid-route is never redacted).
5. `simplify_track()` — stride-decimates to a target point count (default 1500), operating per-segment so a
   gap sentinel never gets simplified away.

`chasemap_track` values are `[lat, lon, timestamp|null]` triples (or `null` for a gap) after this pipeline —
timestamp is Unix seconds, UTC. `sparsify_track_timestamps()` runs last, after `simplify_track()`: it nulls
out every point's timestamp except roughly one checkpoint every 10 minutes per segment (always including the
segment's first and last point), rounded to the nearest 5 minutes. This exists because `chasemap_track` is
embedded directly into the *public* page for the historical radar overlay's JS to read — a real timestamp on
every point would expose precise time-of-day for the whole track to anyone viewing page source. Tracks saved
before this was added only have `[lat, lon]` pairs (or now, unsparsified `[lat, lon, ts]` — same handling
either way); the frontend treats a track as radar-capable only if at least one point has a truthy 3rd
element, so old chases silently just don't offer the toggle. There's no way to backfill timestamps into
already-stored data, since the original per-point time isn't derivable from lat/lon alone — re-uploading the
source GPS file(s) is the only way to gain radar sync on an existing chase. Frontend rendering also re-applies
`simplify_track()` + `sparsify_track_timestamps()` defensively if a stored track somehow exceeds 5000 points,
in case a chase was saved by a much older version of the plugin before this pipeline existed.

### Historical radar overlay (`assets/js/frontend.js`)

`createRadarController()` drives a "Show radar" checkbox next to the track scrubber. It fetches historical
NEXRAD composite reflectivity images directly from the Iowa Environmental Mesonet's public archive
(`mesonet.agron.iastate.edu/archive/data/{yyyy}/{mm}/{dd}/GIS/uscomp/{product}_{yyyymmddHHMM}.png`) — a
national composite, not a single radar site's raw scan, at the archive's native 5-minute cadence. Entirely
client-side: no PHP involvement, no server-side fetching/caching/storage. `n0q` (~500m/px resolution) is
tried first and works from roughly 2013 onward; `n0r` (~1km/px, coarser, available back to at least 2008) is
the fallback for dates before dual-pol reflectivity existed — probed via a throwaway image load rather than
assumed by date, since the exact product transition varied by site.

`loadAndCropRadar()` fetches each frame's accompanying `.wld` ESRI world file (same URL, `.wld` extension)
alongside the PNG and derives that frame's real geographic bounds from it, combined with the image's own
`naturalWidth`/`naturalHeight` — **never** from a hardcoded constant. IEM's `n0q` composite grid is not fixed
across history: it grew from 12000x5200px to 12200x5400px sometime between 2014-01-15 and 2014-12-31,
shifting its real extent from south=24.0°/east=-66.0° to south=23.0°/east=-65.0°. A previous version of this
code hardcoded the current-day extent and silently misplaced pre-2014 archive frames by tens of miles (caught
via a chase page for the May 20, 2013 Moore, OK EF-5, where the overlay showed the storm well east of its
true position) — if `n0q`'s grid ever changes again, or another historical grid change is found, re-deriving
from each frame's own `.wld` is what avoids re-introducing that bug, so don't reintroduce a hardcoded-bounds
shortcut. The crop itself (see below) also means the full continental image never needs to be rendered at
its native size, which is what actually avoids the GPU texture-size overflow described next.

At a chase's typical high zoom level, Leaflet/Google Maps would otherwise have to CSS-scale the full
continental composite image up to several times its native resolution to keep it correctly georeferenced —
comfortably past common GPU maximum-texture-size limits, which some browser/driver combinations silently
mis-render past rather than erroring on (confirmed reproducing identically across three unrelated browser/OS
combinations; the DOM position and the image's own pixel data both checked out correct via
`getBoundingClientRect` + direct canvas sampling, yet what was actually painted to screen was visibly
offset). `loadAndCropRadar()` avoids this entirely by cropping the image (client-side, via canvas) to a
generous but bounded region around the chase track before ever creating the overlay, so its rendered CSS
size stays sane regardless of zoom, then hands back a small Blob URL as the actual overlay source.
`createRadarController()` also keeps an in-memory cache of already-cropped frames (Blob URLs keyed by 5-minute
bucket, capped and evicted oldest-first) so re-scrubbing over an already-viewed time is instant, and debounces
new fetches by ~150ms so dragging quickly across many frames only fetches the one actually settled on.

`createRadarController()` is shared between the Leaflet (`L.imageOverlay`) and Google Maps
(`google.maps.GroundOverlay`) code paths; if you change one provider's overlay handling, check the other.

`estimateTimestamp()` reconstructs an estimated time for the (usual) case of a point without a real stored
timestamp, by linearly interpolating between the nearest checkpoint before and after it (by point position,
never crossing a `null` gap sentinel). This estimate only ever drives which radar frame to request — it's
computed in memory and never written back or exposed as a distinct value.

### chasedate / slug / publish-date: one derivation, two consumers

`StormChaseTemplate::derive_chasedate_from_post_date()` is the single source of truth for turning a post's
`post_date` into an 8-digit `YYYYMMDD` string (with a real `checkdate()` calendar check, not just a regex).
Both `save_post()` (writes the `chasedate` meta) and `set_post_name_from_chasedate()` (sets the post slug via
`wp_update_post()`) call it — this is deliberate, so the two can never compute different answers from the same
`post_date`. If you need the chase date anywhere else, call this method rather than re-deriving it inline.
The admin-side Chase Date field is always read-only; it mirrors the block editor's Publish date live via a
`wp.data.subscribe()` watcher in `admin.js` (parses the ISO date string by slicing the first 10 characters,
deliberately *not* using `new Date()`, to avoid a timezone-driven off-by-one-day).

### Admin UI (`assets/js/admin.js`, `templates/stormchases.php`, `templates/admin/settings.php`)

One `admin.js` file drives several independent panels on the post-edit screen and the settings page, all keyed
off the localized `stormChasesSettings` object (nonce, REST URL, ajax URL, max file size, etc. — set in
`Storm_Chases::enqueue_admin_scripts()` in `stormchases.php`). Panels: tornado entries (drag/arrow reorder,
collapse-to-summary, backed by `renumberTornadoes()` which rewrites every `tornadoes[N][...]` field name to
match DOM order after any add/remove/reorder — this is what prevents index collisions when an entry is
removed from the middle of the list and a new one is added; a per-entry **📍 Pick Location on Map** button
opens a single shared Leaflet modal, `#sc-location-picker-modal`, reused across all entries and both the
start and end lat/lon pair — Leaflet is only enqueued on `post.php`/`post-new.php`, not the settings page),
chase milestones (bullet list, Enter or **+** adds a row), chase map upload/remove (REST, not a form submit —
a plain form submit here previously tripped WordPress's "leave site?" unsaved-changes guard), Spotter Network
CSV import, windshield replacements, and GPS privacy zones (the latter two via `wp_ajax_*` actions in
`includes/settings.php`, not REST).

**Two different templates render a tornado entry** — `templates/stormchases.php` (already-saved entries) and
`Storm_Chases::get_tornado_entry()` in `stormchases.php` (the AJAX response for the **Add Tornado** button,
action `storm_chases_get_tornado_entry`). These must be kept in sync by hand; there's no shared partial.
They drifted once already (the AJAX-added version missed the drag/collapse UI for a full release cycle
before being caught) — if you change one, check the other. This includes the location-picker buttons.

### Frontend (`assets/js/frontend.js`, `includes/functions.php`)

Single-chase map rendering is localized via `stormChasesFrontend` (set in `StormChaseTemplate::enqueue_scripts()`,
`wp_enqueue_scripts` hook). The `[sc_tornado_map]` and `[sc_reports]` shortcodes are a *separate* map-asset
enqueue path (`sc_enqueue_map_assets()` in `functions.php`) that can run on any page, not just a single chase
— they share the Leaflet/Google Maps loading logic but localize a minimal, distinct `stormChasesFrontend`
payload (empty track/type) and pass point data via a page-global `window.scMapData[uid]` keyed by a
`uniqid()`-suffixed map container ID, since a page can contain more than one such map.

`[sc_tornado_map]` markers are colored per EF rating using **pre-generated PNGs**
(`assets/images/tornado-ef0.png`…`ef5.png`, `tornado-unrated.png`), not a CSS filter/mask applied to a single
icon at render time — a flat CSS `mask-image` recolor was tried first and looked bad, since it collapses the
source icon's shading (the black band, white highlights) to one flat fill. The shipped PNGs were generated by
a throwaway hue-preserving-lightness recolor script (grayscale-detection per pixel via HSL: near-neutral
pixels — the black band, white highlights, anti-aliased gray edges — are left untouched, only saturated
"colored" pixels get their hue replaced) run once against `assets/images/tornado-icon.png` (the original
single-color 20x18 source icon), which the script first upsamples 2x with `imagecopyresampled()` before
recoloring — `imagescale()`'s bicubic modes fail silently on at least one GD build encountered during
development, hence the manual resample — so the shipped PNGs are 40x36 even though `frontend.js` displays
them smaller (`TORNADO_ICON_SIZE`, currently `[26, 23]`); this keeps the icon crisp at display size instead
of blurring a native-resolution tiny asset upward. There's no build step that regenerates them, so if the
source icon, the per-rating hue/saturation targets, or the display size change, they have to be regenerated
by hand and the new PNGs committed. `frontend.js` picks the right file via `EF_ICON_SLUG` + the localized
`stormChasesFrontend.tornadoIconBase` (`STORM_CHASES_URL . 'assets/images/'`, set in
`sc_enqueue_map_assets()`) — this works identically for both Leaflet (`L.icon`) and Google Maps
(`Marker.icon`) since both just take an image URL, unlike the CSS-mask approach which could only ever apply
to Leaflet's DOM-based markers. Marker `zIndexOffset` (Leaflet) / `zIndex` (Google) is set proportional to EF
rank (`tornadoRank()`) so higher-rated tornadoes always draw on top of lower-rated ones when markers overlap
at low zoom, regardless of screen position.

`openModal(modalId, clickPos)` takes an optional `{x, y}` viewport position — map marker clicks pass their
click coordinates (via `sc-modal-positioned` class + `.modal-content` inline `left`/`top`) so the modal pops
up next to the marker instead of centered; the `.tornado-link`/`.report-link` delegated handler (used on the
single-chase page, not the map) omits it and gets the original centered behavior. Any click inside a modal —
the close button, the dimmed backdrop, or the content area itself — closes it.

Both shortcode map types (`[sc_tornado_map]`, `[sc_reports]`) get a fullscreen toggle: a hand-rolled
`L.Control` on Leaflet (`addLeafletFullscreenControl()` — there's no Leaflet.fullscreen plugin dependency,
just the native Fullscreen API on the `.sc-leaflet-map` container, plus `map.invalidateSize()` on
`fullscreenchange` so Leaflet redraws at the new size) and Google Maps' own built-in `fullscreenControl`. The
`:fullscreen` CSS override forces the container to `100vw`/`100vh`, taking priority over the inline
height/width the shortcode sets — needs `!important` since inline styles otherwise win.

**Spotter reports have no real "type" field.** Spotter Network's own `report_type` CSV column is
hardcoded to only accept the value `'S'` at import time (`handle_spotter_reports_upload()`,
`includes/post-types/storm_chase.php`) and the resulting `spotter_reports[].type` field is never read
anywhere else — it's effectively dead. The human-facing weather-type label (Tornado / Hail / Wind /
Funnel Cloud / Wall Cloud / Damage / generic "Report") is instead **derived** from the independent
`tornado`/`hailsize`/`windspeed`/`funnelcloud`/`wallcloud`/`damage` fields, in that priority order (a
report can have more than one flag set — the highest-priority one wins for label/icon purposes, though
all set flags still show individually in the report's modal). `sc_report_weather_type()` in
`functions.php` is the single source of truth for both the label and the icon slug — replaced three
previously-duplicated inline copies of this same priority-ternary chain (two in `sc_reports_shortcode()`,
one in `display_storm_chase_data()` in `storm_chase.php`) that had already drifted slightly (the
`storm_chase.php` copy wasn't translation-wrapped and used `'Other'` instead of `'Report'` as its
fallback — now consistent everywhere). `[sc_reports]` markers use one PNG per slug
(`assets/images/report-{tornado,hail,wind,funnel,wallcloud,damage,generic}.png`, 32x32, hand-drawn via
GD as a colored circular badge with a white glyph — not a recolor of any existing asset, since these are
different silhouettes per type rather than one shape recolored like the EF-rating icons) — same
`tornadoIconBase`-relative URL scheme as the tornado map (`REPORT_ICON_SLUGS` in `frontend.js`,
`markerIconSpec()` picks the right icon set by `mapType` for both Leaflet and Google Maps). `[sc_reports]`
also gets an icon legend now, same markup/CSS as the tornado map's EF legend (`.sc-map-legend*` classes —
renamed from `.sc-tornado-legend*` since both maps use it now).

### Gutenberg blocks (`blocks/`, `includes/blocks.php`)

`[sc_tornado_map]`, `[sc_reports]`, and `[scstats]` (its tornado-list and chase-stats modes) each have a
block-editor equivalent — `stormchases/tornado-map`, `stormchases/spotter-reports`,
`stormchases/tornado-list`, `stormchases/chase-stats` — added purely additively; the shortcodes are
unchanged and still the primary path today. The intent is to eventually retire the shortcodes in a
future v2.0.0 once the blocks have had a release cycle to prove out, but that hasn't happened yet —
don't remove or deprecate the shortcodes without being asked. `[scarchive]` has no block equivalent yet.

**Shared render functions, not duplicated logic.** Both the shortcode and the matching block's
`render.php` call the same underlying function in `functions.php` — `sc_render_tornado_map()`,
`sc_render_spotter_reports()`, `sc_render_tornado_list()`, `sc_render_chase_stats()` — each taking a
`year`/`heading` (custom text override, `''` = the original smart default like "All Tornadoes Map")/
`show_heading` (bool) argument shape. `sc_render_tornado_map()` additionally takes `height` (px) and
`ratings` (array of EF slugs to include; empty = show all — this is the "Filter by EF Rating" checkbox
group in the map block's Inspector Controls, letting only a subset of severities be shown, e.g.
EF-4/EF-5 only); `sc_render_spotter_reports()` takes the same `height` plus an analogous `types` filter
(array of report-type slugs — see the report-type paragraph above). `sc_tornado_ef_slug()` maps a
tornado's raw stored `ef_rating` (`'EF-5'`, `'EF-U'`, `'Unrated'`, …) to the `ef5`…`ef0`/`unrated` slug
used for both the ratings filter and icon filenames — keep it in sync with `EF_ICON_SLUG` in
`frontend.js` if either changes. The shortcodes themselves are now thin wrappers around these functions
with their historical default args (`show_heading=true`, `heading=''`, and for the two maps,
`height=500`/`ratings=[]`/`types=[]` i.e. unfiltered) — byte-identical output to before this split.
Transient caching for the list/stats renderers uses `sc_stats_transient_suffix()`, which only appends a
hash suffix when `heading`/`show_heading` deviate from those shortcode defaults — default (shortcode)
calls keep the exact original cache key (`storm_chases_stats_{year|all}[_tornadoes]`), so
`StormChaseTemplate::clear_transients()`'s wildcard delete against `storm_chases_stats_%` still catches
every variant regardless of suffix. (`sc_render_tornado_map()`/`sc_render_spotter_reports()` aren't
transient-cached at all — matches the pre-existing, uncached behavior of both shortcodes.)

**No build step** (per Development Commands above) means block editor JS can't use JSX or rely on an
auto-generated `index.asset.php` dependency manifest — `blocks/shared/editor-common.js` is plain
`wp.element.createElement` calls exposing `window.StormChasesBlocks.registerServerRenderedBlock(config)`,
a small factory (Inspector Controls fields → `ServerSideRender` preview) that each block's own
`blocks/{name}/index.js` calls with just its field list, instead of four near-copies of the same
PanelBody/TextControl/RangeControl/CheckboxControl/ServerSideRender boilerplate. `includes/blocks.php`
registers that shared script and each block's `index.js` by hand via `wp_register_script()` with an
explicit dependency array (`wp-blocks`, `wp-element`, `wp-block-editor`, `wp-components`,
`wp-server-side-render`, `wp-i18n`) — each block's `block.json` references its script by the registered
handle name (not a `file:` path), which tells WP to use the already-registered script rather than trying
to auto-register one from a path with an auto-detected dependency list that doesn't exist here.

Each block is **dynamic/server-rendered** via WP 6.1's block.json `"render": "file:./render.php"` field
(no PHP `render_callback` registration needed — WP includes the file with `$attributes` already in
scope and captures its output), which is also what backs the editor's live `ServerSideRender` preview
(calling WP core's built-in `/wp/v2/block-renderer/{name}` REST endpoint — no custom REST code needed).
This works cleanly for the Tornado List and Chase Stats blocks (pure server-rendered HTML/text), and for
the Spotter Reports block's report list portion. **Both map blocks' (Tornado Map, Spotter Reports)
editor preview of the map itself is only partial**: `ServerSideRender`'s REST call renders the
container/legend/modal markup correctly, but Leaflet.js isn't loaded into that request's context, so the
interactive map itself doesn't visually initialize inside the editor canvas (shows as an empty box where
the map would render) — the real map works correctly once the page is published/previewed normally,
since that's a regular page load where `sc_enqueue_map_assets()` runs as usual. Making the editor
preview fully live would need MutationObserver-based re-init logic watching `ServerSideRender`'s async
DOM updates; not implemented.

### Routing

The custom rewrite rule `^chases/([0-9]{8})/?$` → `index.php?post_type=storm_chase&name=$matches[1]` (added in
`init()`) is what makes `/chases/20260716/` resolve — the date-string slug **is** the lookup key, which is why
slug/chasedate/post_date must always agree.

### Caching

`[scarchive]` and `[scstats]` output is cached in transients keyed by their shortcode attributes (year, count,
etc.). `StormChaseTemplate::clear_transients()` is a blunt instrument: passing post IDs only adds *more*
targeted deletes, it doesn't scope the operation — the same call always also wipes every transient matching
`storm_chases_stats_%`, `storm_chases_archive_%`, `sc_archive_v2_%`, and `sc_stats_v12_%` site-wide. Called
after any chase save, GPS upload, or trash.

### Debug logging

`Storm_Chases::debug_log()` is a no-op unless both `WP_DEBUG` and `WP_DEBUG_LOG` are true — safe to leave
calls in place; they won't fire on a production site with debug logging off.

## Feature Requirements

Edit this section directly to add or change requirements — reference it before implementing related changes.

### Chase log fields
- Required: Chase Date (auto from publish date, `YYYYMMDD`), States Chased.
- Optional, each independently toggleable in Settings, **default on**: Miles Logged, States Chased,
  Tornadoes, Wind, Hail, Milestones, Spotter Network Reports.
- Optional, **default off**: Chase Partners, Chasers Encountered, Best Chase of the Season, Windshields
  Replaced, Google Maps (map provider defaults to OpenStreetMap regardless).
- Chase Milestones is a bullet-point list (array of strings), not a paragraph field — one bullet per
  milestone, editor supports Enter-to-add.

### Tornado entries
- Reorderable by drag handle or up/down arrows; display order on the public chase page matches editor order,
  top entry first.
- Collapse to a one-line "Name — EF Rating" summary when not being edited; previously-saved entries load
  collapsed, newly-added entries start expanded. A "Done" button (or clicking the summary again) collapses.
- Fields: Name, Latitude, Longitude, EF Rating, Start Time, End Time, End Latitude, End Longitude, Photo
  (media library), Photogenic flag.
- Latitude/Longitude and End Latitude/End Longitude can each be filled by clicking a point on a map instead
  of typing coordinates by hand ("📍 Pick Location on Map" / "📍 Pick End Location on Map"), rounded to 4
  decimal places (~36 ft), matching the precision of the manual number inputs (`step="0.0001"`).

### GPS tracks
- Accepted formats: `.kml`, `.gpx`, `.nmea`. Multiple files for one chase are merged into one track.
- A time gap between merged files greater than ~2 minutes (or 15× the sampling interval) draws as a visible
  break in the line ("GPS signal lost here"), not a jump line.
- Any single point implying a jump of more than 10 miles from the previous accepted point is discarded, not
  drawn — GPS receiver glitches must never appear on the map.
- GPS track files bypass the image upload size limit (governed by PHP's `upload_max_filesize` instead) and
  are always accepted regardless of the configured MIME allowlist.
- Public track view includes a start→end scrubber showing percentage-through-track only (no lat/lon), plus
  Play/Pause and speed (+/-, 0.25x-8x) controls that animate the scrubber automatically
  (`setupTrackSlider()` in `frontend.js` — tick interval is fixed, speed instead scales how many track
  points are advanced per tick, so playback duration at 1x is roughly constant regardless of how many
  points a given track has). Dragging the scrubber manually stops playback.
- Chase map renders after the recap summary and Severe Risk/Reports links, above the full write-up — not at
  the top of the page.
- Privacy zones (named lat/lon + radius, configured in Settings) strip matching points from the start/end of
  a track only; points mid-route are always kept.
- A "Show radar" toggle on tracks that have point timestamps overlays historical NEXRAD composite
  reflectivity (national mosaic, not single-site raw data), synced to the scrubber's current position and
  updating automatically as it's dragged or played. Tracks uploaded before this feature existed don't have
  per-point timestamps and can't offer it without being re-uploaded. Checked by default — but on initial
  page load (before Play or manual dragging), only a single frame is ever fetched — see
  `createRadarController()` below for why that stays true even with playback buffering.
- Playback buffering: pressing Play triggers `setupTrackSlider()`'s `bufferAhead()`, which pre-fetches (via
  `createRadarController()`'s exposed `update.prefetch()`/`update.isCached()`, not the normal
  apply-to-the-map `update()` path) up to `PLAYBACK_LOOKAHEAD_BUCKETS` (6) upcoming distinct radar frames
  before ticking actually starts, showing "Buffering radar… N/M" via `#sc-track-buffer-status`. While
  playing, `topUpLookahead()` keeps at most one background prefetch in flight so a fast connection stays
  ahead of playback; if a slow connection falls behind anyway, `tick()` detects the upcoming frame isn't
  cached yet and pauses to re-buffer rather than showing a stale/blank frame. Nothing is fetched ahead of
  time from just viewing the page or dragging the scrubber manually — buffering only ever starts once Play
  is pressed, which is what keeps the toggle's default-on behavior (above) bandwidth-cheap for visitors who
  never use playback. `playGeneration` (bumped on every user-initiated stop/cancel) guards against a
  buffering pass that's already been abandoned from resuming playback or clobbering a newer pass's UI state
  once its now-irrelevant fetches finish.
- Stored timestamps are intentionally sparse (~every 10 minutes per segment, rounded to the nearest 5) rather
  than one per point — `chasemap_track` is public page data, and a real timestamp on every point would
  expose precise time-of-day for the whole track to anyone viewing page source. The gaps are estimated
  client-side by interpolation for radar-frame selection only; the estimate is never stored.

### Settings page
- Every feature toggle's checkbox state must reflect its real effective value (registered default or saved
  value) — never a hardcoded fallback that could show "off" for a feature that's actually on.
- GPS track privacy zones and windshield-replacement entries are managed inline on the Settings page via
  AJAX (add/delete), not through the standard WordPress Settings API form.

## Planned / Roadmap

Not yet implemented — captured here so the design isn't lost, deliberately held off implementing so it
doesn't hold up the 1.9.0 release. Read this section before starting the work; it also lists open questions
that need answers first.

### Chase Type (Convective / Hurricane / Snow / Other) and hurricane landfalls

Today the plugin has one implicit chase model: severe convective weather (tornadoes, hail, wind). The plan
is to add an explicit **Chase Type** classification per chase — likely `Convective` (the default, matching
every existing chase's current behavior unchanged), `Hurricane`, `Snow`, and possibly `Other` — that
conditionally shows/hides type-specific fields in the admin edit screen.

- **Hurricane chases** get a **Landfall** sub-object: hurricane name, measured pressure, wind speed at
  landfall, and a lat/lon of the landfall point — the lat/lon should plot on `[sc_tornado_map]` (and its
  block equivalent) using a distinct hurricane icon, alongside tornado markers, not as a separate map. A
  hurricane-type chase should still allow logging tornado entries (hurricanes spawn tornadoes) — Chase Type
  is not meant to hide/disable the existing `tornadoes` array, only to add hurricane-specific fields
  alongside it. Hurricane chases also get a "Highest Wind Gust" field.
- **Snow chases** get their own set of fields, analogous in spirit to the convective fields (`chasehail`,
  `chasewind`, etc.) but for winter weather — the exact field list hasn't been decided yet (see open
  questions).
- **Convective** (the default) and **Other** presumably need no new fields — Other is a catch-all for chases
  that don't fit the other three.

**Architecture, following existing patterns:**
- New top-level `chase_data` fields: `chase_type` (string, one of the enum values, default `'Convective'`
  for both new and existing chases) plus a `landfall` array field. Follow the `tornadoes`/`spotter_reports`
  pattern (`StormChasesData::$meta_fields` in `includes/data.php`, bypassing the generic per-field
  sanitization loop with a dedicated `sanitize_landfall_data()` method — mirror
  `sanitize_tornado_data()`) rather than the generic string-sanitization branch.
  Any new snow-specific fields would follow the same pattern.
- **Admin UI**: unlike most existing feature toggles (which only gate the public-facing recap — see
  `display_storm_chase_data()`), this needs to conditionally show/hide fields **in the edit screen itself**
  based on the live value of a "Chase Type" `<select>` — closer to `best_chase_enable`'s admin-`<tr>`-wrap
  precedent (`templates/stormchases.php`) than the typical toggle, but reactive to a field value instead of
  a static settings option, so it needs new `admin.js` JS (show/hide the Landfall / Snow field groups on
  `change` of the Chase Type select, not just a server-rendered conditional).
- A **Landfall entry UI** would follow the tornado-entry repeatable-array-field pattern exactly: a container
  in `templates/stormchases.php` plus a matching AJAX-added-entry copy (`Storm_Chases::get_tornado_entry()`'s
  counterpart) in `stormchases.php`, both using the shared `#sc-location-picker-modal` for the landfall
  lat/lon via the same `📍 Pick Location on Map` button pattern. Per `CLAUDE.md`'s existing warning about the
  two tornado-entry templates drifting once already, be careful to build this as a single shared pattern
  from the start if at all possible.
- **Map integration**: `sc_render_tornado_map()` (`includes/functions.php`) would need to also plot landfall
  points (a new icon, hand-drawn the same way as `assets/images/report-*.png` — a colored badge or similar,
  not a recolor of the tornado icon since it's a different phenomenon) alongside tornado markers — probably
  as another entry in the existing marker-type system (`markerIconSpec()` in `frontend.js`) rather than a
  separate map/shortcode.
- Consider a Settings-page toggle (`chase_type_enable` or similar, following the pattern in Feature
  Requirements → Settings page above) to let the site owner turn this whole feature off if unused — most of
  the existing optional fields follow this pattern already.

**Open questions to resolve before implementing** (ask the user, don't guess):
1. Exact field list for Snow chases — what should actually be captured? (snowfall total? duration? road
   conditions? something else?)
2. Is "Highest Wind Gust" (hurricane) a genuinely new field, or should it reuse the existing `chasewind`
   ("Highest Wind Observed") field with different labeling/semantics for hurricane-type chases?
3. Should Chase Type be changeable after a chase is saved (e.g. someone realizes mid-edit their "Convective"
   chase was actually storm damage from a landfalling hurricane), and if so, does changing away from
   Hurricane/Snow need to warn about or preserve the now-hidden field data rather than silently discarding it?
4. Multiple landfalls per chase (a chase spanning a hurricane making landfall more than once) — array like
   `tornadoes`, or a single landfall object? The tornado precedent (an array) suggests array, but confirm.
5. Does this need its own dedicated Gutenberg block(s) (e.g. a "Hurricane Landfall" or "Storm Details" block
   mirroring the pattern in `blocks/`), or is it purely an admin-side data-entry feature with no new
   public-facing shortcode/block of its own (landfalls surface only via the tornado map)?

## Known Issues / Inconsistencies

- **Duplicate settings-page menu registration.** `Storm_Chases_Settings::add_menu()` (`includes/settings.php`)
  registers a submenu under `edit.php?post_type=storm_chases` — plural, which doesn't match the actual
  registered post type (`storm_chase`, singular) — so it's not reachable from the admin menu. The real,
  linked-to settings page is `StormChaseTemplate::add_settings_page()` (`includes/post-types/storm_chase.php`),
  under `edit.php?post_type=storm_chase&page=storm-chases-settings`. Both point their `render_settings_page()`
  callback at the same `templates/admin/settings.php`, so the page itself works either way — the dead
  registration is otherwise-harmless clutter, not a functional bug.
- **`wp_load_alloptions()` returns raw, still-serialized values**, not the unserialized values `get_option()`
  gives you — it bit the Settings page once already for an array-typed option. Any array/object-valued
  option must be read with `get_option()`, never pulled out of the `wp_load_alloptions()` result directly.
  Relatedly: call `get_option($name)` **without** an explicit second argument if you want it to honor a
  default registered via `register_setting()` — passing an explicit default (even one matching the
  registered value) bypasses the `default_option_{$name}` filter WordPress uses for that.
