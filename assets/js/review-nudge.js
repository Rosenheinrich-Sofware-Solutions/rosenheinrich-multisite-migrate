(function ($) {
    'use strict';

    if (typeof rmmigrateReviewNudge === 'undefined') {
        return;
    }

    function fadeOut($notice) {
        $notice.fadeOut(200, function () {
            $(this).remove();
        });
    }

    function post(action, feedback, done) {
        $.post(rmmigrateReviewNudge.ajaxUrl, {
            action: action,
            nonce: rmmigrateReviewNudge.nonce,
            feedback: feedback || ''
        }).done(function (res) {
            if (typeof done === 'function') {
                done(res);
            }
        }).fail(function () {
            if (typeof done === 'function') {
                done(null);
            }
        });
    }

    $(document).on('click', '.mm-review-nudge-dismiss, .mm-notice-card__dismiss.mm-review-nudge-dismiss', function () {
        var $notice = $(this).closest('.mm-review-nudge, .mm-notice-card');
        post(rmmigrateReviewNudge.dismissAction, '', function (res) {
            if (!res || !res.success) {
                return;
            }
            fadeOut($notice);
        });
    });

    $(document).on('click', '.mm-review-nudge-negative', function (e) {
        e.preventDefault();
        var $notice = $(this).closest('.mm-review-nudge, .mm-notice-card');
        post(rmmigrateReviewNudge.feedbackAction, 'negative', function (res) {
            if (!res || !res.success) {
                return;
            }
            fadeOut($notice);
        });
    });

    $(document).on('click', '.mm-review-nudge-yes', function () {
        post(rmmigrateReviewNudge.feedbackAction, 'reviewed');
    });
})(jQuery);
