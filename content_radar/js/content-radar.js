/**
 * @file
 * JavaScript behaviors for Content Radar module.
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Behavior for select all functionality.
   */
  Drupal.behaviors.contentRadarSelectAll = {
    attach: function (context, settings) {
      // Select all results.
      $('#select-all-results', context).once('content-radar-select-all').on('change', function () {
        var checked = $(this).prop('checked');
        $('.result-item-checkbox', context).prop('checked', checked);
        $('.select-group-checkbox', context).prop('checked', checked);
      });

      // Select all in entity type group.
      $('.select-group-checkbox', context).once('content-radar-select-group').on('change', function () {
        var checked = $(this).prop('checked');
        var entityType = $(this).data('entity-type');
        $('.result-item-checkbox[data-entity-type="' + entityType + '"]', context).prop('checked', checked);
        
        // Update select all checkbox.
        var allChecked = $('.result-item-checkbox:not(:checked)', context).length === 0;
        $('#select-all-results', context).prop('checked', allChecked);
      });

      // Individual checkbox change.
      $('.result-item-checkbox', context).once('content-radar-select-item').on('change', function () {
        var entityType = $(this).data('entity-type');
        
        // Update group checkbox.
        var groupCheckboxes = $('.result-item-checkbox[data-entity-type="' + entityType + '"]', context);
        var groupChecked = groupCheckboxes.filter(':checked').length === groupCheckboxes.length;
        $('.select-group-checkbox[data-entity-type="' + entityType + '"]', context).prop('checked', groupChecked);
        
        // Update select all checkbox.
        var allChecked = $('.result-item-checkbox:not(:checked)', context).length === 0;
        $('#select-all-results', context).prop('checked', allChecked);
      });

      // Show/hide replace notice based on mode.
      $('input[name="replace_mode"]', context).once('content-radar-replace-mode').on('change', function () {
        if ($(this).val() === 'selected') {
          var selectedCount = $('.result-item-checkbox:checked', context).length;
          if (selectedCount === 0) {
            $('.replace-mode-selected-notice', context).show();
          }
        } else {
          $('.replace-mode-selected-notice', context).hide();
        }
      });
    }
  };

})(jQuery, Drupal);