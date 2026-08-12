(function ($) {
    'use strict';

    var currentStep = 1;
    var totalSteps = 4;
    var skipMigrationStep = false;
    var subsiteRestoreMode = $('#mm-restore-dialog').data('mmSubsiteMode') === 1
        || $('#mm-restore-dialog').attr('data-mm-subsite-mode') === '1';

    function applySubsiteRestoreMode() {
        if (!subsiteRestoreMode) {
            return;
        }
        skipMigrationStep = true;
        $('.multisite-migrate-wizard-card[data-restore-type="migration"]').addClass('mm-hidden').prop('disabled', true);
        $('.multisite-migrate-wizard-card[data-restore-type="same_server"]').addClass('is-selected');
        $('input[name="mm_restore_type"][value="same_server"]').prop('checked', true);
        $('#mm-migration-fields').addClass('mm-hidden');
        $('#mm-topology-options, #mm-topology-subsite-on-network, #mm-topology-network-to-subsite').addClass('mm-hidden');
        $('.mm-restore-step[data-restore-step="2"]').addClass('mm-hidden');
        $('.mm-restore-wizard-steps li').eq(1).addClass('mm-hidden');
    }

    function getVisibleSteps() {
        return skipMigrationStep ? [1, 3, 4] : [1, 2, 3, 4];
    }

    /** Visible steps show 1..3 (same server) or 1..4 (migration), matching backup create UI. */
    function renumberRestoreSteps() {
        var display = 1;
        $('.mm-restore-wizard-steps li').each(function () {
            var $li = $(this);
            if ($li.hasClass('mm-hidden')) {
                return;
            }
            $li.find('.mm-step-num').text(String(display));
            display += 1;
        });
    }

    function showStep(n) {
        currentStep = n;
        $('.mm-restore-step').removeClass('is-active');
        $('.mm-restore-step[data-restore-step="' + n + '"]').addClass('is-active');
        $('.mm-restore-wizard-steps li').addClass('mm-hidden').removeClass('is-active is-done').removeAttr('aria-current');
        var visible = getVisibleSteps();
        visible.forEach(function (stepNum) {
            var $li = $('.mm-restore-wizard-steps li').eq(stepNum - 1);
            $li.removeClass('mm-hidden');
            if (stepNum === n) {
                $li.addClass('is-active').attr('aria-current', 'step');
            } else if (visible.indexOf(stepNum) < visible.indexOf(n)) {
                $li.addClass('is-done');
            }
        });
        renumberRestoreSteps();
        $('#mm-restore-back').toggleClass('mm-hidden', visible.indexOf(n) <= 0);
        $('#mm-restore-next').toggleClass('mm-hidden', n === visible[visible.length - 1]);
        $('#mm-restore-start').toggleClass('mm-hidden', n !== visible[visible.length - 1]);
        if (window.rmmigrateAdminUI && typeof rmmigrateAdminUI.refreshTrap === 'function') {
            rmmigrateAdminUI.refreshTrap(rmmigrateAdminUI._overlayRelease);
        }
    }

    function getRestoreType() {
        return $('.multisite-migrate-wizard-card.is-selected[data-restore-type]').data('restore-type') || 'same_server';
    }

    function syncRestoreTypeRadios() {
        var type = getRestoreType();
        skipMigrationStep = type !== 'migration';
        $('input[name="mm_restore_type"][value="' + type + '"]').prop('checked', true);
        $('.mm-restore-step[data-restore-step="2"]').toggleClass('mm-hidden', skipMigrationStep);
        $('.mm-restore-wizard-steps li').eq(1).toggleClass('mm-hidden', skipMigrationStep);
        if (type === 'migration') {
            $('#mm-migration-fields').removeClass('mm-hidden');
            applyMigrationDefaults();
        } else {
            $('#mm-migration-fields').addClass('mm-hidden');
        }
        renumberRestoreSteps();
    }

    function nextStep(n) {
        var visible = getVisibleSteps();
        var idx = visible.indexOf(n);
        return idx >= 0 && idx < visible.length - 1 ? visible[idx + 1] : n;
    }

    function prevStep(n) {
        var visible = getVisibleSteps();
        var idx = visible.indexOf(n);
        return idx > 0 ? visible[idx - 1] : n;
    }

    function isValidUrl(url) {
        if (!url) {
            return false;
        }
        try {
            var parsed = new URL(url);
            return parsed.protocol === 'http:' || parsed.protocol === 'https:';
        } catch (e) {
            return false;
        }
    }

    function currentSiteUrl() {
        if (rmmigrateAdmin.siteUrl) {
            return rmmigrateAdmin.siteUrl;
        }
        return window.location.origin;
    }

    function applyMigrationDefaults() {
        var base = currentSiteUrl();
        if (!$('#mm-new-site-url').val()) {
            $('#mm-new-site-url').val(base);
        }
        if ($('#mm-home-same-as-site').is(':checked')) {
            $('#mm-new-home-url').val($('#mm-new-site-url').val() || base);
        }
    }

    function validateMigrationStep() {
        if (skipMigrationStep) {
            return true;
        }
        var newSite = $('#mm-new-site-url').val();
        if (!isValidUrl(newSite)) {
            rmmigrateAdminUI.toast(rmmigrateAdminUI.i18n('enterNewUrl', 'Enter a valid new site URL (https://…).'), 'error');
            return false;
        }
        if (!$('#mm-home-same-as-site').is(':checked')) {
            var newHome = $('#mm-new-home-url').val();
            if (!isValidUrl(newHome)) {
                rmmigrateAdminUI.toast(rmmigrateAdminUI.i18n('enterNewHomeUrl', 'Enter a valid new home URL or check “Same as site URL”.'), 'error');
                return false;
            }
        }
        return true;
    }

    $('#mm-new-site-url').on('input', function () {
        if ($('#mm-home-same-as-site').is(':checked')) {
            $('#mm-new-home-url').val($(this).val());
        }
    });

    $('#mm-home-same-as-site').on('change', function () {
        if ($(this).is(':checked')) {
            $('#mm-new-home-url').val($('#mm-new-site-url').val()).prop('readonly', true);
        } else {
            $('#mm-new-home-url').prop('readonly', false);
        }
    });

    function buildReviewSummary() {
        var type = getRestoreType();
        var mode = $('input[name="mm_restore_mode"]:checked').val() || 'both';
        var snapshot = $('#mm-safety-snapshot').is(':checked');
        var lines = [
            'Type: ' + (type === 'migration' ? 'Migration' : 'Same server'),
            'Mode: ' + mode,
            'Safety snapshot: ' + (snapshot ? 'Yes' : 'No')
        ];
        if (type === 'migration') {
            lines.push('New site URL: ' + ($('#mm-new-site-url').val() || '(not set)'));
        }
        $('#mm-restore-review-summary').html(lines.map(function (l) {
            return '<dt>' + l.split(':')[0] + '</dt><dd>' + l.split(':').slice(1).join(':').trim() + '</dd>';
        }).join(''));
    }

    window.rmmigrateAdminRestoreWizard = {
        reset: function (presetMigration) {
            currentStep = 1;
            skipMigrationStep = subsiteRestoreMode || !presetMigration;
            $('.multisite-migrate-wizard-card[data-restore-type="same_server"]').toggleClass('is-selected', subsiteRestoreMode || !presetMigration);
            $('.multisite-migrate-wizard-card[data-restore-type="migration"]').toggleClass('is-selected', !subsiteRestoreMode && !!presetMigration);
            syncRestoreTypeRadios();
            applySubsiteRestoreMode();
            if (presetMigration) {
                applyMigrationDefaults();
            }
            showStep(presetMigration ? 2 : 1);
            $('#mm-restore-confirm').prop('checked', false);
        },
        showStep: showStep
    };

    $(document).on('click', '.multisite-migrate-wizard-card[data-restore-type]', function () {
        if (subsiteRestoreMode) {
            return;
        }
        $('.multisite-migrate-wizard-card[data-restore-type]').removeClass('is-selected');
        $(this).addClass('is-selected');
        var type = $(this).data('restore-type');
        $('input[name="mm_restore_type"][value="' + type + '"]').prop('checked', true);
        syncRestoreTypeRadios();
    });

    $('#mm-restore-next').on('click', function () {
        if (currentStep === 2 && !validateMigrationStep()) {
            return;
        }
        if (currentStep === 3) {
            buildReviewSummary();
        }
        showStep(nextStep(currentStep));
    });

    $('#mm-restore-back').on('click', function () {
        showStep(prevStep(currentStep));
    });

    applySubsiteRestoreMode();
    syncRestoreTypeRadios();
    showStep(1);
})(jQuery);
