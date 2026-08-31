(function() {
    'use strict';

    const el = wp.element.createElement;
    const Fragment = wp.element.Fragment;
    const InspectorControls = wp.blockEditor.InspectorControls;
    const useBlockProps = wp.blockEditor.useBlockProps;
    const ServerSideRender = wp.serverSideRender;
    const PanelBody = wp.components.PanelBody;
    const TextControl = wp.components.TextControl;
    const ToggleControl = wp.components.ToggleControl;
    const RangeControl = wp.components.RangeControl;
    const CheckboxControl = wp.components.CheckboxControl;
    const SelectControl = wp.components.SelectControl;
    const __ = wp.i18n.__;

    function setSingle(setAttributes, attribute, value) {
        const update = {};
        update[attribute] = value;
        setAttributes(update);
    }

    function buildControl(field, attributes, setAttributes) {
        if (field.type === 'text') {
            return el(TextControl, {
                key: field.attribute,
                label: field.label,
                value: attributes[field.attribute] || '',
                onChange: (val) => setSingle(setAttributes, field.attribute, val),
            });
        }
        if (field.type === 'number') {
            return el(TextControl, {
                key: field.attribute,
                label: field.label,
                type: 'number',
                step: field.step || 'any',
                value: attributes[field.attribute],
                onChange: (val) => setSingle(setAttributes, field.attribute, val === '' ? 0 : parseFloat(val)),
            });
        }
        if (field.type === 'toggle') {
            return el(ToggleControl, {
                key: field.attribute,
                label: field.label,
                checked: !!attributes[field.attribute],
                onChange: (val) => setSingle(setAttributes, field.attribute, val),
            });
        }
        if (field.type === 'range') {
            return el(RangeControl, {
                key: field.attribute,
                label: field.label,
                value: attributes[field.attribute],
                min: field.min,
                max: field.max,
                step: field.step,
                onChange: (val) => setSingle(setAttributes, field.attribute, val),
            });
        }
        if (field.type === 'select') {
            return el(SelectControl, {
                key: field.attribute,
                label: field.label,
                value: attributes[field.attribute] || '',
                options: field.options,
                onChange: (val) => setSingle(setAttributes, field.attribute, val),
            });
        }
        // Like 'select', but paired with a free-text field bound to the same attribute —
        // for cases like the Chase Archive block's Year field, where the dropdown only
        // lists years that already have a chase logged, but a year with none yet (a future
        // season, or one not backfilled) should still be typeable. Both controls write the
        // same attribute, so whichever one was used last simply wins.
        if (field.type === 'yearSelect') {
            return el(Fragment, {key: field.attribute},
                el(SelectControl, {
                    label: field.label,
                    value: attributes[field.attribute] || '',
                    options: field.options,
                    onChange: (val) => setSingle(setAttributes, field.attribute, val),
                }),
                el(TextControl, {
                    label: field.overrideLabel || __('Or type a year not listed above', 'stormchases'),
                    help: field.overrideHelp,
                    value: attributes[field.attribute] || '',
                    onChange: (val) => setSingle(setAttributes, field.attribute, val),
                })
            );
        }
        if (field.type === 'checkboxGroup') {
            const current = attributes[field.attribute] || [];
            const items = field.options.map((opt) => el(CheckboxControl, {
                key: opt.value,
                label: opt.label,
                checked: current.indexOf(opt.value) !== -1,
                onChange: (checked) => {
                    let next = current.slice();
                    if (checked && next.indexOf(opt.value) === -1) {
                        next.push(opt.value);
                    } else if (!checked) {
                        next = next.filter((v) => v !== opt.value);
                    }
                    setSingle(setAttributes, field.attribute, next);
                },
            }));
            return el(Fragment, {key: field.attribute},
                field.label ? el('p', {className: 'components-base-control__label'}, field.label) : null,
                items
            );
        }
        return null;
    }

    // config: {name, panelTitle, fields: [{type, attribute, label, ...}]}
    // Renders a standard Inspector-controls-driven, ServerSideRender-previewed
    // dynamic block — every field just maps an attribute to a control, and the
    // preview always reflects the current attributes via WP's block-renderer
    // REST endpoint (same PHP render.php the frontend uses). For the map blocks, the
    // interactive map itself actually initializing inside the editor canvas is handled
    // entirely outside this component — see sc_enqueue_editor_preview_assets() in
    // functions.php and the MutationObserver in frontend.js's initializeMapShortcodes()
    // path, not anything block-specific here.
    function registerServerRenderedBlock(config) {
        wp.blocks.registerBlockType(config.name, {
            edit(props) {
                const {attributes, setAttributes} = props;
                const blockProps = useBlockProps();
                const controls = config.fields.map((field) => buildControl(field, attributes, setAttributes));

                return el(Fragment, {},
                    el(InspectorControls, {},
                        el(PanelBody, {title: config.panelTitle || __('Settings', 'stormchases')}, controls)
                    ),
                    el('div', blockProps,
                        el(ServerSideRender, {block: config.name, attributes: attributes})
                    )
                );
            },
            save() {
                return null;
            },
        });
    }

    window.StormChasesBlocks = window.StormChasesBlocks || {};
    window.StormChasesBlocks.registerServerRenderedBlock = registerServerRenderedBlock;
})();
