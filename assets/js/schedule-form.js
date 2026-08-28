(function ($) {
    'use strict';

    var SCOPE_NETWORK = 'network';
    var SCOPE_SUBSITE = 'subsite';

    function toggleRowFields($row) {
        var interval = $row.find('.mm-schedule-interval').val() || 'weekly';
        $row.attr('data-interval', interval);
    }

    function toggleScopePanel($row) {
        var $scope = $row.find('.mm-schedule-scope');
        if (!$scope.length) {
            return;
        }
        var scope = $scope.val() || SCOPE_NETWORK;
        $row.attr('data-scope', scope);
        $row.find('.mm-schedule-scope-pane').each(function () {
            var $pane = $(this);
            var active = false;
            if (scope === SCOPE_SUBSITE) {
                active = $pane.hasClass('mm-schedule-scope-pane--subsite');
            } else {
                active = $pane.hasClass('mm-schedule-scope-pane--network');
            }
            $pane.toggleClass('mm-hidden', !active);
            $pane.find('input, select').prop('disabled', !active);
        });
    }

    function bindRow($row) {
        toggleRowFields($row);
        toggleScopePanel($row);
    }

    $(function () {
        $('#mm-schedules-body')
            .on('change', '.mm-schedule-interval', function () {
                toggleRowFields($(this).closest('.mm-schedule-row'));
            })
            .on('change', '.mm-schedule-scope', function () {
                toggleScopePanel($(this).closest('.mm-schedule-row'));
            });

        $('#mm-schedules-body .mm-schedule-row').each(function () {
            bindRow($(this));
        });
    });
}(jQuery));
