/**
 * @file
 * JavaScript behaviors for Content Radar module.
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Behavior for Content Radar functionality.
   */
  Drupal.behaviors.contentRadar = {
    attach: function (context, settings) {
      // Select all functionality
      $('#select-all-results', context).once('content-radar-select-all').on('change', function () {
        var checked = $(this).prop('checked');
        $('.result-item-checkbox', context).prop('checked', checked);
      });

      // Individual checkbox change
      $('.result-item-checkbox', context).once('content-radar-select-item').on('change', function () {
        // Update select all checkbox
        var totalCheckboxes = $('.result-item-checkbox', context).length;
        var checkedCheckboxes = $('.result-item-checkbox:checked', context).length;
        $('#select-all-results', context).prop('checked', totalCheckboxes === checkedCheckboxes);
      });
    }
  };

})(jQuery, Drupal);