(function ($) {
    'use strict';

    function t(key, fallback) {
        return rmmigrateAdminUI.i18n(key, fallback);
    }

    var pollTimer = null;
    var progressMode = 'restore';
    var restoreFocusReturn = null;

    function reloadWithoutJobId() {
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete('mm_restore_ok');
            url.searchParams.delete('mm_verify');
            if (url.searchParams.has('job_id')) {
                url.searchParams.delete('job_id');
                window.location.href = url.pathname + url.search + url.hash;
            } else {
                window.location.reload();
            }
        } catch (e) {
            window.location.reload();
        }
    }

    function reloadAfterRestoreSuccess(jobId) {
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete('create');
            url.searchParams.delete('mm_verify');
            if (jobId) {
                url.searchParams.set('job_id', String(jobId));
                url.searchParams.set('mm_restore_ok', '1');
            } else {
                url.searchParams.delete('job_id');
                url.searchParams.delete('mm_restore_ok');
            }
            window.location.href = url.pathname + url.search + url.hash;
        } catch (e) {
            window.location.reload();
        }
    }

    function showActiveJobDismiss() {
        var $banner = $('#mm-active-job-banner');
        if (!$banner.length || $banner.find('.mm-banner__dismiss').length) {
            return;
        }
        var $dismiss = $('<button type="button" class="button mm-banner__dismiss mm-dismiss-active-job">' + t('dismiss', 'Dismiss') + '</button>');
        $dismiss.on('click', function () {
            reloadWithoutJobId();
        });
        var $actions = $banner.find('.mm-active-job-actions');
        if ($actions.length) {
            $actions.empty().append($dismiss).show();
        } else {
            $banner.append($('<p class="mm-active-job-actions"></p>').append($dismiss));
        }
    }

    function hideRestoreCancelActions() {
        $('#mm-restore-cancel-progress, .mm-cancel-restore-progress, #mm-active-job-cancel').closest('p, .mm-active-job-actions').hide();
    }

    function showRestoreCancelActions(jobId) {
        var jid = jobId ? String(jobId) : '';
        var $btns = $('#mm-restore-cancel-progress, .mm-cancel-restore-progress');
        if (jid) {
            $btns.attr('data-job-id', jid);
            $('#mm-active-job-banner').attr('data-job-id', jid).data('job-id', jobId);
        }
        $btns.closest('p, .mm-active-job-actions').show();

        var $panel = $('#mm-restore-progress');
        if ($panel.length && !$panel.find('#mm-restore-cancel-progress, .mm-cancel-restore-progress').length) {
            $panel.append(
                $('<p class="mm-active-job-actions"></p>').append(
                    $('<button type="button" class="button" id="mm-restore-cancel-progress"></button>')
                        .attr('data-job-id', jid)
                        .text(t('cancelRestore', 'Cancel restore'))
                )
            );
        }

        var $banner = $('#mm-active-job-banner');
        if ($banner.length && !$banner.hasClass('mm-finished-job')
            && !$banner.find('.mm-cancel-restore-progress, #mm-restore-cancel-progress, #multisite-migrate-cancel').length) {
            var $actions = $banner.find('.mm-active-job-actions');
            if (!$actions.length) {
                $actions = $('<p class="mm-active-job-actions"></p>').appendTo($banner);
            }
            $actions.empty().append(
                $('<button type="button" class="button mm-cancel-restore-progress"></button>')
                    .attr('data-job-id', jid || String($banner.attr('data-job-id') || ''))
                    .text(t('cancelRestore', 'Cancel restore'))
            ).show();
        }
    }

    function redirectToJobProgress(id) {
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('job_id', String(id));
            window.location.href = url.pathname + url.search + url.hash;
        } catch (e) {
            window.location.reload();
        }
    }

    function progressFill() {
        var $fill = $('#mm-active-job-fill');
        return $fill.length ? $fill : $('#mm-restore-progress .mm-progress-fill');
    }

    function progressText() {
        var $text = $('#mm-active-job-text');
        return $text.length ? $text : $('.mm-restore-progress-text');
    }

    function ensureActiveJobBanner(jobId, title) {
        var $banner = $('#mm-active-job-banner');
        title = title || t('restoreInProgress', 'Restore in progress');
        if ($banner.length) {
            if (jobId) {
                $banner.attr('data-job-id', String(jobId)).data('job-id', jobId);
            }
            $banner.attr('data-job-type', 'restore').data('job-type', 'restore');
            $('#mm-active-job-title').text(title);
            showRestoreCancelActions(jobId || $banner.attr('data-job-id'));
            return $banner;
        }
        var jid = jobId ? String(jobId) : '';
        var $el = $('<div id="mm-active-job-banner" class="mm-form-section mm-active-job-banner" data-job-type="restore"></div>')
            .attr('data-job-id', jid)
            .append($('<h2 id="mm-active-job-title"></h2>').text(title))
            .append('<div class="mm-progress-bar"><div class="mm-progress-fill" id="mm-active-job-fill" style="width:0%"></div></div>')
            .append($('<p class="mm-active-job-text" id="mm-active-job-text"></p>').text('0%'))
            .append(
                $('<p class="mm-active-job-actions"></p>').append(
                    $('<button type="button" class="button mm-cancel-restore-progress"></button>')
                        .attr('data-job-id', jid)
                        .text(t('cancelRestore', 'Cancel restore'))
                )
            );
        var $mount = $('.mm-below-header').first();
        if ($mount.length) {
            $mount.append($el);
        } else {
            var $anchor = $('#mm-create-wizard, #mm-create-host').first();
            if ($anchor.length) {
                $anchor.before($el);
            } else {
                $('.multisite-migrate-wrap').first().prepend($el);
            }
        }
        if ($el[0] && $el[0].scrollIntoView) {
            $el[0].scrollIntoView({ block: 'nearest' });
        }
        return $el;
    }

    function toastRestoreRequestFailed(xhr) {
        rmmigrateAdminUI.toast(
            rmmigrateAdminUI.ajaxErrorMessage(xhr, t('restoreRequestFailed', 'Restore request failed. Refresh and try again.')),
            'error'
        );
    }

    function tryResumeActiveRestorePoll(activityUrl) {
        $.get(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_status',
            nonce: rmmigrateAdmin.nonce
        }).done(function (res) {
            if (!res.success || !res.data || !res.data.active || res.data.job_type !== 'restore') {
                return;
            }
            hideDialog();
            showProgressPanel('restore');
            pollRestore(res.data.job_id, activityUrl || '');
        });
    }

    var releaseRestoreA11y = null;

    function showDialog(presetMigration) {
        if (typeof releaseRestoreA11y === 'function') {
            releaseRestoreA11y();
            releaseRestoreA11y = null;
        }
        if (window.rmmigrateAdminRestoreWizard) {
            rmmigrateAdminRestoreWizard.reset(!!presetMigration);
        }
        $('#mm-restore-dialog').removeClass('mm-hidden').show();
        var $modal = $('#mm-restore-dialog .mm-restore-modal');
        if (window.rmmigrateAdminUI && typeof rmmigrateAdminUI.bindOverlayA11y === 'function') {
            releaseRestoreA11y = rmmigrateAdminUI.bindOverlayA11y({
                $container: $modal,
                $initialFocus: $('#mm-restore-cancel-dialog'),
                ns: 'mmRestoreDialog',
                onEscape: hideDialog
            });
        } else {
            restoreFocusReturn = document.activeElement;
            $('#mm-restore-cancel-dialog').trigger('focus');
            $(document).on('keydown.mmRestoreDialog', function (e) {
                if (e.key === 'Escape') {
                    hideDialog();
                }
            });
        }
    }

    function hideDialog() {
        if (typeof releaseRestoreA11y === 'function') {
            releaseRestoreA11y();
            releaseRestoreA11y = null;
        } else {
            $(document).off('keydown.mmRestoreDialog');
            if (restoreFocusReturn && restoreFocusReturn.focus) {
                restoreFocusReturn.focus();
            }
        }
        $('#mm-restore-confirm').closest('.mm-restore-confirm-label').removeClass('mm-restore-confirm-label--error');
        $('#mm-restore-dialog').hide().addClass('mm-hidden');
    }

    $(document).on('change', '#mm-restore-confirm', function () {
        if ($(this).is(':checked')) {
            $(this).closest('.mm-restore-confirm-label').removeClass('mm-restore-confirm-label--error');
        }
    });

    function showProgressPanel(mode) {
        progressMode = mode || 'restore';
        var titles = {
            restore: t('restoreInProgress', 'Restore in progress')
        };
        var title = titles[progressMode] || titles.restore;
        ensureActiveJobBanner(null, title);
        $('#mm-active-job-title').text(title);
        showRestoreCancelActions();
        // Top banner (same slot as backup) — hide legacy bottom panel when banner is present.
        if ($('#mm-active-job-banner').length) {
            $('#mm-restore-progress').addClass('mm-hidden');
        } else {
            $('#mm-restore-progress-title').text(title);
            $('#mm-restore-progress').removeClass('mm-hidden');
        }
    }

    function loadManifest(jobId) {
        return $.get(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_manifest',
            job_id: jobId,
            nonce: rmmigrateAdmin.nonce
        });
    }

    function prefetchArchive(jobId) {
        return $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_prefetch_archive',
            nonce: rmmigrateAdmin.nonce,
            job_id: jobId
        });
    }

    function pollSafetyThenRestore(safetyJobId, restoreData) {
        showRestoreCancelActions(safetyJobId);
        rmmigrateAdminUI.pollJob({
            jobId: safetyJobId,
            $fill: progressFill(),
            $text: progressText(),
            onComplete: function () {
                progressText().text(t('startingRestore', 'Starting restore…'));
                var next = $.extend({}, restoreData, {
                    safety_snapshot: 0,
                    safety_job_id: safetyJobId
                });
                $.post(rmmigrateAdmin.ajaxUrl, next).done(function (res) {
                    if (!res.success) {
                        rmmigrateAdminUI.toast(res.data && res.data.message ? res.data.message : t('restoreFailed', 'Restore failed'), 'error');
                        return;
                    }
                    if (res.data.awaiting_safety) {
                        pollSafetyThenRestore(res.data.safety_job_id, next);
                        return;
                    }
                    redirectToJobProgress(res.data.job_id);
                }).fail(function (xhr) {
                    toastRestoreRequestFailed(xhr);
                    tryResumeActiveRestorePoll();
                });
            },
            onError: function (d) {
                // pollJob already showed one error toast.
            }
        });
    }

    function runRestoreRequest(postData, activityUrl) {
        $.post(rmmigrateAdmin.ajaxUrl, postData).done(function (res) {
            if (!res.success) {
                rmmigrateAdminUI.toast(res.data && res.data.message ? res.data.message : t('restoreFailed', 'Restore failed'), 'error');
                return;
            }
            if (res.data.awaiting_safety && res.data.safety_job_id) {
                hideDialog();
                showProgressPanel('restore');
                progressText().text(res.data.message || t('creatingSafetySnapshot', 'Creating safety snapshot…'));
                pollSafetyThenRestore(res.data.safety_job_id, postData);
                return;
            }
            hideDialog();
            redirectToJobProgress(res.data.job_id);
        }).fail(function (xhr) {
            toastRestoreRequestFailed(xhr);
            tryResumeActiveRestorePoll(activityUrl);
        });
    }

    function parseHostPath(url) {
        try {
            var parsed = new URL(url);
            return { host: parsed.hostname, path: parsed.pathname || '/' };
        } catch (e) {
            return { host: '', path: '/' };
        }
    }

    function collectBlogDomains() {
        var entries = [];
        $('#mm-blog-migration-table .mm-blog-new-url').each(function () {
            var $el = $(this);
            var newUrl = ($el.val() || '').trim();
            if (!newUrl) {
                return;
            }
            var newParts = parseHostPath(newUrl);
            entries.push({
                blog_id: parseInt($el.attr('data-blog-id'), 10) || 0,
                old_domain: $el.attr('data-old-domain') || '',
                new_domain: newParts.host,
                old_path: $el.attr('data-old-path') || '/',
                new_path: newParts.path
            });
        });
        return entries;
    }

    function syncTopologyOptions(manifest) {
        var dest = rmmigrateAdmin.topologyDestination || {};
        var subsiteOnNetwork = manifest.scope === 'subsite'
            && manifest.is_multisite
            && dest.is_multisite
            && !dest.is_multisite_subsite;
        var networkToSubsite = !!manifest.is_multisite
            && manifest.scope !== 'subsite'
            && dest.is_multisite
            && dest.is_multisite_subsite;
        var $wrap = $('#mm-topology-options');
        $wrap.toggleClass('mm-hidden', !subsiteOnNetwork && !networkToSubsite);
        $('#mm-topology-subsite-on-network').toggleClass('mm-hidden', !subsiteOnNetwork);
        $('#mm-topology-network-to-subsite').toggleClass('mm-hidden', !networkToSubsite);
        if (networkToSubsite && dest.destination_blog_id) {
            $('#mm-topology-network-blog-id').val(dest.destination_blog_id);
        }
    }

    function collectTopologyPayload() {
        var payload = {};
        if ($('#mm-topology-options').hasClass('mm-hidden')) {
            return payload;
        }
        var mode = $('input[name="mm_topology_import"]:checked').val() || 'auto';
        if (mode === 'auto') {
            return payload;
        }
        payload.topology_import = mode;
        if (mode === 'subsite_on_network') {
            payload.topology_target_url = $('#mm-topology-target-url').val();
            payload.topology_target_blog_id = $('#mm-topology-target-blog-id').val() || '0';
        } else if (mode === 'network_to_subsite') {
            payload.topology_target_blog_id = $('#mm-topology-network-blog-id').val() || '0';
        }
        return payload;
    }

    function populateBlogMigrationTable(blogs) {
        var $table = $('#mm-blog-migration-table');
        if (!$table.length || !blogs || !blogs.length) {
            return;
        }
        var subsiteBlogs = blogs.filter(function (blog) {
            return parseInt(blog.blog_id, 10) > 1;
        });
        if (!subsiteBlogs.length) {
            $table.empty();
            return;
        }
        var html = '<table class="widefat striped"><thead><tr><th>' + t('subsiteColumn', 'Subsite / Domain') + '</th><th>' + t('oldUrlColumn', 'Old URL') + '</th><th>' + t('newUrlColumn', 'New URL') + '</th></tr></thead><tbody>';
        subsiteBlogs.forEach(function (blog) {
            var oldUrl = blog.site_url || '';
            var oldParts = parseHostPath(oldUrl);
            var pathTrim = (blog.path || '').replace(/^\/+|\/+$/g, '');
            var label = pathTrim !== '' ? ('/' + pathTrim) : (oldParts.host || ('#' + blog.blog_id));
            html += '<tr><td><strong>' + label + '</strong></td><td>' + oldUrl + '</td><td><input type="url" class="regular-text mm-blog-new-url" data-blog-id="' + blog.blog_id + '" data-old-domain="' + oldParts.host + '" data-old-path="' + (blog.path || '/') + '" placeholder="https://"></td></tr>';
        });
        html += '</tbody></table>';
        $table.html(html);
    }

    $(document).on('click', '.mm-restore-backup', function () {
        var jobId = parseInt($(this).attr('data-job-id') || $(this).data('job-id') || sessionStorage.getItem('mm_last_import_job_id') || new URLSearchParams(window.location.search).get('job_id') || '0', 10);
        var presetMigration = $(this).data('preset-migration') === 1 || $(this).attr('data-preset-migration') === '1';
        $('#mm-restore-source-id').val(jobId);
        $('#mm-restore-passphrase').val('');
        $('#mm-restore-passphrase-wrap').addClass('mm-hidden');
        showDialog(presetMigration);
        loadManifest(jobId).done(function (res) {
            if (!res.success || !res.data.manifest) {
                return;
            }
            if (res.data.manifest_incomplete) {
                rmmigrateAdminUI.toast(t('manifestIncomplete', 'Archive metadata is missing — verify migration URLs manually.'), 'warning');
            }
            if (res.data.install_type_mismatch) {
                rmmigrateAdminUI.toast(
                    t('installTypeMismatch', 'Backup multisite install type (subdomain vs subdirectory) differs from this network — verify URLs and paths before restoring.'),
                    'warning'
                );
            }
            if (res.data.is_encrypted) {
                $('#mm-restore-passphrase-wrap').removeClass('mm-hidden');
            }
            var m = res.data.manifest;
            $('#mm-old-site-url').val(m.site_url || '');
            $('#mm-old-home-url').val(m.home_url || '');
            if (presetMigration) {
                applyMigrationDefaultsFromManifest(m);
            } else {
                $('#mm-new-site-url').val('');
                $('#mm-new-home-url').val('');
            }
            populateBlogMigrationTable(m.blogs || []);
            syncTopologyOptions(m);
        });
    });

    function applyMigrationDefaultsFromManifest(manifest) {
        var base = currentSiteUrl();
        $('#mm-new-site-url').val(base);
        if ($('#mm-home-same-as-site').is(':checked')) {
            $('#mm-new-home-url').val(base).prop('readonly', true);
        }
    }

    function currentSiteUrl() {
        if (rmmigrateAdmin.siteUrl) {
            return rmmigrateAdmin.siteUrl;
        }
        return window.location.origin;
    }

    $(document).on('click', '#mm-restore-cancel-dialog', function () {
        hideDialog();
    });

    $('#mm-restore-start').on('click', function () {
        var $confirm = $('#mm-restore-confirm');
        var $label = $confirm.closest('.mm-restore-confirm-label');
        if (!$confirm.is(':checked')) {
            $label.addClass('mm-restore-confirm-label--error');
            $confirm.trigger('focus');
            return;
        }
        $label.removeClass('mm-restore-confirm-label--error');
        var jobId = $('#mm-restore-source-id').val();
        var data = {
            action: 'rmmigrate_start_restore',
            nonce: rmmigrateAdmin.nonce,
            source_job_id: jobId,
            restore_mode: $('input[name="mm_restore_mode"]:checked').val(),
            restore_type: $('input[name="mm_restore_type"]:checked').val(),
            confirm_overwrite: 1,
            safety_snapshot: $('#mm-safety-snapshot').is(':checked') ? 1 : 0,
            old_site_url: $('#mm-old-site-url').val(),
            new_site_url: $('#mm-new-site-url').val(),
            old_home_url: $('#mm-old-home-url').val(),
            new_home_url: $('#mm-new-home-url').val(),
            archive_passphrase: $('#mm-restore-passphrase').val()
        };
        $.extend(data, collectTopologyPayload());
        var blogDomains = collectBlogDomains();
        if (blogDomains.length) {
            data.blog_domains = blogDomains;
        }
        var startAdvanced = function () {
            $.post(rmmigrateAdmin.ajaxUrl, data).done(function (res) {
                if (!res.success) {
                    rmmigrateAdminUI.toast(res.data && res.data.message ? res.data.message : t('restoreFailed', 'Restore failed'), 'error');
                    return;
                }
                if (res.data.awaiting_safety && res.data.safety_job_id) {
                    hideDialog();
                    showProgressPanel('restore');
                    progressText().text(res.data.message || t('creatingSafetySnapshot', 'Creating safety snapshot…'));
                    pollSafetyThenRestore(res.data.safety_job_id, data);
                    return;
                }
                hideDialog();
                redirectToJobProgress(res.data.job_id);
            }).fail(function (xhr) {
                toastRestoreRequestFailed(xhr);
                tryResumeActiveRestorePoll();
            });
        };
        startAdvanced();
    });

    function pollRestore(jobId, activityUrl) {
        ensureActiveJobBanner(jobId, t('restoreInProgress', 'Restore in progress'));
        showRestoreCancelActions(jobId);
        rmmigrateAdminUI.pollJob({
            jobId: jobId,
            $fill: progressFill(),
            $text: progressText(),
            onComplete: function () {
                hideRestoreCancelActions();
                $('#mm-active-job-banner, #mm-restore-progress').hide();
                var successJobId = jobId;
                var go = function () { reloadAfterRestoreSuccess(successJobId); };
                if (window.rmmigrateFeedback && typeof window.rmmigrateFeedback.maybePrompt === 'function') {
                    window.rmmigrateFeedback.maybePrompt({
                        context: 'restore_success',
                        jobType: 'restore',
                        onDone: go,
                        holdMs: 2000
                    });
                } else {
                    setTimeout(go, 2000);
                }
            },
            onError: function (d) {
                // pollJob already showed one error toast.
                $('#mm-active-job-title').text(t('restoreFailed', 'Restore failed'));
                $('#mm-active-job-banner, #mm-restore-progress').addClass('mm-finished-job').css('position', 'relative');
                hideRestoreCancelActions();
                progressText().css('color', '#d63638');
                progressFill().css('background-color', '#d63638');
            }
        });
    }

    function resumeActiveJob() {
        try {
            var params = new URLSearchParams(window.location.search);
            if (params.get('mm_restore_ok') === '1') {
                return;
            }
        } catch (e) {
            // ignore
        }
        if (!rmmigrateAdmin.activeJob || !rmmigrateAdmin.activeJob.id) {
            return;
        }
        if (rmmigrateAdmin.activeJob.type === 'restore') {
            showProgressPanel('restore');
            pollRestore(rmmigrateAdmin.activeJob.id);
        }
    }

    function cancelRestoreJob(jobId) {
        if (!jobId) {
            return;
        }
        rmmigrateAdminUI.confirm(t('cancelRestoreConfirm', 'Cancel restore? The site may be left in an inconsistent state.'), function () {
            $.post(rmmigrateAdmin.ajaxUrl, {
                action: 'rmmigrate_cancel',
                job_id: jobId,
                nonce: rmmigrateAdmin.nonce
            }).always(function () {
                reloadWithoutJobId();
            });
        });
    }

    $(document).on('click', '#mm-restore-cancel-progress, .mm-cancel-restore-progress', function () {
        if ($('#mm-active-job-banner').hasClass('mm-finished-job') || $('#mm-restore-progress').hasClass('mm-finished-job')) {
            reloadWithoutJobId();
            return;
        }
        var jobId = parseInt(
            $(this).data('job-id')
            || $('#mm-active-job-banner').data('job-id')
            || (rmmigrateAdmin.activeJob && rmmigrateAdmin.activeJob.id)
            || 0,
            10
        );
        cancelRestoreJob(jobId);
    });

    window.rmmigrateAdminRestoreProgress = {
        resume: function (jobId) {
            if (!jobId) {
                return;
            }
            showProgressPanel('restore');
            pollRestore(jobId);
        },
        cancel: cancelRestoreJob
    };

    resumeActiveJob();
})(jQuery);
