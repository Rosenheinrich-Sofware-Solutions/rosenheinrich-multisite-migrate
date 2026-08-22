(function ($) {
    'use strict';

    var telemetryConsentInFlight = false;

    function t(key, fallback) {
        return rmmigrateAdminUI.i18n(key, fallback);
    }

    function setStatus(message, state) {
        var $status = $('#mm-telemetry-consent-status');
        if (!$status.length) {
            return;
        }
        $status.removeClass('is-ok is-error is-busy');
        if (!message) {
            $status.prop('hidden', true).text('');
            return;
        }
        $status.prop('hidden', false).text(message);
        if (state === 'ok') {
            $status.addClass('is-ok');
        } else if (state === 'error') {
            $status.addClass('is-error');
        } else {
            $status.addClass('is-busy');
        }
    }

    $(document).on('change', '.mm-settings-telemetry-optin', function () {
        var $input = $(this);
        if ($input.prop('disabled') || telemetryConsentInFlight) {
            return;
        }
        telemetryConsentInFlight = true;
        var grant = $input.is(':checked');
        $input.prop('disabled', true);
        setStatus(t('saving', 'Saving…'), 'busy');
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_telemetry_consent',
            nonce: rmmigrateAdmin.nonce,
            grant: grant ? '1' : '0',
            source: 'settings'
        }).done(function (res) {
            if (!res || !res.success) {
                $input.prop('checked', !grant);
                setStatus(t('requestFailed', 'Request failed'), 'error');
                return;
            }
            setStatus(
                grant
                    ? t('telemetryEnabled', 'Telemetry enabled.')
                    : t('telemetryDisabled', 'Telemetry disabled.'),
                'ok'
            );
        }).fail(function () {
            $input.prop('checked', !grant);
            setStatus(t('requestFailed', 'Request failed'), 'error');
        }).always(function () {
            $input.prop('disabled', false);
            telemetryConsentInFlight = false;
        });
    });
}(jQuery));
