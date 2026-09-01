(function ($) {
    'use strict';

    function adminConfig() {
        return window.rmmigrateAdmin || {};
    }

    function adminUI() {
        return window.rmmigrateAdminUI || null;
    }

    function defaultDetailAction() {
        return 'rmmigrate_activity_detail';
    }

    function defaultListAction() {
        return 'rmmigrate_activity_list';
    }

    function defaultLogChunkAction() {
        return 'rmmigrate_log_chunk';
    }

    function escHtml(text) {
        return $('<div/>').text(text || '').html();
    }

    function statusClass(status) {
        if (status === 'error' || status === 'failed' || status === 'failure') {
            return 'mm-status-error';
        }
        if (status === 'warning' || status === 'warn' || status === 'active' || status === 'running' || status === 'progress') {
            return 'mm-status-warn';
        }
        if (status === 'success' || status === 'ok' || status === 'complete' || status === 'completed' || status === 'done') {
            return 'mm-status-ok';
        }
        return 'mm-status-idle';
    }

    function statusPillClass(status) {
        return 'mm-status-pill ' + statusClass(status);
    }

    function actionName(key, fallback) {
        var admin = adminConfig();
        return admin[key] || fallback;
    }

    function metaPair(label, valueHtml) {
        return '<dt>' + escHtml(label) + '</dt><dd>' + valueHtml + '</dd>';
    }

    function formatBytes(bytes) {
        var n = Number(bytes);
        if (!isFinite(n) || n < 0) {
            return String(bytes);
        }
        if (n < 1024) {
            return Math.round(n) + ' B';
        }
        var units = ['KB', 'MB', 'GB', 'TB'];
        var u = -1;
        do {
            n /= 1024;
            u += 1;
        } while (n >= 1024 && u < units.length - 1);
        var digits = n >= 100 ? 0 : n >= 10 ? 1 : 2;
        return n.toFixed(digits) + ' ' + units[u];
    }

    function formatDestination(dest, i18n) {
        var raw = String(dest || '');
        if (raw.toLowerCase() === 'local') {
            return i18n.destLocal || 'Local — on this server';
        }
        return raw;
    }

    function renderDetail(data) {
        var entry = data.entry || {};
        var job = data.job;
        var admin = adminConfig();
        var i18n = admin.i18n || {};
        var html = '';
        var typeLabel = entry.type_label || entry.type || '';
        var statusLabel = entry.status_label || entry.status || '';
        var timeLabel = entry.time_display || entry.time || '';
        var subBits = [];

        html += '<div class="mm-activity-detail">';

        html += '<header class="mm-activity-detail-hero">';
        html += '<div class="mm-activity-detail-hero__main">';
        if (typeLabel) {
            html += '<span class="mm-activity-detail-hero__type">' + escHtml(typeLabel) + '</span>';
        }
        html += '<span class="mm-status-pill ' + statusClass(entry.status) + '">' + escHtml(statusLabel) + '</span>';
        html += '</div>';
        if (timeLabel) {
            subBits.push(escHtml(timeLabel));
        }
        if (data.server && data.server.plugin_version) {
            subBits.push(escHtml((i18n.pluginVersion || 'Plugin version') + ' ' + data.server.plugin_version));
        }
        if (subBits.length) {
            html += '<p class="mm-activity-detail-hero__sub">' + subBits.join('<span class="mm-activity-detail-hero__sep" aria-hidden="true">·</span>') + '</p>';
        }
        html += '</header>';

        if (job) {
            html += '<section class="mm-activity-detail-card">';
            html += '<h3 class="mm-activity-detail-card__title">' + escHtml(i18n.jobSummary || 'Job summary') + '</h3>';
            html += '<dl class="mm-activity-kv">';
            if (job.status) {
                html += metaPair(i18n.status || 'Status', escHtml(job.status));
            }
            if (job.scope) {
                html += metaPair(i18n.labelScope || 'Scope', escHtml(job.scope));
            }
            if (job.destination) {
                html += metaPair(i18n.destination || 'Destination', escHtml(formatDestination(job.destination, i18n)));
            }
            if (job.file_size) {
                html += metaPair(i18n.size || 'Size', escHtml(formatBytes(job.file_size)));
            }
            html += metaPair(
                i18n.databaseArchive || 'Database / archive',
                escHtml((job.db_mode || '—') + ' · ' + (job.archive_mode || '—'))
            );
            if (job.duration_sec !== null && job.duration_sec !== undefined) {
                html += metaPair(i18n.duration || 'Duration', escHtml(String(job.duration_sec)) + 's');
            }
            if (data.server) {
                html += metaPair(
                    i18n.phpEnvironment || 'PHP environment',
                    escHtml(String(data.server.time_limit)) + 's · ' + escHtml(data.server.php_memory)
                );
            }
            html += '</dl>';
            html += '</section>';
        } else {
            var ctx = entry.context || {};
            var filename = ctx.filename || ctx.file_name || '';
            if (!filename && entry.message) {
                var m = entry.message.match(/"([^"]+\.(?:zip|daf|venc))"/i);
                if (m) { filename = m[1]; }
            }
            var uploadId = ctx.upload_id || ctx.import_id || entry.upload_id || '';

            html += '<section class="mm-activity-detail-card">';
            html += '<h3 class="mm-activity-detail-card__title">' + escHtml(i18n.eventSummary || 'Event summary') + '</h3>';
            html += '<dl class="mm-activity-kv">';
            html += metaPair(i18n.type || 'Type', escHtml(typeLabel));
            html += metaPair(i18n.status || 'Status', escHtml(statusLabel));
            if (filename) {
                html += metaPair(i18n.file || 'File', escHtml(filename));
            }
            if (uploadId) {
                html += metaPair(i18n.uploadId || 'Upload ID', escHtml(uploadId));
            }
            if (data.server) {
                html += metaPair(
                    i18n.phpEnvironment || 'PHP environment',
                    escHtml(String(data.server.time_limit)) + 's · ' + escHtml(data.server.php_memory)
                );
            }
            if (entry.message) {
                html += metaPair(i18n.message || 'Message', escHtml(entry.message));
            }
            html += '</dl>';
            html += '</section>';
        }

        var bundled = (entry.bundled_entries && entry.bundled_entries.length) ? entry.bundled_entries : [entry];
        html += '<section class="mm-activity-detail-card">';
        html += '<h3 class="mm-activity-detail-card__title">' + escHtml((i18n.processEvents || 'Process events (%s)').replace('%s', bundled.length)) + '</h3>';
        html += '<ul class="mm-bundled-events-list">';
        bundled.forEach(function (sub) {
            var subTime = sub.time_display || sub.time || '';
            html += '<li>';
            html += '<span class="mm-status-pill ' + statusClass(sub.status) + '">' + escHtml(sub.status_label || sub.status) + '</span>';
            html += '<code>' + escHtml(subTime) + '</code>';
            html += '<span>' + escHtml(sub.message) + '</span>';
            html += '</li>';
        });
        html += '</ul>';
        html += '</section>';

        if (data.log_chunk) {
            var chunk = data.log_chunk;
            var log = chunk.log;
            var offsetEnd = chunk.offset_from_end || 0;
            var lineCount = chunk.line_count || 0;

            html += '<section class="mm-activity-detail-card mm-activity-detail-log mm-log-viewer-panel">';
            html += '<div class="mm-activity-detail-card__head">';
            html += '<h3 class="mm-activity-detail-card__title">' + escHtml(i18n.jobLog || 'Job log') + '</h3>';
            html += '<code class="mm-activity-log-file">' + escHtml(log) + '</code>';
            html += '</div>';
            var $logWrap = $('<div class="mm-log-viewer-wrap"></div>').attr({
                'data-log': log,
                'data-offset': String(offsetEnd),
                'data-lines': '200'
            });
            var $toolbar = $('<div class="mm-log-viewer-toolbar"></div>');
            if (chunk.has_newer) {
                $toolbar.append(
                    $('<a href="#" class="button button-secondary mm-log-view-newer"></a>')
                        .attr('data-offset', Math.max(0, offsetEnd - 200))
                        .text(i18n.loadNewer || 'Load newer')
                );
            }
            if (chunk.has_older) {
                if (chunk.older_file) {
                    $toolbar.append(
                        $('<a href="#" class="button button-secondary mm-log-view-older-file"></a>')
                            .attr({ 'data-log': chunk.older_file, 'data-offset': '0' })
                            .text((i18n.continueIn || 'Continue in %s').replace('%s', chunk.older_file))
                    );
                } else {
                    $toolbar.append(
                        $('<a href="#" class="button button-secondary mm-log-view-older"></a>')
                            .attr('data-offset', offsetEnd + 200)
                            .text(i18n.loadOlder || 'Load older')
                    );
                }
            }
            $logWrap.append($toolbar);
            $logWrap.append($('<pre class="mm-log-viewer"></pre>').text(chunk.lines || ''));
            $logWrap.append(
                $('<p class="description mm-activity-log-footer"></p>')
                    .text((i18n.showingLines || 'Showing %1$d lines').replace('%1$d', lineCount))
            );
            html += $logWrap.prop('outerHTML');
            html += '</section>';
        }

        html += '</div>';
        return html;
    }

    function columnLabel(selector, fallback) {
        var text = $('.mm-activity-table thead ' + selector).first().text();
        return (text && text.trim()) || fallback;
    }

    function renderActivityRow(entry) {
        var admin = adminConfig();
        var i18n = admin.i18n || {};
        var entryId = entry.entry_id || '';
        var status = entry.status || 'info';
        var typeLabel = entry.type_label || entry.type || '';
        var statusLabel = entry.status_label || status;
        var jobId = parseInt(entry.job_id, 10) || 0;
        var titleTemplate = i18n.activityJobTitle || '%1$s job #%2$d';
        var detailTitle = jobId > 0
            ? titleTemplate.replace('%1$s', typeLabel).replace('%2$d', String(jobId))
            : typeLabel;
        var message = entry.message || '';
        var bundledCount = parseInt(entry.bundled_count, 10) || 0;
        var timeLabel = columnLabel('.column-date', 'Time');
        var typeCol = columnLabel('.column-type', i18n.type || 'Type');
        var statusCol = columnLabel('.column-status', i18n.status || 'Status');
        var messageCol = columnLabel('.column-message', 'Message');
        var actionsCol = columnLabel('.column-actions', 'Actions');
        var html = '<tr data-entry-id="' + escHtml(entryId) + '">';
        html += '<td class="column-date mm-activity-time" data-label="' + escHtml(timeLabel) + '"><code>' + escHtml(entry.time_display || entry.time || '') + '</code></td>';
        html += '<td class="column-type" data-label="' + escHtml(typeCol) + '"><span class="mm-status-chip mm-activity-type">' + escHtml(typeLabel) + '</span></td>';
        html += '<td class="column-status" data-label="' + escHtml(statusCol) + '"><span class="' + escHtml(statusPillClass(status)) + '">' + escHtml(statusLabel) + '</span></td>';
        html += '<td class="column-message mm-activity-message" data-label="' + escHtml(messageCol) + '" title="' + escHtml(message) + '">' + escHtml(message);
        if (bundledCount > 1) {
            html += ' <span class="mm-activity-bundled-hint">' + escHtml((i18n.bundledEventsHint || '(%d events — open Details)').replace('%d', String(bundledCount))) + '</span>';
        }
        html += '</td>';
        html += '<td class="column-actions mm-row-actions" data-label="' + escHtml(actionsCol) + '">';
        html += '<button type="button" class="button button-small button-primary mm-btn-teal mm-activity-detail-btn" data-entry-id="' + escHtml(entryId) + '" data-job-id="' + escHtml(String(jobId)) + '" data-title="' + escHtml(detailTitle) + '">' + escHtml(i18n.details || 'Details') + '</button>';
        html += '</td></tr>';
        return html;
    }

    function hasRunningEntries(entries) {
        if (!Array.isArray(entries)) {
            return false;
        }
        return entries.some(function (entry) {
            var status = String((entry && entry.status) || '').toLowerCase();
            return status === 'running' || status === 'progress' || status === 'active';
        });
    }

    function tableHasRunningRows() {
        var found = false;
        $('.mm-activity-table tbody .mm-status-pill').each(function () {
            if ($(this).hasClass('mm-status-warn') && /in progress/i.test($(this).text())) {
                found = true;
                return false;
            }
            return true;
        });
        return found;
    }

    var pollTimer = null;
    var pollInFlight = false;

    function stopActivityPoll() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function scheduleActivityPoll(delay) {
        stopActivityPoll();
        pollTimer = setTimeout(pollActivityList, typeof delay === 'number' ? delay : 1500);
    }

    function readFilterState() {
        var $form = $('.mm-activity-filters').first();
        var page = 1;
        var pageMatch = window.location.search.match(/[?&]paged=(\d+)/);
        if (pageMatch) {
            page = Math.max(1, parseInt(pageMatch[1], 10) || 1);
        }
        return {
            type: $form.find('[name="type"]').val() || '',
            date_from: $form.find('[name="date_from"]').val() || '',
            date_to: $form.find('[name="date_to"]').val() || '',
            page: page,
            per_page: $('.mm-activity-table tbody tr').length || 25
        };
    }

    function pollActivityList() {
        var admin = adminConfig();
        if (!admin.ajaxUrl || !$('.mm-activity-table tbody').length) {
            return;
        }
        if (pollInFlight) {
            scheduleActivityPoll(1500);
            return;
        }
        pollInFlight = true;
        var filters = readFilterState();
        $.post(admin.ajaxUrl, {
            action: actionName('activityListAction', defaultListAction()),
            nonce: admin.nonce,
            type: filters.type,
            date_from: filters.date_from,
            date_to: filters.date_to,
            page: filters.page,
            per_page: filters.per_page
        }).done(function (resp) {
            if (!resp || !resp.success || !resp.data || !Array.isArray(resp.data.entries)) {
                return;
            }
            var rows = resp.data.entries.map(renderActivityRow).join('');
            $('.mm-activity-table tbody').html(rows);
            if (hasRunningEntries(resp.data.entries)) {
                scheduleActivityPoll(1500);
            } else {
                stopActivityPoll();
            }
        }).always(function () {
            pollInFlight = false;
        }).fail(function () {
            scheduleActivityPoll(3000);
        });
    }

    var releaseDetailA11y = null;

    function openDetail(entryId, title, jobId) {
        var $dialog = $('#mm-activity-detail-dialog');
        var admin = adminConfig();
        var i18n = admin.i18n || {};
        var ui = adminUI();
        $('#mm-activity-detail-title').text(title || i18n.activityDetails || 'Activity details');
        $('#mm-activity-detail-body').html('<p class="description">' + escHtml(admin.loadingText || 'Loading…') + '</p>');
        $dialog.removeClass('mm-hidden');
        var $modal = $dialog.find('.mm-restore-modal').first();
        if (!$modal.length) {
            $modal = $dialog;
        }
        if (typeof releaseDetailA11y === 'function') {
            releaseDetailA11y();
            releaseDetailA11y = null;
        }
        if (ui && typeof ui.bindOverlayA11y === 'function') {
            releaseDetailA11y = ui.bindOverlayA11y({
                $container: $modal,
                $initialFocus: $dialog.find('.mm-activity-detail-close').first(),
                ns: 'mmActivityDetail',
                onEscape: closeDetail
            });
        }

        function refreshDetailTrap() {
            if (ui && typeof ui.refreshTrap === 'function') {
                ui.refreshTrap(releaseDetailA11y);
            }
        }

        var requestFailedMsg = i18n.requestFailed || 'Request failed.';

        $.post(admin.ajaxUrl, {
            action: actionName('activityDetailAction', defaultDetailAction()),
            nonce: admin.nonce,
            entry_id: entryId || '',
            job_id: jobId || 0
        }).done(function (resp) {
            if (!resp || !resp.success) {
                $('#mm-activity-detail-body').html('<p class="mm-status-warn">' + escHtml((resp && resp.data && resp.data.message) || requestFailedMsg) + '</p>');
                refreshDetailTrap();
                return;
            }
            $('#mm-activity-detail-body').html(renderDetail(resp.data));
            refreshDetailTrap();
        }).fail(function () {
            $('#mm-activity-detail-body').html('<p class="mm-status-warn">' + escHtml(requestFailedMsg) + '</p>');
            refreshDetailTrap();
        });
    }

    function closeDetail() {
        if (typeof releaseDetailA11y === 'function') {
            releaseDetailA11y();
            releaseDetailA11y = null;
        }
        $('#mm-activity-detail-dialog').addClass('mm-hidden');
    }

    $(document).on('click', '.mm-activity-detail-btn', function (e) {
        e.preventDefault();
        openDetail($(this).data('entry-id'), $(this).data('title'), $(this).data('job-id'));
    });

    $(document).on('click', '.mm-activity-detail-close, #mm-activity-detail-dialog .mm-restore-overlay', function (e) {
        if (e.target === this || $(e.target).closest('.mm-activity-detail-close').length) {
            closeDetail();
        }
    });

    function loadLogChunk($wrap, log, offset, lines) {
        var admin = adminConfig();
        var $viewer = $wrap.find('.mm-log-viewer');
        var $toolbar = $wrap.find('.mm-log-viewer-toolbar');
        var i18n = admin.i18n || {};
        var requestFailedMsg = i18n.requestFailed || 'Request failed.';
        $viewer.text(admin.loadingText || 'Loading…');
        $.post(admin.ajaxUrl, {
            action: actionName('logChunkAction', defaultLogChunkAction()),
            nonce: admin.nonce,
            log: log,
            offset: offset,
            lines: lines
        }).done(function (resp) {
            if (!resp || !resp.success || !resp.data) {
                $viewer.text((resp && resp.data && resp.data.message) || requestFailedMsg);
                return;
            }
            var data = resp.data;
            $wrap.attr('data-log', data.log || log);
            $wrap.attr('data-offset', String(data.offset_from_end || 0));
            $wrap.data('log', data.log || log);
            $wrap.data('offset', data.offset_from_end || 0);
            $viewer.text(data.lines || '');
            $toolbar.find('.mm-log-view-newer, .mm-log-view-older, .mm-log-view-older-file').remove();
            if (data.has_newer) {
                $toolbar.prepend(
                    '<a href="#" class="button button-secondary mm-log-view-newer" data-offset="' +
                    Math.max(0, (data.offset_from_end || 0) - lines) + '">' +
                    escHtml(i18n.loadNewer || 'Load newer') + '</a>'
                );
            }
            if (data.has_older) {
                if (data.older_file) {
                    $toolbar.append(
                        $('<a href="#" class="button button-secondary mm-log-view-older-file"></a>')
                            .attr({ 'data-log': data.older_file, 'data-offset': '0' })
                            .text((i18n.continueIn || 'Continue in %s').replace('%s', data.older_file))
                    );
                } else {
                    $toolbar.append(
                        '<a href="#" class="button button-secondary mm-log-view-older" data-offset="' +
                        ((data.offset_from_end || 0) + lines) + '">' +
                        escHtml(i18n.loadOlder || 'Load older') + '</a>'
                    );
                }
            }
        }).fail(function () {
            $viewer.text(requestFailedMsg);
        });
    }

    $(document).on('click', '.mm-log-viewer-wrap .mm-log-view-newer, .mm-log-viewer-wrap .mm-log-view-older', function (e) {
        e.preventDefault();
        var $wrap = $(this).closest('.mm-log-viewer-wrap');
        loadLogChunk($wrap, $wrap.data('log'), parseInt($(this).data('offset'), 10) || 0, parseInt($wrap.data('lines'), 10) || 200);
    });

    $(document).on('click', '.mm-log-viewer-wrap .mm-log-view-older-file', function (e) {
        e.preventDefault();
        var $wrap = $(this).closest('.mm-log-viewer-wrap');
        loadLogChunk($wrap, $(this).data('log'), 0, parseInt($wrap.data('lines'), 10) || 200);
    });

    $(function () {
        if ($('.mm-activity-table tbody').length && tableHasRunningRows()) {
            scheduleActivityPoll(1500);
        }
    });
}(jQuery));
