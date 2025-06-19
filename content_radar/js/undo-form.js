/**
 * @file
 * JavaScript for the undo form select all functionality.
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Attaches the select all functionality to the undo form.
   */
  Drupal.behaviors.contentRadarUndoForm = {
    attach: function (context, settings) {
      // Select all checkbox functionality.
      $('.select-all-nodes', context).once('content-radar-select-all').on('change', function () {
        var isChecked = $(this).is(':checked');
        $('.node-checkboxes input[type="checkbox"]', context).prop('checked', isChecked);
      });

      // Update select all checkbox when individual checkboxes change.
      $('.node-checkboxes input[type="checkbox"]', context).once('content-radar-node-check').on('change', function () {
        var totalCheckboxes = $('.node-checkboxes input[type="checkbox"]', context).length;
        var checkedCheckboxes = $('.node-checkboxes input[type="checkbox"]:checked', context).length;
        
        if (checkedCheckboxes === 0) {
          $('.select-all-nodes', context).prop('checked', false).prop('indeterminate', false);
        } else if (checkedCheckboxes === totalCheckboxes) {
          $('.select-all-nodes', context).prop('checked', true).prop('indeterminate', false);
        } else {
          $('.select-all-nodes', context).prop('checked', false).prop('indeterminate', true);
        }
      });
    }
  };

})(jQuery, Drupal);