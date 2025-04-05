jQuery(document).ready(function($) {
    // Initialize security charts
    function initSecurityCharts() {
        if ($('#securityEventsChart').length) {
            const ctx = document.getElementById('securityEventsChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: window.securityChartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    }

    // Security log filters
    $('#security-log-filter').on('change', function() {
        const severity = $(this).val();
        if (severity === 'all') {
            $('.aag-log-row').show();
        } else {
            $('.aag-log-row').hide();
            $('.aag-log-row[data-severity="' + severity + '"]').show();
        }
    });

    // API Key validation
    $('.aag-api-key-input input').on('blur', function() {
        const $input = $(this);
        const $status = $input.siblings('.aag-key-status');
        const key = $input.val().trim();

        if (!key) {
            $status.removeClass('valid invalid').html('');
            return;
        }

        // Basic format validation
        const isValidFormat = /^[a-zA-Z0-9_-]{20,}$/.test(key);
        
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

    // Security recommendations
    $('.aag-recommendation-action').on('click', function(e) {
        e.preventDefault();
        const action = $(this).data('action');
        const $item = $(this).closest('.aag-recommendation-item');

        $.ajax({
            url: aagAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'aag_handle_security_recommendation',
                nonce: aagAdmin.nonce,
                recommendation: action
            },
            beforeSend: function() {
                $item.addClass('processing');
            },
            success: function(response) {
                if (response.success) {
                    $item.addClass('completed');
                    showToast(response.data, 'success');
                } else {
                    showToast(response.data, 'error');
                }
            },
            complete: function() {
                $item.removeClass('processing');
            }
        });
    });

    // Export security logs
    $('#export-security-logs').on('click', function(e) {
        e.preventDefault();
        
        $.ajax({
            url: aagAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'aag_export_security_logs',
                nonce: aagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    const blob = new Blob([response.data], { type: 'text/csv' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'security-logs.csv';
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                } else {
                    showToast('Failed to export security logs', 'error');
                }
            }
        });
    });

    // Initialize components
    initSecurityCharts();

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

    // Security actions
    $('#rotate-encryption-key').on('click', function() {
        if (!confirm('Are you sure you want to rotate the encryption key? This will require re-entering all API keys.')) {
            return;
        }

        $.ajax({
            url: aagAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'aag_rotate_encryption_key',
                nonce: aagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showToast('Encryption key rotated successfully', 'success');
                } else {
                    showToast('Failed to rotate encryption key', 'error');
                }
            }
        });
    });

    $('#clear-api-keys').on('click', function() {
        if (!confirm('Are you sure you want to clear all API keys? This action cannot be undone.')) {
            return;
        }

        $.ajax({
            url: aagAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'aag_clear_api_keys',
                nonce: aagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showToast('All API keys cleared successfully', 'success');
                    // Clear all API key inputs
                    $('input[type="password"]').val('');
                } else {
                    showToast('Failed to clear API keys', 'error');
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
});
