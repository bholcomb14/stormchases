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
            {type: 'toggle', attribute: 'showOverall', label: __('Show overall stats (chase days, miles, states…)', 'stormchases')},
            {type: 'toggle', attribute: 'showConvective', label: __('Show Convective section', 'stormchases')},
            {type: 'toggle', attribute: 'showHurricane', label: __('Show Hurricane section', 'stormchases')},
            {type: 'toggle', attribute: 'showWinter', label: __('Show Winter section', 'stormchases')},
            {type: 'toggle', attribute: 'showLongestChase', label: __('Show longest chase (in overall stats)', 'stormchases')},
            {type: 'toggle', attribute: 'showEfBreakdown', label: __('Show EF rating breakdown (in Convective)', 'stormchases')},
            {type: 'toggle', attribute: 'showStormModes', label: __('Show storm mode breakdown (in Convective)', 'stormchases')},
            {type: 'toggle', attribute: 'showTopDays', label: __('Show "Biggest Chase Days" sortable table', 'stormchases')},
            {type: 'number', attribute: 'topDaysCount', label: __('Number of days in that table', 'stormchases'), step: '1'},
            {type: 'toggle', attribute: 'showFirstLast', label: __('Show first/last tornado & landfall of the season', 'stormchases')},
            {type: 'toggle', attribute: 'showNewStates', label: __('Show "new states this season" callout', 'stormchases')},
            {type: 'toggle', attribute: 'showStreak', label: __('Show consecutive-year chase streak', 'stormchases')},
            {type: 'toggle', attribute: 'showTopPeople', label: __('Show Top Chase Partners / Most Encountered Chasers (all-time only)', 'stormchases')},
            {type: 'number', attribute: 'topPeopleCount', label: __('Number of people in each list', 'stormchases'), step: '1'},
        ],
    });

    // Applies the Settings page's "Chase Stats Block Defaults" (stormChasesStatsDefaults,
    // localized in includes/blocks.php from the stats_default_* options) as this block's
    // attribute defaults — only affects a brand new block instance (a block already placed
    // on a page keeps its own saved attributes; registerBlockVariation only supplies values
    // for ones that aren't already set). Uses the block variations API rather than filtering
    // blocks.registerBlockType's attribute schema — this block's own registerBlockType call
    // above doesn't pass an attributes list at all (it relies on the schema already
    // registered server-side from block.json), so a blocks.registerBlockType filter reading
    // settings.attributes here would have nothing to act on. isDefault: true is what makes
    // this the variation createBlock() and the inserter use when no attributes are otherwise
    // specified — the officially documented way to give a block different default attribute
    // values without touching block.json.
    if (window.stormChasesStatsDefaults) {
        wp.blocks.registerBlockVariation('stormchases/chase-stats', {
            name: 'stormchases-chase-stats-default',
            title: __('Chase Stats', 'stormchases'),
            isDefault: true,
            attributes: window.stormChasesStatsDefaults,
        });
    }
})();
