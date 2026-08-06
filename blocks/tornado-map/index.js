(function() {
    'use strict';
    const __ = wp.i18n.__;

    const EF_RATINGS = [
        {value: 'ef5', label: __('EF-5', 'stormchases')},
        {value: 'ef4', label: __('EF-4', 'stormchases')},
        {value: 'ef3', label: __('EF-3', 'stormchases')},
        {value: 'ef2', label: __('EF-2', 'stormchases')},
        {value: 'ef1', label: __('EF-1', 'stormchases')},
        {value: 'ef0', label: __('EF-0', 'stormchases')},
        {value: 'unrated', label: __('Unrated', 'stormchases')},
    ];

    window.StormChasesBlocks.registerServerRenderedBlock({
        name: 'stormchases/tornado-map',
        panelTitle: __('Tornado Map Settings', 'stormchases'),
        fields: [
            {type: 'text', attribute: 'year', label: __('Year (blank = all years)', 'stormchases')},
            {type: 'toggle', attribute: 'showHeading', label: __('Show heading', 'stormchases')},
            {type: 'text', attribute: 'heading', label: __('Heading text (blank = default)', 'stormchases')},
            {type: 'range', attribute: 'height', label: __('Map height (px)', 'stormchases'), min: 250, max: 900, step: 50},
            {type: 'checkboxGroup', attribute: 'ratings', label: __('Show EF Ratings', 'stormchases'), options: EF_RATINGS},
        ],
    });
})();
