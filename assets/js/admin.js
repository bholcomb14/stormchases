jQuery(document).ready(function($) {
    'use strict';

    // -----------------------------------------------------------------------
    // Keep the (readonly) Chase Date field and the post slug in sync with the
    // Publish date, live, as it's changed in the block editor. save_post()
    // enforces the same derivation authoritatively on the server, so this is
    // a live preview of what will be saved — not a separate source of truth.
    // -----------------------------------------------------------------------
    if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe && wp.data.select('core/editor')) {
        let lastSyncedDate = null;

        wp.data.subscribe(function() {
            const editor = wp.data.select('core/editor');
            if (!editor || editor.getCurrentPostType() !== 'storm_chase') {
                return;
            }

            const rawDate = editor.getEditedPostAttribute('date');
            if (typeof rawDate !== 'string' || rawDate.length < 10) {
                return;
            }

            // First 10 characters of the site-local date string are "YYYY-MM-DD".
            // Sliced as text (not parsed via `new Date()`) so this can't drift a
            // day off from a browser/site timezone mismatch.
            const isoDay = rawDate.slice(0, 10);
            if (!/^\d{4}-\d{2}-\d{2}$/.test(isoDay)) {
                return;
            }
            const ymd = isoDay.replace(/-/g, '');
            if (ymd === lastSyncedDate) {
                return;
            }
            lastSyncedDate = ymd;

            const $chasedate = $('#chasedate');
            if ($chasedate.length && $chasedate.val() !== ymd) {
                $chasedate.val(ymd);
            }

            if (editor.getEditedPostAttribute('slug') !== ymd) {
                wp.data.dispatch('core/editor').editPost({ slug: ymd });
            }
        });
    }

    // -----------------------------------------------------------------------
    // Chase Type: show/hide type-specific field groups
    // -----------------------------------------------------------------------
    // Reactive to the live value of #chase_type, not a static Settings toggle — unlike
    // most feature-visibility switches in this plugin, which only gate the public-facing
    // recap (see display_storm_chase_data() in storm_chase.php). Switching away from a
    // type only hides its [data-chase-type-group] rows; the underlying fields/values are
    // untouched and still submit with the form (hidden inputs still POST), so switching
    // back restores whatever was there — save_post() persists chase_data for every type's
    // fields regardless of which type is currently selected, only the *display* here is
    // type-reactive.
    const $chaseType = $('#chase_type');

    function applyChaseTypeVisibility() {
        const type = $chaseType.val();
        $('[data-chase-type-group]').each(function() {
            $(this).toggle($(this).data('chase-type-group') === type);
        });
    }

    if ($chaseType.length) {
        $chaseType.on('change', applyChaseTypeVisibility);
        applyChaseTypeVisibility();
    }

    // -----------------------------------------------------------------------
    // Highest Wind: flag suspiciously high values (likely typos) — the field's
    // own max="300" is a hard cap enforced again server-side in save_post(),
    // this is just a visual nudge above 120 (still a legitimate value in a
    // violent tornado, so it's not blocked, just called out).
    // -----------------------------------------------------------------------
    const $chaseWind = $('#chasewind');

    function applyChaseWindWarning() {
        const value = parseInt($chaseWind.val(), 10);
        $chaseWind.toggleClass('sc-field-warning', !isNaN(value) && value > 120);
    }

    if ($chaseWind.length) {
        $chaseWind.on('input change', applyChaseWindWarning);
        applyChaseWindWarning();
    }

    // -----------------------------------------------------------------------
    // Tornado entries: add / remove / reorder / collapse
    // -----------------------------------------------------------------------

    const $tornadoContainer = $('#tornadoes-container');

    function tornadoSummaryText($entry) {
        const name = $.trim($entry.find('input[name$="[name]"]').val()) || 'Unnamed Tornado';
        const ef = $entry.find('select[name$="[ef_rating]"]').val() || 'Unrated';
        return name + ' — ' + ef;
    }

    function refreshTornadoSummary($entry) {
        $entry.find('.tornado-entry-summary').text(tornadoSummaryText($entry));
    }

    function setTornadoCollapsed($entry, collapsed) {
        $entry.toggleClass('is-collapsed', collapsed);
        $entry.find('.tornado-toggle .dashicons')
            .toggleClass('dashicons-arrow-down-alt2', collapsed)
            .toggleClass('dashicons-arrow-up-alt2', !collapsed);
        if (collapsed) {
            refreshTornadoSummary($entry);
        }
    }

    // Rewrite every tornadoes[N][...] name attribute to match current DOM order, so the
    // top entry is always index 0 regardless of how entries were added, removed, or dragged.
    // Also disables the up/down arrows at the top/bottom boundaries.
    function renumberTornadoes() {
        const $entries = $tornadoContainer.find('.tornado-entry');
        $entries.each(function(i) {
            $(this).find('[name^="tornadoes["]').each(function() {
                this.name = this.name.replace(/^tornadoes\[\d+\]/, 'tornadoes[' + i + ']');
            });
            $(this).find('.tornado-move-up').prop('disabled', i === 0);
            $(this).find('.tornado-move-down').prop('disabled', i === $entries.length - 1);
        });
        $tornadoContainer.data('tornado-count', $entries.length);
    }

    $('#add-tornado').on('click', function() {
        const index = $tornadoContainer.find('.tornado-entry').length;

        // Remove any existing hidden tornadoes input before adding a new entry
        $tornadoContainer.find('input[name="tornadoes"]').remove();

        $.ajax({
            url: stormChasesSettings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'storm_chases_get_tornado_entry',
                nonce: stormChasesSettings.nonce,
                index: index
            },
            success: function(response) {
                if (response.success && response.data.entry) {
                    // New entries start expanded so the details can be filled in
                    $(response.data.entry).appendTo($tornadoContainer);
                    renumberTornadoes();
                } else {
                    showNotice('error', 'Failed to add tornado entry.');
                }
            },
            error: function(xhr, status, error) {
                showNotice('error', 'Error communicating with server: ' + error);
                console.error('AJAX error:', status, error, xhr.responseText);
            }
        });
    });

    $tornadoContainer.sortable({
        handle: '.tornado-drag-handle',
        axis: 'y',
        placeholder: 'tornado-entry-placeholder',
        forcePlaceholderSize: true,
        update: function() {
            renumberTornadoes();
        }
    });

    // Delegated handlers work for entries present at page load and ones added later
    $tornadoContainer.on('click', '.tornado-toggle, .tornado-entry-summary', function() {
        const $entry = $(this).closest('.tornado-entry');
        setTornadoCollapsed($entry, !$entry.hasClass('is-collapsed'));
    });

    $tornadoContainer.on('click', '.tornado-done', function() {
        setTornadoCollapsed($(this).closest('.tornado-entry'), true);
    });

    $tornadoContainer.on('click', '.remove-tornado', function() {
        $(this).closest('.tornado-entry').remove();

        // Remove any existing hidden tornadoes input
        $tornadoContainer.find('input[name="tornadoes"]').remove();

        // If no tornado entries remain, add a hidden input to send an empty tornadoes array
        if ($tornadoContainer.find('.tornado-entry').length === 0) {
            $tornadoContainer.append('<input type="hidden" name="tornadoes" value="">');
        } else {
            renumberTornadoes();
        }
    });

    $tornadoContainer.on('click', '.tornado-move-up', function() {
        const $entry = $(this).closest('.tornado-entry');
        const $prev = $entry.prev('.tornado-entry');
        if ($prev.length) {
            $entry.insertBefore($prev);
            renumberTornadoes();
        }
    });

    $tornadoContainer.on('click', '.tornado-move-down', function() {
        const $entry = $(this).closest('.tornado-entry');
        const $next = $entry.next('.tornado-entry');
        if ($next.length) {
            $entry.insertAfter($next);
            renumberTornadoes();
        }
    });

    $tornadoContainer.on('click', '.upload-tornado-photo-button', function(e) {
        e.preventDefault();
        const button = $(this);
        const wrapper = button.closest('.tornado-entry');
        const photoIdInput = wrapper.find('.tornado-photo-id');
        const photoUrlInput = wrapper.find('.tornado-photo-url');

        const frame = wp.media({
            title: 'Select Tornado Image',
            button: { text: 'Use Image' },
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            photoIdInput.val(attachment.id);
            photoUrlInput.val(attachment.url);
        });

        frame.open();
    });

    // Keep the collapsed summary in sync while a tornado is being edited
    $tornadoContainer.on('input change', 'input[name$="[name]"], select[name$="[ef_rating]"]', function() {
        refreshTornadoSummary($(this).closest('.tornado-entry'));
    });

    // -----------------------------------------------------------------------
    // Hurricane landfall entries: add / remove / reorder / collapse
    // -----------------------------------------------------------------------
    // Same pattern as tornado entries above (add/remove/move/collapse, AJAX-added blank
    // entries, drag-to-reorder), scoped to its own #landfalls-container and its own
    // landfall-*/remove-landfall class names throughout — deliberately kept as a separate,
    // parallel block rather than factored into one shared controller function: the two
    // entry types' fields/summary logic differ enough (no photo upload, no EF rating, a
    // different summary format) that a shared factory would need as many parameters as
    // there are differences, without a live-tested payoff — see CLAUDE.md's own note about
    // keeping this pattern as a single well-understood shape per entry type rather than
    // over-abstracting on the first repeat.

    const $landfallContainer = $('#landfalls-container');

    function landfallSummaryText($entry) {
        const name = $.trim($entry.find('input[name$="[name]"]').val()) || 'Unnamed Landfall';
        const category = $entry.find('select[name$="[category]"]').val() || 'Unknown';
        return name + ' — ' + category;
    }

    function refreshLandfallSummary($entry) {
        $entry.find('.landfall-entry-summary').text(landfallSummaryText($entry));
    }

    function setLandfallCollapsed($entry, collapsed) {
        $entry.toggleClass('is-collapsed', collapsed);
        $entry.find('.landfall-toggle .dashicons')
            .toggleClass('dashicons-arrow-down-alt2', collapsed)
            .toggleClass('dashicons-arrow-up-alt2', !collapsed);
        if (collapsed) {
            refreshLandfallSummary($entry);
        }
    }

    function renumberLandfalls() {
        const $entries = $landfallContainer.find('.landfall-entry');
        $entries.each(function(i) {
            $(this).find('[name^="landfalls["]').each(function() {
                this.name = this.name.replace(/^landfalls\[\d+\]/, 'landfalls[' + i + ']');
            });
            $(this).find('.landfall-move-up').prop('disabled', i === 0);
            $(this).find('.landfall-move-down').prop('disabled', i === $entries.length - 1);
        });
        $landfallContainer.data('landfall-count', $entries.length);
    }

    $('#add-landfall').on('click', function() {
        const index = $landfallContainer.find('.landfall-entry').length;
        $landfallContainer.find('input[name="landfalls"]').remove();

        $.ajax({
            url: stormChasesSettings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'storm_chases_get_landfall_entry',
                nonce: stormChasesSettings.nonce,
                index: index
            },
            success: function(response) {
                if (response.success && response.data.entry) {
                    $(response.data.entry).appendTo($landfallContainer);
                    renumberLandfalls();
                } else {
                    showNotice('error', 'Failed to add landfall entry.');
                }
            },
            error: function(xhr, status, error) {
                showNotice('error', 'Error communicating with server: ' + error);
                console.error('AJAX error:', status, error, xhr.responseText);
            }
        });
    });

    $landfallContainer.sortable({
        handle: '.tornado-drag-handle',
        axis: 'y',
        placeholder: 'tornado-entry-placeholder',
        forcePlaceholderSize: true,
        update: function() {
            renumberLandfalls();
        }
    });

    $landfallContainer.on('click', '.landfall-toggle, .landfall-entry-summary', function() {
        const $entry = $(this).closest('.landfall-entry');
        setLandfallCollapsed($entry, !$entry.hasClass('is-collapsed'));
    });

    $landfallContainer.on('click', '.landfall-done', function() {
        setLandfallCollapsed($(this).closest('.landfall-entry'), true);
    });

    $landfallContainer.on('click', '.remove-landfall', function() {
        $(this).closest('.landfall-entry').remove();
        $landfallContainer.find('input[name="landfalls"]').remove();
        if ($landfallContainer.find('.landfall-entry').length === 0) {
            $landfallContainer.append('<input type="hidden" name="landfalls" value="">');
        } else {
            renumberLandfalls();
        }
    });

    $landfallContainer.on('click', '.landfall-move-up', function() {
        const $entry = $(this).closest('.landfall-entry');
        const $prev = $entry.prev('.landfall-entry');
        if ($prev.length) {
            $entry.insertBefore($prev);
            renumberLandfalls();
        }
    });

    $landfallContainer.on('click', '.landfall-move-down', function() {
        const $entry = $(this).closest('.landfall-entry');
        const $next = $entry.next('.landfall-entry');
        if ($next.length) {
            $entry.insertAfter($next);
            renumberLandfalls();
        }
    });

    $landfallContainer.on('input change', 'input[name$="[name]"], select[name$="[category]"]', function() {
        refreshLandfallSummary($(this).closest('.landfall-entry'));
    });

    $landfallContainer.find('.landfall-entry').each(function() {
        setLandfallCollapsed($(this), true);
    });
    renumberLandfalls();

    // -----------------------------------------------------------------------
    // Winter snowfall entries: add / remove / reorder / collapse
    // -----------------------------------------------------------------------
    // Same parallel-controller pattern as the landfall block above, for the same reason —
    // see the comment there.

    const $snowfallContainer = $('#snowfall-container');

    function snowfallSummaryText($entry) {
        const location = $.trim($entry.find('input[name$="[location]"]').val()) || 'Unnamed Location';
        const depth = parseFloat($entry.find('input[name$="[depth]"]').val()) || 0;
        return location + ' — ' + depth.toFixed(1) + '"';
    }

    function refreshSnowfallSummary($entry) {
        $entry.find('.snowfall-entry-summary').text(snowfallSummaryText($entry));
    }

    function setSnowfallCollapsed($entry, collapsed) {
        $entry.toggleClass('is-collapsed', collapsed);
        $entry.find('.snowfall-toggle .dashicons')
            .toggleClass('dashicons-arrow-down-alt2', collapsed)
            .toggleClass('dashicons-arrow-up-alt2', !collapsed);
        if (collapsed) {
            refreshSnowfallSummary($entry);
        }
    }

    function renumberSnowfall() {
        const $entries = $snowfallContainer.find('.snowfall-entry');
        $entries.each(function(i) {
            $(this).find('[name^="snowfall_reports["]').each(function() {
                this.name = this.name.replace(/^snowfall_reports\[\d+\]/, 'snowfall_reports[' + i + ']');
            });
            $(this).find('.snowfall-move-up').prop('disabled', i === 0);
            $(this).find('.snowfall-move-down').prop('disabled', i === $entries.length - 1);
        });
        $snowfallContainer.data('snowfall-count', $entries.length);
    }

    $('#add-snowfall').on('click', function() {
        const index = $snowfallContainer.find('.snowfall-entry').length;
        $snowfallContainer.find('input[name="snowfall_reports"]').remove();

        $.ajax({
            url: stormChasesSettings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'storm_chases_get_snowfall_entry',
                nonce: stormChasesSettings.nonce,
                index: index
            },
            success: function(response) {
                if (response.success && response.data.entry) {
                    $(response.data.entry).appendTo($snowfallContainer);
                    renumberSnowfall();
                } else {
                    showNotice('error', 'Failed to add snowfall entry.');
                }
            },
            error: function(xhr, status, error) {
                showNotice('error', 'Error communicating with server: ' + error);
                console.error('AJAX error:', status, error, xhr.responseText);
            }
        });
    });

    $snowfallContainer.sortable({
        handle: '.tornado-drag-handle',
        axis: 'y',
        placeholder: 'tornado-entry-placeholder',
        forcePlaceholderSize: true,
        update: function() {
            renumberSnowfall();
        }
    });

    $snowfallContainer.on('click', '.snowfall-toggle, .snowfall-entry-summary', function() {
        const $entry = $(this).closest('.snowfall-entry');
        setSnowfallCollapsed($entry, !$entry.hasClass('is-collapsed'));
    });

    $snowfallContainer.on('click', '.snowfall-done', function() {
        setSnowfallCollapsed($(this).closest('.snowfall-entry'), true);
    });

    $snowfallContainer.on('click', '.remove-snowfall', function() {
        $(this).closest('.snowfall-entry').remove();
        $snowfallContainer.find('input[name="snowfall_reports"]').remove();
        if ($snowfallContainer.find('.snowfall-entry').length === 0) {
            $snowfallContainer.append('<input type="hidden" name="snowfall_reports" value="">');
        } else {
            renumberSnowfall();
        }
    });

    $snowfallContainer.on('click', '.snowfall-move-up', function() {
        const $entry = $(this).closest('.snowfall-entry');
        const $prev = $entry.prev('.snowfall-entry');
        if ($prev.length) {
            $entry.insertBefore($prev);
            renumberSnowfall();
        }
    });

    $snowfallContainer.on('click', '.snowfall-move-down', function() {
        const $entry = $(this).closest('.snowfall-entry');
        const $next = $entry.next('.snowfall-entry');
        if ($next.length) {
            $entry.insertAfter($next);
            renumberSnowfall();
        }
    });

    $snowfallContainer.on('input change', 'input[name$="[location]"], input[name$="[depth]"]', function() {
        refreshSnowfallSummary($(this).closest('.snowfall-entry'));
    });

    $snowfallContainer.find('.snowfall-entry').each(function() {
        setSnowfallCollapsed($(this), true);
    });
    renumberSnowfall();

    // -----------------------------------------------------------------------
    // Location picker — click a map to fill lat/lon fields. Shared by tornado
    // entries (.tornado-pick-location) and landfall entries (.landfall-pick-location).
    // -----------------------------------------------------------------------

    let scLocationPickerMap = null;
    let scLocationPickerMarker = null;
    let scLocationPickerTarget = null; // { $lat, $lon }
    let scLocationPickerPicked = null; // { lat, lon }

    const $locationPickerModal   = $('#sc-location-picker-modal');
    const $locationPickerReadout = $('#sc-location-picker-readout');
    const $locationPickerConfirm = $('#sc-location-picker-confirm');

    function initLocationPickerMap() {
        if (scLocationPickerMap || typeof L === 'undefined') {
            return;
        }
        scLocationPickerMap = L.map('sc-location-picker-map').setView([39.8283, -98.5795], 4);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" rel="noopener noreferrer">OpenStreetMap</a> contributors',
            maxZoom: 18,
        }).addTo(scLocationPickerMap);

        scLocationPickerMap.on('click', function(e) {
            const lat = Math.round(e.latlng.lat * 10000) / 10000;
            const lon = Math.round(e.latlng.lng * 10000) / 10000;
            scLocationPickerPicked = { lat: lat, lon: lon };

            if (scLocationPickerMarker) {
                scLocationPickerMarker.setLatLng(e.latlng);
            } else {
                scLocationPickerMarker = L.marker(e.latlng).addTo(scLocationPickerMap);
            }

            $locationPickerReadout.text(lat.toFixed(4) + '°, ' + lon.toFixed(4) + '°');
            $locationPickerConfirm.prop('disabled', false);
        });
    }

    // Shared by both .tornado-pick-location (tornado entries) and .landfall-pick-location
    // (landfall entries) below — the field lookup is generic (any entry body with
    // lat/lon-suffixed input names), so one function serves both entry types.
    function openLocationPickerFor($button) {
        const $entryBody = $button.closest('.tornado-entry-body');
        const latField = $button.data('lat-field');
        const lonField = $button.data('lon-field');
        scLocationPickerTarget = {
            $lat: $entryBody.find('input[name$="[' + latField + ']"]'),
            $lon: $entryBody.find('input[name$="[' + lonField + ']"]'),
        };

        scLocationPickerPicked = null;
        $locationPickerReadout.text('No location selected yet.');
        $locationPickerConfirm.prop('disabled', true);

        $locationPickerModal.show();
        initLocationPickerMap();

        // Center on the field's current value if it's already set, else keep the
        // default US-wide view. Leaflet needs invalidateSize() after being shown,
        // since it was hidden (zero size) when first initialized.
        const currentLat = parseFloat(scLocationPickerTarget.$lat.val());
        const currentLon = parseFloat(scLocationPickerTarget.$lon.val());
        setTimeout(function() {
            scLocationPickerMap.invalidateSize();
            if (currentLat && currentLon) {
                const latlng = [currentLat, currentLon];
                scLocationPickerMap.setView(latlng, 11);
                if (scLocationPickerMarker) {
                    scLocationPickerMarker.setLatLng(latlng);
                } else {
                    scLocationPickerMarker = L.marker(latlng).addTo(scLocationPickerMap);
                }
                scLocationPickerPicked = { lat: currentLat, lon: currentLon };
                $locationPickerReadout.text(currentLat.toFixed(4) + '°, ' + currentLon.toFixed(4) + '°');
                $locationPickerConfirm.prop('disabled', false);
            }
        }, 0);
    }

    $tornadoContainer.on('click', '.tornado-pick-location', function() {
        openLocationPickerFor($(this));
    });

    $landfallContainer.on('click', '.landfall-pick-location', function() {
        openLocationPickerFor($(this));
    });

    $snowfallContainer.on('click', '.snowfall-pick-location', function() {
        openLocationPickerFor($(this));
    });

    function closeLocationPicker() {
        $locationPickerModal.hide();
        scLocationPickerTarget = null;
    }

    $locationPickerConfirm.on('click', function() {
        if (scLocationPickerTarget && scLocationPickerPicked) {
            scLocationPickerTarget.$lat.val(scLocationPickerPicked.lat).trigger('change');
            scLocationPickerTarget.$lon.val(scLocationPickerPicked.lon).trigger('change');
        }
        closeLocationPicker();
    });

    $('#sc-location-picker-cancel, #sc-location-picker-modal .modal-close, #sc-location-picker-modal .modal-overlay').on('click', function() {
        closeLocationPicker();
    });

    // Collapse entries that were already saved so a chase with many tornadoes doesn't
    // overwhelm the editor; entries added via #add-tornado start expanded (see above).
    $tornadoContainer.find('.tornado-entry').each(function() {
        setTornadoCollapsed($(this), true);
    });
    renumberTornadoes();

    // -----------------------------------------------------------------------
    // Chase Milestones: bullet list add / remove / Enter-to-add
    // -----------------------------------------------------------------------

    const $milestonesContainer = $('#chasems-container');

    function addMilestoneRow(focus) {
        $milestonesContainer.find('input[name="chasems"]').remove(); // drop the "empty" placeholder
        const $item = $(
            '<div class="chasems-item">' +
                '<span class="chasems-bullet" aria-hidden="true">&bull;</span>' +
                '<input type="text" name="chasems[]" value="">' +
                '<button type="button" class="button-link remove-milestone" title="Remove"><span class="dashicons dashicons-no-alt"></span></button>' +
            '</div>'
        );
        $milestonesContainer.append($item);
        if (focus) {
            $item.find('input').trigger('focus');
        }
        return $item;
    }

    $('#add-milestone').on('click', function() {
        addMilestoneRow(true);
    });

    $milestonesContainer.on('keydown', 'input[name="chasems[]"]', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addMilestoneRow(true);
        }
    });

    $milestonesContainer.on('click', '.remove-milestone', function() {
        $(this).closest('.chasems-item').remove();
        if ($milestonesContainer.find('.chasems-item').length === 0) {
            $milestonesContainer.append('<input type="hidden" name="chasems" value="">');
        }
    });

    // -----------------------------------------------------------------------
    // Chase map upload via REST (GPS track or image)
    // -----------------------------------------------------------------------

    $('#upload-chasemap-btn').on('click', function () {
        const fileInput = document.getElementById('chasemap');
        if (!fileInput || !fileInput.files.length) {
            showChasemapMsg('error', 'Please choose a file first.');
            return;
        }

        const maxSize  = stormChasesSettings.maxFileSize || 10 * 1024 * 1024;
        const allowed  = ['kml', 'gpx', 'nmea', 'jpg', 'jpeg', 'png', 'gif'];
        const gpsExts  = ['kml', 'gpx', 'nmea'];

        // Validate extensions first (sync, before any async work)
        for (let i = 0; i < fileInput.files.length; i++) {
            const ext = fileInput.files[i].name.split('.').pop().toLowerCase();
            if (!allowed.includes(ext)) {
                showChasemapMsg('error', 'Unsupported file: .' + ext + '. Use .kml, .gpx, .nmea, .jpg, .png, or .gif.');
                return;
            }
            if (!gpsExts.includes(ext) && fileInput.files[i].size > maxSize) {
                showChasemapMsg('error', fileInput.files[i].name + ' exceeds the maximum allowed size.');
                return;
            }
        }

        const $btn      = $(this);
        const $progress = $('#chasemap-progress');
        const $fill     = $('#chasemap-progress-fill');
        const $label    = $('#chasemap-progress-label');
        let processingTimer = null;

        function setProgress(pct, msg) {
            $progress.show();
            $fill.css('width', pct + '%');
            $label.text(msg);
        }

        $btn.prop('disabled', true);
        setProgress(0, 'Preparing files\u2026');

        // Strip NMEA files to RMC-only lines in the browser before uploading.
        // A 14 MB log shrinks to ~2-3 MB this way, staying within typical PHP limits.
        async function prepareFile(file) {
            const ext = file.name.split('.').pop().toLowerCase();
            if (ext !== 'nmea') { return file; }
            const text   = await file.text();
            const lines  = text.split(/\r?\n/);
            const rmc    = lines.filter(function(l) {
                return l.startsWith('$GPRMC,') || l.startsWith('$GNRMC,');
            });
            const blob = new Blob([rmc.join('\n')], { type: 'text/plain' });
            const origMB = (file.size / 1048576).toFixed(1);
            const newMB  = (blob.size  / 1048576).toFixed(1);
            console.log('NMEA pre-filter: ' + origMB + ' MB \u2192 ' + newMB + ' MB (' + rmc.length + ' RMC sentences)');
            return new File([blob], file.name, { type: 'text/plain' });
        }

        Promise.all(Array.from(fileInput.files).map(prepareFile)).then(function(prepared) {
            const formData = new FormData();
            prepared.forEach(function(f) { formData.append('chasemap[]', f); });

            const totalMB = (prepared.reduce(function(s, f) { return s + f.size; }, 0) / 1048576).toFixed(1);
            setProgress(2, 'Uploading ' + totalMB + ' MB\u2026');

            const xhr       = new XMLHttpRequest();
            const startTime = Date.now();

            xhr.upload.onprogress = function(e) {
                if (!e.lengthComputable) { return; }
                const elapsed = (Date.now() - startTime) / 1000 || 0.001;
                const pct     = Math.round(e.loaded / e.total * 70) + 2;
                const mbDone  = (e.loaded  / 1048576).toFixed(1);
                const mbTotal = (e.total   / 1048576).toFixed(1);
                const speed   = (e.loaded  / 1048576 / elapsed).toFixed(1);
                setProgress(pct, 'Uploading ' + mbDone + ' / ' + mbTotal + ' MB  \u2014  ' + speed + ' MB/s');
            };

            xhr.upload.onload = function() {
                setProgress(75, 'Processing GPS data on server\u2026 (0s)');
                const processingStart = Date.now();
                processingTimer = setInterval(function() {
                    // Use wall-clock time so throttled tabs still show correct elapsed seconds
                    const sec = Math.round((Date.now() - processingStart) / 1000);
                    setProgress(75, 'Processing GPS data on server\u2026 (' + sec + 's)');
                    if (sec === 60) {
                        setProgress(75, 'Still working \u2014 large files can take 1\u20132 min\u2026 (' + sec + 's)');
                    }
                }, 500);
            };

            xhr.timeout = 300000; // 5-minute hard timeout

            xhr.ontimeout = function() {
                if (processingTimer) { clearInterval(processingTimer); processingTimer = null; }
                $btn.prop('disabled', false).text('Upload Chase Map');
                $progress.hide();
                showChasemapMsg('error', 'Server timed out. The file may be too large or the server too slow. Try increasing max_execution_time in php.ini.');
            };

            xhr.onload = function() {
                if (processingTimer) { clearInterval(processingTimer); processingTimer = null; }
                $btn.prop('disabled', false).text('Upload Chase Map');
                var rawText = xhr.responseText;
                console.log('[StormChases] Upload response (HTTP ' + xhr.status + '):', rawText);
                var response;
                try { response = JSON.parse(rawText); } catch(e) {
                    $progress.hide();
                    showChasemapMsg('error', 'Server returned invalid JSON (HTTP ' + xhr.status + '). Check PHP error log. Raw: ' + rawText.substring(0, 200));
                    return;
                }

                if (xhr.status === 413) {
                    $progress.hide();
                    showChasemapMsg('error', 'Server rejected the file (HTTP 413). Add "php_value upload_max_filesize 50M" and "php_value post_max_size 50M" to your .htaccess, then retry.');
                    return;
                }

                if (xhr.status >= 200 && xhr.status < 300 && response && response.success) {
                    // REST API puts data at top level, not nested under response.data
                    const msg  = response.message || 'Done!';
                    const $td  = $btn.closest('td');
                    setProgress(100, msg);
                    if (response.track) {
                        const pts  = response.track_points || 0;
                        const segs = response.segment_count || 1;
                        const segNote = segs > 1
                            ? ' (' + segs + ' segments \u2014 look for \u25CF gap markers on the map)'
                            : '';
                        const $status = $('<p class="sc-chasemap-status">')
                            .html('<span class="dashicons dashicons-location-alt" style="vertical-align:middle;"></span> GPS track loaded &mdash; ' + pts + ' points' + segNote + ' stored');
                        $td.find('.sc-chasemap-status').closest('p').remove();
                        $td.find('img').closest('p').remove();
                        $td.prepend($status);
                        showRemoveCheckbox($td);
                    } else if (response.thumb_url) {
                        $td.find('.sc-chasemap-status').closest('p').remove();
                        const $thumb = $('<p>').append($('<img>').attr({ src: response.thumb_url, alt: '' }).css('max-width', '150px'));
                        $td.find('img').closest('p').replaceWith($thumb);
                        if (!$td.find('img').length) { $td.prepend($thumb); }
                        showRemoveCheckbox($td);
                    }
                    fileInput.value = '';
                    setTimeout(function() {
                        $progress.hide();
                        showChasemapMsg('success', msg + '<br><strong>Refresh the public chase page</strong> to see the updated map.');
                    }, 800);
                } else {
                    const msg = (response && response.message)
                        || 'Upload failed (HTTP ' + xhr.status + ').';
                    $progress.hide();
                    showChasemapMsg('error', msg);
                }
            };

            xhr.onerror = function() {
                if (processingTimer) { clearInterval(processingTimer); processingTimer = null; }
                $btn.prop('disabled', false).text('Upload Chase Map');
                $progress.hide();
                showChasemapMsg('error', 'Network error. Check that upload_max_filesize and post_max_size in php.ini are large enough.');
            };

            xhr.open('POST', stormChasesSettings.restUrl + 'stormchases/v1/upload-chasemap/' + stormChasesSettings.postId);
            xhr.setRequestHeader('X-WP-Nonce', stormChasesSettings.nonce);
            xhr.send(formData);

        }).catch(function(err) {
            $btn.prop('disabled', false).text('Upload Chase Map');
            $progress.hide();
            showChasemapMsg('error', 'Failed to read file: ' + err.message);
        });
    });

    function showChasemapMsg(type, message) {
        $('#chasemap-message').html('<div class="storm-chases-notice ' + type + '">' + message + '</div>');
        setTimeout(function () { $('#chasemap-message').empty(); }, 6000);
    }

    function showRemoveCheckbox($td) {
        if (!$td.find('#remove-chasemap-btn').length) {
            $td.append(
                '<p style="margin-top:10px;">' +
                '<button type="button" id="remove-chasemap-btn" class="button button-small">Remove Chase Map</button>' +
                '</p>'
            );
        }
    }

    // Remove Chase Map — REST call, no form submit (avoids the "leave site?" unsaved-changes
    // prompt that firing form.submit() used to trigger on the post edit screen).
    $(document).on('click', '#remove-chasemap-btn', function () {
        if (!confirm('Permanently remove the GPS track from this chase log?')) {
            return;
        }
        const $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: stormChasesSettings.restUrl + 'stormchases/v1/remove-chasemap/' + stormChasesSettings.postId,
            method: 'POST',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', stormChasesSettings.nonce); },
        }).done(function (response) {
            if (response && response.success) {
                const $td = $btn.closest('td');
                $td.find('.sc-chasemap-status').closest('p').remove();
                $td.find('img').closest('p').remove();
                $btn.closest('p').remove();
                const fileInput = document.getElementById('chasemap');
                if (fileInput) { fileInput.value = ''; }
                showChasemapMsg('success', (response && response.message) || 'Chase map removed.');
            } else {
                $btn.prop('disabled', false);
                showChasemapMsg('error', (response && response.message) || 'Failed to remove chase map.');
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false);
            let msg = 'Failed to remove chase map.';
            try {
                const r = JSON.parse(xhr.responseText);
                if (r && r.message) { msg = r.message; }
            } catch (e) { /* keep default msg */ }
            showChasemapMsg('error', msg);
        });
    });

    // -----------------------------------------------------------------------
    // Spotter Network CSV upload — improved UX
    // -----------------------------------------------------------------------

    // Show filename + size when the user picks a file
    $('#spotter_reports').on('change', function () {
        const file = this.files[0];
        const $name = $('#sc-file-name');
        const $dropZone = $('#sc-file-drop-zone');
        if (file) {
            const sizeKB = (file.size / 1024).toFixed(1);
            $name.text(file.name + ' (' + sizeKB + ' KB)').show();
            $dropZone.addClass('sc-file-selected');
        } else {
            $name.hide();
            $dropZone.removeClass('sc-file-selected');
        }
    });

    // Drag-and-drop support on the drop zone
    $('#sc-file-drop-zone').on('dragover dragenter', function (e) {
        e.preventDefault();
        $(this).addClass('sc-drag-over');
    }).on('dragleave drop', function (e) {
        e.preventDefault();
        $(this).removeClass('sc-drag-over');
        if (e.type === 'drop') {
            const dt = e.originalEvent.dataTransfer;
            if (dt && dt.files.length) {
                $('#spotter_reports')[0].files = dt.files;
                $('#spotter_reports').trigger('change');
            }
        }
    });

    $('#upload-spotter-reports').on('click', function() {
        const form = $('#storm-chases-settings-form');
        const formData = new FormData(form[0]);
        const isDryRun = $('#dry_run').is(':checked');

        const fileInput = $('#spotter_reports')[0];
        if (!fileInput.files.length) {
            showNotice('error', 'Please select a CSV file to upload.');
            return;
        }

        if (fileInput.files[0].size > stormChasesSettings.maxFileSize) {
            showNotice('error', 'File size exceeds the maximum limit.');
            return;
        }

        const fileType = fileInput.files[0].type || fileInput.files[0].name.split('.').pop().toLowerCase();
        if (!['text/csv', 'text/plain', 'application/csv'].includes(fileType) && !['csv', 'txt'].includes(fileType)) {
            showNotice('error', 'Only CSV or TXT files are supported.');
            return;
        }

        // Show spinner
        const $btn = $('#upload-spotter-reports');
        $btn.prop('disabled', true);
        $btn.find('.sc-btn-text').hide();
        $btn.find('.sc-btn-spinner').show();

        $.ajax({
            url: stormChasesSettings.restUrl + 'stormchases/v1/upload-spotter-reports',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            headers: {
                'X-WP-Nonce': stormChasesSettings.nonce
            },
            dataType: 'json',
            success: function(response) {
                const isDryRunResult = response.data && response.data.dry_run;
                if (response && typeof response === 'object' && response.success) {
                    let message = (response.data && response.data.message) ? response.data.message : 'Reports uploaded successfully.';
                    if (isDryRunResult) {
                        message = '<strong>[DRY RUN — no data saved]</strong> ' + message;
                    }
                    message += buildSkipDetails(response.data);
                    showNotice(isDryRunResult ? 'info' : 'success', message);
                } else {
                    let message = (response.data && response.data.message) ? response.data.message : 'Failed to upload reports.';
                    message += buildSkipDetails(response.data);
                    showNotice('error', message);
                }
            },
            error: function(xhr) {
                let message = 'Error uploading reports.';
                try {
                    const responseJSON = xhr.responseJSON;
                    if (responseJSON && responseJSON.message) {
                        message = responseJSON.message;
                    } else if (responseJSON && responseJSON.data && responseJSON.data.message) {
                        message = responseJSON.data.message;
                    }
                    message += buildSkipDetails(responseJSON && responseJSON.data);
                } catch (e) {
                    message += ' Invalid server response.';
                }
                showNotice('error', message);
            },
            complete: function() {
                $btn.prop('disabled', false);
                $btn.find('.sc-btn-spinner').hide();
                $btn.find('.sc-btn-text').show();
            }
        });
    });

    function buildSkipDetails(data) {
        if (!data) return '';
        let extra = '';
        if (data.unmatched_dates && Object.keys(data.unmatched_dates).length > 0) {
            extra += '<br><strong>Unmatched dates (no chase log found):</strong><ul>';
            for (let date in data.unmatched_dates) {
                extra += '<li>' + date + ' (' + data.unmatched_dates[date] + ' report(s))</li>';
            }
            extra += '</ul>';
        }
        if (data.skip_reasons && data.skip_reasons.length > 0) {
            extra += '<br><details><summary>Skipped rows (' + data.skip_reasons.length + ')</summary><ul>';
            data.skip_reasons.forEach(function(reason) {
                extra += '<li>' + reason + '</li>';
            });
            extra += '</ul></details>';
        }
        return extra;
    }

    // Detect wp.data source — meta boxes run in a hidden iframe in Gutenberg,
    // so the core/editor store lives in the parent frame.
    const inIframe = window.parent !== window;
    const wpData = (inIframe && window.parent.wp && window.parent.wp.data)
        ? window.parent.wp.data
        : (typeof wp !== 'undefined' && wp.data ? wp.data : null);

    // Sync #chasedate AND the Gutenberg permalink slug with the publish date.
    function syncChaseDateFromPostDate(dateStr) {
        const match = (dateStr || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!match) { return; }
        const slug = match[1] + match[2] + match[3];
        $('#chasedate').val(slug);
        // Push the new slug into the Gutenberg editor store so the
        // permalink panel reflects it immediately (and saves it correctly).
        try {
            if (wpData) {
                const dispatch = wpData.dispatch('core/editor');
                if (dispatch && dispatch.editPost) {
                    dispatch.editPost({ slug: slug });
                }
            }
        } catch (e) {}
    }

    if ($('#chasedate').length) {
        if (wpData && wpData.subscribe) {
            let prevPublishDate;
            wpData.subscribe(function() {
                try {
                    const coreEditor = wpData.select('core/editor');
                    if (!coreEditor) { return; }
                    const date = coreEditor.getEditedPostAttribute('date');
                    if (prevPublishDate === undefined) {
                        // Initial load: always populate from publish date
                        prevPublishDate = date;
                        if (date) { syncChaseDateFromPostDate(date); }
                    } else if (date !== prevPublishDate) {
                        // Publish date changed: always sync
                        prevPublishDate = date;
                        if (date) { syncChaseDateFromPostDate(date); }
                    }
                } catch (e) {}
            });
        }

        // Classic editor (not in an iframe): read #aa/#mm/#jj date fields.
        if (!inIframe) {
            (function() {
                const y = $('#aa').val(), m = $('#mm').val(), d = $('#jj').val();
                if (y && m && d) { syncChaseDateFromPostDate(y + '-' + m + '-' + d); }
            }());
            $(document).on('change', '#aa, #mm, #jj', function() {
                const y = $('#aa').val(), m = $('#mm').val(), d = $('#jj').val();
                if (y && m && d) { syncChaseDateFromPostDate(y + '-' + m + '-' + d); }
            });
        }
    }

    function showNotice(type, message) {
        const notice = $('<div>', {
            class: 'storm-chases-notice ' + type,
            html: message
        }).appendTo('#upload-message');
        setTimeout(function() {
            notice.fadeOut(400, function() {
                $(this).remove();
            });
        }, 8000);
    }

    // -----------------------------------------------------------------------
    // Windshield replacements management
    // -----------------------------------------------------------------------

    const $windshieldMsg = $('#sc-windshield-message');
    const months = ['', 'January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'];

    $('#sc-add-windshield').on('click', function () {
        const month = parseInt($('#sc-ws-month').val(), 10);
        const year  = parseInt($('#sc-ws-year').val(), 10);

        if (!month || month < 1 || month > 12) {
            showWindshieldMsg('error', 'Please select a valid month.');
            return;
        }
        if (!year || year < 1990 || year > new Date().getFullYear() + 1) {
            showWindshieldMsg('error', 'Please enter a valid year.');
            return;
        }

        $.ajax({
            url: stormChasesSettings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'storm_chases_add_windshield',
                nonce: stormChasesSettings.windshieldNonce,
                month: month,
                year: year,
            },
            success: function (response) {
                if (response.success) {
                    const idx = response.data.index;
                    $('#sc-windshield-empty').remove();
                    const $row = $('<tr>').attr('data-index', idx).html(
                        '<td>' + months[month] + '</td>' +
                        '<td>' + year + '</td>' +
                        '<td><button type="button" class="button button-small sc-delete-windshield" data-index="' + idx + '">Delete</button></td>'
                    );
                    $('#sc-windshield-list').append($row);
                    showWindshieldMsg('success', 'Entry added.');
                } else {
                    showWindshieldMsg('error', response.data.message || 'Failed to add entry.');
                }
            },
            error: function () {
                showWindshieldMsg('error', 'Server error. Please try again.');
            }
        });
    });

    $(document).on('click', '.sc-delete-windshield', function () {
        const $btn = $(this);
        const index = $btn.data('index');

        $.ajax({
            url: stormChasesSettings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'storm_chases_delete_windshield',
                nonce: stormChasesSettings.windshieldNonce,
                index: index,
            },
            success: function (response) {
                if (response.success) {
                    $btn.closest('tr').remove();
                    // Re-index remaining rows so delete still works after multiple deletes
                    $('#sc-windshield-list tr').each(function (i) {
                        $(this).attr('data-index', i);
                        $(this).find('.sc-delete-windshield').attr('data-index', i);
                    });
                    if ($('#sc-windshield-list tr').length === 0) {
                        $('#sc-windshield-list').append(
                            '<tr id="sc-windshield-empty"><td colspan="3">No windshield replacements recorded yet.</td></tr>'
                        );
                    }
                    showWindshieldMsg('success', 'Entry deleted.');
                } else {
                    showWindshieldMsg('error', response.data.message || 'Failed to delete entry.');
                }
            },
            error: function () {
                showWindshieldMsg('error', 'Server error. Please try again.');
            }
        });
    });

    function showWindshieldMsg(type, message) {
        $windshieldMsg.html('<div class="storm-chases-notice ' + type + '">' + message + '</div>');
        setTimeout(function () { $windshieldMsg.empty(); }, 4000);
    }

    // -----------------------------------------------------------------------
    // GPS track privacy zones management
    // -----------------------------------------------------------------------

    const $privacyMsg = $('#sc-privacy-message');

    $('#sc-add-privacy-zone').on('click', function () {
        const label  = $('#sc-pz-label').val().trim();
        const lat    = parseFloat($('#sc-pz-lat').val());
        const lon    = parseFloat($('#sc-pz-lon').val());
        const radius = parseFloat($('#sc-pz-radius').val());

        if (isNaN(lat) || lat < -90 || lat > 90) {
            showPrivacyMsg('error', 'Enter a valid latitude (−90 to 90).');
            return;
        }
        if (isNaN(lon) || lon < -180 || lon > 180) {
            showPrivacyMsg('error', 'Enter a valid longitude (−180 to 180).');
            return;
        }
        if (isNaN(radius) || radius < 0.5 || radius > 50) {
            showPrivacyMsg('error', 'Radius must be between 0.5 and 50 miles.');
            return;
        }

        $.ajax({
            url: stormChasesSettings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'storm_chases_add_privacy_zone',
                nonce:  stormChasesSettings.privacyNonce,
                label:  label,
                lat:    lat,
                lon:    lon,
                radius: radius,
            },
            success: function (response) {
                if (response.success) {
                    const d   = response.data;
                    const idx = d.index;
                    $('#sc-privacy-empty').remove();
                    $('#sc-privacy-list').append(
                        '<tr data-index="' + idx + '">' +
                        '<td>' + $('<span>').text(d.label).html() + '</td>' +
                        '<td>' + d.lat + '</td>' +
                        '<td>' + d.lon + '</td>' +
                        '<td>' + d.radius + '</td>' +
                        '<td><button type="button" class="button button-small sc-delete-privacy-zone" data-index="' + idx + '">Delete</button></td>' +
                        '</tr>'
                    );
                    $('#sc-pz-label').val('');
                    $('#sc-pz-lat, #sc-pz-lon').val('');
                    showPrivacyMsg('success', 'Zone added. Future GPS uploads to this site will strip track points within ' + d.radius + ' mi of this location.');
                } else {
                    showPrivacyMsg('error', response.data.message || 'Failed to add zone.');
                }
            },
            error: function () { showPrivacyMsg('error', 'Server error. Please try again.'); }
        });
    });

    $(document).on('click', '.sc-delete-privacy-zone', function () {
        const $btn  = $(this);
        const index = $btn.data('index');

        $.ajax({
            url: stormChasesSettings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'storm_chases_delete_privacy_zone',
                nonce:  stormChasesSettings.privacyNonce,
                index:  index,
            },
            success: function (response) {
                if (response.success) {
                    $btn.closest('tr').remove();
                    $('#sc-privacy-list tr').each(function (i) {
                        $(this).attr('data-index', i).find('.sc-delete-privacy-zone').attr('data-index', i);
                    });
                    if (!$('#sc-privacy-list tr').length) {
                        $('#sc-privacy-list').append('<tr id="sc-privacy-empty"><td colspan="5">No privacy zones defined yet.</td></tr>');
                    }
                    showPrivacyMsg('success', 'Zone deleted.');
                } else {
                    showPrivacyMsg('error', response.data.message || 'Failed to delete zone.');
                }
            },
            error: function () { showPrivacyMsg('error', 'Server error. Please try again.'); }
        });
    });

    function showPrivacyMsg(type, message) {
        $privacyMsg.html('<div class="storm-chases-notice ' + type + '">' + message + '</div>');
        setTimeout(function () { $privacyMsg.empty(); }, 6000);
    }
});
