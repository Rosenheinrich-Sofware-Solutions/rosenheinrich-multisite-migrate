(function ($) {
    'use strict';

    function scrollBehavior() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth';
    }

    function setHealthPanelOpen(open) {
        var $panel = $('#mm-health-details');
        if (!$panel.length) {
            return;
        }

        $panel.toggleClass('mm-hidden', !open);
        $panel.prop('hidden', !open);
        $('.mm-health-toggle').attr('aria-expanded', open ? 'true' : 'false');

        if (open) {
            $panel[0].scrollIntoView({ block: 'nearest', behavior: scrollBehavior() });
        }
    }

    function toggleHealthDetails(e) {
        var $panel = $('#mm-health-details');
        if (!$panel.length) {
            return;
        }

        e.preventDefault();
        setHealthPanelOpen($panel.hasClass('mm-hidden') || $panel.prop('hidden'));
    }

    $(document).on('click', '.mm-stat-card--clickable, .mm-health-toggle', toggleHealthDetails);

    $(document).on('click', '.mm-error-expand', function (e) {
        e.preventDefault();
        var jobId = $(this).data('job-id');
        $('#mm-job-error-' + jobId).toggleClass('mm-hidden');
    });

    $(function () {
        var params = new URLSearchParams(window.location.search);
        var jobId = params.get('job_id');
        if (jobId) {
            var $detail = $('#mm-job-error-' + jobId);
            if ($detail.length) {
                $detail.removeClass('mm-hidden');
            }
            // Progress for active backups is in the top banner — keep the viewport at the top.
            if (!$('#mm-active-job-banner').length) {
                var $row = $('.mm-job-row-highlight');
                if ($row.length) {
                    $row[0].scrollIntoView({ block: 'center' });
                }
            }
        }
        if (params.get('log')) {
            var $logs = $('.mm-log-viewer-panel');
            if ($logs.length) {
                $logs[0].scrollIntoView({ block: 'start', behavior: scrollBehavior() });
            }
        }
    });

    function cleanupEmptyBannerWrappers() {
        var $wrap = $('.mm-admin-banners');
        if ($wrap.length && !$wrap.children().length) {
            $wrap.remove();
        }
        $('.mm-below-header').filter(function () {
            return !$(this).children().length;
        }).remove();
    }

    function fadeOutBannerNotice($notice) {
        var $target = ($notice && $notice.length) ? $notice : $();
        if (!$target.length) {
            return;
        }
        $target.fadeOut(200, function () {
            $(this).remove();
            cleanupEmptyBannerWrappers();
        });
    }

    $(document).on('click', '.mm-dismiss-last-error', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (typeof rmmigrateAdmin === 'undefined') {
            return;
        }
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        var $notice = $btn.closest('.mm-last-error-notice, .mm-notice-card');
        $btn.prop('disabled', true);
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_dismiss_last_error',
            nonce: rmmigrateAdmin.nonce
        }).done(function (res) {
            if (res && res.success) {
                fadeOutBannerNotice($notice);
                return;
            }
            $btn.prop('disabled', false);
            if (window.rmmigrateAdminUI) {
                var message = res && res.data && res.data.message
                    ? res.data.message
                    : rmmigrateAdminUI.i18n('requestFailed', 'Request failed');
                rmmigrateAdminUI.toast(message, 'error');
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false);
            if (window.rmmigrateAdminUI) {
                rmmigrateAdminUI.toast(rmmigrateAdminUI.ajaxErrorMessage(xhr), 'error');
            }
        });
    });

    $(document).on('click', '.mm-dismiss-verify-restore', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        $btn.prop('disabled', true);
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete('mm_verify');
            url.searchParams.delete('job_id');
            window.location.href = url.pathname + url.search + url.hash;
        } catch (err) {
            window.location.reload();
        }
    });

    function reviewNudgeFadeOut($notice) {
        $notice.fadeOut(200, function () {
            $(this).remove();
        });
    }

    function reviewNudgePost(action, feedback, done) {
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: action,
            nonce: rmmigrateAdmin.nonce,
            feedback: feedback || ''
        }).always(function (res) {
            if (typeof done === 'function') {
                done(res);
            }
        });
    }

    $(document).on('click', '.mm-review-nudge-dismiss, .mm-notice-card__dismiss.mm-review-nudge-dismiss', function () {
        var $notice = $(this).closest('.mm-review-nudge, .mm-notice-card');
        reviewNudgePost('rmmigrate_review_nudge_dismiss', '', function (res) {
            if (res.success) {
                reviewNudgeFadeOut($notice);
            }
        });
    });

    $(document).on('click', '.mm-review-nudge-negative', function (e) {
        e.preventDefault();
        var $notice = $(this).closest('.mm-review-nudge, .mm-notice-card');
        reviewNudgePost('rmmigrate_review_nudge_feedback', 'negative', function (res) {
            if (!res.success) {
                return;
            }
            reviewNudgeFadeOut($notice);
            if (window.rmmigrateFeedback && typeof window.rmmigrateFeedback.open === 'function') {
                window.rmmigrateFeedback.open({
                    context: 'review_nudge',
                    manual: true,
                    presetSentiment: 'negative'
                });
            }
        });
    });

    $(document).on('click', '.mm-review-nudge-yes', function () {
        reviewNudgePost('rmmigrate_review_nudge_feedback', 'reviewed');
    });

    $(document).on('click', '.mm-pro-hint__dismiss', function () {
        var $hint = $(this).closest('.mm-pro-hint');
        var slug = $(this).data('slug');
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: 'rmmigrate_pro_hint_dismiss',
            nonce: rmmigrateAdmin.nonce,
            slug: slug
        }).done(function (res) {
            if (res && res.success) {
                $hint.fadeOut(200, function () {
                    $(this).remove();
                });
            }
        });
    });

    function mmAdminAjaxConfig() {
        if (typeof rmmigrateAdmin !== 'undefined') {
            return rmmigrateAdmin;
        }
        if (typeof rmmigrateAdminUx !== 'undefined') {
            return rmmigrateAdminUx;
        }
        return null;
    }

    function setupBannerDismissConfig($btn) {
        var cfg = mmAdminAjaxConfig() || {};
        return {
            ajaxUrl: $btn.attr('data-ajax-url') || cfg.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : ''),
            nonce: $btn.attr('data-nonce') || cfg.nonce || '',
            action: $btn.attr('data-dismiss-action') || cfg.setupDismissAction || ''
        };
    }

    function fadeOutSetupBanner($banner) {
        fadeOutBannerNotice(($banner && $banner.length) ? $banner : $('.mm-setup-banner'));
    }

    $(document).on('click', '.mm-dismiss-setup-banner', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        var dismissCfg = setupBannerDismissConfig($btn);
        if (!dismissCfg.ajaxUrl || !dismissCfg.nonce || !dismissCfg.action) {
            if (window.rmmigrateAdminUI) {
                rmmigrateAdminUI.toast(rmmigrateAdminUI.i18n('requestFailed', 'Request failed'), 'error');
            }
            return;
        }
        var $banner = $btn.closest('.mm-setup-banner');
        $btn.prop('disabled', true);
        $.post(dismissCfg.ajaxUrl, {
            action: dismissCfg.action,
            nonce: dismissCfg.nonce
        }, null, 'json').done(function (res) {
            if (res && res.success) {
                fadeOutSetupBanner($banner);
                return;
            }
            $btn.prop('disabled', false);
            if (window.rmmigrateAdminUI) {
                var message = res && res.data && res.data.message
                    ? res.data.message
                    : rmmigrateAdminUI.i18n('requestFailed', 'Request failed');
                rmmigrateAdminUI.toast(message, 'error');
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false);
            if (window.rmmigrateAdminUI) {
                rmmigrateAdminUI.toast(rmmigrateAdminUI.ajaxErrorMessage(xhr), 'error');
            }
        });
    });

    $(document).on('click', '.mm-dropdown-toggle', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $dropdown = $(this).closest('.mm-dropdown');
        $('.mm-dropdown').not($dropdown).removeClass('mm-dropdown-open');
        $dropdown.toggleClass('mm-dropdown-open');
    });

    $(document).on('click', function () {
        $('.mm-dropdown').removeClass('mm-dropdown-open');
    });
})(jQuery);
