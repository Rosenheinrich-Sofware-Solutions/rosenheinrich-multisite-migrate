(function ($) {
    'use strict';

    function t(key, fallback) {
        return rmmigrateAdminUI.i18n(key, fallback);
    }

    function markStep(step) {
        return $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_setup_wizard_step',
            nonce: rmmigrateAdmin.nonce,
            step: step
        });
    }

    function completeAndGo(archivesUrl) {
        return $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_setup_wizard_complete',
            nonce: rmmigrateAdmin.nonce
        }).done(function (res) {
            var url = (res && res.data && res.data.redirect) ? res.data.redirect : (archivesUrl || rmmigrateAdmin.backupsUrl);
            window.location.href = url;
        }).fail(function () {
            window.location.href = archivesUrl || rmmigrateAdmin.backupsUrl || window.location.href;
        });
    }


    function postSubscribe(subscribeUrl, fields) {
        return fetch(subscribeUrl, {
            method: 'POST',
            mode: 'cors',
            credentials: 'omit',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(fields || {})
        }).then(function (resp) {
            if (!resp.ok) {
                throw new Error('subscribe_failed');
            }
            return resp.json();
        });
    }

    function fallbackSubscribe() {
        return $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_setup_wizard_newsletter_fallback',
            nonce: rmmigrateAdmin.nonce
        });
    }

    function markWelcomeDone() {
        var $card = $('.mm-setup-welcome .mm-setup-card');
        $card.addClass('is-done');
        $card.find('.mm-setup-card__actions').prop('hidden', true);
        $card.find('.mm-setup-card__thanks').prop('hidden', false);
        markStep('features');
        var el = document.getElementById('mm-setup-features');
        if (el && typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function allowAndContinue() {
        var $btn = $('.mm-setup-allow-continue');
        $btn.prop('disabled', true);
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_setup_wizard_newsletter',
            nonce: rmmigrateAdmin.nonce,
            allow: '1'
        }).done(function (res) {
            if (!res || !res.success || !res.data) {
                rmmigrateAdminUI.toast(t('requestFailed', 'Request failed'), 'error');
                $btn.prop('disabled', false);
                return;
            }
            var data = res.data;
            if (data.skipped) {
                markWelcomeDone();
                return;
            }
            postSubscribe(data.subscribeUrl, data.fields).then(function () {
                markWelcomeDone();
            }).catch(function () {
                fallbackSubscribe().always(function () {
                    markWelcomeDone();
                });
            });
        }).fail(function () {
            rmmigrateAdminUI.toast(t('requestFailed', 'Request failed'), 'error');
            $btn.prop('disabled', false);
        });
    }

    function skipWelcome() {
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_setup_wizard_newsletter',
            nonce: rmmigrateAdmin.nonce,
            allow: '0'
        }).always(function () {
            markWelcomeDone();
        });
    }

    $(document).on('click', '.mm-setup-allow-continue', function (e) {
        e.preventDefault();
        allowAndContinue();
    });

    $(document).on('click', '.mm-setup-skip-welcome', function (e) {
        e.preventDefault();
        skipWelcome();
    });

    $(document).on('click', '.mm-setup-create-backup', function (e) {
        e.preventDefault();
        var url = $(this).data('archives-url') || rmmigrateAdmin.backupsUrl;
        completeAndGo(url);
    });

}(jQuery));
