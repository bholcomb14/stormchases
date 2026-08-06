(function($) {
    'use strict';

    let mapsEnabled = stormChasesFrontend.googleMapsEnabled || false;
    const mapProvider = stormChasesFrontend.mapProvider || 'openstreetmap';
    const debugMode = false;

    // -----------------------------------------------------------------------
    // Modal helpers — enforce one-open-at-a-time across all modal types
    // -----------------------------------------------------------------------

    // clickPos (optional): {x, y} viewport coordinates (e.g. a map marker click) —
    // when given, the modal pops up next to that point instead of centered.
    function openModal(modalId, clickPos) {
        closeAllModals();
        const modal = $('#' + modalId);
        if (!modal.length) {
            if (debugMode) { console.warn('Modal not found: ' + modalId); }
            return;
        }
        const $content = modal.find('.modal-content');
        if (clickPos) {
            modal.addClass('sc-modal-positioned').css('display', 'block').css('visibility', 'hidden');
            const vw = $(window).width();
            const vh = $(window).height();
            const w  = $content.outerWidth();
            const h  = $content.outerHeight();
            let left = clickPos.x - w / 2;
            let top  = clickPos.y - h - 16;
            left = Math.max(10, Math.min(left, vw - w - 10));
            if (top < 10) { top = clickPos.y + 20; }
            top = Math.max(10, Math.min(top, vh - h - 10));
            $content.css({left: left + 'px', top: top + 'px'});
            modal.css('display', 'none').css('visibility', '');
        } else {
            modal.removeClass('sc-modal-positioned');
            $content.css({left: '', top: ''});
        }
        modal.fadeIn(200);
        modal.find('.modal-close').first().focus();
    }

    function closeAllModals() {
        $('.modal').fadeOut(200);
    }

    $(document).ready(function() {
        initializeModals();
        initializeMapShortcodes();
        initializeChaseMap();
    });

    function initializeModals() {
        $(document).off('click.scModalLink').on('click.scModalLink', '.tornado-link, .report-link, .sc-report-link', function(e) {
            e.preventDefault();
            openModal($(this).data('modal-id'));
        });

        // Clicking anywhere on a modal (the X, the dimmed backdrop, or the content
        // itself) closes it.
        $(document).off('click.scModalClose').on('click.scModalClose', '.modal-close, .modal-overlay, .modal-content', function() {
            closeAllModals();
        });

        $(document).off('keydown.scModal').on('keydown.scModal', function(e) {
            if (e.key === 'Escape') {
                closeAllModals();
            }
        });
    }

    // -----------------------------------------------------------------------
    // Shortcode map initializer — picks up every .sc-leaflet-map on the page
    // -----------------------------------------------------------------------

    const TORNADO_ICON_BASE = stormChasesFrontend.tornadoIconBase || '';
    // Displayed size on the map — smaller than the packaged 40x36 source PNGs, which
    // are 2x resolution so this stays crisp instead of blurring a tiny asset upward.
    const TORNADO_ICON_SIZE = [26, 23];
    const REPORT_ICON_SIZE = [24, 24];

    // Higher-rated tornadoes stack on top of lower-rated ones when markers
    // overlap at low zoom, and each rating gets its own pre-colored icon.
    const EF_RANK = {'EF-5': 5, 'EF-4': 4, 'EF-3': 3, 'EF-2': 2, 'EF-1': 1, 'EF-0': 0, 'EF-U': -1, 'Unrated': -1};
    const EF_ICON_SLUG = {
        'EF-5': 'ef5',
        'EF-4': 'ef4',
        'EF-3': 'ef3',
        'EF-2': 'ef2',
        'EF-1': 'ef1',
        'EF-0': 'ef0',
    };
    // Keep in sync with sc_report_weather_type()'s slugs in functions.php.
    const REPORT_ICON_SLUGS = ['tornado', 'hail', 'wind', 'funnel', 'wallcloud', 'damage', 'generic'];

    function tornadoRank(ef) {
        return EF_RANK.hasOwnProperty(ef) ? EF_RANK[ef] : -1;
    }

    function tornadoIconUrl(ef) {
        return TORNADO_ICON_BASE + 'tornado-' + (EF_ICON_SLUG[ef] || 'unrated') + '.png';
    }

    function reportIconUrl(reportType) {
        const slug = REPORT_ICON_SLUGS.indexOf(reportType) !== -1 ? reportType : 'generic';
        return TORNADO_ICON_BASE + 'report-' + slug + '.png';
    }

    // Returns {url, size} for a marker's custom icon, or null to fall back to
    // the map provider's default pin (used by neither current map type, but
    // keeps this function safe to call for any future mapType).
    function markerIconSpec(mapType, pt) {
        if (mapType === 'tornado') {
            return {url: tornadoIconUrl(pt.ef), size: TORNADO_ICON_SIZE};
        }
        if (mapType === 'reports') {
            return {url: reportIconUrl(pt.reportType), size: REPORT_ICON_SIZE};
        }
        return null;
    }

    // Ascending by severity so higher-rated tornadoes are drawn (and z-index'd) last/on top
    function orderBySeverity(points, mapType) {
        if (mapType !== 'tornado') { return points; }
        return points.slice().sort(function(a, b) { return tornadoRank(a.ef) - tornadoRank(b.ef); });
    }

    function initializeMapShortcodes() {
        $('.sc-leaflet-map').each(function() {
            const $el     = $(this);
            const mapId   = $el.attr('id');
            const mapType = $el.data('map-type');
            const points  = (window.scMapData && window.scMapData[mapId]) ? window.scMapData[mapId] : [];

            if (!points.length) {
                $el.html('<p style="padding:10px;">' + 'No location data available for this map.' + '</p>');
                return;
            }

            if (mapProvider === 'google') {
                initGoogleMapShortcode($el[0], points, mapType);
            } else {
                initLeafletMapShortcode($el[0], points, mapType);
            }
        });
    }

    // Toggles the native Fullscreen API on a map container (works for any
    // provider); shared by the Leaflet custom control below.
    function toggleMapFullscreen(container) {
        const fsElement = document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
        if (!fsElement) {
            const req = container.requestFullscreen || container.webkitRequestFullscreen || container.msRequestFullscreen;
            if (req) { req.call(container); }
        } else {
            const exit = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
            if (exit) { exit.call(document); }
        }
    }

    function addLeafletFullscreenControl(map, container) {
        const FullscreenControl = L.Control.extend({
            options: {position: 'topleft'},
            onAdd: function() {
                const bar  = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                const link = L.DomUtil.create('a', 'sc-map-fullscreen-btn', bar);
                link.href = '#';
                link.title = 'Toggle fullscreen';
                link.setAttribute('role', 'button');
                link.setAttribute('aria-label', 'Toggle fullscreen');
                link.innerHTML = '&#9974;';
                L.DomEvent.disableClickPropagation(bar);
                L.DomEvent.on(link, 'click', function(e) {
                    L.DomEvent.preventDefault(e);
                    toggleMapFullscreen(container);
                });
                return bar;
            },
        });
        map.addControl(new FullscreenControl());

        ['fullscreenchange', 'webkitfullscreenchange', 'MSFullscreenChange'].forEach(function(evt) {
            document.addEventListener(evt, function() {
                setTimeout(function() { map.invalidateSize(); }, 60);
            });
        });
    }

    function initLeafletMapShortcode(container, points, mapType) {
        if (typeof L === 'undefined') {
            container.innerHTML = '<p style="padding:10px;">Leaflet map library not loaded.</p>';
            return;
        }

        const map = L.map(container).setView([39.8283, -98.5795], 4);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" rel="noopener noreferrer">OpenStreetMap</a> contributors',
            maxZoom: 18,
        }).addTo(map);

        addLeafletFullscreenControl(map, container);

        const boundsGroup = [];
        orderBySeverity(points, mapType).forEach(function(pt) {
            const markerOpts = {};
            const iconSpec = markerIconSpec(mapType, pt);
            if (iconSpec) {
                markerOpts.icon = L.icon({
                    iconUrl: iconSpec.url,
                    iconSize: iconSpec.size,
                    iconAnchor: [iconSpec.size[0] / 2, iconSpec.size[1] / 2],
                    popupAnchor: [0, -iconSpec.size[1] / 2],
                });
            }
            if (mapType === 'tornado') {
                markerOpts.zIndexOffset = tornadoRank(pt.ef) * 10000;
            }
            const marker = L.marker([pt.lat, pt.lon], markerOpts).addTo(map);
            marker.bindTooltip(pt.label, {permanent: false, direction: 'top', className: 'sc-marker-tooltip'});
            marker.on('click', function(e) {
                const oe = e.originalEvent;
                openModal(pt.modalId, oe ? {x: oe.clientX, y: oe.clientY} : null);
            });
            boundsGroup.push([pt.lat, pt.lon]);
        });

        if (boundsGroup.length > 1) {
            map.fitBounds(boundsGroup, {padding: [30, 30]});
        } else if (boundsGroup.length === 1) {
            map.setView(boundsGroup[0], 8);
        }
    }

    function initGoogleMapShortcode(container, points, mapType) {
        if (typeof google === 'undefined' || !google.maps) {
            container.innerHTML = '<p style="padding:10px;">Google Maps not loaded.</p>';
            return;
        }

        const map = new google.maps.Map(container, {
            center: {lat: 39.8283, lng: -98.5795},
            zoom: 4,
            mapTypeId: google.maps.MapTypeId.ROADMAP,
            fullscreenControl: true,
        });

        const bounds = new google.maps.LatLngBounds();
        orderBySeverity(points, mapType).forEach(function(pt) {
            const iconSpec = markerIconSpec(mapType, pt);
            const icon = iconSpec ? {
                url: iconSpec.url,
                scaledSize: new google.maps.Size(iconSpec.size[0], iconSpec.size[1]),
                anchor: new google.maps.Point(iconSpec.size[0] / 2, iconSpec.size[1] / 2),
            } : undefined;
            const marker = new google.maps.Marker({
                position: {lat: pt.lat, lng: pt.lon},
                map: map,
                title: pt.label,
                icon: icon,
                zIndex: mapType === 'tornado' ? tornadoRank(pt.ef) * 10000 : undefined,
            });
            marker.addListener('click', function(event) {
                const de = event.domEvent;
                openModal(pt.modalId, de ? {x: de.clientX, y: de.clientY} : null);
            });
            bounds.extend({lat: pt.lat, lng: pt.lon});
        });

        if (points.length > 1) {
            map.fitBounds(bounds);
        } else if (points.length === 1) {
            map.setCenter({lat: points[0].lat, lng: points[0].lon});
            map.setZoom(8);
        }
    }

    // -----------------------------------------------------------------------
    // Track segment helpers (null = "lift the pen" / gap sentinel)
    // -----------------------------------------------------------------------

    // Returns {points: [[lat,lon],...], indices: [rawIdx,...]} for non-null elements
    function buildPointIndex(rawTrack) {
        var pts = [], idxs = [];
        for (var i = 0; i < rawTrack.length; i++) {
            if (rawTrack[i] !== null) {
                pts.push([rawTrack[i][0], rawTrack[i][1]]);
                idxs.push(i);
            }
        }
        return { points: pts, indices: idxs };
    }

    // Build polyline-ready segments from rawTrack[0..upToRawIdx], splitting on nulls
    function buildSegments(rawTrack, upToRawIdx) {
        var segs = [], cur = [];
        for (var i = 0; i <= upToRawIdx && i < rawTrack.length; i++) {
            var pt = rawTrack[i];
            if (pt === null) {
                if (cur.length) { segs.push(cur); cur = []; }
            } else {
                cur.push([pt[0], pt[1]]);
            }
        }
        if (cur.length) segs.push(cur);
        return segs;
    }

    // -----------------------------------------------------------------------
    // Historical radar overlay — NEXRAD composite reflectivity archive
    // (Iowa Environmental Mesonet, mesonet.agron.iastate.edu). Fetched directly
    // from the browser at a fixed 5-minute cadence matching the archive's own
    // update interval; no server-side involvement. n0q (~500m/px) covers roughly
    // 2013 onward; n0r (~1km/px, coarser) is used as a fallback for older dates.
    //
    // Only a sparse subset of points (roughly every 10 minutes) carry a real
    // timestamp — the rest are nulled out server-side before the track is ever
    // stored, so the public page doesn't expose precise time-of-day for every
    // point. estimateTimestamp() interpolates the gap from point position
    // purely to pick a radar frame; the estimate is never stored anywhere.
    // -----------------------------------------------------------------------

    function estimateTimestamp(rawTrack, rawIdx) {
        var pt = rawTrack[rawIdx];
        if (pt && pt[2]) { return pt[2]; }

        var prev = null;
        for (var i = rawIdx - 1; i >= 0; i--) {
            if (rawTrack[i] === null) { break; } // don't cross a gap segment boundary
            if (rawTrack[i][2]) { prev = { idx: i, ts: rawTrack[i][2] }; break; }
        }
        var next = null;
        for (var j = rawIdx + 1; j < rawTrack.length; j++) {
            if (rawTrack[j] === null) { break; }
            if (rawTrack[j][2]) { next = { idx: j, ts: rawTrack[j][2] }; break; }
        }

        if (prev && next) {
            var frac = (rawIdx - prev.idx) / (next.idx - prev.idx);
            return Math.round(prev.ts + (next.ts - prev.ts) * frac);
        }
        return prev ? prev.ts : (next ? next.ts : null);
    }

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    // Floors a Unix-seconds timestamp to the archive's 5-minute cadence and
    // returns its {yyyy, mm, dd, hh, mi} UTC parts. Rounds to the *nearest* 5-minute
    // mark (not floor) by rounding the epoch value itself before splitting into
    // calendar fields — flooring always shows a frame 0-5 min stale; rounding keeps
    // the error to at most ~2.5 min either way, and rounding the epoch (rather than
    // the minutes field) sidesteps any hour/day/month rollover edge cases.
    function radarBucketParts(ts) {
        var rounded = Math.round(ts / 300) * 300;
        var d = new Date(rounded * 1000);
        return {
            yyyy: d.getUTCFullYear(),
            mm: pad2(d.getUTCMonth() + 1),
            dd: pad2(d.getUTCDate()),
            hh: pad2(d.getUTCHours()),
            mi: pad2(d.getUTCMinutes()),
        };
    }

    function radarBucketKey(ts) {
        return Math.round(ts / 300); // nearest 5-minute bucket, matching radarBucketParts()
    }

    function radarBucketUrl(product, ts) {
        var p = radarBucketParts(ts);
        return 'https://mesonet.agron.iastate.edu/archive/data/' + p.yyyy + '/' + p.mm + '/' + p.dd +
            '/GIS/uscomp/' + product + '_' + p.yyyy + p.mm + p.dd + p.hh + p.mi + '.png';
    }

    // Same archive frame's ESRI world file — see loadAndCropRadar() for why this is
    // fetched per-request instead of trusting a fixed set of bounds.
    function radarWldUrl(product, ts) {
        var p = radarBucketParts(ts);
        return 'https://mesonet.agron.iastate.edu/archive/data/' + p.yyyy + '/' + p.mm + '/' + p.dd +
            '/GIS/uscomp/' + product + '_' + p.yyyy + p.mm + p.dd + p.hh + p.mi + '.wld';
    }

    function radarTimeLabel(ts) {
        var p = radarBucketParts(ts);
        return p.hh + ':' + p.mi + ' UTC';
    }

    // The archive's composite images cover the entire continental US (12200x5400px
    // for n0q). At a chase's typical high zoom level, Leaflet has to CSS-scale that
    // whole image up to several times its native resolution to keep it correctly
    // georeferenced (observed as high as ~11000x6000 *CSS* pixels for a single-county
    // view) — comfortably past common GPU maximum-texture-size limits, which some
    // browser/driver combinations silently mis-render past rather than erroring on.
    // The result: the DOM position and the image's own pixel data both check out
    // correct (confirmed via getBoundingClientRect + direct canvas sampling during
    // debugging), yet what's actually painted to screen can be visibly offset —
    // reproduced identically across three unrelated browser/OS combinations.
    //
    // Fix: never hand the browser more image than it needs. Crop to a generous but
    // bounded region around the chase track (client-side, via canvas) before ever
    // creating the overlay, so its rendered CSS size stays sane regardless of zoom.
    function trackCropBounds(points, padDeg) {
        var south = Infinity, north = -Infinity, west = Infinity, east = -Infinity;
        points.forEach(function(pt) {
            var lat = pt[0] !== undefined ? pt[0] : pt.lat;
            var lon = pt[1] !== undefined ? pt[1] : pt.lng;
            if (lat < south) south = lat;
            if (lat > north) north = lat;
            if (lon < west) west = lon;
            if (lon > east) east = lon;
        });
        return {south: south - padDeg, north: north + padDeg, west: west - padDeg, east: east + padDeg};
    }

    // Loads the full archive image AND its accompanying .wld world file, crops the
    // image (canvas) to cropBounds intersected with the image's own real bounds (as
    // given by the .wld — never assumed), and hands back a small Blob URL + the
    // crop's own precise bounds — ready to use directly as an L.imageOverlay/
    // GroundOverlay source.
    //
    // The source bounds are read from each frame's own .wld rather than a hardcoded
    // constant because IEM's n0q composite grid isn't fixed across history: it grew
    // from 12000x5200px to 12200x5400px sometime between 2014-01-15 and 2014-12-31,
    // shifting its real extent from south=24.0/east=-66.0 to south=23.0/east=-65.0.
    // A hardcoded (current-day) extent silently misplaced pre-2014 frames by tens of
    // miles — exactly the kind of bug this function exists to prevent, so trust the
    // .wld every time instead of re-guessing a cutover date.
    function loadAndCropRadar(pngUrl, wldUrl, cropBounds, onReady, onError) {
        var img = new Image();
        var wld = null; // {pxW, pxH, originX, originY} once the .wld has loaded
        var imgReady = false;
        var settled = false;

        function fail() {
            if (settled) { return; }
            settled = true;
            onError();
        }

        function proceed() {
            if (settled || !imgReady || !wld) { return; }
            try {
                var srcW = img.naturalWidth, srcH = img.naturalHeight;
                var sWest = wld.originX, sNorth = wld.originY;
                var sEast = sWest + srcW * wld.pxW;
                var sSouth = sNorth + srcH * wld.pxH; // pxH is negative per world-file convention
                var cWest  = Math.max(sWest, cropBounds.west);
                var cEast  = Math.min(sEast, cropBounds.east);
                var cSouth = Math.max(sSouth, cropBounds.south);
                var cNorth = Math.min(sNorth, cropBounds.north);
                if (cWest >= cEast || cSouth >= cNorth) { fail(); return; }
                var sx = Math.round((cWest - sWest) / (sEast - sWest) * srcW);
                var ex = Math.round((cEast - sWest) / (sEast - sWest) * srcW);
                var sy = Math.round((sNorth - cNorth) / (sNorth - sSouth) * srcH);
                var ey = Math.round((sNorth - cSouth) / (sNorth - sSouth) * srcH);
                var cw = Math.max(1, ex - sx), ch = Math.max(1, ey - sy);
                var canvas = document.createElement('canvas');
                canvas.width = cw;
                canvas.height = ch;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, sx, sy, cw, ch, 0, 0, cw, ch);
                canvas.toBlob(function(blob) {
                    if (!blob) { fail(); return; }
                    settled = true;
                    onReady(URL.createObjectURL(blob), [[cSouth, cWest], [cNorth, cEast]]);
                });
            } catch (e) {
                fail();
            }
        }

        img.crossOrigin = 'anonymous';
        img.onload = function() { imgReady = true; proceed(); };
        img.onerror = fail;
        img.src = pngUrl;

        fetch(wldUrl).then(function(resp) {
            if (!resp.ok) { throw new Error('wld fetch failed: ' + resp.status); }
            return resp.text();
        }).then(function(text) {
            var nums = text.trim().split(/\s+/).map(parseFloat);
            if (nums.length < 6 || nums.some(isNaN)) { throw new Error('malformed wld'); }
            wld = { pxW: nums[0], pxH: nums[3], originX: nums[4], originY: nums[5] };
            proceed();
        }).catch(fail);
    }

    // Drives the "Show radar" checkbox + label shared by both map providers.
    // setOverlay(url, bounds)/clearOverlay() are provider-specific callbacks.
    // cropBounds ({south,west,north,east}) limits how much of the continental
    // composite ever gets downloaded/rendered — see loadAndCropRadar() above.
    //
    // Two perf measures on top of the crop itself, since cropping still means
    // downloading a several-MB continental image per distinct frame:
    //  - FRAME_CACHE: already-fetched-and-cropped frames are kept (as Blob URLs,
    //    keyed by bucket) for the life of the page, so re-visiting a time you've
    //    already scrubbed past is instant with zero network cost. Capped at
    //    FRAME_CACHE_MAX with oldest-inserted eviction (revoking that entry's Blob
    //    URL) so a very long scrubbing session can't grow this unbounded.
    //  - FETCH_DEBOUNCE_MS: the label updates immediately on every bucket change
    //    (cheap, synchronous), but the actual fetch+crop is deferred until the
    //    position has been stable for a moment — so dragging quickly across many
    //    buckets doesn't fire (and immediately discard) a fetch for every one of
    //    them, only for the bucket the user actually settles on. Cache hits bypass
    //    the debounce entirely since they're free.
    //
    // Dragging can still land back-to-back fetches close enough together to race
    // (e.g. two deliberate slow drags less than FETCH_DEBOUNCE_MS apart) — requestSeq
    // guards against a slower *earlier* request finishing after a faster *later* one
    // and clobbering it. Bumped on every new request; each async callback checks it
    // still matches before applying its result or caching it, so only the most
    // recently *issued* request is ever allowed to actually paint the overlay,
    // regardless of load order.
    var FRAME_CACHE_MAX = 48;
    var FETCH_DEBOUNCE_MS = 150;

    function createRadarController($toggle, $label, setOverlay, clearOverlay, cropBounds) {
        var lastBucket = null;
        var lastTs = null;
        var requestSeq = 0;
        var debounceTimer = null;
        var frameCache = new Map(); // bucket -> {blobUrl, bounds}

        // Claims the cache slot for bucket, or — if a concurrent fetch for the same
        // bucket (e.g. a playback prefetch racing the normal debounced fetch) already
        // won it — discards this duplicate blob and returns the existing entry instead.
        // Always use the returned entry, never the blobUrl/bounds passed in directly,
        // since those may have just been revoked.
        function claimCacheSlot(bucket, blobUrl, bounds) {
            var existing = frameCache.get(bucket);
            if (existing) { URL.revokeObjectURL(blobUrl); return existing; }
            var entry = { blobUrl: blobUrl, bounds: bounds };
            frameCache.set(bucket, entry);
            if (frameCache.size > FRAME_CACHE_MAX) {
                var oldestKey = frameCache.keys().next().value;
                URL.revokeObjectURL(frameCache.get(oldestKey).blobUrl);
                frameCache.delete(oldestKey);
            }
            return entry;
        }

        function cancelPendingFetch() {
            if (debounceTimer) { clearTimeout(debounceTimer); debounceTimer = null; }
        }

        // Fetches+crops one frame and caches it. If apply is true, also paints it via
        // setOverlay once ready (guarded by mySeq so a request superseded by a newer
        // one never paints) — used by both update() (apply=true) and prefetch()
        // (apply=false, just populates the cache for later). onDone(success) always
        // fires exactly once.
        function fetchFrame(bucket, ts, mySeq, apply, onDone) {
            var urlQ = radarBucketUrl('n0q', ts);
            var wldQ = radarWldUrl('n0q', ts);
            loadAndCropRadar(urlQ, wldQ, cropBounds, function(blobUrl, bounds) {
                if (apply && mySeq !== requestSeq) { URL.revokeObjectURL(blobUrl); onDone(false); return; }
                var entry = claimCacheSlot(bucket, blobUrl, bounds);
                if (apply) { setOverlay(entry.blobUrl, entry.bounds); }
                onDone(true);
            }, function() {
                if (apply && mySeq !== requestSeq) { onDone(false); return; }
                var urlR = radarBucketUrl('n0r', ts);
                var wldR = radarWldUrl('n0r', ts);
                loadAndCropRadar(urlR, wldR, cropBounds, function(blobUrl, bounds) {
                    if (apply && mySeq !== requestSeq) { URL.revokeObjectURL(blobUrl); onDone(false); return; }
                    var entry = claimCacheSlot(bucket, blobUrl, bounds);
                    if (apply) { setOverlay(entry.blobUrl, entry.bounds); }
                    onDone(true);
                }, function() {
                    if (apply && mySeq !== requestSeq) { onDone(false); return; }
                    if (apply) {
                        clearOverlay();
                        $label.text('Radar: unavailable for this time');
                    }
                    onDone(false);
                });
            });
        }

        function update(ts) {
            lastTs = ts;
            if (!$toggle.is(':checked') || !ts) {
                cancelPendingFetch();
                if (lastBucket !== null) { clearOverlay(); lastBucket = null; }
                $label.text('');
                requestSeq++;
                return;
            }
            var bucket = radarBucketKey(ts);
            if (bucket === lastBucket) { return; }
            lastBucket = bucket;
            $label.text('Radar: ' + radarTimeLabel(ts));

            cancelPendingFetch();

            var cached = frameCache.get(bucket);
            if (cached) {
                requestSeq++; // invalidate any still-in-flight fetch for a previous bucket
                setOverlay(cached.blobUrl, cached.bounds);
                return;
            }

            var mySeq = ++requestSeq;
            debounceTimer = setTimeout(function() {
                debounceTimer = null;
                fetchFrame(bucket, ts, mySeq, true, function() {});
            }, FETCH_DEBOUNCE_MS);
        }

        // Fetches+caches a frame WITHOUT displaying it — for playback buffering, so a
        // run of upcoming frames can be downloaded ahead of when they're actually
        // needed. No-ops immediately (success) if already cached.
        function prefetch(ts, onDone) {
            if (!ts) { onDone(false); return; }
            var bucket = radarBucketKey(ts);
            if (frameCache.has(bucket)) { onDone(true); return; }
            fetchFrame(bucket, ts, 0, false, onDone);
        }

        function isCached(ts) {
            return !!ts && frameCache.has(radarBucketKey(ts));
        }

        update.prefetch = prefetch;
        update.isCached = isCached;

        $toggle.on('change', function() {
            cancelPendingFetch();
            if (!$toggle.is(':checked')) {
                clearOverlay();
                lastBucket = null;
                $label.text('');
                requestSeq++;
            } else {
                lastBucket = null; // force a redraw at the current position
                update(lastTs);
            }
        });

        return update;
    }

    // -----------------------------------------------------------------------
    // Individual chase page — track polyline or legacy KML overlay
    // -----------------------------------------------------------------------

    function initializeChaseMap() {
        var type  = stormChasesFrontend.chasemapType;
        var track = stormChasesFrontend.chasemapTrack;

        if (type !== '3' || !Array.isArray(track) || track.length < 2) {
            return;
        }

        var container = document.getElementById('chasemap');
        if (!container) {
            return;
        }

        try {
            if (mapProvider === 'google' && mapsEnabled && typeof google !== 'undefined' && google.maps) {
                initGoogleTrack(container, track);
            } else if (typeof L !== 'undefined') {
                initLeafletTrack(container, track);
            } else {
                container.innerHTML = '<p style="padding:10px;color:#c00;">Map library not loaded.</p>';
            }
        } catch(e) {
            console.error('[StormChases] initializeChaseMap error:', e);
            container.innerHTML = '<p style="padding:10px;color:#c00;">Map error: ' + e.message + '</p>';
        }
    }

    function initLeafletTrack(container, rawTrack) {
        if (typeof L === 'undefined') {
            container.innerHTML = '<p style="padding:10px;">Leaflet not loaded.</p>';
            return;
        }
        var pi = buildPointIndex(rawTrack);
        if (pi.points.length < 2) {
            container.innerHTML = '<p style="padding:10px;">Not enough track data.</p>';
            return;
        }
        var map = L.map(container).setView([39.8283, -98.5795], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" rel="noopener noreferrer">OpenStreetMap</a> contributors',
            maxZoom: 18,
        }).addTo(map);

        var trackGroup = L.layerGroup().addTo(map);

        function renderTo(nonNullIdx) {
            trackGroup.clearLayers();
            var rawUpTo = pi.indices[Math.min(nonNullIdx, pi.indices.length - 1)];
            var segs    = buildSegments(rawTrack, rawUpTo);
            var bounds  = L.latLngBounds();
            segs.forEach(function(seg, i) {
                if (seg.length > 1) {
                    L.polyline(seg, { color: '#e63946', weight: 5, opacity: 0.9 }).addTo(trackGroup);
                    seg.forEach(function(pt) { bounds.extend(pt); });
                }
                // Red dot with white ring at each gap point (end of every segment except last)
                if (i < segs.length - 1 && seg.length > 0) {
                    L.circleMarker(seg[seg.length - 1], {
                        radius: 7, color: '#fff', weight: 2,
                        fillColor: '#e63946', fillOpacity: 1,
                    }).bindTooltip('GPS signal lost here', { direction: 'top' }).addTo(trackGroup);
                }
            });
            return bounds;
        }

        var lastIdx = pi.points.length - 1;
        var bounds  = renderTo(lastIdx);
        if (bounds.isValid()) { map.fitBounds(bounds, { padding: [20, 20] }); }

        var carMarker = L.marker(pi.points[lastIdx], {
            icon: leafletArrowIcon(lastIdx > 0 ? trackBearing(pi.points[lastIdx - 1], pi.points[lastIdx]) : 0),
            interactive: false,
            zIndexOffset: 1000,
        }).addTo(map);

        // Radar overlay — only offer it if this track actually has timestamps
        // (older chases stored before this feature existed won't).
        var hasTimestamps = rawTrack.some(function(pt) { return pt && pt[2]; });
        var updateRadar = function() {};
        if (hasTimestamps) {
            var radarLayer = null;
            $('#sc-radar-control').show();
            updateRadar = createRadarController(
                $('#sc-radar-toggle'),
                $('#sc-radar-label'),
                function setOverlay(url, imgBounds) {
                    if (radarLayer) { map.removeLayer(radarLayer); }
                    radarLayer = L.imageOverlay(url, imgBounds, { opacity: 0.65 }).addTo(map);
                    radarLayer.bringToBack();
                },
                function clearOverlay() {
                    if (radarLayer) { map.removeLayer(radarLayer); radarLayer = null; }
                },
                trackCropBounds(pi.points, 2)
            );
        }

        function pointTimestamp(idx) {
            return estimateTimestamp(rawTrack, pi.indices[idx]);
        }

        setupTrackSlider(rawTrack, pi.points, function(idx) {
            renderTo(idx);
            carMarker.setLatLng(pi.points[idx]);
            carMarker.setIcon(leafletArrowIcon(idx > 0 ? trackBearing(pi.points[idx - 1], pi.points[idx]) : 0));
            updatePositionLabel(pi.points[idx], idx, pi.points.length);
            updateRadar(pointTimestamp(idx));
        }, pointTimestamp, updateRadar);
        updatePositionLabel(pi.points[lastIdx], lastIdx, pi.points.length);
        updateRadar(pointTimestamp(lastIdx));
    }

    function initGoogleTrack(container, rawTrack) {
        if (typeof google === 'undefined' || !google.maps) {
            container.innerHTML = '<p style="padding:10px;">Google Maps not loaded.</p>';
            return;
        }
        var pi = buildPointIndex(rawTrack);
        if (pi.points.length < 2) {
            container.innerHTML = '<p style="padding:10px;">Not enough track data.</p>';
            return;
        }
        var map = new google.maps.Map(container, {
            center: { lat: 39.8283, lng: -98.5795 },
            zoom: 5,
            mapTypeId: google.maps.MapTypeId.ROADMAP,
            mapTypeControl: false,
            streetViewControl: false,
        });

        var polylines = [];

        function renderTo(nonNullIdx) {
            polylines.forEach(function(p) { p.setMap(null); });
            polylines = [];
            var rawUpTo = pi.indices[Math.min(nonNullIdx, pi.indices.length - 1)];
            var segs    = buildSegments(rawTrack, rawUpTo);
            var bounds  = new google.maps.LatLngBounds();
            segs.forEach(function(seg) {
                if (seg.length > 1) {
                    var path = seg.map(function(p) { return { lat: p[0], lng: p[1] }; });
                    polylines.push(new google.maps.Polyline({
                        path: path, strokeColor: '#e63946', strokeOpacity: 0.9, strokeWeight: 5, map: map,
                    }));
                    path.forEach(function(p) { bounds.extend(p); });
                }
            });
            return bounds;
        }

        var lastIdx = pi.points.length - 1;
        var bounds  = renderTo(lastIdx);
        if (!bounds.isEmpty()) { map.fitBounds(bounds); }

        var carMarker = new google.maps.Marker({
            position: { lat: pi.points[lastIdx][0], lng: pi.points[lastIdx][1] },
            map: map,
            icon: googleArrowSymbol(lastIdx > 0 ? trackBearing(pi.points[lastIdx - 1], pi.points[lastIdx]) : 0),
        });

        var hasTimestamps = rawTrack.some(function(pt) { return pt && pt[2]; });
        var updateRadar = function() {};
        if (hasTimestamps) {
            var radarOverlay = null;
            $('#sc-radar-control').show();
            updateRadar = createRadarController(
                $('#sc-radar-toggle'),
                $('#sc-radar-label'),
                function setOverlay(url, imgBounds) {
                    if (radarOverlay) { radarOverlay.setMap(null); }
                    var gBounds = new google.maps.LatLngBounds(
                        { lat: imgBounds[0][0], lng: imgBounds[0][1] },
                        { lat: imgBounds[1][0], lng: imgBounds[1][1] }
                    );
                    radarOverlay = new google.maps.GroundOverlay(url, gBounds, { opacity: 0.65 });
                    radarOverlay.setMap(map);
                },
                function clearOverlay() {
                    if (radarOverlay) { radarOverlay.setMap(null); radarOverlay = null; }
                },
                trackCropBounds(pi.points, 2)
            );
        }

        function pointTimestamp(idx) {
            return estimateTimestamp(rawTrack, pi.indices[idx]);
        }

        setupTrackSlider(rawTrack, pi.points, function(idx) {
            renderTo(idx);
            carMarker.setPosition({ lat: pi.points[idx][0], lng: pi.points[idx][1] });
            carMarker.setIcon(googleArrowSymbol(idx > 0 ? trackBearing(pi.points[idx - 1], pi.points[idx]) : 0));
            updatePositionLabel(pi.points[idx], idx, pi.points.length);
            updateRadar(pointTimestamp(idx));
        }, pointTimestamp, updateRadar);
        updatePositionLabel(pi.points[lastIdx], lastIdx, pi.points.length);
        updateRadar(pointTimestamp(lastIdx));
    }

    // Returns bearing in degrees (0 = north, clockwise) between two [lat,lon] points
    function trackBearing(from, to) {
        var f1 = from[0] * Math.PI / 180, f2 = to[0] * Math.PI / 180;
        var dl = (to[1] - from[1]) * Math.PI / 180;
        var y = Math.sin(dl) * Math.cos(f2);
        var x = Math.cos(f1) * Math.sin(f2) - Math.sin(f1) * Math.cos(f2) * Math.cos(dl);
        return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
    }

    function leafletArrowIcon(deg) {
        // Inline SVG arrowhead, rotated to face direction of travel
        var svg =
            '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">' +
            '<polygon points="12,2 22,22 12,17 2,22" fill="#e63946" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/>' +
            '</svg>';
        return L.divIcon({
            className: '',
            html: '<div style="transform:rotate(' + Math.round(deg) + 'deg);transform-origin:center;width:24px;height:24px;">' + svg + '</div>',
            iconSize: [24, 24],
            iconAnchor: [12, 12],
        });
    }

    function googleArrowSymbol(deg) {
        return {
            path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
            scale: 5,
            fillColor: '#e63946',
            fillOpacity: 1,
            strokeColor: '#fff',
            strokeWeight: 1.5,
            rotation: deg,
        };
    }

    function updatePositionLabel(pt, idx, total) {
        var $label = $('#sc-track-position');
        if (!$label.length) { return; }
        var pct = total > 1 ? Math.round(idx / (total - 1) * 100) : 100;
        $label.text(pct + '%');
    }

    // Playback speed multipliers cycled through by the +/- buttons. Tick interval is
    // fixed; speed instead scales how many track points are advanced per tick, so a
    // slower/denser (more-point) track and a faster/sparser one both still play back
    // over roughly the same real-world duration at a given speed.
    var PLAYBACK_SPEEDS = [0.25, 0.5, 1, 2, 4, 8];
    var PLAYBACK_TICK_MS = 80;
    var PLAYBACK_DEFAULT_DURATION_MS = 60000; // ~1 minute to play a full track at 1x

    // How many upcoming distinct radar frames playback tries to keep pre-cached.
    // Only ever downloaded once Play is actually pressed (or playback catches up to
    // the edge of what's buffered) — never preloaded just from viewing the page.
    var PLAYBACK_LOOKAHEAD_BUCKETS = 6;
    // Safety cap so a dead/very slow connection can't hang the initial buffering
    // step forever — playback starts anyway after this, same as it would have
    // before buffering existed (frames just load a bit late, one at a time).
    var PLAYBACK_BUFFER_TIMEOUT_MS = 15000;

    function setupTrackSlider(rawTrack, points, updateFn, pointTimestampFn, radarUpdateFn) {
        var $timeline = $('#sc-track-timeline');
        var $slider   = $('#sc-track-slider');
        // points is the non-null list; slider range = number of actual GPS points
        if (!$timeline.length || !$slider.length || points.length < 3) { return; }

        var lastIdx = points.length - 1;
        $slider.attr({ min: 1, max: lastIdx, value: lastIdx });
        $timeline.show();

        var raf = null;
        function applyIdx(idx) {
            if (raf) { cancelAnimationFrame(raf); }
            raf = requestAnimationFrame(function() {
                updateFn(idx);
                raf = null;
            });
        }

        $slider.on('input', function() {
            stopPlayback();
            applyIdx(parseInt(this.value, 10));
        });

        // Playback controls (optional — only wired up if the markup is present).
        var $playToggle    = $('#sc-track-play-toggle');
        var $speedDown     = $('#sc-track-speed-down');
        var $speedUp       = $('#sc-track-speed-up');
        var $speedLabel    = $('#sc-track-speed-label');
        var $bufferStatus  = $('#sc-track-buffer-status');
        var $radarToggle   = $('#sc-radar-toggle');
        if (!$playToggle.length) { return; }

        var speedIdx       = PLAYBACK_SPEEDS.indexOf(1);
        var baseStep       = Math.max(1, Math.round(points.length / (PLAYBACK_DEFAULT_DURATION_MS / PLAYBACK_TICK_MS)));
        var playTimer      = null;
        var buffering      = false;
        var playGeneration = 0; // bumped on any user-initiated stop, to cancel a stale buffering resume
        var topUpInFlight  = false;

        function currentStep() {
            return Math.max(1, Math.round(baseStep * PLAYBACK_SPEEDS[speedIdx]));
        }

        function radarBufferingEnabled() {
            return !!(radarUpdateFn && typeof radarUpdateFn.prefetch === 'function' &&
                pointTimestampFn && $radarToggle.length && $radarToggle.is(':checked'));
        }

        function setBufferStatus(text) {
            if ($bufferStatus.length) { $bufferStatus.text(text); }
        }

        // Distinct upcoming radar timestamps (deduped by 5-minute bucket) that
        // playback would actually touch starting at fromIdx with the given step.
        function upcomingTimestamps(fromIdx, step, maxCount) {
            var out = [];
            var lastBucket = null;
            for (var idx = fromIdx; idx <= lastIdx; idx += step) {
                var ts = pointTimestampFn(idx);
                if (!ts) { continue; }
                var bucket = radarBucketKey(ts);
                if (bucket !== lastBucket) {
                    lastBucket = bucket;
                    out.push(ts);
                    if (out.length >= maxCount) { break; }
                }
                if (idx === lastIdx) { break; }
            }
            return out;
        }

        // Buffers upcoming frames (showing progress via the status text; the
        // Play/Pause button stays clickable throughout as a cancel — see the click
        // handler below) before calling onReady. Resolves immediately if radar
        // buffering doesn't apply (radar off/unsupported) or everything needed in
        // the lookahead window is already cached.
        function bufferAhead(fromIdx, onReady) {
            if (!radarBufferingEnabled()) { onReady(); return; }
            var need = upcomingTimestamps(fromIdx, currentStep(), PLAYBACK_LOOKAHEAD_BUCKETS)
                .filter(function(ts) { return !radarUpdateFn.isCached(ts); });
            if (!need.length) { onReady(); return; }

            var myGen  = playGeneration;
            var done   = false;
            var timeoutTimer = setTimeout(finish, PLAYBACK_BUFFER_TIMEOUT_MS);

            function finish() {
                if (done) { return; }
                done = true;
                clearTimeout(timeoutTimer);
                // A newer buffering pass may have started since this one began (user
                // cancelled meanwhile) — stopPlayback() already reset the shared
                // button/status UI in that case, so only touch it here if this is
                // still the active generation, to avoid clobbering that newer pass.
                if (myGen === playGeneration) {
                    buffering = false;
                    setBufferStatus('');
                    onReady();
                }
            }

            buffering = true;
            var i = 0;
            function next() {
                if (done) { return; }
                if (myGen !== playGeneration) { finish(); return; } // user cancelled meanwhile
                if (i >= need.length) { finish(); return; }
                setBufferStatus('Buffering radar… ' + (i + 1) + '/' + need.length);
                radarUpdateFn.prefetch(need[i], function() {
                    i++;
                    next();
                });
            }
            next();
        }

        // Fire-and-forget: keeps at most one background prefetch in flight so
        // playback doesn't fall behind on a fast connection, without hammering a
        // slow one with a burst of parallel requests.
        function topUpLookahead(fromIdx) {
            if (topUpInFlight || !radarBufferingEnabled()) { return; }
            var need = upcomingTimestamps(fromIdx, currentStep(), PLAYBACK_LOOKAHEAD_BUCKETS)
                .filter(function(ts) { return !radarUpdateFn.isCached(ts); });
            if (!need.length) { return; }
            topUpInFlight = true;
            radarUpdateFn.prefetch(need[0], function() { topUpInFlight = false; });
        }

        function updateSpeedLabel() {
            var s = PLAYBACK_SPEEDS[speedIdx];
            $speedLabel.text((s < 1 ? s : Math.round(s)) + 'x');
        }

        function stopPlayback() {
            playGeneration++;
            buffering = false;
            if (playTimer) { clearInterval(playTimer); playTimer = null; }
            $playToggle.html('&#9654;').attr('aria-label', 'Play');
            setBufferStatus('');
        }

        function tick() {
            var idx  = parseInt($slider.val(), 10);
            var step = currentStep();
            var next = Math.min(idx + step, lastIdx);
            var ts   = pointTimestampFn ? pointTimestampFn(next) : null;

            // Buffer underrun (slow connection catching up) — pause and re-buffer
            // rather than showing a stale/blank radar frame. The button already
            // reads "Pause" from when playback started; clicking it now still
            // correctly cancels, via the buffering flag in the click handler below.
            if (radarBufferingEnabled() && ts && !radarUpdateFn.isCached(ts)) {
                if (playTimer) { clearInterval(playTimer); playTimer = null; }
                bufferAhead(next, function() {
                    playTimer = setInterval(tick, PLAYBACK_TICK_MS);
                });
                return;
            }

            $slider.val(next);
            applyIdx(next);
            if (next >= lastIdx) { stopPlayback(); return; }
            topUpLookahead(next);
        }

        function startPlayback() {
            if (playTimer || buffering) { return; }
            var myGen = playGeneration;
            var fromIdx = parseInt($slider.val(), 10);
            if (fromIdx >= lastIdx) {
                fromIdx = 1;
                $slider.val(1);
                applyIdx(1);
            }
            // Shown immediately so the button reads "in progress, click to cancel"
            // for the whole buffer+play span, not just once actual ticking starts.
            $playToggle.html('&#10074;&#10074;').attr('aria-label', 'Pause');
            bufferAhead(fromIdx, function() {
                if (myGen !== playGeneration || playTimer) { return; }
                playTimer = setInterval(tick, PLAYBACK_TICK_MS);
            });
        }

        $playToggle.on('click', function() {
            if (playTimer || buffering) { stopPlayback(); } else { startPlayback(); }
        });
        $speedDown.on('click', function() {
            speedIdx = Math.max(0, speedIdx - 1);
            updateSpeedLabel();
        });
        $speedUp.on('click', function() {
            speedIdx = Math.min(PLAYBACK_SPEEDS.length - 1, speedIdx + 1);
            updateSpeedLabel();
        });
        updateSpeedLabel();
    }

    window.StormChases = window.StormChases || {};
    window.StormChases.log = function(message, level) {
        if (window.console && console[level] && typeof console[level] === 'function') {
            if (level !== 'info' || debugMode) {
                console[level](message);
            }
        }
    };
})(jQuery);
