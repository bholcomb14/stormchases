(function() {
    'use strict';
    const __ = wp.i18n.__;

    window.StormChasesBlocks.registerServerRenderedBlock({
        name: 'stormchases/current-location',
        panelTitle: __('Current Location Settings', 'stormchases'),
        fields: [
            {type: 'text', attribute: 'url', label: __('Embed URL', 'stormchases')},
            {type: 'range', attribute: 'height', label: __('Height (px)', 'stormchases'), min: 300, max: 1200, step: 50},
        ],
    });
})();
