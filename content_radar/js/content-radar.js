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
      var $selectAllCheckbox = $('#select-all-results', context);
      var $itemCheckboxes = $('.result-item-checkbox', context);

      // Initialize: check if all items are already selected
      function updateSelectAllState() {
        var totalCheckboxes = $itemCheckboxes.length;
        var checkedCheckboxes = $itemCheckboxes.filter(':checked').length;
        
        if (totalCheckboxes === 0) {
          $selectAllCheckbox.prop('checked', false).prop('indeterminate', false);
        } else if (checkedCheckboxes === 0) {
          $selectAllCheckbox.prop('checked', false).prop('indeterminate', false);
        } else if (checkedCheckboxes === totalCheckboxes) {
          $selectAllCheckbox.prop('checked', true).prop('indeterminate', false);
        } else {
          $selectAllCheckbox.prop('checked', false).prop('indeterminate', true);
        }
      }

      // Select all functionality
      $selectAllCheckbox.once('content-radar-select-all').on('change', function () {
        var checked = $(this).prop('checked');
        $itemCheckboxes.prop('checked', checked);
        
        updateSelectAllState();
        updateReplaceButtonVisibility();
        updateSelectedItemsData();
      });

      // Individual checkbox change
      $itemCheckboxes.once('content-radar-select-item').on('change', function () {
        updateSelectAllState();
        updateReplaceButtonVisibility();
        updateSelectedItemsData();
      });

      // Update replace button visibility and mode based on selection
      function updateReplaceButtonVisibility() {
        var checkedCheckboxes = $itemCheckboxes.filter(':checked').length;
        var totalCheckboxes = $itemCheckboxes.length;
        var $replaceButton = $('input[name="op"][value="Replace"]', context);
        var $replaceModeRadios = $('input[name="replace_mode"]', context);
        var $replaceModeContainer = $replaceModeRadios.closest('.form-radios').parent();
        
        if (checkedCheckboxes > 0) {
          // If items are selected, automatically choose "selected" mode
          $replaceModeRadios.filter('[value="selected"]').prop('checked', true);
          
          // Update the selected mode label to show count
          var $selectedLabel = $('label[for*="selected"]', context);
          if ($selectedLabel.length) {
            $selectedLabel.text(Drupal.t('Replace only selected occurrences (@count selected)', {'@count': checkedCheckboxes}));
          }
        } else {
          // If nothing is selected, default to "all" mode
          $replaceModeRadios.filter('[value="all"]').prop('checked', true);
          
          // Reset the selected mode label
          var $selectedLabel = $('label[for*="selected"]', context);
          if ($selectedLabel.length) {
            $selectedLabel.text(Drupal.t('Replace only selected occurrences'));
          }
        }
        
        // Show helpful text about selection
        var $selectionHint = $('#selection-hint', context);
        if ($selectionHint.length === 0) {
          $selectionHint = $('<div id="selection-hint" class="form-item__description"></div>');
          $replaceModeContainer.append($selectionHint);
        }
        
        if (checkedCheckboxes === 0) {
          $selectionHint.text(Drupal.t('No items selected. "Replace all" will affect all search results.'));
        } else if (checkedCheckboxes === totalCheckboxes) {
          $selectionHint.text(Drupal.t('All items selected (@count of @total).', {'@count': checkedCheckboxes, '@total': totalCheckboxes}));
        } else {
          $selectionHint.text(Drupal.t('@count of @total items selected.', {'@count': checkedCheckboxes, '@total': totalCheckboxes}));
        }
      }

      // Handle replace mode radio button changes
      $('input[name="replace_mode"]', context).once('content-radar-replace-mode').on('change', function () {
        var selectedMode = $(this).val();
        var checkedCheckboxes = $itemCheckboxes.filter(':checked').length;
        
        if (selectedMode === 'selected' && checkedCheckboxes === 0) {
          // Show warning if selected mode is chosen but nothing is selected
          var $warning = $('#mode-warning', context);
          if ($warning.length === 0) {
            $warning = $('<div id="mode-warning" class="messages messages--warning"></div>');
            $(this).closest('.form-radios').after($warning);
          }
          $warning.html('<p>' + Drupal.t('You have selected "Replace only selected" but no items are currently selected. Please select items from the results table above.') + '</p>');
        } else {
          $('#mode-warning', context).remove();
        }
      });

      // Update the hidden field with selected items data
      function updateSelectedItemsData() {
        var selectedKeys = [];
        $itemCheckboxes.filter(':checked').each(function() {
          var key = $(this).data('checkbox-key');
          if (key) {
            selectedKeys.push(key);
          }
        });
        
        // Update the hidden field
        var $hiddenField = $('#selected-items-data');
        if ($hiddenField.length) {
          $hiddenField.val(JSON.stringify(selectedKeys));
          console.log('Updated selected items:', selectedKeys);
        }
      }

      // Initialize on page load
      updateSelectAllState();
      updateReplaceButtonVisibility();
      updateSelectedItemsData();
    }
  };

})(jQuery, Drupal);