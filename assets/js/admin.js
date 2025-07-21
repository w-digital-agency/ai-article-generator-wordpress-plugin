jQuery(document).ready(function($) {
    // Tab handling
    $('.aag-tabs .nav-tab').on('click', function(e) {
        e.preventDefault();
        var target = $(this).attr('href');
        
        // Update tabs
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Update content
        $('.aag-tab-pane').removeClass('active');
        $(target).addClass('active');
    });

    // Toggle password visibility
    $('.toggle-password').on('click', function() {
        var input = $(this).prev('input');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            $(this).text('Hide');
        } else {
            input.attr('type', 'password');
            $(this).text('Show');
        }
    });

    // Test Notion connection
    $('#test-notion-connection').on('click', function() {
        var $button = $(this);
        var $status = $('.aag-key-status');
        var $tokenInput = $('input[name="aag_notion_token"]');
        var token = $tokenInput.val().trim();
        
        // Basic client-side validation
        if (!token) {
            $status.removeClass('valid').addClass('invalid')
                .html('<span class="dashicons dashicons-warning"></span> Please enter a Notion token');
            return;
        }
        
        if (!token.startsWith('secret_')) {
            $status.removeClass('valid').addClass('invalid')
                .html('<span class="dashicons dashicons-warning"></span> Token must start with "secret_"');
            return;
        }
        
        if (token.length !== 50) {
            $status.removeClass('valid').addClass('invalid')
                .html('<span class="dashicons dashicons-warning"></span> Token should be 50 characters long (secret_ + 43 chars)');
            return;
        }
        
        $button.prop('disabled', true).text('Testing...');
        $status.removeClass('valid invalid').html('<span class="dashicons dashicons-update"></span> Testing connection...');
        
        $.ajax({
            url: aagAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'aag_test_notion_connection',
                nonce: aagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.removeClass('invalid').addClass('valid')
                        .html('<span class="dashicons dashicons-yes"></span> Connection successful! Token is valid.');
                } else {
                    $status.removeClass('valid').addClass('invalid')
                        .html('<span class="dashicons dashicons-warning"></span> Connection failed: ' + response.data);
                }
            },
            error: function() {
                $status.removeClass('valid').addClass('invalid')
                    .html('<span class="dashicons dashicons-warning"></span> Network error - please try again');
            },
            complete: function() {
                $button.prop('disabled', false).text('Test Connection');
            }
        });
    });

    // Get Notion databases
    $('#get-notion-databases').on('click', function() {
        var $button = $(this);
        var $list = $('#notion-databases-list');
        
        $button.prop('disabled', true).text('Loading...');
        
        $.ajax({
            url: aagAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'aag_get_notion_databases',
                nonce: aagAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    var html = '<h4>Available Databases:</h4><ul>';
                    response.data.forEach(function(db) {
                        var title = db.title && db.title[0] ? db.title[0].plain_text : 'Untitled Database';
                        html += '<li><a href="#" class="select-database" data-id="' + db.id + '">' + title + '</a> <small>(' + db.id + ')</small></li>';
                    });
                    html += '</ul>';
                    $list.html(html).show();
                } else {
                    $list.html('<p>No databases found or connection failed.</p>').show();
                }
            },
            error: function() {
                $list.html('<p>Failed to load databases.</p>').show();
            },
            complete: function() {
                $button.prop('disabled', false).text('Browse Databases');
            }
        });
    });

    // Select database
    $(document).on('click', '.select-database', function(e) {
        e.preventDefault();
        var databaseId = $(this).data('id');
        $('input[name="aag_notion_database_id"]').val(databaseId);
        $('#notion-databases-list').hide();
        showToast('Database selected successfully', 'success');
    });

    // Sync Notion now
    $('#sync-notion-now').on('click', function() {
        var $button = $(this);
        
        $button.prop('disabled', true).text('Syncing...');
        
        $.ajax({
            url: aagAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'aag_sync_notion_now',
                nonce: aagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var message = 'Sync completed! Synced: ' + response.data.synced + ' posts';
                    if (response.data.errors.length > 0) {
                        message += '. Errors: ' + response.data.errors.length;
                    }
                    showToast(message, 'success');
                } else {
                    showToast('Sync failed: ' + response.data, 'error');
                }
            },
            error: function() {
                showToast('Sync failed due to network error', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Sync Now');
            }
        });
    });

    // API Key validation
    $('.aag-api-key-input input').on('input blur', function() {
        var $input = $(this);
        var $status = $input.siblings('.aag-key-status');
        var key = $input.val().trim();
        var validateType = $input.data('validate');

        if (!key) {
            $status.removeClass('valid invalid').html('');
            return;
        }

        var isValidFormat = false;
        var errorMessage = '';
        
        // Notion token validation
        if (validateType === 'notion') {
            if (!key.startsWith('secret_')) {
                errorMessage = 'Token must start with "secret_"';
            } else if (key.length !== 50) {
                errorMessage = 'Token should be 50 characters long';
            } else if (!/^secret_[a-zA-Z0-9]{43}$/.test(key)) {
                errorMessage = 'Invalid token format';
            } else {
                isValidFormat = true;
            }
        } else {
            // Other API keys validation
            isValidFormat = /^[a-zA-Z0-9_-]{20,}$/.test(key);
            if (!isValidFormat) {
                errorMessage = 'Invalid key format';
            }
        }
        
        if (!isValidFormat) {
            $status.removeClass('valid').addClass('invalid')
                .html('<span class="dashicons dashicons-warning"></span> ' + errorMessage);
            return;
        }

        // Show valid format for client-side validation
        $status.removeClass('invalid').addClass('valid')
            .html('<span class="dashicons dashicons-yes"></span> Valid format - use "Test Connection" to verify');
    });

    // Toast notifications
    function showToast(message, type) {
        var toast = $('<div class="aag-toast ' + type + '">' + message + '</div>');
        $('body').append(toast);
        toast.fadeIn();
        
        setTimeout(function() {
            toast.fadeOut(function() {
                toast.remove();
            });
        }, 3000);
    }

    // Form validation
    $('.aag-settings-form').on('submit', function(e) {
        var apiKeys = $(this).find('input[type="password"]');
        var hasKeys = false;
        
        apiKeys.each(function() {
            if ($(this).val()) {
                hasKeys = true;
                return false;
            }
        });
        
        if (!hasKeys) {
            if (!confirm('No API keys are set. The plugin will not function without at least one API key. Continue anyway?')) {
                e.preventDefault();
            }
        }
    });

    // Initialize first tab as active
    if ($('.nav-tab-active').length === 0) {
        $('.nav-tab').first().addClass('nav-tab-active');
        $('.aag-tab-pane').first().addClass('active');
    }

    // Debug Tools functionality
    $('#debug-notion-sync').on('click', function() {
        var $button = $(this);
        var $results = $('#debug-results');
        var $summary = $('#debug-summary');
        var $logs = $('#debug-logs');
        
        $button.prop('disabled', true).text('🔄 Running Debug Test...');
        $results.show();
        $summary.html('<p>Running comprehensive debug test...</p>');
        $logs.html('');
        
        $.ajax({
            url: aagAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'aag_debug_notion_sync',
                nonce: aagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var summary = data.summary;
                    
                    var statusColor = summary.status === 'success' ? 'green' : 
                                    summary.status === 'warning' ? 'orange' : 'red';
                    
                    $summary.html(
                        '<div style="padding: 15px; border-left: 4px solid ' + statusColor + '; background: #f9f9f9;">' +
                        '<h4 style="margin: 0 0 10px;">Debug Summary</h4>' +
                        '<p><strong>Status:</strong> <span style="color: ' + statusColor + ';">' + summary.status.toUpperCase() + '</span></p>' +
                        '<p><strong>Total Logs:</strong> ' + summary.total_logs + '</p>' +
                        '<p><strong>Errors:</strong> ' + summary.errors + '</p>' +
                        '<p><strong>Warnings:</strong> ' + summary.warnings + '</p>' +
                        '</div>'
                    );
                    
                    // Display logs
                    var logsHtml = '<h4>Debug Logs</h4><div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">';
                    data.logs.forEach(function(log) {
                        var levelColor = log.level === 'error' ? 'red' : 
                                       log.level === 'warning' ? 'orange' : 
                                       log.level === 'info' ? 'blue' : 'black';
                        
                        logsHtml += '<div style="margin-bottom: 5px;">' +
                                   '<span style="color: #666; font-size: 12px;">' + log.timestamp + '</span> ' +
                                   '<span style="color: ' + levelColor + '; font-weight: bold;">[' + log.level.toUpperCase() + ']</span> ' +
                                   '<span>' + log.message + '</span>' +
                                   '</div>';
                    });
                    logsHtml += '</div>';
                    $logs.html(logsHtml);
                    
                    showToast('Debug test completed successfully', 'success');
                } else {
                    $summary.html('<div style="color: red;">Debug test failed: ' + (response.data ? response.data.message : 'Unknown error') + '</div>');
                    showToast('Debug test failed', 'error');
                }
            },
            error: function() {
                $summary.html('<div style="color: red;">Debug test failed due to network error</div>');
                showToast('Debug test failed due to network error', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('🧪 Run Full Debug Test');
            }
        });
    });

    // Test block conversion
    $('#test-block-conversion').on('click', function() {
        var $button = $(this);
        
        $button.prop('disabled', true).text('🔄 Testing...');
        
        $.ajax({
            url: aagAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'aag_test_notion_block_conversion',
                nonce: aagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var blocks = response.data.blocks;
                    var html = '<h4>Block Conversion Test Results</h4>';
                    
                    blocks.forEach(function(block, index) {
                        html += '<div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
                        html += '<h5>Block #' + (index + 1) + ' - Type: ' + block.original.type + '</h5>';
                        html += '<p><strong>Converted HTML:</strong></p>';
                        html += '<pre style="background: #fff; padding: 10px; border: 1px solid #ccc; overflow-x: auto;">' + 
                               block.converted.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</pre>';
                        html += '<p><strong>Rendered Preview:</strong></p>';
                        html += '<div style="border: 1px solid #ccc; padding: 10px; background: #fff;">' + block.converted + '</div>';
                        html += '</div>';
                    });
                    
                    $('#debug-results').show();
                    $('#debug-logs').html(html);
                    showToast('Block conversion test completed', 'success');
                } else {
                    showToast('Block conversion test failed: ' + response.data, 'error');
                }
            },
            error: function() {
                showToast('Block conversion test failed due to network error', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('🔄 Test Block Conversion');
            }
        });
    });

    // Clear debug log
    $('#clear-debug-log').on('click', function() {
        $('#debug-results').hide();
        $('#debug-summary').html('');
        $('#debug-logs').html('');
        showToast('Debug log cleared', 'success');
    });
});