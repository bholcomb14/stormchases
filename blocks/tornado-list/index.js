(function() {
    'use strict';
    const __ = wp.i18n.__;

    window.StormChasesBlocks.registerServerRenderedBlock({
        name: 'stormchases/tornado-list',
        panelTitle: __('Tornado List Settings', 'stormchases'),
        fields: [
            {type: 'text', attribute: 'year', label: __('Year (blank = all years)', 'stormchases')},
            {type: 'toggle', attribute: 'showHeading', label: __('Show heading', 'stormchases')},
            {type: 'text', attribute: 'heading', label: __('Heading text (blank = default)', 'stormchases')},
        ],
    });
})();
