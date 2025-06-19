<?php

namespace Drupal\content_radar\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\content_radar\Service\TextSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Provides the text search form.
 */
class TextSearchForm extends FormBase {

  /**
   * The text search service.
   *
   * @var \Drupal\content_radar\Service\TextSearchService
   */
  protected $textSearchService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a new TextSearchForm.
   *
   * @param \Drupal\content_radar\Service\TextSearchService $text_search_service
   *   The text search service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(TextSearchService $text_search_service, EntityTypeManagerInterface $entity_type_manager, CacheBackendInterface $cache, AccountInterface $current_user, LanguageManagerInterface $language_manager) {
    $this->textSearchService = $text_search_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->cache = $cache;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content_radar.search_service'),
      $container->get('entity_type.manager'),
      $container->get('cache.default'),
      $container->get('current_user'),
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_radar_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'content_radar/search';
    
    // Check if we're returning from a batch operation.
    $session = \Drupal::request()->getSession();
    if ($session->has('content_radar_search_params')) {
      $params = $session->get('content_radar_search_params');
      $session->remove('content_radar_search_params');
      
      // Set the values and trigger search.
      $form_state->setValues($params);
      $form_state->set('force_fresh_results', TRUE);
      $this->submitForm($form, $form_state);
    }

    $form['search_term'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search term'),
      '#description' => $this->t('Enter the text to search for.'),
      '#required' => TRUE,
      '#size' => 60,
      '#maxlength' => 255,
      '#default_value' => $form_state->getValue('search_term', ''),
    ];

    $form['use_regex'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use regular expressions'),
      '#description' => $this->t('Enable to use regular expressions in your search.'),
      '#default_value' => $form_state->getValue('use_regex', FALSE),
    ];

    // Get available languages.
    $languages = $this->languageManager->getLanguages();
    $language_options = ['' => $this->t('- All languages -')];
    foreach ($languages as $langcode => $language) {
      $language_options[$langcode] = $language->getName();
    }

    $form['langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#description' => $this->t('Select the language to search in. Leave empty to search all languages.'),
      '#options' => $language_options,
      '#default_value' => $form_state->getValue('langcode', ''),
    ];

    // Entity types selection
    $form['entity_types_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Entity types'),
      '#open' => FALSE,
      '#description' => $this->t('Select entity types to search in. Leave unchecked to search all supported entity types.'),
    ];

    $entity_type_options = [
      'node' => $this->t('Content'),
      'block_content' => $this->t('Custom blocks'),
      'taxonomy_term' => $this->t('Taxonomy terms'),
      'user' => $this->t('Users'),
      'media' => $this->t('Media'),
      'paragraph' => $this->t('Paragraphs'),
      'menu_link_content' => $this->t('Menu links'),
      'comment' => $this->t('Comments'),
    ];

    $form['entity_types_container']['entity_types'] = [
      '#type' => 'checkboxes',
      '#options' => $entity_type_options,
      '#default_value' => $form_state->getValue('entity_types', []),
    ];

    // Get available content types.
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $options = [];
    foreach ($content_types as $type) {
      $options[$type->id()] = $type->label();
    }

    // Content types in collapsible container
    $form['content_types_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Content types'),
      '#open' => FALSE,
      '#description' => $this->t('Select specific content types to search in. Leave unchecked to search all content types.'),
      '#states' => [
        'visible' => [
          ':input[name="entity_types[node]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['content_types_container']['content_types'] = [
      '#type' => 'checkboxes',
      '#options' => $options,
      '#default_value' => $form_state->getValue('content_types', []),
    ];

    $form['sort_alphabetically'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sort results by entity type'),
      '#description' => $this->t('Group and sort results by entity type.'),
      '#default_value' => $form_state->getValue('sort_alphabetically', TRUE),
    ];

    // Replace functionality - only show if user has permission and we have search results.
    $results = $form_state->get('results');
    if ($this->currentUser->hasPermission('replace content radar') && $results && $results['total'] > 0) {
      $form['replace_container'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Find and Replace'),
        '#attributes' => ['class' => ['content-radar-replace-container']],
      ];
      
      $form['replace_container']['replace_term'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Replace "' . $form_state->getValue('search_term') . '" with:'),
        '#description' => $this->t('Enter the text that will replace selected occurrences of your search term.'),
        '#size' => 60,
        '#maxlength' => 255,
        '#default_value' => $form_state->getValue('replace_term', ''),
        '#placeholder' => $this->t('Enter replacement text...'),
      ];
      
      $form['replace_container']['replace_mode'] = [
        '#type' => 'radios',
        '#title' => $this->t('Replace mode'),
        '#options' => [
          'all' => $this->t('Replace all occurrences'),
          'selected' => $this->t('Replace only selected occurrences'),
        ],
        '#default_value' => $form_state->getValue('replace_mode', 'selected'),
        '#states' => [
          'visible' => [
            ':input[name="replace_term"]' => ['filled' => TRUE],
          ],
        ],
      ];
      
      $form['replace_container']['replace_confirm'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('I understand this will modify content'),
        '#description' => $this->t('Check this box to confirm you want to replace text. Changes will be tracked and can be reverted.'),
        '#default_value' => FALSE,
        '#states' => [
          'visible' => [
            ':input[name="replace_term"]' => ['filled' => TRUE],
          ],
          'required' => [
            ':input[name="replace_term"]' => ['filled' => TRUE],
          ],
        ],
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#button_type' => 'primary',
    ];
    
    // Only show replace button if we have search results
    $results = $form_state->get('results');
    if ($this->currentUser->hasPermission('replace content radar') && $results && $results['total'] > 0) {
      $form['actions']['replace'] = [
        '#type' => 'submit',
        '#value' => $this->t('Replace'),
        '#button_type' => 'danger',
        '#submit' => ['::replaceSubmit'],
        '#states' => [
          'visible' => [
            ':input[name="replace_term"]' => ['filled' => TRUE],
            ':input[name="replace_confirm"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    // Display results if available.
    $results = $form_state->get('results');
    if ($results !== NULL) {
      $form['results_container'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['content-radar-results-container']],
      ];

      // Add select all checkbox
      if ($results['total'] > 0) {
        $form['results_container']['select_all'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Select all'),
          '#attributes' => ['class' => ['select-all-checkbox']],
        ];
      }

      // Group results by entity type
      $grouped_results = [];
      if ($results['total'] > 0 && !empty($results['items'])) {
        foreach ($results['items'] as $index => $item) {
          // Get entity type with fallback for backward compatibility
          $entity_type = isset($item['entity_type']) ? $item['entity_type'] : 'node';
          $entity_type_label = $this->getEntityTypeLabel($entity_type);
          // Convert TranslatableMarkup to string for use as array key
          $entity_type_key = (string) $entity_type_label;
          
          if (!isset($grouped_results[$entity_type_key])) {
            $grouped_results[$entity_type_key] = [
              'entity_type' => $entity_type,
              'entity_type_label' => $entity_type_label,
              'items' => [],
            ];
          }
          
          // Prepare item data
          $entity = $item['entity'];
          $view_url = NULL;
          $edit_url = NULL;
          
          try {
            if ($entity->hasLinkTemplate('canonical')) {
              $view_url = $entity->toUrl()->setOption('attributes', ['target' => '_blank']);
            }
            if ($entity->access('update', $this->currentUser) && $entity->hasLinkTemplate('edit-form')) {
              $edit_url = $entity->toUrl('edit-form')->setOption('attributes', ['target' => '_blank']);
            }
          } catch (\Exception $e) {
            // Some entities may not have URLs
          }
          
          // Create unique key for selection
          $item_key = $entity_type . ':' . $item['id'] . ':' . $item['field_name'] . ':' . $item['langcode'];
          
          $grouped_results[$entity_type_key]['items'][] = [
            'key' => $item_key,
            'index' => $index,
            'entity_type' => $entity_type,
            'content_type' => $item['content_type'],
            'id' => $item['id'],
            'title' => $item['title'],
            'language' => $item['language'] ?? $this->t('Unknown'),
            'field_label' => $item['field_label'],
            'extract' => $item['extract'],
            'status' => $item['status'],
            'changed' => isset($item['changed']) ? (is_object($item['changed']) ? $item['changed']->format('Y-m-d H:i') : date('Y-m-d H:i', $item['changed'])) : '',
            'view_url' => $view_url,
            'edit_url' => $edit_url,
          ];
        }
      }

      // Prepare export URL.
      $export_url = NULL;
      if ($results['total'] > 0) {
        $export_url = Url::fromRoute('content_radar.export', [], [
          'query' => [
            'search_term' => $form_state->getValue('search_term'),
            'use_regex' => $form_state->getValue('use_regex') ? 'true' : 'false',
            'content_types' => implode(',', array_filter($form_state->getValue(['content_types_container', 'content_types'], []))),
            'langcode' => $form_state->getValue('langcode', ''),
          ],
        ]);
      }

      // Sort grouped results if needed
      if ($form_state->getValue('sort_alphabetically', TRUE)) {
        ksort($grouped_results);
      }

      // Build selectable results
      foreach ($grouped_results as $entity_type_key => $group_data) {
        $entity_type_label = isset($group_data['entity_type_label']) ? $group_data['entity_type_label'] : $entity_type_key;
        $form['results_container'][$entity_type_key] = [
          '#type' => 'details',
          '#title' => $this->t('@type (@count)', [
            '@type' => $entity_type_label,
            '@count' => count($group_data['items']),
          ]),
          '#open' => TRUE,
          '#attributes' => ['class' => ['entity-type-group']],
        ];

        $form['results_container'][$entity_type_key]['select_group'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Select all in this group'),
          '#attributes' => ['class' => ['select-group-checkbox'], 'data-group' => $entity_type_key],
        ];

        $form['results_container'][$entity_type_key]['items'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['results-items']],
        ];

        foreach ($group_data['items'] as $item) {
          $item_id = 'item_' . $item['index'];
          
          $form['results_container'][$entity_type_key]['items'][$item_id] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['result-item']],
          ];

          $form['results_container'][$entity_type_key]['items'][$item_id]['selected'] = [
            '#type' => 'checkbox',
            '#title' => '',
            '#default_value' => FALSE,
            '#attributes' => [
              'class' => ['item-select-checkbox'],
              'data-group' => $entity_type_key,
              'data-key' => $item['key'],
            ],
          ];

          // Create structured display
          $item_display = [
            '#theme' => 'content_radar_result_item',
            '#item' => $item,
          ];

          $form['results_container'][$entity_type_key]['items'][$item_id]['display'] = $item_display;
        }
      }

      // Store selected items in form state
      $form['results_container']['selected_items'] = [
        '#type' => 'hidden',
        '#value' => '',
        '#attributes' => ['id' => 'selected-items-data'],
      ];

      // Add JavaScript for selection handling
      $form['#attached']['library'][] = 'content_radar/selection';
    }

    return $form;
  }

  /**
   * Build result rows for the table.
   *
   * @param array $items
   *   The result items.
   *
   * @return array
   *   The table rows.
   */
  protected function buildResultRows(array $items) {
    $rows = [];
    
    foreach ($items as $item) {
      $entity = $item['entity'];
      
      // Build view and edit links.
      $view_link = Link::fromTextAndUrl(
        $this->t('View'),
        $entity->toUrl()->setOption('attributes', ['target' => '_blank'])
      )->toString();
      
      $edit_link = '';
      if ($entity->access('update', $this->currentUser)) {
        $edit_link = Link::fromTextAndUrl(
          $this->t('Edit'),
          $entity->toUrl('edit-form')->setOption('attributes', ['target' => '_blank'])
        )->toString();
      }
      
      // Get usage/references.
      $usage = $this->textSearchService->getEntityUsage($entity);
      $usage_display = [];
      foreach ($usage as $ref) {
        $ref_link = Link::fromTextAndUrl(
          $ref['title'],
          Url::fromRoute('entity.node.canonical', ['node' => $ref['id']])
            ->setOption('attributes', ['target' => '_blank'])
        )->toString();
        $usage_display[] = $ref_link;
      }
      
      $rows[] = [
        'data' => [
          $item['content_type'],
          $item['id'],
          $item['title'],
          isset($item['language']) ? $item['language'] : $this->t('Unknown'),
          $item['field_label'],
          [
            'data' => [
              '#markup' => !empty($usage_display) ? implode(', ', $usage_display) : $this->t('Not referenced'),
            ],
          ],
          [
            'data' => [
              '#markup' => '<div class="search-extract">' . $item['extract'] . '</div>',
            ],
          ],
          $item['status'] ? $this->t('Published') : $this->t('Unpublished'),
          is_object($item['changed']) ? $item['changed']->format('Y-m-d H:i') : date('Y-m-d H:i', $item['changed']),
          [
            'data' => [
              '#markup' => $view_link . ' | ' . $edit_link,
            ],
          ],
        ],
        'class' => $item['status'] ? [] : ['unpublished'],
      ];
    }
    
    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $search_term = $form_state->getValue('search_term');
    $use_regex = $form_state->getValue('use_regex');

    if ($use_regex) {
      // Validate regex pattern.
      if (@preg_match('/' . $search_term . '/', '') === FALSE) {
        $form_state->setErrorByName('search_term', $this->t('Invalid regular expression pattern.'));
      }
    }
  }

  /**
   * Preview replace handler.
   */
  public function previewReplace(array &$form, FormStateInterface $form_state) {
    $search_term = $form_state->getValue('search_term');
    $replace_term = $form_state->getValue('replace_term');
    $use_regex = $form_state->getValue('use_regex');
    $content_types = array_filter($form_state->getValue(['content_types_container', 'content_types'], []));
    $langcode = $form_state->getValue('langcode', '');
    
    try {
      // Perform a dry run to get preview.
      $result = $this->textSearchService->replaceText($search_term, $replace_term, $use_regex, $content_types, $langcode, TRUE);
      
      if ($result['replaced_count'] > 0) {
        $this->messenger()->addStatus($this->formatPlural(
          $result['replaced_count'],
          'Found 1 occurrence that would be replaced in @node_count content item.',
          'Found @count occurrences that would be replaced in @node_count content items.',
          ['@node_count' => count($result['affected_nodes'])]
        ));
        
        // Store preview results.
        $form_state->set('preview_results', $result);
      } else {
        $this->messenger()->addWarning($this->t('No matches found for replacement.'));
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error during preview: @message', ['@message' => $e->getMessage()]));
    }
    
    $form_state->setRebuild();
  }

  /**
   * Replace submit handler.
   */
  public function replaceSubmit(array &$form, FormStateInterface $form_state) {
    $search_term = $form_state->getValue('search_term');
    $replace_term = $form_state->getValue('replace_term');
    $use_regex = $form_state->getValue('use_regex');
    $langcode = $form_state->getValue('langcode', '');
    $replace_mode = $form_state->getValue('replace_mode', 'selected');
    
    if (!$form_state->getValue('replace_confirm')) {
      $this->messenger()->addError($this->t('You must confirm the replacement action.'));
      $form_state->setRebuild();
      return;
    }
    
    // Get the current search results.
    $results = $form_state->get('results');
    if (!$results || empty($results['items'])) {
      $this->messenger()->addWarning($this->t('No search results found. Please search first.'));
      return;
    }
    
    // Get selected items from form submission
    $selected_items = [];
    if ($replace_mode === 'selected') {
      // Parse selected items from form values
      $form_values = $form_state->getValues();
      foreach ($form_values as $key => $value) {
        if (strpos($key, 'selected') === 0 && $value == 1) {
          // Extract the item key from the form element
          $parent_key = $form_state->getValue(['results_container', $key, '#attributes', 'data-key']);
          if ($parent_key) {
            $selected_items[$parent_key] = TRUE;
          }
        }
      }
      
      // Also check nested form structure
      $results_container = $form_state->getValue('results_container');
      if (is_array($results_container)) {
        foreach ($results_container as $group_key => $group_data) {
          if (is_array($group_data) && isset($group_data['items'])) {
            foreach ($group_data['items'] as $item_key => $item_data) {
              if (isset($item_data['selected']) && $item_data['selected'] == 1) {
                // Get the data-key attribute
                if (isset($form['results_container'][$group_key]['items'][$item_key]['selected']['#attributes']['data-key'])) {
                  $data_key = $form['results_container'][$group_key]['items'][$item_key]['selected']['#attributes']['data-key'];
                  $selected_items[$data_key] = TRUE;
                }
              }
            }
          }
        }
      }
      
      if (empty($selected_items)) {
        $this->messenger()->addError($this->t('Please select at least one item to replace.'));
        $form_state->setRebuild();
        return;
      }
    }
    
    // Set up batch operation
    $batch = [
      'title' => $this->t('Replacing text across content'),
      'operations' => [],
      'finished' => '\Drupal\content_radar\Form\TextSearchForm::batchFinished',
      'init_message' => $this->t('Starting text replacement...'),
      'progress_message' => $this->t('Processed @current out of @total items.'),
      'error_message' => $this->t('An error occurred during processing.'),
    ];
    
    if ($replace_mode === 'all') {
      // Collect unique entities from search results
      $entities_to_process = [];
      foreach ($results['items'] as $item) {
        // Get entity type with fallback for backward compatibility
        $entity_type = isset($item['entity_type']) ? $item['entity_type'] : 'node';
        $entity_key = $entity_type . ':' . $item['id'] . ':' . $item['langcode'];
        if (!isset($entities_to_process[$entity_key])) {
          $entities_to_process[$entity_key] = [
            'entity_type' => $entity_type,
            'entity_id' => $item['id'],
            'title' => $item['title'],
            'langcode' => $item['langcode'],
          ];
        }
      }
      
      // Process each entity individually
      foreach ($entities_to_process as $entity_info) {
        $batch['operations'][] = [
          '\Drupal\content_radar\Form\TextSearchForm::batchProcessEntity',
          [$entity_info['entity_type'], $entity_info['entity_id'], $search_term, $replace_term, $use_regex, $langcode],
        ];
      }
    } else {
      // Process only selected items
      $batch['operations'][] = [
        '\Drupal\content_radar\Form\TextSearchForm::batchProcessSelected',
        [$selected_items, $search_term, $replace_term, $use_regex],
      ];
    }
    
    batch_set($batch);
    
    // Store the search parameters in session for redirect after batch.
    $session = \Drupal::request()->getSession();
    $session->set('content_radar_search_params', [
      'search_term' => $search_term,
      'use_regex' => $use_regex,
      'entity_types' => array_filter($form_state->getValue(['entity_types_container', 'entity_types'], [])),
      'content_types' => array_filter($form_state->getValue(['content_types_container', 'content_types'], [])),
      'langcode' => $langcode,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $search_term = $form_state->getValue('search_term');
    $use_regex = $form_state->getValue('use_regex');
    $entity_types = array_filter($form_state->getValue(['entity_types_container', 'entity_types'], []));
    $content_types = array_filter($form_state->getValue(['content_types_container', 'content_types'], []));
    $langcode = $form_state->getValue('langcode', '');

    // Create cache key.
    $cache_key = 'content_radar:' . md5($search_term . ':' . ($use_regex ? '1' : '0') . ':' . 
      implode(',', $entity_types) . ':' . implode(',', $content_types) . ':' . $langcode);
    
    // Check if we should force fresh results (after replacement)
    $force_fresh = $form_state->get('force_fresh_results');
    
    // Check cache only if not forcing fresh results.
    if (!$force_fresh) {
      $cache = $this->cache->get($cache_key);
      if ($cache && $cache->data) {
        $results = $cache->data;
      } else {
        // Perform search.
        $results = $this->textSearchService->search($search_term, $use_regex, $content_types, $langcode, 0, 50, $entity_types);
        
        // Cache results for 15 minutes.
        $this->cache->set($cache_key, $results, time() + 900);
      }
    } else {
      // Force fresh search.
      $results = $this->textSearchService->search($search_term, $use_regex, $content_types, $langcode, 0, 50, $entity_types);
      
      // Cache results for 15 minutes.
      $this->cache->set($cache_key, $results, time() + 900);
      
      // Reset the flag.
      $form_state->set('force_fresh_results', FALSE);
    }

    $form_state->set('results', $results);
    $form_state->setRebuild();
  }

  /**
   * Get entity type label.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return string
   *   The entity type label.
   */
  protected function getEntityTypeLabel($entity_type_id) {
    $labels = [
      'node' => $this->t('Content'),
      'block_content' => $this->t('Custom blocks'),
      'taxonomy_term' => $this->t('Taxonomy terms'),
      'user' => $this->t('Users'),
      'media' => $this->t('Media'),
      'paragraph' => $this->t('Paragraphs'),
      'menu_link_content' => $this->t('Menu links'),
      'comment' => $this->t('Comments'),
    ];
    
    return isset($labels[$entity_type_id]) ? $labels[$entity_type_id] : $entity_type_id;
  }

  /**
   * Batch process callback for entity replacement.
   */
  public static function batchProcessEntity($entity_type, $entity_id, $search_term, $replace_term, $use_regex, $langcode, &$context) {
    // Initialize results in context.
    if (!isset($context['results']['replaced_count'])) {
      $context['results']['replaced_count'] = 0;
      $context['results']['processed_entities'] = 0;
      $context['results']['errors'] = [];
      $context['results']['entity_details'] = [];
      $context['results']['search_term'] = $search_term;
      $context['results']['replace_term'] = $replace_term;
      $context['results']['use_regex'] = $use_regex;
      $context['results']['langcode'] = $langcode;
    }
    
    $text_search_service = \Drupal::service('content_radar.search_service');
    
    try {
      $result = $text_search_service->replaceText(
        $search_term,
        $replace_term,
        $use_regex,
        [],
        $langcode,
        FALSE,
        [$entity_type],
        [$entity_type . ':' . $entity_id . ':all:' . $langcode => TRUE]
      );
      
      if ($result['replaced_count'] > 0) {
        $context['results']['replaced_count'] += $result['replaced_count'];
        $context['results']['processed_entities']++;
        
        // Store details for the report
        foreach ($result['affected_entities'] as $entity_info) {
          $key = $entity_info['entity_type'] . ':' . $entity_info['id'];
          $context['results']['entity_details'][$key] = $entity_info;
        }
        
        $context['message'] = t('Replaced @count occurrences', ['@count' => $result['replaced_count']]);
      }
      
    } catch (\Exception $e) {
      $context['results']['errors'][] = t('Error processing @type @id: @error', [
        '@type' => $entity_type,
        '@id' => $entity_id,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Batch process callback for selected items replacement.
   */
  public static function batchProcessSelected($selected_items, $search_term, $replace_term, $use_regex, &$context) {
    // Initialize results in context.
    if (!isset($context['results']['replaced_count'])) {
      $context['results']['replaced_count'] = 0;
      $context['results']['processed_entities'] = 0;
      $context['results']['errors'] = [];
      $context['results']['entity_details'] = [];
      $context['results']['search_term'] = $search_term;
      $context['results']['replace_term'] = $replace_term;
      $context['results']['use_regex'] = $use_regex;
    }
    
    $text_search_service = \Drupal::service('content_radar.search_service');
    
    try {
      $result = $text_search_service->replaceText(
        $search_term,
        $replace_term,
        $use_regex,
        [],
        '',
        FALSE,
        [],
        $selected_items
      );
      
      if ($result['replaced_count'] > 0) {
        $context['results']['replaced_count'] += $result['replaced_count'];
        $context['results']['processed_entities'] += count($result['affected_entities']);
        
        // Store details for the report
        foreach ($result['affected_entities'] as $entity_info) {
          $key = $entity_info['entity_type'] . ':' . $entity_info['id'];
          $context['results']['entity_details'][$key] = $entity_info;
        }
        
        $context['message'] = t('Replaced @count occurrences in @entities entities', [
          '@count' => $result['replaced_count'],
          '@entities' => count($result['affected_entities']),
        ]);
      }
      
    } catch (\Exception $e) {
      $context['results']['errors'][] = t('Error processing selected items: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Batch process callback for text replacement.
   */
  public static function batchProcess($nodes, $search_term, $replace_term, $use_regex, $langcode, &$context) {
    // Initialize results in context.
    if (!isset($context['results']['replaced_count'])) {
      $context['results']['replaced_count'] = 0;
      $context['results']['processed_nodes'] = 0;
      $context['results']['errors'] = [];
    }
    
    $text_search_service = \Drupal::service('content_radar.search_service');
    $entity_type_manager = \Drupal::entityTypeManager();
    $node_storage = $entity_type_manager->getStorage('node');
    
    foreach ($nodes as $nid => $node_info) {
      try {
        $node = $node_storage->load($nid);
        if (!$node) {
          continue;
        }
        
        // Process the specific language or all languages.
        $count = 0;
        if (!empty($langcode) && $node->hasTranslation($langcode)) {
          $translation = $node->getTranslation($langcode);
          $count = $text_search_service->replaceInNode($translation, $search_term, $replace_term, $use_regex);
        } elseif (empty($langcode)) {
          // Process all translations.
          foreach ($node->getTranslationLanguages() as $lang_code => $language) {
            $translation = $node->getTranslation($lang_code);
            $count += $text_search_service->replaceInNode($translation, $search_term, $replace_term, $use_regex);
          }
        }
        
        if ($count > 0) {
          $node->save();
          $context['results']['replaced_count'] += $count;
        }
        
        $context['results']['processed_nodes']++;
        
        // Update progress message.
        $context['message'] = t('Processing @title', ['@title' => $node->getTitle()]);
        
      } catch (\Exception $e) {
        $context['results']['errors'][] = t('Error processing node @nid: @error', [
          '@nid' => $nid,
          '@error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    $messenger = \Drupal::messenger();
    
    if ($success) {
      if (!empty($results['replaced_count'])) {
        $entity_count = isset($results['processed_entities']) ? $results['processed_entities'] : 
                       (isset($results['processed_nodes']) ? $results['processed_nodes'] : 0);
        
        $messenger->addStatus(\Drupal::translation()->formatPlural(
          $results['replaced_count'],
          'Successfully replaced 1 occurrence in @entity_count entities.',
          'Successfully replaced @count occurrences in @entity_count entities.',
          [
            '@entity_count' => $entity_count,
          ]
        ));
        
        // Save the report to database
        try {
          $database = \Drupal::database();
          $entity_details = isset($results['entity_details']) ? $results['entity_details'] : 
                           (isset($results['node_details']) ? $results['node_details'] : []);
          
          $rid = $database->insert('content_radar_reports')
            ->fields([
              'uid' => \Drupal::currentUser()->id(),
              'created' => \Drupal::time()->getRequestTime(),
              'search_term' => isset($results['search_term']) ? $results['search_term'] : '',
              'replace_term' => isset($results['replace_term']) ? $results['replace_term'] : '',
              'use_regex' => isset($results['use_regex']) && $results['use_regex'] ? 1 : 0,
              'langcode' => isset($results['langcode']) ? $results['langcode'] : '',
              'total_replacements' => $results['replaced_count'],
              'nodes_affected' => count($entity_details),
              'details' => serialize($entity_details),
            ])
            ->execute();
          
          // Add link to view the report
          $report_url = \Drupal\Core\Url::fromRoute('content_radar.report_details', ['rid' => $rid]);
          $messenger->addStatus(t('A report of this replacement operation has been saved. <a href="@url">View report</a>', [
            '@url' => $report_url->toString(),
          ]));
        } catch (\Exception $e) {
          \Drupal::logger('content_radar')->error('Failed to save report: @error', ['@error' => $e->getMessage()]);
        }
        
        // Log the action.
        \Drupal::logger('content_radar')->notice('Batch replacement completed: @count replacements in @entities entities', [
          '@count' => $results['replaced_count'],
          '@entities' => $entity_count,
        ]);
        
        // Clear all caches to ensure fresh results.
        \Drupal::cache()->deleteAll();
      } else {
        $messenger->addWarning(t('No replacements were made.'));
      }
      
      // Show any errors.
      if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
          $messenger->addError($error);
        }
      }
    } else {
      $messenger->addError(t('The replacement process encountered an error.'));
    }
    
    // Clear the destination parameter to allow our redirect to work.
    \Drupal::request()->query->remove('destination');
  }

}