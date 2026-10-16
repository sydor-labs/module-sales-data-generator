/**
 * Weighted dynamic rows: shows the share of each row and disables options already chosen in another row.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $root = $(element);

        /**
         * @param {jQuery} $row
         * @returns {number|null}
         */
        function getWeight($row) {
            var weight = parseFloat($row.find('input[name$="[weight]"]').val());

            return isNaN(weight) || weight < 0 ? null : weight;
        }

        function refresh() {
            var $rows = $root.find('tbody tr'),
                $selects = $rows.find('select[data-role="code"]'),
                total = 0;

            $rows.each(function () {
                total += getWeight($(this)) || 0;
            });
            $rows.each(function () {
                var $row = $(this),
                    weight = getWeight($row);

                $row.find('[data-role="share"]').text(
                    weight === null || total <= 0 ? '—' : (weight / total * 100).toFixed(1) + '%'
                );
            });

            $selects.each(function () {
                var select = this,
                    taken = {};

                $selects.not(select).each(function () {
                    taken[$(this).val()] = true;
                });
                $(select).find('option').each(function () {
                    this.disabled = !this.selected && taken[this.value] === true;
                });
            });
        }

        $root.on('change input', 'select, input', refresh);
        // Rows are added and deleted by the core dynamic rows script.
        new MutationObserver(refresh).observe($root.find('tbody')[0], {childList: true});
        refresh();
    };
});
