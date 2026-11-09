(function ($) {
    'use strict';

    function t(key, fallback) {
        return rmmigrateAdminUI.i18n(key, fallback);
    }

    function lastImportJobId() {
        try {
            return sessionStorage.getItem('mm_last_import_job_id') || '';
        } catch (e) {
            return '';
        }
    }

    var pollTimer = null;
    var restorePollHandle = null;
    var progressMode = 'restore';
    var restoreFocusReturn = null;
    var restoreStartInFlight = false;

    function setRestoreStartBusy(busy) {
        restoreStartInFlight = !!busy;
        var $btn = $('#mm-restore-start');
        if ($btn.length) {
            $btn.prop('disabled', !!busy);
        }
    }

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
            if (url.searchParams.get('mm_restore_ok') === '1' && url.searchParams.get('job_id') === String(jobId)) {
                return;
            }
            url.searchParams.delete('create');
            url.searchParams.delete('mm_verify');
            url.searchParams.delete('imported');
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
        var dismissBtn = document.createElement('button');
        dismissBtn.type = 'button';
        dismissBtn.className = 'button mm-banner__dismiss mm-dismiss-active-job';
        dismissBtn.textContent = t('dismiss', 'Dismiss');
        var $dismiss = $(dismissBtn);
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

    var pendingResumeToken = '';

    function redirectToJobProgress(id, workerToken) {
        // A restore can sign the admin out before a new page would load. With a job token,
        // follow the job on this page instead of navigating to the progress view.
        if (workerToken && id) {
            pendingResumeToken = String(workerToken);
            try {
                var here = new URL(window.location.href);
                here.searchParams.set('job_id', String(id));
                window.history.replaceState(null, '', here.pathname + here.search + here.hash);
            } catch (e) {
                /* keep the current address */
            }
            setRestoreStartBusy(false);
            hideDialog();
            showProgressPanel('restore');
            pollRestore(id);
            return;
        }
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
        // The upload card's "Restore this backup" button is misleading while a restore runs.
        var $importCard = $('#mm-import-restore-now').closest('.mm-import-wizard');
        if ($importCard.length && !$importCard.find('#mm-import-restore-progress:visible').length) {
            $importCard.addClass('mm-hidden');
        }
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

    function toastRestoreRequestFailed(xhr, opts) {
        opts = opts || {};
        var msg = rmmigrateAdminUI.ajaxErrorMessage(
            xhr,
            t('restoreRequestFailed', 'Restore request failed. Refresh and try again.')
        );
        if (rmmigrateAdminUI.reportAjaxFailure) {
            rmmigrateAdminUI.reportAjaxFailure({
                action: opts.action || 'rmmigrate_start_restore',
                message: msg,
                jobId: opts.jobId || parseInt($('#mm-restore-source-id').val() || 0, 10) || 0,
                httpStatus: xhr && xhr.status ? xhr.status : 0,
                phase: opts.phase || 'transport'
            });
        }
        rmmigrateAdminUI.toast(msg, 'error');
    }

    function tryResumeActiveRestorePoll(activityUrl) {
        $.get(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_status',
            nonce: rmmigrateAdmin.nonce
        }).done(function (res) {
            if (!res || !res.success || !res.data || !res.data.active || res.data.job_type !== 'restore') {
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
        setRestoreStartBusy(false);
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
                if (restoreStartInFlight) {
                    return;
                }
                setRestoreStartBusy(true);
                progressText().text(t('startingRestore', 'Starting restore…'));
                var next = $.extend({}, restoreData, {
                    safety_snapshot: 0,
                    safety_job_id: safetyJobId
                });
                $.post(rmmigrateAdmin.ajaxUrl, next).done(function (res) {
                    if (!res || !res.success) {
                        setRestoreStartBusy(false);
                        rmmigrateAdminUI.toast(res.data && res.data.message ? res.data.message : t('restoreFailed', 'Restore failed'), 'error');
                        return;
                    }
                    if (res.data.awaiting_safety) {
                        setRestoreStartBusy(false);
                        pollSafetyThenRestore(res.data.safety_job_id, next);
                        return;
                    }
                    redirectToJobProgress(res.data.job_id, res.data.worker_token);
                }).fail(function (xhr) {
                    setRestoreStartBusy(false);
                    toastRestoreRequestFailed(xhr, { action: 'rmmigrate_start_restore', phase: 'transport' });
                    tryResumeActiveRestorePoll();
                });
            },
            onError: function (d) {
                setRestoreStartBusy(false);
                // pollJob already showed one error toast.
            }
        });
    }

    function runRestoreRequest(postData, activityUrl) {
        if (restoreStartInFlight) {
            return;
        }
        setRestoreStartBusy(true);
        $.post(rmmigrateAdmin.ajaxUrl, postData).done(function (res) {
            if (!res || !res.success) {
                setRestoreStartBusy(false);
                var restoreFailMsg = res && res.data && res.data.message ? res.data.message : t('restoreFailed', 'Restore failed');
                if (rmmigrateAdminUI.reportJsonFail) {
                    rmmigrateAdminUI.reportJsonFail(postData.action || 'rmmigrate_start_restore', restoreFailMsg, 'start');
                }
                rmmigrateAdminUI.toast(restoreFailMsg, 'error');
                return;
            }
            if (res.data.awaiting_safety && res.data.safety_job_id) {
                hideDialog();
                showProgressPanel('restore');
                progressText().text(res.data.message || t('creatingSafetySnapshot', 'Creating safety snapshot…'));
                setRestoreStartBusy(false);
                pollSafetyThenRestore(res.data.safety_job_id, postData);
                return;
            }
            hideDialog();
            redirectToJobProgress(res.data.job_id, res.data.worker_token);
        }).fail(function (xhr) {
            setRestoreStartBusy(false);
            toastRestoreRequestFailed(xhr, { action: 'rmmigrate_start_restore', phase: 'transport' });
            tryResumeActiveRestorePoll(activityUrl);
        });
    }

    function parseHostPath(url) {
        try {
            var parsed = new URL(url);
            var host = parsed.hostname || '';
            if (host && parsed.port) {
                host = host + ':' + parsed.port;
            }
            return { host: host, path: parsed.pathname || '/' };
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
            if (!newParts.host) {
                return;
            }
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

    // True only when the archive must be mapped onto this install by the user
    // (subsite onto network, network onto subsite, extract one site). Pure hints do not count.
    function topologyNeedsChoice() {
        if ($('#mm-topology-options').hasClass('mm-hidden')) {
            return false;
        }
        return $('#mm-topology-subsite-on-network, #mm-topology-network-to-subsite, #mm-topology-extract-standalone').filter(function () {
            return !$(this).hasClass('mm-hidden');
        }).length > 0;
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
        var networkArchive = !!manifest.is_multisite
            && manifest.scope !== 'subsite';
        var extractStandalone = networkArchive && !dest.is_multisite;
        var extractNeedSingleSite = networkArchive && !!dest.is_multisite && !networkToSubsite;
        var $wrap = $('#mm-topology-options');
        $wrap.toggleClass('mm-hidden', !subsiteOnNetwork && !networkToSubsite && !extractStandalone && !extractNeedSingleSite);
        $('#mm-topology-subsite-on-network').toggleClass('mm-hidden', !subsiteOnNetwork);
        $('#mm-topology-network-to-subsite').toggleClass('mm-hidden', !networkToSubsite);
        $('#mm-topology-extract-standalone').toggleClass('mm-hidden', !extractStandalone);
        $('#mm-topology-extract-need-singlesite').toggleClass('mm-hidden', !extractNeedSingleSite);
        if (networkToSubsite && dest.destination_blog_id) {
            $('#mm-topology-network-blog-id').val(dest.destination_blog_id);
        }
        if (extractStandalone) {
            populateExtractBlogSelect(manifest.blogs || []);
        }
    }

    function populateExtractBlogSelect(blogs) {
        var $sel = $('#mm-topology-source-blog-id');
        if (!$sel.length) {
            return;
        }
        var current = $sel.val();
        $sel.empty().append($('<option></option>').val('').text(t('selectSite', 'Select a site…')));
        (blogs || []).forEach(function (blog) {
            var id = parseInt(blog.blog_id, 10);
            if (!id) {
                return;
            }
            var label = blog.site_url || blog.path || ('#' + id);
            $sel.append($('<option></option>').val(String(id)).text('#' + id + ' — ' + label));
        });
        if (current) {
            $sel.val(current);
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
        } else if (mode === 'extract_site_standalone') {
            payload.topology_source_blog_id = $('#mm-topology-source-blog-id').val() || '0';
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
        var $tableEl = $('<table>').addClass('widefat striped');
        var $thead = $('<thead>').append(
            $('<tr>').append(
                $('<th>').text(t('subsiteColumn', 'Subsite / Domain')),
                $('<th>').text(t('oldUrlColumn', 'Old URL')),
                $('<th>').text(t('newUrlColumn', 'New URL'))
            )
        );
        var $tbody = $('<tbody>');
        subsiteBlogs.forEach(function (blog) {
            var oldUrl = blog.site_url || '';
            var oldParts = parseHostPath(oldUrl);
            var pathTrim = (blog.path || '').replace(/^\/+|\/+$/g, '');
            var label = pathTrim !== '' ? ('/' + pathTrim) : (oldParts.host || ('#' + String(blog.blog_id)));
            var $input = $('<input>', {
                type: 'url',
                'class': 'regular-text mm-blog-new-url',
                placeholder: 'https://'
            });
            $input.attr('data-blog-id', String(blog.blog_id));
            $input.attr('data-old-domain', oldParts.host || '');
            $input.attr('data-old-path', blog.path || '/');
            $tbody.append(
                $('<tr>').append(
                    $('<td>').append($('<strong>').text(label)),
                    $('<td>').text(oldUrl),
                    $('<td>').append($input)
                )
            );
        });
        $table.empty().append($tableEl.append($thead, $tbody));
    }

    $(document).on('click', '.mm-restore-backup', function () {
        var jobId = parseInt($(this).attr('data-job-id') || $(this).data('job-id') || lastImportJobId() || new URLSearchParams(window.location.search).get('job_id') || '0', 10);
        var presetMigration = $(this).data('preset-migration') === 1 || $(this).attr('data-preset-migration') === '1';
        $('#mm-restore-source-id').val(jobId);
        $('#mm-restore-passphrase').val('');
        $('#mm-restore-passphrase-wrap').addClass('mm-hidden');
        showDialog(presetMigration);
        loadManifest(jobId).done(function (res) {
            if (!res || !res.success || !res.data || !res.data.manifest) {
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
                applyMigrationDefaultsFromManifest();
            } else {
                $('#mm-new-site-url').val('');
                $('#mm-new-home-url').val('');
            }
            populateBlogMigrationTable(m.blogs || []);
            syncTopologyOptions(m);
            // Preselect from the archive and jump to the confirm step, unless the
            // user must choose how a network/subsite archive maps onto this install.
            if (window.rmmigrateAdminRestoreWizard && typeof rmmigrateAdminRestoreWizard.quick === 'function'
                && !topologyNeedsChoice() && !res.data.manifest_incomplete) {
                var sameSite = normalizeSiteUrl(m.site_url) === normalizeSiteUrl(currentSiteUrl());
                var created = '';
                if (m.created_at) {
                    try {
                        created = new Date(m.created_at).toLocaleString();
                    } catch (e) {
                        created = String(m.created_at);
                    }
                }
                rmmigrateAdminRestoreWizard.quick((sameSite || !m.site_url) ? 'same_server' : 'migration', {
                    from: m.site_url || '',
                    date: created
                });
            }
        });
    });

    function normalizeSiteUrl(url) {
        return String(url || '').replace(/^https?:\/\//i, '').replace(/\/+$/, '').toLowerCase();
    }

    function applyMigrationDefaultsFromManifest() {
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
        if (restoreStartInFlight || $(this).prop('disabled')) {
            return;
        }
        if (window.rmmigratePreflightBlocked && window.rmmigratePreflightBlocked('restore')) {
            return;
        }
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
        runRestoreRequest(data);
    });

    function stopRestorePoll() {
        if (restorePollHandle && typeof restorePollHandle.stop === 'function') {
            restorePollHandle.stop();
        }
        restorePollHandle = null;
    }

    function pollRestore(jobId, activityUrl) {
        stopRestorePoll();
        ensureActiveJobBanner(jobId, t('restoreInProgress', 'Restore in progress'));
        showRestoreCancelActions(jobId);
        restorePollHandle = rmmigrateAdminUI.pollJob({
            jobId: jobId,
            resumeToken: pendingResumeToken,
            $fill: progressFill(),
            $text: progressText(),
            onComplete: function (d) {
                stopRestorePoll();
                hideRestoreCancelActions();
                $('#mm-active-job-banner, #mm-restore-progress').hide();
                var successJobId = jobId;
                if (d && d.logged_out) {
                    // Signed out by the restored users table: show why, then go to the login screen.
                    rmmigrateAdminUI.toast(t('restoreDoneLogin', 'Restore finished. Log in with the username and password of the restored site.'), 'success');
                    setTimeout(function () {
                        reloadAfterRestoreSuccess(successJobId);
                    }, 4000);
                    return;
                }
                var go = function () {
                    reloadAfterRestoreSuccess(successJobId);
                };
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
                stopRestorePoll();
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
            if (rmmigrateAdmin.activeJob.workerStaleWarning && rmmigrateAdminUI.syncWorkerStaleBanner) {
                rmmigrateAdminUI.syncWorkerStaleBanner({ worker_stale_hint: true });
            }
        }
    }

    function cancelRestoreJob(jobId, $trigger) {
        if (!jobId) {
            return;
        }
        var $btn = $trigger && $trigger.length ? $trigger : $('#mm-restore-cancel-progress, .mm-cancel-restore-progress').first();
        if (!rmmigrateAdminUI.withBusy($btn, function (release) {
            rmmigrateAdminUI.confirm(t('cancelRestoreConfirm', 'Cancel restore? The site may be left in an inconsistent state.'), function () {
                $.post(rmmigrateAdmin.ajaxUrl, {
                    action: 'rmmigrate_cancel',
                    job_id: jobId,
                    nonce: rmmigrateAdmin.nonce
                }).always(function () {
                    release();
                    reloadWithoutJobId();
                });
            }, release);
        }, { keepDisabled: false })) {
            return;
        }
    }

    $(document).on('click', '#mm-restore-cancel-progress, .mm-cancel-restore-progress', function () {
        if ($('#mm-active-job-banner').hasClass('mm-finished-job') || $('#mm-restore-progress').hasClass('mm-finished-job')) {
            reloadWithoutJobId();
            return;
        }
        var $btn = $(this);
        var jobId = parseInt(
            $btn.data('job-id')
            || $('#mm-active-job-banner').data('job-id')
            || (rmmigrateAdmin.activeJob && rmmigrateAdmin.activeJob.id)
            || 0,
            10
        );
        cancelRestoreJob(jobId, $btn);
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

    $(document).on('click', '.mm-rollback-last-restore', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        var safetyJobId = parseInt($btn.attr('data-safety-job-id') || $btn.data('safety-job-id') || 0, 10) || 0;
        var confirmMsg = t('rollbackConfirm', 'Roll back to the safety snapshot saved before the last restore? Current site data will be replaced.');
        var runRollback = function (release) {
            $.post(rmmigrateAdmin.ajaxUrl, {
                action: 'rmmigrate_rollback_restore',
                safety_job_id: safetyJobId,
                nonce: rmmigrateAdmin.nonce
            }).done(function (res) {
                if (!res || !res.success || !res.data || !res.data.job_id) {
                    rmmigrateAdminUI.toast((res && res.data && res.data.message) ? res.data.message : t('restoreFailed', 'Restore failed'), 'error');
                    if (typeof release === 'function') {
                        release();
                    }
                    return;
                }
                if (typeof release === 'function') {
                    release();
                }
                redirectToJobProgress(res.data.job_id, res.data.worker_token);
            }).fail(function (xhr) {
                toastRestoreRequestFailed(xhr);
                if (typeof release === 'function') {
                    release();
                }
            });
        };
        if (rmmigrateAdminUI && typeof rmmigrateAdminUI.confirm === 'function') {
            rmmigrateAdminUI.withBusy($btn, function (release) {
                rmmigrateAdminUI.confirm(confirmMsg, function () {
                    runRollback(release);
                }, release);
            });
        } else if (window.confirm(confirmMsg)) {
            $btn.prop('disabled', true);
            runRollback(function () {
                $btn.prop('disabled', false);
            });
        }
    });

    $(document).on('click', '.mm-resume-job', function (e) {
        var $btn = $(this);
        var jobType = ($btn.attr('data-job-type') || $btn.data('job-type') || $('#mm-active-job-banner').attr('data-job-type') || 'restore').toString();
        if (jobType !== 'restore') {
            return;
        }
        e.preventDefault();
        if ($btn.prop('disabled')) {
            return;
        }
        var resumeId = parseInt($btn.attr('data-job-id') || $btn.data('job-id') || $('#mm-active-job-banner').data('job-id') || 0, 10);
        if (!resumeId) {
            return;
        }
        $btn.prop('disabled', true);
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_resume_job',
            job_id: resumeId,
            nonce: rmmigrateAdmin.nonce
        }).done(function (res) {
            if (!res || !res.success || !res.data) {
                rmmigrateAdminUI.toast((res && res.data && res.data.message) ? res.data.message : t('requestFailed', 'Request failed'), 'error');
                return;
            }
            $('.mm-last-error-notice').remove();
            redirectToJobProgress(res.data.job_id || resumeId, res.data.worker_token);
        }).fail(function (xhr) {
            toastRestoreRequestFailed(xhr);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // backup-progress.js and restore-progress.js can load on the same page; bind once.
    if (!window.rmmigratePreflightBound) {
        window.rmmigratePreflightBound = true;
        var preflightStartButtons = {
            backup: '#multisite-migrate-start, #mm-quick-start-btn',
            restore: '#mm-restore-start'
        };
        var syncPreflightStart = function ($ampel) {
            var op = ($ampel.attr('data-preflight-op') || '').toString();
            if (!preflightStartButtons[op]) {
                return;
            }
            var blocked = $ampel.attr('data-can-proceed') === '0';
            var summaryId = $ampel.find('.mm-preflight-ampel__summary').attr('id') || '';
            $(preflightStartButtons[op]).each(function () {
                var $start = $(this);
                if (blocked) {
                    $start.prop('disabled', true).attr('data-mm-preflight-blocked', '1');
                    if (summaryId) {
                        $start.attr('aria-describedby', summaryId);
                    }
                } else if ($start.attr('data-mm-preflight-blocked') === '1') {
                    $start.prop('disabled', false).removeAttr('data-mm-preflight-blocked aria-describedby');
                }
            });
        };
        window.rmmigratePreflightBlocked = function (op) {
            var $ampel = $('.mm-preflight-ampel[data-preflight-op="' + op + '"][data-can-proceed="0"]').first();
            if (!$ampel.length) {
                return false;
            }
            $ampel.attr('tabindex', '-1').trigger('focus');
            return true;
        };
        $(function () {
            $('.mm-preflight-ampel').each(function () {
                syncPreflightStart($(this));
            });
        });
        $(document).on('click', '.mm-preflight-refresh', function (e) {
            e.preventDefault();
            var $ampel = $(this).closest('.mm-preflight-ampel');
            var op = ($ampel.attr('data-preflight-op') || 'backup').toString();
            $(this).prop('disabled', true).attr('aria-busy', 'true');
            $.post(rmmigrateAdmin.ajaxUrl, {
                action: 'rmmigrate_preflight_check',
                operation: op,
                nonce: rmmigrateAdmin.nonce
            }).done(function (res) {
                if (!res || !res.success || !res.data || !res.data.html) {
                    return;
                }
                var $fresh = $($.parseHTML(String(res.data.html).trim())).filter('.mm-preflight-ampel').first();
                if (!$fresh.length) {
                    return;
                }
                $ampel.attr({
                    'class': $fresh.attr('class'),
                    'data-preflight-status': $fresh.attr('data-preflight-status'),
                    'data-can-proceed': $fresh.attr('data-can-proceed')
                });
                $ampel.children().not('.mm-preflight-ampel__live').remove();
                $ampel.prepend($fresh.children().not('.mm-preflight-ampel__live'));
                syncPreflightStart($ampel);
                var message = ($ampel.find('.mm-preflight-ampel__badge').text() + ' ' + $ampel.find('.mm-preflight-ampel__summary').text()).replace(/\s+/g, ' ').trim();
                var $live = $ampel.find('.mm-preflight-ampel__live').text('');
                window.setTimeout(function () {
                    $live.text(message);
                }, 100);
            }).always(function () {
                $ampel.find('.mm-preflight-refresh').prop('disabled', false).removeAttr('aria-busy').trigger('focus');
            });
        });
    }

    // backup-progress.js and restore-progress.js can load on the same page; bind once.
    if (!window.rmmigrateLeaveMaintenanceBound) {
        window.rmmigrateLeaveMaintenanceBound = true;
        $(document).on('click', '.mm-leave-maintenance-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.prop('disabled')) {
                return;
            }
            $btn.prop('disabled', true);
            $.post(rmmigrateAdmin.ajaxUrl, {
                action: 'rmmigrate_leave_maintenance',
                nonce: rmmigrateAdmin.nonce
            }).done(function (res) {
                if (!res || !res.success) {
                    rmmigrateAdminUI.toast((res && res.data && res.data.message) ? res.data.message : t('requestFailed', 'Request failed'), 'error');
                    return;
                }
                var msg = (res.data && res.data.message) ? res.data.message : t('maintenanceCleared', 'Maintenance mode has been turned off.');
                rmmigrateAdminUI.toast(msg, 'success');
                $('.mm-maintenance-active-banner, #mm-active-job-leave-maintenance').remove();
                var $heading = $('.wrap h1').first();
                if ($heading.length) {
                    $heading.attr('tabindex', '-1').trigger('focus');
                }
            }).fail(function (xhr) {
                toastRestoreRequestFailed(xhr);
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    }

    resumeActiveJob();

    // After an upload finishes, open the restore dialog right away (once per archive).
    (function autoOpenImportedRestore() {
        var $btn = $('#mm-import-restore-now');
        if (!$btn.length || (rmmigrateAdmin.activeJob && rmmigrateAdmin.activeJob.id)) {
            return;
        }
        var key = 'rmmigrateAutoRestore:' + String($btn.attr('data-job-id') || '');
        try {
            if (window.sessionStorage.getItem(key)) {
                return;
            }
            window.sessionStorage.setItem(key, '1');
        } catch (e) {
            // storage unavailable: still open once on this page view
        }
        window.setTimeout(function () {
            $btn.trigger('click');
        }, 300);
    })();
})(jQuery);
