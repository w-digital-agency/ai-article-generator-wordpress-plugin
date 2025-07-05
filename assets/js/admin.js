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
        
        $button.prop('disabled', true).text('Testing...');
        
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
                        .html('<span class="dashicons dashicons-yes"></span> ' + response.data);
                } else {
                    $status.removeClass('valid').addClass('invalid')
                        .html('<span class="dashicons dashicons-warning"></span> ' + response.data);
                }
            },
            error: function() {
                $status.removeClass('valid').addClass('invalid')
                    .html('<span class="dashicons dashicons-warning"></span> Connection failed');
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
    $('.aag-api-key-input input').on('blur', function() {
        var $input = $(this);
        var $status = $input.siblings('.aag-key-status');
        var key = $input.val().trim();

        if (!key) {
            $status.removeClass('valid invalid').html('');
            return;
        }

        // Basic format validation
        var isValidFormat = /^[a-zA-Z0-9_-]{20,}$/.test(key);
        
        if (!isValidFormat) {
            $status.removeClass('valid').addClass('invalid')
                .html('<span class="dashicons dashicons-warning"></span> Invalid key format');
            return;
        }

        // Server-side validation
        $.ajax({
            url: aagAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'aag_validate_api_key',
                nonce: aagAdmin.nonce,
                key: key,
                provider: $input.attr('name')
            },
            success: function(response) {
                if (response.success) {
                    $status.removeClass('invalid').addClass('valid')
                        .html('<span class="dashicons dashicons-yes"></span> Valid key');
                } else {
                    $status.removeClass('valid').addClass('invalid')
                        .html('<span class="dashicons dashicons-warning"></span> ' + response.data);
                }
            }
        });
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
});