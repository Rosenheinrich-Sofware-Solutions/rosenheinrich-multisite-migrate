(function ($, window, document) {
    'use strict';

    var cfg = window.rmmigrateUninstall;
    var pluginBasenames = (cfg && cfg.pluginBasenames && cfg.pluginBasenames.length)
        ? cfg.pluginBasenames
        : (cfg && cfg.pluginBasename ? [cfg.pluginBasename] : []);
    if (!cfg || pluginBasenames.length === 0) {
        return;
    }

    function t(key, fallback) {
        return (cfg.i18n && cfg.i18n[key]) || fallback || key;
    }

    function rowSelector() {
        return pluginBasenames.map(function (basename) {
            return 'tr[data-plugin="' + basename.replace(/"/g, '\\"') + '"]';
        }).join(',');
    }

    // WP wraps plugin row actions in <span class="deactivate"><a>…</a></span>.
    function actionLinkInRow(row, action) {
        return row.querySelector('span.' + action + ' a');
    }

    function buildOptions($list, $inputs) {
        var keepAll = {
            id: 'keep_all',
            label: t('keepAllData', 'Keep all Multisite Migrate data'),
            help: '',
            radio: true,
            defaultChecked: true
        };
        var options = [
            keepAll,
            { id: 'delete_backups', label: t('deleteBackups', 'Delete backup archives'), help: t('deleteBackupsHelp', ''), radio: false },
            { id: 'delete_logs', label: t('deleteLogs', 'Delete logs'), help: t('deleteLogsHelp', ''), radio: false },
            { id: 'delete_settings', label: t('deleteSettings', 'Delete settings and database records'), help: t('deleteSettingsHelp', ''), radio: false }
        ];

        options.forEach(function (opt) {
            var $li = $('<li></li>');
            var $label = $('<label class="mm-uninstall-option"></label>');
            var inputType = opt.radio ? 'radio' : 'checkbox';
            var $input = $('<input type="' + inputType + '">')
                .attr('id', 'mm-uninstall-' + opt.id)
                .attr('name', opt.radio ? 'mm-uninstall-mode' : opt.id);
            if (opt.defaultChecked) {
                $input.prop('checked', true);
            }
            var $text = $('<span class="mm-uninstall-option-text"></span>');
            $text.append($('<strong></strong>').text(opt.label));
            if (opt.help) {
                $text.append($('<span class="description"></span>').text(opt.help));
            }
            $label.append($input, $text);
            $li.append($label);
            $list.append($li);
            $inputs[opt.id] = $input;
        });
    }

    function readPlan($inputs) {
        if ($inputs.keep_all.is(':checked')) {
            return {
                delete_backups: 0,
                delete_logs: 0,
                delete_settings: 0
            };
        }

        return {
            delete_backups: $inputs.delete_backups.is(':checked') ? 1 : 0,
            delete_logs: $inputs.delete_logs.is(':checked') ? 1 : 0,
            delete_settings: $inputs.delete_settings.is(':checked') ? 1 : 0
        };
    }

    /**
     * Optional deactivate survey — separate DOM/names from keep/remove data UI.
     * @return {{$el: JQuery, read: function(): {reason: string, message: string}}}
     */
    function buildDeactivateSurvey() {
        var reasons = Array.isArray(cfg.reasons) ? cfg.reasons : [];
        var $el = $('<div class="mm-deactivate-survey"></div>');
        var headingId = 'mm-deactivate-survey-title-' + String(Date.now());
        var $title = $('<p class="mm-deactivate-survey-title" id="' + headingId + '"></p>').text(
            t('surveyTitle', 'Why are you deactivating? (optional)')
        );
        var $list = $('<ul class="mm-deactivate-survey-reasons" role="radiogroup" aria-labelledby="' + headingId + '"></ul>');
        var radioName = 'mm_deactivate_reason';

        reasons.forEach(function (item) {
            if (!item || !item.id) {
                return;
            }
            var id = 'mm-deactivate-reason-' + item.id;
            var $li = $('<li></li>');
            var $label = $('<label class="mm-deactivate-survey-option"></label>').attr('for', id);
            var $input = $('<input type="radio">')
                .attr({
                    id: id,
                    name: radioName,
                    value: item.id
                });
            $label.append($input, $('<span></span>').text(item.label || item.id));
            $li.append($label);
            $list.append($li);
        });

        var $details = $('<div class="mm-deactivate-survey-details"></div>');
        var $msgLabel = $('<label class="mm-deactivate-survey-message-label"></label>')
            .attr('for', 'mm-deactivate-reason-message')
            .text(t('surveyDetails', 'Anything else? (optional)'));
        var $msg = $('<textarea id="mm-deactivate-reason-message" class="mm-deactivate-survey-message" rows="3" maxlength="1000"></textarea>')
            .attr('placeholder', t('surveyDetailsPlaceholder', ''));
        var defaultEmail = (cfg.defaultContactEmail || '').toString();
        var $emailLabel = $('<label class="mm-deactivate-survey-message-label"></label>')
            .attr('for', 'mm-deactivate-contact-email')
            .text(t('surveyContactLabel', 'Email for follow-up (optional)'));
        var $emailHint = $('<p class="mm-deactivate-survey-contact-hint"></p>')
            .text(t('surveyContactHint', 'We may contact you if we need more details about a problem.'));
        var $email = $('<input type="email" id="mm-deactivate-contact-email" class="mm-deactivate-survey-email" maxlength="100" autocomplete="email">')
            .val(defaultEmail);
        var $contact = $('<div class="mm-deactivate-survey-contact"></div>');
        $contact.append($emailLabel, $email, $emailHint);
        $details.append($msgLabel, $msg, $contact);
        $el.append($title, $list, $details);

        return {
            $el: $el,
            read: function () {
                var reason = ($el.find('input[name="' + radioName + '"]:checked').val() || '').toString();
                var message = ($msg.val() || '').toString().replace(/^\s+|\s+$/g, '');
                var contactEmail = ($email.val() || '').toString().replace(/^\s+|\s+$/g, '');
                return { reason: reason, message: message, contact_email: contactEmail };
            }
        };
    }

    function postDeactivateFeedback(reason, message, contactEmail) {
        if (!cfg.feedbackAction || !cfg.feedbackNonce || !reason) {
            return $.Deferred().resolve().promise();
        }
        if (reason === 'other' && !message) {
            return $.Deferred().resolve().promise();
        }

        return $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 3000,
            data: {
                action: cfg.feedbackAction,
                nonce: cfg.feedbackNonce,
                reason: reason,
                message: message,
                contact_email: contactEmail || ''
            }
        });
    }

    function postAction(action, nonce, plan, $button, busyText, idleText, failText, pluginBasename) {
        $button.prop('disabled', true).text(busyText);
        return $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 120000,
            data: $.extend({
                action: action,
                nonce: nonce,
                plugin: pluginBasename || pluginBasenames[0],
                network_scope: cfg.networkScope ? 1 : 0
            }, plan)
        }).done(function (res) {
            if (res && res.success && res.data && res.data.redirect) {
                window.location.assign(res.data.redirect);
                return;
            }
            var ui = window.rmmigrateAdminUI || window.rmmigrateProAdminUI;
            var errMsg = (res && res.data && res.data.message) || failText;
            if (ui && typeof ui.alert === 'function') {
                ui.alert(errMsg);
            } else {
                console.error(errMsg);
            }
        }).fail(function (xhr, textStatus) {
            $button.prop('disabled', false).text(idleText);
            var message = failText;
            if (textStatus === 'timeout') {
                message = t('deactivateFailed', failText);
            } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }
            var ui = window.rmmigrateAdminUI || window.rmmigrateProAdminUI;
            if (ui && typeof ui.alert === 'function') {
                ui.alert(message);
            } else {
                console.error(message);
            }
        });
    }

    function showDataModal(mode, row) {
        var pluginBasename = row ? row.getAttribute('data-plugin') : pluginBasenames[0];
        var isDelete = mode === 'delete';
        var titleId = 'mm-uninstall-title-' + String(Date.now());
        var $overlay = $('<div class="mm-confirm-overlay"></div>');
        var $modal = $('<div class="mm-confirm-modal mm-uninstall-modal" role="alertdialog" aria-modal="true" aria-labelledby="' + titleId + '"></div>');
        var $title = $('<h2 class="mm-uninstall-title" id="' + titleId + '"></h2>').text(
            isDelete ? t('deleteTitle', 'Delete Multisite Migrate') : t('deactivateTitle', 'Deactivate Multisite Migrate')
        );
        var $intro = $('<p class="mm-uninstall-intro"></p>').text(
            isDelete ? t('deleteIntro', '') : t('deactivateIntro', '')
        );
        var survey = !isDelete ? buildDeactivateSurvey() : null;

        var $presets = $('<p class="mm-uninstall-presets"></p>');
        var $keepAll = $('<button type="button" class="button-link"></button>').text(t('keepAll', 'Keep all data'));
        var $removeAll = $('<button type="button" class="button-link"></button>').text(t('removeAll', 'Remove all data'));
        $presets.append($keepAll, ' | ', $removeAll);

        var $list = $('<ul class="mm-uninstall-options"></ul>');
        var $inputs = {};
        buildOptions($list, $inputs);

        var $actions = $('<div class="mm-confirm-actions"></div>');
        var $cancel = $('<button type="button" class="button"></button>').text(t('cancel', 'Cancel'));
        var confirmKey = isDelete ? 'deletePlugin' : 'deactivate';
        var busyKey = isDelete ? 'deleting' : 'deactivating';
        var failKey = isDelete ? 'deleteFailed' : 'deactivateFailed';
        var $confirm = $('<button type="button" class="button button-primary mm-btn-teal"></button>').text(t(confirmKey, isDelete ? 'Delete plugin' : 'Deactivate'));

        $actions.append($confirm, $cancel);
        $modal.append($title, $intro);
        if (survey) {
            $modal.append(survey.$el);
        }
        $modal.append($presets, $list, $actions);
        $overlay.append($modal);
        $('body').append($overlay);

        var releaseUninstallA11y = null;

        function setKeepAll() {
            $inputs.keep_all.prop('checked', true);
            $inputs.delete_backups.prop('checked', false);
            $inputs.delete_logs.prop('checked', false);
            $inputs.delete_settings.prop('checked', false);
        }

        function setRemoveAll() {
            $inputs.keep_all.prop('checked', false);
            $inputs.delete_backups.prop('checked', true);
            $inputs.delete_logs.prop('checked', true);
            $inputs.delete_settings.prop('checked', true);
        }

        function close() {
            if (typeof releaseUninstallA11y === 'function') {
                releaseUninstallA11y();
                releaseUninstallA11y = null;
            } else {
                $(document).off('keydown.mmUninstall');
            }
            $overlay.remove();
        }

        if (window.rmmigrateAdminUI && typeof rmmigrateAdminUI.bindOverlayA11y === 'function') {
            releaseUninstallA11y = rmmigrateAdminUI.bindOverlayA11y({
                $container: $modal,
                $initialFocus: $confirm,
                ns: 'mmUninstall',
                onEscape: close
            });
        } else {
            $confirm.focus();
            $(document).on('keydown.mmUninstall', function (e) {
                if (e.key === 'Escape') {
                    close();
                }
            });
        }

        $inputs.keep_all.on('change', function () {
            if ($inputs.keep_all.is(':checked')) {
                $inputs.delete_backups.prop('checked', false);
                $inputs.delete_logs.prop('checked', false);
                $inputs.delete_settings.prop('checked', false);
            }
        });

        [$inputs.delete_backups, $inputs.delete_logs, $inputs.delete_settings].forEach(function ($input) {
            $input.on('change', function () {
                if ($input.is(':checked')) {
                    $inputs.keep_all.prop('checked', false);
                } else if (
                    !$inputs.delete_backups.is(':checked')
                    && !$inputs.delete_logs.is(':checked')
                    && !$inputs.delete_settings.is(':checked')
                ) {
                    $inputs.keep_all.prop('checked', true);
                }
            });
        });

        $keepAll.on('click', function (e) {
            e.preventDefault();
            setKeepAll();
        });
        $removeAll.on('click', function (e) {
            e.preventDefault();
            setRemoveAll();
        });
        $cancel.on('click', close);
        $overlay.on('click', function (e) {
            if (e.target === $overlay[0]) {
                close();
            }
        });
        $confirm.on('click', function () {
            var plan = readPlan($inputs);
            var action = isDelete ? 'rmmigrate_uninstall' : 'rmmigrate_deactivate';
            var nonce = isDelete ? cfg.uninstallNonce : cfg.deactivateNonce;
            var busyText = t(busyKey, isDelete ? 'Deleting…' : 'Deactivating…');
            var idleText = t(confirmKey, isDelete ? 'Delete plugin' : 'Deactivate');
            var failText = t(failKey, isDelete ? 'Could not delete the plugin. Try again.' : 'Could not deactivate Multisite Migrate. Try again.');

            function runDeactivateOrDelete() {
                postAction(action, nonce, plan, $confirm, busyText, idleText, failText, pluginBasename);
            }

            if (isDelete || !survey) {
                runDeactivateOrDelete();
                return;
            }

            var surveyData = survey.read();
            if (!surveyData.reason || (surveyData.reason === 'other' && !surveyData.message)) {
                runDeactivateOrDelete();
                return;
            }

            $confirm.prop('disabled', true).text(busyText);

            var finished = false;
            function finishSurveyThenAct() {
                if (finished) {
                    return;
                }
                finished = true;
                runDeactivateOrDelete();
            }

            window.setTimeout(finishSurveyThenAct, 3000);
            postDeactivateFeedback(surveyData.reason, surveyData.message, surveyData.contact_email)
                .always(finishSurveyThenAct);
        });
    }

    document.addEventListener('click', function (e) {
        var row = e.target.closest(rowSelector());
        if (!row) {
            return;
        }

        var deactivateLink = actionLinkInRow(row, 'deactivate');
        if (deactivateLink && deactivateLink.contains(e.target)) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            showDataModal('deactivate', row);
            return;
        }

        var deleteLink = actionLinkInRow(row, 'delete');
        if (deleteLink && deleteLink.contains(e.target)) {
            if (!cfg.canDelete) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            showDataModal('delete', row);
        }
    }, true);
}(jQuery, window, document));
