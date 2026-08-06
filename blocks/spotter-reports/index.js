(function() {
    'use strict';
    const __ = wp.i18n.__;

    const REPORT_TYPES = [
        {value: 'tornado', label: __('Tornado', 'stormchases')},
        {value: 'hail', label: __('Hail', 'stormchases')},
        {value: 'wind', label: __('Wind', 'stormchases')},
        {value: 'funnel', label: __('Funnel Cloud', 'stormchases')},
        {value: 'wallcloud', label: __('Wall Cloud', 'stormchases')},
        {value: 'damage', label: __('Damage', 'stormchases')},
        {value: 'generic', label: __('Report (no specific type)', 'stormchases')},
    ];

    window.StormChasesBlocks.registerServerRenderedBlock({
        name: 'stormchases/spotter-reports',
        panelTitle: __('Spotter Reports Settings', 'stormchases'),
        fields: [
            {type: 'text', attribute: 'year', label: __('Year (blank = all years)', 'stormchases')},
            {type: 'toggle', attribute: 'showHeading', label: __('Show heading', 'stormchases')},
            {type: 'text', attribute: 'heading', label: __('Heading text (blank = default)', 'stormchases')},
            {type: 'range', attribute: 'height', label: __('Map height (px)', 'stormchases'), min: 250, max: 900, step: 50},
            {type: 'checkboxGroup', attribute: 'types', label: __('Show Report Types', 'stormchases'), options: REPORT_TYPES},
        ],
    });
})();
