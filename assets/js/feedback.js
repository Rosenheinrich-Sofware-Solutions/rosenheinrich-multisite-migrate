(function ($) {
    'use strict';

    var cfg = (window.rmmigrateAdmin && window.rmmigrateAdmin.feedback) || {};
    var i18n = cfg.i18n || {};
    var releaseFeedbackTrap = null;
    var state = {
        open: false,
        sentiment: '',
        context: 'help',
        jobType: '',
        errorCode: '',
        errorMessage: '',
        onDone: null,
        doneCalled: false,
        reviewMode: false
    };

    function t(key, fallback) {
        return i18n[key] || fallback || key;
    }

    function canUse() {
        return !!cfg.enabled && $('#mm-feedback-dialog').length > 0;
    }

    function setTitle(text) {
        $('#mm-feedback-title').text(text);
    }

    function isSuccessContext(context) {
        return /_success$/.test(context || '');
    }

    function setReviewMode(on) {
        state.reviewMode = !!on;
        if (on) {
            $('.mm-feedback-review-cta').removeClass('mm-hidden');
            $('.mm-feedback-sentiments-wrap').addClass('mm-hidden');
            $('.mm-feedback-review-cta-btn').removeClass('mm-hidden');
            $('.mm-feedback-submit').addClass('mm-hidden');
            var url = cfg.reviewUrl || '#';
            $('#mm-feedback-review-link')
                .attr('href', url)
                .text(t('reviewCta', 'Leave a review'));
            $('.mm-feedback-review-body').text(
                t('reviewBody', 'A short WordPress.org review helps others find Multisite Migrate too.')
            );
        } else {
            $('.mm-feedback-review-cta').addClass('mm-hidden');
            $('.mm-feedback-sentiments-wrap').removeClass('mm-hidden');
            $('.mm-feedback-review-cta-btn').addClass('mm-hidden');
            $('.mm-feedback-submit').removeClass('mm-hidden');
        }
    }

    function resetForm() {
        state.sentiment = '';
        state.errorMessage = '';
        $('.mm-feedback-sentiment').attr('aria-pressed', 'false').removeClass('is-selected');
        $('#mm-feedback-message').val('');
        $('#mm-feedback-bug').prop('checked', false);
        $('.mm-feedback-details').addClass('mm-hidden');
        $('.mm-feedback-attached-error').addClass('mm-hidden').text('');
        $('.mm-feedback-error').addClass('mm-hidden').text('');
        $('.mm-feedback-submit').prop('disabled', true).text(t('submit', 'Send feedback'));
        $('.mm-feedback-consent').text(cfg.consentNote || '');
    }

    function showAttachedError(message) {
        state.errorMessage = (message || '').toString().trim();
        var $note = $('.mm-feedback-attached-error');
        if (!state.errorMessage) {
            $note.addClass('mm-hidden').text('');
            return;
        }
        $note
            .removeClass('mm-hidden')
            .text(t('attachedError', 'Including last error:') + ' ' + state.errorMessage);
    }

    function updateDetailsVisibility() {
        if (!state.sentiment) {
            $('.mm-feedback-details').addClass('mm-hidden');
            $('.mm-feedback-submit').prop('disabled', true);
            return;
        }
        $('.mm-feedback-details').removeClass('mm-hidden');
        $('.mm-feedback-submit').prop('disabled', false);
    }

    function closeDialog(dismissRemote) {
        if (!state.open && !state.onDone) {
            return;
        }
        if (typeof releaseFeedbackTrap === 'function') {
            releaseFeedbackTrap();
            releaseFeedbackTrap = null;
        }
        var $dialog = $('#mm-feedback-dialog');
        $dialog.addClass('mm-hidden').attr('hidden', 'hidden');
        $('body').removeClass('mm-feedback-open');
        state.open = false;
        if (dismissRemote) {
            if (!state.dismissInFlight) {
                state.dismissInFlight = true;
                $.post(rmmigrateAdmin.ajaxUrl, {
                    action: cfg.dismissAction || 'rmmigrate_feedback_dismiss',
                    nonce: rmmigrateAdmin.nonce
                }).fail(function (xhr) {
                    if (rmmigrateAdminUI.reportTransportFail) {
                        rmmigrateAdminUI.reportTransportFail(cfg.dismissAction || 'rmmigrate_feedback_dismiss', xhr, 'feedback');
                    }
                }).always(function () {
                    state.dismissInFlight = false;
                });
            }
        }
        var done = state.onDone;
        state.onDone = null;
        if (!state.doneCalled && typeof done === 'function') {
            state.doneCalled = true;
            done();
        }
    }

    function openDialog(opts) {
        if (!canUse()) {
            if (opts && typeof opts.onDone === 'function') {
                opts.onDone();
            }
            return;
        }
        opts = opts || {};
        state.context = opts.context || 'help';
        state.jobType = opts.jobType || '';
        state.errorCode = opts.errorCode || '';
        state.onDone = typeof opts.onDone === 'function' ? opts.onDone : null;
        state.doneCalled = false;
        resetForm();
        showAttachedError(opts.errorMessage || '');

        if (isSuccessContext(state.context) && !opts.presetBug && !opts.presetSentiment) {
            setReviewMode(true);
            setTitle(t('titleReview', 'Enjoying Multisite Migrate?'));
        } else {
            setReviewMode(false);
            if (opts.presetBug) {
                $('#mm-feedback-bug').prop('checked', true);
                state.sentiment = 'negative';
                $('.mm-feedback-sentiment[data-sentiment="negative"]').attr('aria-pressed', 'true').addClass('is-selected');
                setTitle(t('titleBug', 'Report a problem'));
                updateDetailsVisibility();
            } else if (opts.presetSentiment && /^(positive|neutral|negative)$/.test(opts.presetSentiment)) {
                state.sentiment = opts.presetSentiment;
                $('.mm-feedback-sentiment[data-sentiment="' + opts.presetSentiment + '"]')
                    .attr('aria-pressed', 'true')
                    .addClass('is-selected');
                setTitle(t('titleHelp', 'Share feedback'));
                updateDetailsVisibility();
            } else if (state.context === 'help' || state.context === 'review_nudge') {
                setTitle(t('titleHelp', 'Share feedback'));
            } else {
                setTitle(t('title', 'How did that go?'));
            }
        }

        // Shared 14-day lock for auto prompts once the dialog appears.
        if (!opts.manual) {
            cfg.shouldPrompt = false;
            if (!state.shownInFlight) {
                state.shownInFlight = true;
                $.post(rmmigrateAdmin.ajaxUrl, {
                    action: cfg.shownAction || 'rmmigrate_feedback_shown',
                    nonce: rmmigrateAdmin.nonce
                }).fail(function (xhr) {
                    if (rmmigrateAdminUI.reportTransportFail) {
                        rmmigrateAdminUI.reportTransportFail(cfg.shownAction || 'rmmigrate_feedback_shown', xhr, 'feedback');
                    }
                }).always(function () {
                    state.shownInFlight = false;
                });
            }
        }

        var $dialog = $('#mm-feedback-dialog');
        $dialog.removeClass('mm-hidden').removeAttr('hidden');
        $('body').addClass('mm-feedback-open');
        state.open = true;
        if (typeof rmmigrateAdminUI !== 'undefined' && rmmigrateAdminUI.trapFocus) {
            if (typeof releaseFeedbackTrap === 'function') {
                releaseFeedbackTrap();
            }
            releaseFeedbackTrap = rmmigrateAdminUI.trapFocus($dialog.find('.mm-feedback-modal'));
        }
        if (state.reviewMode) {
            $dialog.find('#mm-feedback-review-link').trigger('focus');
        } else {
            $dialog.find('.mm-feedback-sentiment').first().trigger('focus');
        }
    }

    /**
     * Auto-prompt after backup success when cooldown allows.
     */
    function maybePrompt(opts) {
        opts = opts || {};
        var hold = typeof opts.holdMs === 'number' ? opts.holdMs : 2000;
        var runDone = function () {
            if (typeof opts.onDone === 'function') {
                opts.onDone();
            }
        };
        if (!canUse()) {
            window.setTimeout(runDone, Math.max(0, hold));
            return;
        }
        if (!cfg.shouldPrompt) {
            window.setTimeout(runDone, Math.max(0, hold));
            return;
        }
        openDialog(opts);
        // Stay open until the user dismisses or submits — no auto-close/reload.
    }

    var feedbackSubmitInFlight = false;

    function submitFeedback() {
        if (feedbackSubmitInFlight) {
            return;
        }
        if (!state.sentiment) {
            return;
        }
        var message = ($('#mm-feedback-message').val() || '').toString();
        var type = $('#mm-feedback-bug').is(':checked') ? 'bug' : 'general';
        if ((state.sentiment === 'neutral' || state.sentiment === 'negative' || type === 'bug') && !message.trim()) {
            $('.mm-feedback-error').removeClass('mm-hidden').text(t('messageRequired', 'Please add a short note so we can improve.'));
            $('#mm-feedback-message').trigger('focus');
            return;
        }
        $('.mm-feedback-error').addClass('mm-hidden').text('');
        feedbackSubmitInFlight = true;
        var $btn = $('.mm-feedback-submit').prop('disabled', true).text(t('sending', 'Sending…'));
        $.post(rmmigrateAdmin.ajaxUrl, {
            action: cfg.submitAction || 'rmmigrate_feedback_submit',
            nonce: rmmigrateAdmin.nonce,
            sentiment: state.sentiment,
            type: type,
            message: message,
            context: state.context,
            job_type: state.jobType,
            error_code: state.errorCode,
            error_message: state.errorMessage
        }).done(function (res) {
            if (res && res.success) {
                cfg.shouldPrompt = false;
                if (typeof rmmigrateAdminUI !== 'undefined' && rmmigrateAdminUI.toast) {
                    rmmigrateAdminUI.toast(t('thanks', 'Thanks for your feedback!'), 'success');
                }
                closeDialog(false);
                return;
            }
            var msg = (res && res.data && res.data.message) ? res.data.message : t('error', 'Could not send feedback. Try again later.');
            $('.mm-feedback-error').removeClass('mm-hidden').text(msg);
            $btn.prop('disabled', false).text(t('submit', 'Send feedback'));
        }).fail(function (xhr) {
            if (rmmigrateAdminUI.reportTransportFail) {
                rmmigrateAdminUI.reportTransportFail(cfg.submitAction || 'rmmigrate_feedback_submit', xhr, 'feedback');
            }
            $('.mm-feedback-error').removeClass('mm-hidden').text(t('error', 'Could not send feedback. Try again later.'));
            $btn.prop('disabled', false).text(t('submit', 'Send feedback'));
        }).always(function () {
            feedbackSubmitInFlight = false;
        });
    }

    $(document).on('click', '.mm-feedback-sentiment', function () {
        var sentiment = $(this).data('sentiment');
        state.sentiment = sentiment || '';
        $('.mm-feedback-sentiment').attr('aria-pressed', 'false').removeClass('is-selected');
        $(this).attr('aria-pressed', 'true').addClass('is-selected');
        updateDetailsVisibility();
        if (state.sentiment !== 'positive') {
            $('#mm-feedback-message').trigger('focus');
        }
    });

    $(document).on('change', '#mm-feedback-bug', function () {
        updateDetailsVisibility();
    });

    $(document).on('click', '.mm-feedback-submit', function () {
        submitFeedback();
    });

    $(document).on('click', '#mm-feedback-review-link', function (e) {
        // Browser target=_blank + delayed close: immediate reload was aborting the new tab.
        var url = (cfg.reviewUrl || $(this).attr('href') || '').toString();
        if (!url || url === '#') {
            e.preventDefault();
            closeDialog(false);
            return;
        }
        $(this).attr({ href: url, target: '_blank', rel: 'noopener noreferrer' });
        var win = window.open(url, '_blank', 'noopener,noreferrer');
        if (!win) {
            // Popup blocked — keep default <a target=_blank> navigation.
        } else {
            e.preventDefault();
        }
        var action = cfg.reviewNudgeFeedbackAction;
        if (action && typeof rmmigrateAdmin !== 'undefined' && !state.reviewTrackInFlight) {
            state.reviewTrackInFlight = true;
            $.post(rmmigrateAdmin.ajaxUrl, {
                action: action,
                nonce: rmmigrateAdmin.nonce,
                feedback: 'reviewed'
            }).fail(function (xhr) {
                if (rmmigrateAdminUI.reportTransportFail) {
                    rmmigrateAdminUI.reportTransportFail(action, xhr, 'feedback');
                }
            }).always(function () {
                state.reviewTrackInFlight = false;
            });
        }
        cfg.shouldPrompt = false;
        window.setTimeout(function () {
            closeDialog(false);
        }, 100);
    });

    $(document).on('click', '.mm-feedback-later, .mm-feedback-close', function () {
        closeDialog(true);
    });

    $(document).on('click', '.mm-feedback-overlay', function (e) {
        if (e.target === this) {
            closeDialog(true);
        }
    });

    $(document).on('keydown', function (e) {
        if (!state.open) {
            return;
        }
        if (e.key === 'Escape') {
            closeDialog(true);
        }
    });

    $(document).on('click', 'a[href="#mm-feedback"], a.mm-feedback-open-help, .mm-feedback-open-help', function (e) {
        e.preventDefault();
        openDialog({ context: 'help', manual: true });
    });

    $(document).on('click', '.mm-feedback-open-error', function (e) {
        e.preventDefault();
        var $el = $(this);
        openDialog({
            context: $el.data('context') || 'other',
            jobType: $el.data('job-type') || '',
            errorCode: $el.data('error-code') || '',
            errorMessage: $el.attr('data-error-message') || $el.data('errorMessage') || '',
            presetBug: true,
            manual: true
        });
    });

    window.rmmigrateFeedback = {
        maybePrompt: maybePrompt,
        open: openDialog,
        close: function () { closeDialog(false); }
    };
}(jQuery));
