(function ($) {
    'use strict';

    var createStep = 1;
    var jobId = null;
    var running = false;
    var workerFailCount = 0;
    var maxWorkerFails = 8;
    var stuckPolls = 0;
    var lastPollKey = '';
    var lastFailsafeAt = 0;
    var waitingForLock = false;
    var lastDisplayedPercent = 0;
    var statusTimer = null;
    var STATUS_POLL_MS = 1500;
    var WAITING_STATUS_POLL_MS = 2500;
    var FAILSAFE_WORKER_MS = 30000;
    var BROWSER_WORKER_MS = 800;

    var i18n = rmmigrateAdmin.i18n || {};

    function t(key, fallback) {
        return rmmigrateAdminUI.i18n(key, fallback);
    }

    function getCreateWizard() {
        return $('#mm-create-wizard');
    }

    function getCreateLastStep() {
        var $wizard = getCreateWizard();
        var declared = parseInt($wizard.data('createStepCount'), 10);
        if (declared > 0) {
            return declared;
        }
        var count = $wizard.find('.mm-create-step').length;
        return count > 0 ? count : 3;
    }

    function goToCreateReviewStep() {
        showCreateStep(getCreateLastStep());
    }

    function showCreateStep(n) {
        var $wizard = getCreateWizard();
        var lastStep = getCreateLastStep();
        createStep = n;
        $wizard.find('.mm-create-step').removeClass('is-active');
        $wizard.find('.mm-create-step[data-create-step="' + n + '"]').addClass('is-active');
        $wizard.find('.mm-create-wizard-steps li').removeClass('is-active is-done').removeAttr('aria-current');
        $wizard.find('.mm-create-wizard-steps li').each(function (i) {
            var stepIndex = i + 1;
            var $li = $(this);
            var $btn = $li.find('.mm-create-step-btn');
            if (stepIndex === n) {
                $li.addClass('is-active').attr('aria-current', 'step');
                $btn.prop('disabled', true);
            } else if (stepIndex < n) {
                $li.addClass('is-done');
                $btn.prop('disabled', false);
            } else {
                $btn.prop('disabled', true);
            }
        });
        if (n === lastStep) {
            buildCreateReview();
        }
        $wizard.find('#mm-create-back').toggleClass('mm-hidden', n <= 1);
        $wizard.find('#mm-create-next').toggleClass('mm-hidden', n >= lastStep);
        $wizard.find('#multisite-migrate-start').toggleClass('mm-hidden', n < lastStep);
        if (window.rmmigrateAdminUI && typeof rmmigrateAdminUI.refreshTrap === 'function') {
            rmmigrateAdminUI.refreshTrap(releaseCreateWizardA11y);
        }
    }

    function escapeHtml(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function createReviewRow(label, value, detail, editStep) {
        var detailHtml = detail
            ? '<span class="mm-setup-review-row__detail">' + escapeHtml(detail) + '</span>'
            : '';
        var editHtml = editStep
            ? '<button type="button" class="button-link mm-setup-review-row__action mm-create-review-edit" data-create-goto="' + editStep + '">' +
                escapeHtml(t('edit', 'Edit')) +
              '</button>'
            : '';
        return (
            '<div class="mm-setup-review-row">' +
                '<dt>' + escapeHtml(label) + '</dt>' +
                '<dd>' +
                    '<div class="mm-setup-review-row__body">' +
                        '<span class="mm-setup-review-row__value">' + escapeHtml(value) + '</span>' +
                        detailHtml +
                    '</div>' +
                    editHtml +
                '</dd>' +
            '</div>'
        );
    }

    function buildCreateReview() {
        var $wizard = getCreateWizard();
        var profile = $wizard.find('#rosenheinrich-multisite-migratefile option:selected').text();
        var archiveFormat = $wizard.find('#mm-archive-mode option:selected').text();
        var dest = t('destLocal', 'Local — on this server');
        var wpCore = $wizard.find('#mm-include-wp-core').is(':checked') ? t('yes', 'Yes') : t('no', 'No');
        var scope = getScope();
        var scopeLabels = {
            network: t('scopeNetwork', 'Full network'),
            network_included: t('scopeIncluded', 'Only selected subsites'),
            network_filtered: t('scopeFiltered', 'Network (exclude subsites)'),
            subsite: rmmigrateAdmin.scopeSubsiteLabel || t('scopeSubsite', 'Subsite only')
        };
        var scopeLabel = scopeLabels[scope] || scope;
        var sitesDetail = '';
        if (scope === 'network_included') {
            sitesDetail = formatSelectedSiteLabels(getIncludedBlogs());
        } else if (scope === 'network_filtered') {
            sitesDetail = formatSelectedSiteLabels(getExcludedBlogs(), 'excluded');
        }
        $wizard.find('#mm-create-review').html(
            createReviewRow(t('labelScope', 'Scope'), scopeLabel, sitesDetail, 1) +
            createReviewRow(t('labelProfile', 'Profile'), profile, '', 1) +
            createReviewRow(t('labelArchiveFormat', 'Archive format'), archiveFormat, '', 1) +
            createReviewRow(t('labelIncludeWpCore', 'Include WordPress core'), wpCore, '', 1) +
            createReviewRow(t('labelDestination', 'Destination'), dest, '', 2)
        );
    }

    function formatSelectedSiteLabels(ids, mode) {
        if (!ids || !ids.length) {
            return '';
        }
        var labels = [];
        ids.forEach(function (id) {
            var $input = mode === 'excluded'
                ? $('#mm-subsite-exclude input[value="' + id + '"]')
                : $('#mm-subsite-include input[value="' + id + '"]');
            var $label = $input.closest('label');
            if ($label.length) {
                labels.push($.trim($label.text()));
            }
        });
        if (!labels.length) {
            return String(ids.length);
        }
        return labels.join('; ');
    }

    function isCreateBlockedByActiveJob() {
        if (running) {
            return true;
        }
        var $banner = $('#mm-active-job-banner');
        return $banner.length > 0 && !$banner.hasClass('mm-finished-job');
    }

    function createBlockedMessage() {
        var jobType = $('#mm-active-job-banner').data('job-type')
            || (rmmigrateAdmin.activeJob && rmmigrateAdmin.activeJob.type)
            || 'backup';
        if (jobType === 'restore') {
            return t(
                'createBlockedByRestore',
                'A restore is already in progress. Wait for it to finish or cancel it before starting a new backup.'
            );
        }
        return t(
            'createBlockedByBackup',
            'A backup is already in progress. Wait for it to finish or cancel it before starting a new backup.'
        );
    }

    var releaseCreateWizardA11y = null;

    function openCreateWizard() {
        if (typeof releaseCreateWizardA11y === 'function') {
            releaseCreateWizardA11y();
            releaseCreateWizardA11y = null;
        }
        $('#mm-create-host').addClass('mm-hidden is-create-open').attr('aria-hidden', 'true');
        getCreateWizard().removeClass('mm-hidden').attr('aria-hidden', 'false');
        if (window.rmmigrateAdminUI && typeof rmmigrateAdminUI.bindOverlayA11y === 'function') {
            releaseCreateWizardA11y = rmmigrateAdminUI.bindOverlayA11y({
                $container: getCreateWizard(),
                $initialFocus: $('#mm-create-wizard-title'),
                ns: 'mmCreateWizard',
                onEscape: closeCreateWizard
            });
        } else {
            $('#mm-create-wizard-title').trigger('focus');
        }
        showCreateStep(1);
    }

    function tryOpenCreateWizard() {
        if (isCreateBlockedByActiveJob()) {
            rmmigrateAdminUI.alert(createBlockedMessage());
            return;
        }
        openCreateWizard();
    }

    function closeCreateWizard() {
        if (typeof releaseCreateWizardA11y === 'function') {
            releaseCreateWizardA11y();
            releaseCreateWizardA11y = null;
        }
        getCreateWizard().addClass('mm-hidden').attr('aria-hidden', 'true');
        $('#mm-create-host').removeClass('mm-hidden is-create-open').attr('aria-hidden', 'false');
    }

    function formatProgressText(percent, message) {
        message = message || '';
        if (percent >= 100) {
            return message || '100%';
        }
        if (message && percent > 0) {
            return message + ' (' + percent + '%)';
        }
        return message || (percent + '%');
    }

    function normalizePercent(raw, fallback) {
        var pct = typeof raw === 'number' ? raw : parseInt(raw, 10);
        if (isNaN(pct)) {
            return fallback;
        }
        return Math.max(0, Math.min(100, pct));
    }

    function formatInProgressPct(percent) {
        // PHP i18n uses %% for a literal %; JS must unescape after %d/%1$d substitution.
        var tpl = t('inProgressPct', 'In progress (%d%%)');
        return tpl.replace(/%\d*\$?d/, String(percent)).replace(/%%/g, '%');
    }

    function phaseTextFromMessage(message) {
        return String(message || '').replace(/\s*\(\d+%\)\s*$/, '').trim();
    }

    function truncatePhase(text, maxLen) {
        maxLen = maxLen || 90;
        text = String(text || '');
        if (text.length <= maxLen) {
            return text;
        }
        return text.slice(0, maxLen - 1).replace(/\s+\S*$/, '').trim() + '…';
    }

    function getLiveJobRow() {
        var sel = 'tr.mm-job-row-active';
        if (jobId) {
            sel += '[data-job-id="' + String(jobId) + '"]';
        }
        var $row = $(sel).first();
        if (!$row.length && jobId) {
            $row = $('tr.mm-job-row-active').first();
        }
        return $row;
    }

    /**
     * Sync backups-list STATUS with banner from the same status-poll percent + message.
     * Poll is DB-backed (~1.5s); list must not stay frozen at SSR page-load %.
     */
    function updateListJobStatus(percent, message) {
        var $row = getLiveJobRow();
        if (!$row.length) {
            return;
        }
        var $pill = $row.find('.mm-live-job-status');
        var $phase = $row.find('.mm-live-job-phase');
        if ($pill.length) {
            $pill.text(formatInProgressPct(percent));
        }
        if ($phase.length) {
            var full = phaseTextFromMessage(message);
            if (full) {
                $phase.text(truncatePhase(full)).attr('title', full);
            } else {
                $phase.text('').removeAttr('title');
            }
        }
    }

    function finishListJobStatus(ok) {
        var $row = getLiveJobRow();
        if (!$row.length) {
            return;
        }
        var $pill = $row.find('.mm-live-job-status');
        var $phase = $row.find('.mm-live-job-phase');
        if ($pill.length) {
            $pill
                .removeClass('mm-status-active mm-status-ok mm-status-error mm-status-warn')
                .addClass(ok ? 'mm-status-ok' : 'mm-status-error')
                .text(ok ? t('complete', 'Complete') : t('failed', 'Failed'));
        }
        if ($phase.length) {
            $phase.text('').removeAttr('title');
        }
    }

    /**
     * Apply progress without regressing the bar (avoids 0% flash on sparse/race polls).
     * @return {boolean} false when payload should be ignored
     */
    function applyProgressFromData(data, opts) {
        opts = opts || {};
        if (!data || typeof data !== 'object') {
            return false;
        }
        if (data.active === false && data.status === undefined && data.done === undefined && data.percent === undefined) {
            return false;
        }
        var pct = normalizePercent(data.percent, lastDisplayedPercent);
        if (pct <= 0 && lastDisplayedPercent > 0) {
            pct = lastDisplayedPercent;
        } else if (!opts.allowRegress && pct < lastDisplayedPercent && pct < 100) {
            pct = lastDisplayedPercent;
        }
        lastDisplayedPercent = Math.max(lastDisplayedPercent, pct);
        var msg = data.message || opts.fallbackMessage || '';
        updateProgress(pct, msg);
        if (rmmigrateAdminUI.syncWorkerStaleBanner) {
            rmmigrateAdminUI.syncWorkerStaleBanner(data);
        }
        return true;
    }

    function updateProgress(percent, message) {
        percent = normalizePercent(percent, lastDisplayedPercent);
        if (percent <= 0 && lastDisplayedPercent > 0) {
            percent = lastDisplayedPercent;
        }
        if (percent > lastDisplayedPercent) {
            lastDisplayedPercent = percent;
        }
        var text = formatProgressText(percent, message);
        if (text === '0%' && lastDisplayedPercent > 0) {
            text = formatProgressText(lastDisplayedPercent, message);
            percent = lastDisplayedPercent;
        }
        $('#mm-active-job-fill').css({
            width: percent + '%',
            transition: 'width 0.45s ease-out'
        });
        $('#mm-active-job-text').text(text);
        if (percent < 100) {
            updateListJobStatus(percent, message);
        }
    }

    function redirectToJobProgress(id) {
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete('mm_verify');
            url.searchParams.set('job_id', String(id));
            url.searchParams.delete('create');
            window.location.assign(url.pathname + url.search + url.hash);
        } catch (e) {
            window.location.reload();
        }
    }

    function reloadWithoutJobId() {
        try {
            var url = new URL(window.location.href);
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

    function reloadAfterBackupSuccess(jobId) {
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete('create');
            if (jobId) {
                url.searchParams.set('job_id', String(jobId));
                url.searchParams.set('mm_verify', '1');
            } else {
                url.searchParams.delete('job_id');
                url.searchParams.delete('mm_verify');
            }
            window.location.href = url.pathname + url.search + url.hash;
        } catch (e) {
            window.location.reload();
        }
    }

    function getScope() {
        if (!rmmigrateAdmin.isNetwork) {
            return rmmigrateAdmin.scope || 'subsite';
        }
        return $('input[name="mm_scope"]:checked').val() || 'network';
    }

    function getExcludedBlogs() {
        if (getScope() !== 'network_filtered') {
            return [];
        }
        var excluded = [];
        $('#mm-subsite-exclude input:checked').each(function () {
            excluded.push($(this).val());
        });
        return excluded;
    }

    function getIncludedBlogs() {
        if (getScope() !== 'network_included') {
            return [];
        }
        var included = [];
        $('#mm-subsite-include input:checked').each(function () {
            included.push($(this).val());
        });
        return included;
    }

    function validateScope() {
        var scope = getScope();
        if (scope === 'network_included' && getIncludedBlogs().length === 0) {
            rmmigrateAdminUI.toast(t('selectSubsiteInclude', 'Select at least one subsite to include.'), 'error');
            return false;
        }
        if (scope === 'network_filtered' && getExcludedBlogs().length === 0) {
            rmmigrateAdminUI.toast(t('selectSubsiteExclude', 'Select at least one subsite to exclude, or choose Full network.'), 'error');
            return false;
        }
        if (scope === 'network_filtered') {
            var totalSites = $('#mm-subsite-exclude input[type="checkbox"]').length;
            if (totalSites > 0 && getExcludedBlogs().length >= totalSites) {
                rmmigrateAdminUI.toast(t('cannotExcludeAll', 'Cannot exclude every subsite. Use “Only selected subsites” to back up specific sites.'), 'error');
                return false;
            }
        }
        return true;
    }

    function ensureWorkerPolling(forcedJobId) {
        if (forcedJobId) {
            jobId = forcedJobId;
        }
        if (!jobId || running) {
            return;
        }
        running = true;
        workerFailCount = 0;
        stuckPolls = 0;
        lastPollKey = '';
        lastFailsafeAt = 0;
        clearStatusTimer();
        if (browserDrivesWorker()) {
            runWorker();
        } else {
            // Cron/loopback: status-first; failsafe may kick once if stalled.
            lastFailsafeAt = Date.now();
            scheduleNextTick(false);
        }
    }

    function focusActiveJobBanner(shouldScroll) {
        var $banner = $('#mm-active-job-banner');
        if (!$banner.length) {
            return;
        }
        if (shouldScroll && $banner[0].scrollIntoView) {
            var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            $banner[0].scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'nearest' });
        }
        $banner.addClass('mm-active-job-banner--focus');
        setTimeout(function () {
            $banner.removeClass('mm-active-job-banner--focus');
        }, 2400);
    }

    function focusMainBackupProgress(forcedJobId) {
        if (forcedJobId) {
            jobId = forcedJobId;
        }
        focusActiveJobBanner(true);
        ensureWorkerPolling(forcedJobId);
    }

    function focusRestoreProgress(forcedJobId) {
        focusActiveJobBanner(true);
        if (window.rmmigrateAdminRestoreProgress && typeof window.rmmigrateAdminRestoreProgress.resume === 'function') {
            window.rmmigrateAdminRestoreProgress.resume(forcedJobId || jobId);
        }
    }

    function workerStoppedMessage(xhr) {
        var msg = t('workerFailed', 'Backup worker stopped responding. Refresh the page or cancel and try again.');
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            msg = xhr.responseJSON.data.message;
        }
        return msg;
    }

    function clearStatusTimer() {
        if (statusTimer) {
            clearTimeout(statusTimer);
            statusTimer = null;
        }
    }

    function browserDrivesWorker() {
        return (rmmigrateAdmin.kickoffMode || 'auto') === 'browser';
    }

    function finishJobUi(ok, message, reportOpts) {
        running = false;
        waitingForLock = false;
        clearStatusTimer();
        stuckPolls = 0;
        $('#multisite-migrate-start').prop('disabled', false);
        $('#mm-active-job-banner').addClass('mm-finished-job').css('position', 'relative');
        if (ok) {
            lastDisplayedPercent = 100;
            updateProgress(100, message || t('backupComplete', 'Backup complete'));
            finishListJobStatus(true);
            $('#mm-active-job-title').text(t('backupComplete', 'Backup complete'));
            var successJobId = jobId || ($('#mm-active-job-banner').data('job-id') || null);
            var go = function () { reloadAfterBackupSuccess(successJobId); };
            if (window.rmmigrateFeedback && typeof window.rmmigrateFeedback.maybePrompt === 'function') {
                window.rmmigrateFeedback.maybePrompt({
                    context: 'backup_success',
                    jobType: 'backup',
                    onDone: go,
                    holdMs: 2000
                });
            } else {
                setTimeout(go, 2000);
            }
            return;
        }
        var msg = message || t('backupFailed', 'Backup failed');
        reportOpts = reportOpts || {};
        if (reportOpts.report && rmmigrateAdminUI && typeof rmmigrateAdminUI.reportAjaxFailure === 'function') {
            rmmigrateAdminUI.reportAjaxFailure({
                action: reportOpts.action || 'rmmigrate_worker',
                message: msg,
                jobId: jobId || parseInt($('#mm-active-job-banner').data('job-id') || 0, 10) || 0,
                httpStatus: reportOpts.httpStatus || 0,
                phase: reportOpts.phase || 'worker'
            });
        }
        rmmigrateAdminUI.toast(msg, 'error');
        $('#mm-active-job-text').text(msg).css('color', '#d63638');
        $('#mm-active-job-fill').css('background-color', '#d63638');
        finishListJobStatus(false);
        setTimeout(function () { reloadWithoutJobId(); }, 3000);
    }

    function handleTerminalStatus(data) {
        if (!data) {
            return false;
        }
        var status = typeof data.status === 'number' ? data.status : parseInt(data.status, 10);
        if (isNaN(status)) {
            status = null;
        }
        if (status === 100) {
            finishJobUi(true, data.message);
            return true;
        }
        if (status !== null && status < 0) {
            finishJobUi(false, data.error || data.message || t('backupFailed', 'Backup failed'));
            return true;
        }
        // Worker terminal payload (has status/error). Bare {active:false} is not failure —
        // that also means "job not found yet" right after redirect from setup.
        if (data.done === true && (status !== null || data.error)) {
            finishJobUi(false, data.error || data.message || t('backupFailed', 'Backup failed'));
            return true;
        }
        return false;
    }

    function trackStuckProgress(data) {
        if (data && data.lease_fresh) {
            stuckPolls = 0;
            lastPollKey = String(data.percent || 0) + '|' + (data.message || '');
            return;
        }
        var pollKey = String(data.percent || 0) + '|' + (data.message || '');
        if (pollKey === lastPollKey) {
            stuckPolls++;
        } else {
            stuckPolls = 0;
            lastPollKey = pollKey;
        }
        if (stuckPolls > 40 && (data.percent || 0) < 5) {
            rmmigrateAdminUI.toast(t('workerStalled', 'Backup is taking longer than expected. Keep this tab open or refresh to resume.'), 'warning');
            stuckPolls = 0;
        }
    }

    function scheduleNextTick(preferWorker) {
        if (!jobId || !running) {
            return;
        }
        clearStatusTimer();
        var now = Date.now();

        // Another worker holds the lock: status-only poll; failsafe kick at most 1/30s.
        if (waitingForLock) {
            if (preferWorker || (now - lastFailsafeAt >= FAILSAFE_WORKER_MS)) {
                lastFailsafeAt = now;
                statusTimer = setTimeout(runWorker, 250);
                return;
            }
            statusTimer = setTimeout(pollJobStatusLoop, WAITING_STATUS_POLL_MS);
            return;
        }

        if (browserDrivesWorker()) {
            statusTimer = setTimeout(runWorker, BROWSER_WORKER_MS);
            return;
        }
        // Cron/loopback: poll status; failsafe worker only when progress appears stalled.
        if (preferWorker || (stuckPolls >= 3 && now - lastFailsafeAt >= FAILSAFE_WORKER_MS)) {
            lastFailsafeAt = now;
            stuckPolls = 0;
            statusTimer = setTimeout(runWorker, 250);
            return;
        }
        statusTimer = setTimeout(pollJobStatusLoop, STATUS_POLL_MS);
    }

    function pollJobStatus(callback) {
        if (!jobId) {
            return;
        }
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_status',
            job_id: jobId,
            nonce: rmmigrateAdmin.nonce
        }).done(function (response) {
            if (response.success && response.data) {
                if (handleTerminalStatus(response.data)) {
                    return;
                }
                applyProgressFromData(response.data);
            }
            if (typeof callback === 'function') {
                callback();
            }
        }).fail(function () {
            if (typeof callback === 'function') {
                callback();
            }
        });
    }

    function pollJobStatusLoop() {
        if (!jobId || !running) {
            return;
        }
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_status',
            job_id: jobId,
            nonce: rmmigrateAdmin.nonce
        }).done(function (response) {
            if (!running) {
                return;
            }
            if (response.success && response.data) {
                if (handleTerminalStatus(response.data)) {
                    return;
                }
                applyProgressFromData(response.data);
                trackStuckProgress(response.data);
            }
            scheduleNextTick(false);
        }).fail(function () {
            if (!running) {
                return;
            }
            scheduleNextTick(true);
        });
    }

    function runWorker() {
        if (!jobId || !running) {
            return;
        }
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_worker',
            job_id: jobId,
            nonce: rmmigrateAdmin.nonce
        }).done(function (response) {
            if (!running) {
                return;
            }
            if (!response.success) {
                workerFailCount = 0;
                finishJobUi(false, response.data && response.data.message ? response.data.message : t('error', 'Error'), {
                    report: true,
                    action: 'rmmigrate_worker',
                    phase: 'worker'
                });
                return;
            }
            workerFailCount = 0;
            var data = response.data || {};
            if (data.waiting) {
                waitingForLock = true;
                applyProgressFromData(data, { fallbackMessage: t('workerWaiting', 'Another operation is running — waiting…') });
                scheduleNextTick(false);
                return;
            }
            waitingForLock = false;
            if (data.done) {
                if (data.status === 100) {
                    finishJobUi(true, data.message);
                } else {
                    finishJobUi(false, data.error || data.message || t('backupFailed', 'Backup failed'));
                }
                return;
            }
            applyProgressFromData(data);
            trackStuckProgress(data);
            scheduleNextTick(false);
        }).fail(function (xhr) {
            if (!running) {
                return;
            }
            workerFailCount++;
            if (workerFailCount >= maxWorkerFails) {
                finishJobUi(false, workerStoppedMessage(xhr), {
                    report: true,
                    action: 'rmmigrate_worker',
                    httpStatus: xhr && xhr.status ? xhr.status : 0,
                    phase: 'transport'
                });
                return;
            }
            clearStatusTimer();
            statusTimer = setTimeout(runWorker, Math.min(2000 * workerFailCount, 10000));
        });
    }

    function getJobIdFromUrl() {
        try {
            var params = new URLSearchParams(window.location.search);
            var id = parseInt(params.get('job_id') || '0', 10);
            return id > 0 ? id : null;
        } catch (e) {
            return null;
        }
    }

    function resumeBackupProgress(forcedJobId) {
        var id = forcedJobId || (rmmigrateAdmin.activeJob && rmmigrateAdmin.activeJob.id) || getJobIdFromUrl();
        if (!id) {
            return false;
        }
        if (rmmigrateAdmin.activeJob && rmmigrateAdmin.activeJob.type && rmmigrateAdmin.activeJob.type !== 'backup') {
            return false;
        }
        jobId = id;
        running = true;
        workerFailCount = 0;
        stuckPolls = 0;
        lastFailsafeAt = 0;
        waitingForLock = false;
        var styleWidth = ($('#mm-active-job-fill').attr('style') || '').match(/width\s*:\s*([\d.]+)%/i);
        lastDisplayedPercent = styleWidth ? Math.max(0, Math.min(100, parseInt(styleWidth[1], 10) || 0)) : 0;
        if (rmmigrateAdmin.activeJob && rmmigrateAdmin.activeJob.id === id && typeof rmmigrateAdmin.activeJob.percent === 'number') {
            lastDisplayedPercent = Math.max(lastDisplayedPercent, Math.max(0, Math.min(100, rmmigrateAdmin.activeJob.percent)));
        }
        if (lastDisplayedPercent > 0) {
            updateProgress(lastDisplayedPercent, (rmmigrateAdmin.activeJob && rmmigrateAdmin.activeJob.message) || '');
        }
        clearStatusTimer();
        $('#multisite-migrate-start').prop('disabled', true);
        focusActiveJobBanner(false);
        pollJobStatus(function () {
            if (browserDrivesWorker()) {
                runWorker();
            } else {
                lastFailsafeAt = Date.now();
                scheduleNextTick(false);
            }
        });
        return true;
    }

    function resumeActiveJob() {
        if (rmmigrateAdmin.activeJob && rmmigrateAdmin.activeJob.id && rmmigrateAdmin.activeJob.type !== 'backup') {
            return;
        }
        if (!resumeBackupProgress()) {
            return;
        }
    }

    $(document).on('click', 'a.mm-view-job-progress, a.mm-view-backup-progress, a[href="#mm-active-job-banner"]', function (e) {
        e.preventDefault();
        var id = parseInt($(this).data('job-id') || $('#mm-active-job-banner').data('job-id') || 0, 10);
        var jobType = $(this).data('job-type') || $('#mm-active-job-banner').data('job-type') || 'backup';
        if (id) {
            jobId = id;
        }
        if (jobType === 'restore') {
            focusRestoreProgress(id || jobId);
            return;
        }
        focusMainBackupProgress(id || jobId);
    });

    $('#mm-toggle-create, #mm-toggle-create-empty').on('click', tryOpenCreateWizard);
    $('#mm-create-wizard-close, #mm-create-cancel').on('click', function () {
        closeCreateWizard();
    });

    $(document).on('click', '.mm-create-step-btn, .mm-create-review-edit', function () {
        if ($(this).hasClass('mm-create-step-btn') && $(this).prop('disabled')) {
            return;
        }
        var goto = parseInt($(this).data('createGoto'), 10);
        if (goto > 0 && goto < createStep) {
            showCreateStep(goto);
        }
    });

    $('#mm-create-next').on('click', function () {
        if (createStep === 1) {
            if (!validateScope()) {
                return;
            }
            showCreateStep(2);
        } else if (createStep === 2) {
            goToCreateReviewStep();
        }
    });

    $('#mm-create-back').on('click', function () {
        if (createStep > 1) {
            showCreateStep(createStep - 1);
        }
    });

    $('#multisite-migrate-start').on('click', function () {
        if (running) {
            return;
        }
        if (!validateScope()) {
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true);
        running = true;
        lastDisplayedPercent = 0;
        updateProgress(0, t('starting', 'Starting…'));

        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_start',
            nonce: rmmigrateAdmin.nonce,
            scope: getScope(),
            excluded_blogs: getExcludedBlogs(),
            included_blogs: getIncludedBlogs(),
            backup_profile: $('#rosenheinrich-multisite-migratefile').val() || 'full',
            archive_mode: $('#mm-archive-mode').val() || '',
            custom_paths: $('#mm-custom-paths').val() || '',
            exclude_tables: $('#mm-exclude-tables').val() || '',
            exclude_log_tables: $('#mm-exclude-log-tables').is(':checked') ? 1 : 0,
            exclude_revisions: $('#mm-exclude-revisions').is(':checked') ? 1 : 0,
            include_wp_core: $('#mm-include-wp-core').is(':checked') ? 1 : 0
        }).done(function (response) {
            if (!response.success) {
                running = false;
                $btn.prop('disabled', false);
                var startFailMsg = response.data && response.data.message ? response.data.message : t('failedToStartBackup', 'Failed to start backup');
                if (rmmigrateAdminUI.reportJsonFail) {
                    rmmigrateAdminUI.reportJsonFail('rmmigrate_start', startFailMsg, 'start');
                }
                rmmigrateAdminUI.toast(startFailMsg, 'error');
                return;
            }
            closeCreateWizard();
            var newJobId = parseInt(response.data.job_id, 10) || null;
            resumeBackupProgress(newJobId);
            redirectToJobProgress(newJobId);
        }).fail(function (xhr) {
            running = false;
            $btn.prop('disabled', false);
            var msg = rmmigrateAdminUI.ajaxErrorMessage(xhr, t('requestFailed', 'Request failed'));
            if (rmmigrateAdminUI.reportAjaxFailure) {
                rmmigrateAdminUI.reportAjaxFailure({
                    action: 'rmmigrate_start',
                    message: msg,
                    httpStatus: xhr && xhr.status ? xhr.status : 0,
                    phase: 'start'
                });
            }
            rmmigrateAdminUI.toast(msg, 'error');
        });
    });

    $(document).on('click', '#multisite-migrate-cancel', function () {
        var $btn = $(this);
        if ($('#mm-active-job-banner').hasClass('mm-finished-job')) {
            reloadWithoutJobId();
            return;
        }
        if (!jobId) {
            jobId = parseInt($('#mm-active-job-banner').data('job-id') || 0, 10) || null;
        }
        if (!jobId) {
            return;
        }
        if (!rmmigrateAdminUI.withBusy($btn, function (release) {
            running = false;
            clearStatusTimer();
            $.post(rmmigrateAdmin.ajaxUrl, {
                action: 'rmmigrate_cancel',
                job_id: jobId,
                nonce: rmmigrateAdmin.nonce
            }).always(function () {
                release();
                reloadWithoutJobId();
            });
        }, { keepDisabled: true })) {
            return;
        }
    });

    $('input[name="mm_scope"]').on('change', function () {
        var scope = $(this).val();
        $('#mm-subsite-include').toggleClass('mm-hidden', scope !== 'network_included');
        $('#mm-subsite-exclude').toggleClass('mm-hidden', scope !== 'network_filtered');
    });

    $('#rosenheinrich-multisite-migratefile').on('change', function () {
        $('#mm-custom-paths-wrap').toggleClass('mm-hidden', $(this).val() !== 'custom');
    });

    $('.mm-delete-backup').on('click', function () {
        var id = $(this).data('job-id');
        var $row = $(this).closest('tr');
        var $btn = $(this);
        if (!rmmigrateAdminUI.withBusy($btn, function (release) {
            rmmigrateAdminUI.confirm(rmmigrateAdmin.confirmDelete || 'Delete this backup?', function () {
                rmmigrateAdminUI.toast(rmmigrateAdmin.deletingBackup || t('deletingBackup', 'Deleting…'), 'info');
                $row.addClass('mm-row-deleting').fadeOut(150);
                $.post(rmmigrateAdmin.ajaxUrl, {
                    action: 'rmmigrate_delete',
                    job_id: id,
                    nonce: rmmigrateAdmin.nonce
                }).done(function (response) {
                    if (!response.success) {
                        $row.stop(true, true).show().removeClass('mm-row-deleting');
                        rmmigrateAdminUI.toast(response.data && response.data.message ? response.data.message : t('error', 'Error'), 'error');
                        release();
                        return;
                    }
                    var msg = (response.data && response.data.message)
                        ? response.data.message
                        : (rmmigrateAdmin.deletingBackup || t('deletingBackup', 'Deleting…'));
                    rmmigrateAdminUI.toast(msg, 'success');
                    window.location.reload();
                }).fail(function (xhr) {
                    rmmigrateAdminUI.reportTransportFail('rmmigrate_delete', xhr, 'delete', id);
                    $row.stop(true, true).show().removeClass('mm-row-deleting');
                    rmmigrateAdminUI.toast(rmmigrateAdminUI.ajaxErrorMessage(xhr, t('requestFailed', 'Request failed')), 'error');
                    release();
                });
            }, release);
        })) {
            return;
        }
    });

    function selectedBackupIds() {
        return $('.mm-backup-select:checked').map(function () {
            return $(this).val();
        }).get();
    }

    function updateBulkDeleteButton() {
        var ids = selectedBackupIds();
        var $btn = $('#mm-bulk-delete-backups');
        if (!$btn.length) {
            return;
        }
        $btn.prop('disabled', ids.length === 0);
    }

    $(document).on('change', '.mm-backup-select, #mm-select-all-backups', function () {
        if ($(this).is('#mm-select-all-backups')) {
            $('.mm-backup-select').prop('checked', $(this).prop('checked'));
        } else {
            var $all = $('.mm-backup-select');
            var $checked = $('.mm-backup-select:checked');
            $('#mm-select-all-backups').prop('checked', $all.length > 0 && $all.length === $checked.length);
        }
        updateBulkDeleteButton();
    });

    $('#mm-bulk-delete-backups').on('click', function () {
        var $btn = $(this);
        var ids = selectedBackupIds();
        if (!ids.length) {
            return;
        }
        var confirmMsg = rmmigrateAdmin.confirmDeleteBulk || t('confirmDeleteBulk', 'Delete the selected backups?');
        if (ids.length > 1) {
            confirmMsg = confirmMsg + ' (' + ids.length + ')';
        }
        if (!rmmigrateAdminUI.withBusy($btn, function (release) {
            rmmigrateAdminUI.confirm(confirmMsg, function () {
                rmmigrateAdminUI.toast(rmmigrateAdmin.deletingBackup || t('deletingBackup', 'Deleting…'), 'info');
                ids.forEach(function (bulkJobId) {
                    $('.mm-backup-select[value="' + bulkJobId + '"]').closest('tr').addClass('mm-row-deleting').fadeOut(150);
                });
                $.post(rmmigrateAdmin.ajaxUrl, {
                    action: 'rmmigrate_delete_bulk',
                    job_ids: ids,
                    nonce: rmmigrateAdmin.nonce
                }).done(function (response) {
                    if (!response.success) {
                        $('.mm-row-deleting').stop(true, true).show().removeClass('mm-row-deleting');
                        rmmigrateAdminUI.toast(response.data && response.data.message ? response.data.message : t('error', 'Error'), 'error');
                        updateBulkDeleteButton();
                        return;
                    }
                    var toastType = 'success';
                    if (response.data && response.data.warnings && response.data.warnings.length) {
                        toastType = 'warning';
                    }
                    rmmigrateAdminUI.toast(
                        (response.data && response.data.message)
                            ? response.data.message
                            : (rmmigrateAdmin.deletingBackup || t('deletingBackup', 'Deleting…')),
                        toastType
                    );
                    window.location.reload();
                }).fail(function (xhr) {
                    rmmigrateAdminUI.reportTransportFail('rmmigrate_delete_bulk', xhr, 'delete');
                    $('.mm-row-deleting').stop(true, true).show().removeClass('mm-row-deleting');
                    rmmigrateAdminUI.toast(rmmigrateAdminUI.ajaxErrorMessage(xhr, t('requestFailed', 'Request failed')), 'error');
                    updateBulkDeleteButton();
                }).always(release);
            }, release);
        })) {
            return;
        }
    });

    updateBulkDeleteButton();

    resumeActiveJob();

    if (rmmigrateAdmin.activeJob && rmmigrateAdmin.activeJob.workerStaleWarning && rmmigrateAdminUI.syncWorkerStaleBanner) {
        rmmigrateAdminUI.syncWorkerStaleBanner({ worker_stale_hint: true });
    }

    try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('create') === '1' && $('#mm-create-wizard').length) {
            tryOpenCreateWizard();
        }
        if (params.get('job_id') && $('#mm-active-job-banner').length) {
            setTimeout(function () {
                window.scrollTo(0, 0);
            }, 0);
        }
        if (params.get('job_id') && !running) {
            resumeBackupProgress(parseInt(params.get('job_id'), 10) || null);
        }
    } catch (e) {
        // ignore
    }

    var subsiteSearchTimer = null;

    function subsiteFieldName($fieldset) {
        return ($fieldset.data('mmSelectionMode') || 'exclude') === 'include'
            ? 'included_blogs[]'
            : 'excluded_blogs[]';
    }

    function ensureSubsiteOption($fieldset, site, checked) {
        var blogId = parseInt(site.blog_id, 10);
        if (!blogId) {
            return;
        }
        var blogIdStr = String(blogId);
        if ($fieldset.find('input[value="' + blogIdStr + '"]').length) {
            if (checked) {
                $fieldset.find('input[value="' + blogIdStr + '"]').prop('checked', true);
            }
            return;
        }
        var $extra = $fieldset.find('.mm-subsite-extra');
        if (!$extra.length) {
            $extra = $('<div class="mm-subsite-extra"></div>');
            $fieldset.find('.mm-subsite-search-results').first().after($extra);
        }
        var $label = $('<label class="mm-subsite-option"></label>');
        var $input = $('<input type="checkbox">').attr('name', subsiteFieldName($fieldset)).val(blogId);
        if (checked) {
            $input.prop('checked', true);
        }
        $label.append($input).append(
            $('<span class="mm-subsite-option__text"></span>').text(site.label || blogId)
        );
        $extra.append($label);
    }

    $(document).on('input', '.mm-subsite-search-input', function () {
        var $input = $(this);
        var $fieldset = $input.closest('.mm-subsite-list');
        var $results = $fieldset.find('.mm-subsite-search-results');
        clearTimeout(subsiteSearchTimer);
        var q = ($input.val() || '').trim();
        if (q.length < 2) {
            $results.addClass('mm-hidden').empty();
            return;
        }
        subsiteSearchTimer = setTimeout(function () {
            $.post(rmmigrateAdmin.ajaxUrl, {
                action: 'rmmigrate_search_subsites',
                nonce: rmmigrateAdmin.nonce,
                search: q,
                offset: 0
            }).done(function (res) {
                if (!res || !res.success || !res.data || !Array.isArray(res.data.sites)) {
                    return;
                }
                $results.removeClass('mm-hidden').empty();
                if (!res.data.sites.length) {
                    $results.text(t('subsiteSearchEmpty', 'No subsites matched your search.'));
                    return;
                }
                res.data.sites.forEach(function (site) {
                    var exists = $fieldset.find('input[value="' + site.blog_id + '"]').length > 0;
                    var $btn = $('<button type="button" class="button button-small mm-subsite-search-add"></button>')
                        .text((exists ? '✓ ' : '+ ') + (site.label || site.blog_id))
                        .prop('disabled', exists)
                        .data('site', site);
                    $results.append($btn);
                });
            });
        }, 300);
    });

    $(document).on('click', '.mm-subsite-search-add', function () {
        var site = $(this).data('site');
        if (!site) {
            return;
        }
        var $fieldset = $(this).closest('.mm-subsite-list');
        ensureSubsiteOption($fieldset, site, true);
        $(this).prop('disabled', true).text('✓ ' + (site.label || site.blog_id));
    });
})(jQuery);
