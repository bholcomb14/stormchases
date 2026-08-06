<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">StormChases</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>This site runs on <strong>StormChases</strong>, a WordPress plugin I built to log storm chase days the way I actually think about them — date, states, miles, hail, wind, tornadoes witnessed, a GPS track, and the Spotter Network reports that went with it. It's open source, free to use on your own WordPress site, and available on GitHub.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://github.com/bholcomb14/stormchases">Get it on GitHub</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:heading -->
<h2 class="wp-block-heading">What it does</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><strong>Chase logs</strong> as a custom post type, accessible at <code>/chases/YYYYMMDD/</code> — states chased, partners, miles, largest hail, highest wind, and a bullet-point list of milestones.</li>
<li><strong>Tornado entries</strong> with name, EF rating, start/end coordinates and times, and a photo — reorderable by drag or arrow keys, with a click-to-place map for coordinates instead of typing lat/lon by hand.</li>
<li><strong>GPS track upload</strong> (KML, GPX, or NMEA) with a start&nbsp;→&nbsp;end scrubber — drag it manually or hit Play for automatic, speed-adjustable playback — automatic gap detection where signal was lost, outlier rejection for bad GPS fixes, and a historical NEXRAD radar overlay synced to wherever the scrubber is positioned.</li>
<li><strong>Spotter Network report import</strong> via CSV, matched automatically to the right chase day.</li>
<li><strong>Interactive maps</strong> — every tornado colored by EF rating, every spotter report colored by type (tornado, hail, wind, funnel cloud, wall cloud, damage) — on OpenStreetMap or Google Maps, with fullscreen and clickable detail popups.</li>
<li><strong>Chase statistics</strong> — totals for chase days, tornado days, miles, hail, wind, and more, for all time or any single year.</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Shortcodes and blocks</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Everything below is available both as a classic shortcode and as a Gutenberg block (search "Tornado Map," "Spotter Reports," "Tornado List," or "Chase Stats" in the block inserter) — the blocks add sidebar controls for things like EF-rating or report-type filtering that aren't available as shortcode attributes.</p>
<!-- /wp:paragraph -->

<!-- wp:code -->
<pre class="wp-block-code"><code>[scarchive]          List of chases, filterable by year or count
[scstats]            Aggregated chase statistics
[sc_tornado_map]     Interactive map of all tornadoes, colored by EF rating
[sc_reports]         Interactive map + list of Spotter Network reports</code></pre>
<!-- /wp:code -->

<!-- wp:heading -->
<h2 class="wp-block-heading">See it in action</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>This is live data from this site — remove this section if you'd rather link out to an existing archive/map page instead of duplicating it here.</p>
<!-- /wp:paragraph -->

<!-- wp:shortcode -->
[scstats]
<!-- /wp:shortcode -->

<!-- wp:shortcode -->
[sc_tornado_map]
<!-- /wp:shortcode -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Get it for your own site</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>StormChases requires WordPress 6.1+ and PHP 7.4+. Download it from <a href="https://github.com/bholcomb14/stormchases">GitHub</a>, upload the <code>stormchases</code> folder to <code>/wp-content/plugins/</code> (or upload the ZIP directly through <strong>Plugins&nbsp;→&nbsp;Add New</strong>), then activate it and visit <strong>Storm Chases&nbsp;→&nbsp;Settings</strong> to choose a map provider and enable the fields you want. It's released under the <a href="https://www.gnu.org/licenses/gpl-2.0.html">GPLv2 license</a> — free to use, modify, and redistribute.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Found a bug or have a feature idea? Issues and pull requests are welcome on <a href="https://github.com/bholcomb14/stormchases">GitHub</a>.</p>
<!-- /wp:paragraph -->
