(function ($) {
    'use strict';

    function dismissMultisiteUpgradeNotice($notice) {
        if (typeof rmmigrateAdminGlobalNotice === 'undefined') {
            return;
        }
        if ($notice.data('mmDismissBusy')) {
            return;
        }
        $notice.data('mmDismissBusy', 1);
        $.post(rmmigrateAdminGlobalNotice.ajaxUrl, {
            action: 'rmmigrate_dismiss_multisite_upgrade_notice',
            nonce: rmmigrateAdminGlobalNotice.nonce
        }).fail(function (xhr) {
            if (window.rmmigrateAdminUI && rmmigrateAdminUI.reportTransportFail) {
                rmmigrateAdminUI.reportTransportFail('rmmigrate_dismiss_multisite_upgrade_notice', xhr, 'dismiss');
            }
        }).always(function () {
            $notice.removeData('mmDismissBusy');
        });
        $notice.fadeOut(200, function () {
            $(this).remove();
            var $banners = $('.mm-admin-banners');
            if ($banners.length && $banners.children().length === 0) {
                $banners.remove();
            }
        });
    }

    $(document).on('click', '.mm-global-multisite-notice .notice-dismiss, .mm-global-multisite-notice__dismiss', function () {
        dismissMultisiteUpgradeNotice($(this).closest('.mm-global-multisite-notice'));
    });

    function relocateAdminNotices() {
        var wrap = document.querySelector('.multisite-migrate-wrap.mm-app');
        if (!wrap || !wrap.parentNode) {
            return;
        }
        var banners = wrap.querySelector('.mm-admin-banners');
        if (!banners) {
            return;
        }

        var node = wrap.previousElementSibling;
        while (node && node.matches && node.matches('.notice, .updated, .error, .mm-global-multisite-notice')) {
            var previous = node.previousElementSibling;
            banners.insertBefore(node, banners.firstChild);
            node = previous;
        }
    }

    $(relocateAdminNotices);
})(jQuery);
