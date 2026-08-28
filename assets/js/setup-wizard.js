(function ($) {
    'use strict';

    var finishOptInInFlight = false;
    var wizardCompleteInFlight = false;

    function t(key, fallback) {
        return rmmigrateAdminUI.i18n(key, fallback);
    }

    function adminHref(url) {
        var fallback = 'admin.php?page=multisite-migrate-archives';
        if (!url || typeof url !== 'string') {
            return fallback;
        }
        var idx = url.indexOf('admin.php');
        if (idx !== -1) {
            return url.substring(idx);
        }
        return fallback;
    }

    function archivesUrlFromButton(el) {
        var $btn = $(el);
        var url = $btn.attr('data-archives-url')
            || $btn.data('archivesUrl')
            || rmmigrateAdmin.backupsUrl;
        return adminHref(url);
    }

    function markStep(step) {
        return $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_setup_wizard_step',
            nonce: rmmigrateAdmin.nonce,
            step: step
        });
    }

    function completeAndGo(archivesUrl) {
        var target = adminHref(archivesUrl);
        if (wizardCompleteInFlight) {
            window.location.assign(target);
            return;
        }
        wizardCompleteInFlight = true;
        var redirected = false;
        function go(url) {
            if (redirected) {
                return;
            }
            redirected = true;
            window.location.assign(url || target);
        }
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_setup_wizard_complete',
            nonce: rmmigrateAdmin.nonce
        }).done(function (res) {
            var url = (res && res.data && res.data.redirect) ? adminHref(res.data.redirect) : target;
            go(url);
        }).fail(function () {
            go(target);
        });
        window.setTimeout(function () {
            go(target);
        }, 4000);
    }

    function postSubscribe(subscribeUrl, fields) {
        var controller = new AbortController();
        var timeoutId = window.setTimeout(function () {
            controller.abort();
        }, 10000);
        return fetch(subscribeUrl, {
            method: 'POST',
            mode: 'cors',
            credentials: 'omit',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(fields || {}),
            signal: controller.signal
        }).then(function (resp) {
            window.clearTimeout(timeoutId);
            if (!resp.ok) {
                throw new Error('subscribe_failed');
            }
            return resp.json();
        }).catch(function (err) {
            window.clearTimeout(timeoutId);
            throw err;
        });
    }

    function fallbackSubscribe() {
        return $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_setup_wizard_newsletter_fallback',
            nonce: rmmigrateAdmin.nonce
        });
    }

    function persistFinishStep() {
        markStep('finish');
    }

    function markOptInDone() {
        var $card = $('.mm-setup-optin');
        $card.addClass('is-done');
        $card.find('.mm-setup-card__actions').prop('hidden', true);
        $card.find('.mm-setup-card__thanks').prop('hidden', false);
        persistFinishStep();
    }

    function postTelemetryConsent(grant) {
        return $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_telemetry_consent',
            nonce: rmmigrateAdmin.nonce,
            grant: grant ? '1' : '0',
            source: 'setup_wizard_finish'
        });
    }

    function postNewsletterAllow() {
        return $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_setup_wizard_newsletter',
            nonce: rmmigrateAdmin.nonce,
            allow: '1'
        }).then(function (res) {
            if (!res || !res.success || !res.data) {
                return fallbackSubscribe();
            }
            var data = res.data;
            if (data.skipped) {
                return $.Deferred().resolve().promise();
            }
            return postSubscribe(data.subscribeUrl, data.fields).then(function () {
                return $.post(rmmigrateAdmin.ajaxUrl, {
                    action: 'rmmigrate_setup_wizard_newsletter_confirm',
                    nonce: rmmigrateAdmin.nonce
                });
            }).catch(function () {
                return fallbackSubscribe();
            });
        }).catch(function () {
            return fallbackSubscribe();
        });
    }

    function postNewsletterSkip() {
        return $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_setup_wizard_newsletter',
            nonce: rmmigrateAdmin.nonce,
            allow: '0'
        });
    }

    function resolveFinishOptIn(options) {
        options = options || {};
        var redirecting = !!options.redirecting;
        var $card = $('.mm-setup-optin');
        if ($card.hasClass('is-done') || $card.hasClass('is-submitting') || finishOptInInFlight) {
            return $.Deferred().resolve().promise();
        }
        finishOptInInFlight = true;
        var $btn = $card.find('.mm-setup-create-backup');
        $card.addClass('is-submitting');
        $btn.prop('disabled', true).attr('aria-busy', 'true');
        var emailWanted = $('.mm-setup-optin-email').is(':checked');
        var telemetryGrant = $('.mm-setup-optin-telemetry').is(':checked');
        var telemetryPromise = postTelemetryConsent(telemetryGrant);
        var newsletterPromise = emailWanted ? postNewsletterAllow() : postNewsletterSkip();
        return $.when(telemetryPromise, newsletterPromise).done(function () {
            if (redirecting) {
                persistFinishStep();
            } else {
                markOptInDone();
                $card.removeClass('is-submitting');
                $btn.prop('disabled', false).removeAttr('aria-busy');
            }
        }).fail(function () {
            if (!redirecting) {
                $card.removeClass('is-submitting');
                $btn.prop('disabled', false).removeAttr('aria-busy');
                rmmigrateAdminUI.toast(t('requestFailed', 'Request failed'), 'error');
            }
        }).always(function () {
            finishOptInInFlight = false;
        });
    }

    $(document).on('click', '.mm-setup-create-backup', function (e) {
        e.preventDefault();
        var target = archivesUrlFromButton(this);
        var $card = $('.mm-setup-optin');
        var $btn = $(this);
        if (!$card.hasClass('is-done') && !finishOptInInFlight) {
            resolveFinishOptIn({ redirecting: true }).always(function () {
                if (!$btn.prop('disabled')) {
                    $card.addClass('is-submitting');
                    $btn.prop('disabled', true).attr('aria-busy', 'true');
                }
                completeAndGo(target);
            });
            return;
        }
        if (!$btn.prop('disabled')) {
            $card.addClass('is-submitting');
            $btn.prop('disabled', true).attr('aria-busy', 'true');
        }
        completeAndGo(target);
    });

}(jQuery));
