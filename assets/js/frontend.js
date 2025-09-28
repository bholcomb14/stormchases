(function($) {
    'use strict';

    let mapsEnabled = stormChasesFrontend.googleMapsEnabled || false;
    // Set to false in production to suppress non-error logs
    const debugMode = false;

    function tryInitializeModals() {
        if (debugMode) console.log('Attempting to initialize modals');
        if ($('#chase-details').length) {
            if ($('.tornado-link').length || $('.report-link').length) {
                if (debugMode) console.log('Modal links found, initializing modals');
                initializeModals();
            } else {
                if (debugMode) console.warn('No modal links found in #chase-details');
            }
        } else {
            if (debugMode) console.log('No chase details container found, skipping modal initialization');
        }
    }

    $(document).ready(function() {
        if (debugMode) console.log('Document ready, starting modal initialization');
        setTimeout(tryInitializeModals, 0);
    });

    function initializeModals() {
        if (debugMode) {
            console.log('Initializing modals');
            console.log('Found .tornado-link: ', $('.tornado-link').length);
            console.log('Found .report-link: ', $('.report-link').length);
            console.log('Chase details container exists: ', $('#chase-details').length > 0);
        }

        if ($('.tornado-link, .report-link').length === 0) {
            console.warn('No modal links found in DOM');
            StormChases.log('No modal links found in DOM', 'error');
        }

        $(document).off('click.tornadoLink').on('click.tornadoLink', '.tornado-link, .report-link', function(e) {
            e.preventDefault();
            const modalId = $(this).data('modal-id');
            if (debugMode) console.log('Clicked link with data-modal-id: ' + modalId);
            const modal = $('#' + modalId);
            if (modal.length) {
                if (debugMode) console.log('Modal found: ' + modalId);
                modal.fadeIn(200);
                modal.find('.modal-close').focus();
                StormChases.log('Opened modal: ' + modalId, 'info');
            } else {
                console.warn('Modal not found: ' + modalId);
                StormChases.log('Modal not found: ' + modalId, 'error');
            }
        });

        $(document).off('click.modalClose').on('click.modalClose', '.modal-close', function() {
            if (debugMode) console.log('Clicked modal-close');
            $(this).closest('.modal').fadeOut(200);
            StormChases.log('Closed modal: ' + $(this).closest('.modal').attr('id'), 'info');
        });

        $(document).off('click.modalOverlay').on('click.modalOverlay', '.modal-overlay', function() {
            if (debugMode) console.log('Clicked modal-overlay');
            $(this).closest('.modal').fadeOut(200);
            StormChases.log('Closed modal via overlay: ' + $(this).closest('.modal').attr('id'), 'info');
        });

        $(document).off('keydown.modal').on('keydown.modal', function(e) {
            if (e.key === 'Escape') {
                if (debugMode) console.log('Escape key pressed');
                $('.modal').fadeOut(200);
                StormChases.log('Closed modals via Escape key', 'info');
            }
        });
    }

    function initializeChaseMap() {
        if (debugMode) console.log('Initializing chase map with chasemapUrl: ' + (stormChasesFrontend.chasemapUrl || 'none') + ', chasemapType: ' + (stormChasesFrontend.chasemapType || 'none'));
        if (!mapsEnabled || typeof google === 'undefined' || !google.maps) {
            console.warn('Google Maps API not loaded for chase map.');
            StormChases.log('Google Maps API not loaded for chase map.', 'error');
            return;
        }

        if (!stormChasesFrontend.chasemapUrl || stormChasesFrontend.chasemapType !== '1') {
            console.warn('Chase map not initialized: Missing chasemapUrl (' + (stormChasesFrontend.chasemapUrl || 'none') + ') or invalid chasemapType (' + (stormChasesFrontend.chasemapType || 'none') + ').');
            StormChases.log('Chase map not initialized: Missing chasemapUrl (' + (stormChasesFrontend.chasemapUrl || 'none') + ') or invalid chasemapType (' + (stormChasesFrontend.chasemapType || 'none') + ').', 'error');
            return;
        }

        const mapContainer = $('#chasemap');
        if (!mapContainer.length) {
            console.warn('Chase map container not found.');
            StormChases.log('Chase map container not found.', 'error');
            return;
        }

        const mapOptions = {
            center: { lat: 39.8283, lng: -98.5795 }, // Center of US
            zoom: 4,
            mapTypeId: google.maps.MapTypeId.ROADMAP,
            mapTypeControl: false,
            streetViewControl: false
        };

        try {
            const map = new google.maps.Map(mapContainer[0], mapOptions);
            const kmlLayer = new google.maps.KmlLayer({
                url: stormChasesFrontend.chasemapUrl + '?v=' + Date.now(),
                map: map,
                preserveViewport: false
            });

            kmlLayer.addListener('status_changed', function() {
                const status = kmlLayer.getStatus();
                if (status !== google.maps.KmlLayerStatus.OK) {
                    console.warn('KML layer failed to load: ' + status + ' for URL: ' + stormChasesFrontend.chasemapUrl);
                    StormChases.log('KML layer failed to load: ' + status + ' for URL: ' + stormChasesFrontend.chasemapUrl, 'error');
                    if (stormChasesFrontend.chasemapType === '2') {
                        mapContainer.replaceWith('<img src="' + stormChasesFrontend.chasemapUrl + '" alt="Chase Map" style="max-width: 100%; height: auto;" />');
                    }
                } else {
                    if (debugMode) console.log('KML layer loaded successfully for chase map.');
                    StormChases.log('KML layer loaded successfully for chase map.', 'info');
                }
            });
        } catch (error) {
            console.error('Failed to initialize chase map: ' + error.message);
            StormChases.log('Failed to initialize chase map: ' + error.message, 'error');
            mapContainer.html('<p style="color: red;">Failed to initialize chase map: ' + error.message + '</p>');
        }
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