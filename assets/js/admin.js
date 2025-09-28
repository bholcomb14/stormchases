jQuery(document).ready(function($) {
    'use strict';

    $('#chasedate').datepicker({
        dateFormat: 'yymmdd',
        changeMonth: true,
        changeYear: true,
        showButtonPanel: true,
        beforeShow: function(input, inst) {
            inst.dpDiv.addClass('storm-chases-datepicker');
        }
    });

    $('#add-tornado').on('click', function() {
        const container = $('#tornadoes-container');
        const count = parseInt(container.data('tornado-count'), 10) || 0;
        const newCount = count + 1;

        // Remove any existing hidden tornadoes input before adding a new entry
        container.find('input[name="tornadoes"]').remove();

        $.ajax({
            url: stormChasesSettings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'storm_chases_get_tornado_entry',
                nonce: stormChasesSettings.nonce,
                index: count
            },
            success: function(response) {
                if (response.success && response.data.entry) {
                    container.append(response.data.entry);
                    container.data('tornado-count', newCount);
                    bindTornadoEvents();
                    console.log('Added tornado entry, new count: ' + newCount);
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

    function bindTornadoEvents() {
        $('.remove-tornado').off('click').on('click', function() {
            $(this).closest('.tornado-entry').remove();
            const container = $('#tornadoes-container');
            const count = parseInt(container.data('tornado-count'), 10) || 1;
            const newCount = count - 1;
            container.data('tornado-count', newCount);

            // Remove any existing hidden tornadoes input
            container.find('input[name="tornadoes"]').remove();

            // If no tornado entries remain, add a hidden input to send an empty tornadoes array
            if (container.find('.tornado-entry').length === 0) {
                container.append('<input type="hidden" name="tornadoes" value="">');
                console.log('All tornadoes removed, added hidden input: name="tornadoes" value=""');
            } else {
                console.log('Tornado removed, remaining count: ' + newCount);
            }
        });

        $('.upload-tornado-photo-button').off('click').on('click', function(e) {
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
                console.log('Selected tornado photo, ID: ' + attachment.id);
            });

            frame.open();
        });
    }

    bindTornadoEvents();

    $('#upload-spotter-reports').on('click', function() {
        const form = $('#storm-chases-settings-form');
        const formData = new FormData(form[0]);
        formData.append('action', 'storm_chases_upload_spotter_reports');
        formData.append('nonce', stormChasesSettings.nonce);

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
                console.log('Raw response:', response); // Log raw response for debugging
                if (response && typeof response === 'object' && response.success) {
                    let message = (response.data && response.data.message) ? response.data.message : (response.message || 'Reports uploaded successfully.');
                    if (response.data && response.data.skipped > 0 && response.data.skip_reasons && response.data.skip_reasons.length > 0) {
                        message += '<br><strong>Skipped Rows:</strong><ul>';
                        response.data.skip_reasons.forEach(function(reason) {
                            message += '<li>' + reason + '</li>';
                        });
                        message += '</ul>';
                    }
                    if (response.data && response.data.unmatched_dates && Object.keys(response.data.unmatched_dates).length > 0) {
                        message += '<br><strong>Unmatched Dates (Count):</strong><ul>';
                        for (let date in response.data.unmatched_dates) {
                            message += '<li>' + date + ' (' + response.data.unmatched_dates[date] + ' reports)</li>';
                        }
                        message += '</ul>';
                    }
                    showNotice('success', message);
                } else {
                    let message = (response.data && response.data.message) ? response.data.message : (response.message || 'Failed to upload reports.');
                    if (response.data && response.data.skip_reasons && response.data.skip_reasons.length > 0) {
                        message += '<br><strong>Skipped Rows:</strong><ul>';
                        response.data.skip_reasons.forEach(function(reason) {
                            message += '<li>' + reason + '</li>';
                        });
                        message += '</ul>';
                    }
                    if (response.data && response.data.unmatched_dates && Object.keys(response.data.unmatched_dates).length > 0) {
                        message += '<br><strong>Unmatched Dates (Count):</strong><ul>';
                        for (let date in response.data.unmatched_dates) {
                            message += '<li>' + date + ' (' + response.data.unmatched_dates[date] + ' reports)</li>';
                        }
                        message += '</ul>';
                    }
                    showNotice('error', message);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', status, error, xhr.responseText);
                let message = 'Error uploading reports: ' + status;
                try {
                    const responseJSON = xhr.responseJSON;
                    if (responseJSON && responseJSON.message) {
                        message = responseJSON.message;
                    }
                    if (responseJSON && responseJSON.data && responseJSON.data.skip_reasons && responseJSON.data.skip_reasons.length > 0) {
                        message += '<br><strong>Skipped Rows:</strong><ul>';
                        responseJSON.data.skip_reasons.forEach(function(reason) {
                            message += '<li>' + reason + '</li>';
                        });
                        message += '</ul>';
                    }
                    if (responseJSON && responseJSON.data && responseJSON.data.unmatched_dates && Object.keys(responseJSON.data.unmatched_dates).length > 0) {
                        message += '<br><strong>Unmatched Dates (Count):</strong><ul>';
                        for (let date in responseJSON.data.unmatched_dates) {
                            message += '<li>' + date + ' (' + responseJSON.data.unmatched_dates[date] + ' reports)</li>';
                        }
                        message += '</ul>';
                    }
                } catch (e) {
                    message += '<br>Invalid server response format.';
                }
                showNotice('error', message);
            }
        });
    });

    function showNotice(type, message) {
        const notice = $('<div>', {
            class: 'storm-chases-notice ' + type,
            html: message
        }).appendTo('#upload-message');
        setTimeout(function() {
            notice.fadeOut(400, function() {
                $(this).remove();
            });
        }, 5000);
    }
});