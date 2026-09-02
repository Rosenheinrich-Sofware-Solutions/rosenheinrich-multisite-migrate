(function ($) {
    'use strict';

    if (typeof rmmigrateReviewNudge === 'undefined') {
        return;
    }

    var nudgePostInFlight = false;

    function fadeOut($notice) {
        $notice.fadeOut(200, function () {
            $(this).remove();
        });
    }

    function post(action, feedback, done) {
        if (nudgePostInFlight) {
            return;
        }
        nudgePostInFlight = true;
        $.post(rmmigrateReviewNudge.ajaxUrl, {
            action: action,
            nonce: rmmigrateReviewNudge.nonce,
            feedback: feedback || ''
        }).done(function (res) {
            if (typeof done === 'function') {
                done(res);
            }
        }).fail(function (xhr) {
            if (window.rmmigrateAdminUI && rmmigrateAdminUI.reportTransportFail) {
                rmmigrateAdminUI.reportTransportFail(action, xhr, 'nudge');
            }
            if (typeof done === 'function') {
                done(null);
            }
        }).always(function () {
            nudgePostInFlight = false;
        });
    }

    $(document).on('click', '.mm-review-nudge-dismiss, .mm-notice-card__dismiss.mm-review-nudge-dismiss', function () {
        var $btn = $(this);
        if ($btn.data('mmBusy')) {
            return;
        }
        $btn.data('mmBusy', 1);
        var $notice = $btn.closest('.mm-review-nudge, .mm-notice-card');
        post(rmmigrateReviewNudge.dismissAction, '', function (res) {
            $btn.removeData('mmBusy');
            if (!res || !res.success) {
                return;
            }
            fadeOut($notice);
        });
    });

    $(document).on('click', '.mm-review-nudge-negative', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.data('mmBusy')) {
            return;
        }
        $btn.data('mmBusy', 1);
        var $notice = $btn.closest('.mm-review-nudge, .mm-notice-card');
        post(rmmigrateReviewNudge.feedbackAction, 'negative', function (res) {
            $btn.removeData('mmBusy');
            if (!res || !res.success) {
                return;
            }
            fadeOut($notice);
        });
    });

    $(document).on('click', '.mm-review-nudge-yes', function () {
        var $btn = $(this);
        if ($btn.data('mmBusy')) {
            return;
        }
        $btn.data('mmBusy', 1);
        post(rmmigrateReviewNudge.feedbackAction, 'reviewed', function () {
            $btn.removeData('mmBusy');
        });
    });
})(jQuery);
