(function() {
    'use strict';
    const __ = wp.i18n.__;

    // Localized in includes/blocks.php from sc_get_chase_years() — years with at least one
    // published chase, newest first.
    const years = window.stormChasesArchiveYears || [];
    const yearOptions = [{value: '', label: __('All Years', 'stormchases')}].concat(
        years.map((y) => ({value: y, label: y}))
    );

    window.StormChasesBlocks.registerServerRenderedBlock({
        name: 'stormchases/chase-archive',
        panelTitle: __('Chase Archive Settings', 'stormchases'),
        fields: [
            {
                type: 'yearSelect',
                attribute: 'year',
                label: __('Year', 'stormchases'),
                options: yearOptions,
                overrideLabel: __('Or type a year not listed above', 'stormchases'),
                overrideHelp: __('Only years with at least one logged chase appear in the dropdown — type a year here for one that isn\'t listed yet (a future season, or one you haven\'t backfilled).', 'stormchases'),
            },
            {type: 'number', attribute: 'show', label: __('Number of chases to show (default: all)', 'stormchases'), step: '1'},
            {type: 'toggle', attribute: 'showHeading', label: __('Show heading', 'stormchases')},
            {type: 'text', attribute: 'heading', label: __('Heading text (blank = default)', 'stormchases')},
            {type: 'toggle', attribute: 'showTornadoIcon', label: __('Show tornado count icon', 'stormchases')},
            {type: 'toggle', attribute: 'showHailIcon', label: __('Show largest hail icon', 'stormchases')},
            {type: 'toggle', attribute: 'showWindIcon', label: __('Show highest wind icon', 'stormchases')},
            {type: 'toggle', attribute: 'showReportsIcon', label: __('Show Spotter Network report count icon', 'stormchases')},
        ],
    });
})();
