(function ($, Drupal) {
  'use strict';

  /**
   * Behavior for the undo page select all functionality.
   */
  Drupal.behaviors.contentRadarUndoPage = {
    attach: function (context, settings) {
      // Handle select all checkbox.
      $('#select-all-nodes', context).once('content-radar-select-all').on('change', function () {
        var isChecked = $(this).is(':checked');
        $('.node-select', context).prop('checked', isChecked);
      });

      // Update select all checkbox when individual checkboxes change.
      $('.node-select', context).once('content-radar-individual').on('change', function () {
        var totalCheckboxes = $('.node-select', context).length;
        var checkedCheckboxes = $('.node-select:checked', context).length;
        
        $('#select-all-nodes', context).prop('checked', totalCheckboxes === checkedCheckboxes);
      });
    }
  };

})(jQuery, Drupal);