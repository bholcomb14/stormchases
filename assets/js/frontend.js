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

    var RADAR_BOUNDS = {
        n0q: [[23.0, -126.0], [50.0, -65.0]], // [[south, west], [north, east]]
        n0r: [[24.0, -126.0], [50.0, -66.0]],
    };

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

    function radarTimeLabel(ts) {
        var p = radarBucketParts(ts);
        return p.hh + ':' + p.mi + ' UTC';
    }

    // Drives the "Show radar" checkbox + label shared by both map providers.
    // setOverlay(url, bounds)/clearOverlay() are provider-specific callbacks.
    function createRadarController($toggle, $label, setOverlay, clearOverlay) {
        var lastBucket = null;
        var lastTs = null;

        function update(ts) {
            lastTs = ts;
            if (!$toggle.is(':checked') || !ts) {
                if (lastBucket !== null) { clearOverlay(); lastBucket = null; }
                $label.text('');
                return;
            }
            var bucket = radarBucketKey(ts);
            if (bucket === lastBucket) { return; }
            lastBucket = bucket;
            $label.text('Radar: ' + radarTimeLabel(ts));

            var urlQ = radarBucketUrl('n0q', ts);
            var probeQ = new Image();
            probeQ.onload = function() { setOverlay(urlQ, RADAR_BOUNDS.n0q); };
            probeQ.onerror = function() {
                var urlR = radarBucketUrl('n0r', ts);
                var probeR = new Image();
                probeR.onload = function() { setOverlay(urlR, RADAR_BOUNDS.n0r); };
                probeR.onerror = function() {
                    clearOverlay();
                    $label.text('Radar: unavailable for this time');
                };
                probeR.src = urlR;
            };
            probeQ.src = urlQ;
        }

        $toggle.on('change', function() {
            if (!$toggle.is(':checked')) {
                clearOverlay();
                lastBucket = null;
                $label.text('');
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
                }
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
        });
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
                }
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
        });
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

    function setupTrackSlider(rawTrack, points, updateFn) {
        var $timeline = $('#sc-track-timeline');
        var $slider   = $('#sc-track-slider');
        // points is the non-null list; slider range = number of actual GPS points
        if (!$timeline.length || !$slider.length || points.length < 3) { return; }

        var lastIdx = points.length - 1;
        $slider.attr({ min: 1, max: lastIdx, value: lastIdx });
        $timeline.show();

        var raf = null;
        $slider.on('input', function() {
            var idx = parseInt(this.value, 10);
            if (raf) { cancelAnimationFrame(raf); }
            raf = requestAnimationFrame(function() {
                updateFn(idx);
                raf = null;
            });
        });
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
