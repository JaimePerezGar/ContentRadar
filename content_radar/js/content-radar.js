/**
 * @file
 * JavaScript for ContentRadar module.
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Behavior for ContentRadar.
   */
  Drupal.behaviors.contentRadar = {
    attach: function (context, settings) {
      // Add confirmation for regex searches with special characters.
      $('#content-radar-search-form', context).once('content-radar').each(function () {
        var $form = $(this);
        var $searchTerm = $form.find('input[name="search_term"]');
        var $useRegex = $form.find('input[name="use_regex"]');
        
        $form.on('submit', function (e) {
          if ($useRegex.is(':checked')) {
            var term = $searchTerm.val();
            // Check for potentially dangerous regex patterns.
            if (term.match(/[\(\)\[\]\{\}\*\+\?]/)) {
              if (!confirm(Drupal.t('Your search term contains special regex characters. This may take longer to process. Continue?'))) {
                e.preventDefault();
                return false;
              }
            }
          }
        });
      });
      
      // Highlight search terms in results.
      $('.search-extract mark', context).once('highlight').each(function () {
        $(this).css('animation', 'highlight 0.5s ease-in-out');
      });
      
      // Add select all/none functionality for content types.
      var $contentTypes = $('#edit-content-types', context);
      if ($contentTypes.length) {
        $contentTypes.once('select-all').before(
          '<div class="form-item">' +
          '<a href="#" class="select-all">' + Drupal.t('Select all') + '</a> | ' +
          '<a href="#" class="select-none">' + Drupal.t('Select none') + '</a>' +
          '</div>'
        );
        
        $('.select-all', context).on('click', function (e) {
          e.preventDefault();
          $contentTypes.find('input[type="checkbox"]').prop('checked', true);
        });
        
        $('.select-none', context).on('click', function (e) {
          e.preventDefault();
          $contentTypes.find('input[type="checkbox"]').prop('checked', false);
        });
      }
      
      // Add confirmation for replace action.
      $('input[value="Replace All"]', context).once('replace-confirm').on('click', function (e) {
        var searchTerm = $('input[name="search_term"]').val();
        var replaceTerm = $('input[name="replace_term"]').val();
        
        if (!confirm(Drupal.t('Are you sure you want to replace all occurrences of "@search" with "@replace"? This action cannot be undone.', {
          '@search': searchTerm,
          '@replace': replaceTerm
        }))) {
          e.preventDefault();
          return false;
        }
      });
    }
  };

})(jQuery, Drupal);