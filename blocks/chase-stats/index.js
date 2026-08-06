(function() {
    'use strict';
    const __ = wp.i18n.__;

    window.StormChasesBlocks.registerServerRenderedBlock({
        name: 'stormchases/chase-stats',
        panelTitle: __('Chase Stats Settings', 'stormchases'),
        fields: [
            {type: 'text', attribute: 'year', label: __('Year (blank = all time)', 'stormchases')},
            {type: 'toggle', attribute: 'showHeading', label: __('Show heading', 'stormchases')},
            {type: 'text', attribute: 'heading', label: __('Heading text (blank = default)', 'stormchases')},
        ],
    });
})();
