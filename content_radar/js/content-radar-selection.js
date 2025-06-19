(function ($, Drupal, once) {
  'use strict';

  /**
   * Content Radar selection behavior.
   */
  Drupal.behaviors.contentRadarSelection = {
    attach: function (context, settings) {
      
      // Initialize selection handling
      once('content-radar-selection', '.content-radar-results-container', context).forEach(function (container) {
        initializeSelection(container);
      });
    }
  };

  /**
   * Initialize selection functionality.
   */
  function initializeSelection(container) {
    var $container = $(container);
    
    // Handle "Select All" checkbox
    $container.find('.select-all-checkbox').on('change', function () {
      var checked = $(this).is(':checked');
      $container.find('.item-select-checkbox').prop('checked', checked);
      updateSelectedItems();
    });
    
    // Handle group "Select All" checkboxes
    $container.find('.select-group-checkbox').on('change', function () {
      var checked = $(this).is(':checked');
      var group = $(this).data('group');
      $container.find('.item-select-checkbox[data-group="' + group + '"]').prop('checked', checked);
      updateSelectedItems();
      updateSelectAllState();
    });
    
    // Handle individual item checkboxes
    $container.find('.item-select-checkbox').on('change', function () {
      updateGroupState($(this).data('group'));
      updateSelectAllState();
      updateSelectedItems();
    });
    
    // Update replace button state based on selection
    updateReplaceButtonState();
    $container.find('.item-select-checkbox').on('change', updateReplaceButtonState);
  }

  /**
   * Update group checkbox state based on individual selections.
   */
  function updateGroupState(group) {
    var $groupCheckboxes = $('.item-select-checkbox[data-group="' + group + '"]');
    var $groupSelectAll = $('.select-group-checkbox[data-group="' + group + '"]');
    var checkedCount = $groupCheckboxes.filter(':checked').length;
    var totalCount = $groupCheckboxes.length;
    
    if (checkedCount === 0) {
      $groupSelectAll.prop('checked', false).prop('indeterminate', false);
    } else if (checkedCount === totalCount) {
      $groupSelectAll.prop('checked', true).prop('indeterminate', false);
    } else {
      $groupSelectAll.prop('checked', false).prop('indeterminate', true);
    }
  }

  /**
   * Update main "Select All" checkbox state.
   */
  function updateSelectAllState() {
    var $allCheckboxes = $('.item-select-checkbox');
    var $selectAll = $('.select-all-checkbox');
    var checkedCount = $allCheckboxes.filter(':checked').length;
    var totalCount = $allCheckboxes.length;
    
    if (checkedCount === 0) {
      $selectAll.prop('checked', false).prop('indeterminate', false);
    } else if (checkedCount === totalCount) {
      $selectAll.prop('checked', true).prop('indeterminate', false);
    } else {
      $selectAll.prop('checked', false).prop('indeterminate', true);
    }
  }

  /**
   * Update the hidden field with selected items.
   */
  function updateSelectedItems() {
    var selectedItems = [];
    $('.item-select-checkbox:checked').each(function () {
      var key = $(this).data('key');
      if (key) {
        selectedItems.push(key);
      }
    });
    
    $('#selected-items-data').val(JSON.stringify(selectedItems));
  }

  /**
   * Update replace button state based on selection mode and selections.
   */
  function updateReplaceButtonState() {
    var $replaceButton = $('input[name="op"][value="Replace"]');
    var replaceMode = $('input[name="replace_mode"]:checked').val();
    var hasSelections = $('.item-select-checkbox:checked').length > 0;
    var hasReplaceText = $('input[name="replace_term"]').val().trim() !== '';
    var isConfirmed = $('input[name="replace_confirm"]').is(':checked');
    
    if (replaceMode === 'selected') {
      // For selected mode, enable only if there are selections, replace text, and confirmation
      $replaceButton.prop('disabled', !hasSelections || !hasReplaceText || !isConfirmed);
    } else {
      // For "all" mode, enable if there's replace text and confirmation
      $replaceButton.prop('disabled', !hasReplaceText || !isConfirmed);
    }
  }

  // Update button state when replace mode changes
  $(document).on('change', 'input[name="replace_mode"]', updateReplaceButtonState);
  $(document).on('change', 'input[name="replace_confirm"]', updateReplaceButtonState);
  $(document).on('input', 'input[name="replace_term"]', updateReplaceButtonState);

})(jQuery, Drupal, once);