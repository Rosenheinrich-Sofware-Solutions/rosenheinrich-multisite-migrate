(function ($) {
    'use strict';

    function i18n(key, fallback) {
        return rmmigrateAdminUI.i18n(key, fallback);
    }


    function setToolStatus($el, message, state) {
        if (!$el || !$el.length) {
            return;
        }
        $el.removeClass('is-ok is-error is-busy');
        if (!message) {
            $el.attr('hidden', true).text('');
            return;
        }
        $el.removeAttr('hidden').text(message);
        if (state === 'ok') {
            $el.addClass('is-ok');
        } else if (state === 'error') {
            $el.addClass('is-error');
        } else {
            $el.addClass('is-busy');
        }
    }

        $('#mm-prune-now').on('click', function () {
        rmmigrateAdminUI.confirm(i18n('pruneConfirm', 'Delete old backups according to retention rules?'), function () {
            var $status = $('#mm-prune-status');
            setToolStatus($status, '...', 'busy');
            $.post(rmmigrateAdmin.ajaxUrl, {
                action: 'rmmigrate_prune',
                nonce: rmmigrateAdmin.nonce
            }).done(function (res) {
                if (res.success) {
                    setToolStatus($status, res.data.message || i18n('done', 'Done'), 'ok');
                } else {
                    setToolStatus($status, i18n('failed', 'Failed'), 'error');
                }
            }).fail(function () {
                setToolStatus($status, i18n('failed', 'Failed'), 'error');
            });
        });
    });

    $('#mm-clean-storage').on('click', function () {
        rmmigrateAdminUI.confirm(i18n('cleanStorageConfirm', 'Delete orphaned job work folders and partial uploads? Finished backups are kept.'), function () {
            var $status = $('#mm-clean-storage-status');
            setToolStatus($status, '...', 'busy');
            $.post(rmmigrateAdmin.ajaxUrl, {
                action: 'rmmigrate_clean_storage',
                nonce: rmmigrateAdmin.nonce
            }).done(function (res) {
                if (res.success) {
                    setToolStatus($status, res.data.message || i18n('done', 'Done'), 'ok');
                } else {
                    setToolStatus($status, i18n('failed', 'Failed'), 'error');
                }
            }).fail(function () {
                setToolStatus($status, i18n('failed', 'Failed'), 'error');
            });
        });
    });

function formatInstallCleanupSize(bytes) {
        bytes = parseInt(bytes, 10) || 0;
        if (bytes < 1024) {
            return bytes + ' B';
        }
        if (bytes < 1048576) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function renderInstallCleanupScan(res) {
        var $status = $('#mm-install-cleanup-status');
        var $list = $('#mm-install-cleanup-list');
        var $btn = $('#mm-delete-install-files');
        if (!$btn.length) {
            return;
        }
        if (!res || !res.success) {
            $status.text(i18n('installCleanupScanFailed', 'Could not scan for installation files.'));
            $btn.prop('disabled', true);
            $list.prop('hidden', true).empty();
            return;
        }
        var items = (res.data && res.data.items) ? res.data.items : [];
        var found = items.length;
        if (found === 0) {
            $status.text(i18n('installCleanupNone', 'No installation files detected.'));
            $btn.prop('disabled', true);
            $list.prop('hidden', true).empty();
            return;
        }
        $status.text(i18n('installCleanupFound', 'Found %d installation file path(s).').replace('%d', String(found)));
        $btn.prop('disabled', false);
        $list.empty().prop('hidden', false);
        items.forEach(function (item) {
            var label = item.label || item.path || '';
            var size = formatInstallCleanupSize(item.size);
            $list.append($('<li></li>').text(label + ' (' + size + ')'));
        });
    }

    function scanInstallFiles() {
        var $status = $('#mm-install-cleanup-status');
        var $btn = $('#mm-delete-install-files');
        if (!$btn.length) {
            return;
        }
        $status.text(i18n('installCleanupScanning', 'Scanning for installation files…'));
        $btn.prop('disabled', true);
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_scan_install_files',
            nonce: rmmigrateAdmin.nonce
        }).done(renderInstallCleanupScan).fail(function () {
            renderInstallCleanupScan(null);
        });
    }

    if ($('#mm-delete-install-files').length) {
        scanInstallFiles();
        $('#mm-delete-install-files').on('click', function () {
            var $status = $('#mm-install-cleanup-status');
            var count = $('#mm-install-cleanup-list li').length;
            var message = i18n('installCleanupConfirm', 'Delete %d installation file path(s) from the web root? This cannot be undone.')
                .replace('%d', String(count));
            rmmigrateAdminUI.confirm(message, function () {
                $status.text(i18n('installCleanupDeleting', 'Deleting installation files…'));
                $('#mm-delete-install-files').prop('disabled', true);
                $.post(rmmigrateAdmin.ajaxUrl, {
                    action: 'rmmigrate_delete_install_files',
                    nonce: rmmigrateAdmin.nonce
                }).done(function (res) {
                    if (res.success) {
                        $status.text(res.data.message || i18n('done', 'Done'));
                    } else {
                        $status.text(i18n('installCleanupFailed', 'Could not delete installation files.'));
                    }
                    scanInstallFiles();
                }).fail(function () {
                    $status.text(i18n('installCleanupFailed', 'Could not delete installation files.'));
                    scanInstallFiles();
                });
            });
        });
    }

    $('#mm-clear-activity-log').on('click', function (e) {
        e.preventDefault();
        var url = $(this).data('url');
        var message = $(this).data('message') || i18n('clearActivityLog', 'Clear activity log and all log files on disk?');
        rmmigrateAdminUI.confirm(message, function () {
            window.location.href = url;
        });
    });

    $(document).on('change', '.mm-file-picker__input', function () {
        var $name = $(this).closest('.mm-file-picker').find('.mm-file-picker__name');
        var empty = $name.data('empty') || 'No file chosen';
        var file = this.files && this.files[0];
        $name.text(file ? file.name : empty);
    });

    $(document).on('click', '#mm-sr-run, #mm-sr-preview', function () {
        var oldUrl = $('#mm-sr-old').val();
        var newUrl = $('#mm-sr-new').val();
        var $result = $('#mm-sr-result');

        if (!newUrl) {
            rmmigrateAdminUI.toast(i18n('enterNewUrl', 'Enter a valid new site URL.'), 'error');
            return;
        }

        rmmigrateAdminUI.confirm(
            i18n('srConfirmRun', 'Run Search & Replace on your database? Old URL: ') + oldUrl + ' → ' + newUrl,
            function () {
                $result.html('<div style="margin-top: 10px; color: var(--mm-text-muted);">' + i18n('loading', 'Loading…') + '</div>');

                $.post(rmmigrateAdmin.ajaxUrl, {
                    action: 'rmmigrate_search_replace_preview',
                    nonce: rmmigrateAdmin.nonce,
                    old_url: oldUrl,
                    new_url: newUrl
                }).done(function (res) {
                    if (!res.success || !res.data || !res.data.pairs) {
                        var errMsg = res.data && res.data.message ? res.data.message : i18n('failed', 'Failed');
                        $result.empty();
                        rmmigrateAdminUI.toast(errMsg, 'error');
                        return;
                    }
                    var pairs = res.data.pairs;
                    $result.empty();
                    rmmigrateAdminUI.showNotice(
                        i18n('srSuccess', 'Search & Replace configuration ready with %d replacement rule(s).').replace('%d', String(pairs.length)),
                        'success'
                    );
                }).fail(function () {
                    $result.empty();
                    rmmigrateAdminUI.toast(i18n('failed', 'Failed'), 'error');
                });
            }
        );
    });

    $('#mm-test-hosting-detection').on('click', function () {
        var $btn = $(this);
        var $status = $('#mm-hosting-detection-status');
        $btn.prop('disabled', true);
        $status.text('…');
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_test_hosting',
            nonce: rmmigrateAdmin.nonce
        }).done(function (res) {
            if (res.success && res.data && res.data.message) {
                $status.text(res.data.message);
                if (res.data.reload) {
                    setTimeout(function () { window.location.reload(); }, 800);
                }
            } else {
                $status.text(res.data && res.data.message ? res.data.message : i18n('failed', 'Failed'));
            }
        }).fail(function () {
            $status.text(i18n('failed', 'Failed'));
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });
})(jQuery);
