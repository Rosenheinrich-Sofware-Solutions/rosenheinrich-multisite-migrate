(function ($, window) {
    'use strict';

    window.rmmigrateAdminUI = {
        i18n: function (key, fallback) {
            if (typeof rmmigrateAdmin !== 'undefined' && rmmigrateAdmin.i18n && rmmigrateAdmin.i18n[key]) {
                return rmmigrateAdmin.i18n[key];
            }
            return fallback || key;
        },

        speak: function (message, ariaLive) {
            if (!message) {
                return;
            }
            if (window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
                wp.a11y.speak(String(message), ariaLive || 'polite');
            }
        },

        trapFocus: function ($container) {
            var $focusable = $container.find(
                'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
            ).filter(':visible');
            if (!$focusable.length) {
                return function () {};
            }
            var first = $focusable.get(0);
            var last = $focusable.get($focusable.length - 1);
            function onKeydown(e) {
                if (e.key !== 'Tab') {
                    return;
                }
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
            $container.on('keydown.mmTrapFocus', onKeydown);
            return function () {
                $container.off('keydown.mmTrapFocus');
            };
        },

        /**
         * Re-snapshot focusables on an active overlay release handle.
         * @param {Function} releaseHandle return value of bindOverlayA11y
         */
        refreshTrap: function (releaseHandle) {
            if (releaseHandle && typeof releaseHandle.refreshTrap === 'function') {
                releaseHandle.refreshTrap();
            }
        },

        /**
         * Bind Escape + focus trap + focus return for an overlay.
         * @return {Function} releaseAll (also has .refreshTrap)
         */
        bindOverlayA11y: function (opts) {
            var focusReturn = document.activeElement;
            var previousRelease = rmmigrateAdminUI._overlayRelease;
            var releaseTrap = rmmigrateAdminUI.trapFocus(opts.$container);
            var ns = opts.ns || 'mmOverlay';
            var $focus = opts.$initialFocus && opts.$initialFocus.length
                ? opts.$initialFocus
                : opts.$container.find('button, [href], input, select, textarea').filter(':visible').first();
            if ($focus && $focus.length) {
                $focus.trigger('focus');
            }
            $(document).on('keydown.' + ns, function (e) {
                if (e.key !== 'Escape' || typeof opts.onEscape !== 'function') {
                    return;
                }
                // Help tip owns Escape while its popover is open.
                if ($('.mm-help-popover').length) {
                    return;
                }
                e.preventDefault();
                e.stopImmediatePropagation();
                opts.onEscape();
            });
            function releaseAll() {
                $(document).off('keydown.' + ns);
                releaseTrap();
                if (rmmigrateAdminUI._overlayRelease === releaseAll) {
                    rmmigrateAdminUI._overlayRelease = previousRelease || null;
                }
                if (focusReturn && typeof focusReturn.focus === 'function') {
                    focusReturn.focus();
                }
            }
            releaseAll.refreshTrap = function () {
                releaseTrap();
                releaseTrap = rmmigrateAdminUI.trapFocus(opts.$container);
            };
            rmmigrateAdminUI._overlayRelease = releaseAll;
            return releaseAll;
        },

        ajaxErrorMessage: function (xhr, fallback) {
            fallback = fallback || rmmigrateAdminUI.i18n('requestFailed', 'Request failed');
            if (xhr && xhr.responseJSON) {
                if (xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    return xhr.responseJSON.data.message;
                }
                if (xhr.responseJSON.message) {
                    return xhr.responseJSON.message;
                }
            }
            if (xhr && xhr.status === 403) {
                return rmmigrateAdminUI.i18n('sessionExpired', 'Your session expired. Refresh the page and try again.');
            }
            return fallback;
        },

        reportAjaxFailure: function (opts) {
            opts = opts || {};
            var admin = typeof rmmigrateAdmin !== 'undefined' ? rmmigrateAdmin : null;
            if (!admin || !admin.reportAjaxErrorAction || !admin.nonce) {
                return;
            }
            var action = String(opts.action || '').trim();
            var message = String(opts.message || '').trim();
            if (action === '' || message === '' || action.indexOf('rmmigrate_') !== 0) {
                return;
            }
            $.post(admin.ajaxUrl, {
                action: admin.reportAjaxErrorAction,
                nonce: admin.nonce,
                ajax_action: action,
                message: message,
                job_id: parseInt(opts.jobId, 10) || 0,
                http_status: parseInt(opts.httpStatus, 10) || 0,
                phase: opts.phase || 'transport'
            });
        },

        reportJsonFail: function (action, message, phase, jobId) {
            message = String(message || '').trim();
            if (message === '' || action.indexOf('rmmigrate_') !== 0) {
                return;
            }
            rmmigrateAdminUI.reportAjaxFailure({
                action: action,
                message: message,
                httpStatus: 0,
                phase: phase || 'response',
                jobId: jobId || 0
            });
        },

        reportTransportFail: function (action, xhr, phase, jobId) {
            var msg = rmmigrateAdminUI.ajaxErrorMessage(xhr);
            rmmigrateAdminUI.reportAjaxFailure({
                action: action,
                message: msg,
                httpStatus: xhr && xhr.status ? xhr.status : 0,
                phase: phase || 'transport',
                jobId: jobId || 0
            });
            return msg;
        },

        showNotice: function (message, type, title, opts) {
            if (!message) {
                return $();
            }
            type = type || 'success';
            opts = opts || {};

            var isError = type === 'error' || type === 'danger' || type === 'failed';
            var isWarn = type === 'warn' || type === 'warning';
            var isInfo = type === 'info';

            var variantClass = 'mm-banner--' + (isError ? 'error' : (isWarn ? 'warning' : (isInfo ? 'info' : 'success')));
            var iconName = isError ? 'warning' : (isWarn ? 'warning' : (isInfo ? 'info' : 'yes-alt'));

            var $wrap = $('.mm-below-header');
            if (!$wrap.length) {
                var $header = $('.mm-app-header');
                if ($header.length) {
                    $wrap = $('<div class="mm-below-header"></div>').insertAfter($header);
                } else {
                    $wrap = $('<div class="mm-below-header"></div>').prependTo('.multisite-migrate-wrap');
                }
            }

            var $banners = $wrap.children('.mm-admin-banners');
            if (!$banners.length) {
                $banners = $('<div class="mm-admin-banners"></div>').prependTo($wrap);
            }

            var isSimple = !title;
            var $notice = $('<div class="mm-banner ' + variantClass + ' mm-notice-card mm-dynamic-notice" role="alert"></div>');
            var $inner = $('<div class="mm-notice-card__inner"></div>');
            $inner.append('<span class="mm-notice-card__icon dashicons dashicons-' + iconName + '" aria-hidden="true"></span>');
            var $content = $('<div class="mm-notice-card__content"></div>');
            if (title) {
                $content.append($('<p class="mm-notice-card__title"></p>').text(title));
            }
            $content.append($('<p class="mm-notice-card__text"></p>').text(message));
            $inner.append($content);
            var dismissLabel = rmmigrateAdminUI.i18n('dismissNotice', 'Dismiss notice');
            var dismissBtn = document.createElement('button');
            dismissBtn.type = 'button';
            dismissBtn.className = 'mm-banner__dismiss mm-notice-card__dismiss';
            dismissBtn.setAttribute('aria-label', dismissLabel);
            var dismissSr = document.createElement('span');
            dismissSr.className = 'screen-reader-text';
            dismissSr.textContent = dismissLabel;
            dismissBtn.appendChild(dismissSr);
            $inner.append(dismissBtn);
            $notice.append($inner);
            $banners.prepend($notice);

            rmmigrateAdminUI.speak(message, isError ? 'assertive' : 'polite');

            if (window.matchMedia && !window.matchMedia('(prefers-reduced-motion: reduce)').matches && $notice.length && typeof $notice[0].scrollIntoView === 'function') {
                $notice[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }

            var autoHideMs = typeof opts.duration === 'number' ? opts.duration : (isError ? 0 : 7000);
            if (autoHideMs > 0) {
                setTimeout(function () {
                    $notice.fadeOut(250, function () {
                        var $b = $(this).closest('.mm-admin-banners');
                        $(this).remove();
                        if ($b.length && !$b.children().length) {
                            $b.remove();
                        }
                    });
                }, autoHideMs);
            }

            return $notice;
        },

        toast: function (message, type, opts) {
            return rmmigrateAdminUI.showNotice(message, type, null, opts);
        },

        /**
         * Disable click target(s) while work runs.
         * work(release) may call release() itself and/or return a jQuery/Promise
         * that settles → auto-release. Returns false if already busy.
         * @param {JQuery|Element|string} targets
         * @param {function(Function=): (*|undefined)} work
         * @param {{keepDisabled?: boolean}} [opts]
         * @return {boolean}
         */
        withBusy: function (targets, work, opts) {
            opts = opts || {};
            var $targets = $(targets);
            if ($targets.length && $targets.filter(function () {
                return $(this).data('mmBusy') || $(this).prop('disabled');
            }).length) {
                return false;
            }
            $targets.each(function () {
                var $el = $(this);
                $el.data('mmBusy', 1);
                if ($el.is('button, input, select, textarea')) {
                    $el.prop('disabled', true);
                } else if ($el.is('a.button')) {
                    $el.attr('aria-disabled', 'true').addClass('disabled');
                }
            });
            var finished = false;
            function release() {
                if (finished || opts.keepDisabled) {
                    return;
                }
                finished = true;
                $targets.each(function () {
                    var $el = $(this);
                    $el.removeData('mmBusy');
                    if ($el.is('button, input, select, textarea')) {
                        $el.prop('disabled', false);
                    } else if ($el.is('a.button')) {
                        $el.removeAttr('aria-disabled').removeClass('disabled');
                    }
                });
            }
            var result;
            try {
                result = typeof work === 'function' ? work(release) : null;
            } catch (err) {
                release();
                throw err;
            }
            if (result && typeof result.always === 'function') {
                result.always(release);
            } else if (result && typeof result.then === 'function') {
                result.then(release, release);
            }
            return true;
        },

        confirm: function (message, onConfirm, onCancel) {
            var descId = 'mm-confirm-desc-' + String(Date.now()) + '-' + Math.random().toString(36).slice(2, 9);
            var $overlay = $('<div class="mm-confirm-overlay"></div>');
            var $modal = $('<div class="mm-confirm-modal" role="alertdialog" aria-modal="true" aria-describedby="' + descId + '"></div>');
            $modal.attr('aria-label', rmmigrateAdminUI.i18n('confirm', 'Confirm'));
            $modal.append($('<p id="' + descId + '"></p>').text(message));
            var $actions = $('<div class="mm-confirm-actions"></div>');
            var $cancel = $('<button type="button" class="button"></button>').text(rmmigrateAdminUI.i18n('cancel', 'Cancel'));
            var $ok = $('<button type="button" class="button button-primary mm-btn-teal"></button>').text(rmmigrateAdminUI.i18n('confirm', 'Confirm'));
            $actions.append($cancel, $ok);
            $modal.append($actions);
            $overlay.append($modal);
            $('body').append($overlay);

            var releaseA11y = null;
            var settled = false;
            function close(didConfirm) {
                if (settled) {
                    return;
                }
                settled = true;
                if (typeof releaseA11y === 'function') {
                    releaseA11y();
                    releaseA11y = null;
                }
                $overlay.remove();
                if (didConfirm) {
                    if (typeof onConfirm === 'function') {
                        onConfirm();
                    }
                } else if (typeof onCancel === 'function') {
                    onCancel();
                }
            }

            releaseA11y = rmmigrateAdminUI.bindOverlayA11y({
                $container: $modal,
                $initialFocus: $ok,
                ns: 'mmConfirm',
                onEscape: function () {
                    close(false);
                }
            });

            $cancel.on('click', function () {
                close(false);
            });
            $overlay.on('click', function (e) {
                if (e.target === $overlay[0]) {
                    close(false);
                }
            });
            $ok.on('click', function () {
                if ($ok.prop('disabled')) {
                    return;
                }
                $ok.prop('disabled', true);
                $cancel.prop('disabled', true);
                close(true);
            });
        },

        alert: function (message) {
            var descId = 'mm-alert-desc-' + String(Date.now()) + '-' + Math.random().toString(36).slice(2, 9);
            var $overlay = $('<div class="mm-confirm-overlay"></div>');
            var $modal = $('<div class="mm-confirm-modal" role="alertdialog" aria-modal="true" aria-describedby="' + descId + '"></div>');
            $modal.attr('aria-label', rmmigrateAdminUI.i18n('alertTitle', 'Notice'));
            $modal.append($('<p id="' + descId + '"></p>').text(message));
            var $actions = $('<div class="mm-confirm-actions"></div>');
            var $ok = $('<button type="button" class="button button-primary mm-btn-teal"></button>').text(rmmigrateAdminUI.i18n('close', 'Close'));
            $actions.append($ok);
            $modal.append($actions);
            $overlay.append($modal);
            $('body').append($overlay);

            var releaseA11y = null;
            function close() {
                $(document).off('keydown.mmAlertEnter');
                if (typeof releaseA11y === 'function') {
                    releaseA11y();
                    releaseA11y = null;
                }
                $overlay.remove();
            }

            releaseA11y = rmmigrateAdminUI.bindOverlayA11y({
                $container: $modal,
                $initialFocus: $ok,
                ns: 'mmAlert',
                onEscape: close
            });

            $ok.on('click', close);
            $overlay.on('click', function (e) {
                if (e.target === $overlay[0]) {
                    close();
                }
            });
            $(document).on('keydown.mmAlertEnter', function (e) {
                if (e.key === 'Enter' && document.activeElement === $ok[0]) {
                    close();
                }
            });
        },

        syncWorkerStaleBanner: function (data) {
            var $banner = $('#mm-active-job-banner');
            if (!$banner.length || $banner.hasClass('mm-finished-job')) {
                return;
            }
            var show = !!(data && data.worker_stale_hint);
            $banner.toggleClass('mm-active-job-banner--worker-stale', show);
            var $hint = $('#mm-active-job-worker-stale-hint');
            if (show) {
                if (!$hint.length) {
                    $hint = $('<p id="mm-active-job-worker-stale-hint" class="mm-active-job-worker-stale-hint mm-status-warn" role="status"></p>');
                    var $text = $('#mm-active-job-text');
                    if ($text.length) {
                        $text.after($hint);
                    } else {
                        $banner.append($hint);
                    }
                }
                $hint.text(rmmigrateAdminUI.i18n(
                    'workerStaleHint',
                    'No recent worker activity. If progress stays frozen, refresh the page, cancel the job, or wait — stale jobs are cleared automatically.'
                )).removeClass('mm-hidden');
            } else if ($hint.length) {
                $hint.addClass('mm-hidden');
            }
        },

        pollJob: function (options) {
            var jobId = options.jobId;
            var $fill = options.$fill;
            var $text = options.$text;
            var pollTimer = null;
            var stopped = false;

            function resolveProgressMessage(data) {
                var msg = (data && data.message) ? data.message : '';
                if (!data || data.job_type !== 'restore' || !rmmigrateAdmin.restoreStepLabels) {
                    return msg;
                }
                var step = data.progress_step || '';
                if (step && rmmigrateAdmin.restoreStepLabels[step]) {
                    return rmmigrateAdmin.restoreStepLabels[step];
                }
                return msg;
            }

            function update(percent, message) {
                if ($fill && $fill.length) {
                    $fill.css('width', percent + '%');
                }
                if ($text && $text.length) {
                    $text.text(percent + '% — ' + (message || ''));
                }
            }

            var pollFailures = 0;
            var pollFailureLimit = 15;

            function poll() {
                if (stopped) {
                    return;
                }
                if (pollTimer) {
                    clearTimeout(pollTimer);
                }
                $.get(rmmigrateAdmin.ajaxUrl, {
                    action: 'rmmigrate_status',
                    job_id: jobId,
                    nonce: rmmigrateAdmin.nonce
                }).done(function (res) {
                    if (stopped) {
                        return;
                    }
                    if (!res.success || !res.data) {
                        pollFailures++;
                        if (pollFailures >= pollFailureLimit) {
                            var failPayload = { error: rmmigrateAdminUI.i18n('jobFailed', 'Failed') };
                            rmmigrateAdminUI.toast(failPayload.error, 'error');
                            if (typeof options.onError === 'function') {
                                options.onError(failPayload);
                            }
                            return;
                        }
                        pollTimer = setTimeout(poll, 2000);
                        return;
                    }
                    pollFailures = 0;
                    var d = res.data;
                    var pct = d.percent || 0;
                    update(pct, resolveProgressMessage(d));
                    rmmigrateAdminUI.syncWorkerStaleBanner(d);

                    if (d.active) {
                        var kickoff = rmmigrateAdmin.kickoffMode || 'auto';
                        var delay = options.workerDelay || 1500;
                        if (kickoff === 'browser') {
                            pollTimer = setTimeout(function () {
                                $.post(rmmigrateAdmin.ajaxUrl, {
                                    action: 'rmmigrate_worker',
                                    job_id: jobId,
                                    nonce: rmmigrateAdmin.nonce
                                }).always(function () {
                                    poll();
                                });
                            }, delay);
                            return;
                        }
                        // Background kickoff: status-only UI; rare failsafe worker.
                        pollTimer = setTimeout(function () {
                            var now = Date.now();
                            var last = rmmigrateAdminUI._lastFailsafeWorker || 0;
                            if (now - last >= 20000) {
                                rmmigrateAdminUI._lastFailsafeWorker = now;
                                $.post(rmmigrateAdmin.ajaxUrl, {
                                    action: 'rmmigrate_worker',
                                    job_id: jobId,
                                    nonce: rmmigrateAdmin.nonce
                                }).always(function () {
                                    poll();
                                });
                                return;
                            }
                            poll();
                        }, delay);
                        return;
                    }
                    // Terminal failure: ERROR=-1, CANCELLED=-2, DELETING=-3 (empty error must not onComplete).
                    var terminalStatus = typeof d.status === 'number' ? d.status : null;
                    if (d.error || (terminalStatus !== null && terminalStatus < 0)) {
                        if (!d.error) {
                            d.error = terminalStatus === -2
                                ? (rmmigrateAdminUI.i18n('jobCancelled', 'Cancelled'))
                                : (rmmigrateAdminUI.i18n('jobFailed', 'Failed'));
                        }
                        // One sticky toast only — callers must not toast again in onError.
                        rmmigrateAdminUI.toast(d.error, 'error');
                        if (typeof options.onError === 'function') {
                            options.onError(d);
                        }
                        return;
                    }
                    update(100, d.message || 'Complete');
                    // Stop before onComplete so overlapping status polls cannot fire twice
                    // (e.g. safety→restore handoff posting start_restore twice).
                    stopped = true;
                    if (typeof options.onComplete === 'function') {
                        options.onComplete(d);
                    }
                }).fail(function () {
                    if (stopped) {
                        return;
                    }
                    pollFailures++;
                    if (pollFailures >= pollFailureLimit) {
                        var failPayload = { error: rmmigrateAdminUI.i18n('jobFailed', 'Failed') };
                        if (rmmigrateAdminUI.reportAjaxFailure) {
                            rmmigrateAdminUI.reportAjaxFailure({
                                action: options.reportAction || 'rmmigrate_status',
                                message: failPayload.error,
                                jobId: jobId || 0,
                                httpStatus: 0,
                                phase: options.reportPhase || 'poll'
                            });
                        }
                        rmmigrateAdminUI.toast(failPayload.error, 'error');
                        if (typeof options.onError === 'function') {
                            options.onError(failPayload);
                        }
                        return;
                    }
                    pollTimer = setTimeout(poll, 2000);
                });
            }

            poll();
            return {
                stop: function () {
                    stopped = true;
                    if (pollTimer) {
                        clearTimeout(pollTimer);
                    }
                }
            };
        },

        initHelpTips: function () {
            if (rmmigrateAdminUI._helpTipsInit) {
                return;
            }
            rmmigrateAdminUI._helpTipsInit = true;

            var readTemplateHtml = function ($template) {
                var node = $template.get(0);
                if (!node) {
                    return '';
                }
                if (node.content && node.content.childNodes.length) {
                    var wrap = document.createElement('div');
                    wrap.appendChild(node.content.cloneNode(true));
                    return $.trim(wrap.innerHTML);
                }
                return $.trim($template.html());
            };

            function closeHelpPopover($tip) {
                $('.mm-help-popover').remove();
                $(document).off('keydown.mmHelpTipEsc click.mmHelpTipClose');
                if ($tip && $tip.length) {
                    $tip.attr('aria-expanded', 'false').removeAttr('aria-controls');
                } else {
                    $('.mm-help-tip[aria-expanded="true"]').attr('aria-expanded', 'false').removeAttr('aria-controls');
                }
            }

            $(document).on('click.mmHelpTip keydown.mmHelpTip', '.mm-help-tip', function (e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                var $tip = $(this);
                if ($tip.attr('aria-expanded') === 'true') {
                    closeHelpPopover($tip);
                    return;
                }

                var $template = $tip.children('template.mm-help-tip-template').first();
                var content = '';

                if ($template.length) {
                    content = readTemplateHtml($template);
                } else {
                    content = $.trim(String($tip.attr('data-tip') || $tip.attr('title') || ''));
                }
                if (!content) {
                    return;
                }

                closeHelpPopover();
                var popId = 'mm-help-pop-' + String(Date.now());
                var tipLabel = $tip.attr('aria-label') || rmmigrateAdminUI.i18n('help', 'Help');
                var $pop = $('<div class="mm-help-popover" role="dialog" aria-modal="false" id="' + popId + '"></div>');
                $pop.attr('aria-label', tipLabel);
                if ($template.length) {
                    $pop.html(content);
                } else {
                    $pop.text(content);
                }
                $('body').append($pop);
                $tip.attr({ 'aria-expanded': 'true', 'aria-controls': popId, 'aria-haspopup': 'dialog' });

                var offset = $tip.offset();
                var popW = $pop.outerWidth() || 280;
                var left = offset.left - (popW / 2) + ($tip.outerWidth() / 2);
                left = Math.max(8, Math.min(left, Math.max(8, window.innerWidth - popW - 8)));
                $pop.css({
                    top: offset.top + $tip.outerHeight() + 6,
                    left: left
                });

                $(document).on('keydown.mmHelpTipEsc', function (ev) {
                    if (ev.key !== 'Escape') {
                        return;
                    }
                    ev.preventDefault();
                    ev.stopImmediatePropagation();
                    closeHelpPopover($tip);
                    $tip.trigger('focus');
                });

                window.setTimeout(function () {
                    $(document).on('click.mmHelpTipClose', function (ev) {
                        if ($(ev.target).closest('.mm-help-tip, .mm-help-popover').length) {
                            return;
                        }
                        closeHelpPopover($tip);
                    });
                }, 10);
            });
        },

    };

    rmmigrateAdminUI.initHelpTips();

    $(document).on('click', '.mm-upgrade-notice .notice-dismiss', function () {
        if (typeof rmmigrateAdmin === 'undefined') {
            return;
        }
        var $btn = $(this);
        rmmigrateAdminUI.withBusy($btn, function (release) {
            $.post(rmmigrateAdmin.ajaxUrl, {
                action: 'rmmigrate_dismiss_upgrade_notice',
                nonce: rmmigrateAdmin.nonce
            }).fail(function (xhr) {
                rmmigrateAdminUI.reportTransportFail('rmmigrate_dismiss_upgrade_notice', xhr, 'dismiss');
            }).always(release);
        });
    });

    $(document).on('click', '.mm-dynamic-notice .mm-banner__dismiss', function (e) {
        e.preventDefault();
        var $notice = $(this).closest('.mm-dynamic-notice');
        $notice.fadeOut(200, function () {
            var $banners = $(this).closest('.mm-admin-banners');
            $(this).remove();
            if ($banners.length && !$banners.children().length) {
                $banners.remove();
            }
        });
    });

    // Network settings: toggle subsite include/exclude pickers by default scope.
    // No-op on pages without the network scope radios.
    $(function () {
        $(document).on('change', 'input[name="default_scope"]', function () {
            var v = $('input[name="default_scope"]:checked').val();
            $('#mm-network-subsite-include').toggleClass('mm-hidden', v !== 'network_included');
            $('#mm-network-subsite-exclude').toggleClass('mm-hidden', v !== 'network_filtered');
        });
    });
})(jQuery, window);
