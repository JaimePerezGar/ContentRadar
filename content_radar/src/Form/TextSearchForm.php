<?php

namespace Drupal\content_radar\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\content_radar\Service\TextSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Database\Connection;

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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new TextSearchForm.
   */
  public function __construct(
    TextSearchService $text_search_service,
    EntityTypeManagerInterface $entity_type_manager,
    CacheBackendInterface $cache,
    AccountInterface $current_user,
    LanguageManagerInterface $language_manager,
    Connection $database
  ) {
    $this->textSearchService = $text_search_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->cache = $cache;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
    $this->database = $database;
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
      $container->get('language_manager'),
      $container->get('database')
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

    $form['search_term'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search term'),
      '#description' => $this->t('Enter the text to search for.'),
      '#required' => TRUE,
      '#size' => 60,
      '#maxlength' => 255,
      '#default_value' => $form_state->getValue('search_term', ''),
    ];
    
    // Place case_sensitive right after search term
    $form['case_sensitive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Case sensitive'),
      '#description' => $this->t('Enable to make the search case-sensitive (differentiate between uppercase and lowercase).'),
    ];

    $form['search_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced search options'),
      '#open' => FALSE,
    ];


    $form['search_options']['use_regex'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use regular expressions'),
      '#description' => $this->t('Enable to use regular expressions in your search.'),
      '#default_value' => $form_state->getValue(['search_options', 'use_regex'], FALSE),
    ];

    $form['search_options']['deep_search'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Deep search (search all related entities)'),
      '#description' => $this->t('Enable to search recursively in ALL referenced entities, blocks, and components. This may take longer but finds text in VLSuite, Layout Builder, and all nested content. <strong>Recommended for VLSuite sites.</strong>'),
      '#default_value' => $form_state->getValue(['search_options', 'deep_search'], FALSE),
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
      '#description' => $this->t('Select the language to search in.'),
      '#options' => $language_options,
      '#default_value' => $form_state->getValue('langcode', ''),
    ];

    // Node selection section
    $form['node_selection'] = [
      '#type' => 'details',
      '#title' => $this->t('Node Selection'),
      '#open' => TRUE,
    ];

    $form['node_selection']['nodes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Specific Node IDs'),
      '#description' => $this->t('Enter a comma-separated list of node IDs to search within. If empty, all nodes of the selected content types will be searched.'),
      '#default_value' => $form_state->getValue(['node_selection', 'nodes'], ''),
    ];

    // Advanced filters container
    $form['filters_container'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Advanced filters'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];

    // Entity types selection.
    $form['filters_container']['entity_types_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Entity types'),
      '#open' => FALSE,
      '#description' => $this->t('Select entity types to search in. Leave unchecked to search all.'),
    ];

    // Get all available entity types dynamically
    $entity_type_options = $this->getAllEntityTypeOptions();

    // Filter out non-existent entity types.
    $definitions = $this->entityTypeManager->getDefinitions();
    foreach ($entity_type_options as $entity_type_id => $label) {
      if (!isset($definitions[$entity_type_id])) {
        unset($entity_type_options[$entity_type_id]);
      }
    }

    $form['filters_container']['entity_types_container']['entity_types'] = [
      '#type' => 'checkboxes',
      '#options' => $entity_type_options,
      '#default_value' => $form_state->getValue(['filters_container', 'entity_types_container', 'entity_types'], []),
    ];

    // Bundle types selection (for all entity types)
    $form['filters_container']['bundle_types_container'] = [
      '#type' => 'details', 
      '#title' => $this->t('Content/Bundle types'),
      '#open' => FALSE,
      '#description' => $this->t('Select specific types within each entity type.'),
    ];

    // Get bundles for each selected entity type
    $selected_entity_types = $form_state->getValue(['filters_container', 'entity_types_container', 'entity_types'], []);
    if (!empty($selected_entity_types)) {
      foreach ($selected_entity_types as $entity_type_id => $selected) {
        if ($selected) {
          $bundles = $this->getBundlesForEntityType($entity_type_id);
          if (!empty($bundles)) {
            $entity_definition = $this->entityTypeManager->getDefinition($entity_type_id);
            $entity_label = $entity_definition->getLabel();
            
            $form['filters_container']['bundle_types_container'][$entity_type_id . '_bundles'] = [
              '#type' => 'checkboxes',
              '#title' => $this->t('@entity_type types', ['@entity_type' => $entity_label]),
              '#options' => $bundles,
              '#default_value' => $form_state->getValue(['filters_container', 'bundle_types_container', $entity_type_id . '_bundles'], []),
              '#states' => [
                'visible' => [
                  ':input[name="filters_container[entity_types_container][entity_types][' . $entity_type_id . ']"]' => ['checked' => TRUE],
                ],
              ],
            ];
          }
        }
      }
    }

    // Always show content types for nodes (backward compatibility)
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $node_options = [];
    foreach ($content_types as $type) {
      $node_options[$type->id()] = $type->label();
    }

    $form['filters_container']['bundle_types_container']['node_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types'),
      '#options' => $node_options,
      '#default_value' => $form_state->getValue(['filters_container', 'bundle_types_container', 'node_bundles'], []),
      '#states' => [
        'visible' => [
          ':input[name="filters_container[entity_types_container][entity_types][node]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Paragraph types selection.
    $paragraph_types = [];
    if ($this->entityTypeManager->hasDefinition('paragraphs_type')) {
      $paragraph_types_entities = $this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple();
      foreach ($paragraph_types_entities as $type) {
        $paragraph_types[$type->id()] = $type->label();
      }
    }

    if (!empty($paragraph_types)) {
      $form['paragraph_types_container'] = [
        '#type' => 'details',
        '#title' => $this->t('Paragraph types'),
        '#open' => FALSE,
        '#description' => $this->t('Select specific paragraph types to search in.'),
        '#states' => [
          'visible' => [
            ':input[name="entity_types[paragraph]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['paragraph_types_container']['paragraph_types'] = [
        '#type' => 'checkboxes',
        '#options' => $paragraph_types,
        '#default_value' => $form_state->getValue(['paragraph_types_container', 'paragraph_types'], []),
      ];
    }

    // Replace functionality.
    $results = $form_state->get('results');
    if ($this->currentUser->hasPermission('replace content radar') && $results && $results['total'] > 0) {
      $form['replace_container'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Find and Replace'),
        '#attributes' => ['class' => ['form-item-container']],
      ];

      $form['replace_container']['replace_term'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Replace with'),
        '#description' => $this->t('Enter the text that will replace your search term.'),
        '#size' => 60,
        '#maxlength' => 255,
        '#default_value' => $form_state->getValue('replace_term', ''),
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
        '#description' => $this->t('Changes will be tracked and can be reverted.'),
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

    // Only show replace button if we have search results.
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
    if ($results !== NULL) {
      $form['results_container'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['content-radar-results-container']],
      ];


      // Group results by entity type.
      $grouped_results = [];
      foreach ($results['items'] as $item) {
        $entity_type = $item['entity_type'];
        if (!isset($grouped_results[$entity_type])) {
          $grouped_results[$entity_type] = [];
        }
        $grouped_results[$entity_type][] = $item;
      }

      $form['results_container']['results'] = [
        '#theme' => 'content_radar_results',
        '#results' => $results['items'],
        '#grouped_results' => $grouped_results,
        '#total' => $results['total'],
        '#search_term' => $form_state->getValue('search_term'),
        '#is_regex' => $form_state->getValue('use_regex'),
        '#langcode' => $form_state->getValue('langcode'),
        '#export_url' => Url::fromRoute('content_radar.export', [], [
          'query' => [
            'search_term' => $form_state->getValue('search_term'),
            'use_regex' => $form_state->getValue('use_regex') ? 1 : 0,
            'entity_types' => array_filter($form_state->getValue(['entity_types_container', 'entity_types'], [])),
            'content_types' => array_filter($form_state->getValue(['content_types_container', 'content_types'], [])),
            'langcode' => $form_state->getValue('langcode'),
          ],
        ]),
      ];

      // Create a container for selected items that will be populated by JavaScript
      if ($this->currentUser->hasPermission('replace content radar')) {
        $form['results_container']['selected_items'] = [
          '#tree' => TRUE,
          '#type' => 'container',
          '#attributes' => ['class' => ['selected-items-container']],
        ];
        
        // Add hidden checkboxes for each result item to ensure form processing
        foreach ($results['items'] as $item) {
          $checkbox_key = $item['entity_type'] . ':' . $item['id'] . ':' . $item['field_name'] . ':' . $item['langcode'];
          $form['results_container']['selected_items'][$checkbox_key] = [
            '#type' => 'checkbox',
            '#default_value' => FALSE,
            '#attributes' => [
              'class' => ['result-item-checkbox-hidden'],
              'data-checkbox-key' => $checkbox_key,
            ],
          ];
        }
      }
    }

    return $form;
  }

  /**
   * Get all available entity type options for the form.
   */
  protected function getAllEntityTypeOptions() {
    $options = [];
    $definitions = $this->entityTypeManager->getDefinitions();

    // Custom labels for common entity types
    $custom_labels = [
      'node' => $this->t('Content (Nodes)'),
      'block_content' => $this->t('Custom blocks'),
      'taxonomy_term' => $this->t('Taxonomy terms'),
      'user' => $this->t('Users'),
      'media' => $this->t('Media'),
      'menu_link_content' => $this->t('Menu links'),
      'comment' => $this->t('Comments'),
      'paragraph' => $this->t('Paragraphs'),
      'webform_submission' => $this->t('Webform submissions'),
      'commerce_product' => $this->t('Commerce products'),
      'commerce_product_variation' => $this->t('Commerce product variations'),
      'custom_block' => $this->t('Custom blocks (Layout Builder)'),
      'profile' => $this->t('User profiles'),
    ];

    foreach ($definitions as $entity_type_id => $definition) {
      // Only include content entities
      if (!$definition->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface')) {
        continue;
      }

      // Skip entities that typically don't contain searchable text
      $skip_types = [
        'file',
        'crop',
        'image_style',
        'view',
        'shortcut',
        'path_alias',
        'redirect',
      ];

      if (in_array($entity_type_id, $skip_types)) {
        continue;
      }

      // Use custom label if available, otherwise use the entity type label
      if (isset($custom_labels[$entity_type_id])) {
        $options[$entity_type_id] = $custom_labels[$entity_type_id];
      } else {
        $label = $definition->getLabel();
        $options[$entity_type_id] = $label . ' (' . $entity_type_id . ')';
      }
    }

    // Sort options alphabetically
    asort($options);

    return $options;
  }

  /**
   * Get bundles for a specific entity type.
   */
  protected function getBundlesForEntityType($entity_type_id) {
    $bundles = [];
    
    try {
      $entity_definition = $this->entityTypeManager->getDefinition($entity_type_id);
      $bundle_entity_type = $entity_definition->getBundleEntityType();
      
      if ($bundle_entity_type) {
        $bundle_storage = $this->entityTypeManager->getStorage($bundle_entity_type);
        $bundle_entities = $bundle_storage->loadMultiple();
        
        foreach ($bundle_entities as $bundle_entity) {
          $bundles[$bundle_entity->id()] = $bundle_entity->label();
        }
      }
    }
    catch (\Exception $e) {
      // If there's an error, just return empty array
    }
    
    return $bundles;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    
    $search_term = $form_state->getValue('search_term');
    $use_regex = (bool) $form_state->getValue(['search_options', 'use_regex']);
    
    // Validate search term.
    if (empty(trim($search_term))) {
      $form_state->setErrorByName('search_term', $this->t('Search term cannot be empty.'));
    }
    
    // Validate regex if enabled.
    if ($use_regex) {
      if (@preg_match('/' . $search_term . '/', '') === FALSE) {
        $form_state->setErrorByName('search_term', $this->t('Invalid regular expression pattern.'));
      }
      
      // Check for dangerous patterns.
      $dangerous_patterns = ['(?R)', '\g{', '(*'];
      foreach ($dangerous_patterns as $pattern) {
        if (strpos($search_term, $pattern) !== FALSE) {
          $form_state->setErrorByName('search_term', $this->t('Regular expression contains potentially dangerous pattern: @pattern', ['@pattern' => $pattern]));
        }
      }
    }
    
    // Validate replace term if provided.
    $replace_term = $form_state->getValue('replace_term');
    if (!empty($replace_term) && strlen($replace_term) > 1000) {
      $form_state->setErrorByName('replace_term', $this->t('Replace term is too long.'));
    }
    
    // Validate node IDs if provided.
    $node_ids_raw = $form_state->getValue(['node_selection', 'nodes'], '');
    if (!empty($node_ids_raw)) {
      // Remove spaces and validate format
      $node_ids_clean = preg_replace('/\s+/', '', $node_ids_raw);
      if (!preg_match('/^[0-9,]+$/', $node_ids_clean)) {
        $form_state->setErrorByName('node_selection][nodes', $this->t('Node IDs must be comma-separated numbers (e.g., 1,5,23).'));
      }
      else {
        // Check if all IDs are valid integers
        $node_ids = array_filter(array_map('intval', explode(',', $node_ids_clean)));
        if (empty($node_ids)) {
          $form_state->setErrorByName('node_selection][nodes', $this->t('Please enter valid node IDs.'));
        }
        else {
          // Additional validation: check if nodes exist
          $existing_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($node_ids);
          $missing_nodes = array_diff($node_ids, array_keys($existing_nodes));
          if (!empty($missing_nodes)) {
            $form_state->setErrorByName('node_selection][nodes', $this->t('The following node IDs do not exist: @ids', [
              '@ids' => implode(', ', $missing_nodes),
            ]));
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $search_term = $form_state->getValue('search_term');
    
    // Get search options values
    $case_sensitive = (bool) $form_state->getValue('case_sensitive');
    $use_regex = (bool) $form_state->getValue(['search_options', 'use_regex']);
    $deep_search = (bool) $form_state->getValue(['search_options', 'deep_search']);
    
    $langcode = $form_state->getValue('langcode');
    $entity_types = array_filter($form_state->getValue(['filters_container', 'entity_types_container', 'entity_types'], []));
    
    // Get bundles for each entity type
    $all_bundles = [];
    foreach (array_keys($entity_types) as $entity_type_id) {
      $bundle_values = $form_state->getValue(['filters_container', 'bundle_types_container', $entity_type_id . '_bundles'], []);
      $filtered_bundles = array_filter($bundle_values);
      if (!empty($filtered_bundles)) {
        $all_bundles[$entity_type_id] = array_keys($filtered_bundles);
      }
    }
    
    // Backward compatibility
    $content_types = isset($all_bundles['node']) ? $all_bundles['node'] : [];
    $paragraph_types = isset($all_bundles['paragraph']) ? $all_bundles['paragraph'] : [];

    // Get node IDs if provided.
    $node_ids_raw = $form_state->getValue(['node_selection', 'nodes'], '');
    $node_ids = [];
    if (!empty($node_ids_raw)) {
      $node_ids = array_map('intval', explode(',', $node_ids_raw));
    }

    // Get current page from query parameter.
    $page = \Drupal::request()->query->get('page', 0);

    // Perform search.
    if ($deep_search) {
      // Use deep search to find ALL related entities
      $results = $this->textSearchService->deepSearch(
        $search_term,
        $use_regex,
        array_keys($entity_types),
        array_keys($content_types),
        $langcode,
        $page,
        50,
        array_keys($paragraph_types),
        $case_sensitive,
        $node_ids
      );
    } else {
      $results = $this->textSearchService->search(
        $search_term,
        $use_regex,
        array_keys($entity_types),
        array_keys($content_types),
        $langcode,
        $page,
        50,
        array_keys($paragraph_types),
        $case_sensitive,
        $node_ids
      );
    }

    // Store results in form state.
    $form_state->set('results', $results);
    $form_state->setRebuild();

    // Log the search.
    $this->logSearch($search_term, $use_regex, $entity_types, $content_types, $results['total'], $paragraph_types);

    // Show message.
    if ($results['total'] > 0) {
      $this->messenger()->addStatus($this->t('Found @count results for "@term".', [
        '@count' => $results['total'],
        '@term' => $search_term,
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('No results found for "@term".', [
        '@term' => $search_term,
      ]));
    }
  }

  /**
   * Submit handler for replace action.
   */
  public function replaceSubmit(array &$form, FormStateInterface $form_state) {
    $search_term = $form_state->getValue('search_term');
    $replace_term = $form_state->getValue('replace_term');
    
    // Get search options values
    $case_sensitive = (bool) $form_state->getValue('case_sensitive');
    $search_options = $form_state->getValue('search_options', []);
    $use_regex = !empty($search_options['use_regex']);
    $langcode = $form_state->getValue('langcode');
    $entity_types = array_filter($form_state->getValue(['filters_container', 'entity_types_container', 'entity_types'], []));
    
    // Get bundles for each entity type
    $all_bundles = [];
    foreach (array_keys($entity_types) as $entity_type_id) {
      $bundle_values = $form_state->getValue(['filters_container', 'bundle_types_container', $entity_type_id . '_bundles'], []);
      $filtered_bundles = array_filter($bundle_values);
      if (!empty($filtered_bundles)) {
        $all_bundles[$entity_type_id] = array_keys($filtered_bundles);
      }
    }
    
    // Backward compatibility
    $content_types = isset($all_bundles['node']) ? $all_bundles['node'] : [];
    $paragraph_types = isset($all_bundles['paragraph']) ? $all_bundles['paragraph'] : [];
    $replace_mode = $form_state->getValue('replace_mode', 'selected');
    
    // Get node IDs if provided.
    $node_ids_raw = $form_state->getValue(['node_selection', 'nodes'], '');
    $node_ids = [];
    if (!empty($node_ids_raw)) {
      $node_ids = array_map('intval', explode(',', $node_ids_raw));
    }

    // Get selected items.
    $selected_items = [];
    if ($replace_mode === 'selected') {
      // Obtener los valores correctamente del contenedor anidado
      $selected_items = $form_state->getValue(['results_container', 'selected_items'], []);
      
      // DEBUG logging
      \Drupal::logger('content_radar')->debug('DEBUG - Replace mode: @mode', ['@mode' => $replace_mode]);
      \Drupal::logger('content_radar')->debug('DEBUG - Selected items from form: @items', ['@items' => print_r($selected_items, TRUE)]);
      \Drupal::logger('content_radar')->debug('DEBUG - Selected items count before filter: @count', ['@count' => count($selected_items)]);
      
      $selected_items = array_filter($selected_items);
      
      // Validación estricta
      if (empty($selected_items)) {
        $this->messenger()->addError($this->t('No items selected for replacement. Please select items from the results table or choose "Replace all".'));
        $form_state->setRebuild();
        return;
      }
      
      // Log para confirmar
      \Drupal::logger('content_radar')->info('Proceeding with selective replacement of @count items', ['@count' => count($selected_items)]);
    }

    // First, count how many replacements will be made.
    try {
      // Do a dry run to count replacements.
      $dry_run_result = $this->textSearchService->replaceText(
        $search_term,
        $replace_term,
        $use_regex,
        array_keys($entity_types),
        array_keys($content_types),
        $langcode,
        TRUE, // Dry run
        $selected_items,
        array_keys($paragraph_types),
        $case_sensitive,
        $node_ids
      );

      if ($dry_run_result['replaced_count'] > 0) {
        // Set up batch process.
        $batch = [
          'title' => $this->t('Processing text replacements'),
          'operations' => [],
          'init_message' => $this->t('Starting text replacement...'),
          'progress_message' => $this->t('Processed @current out of @total entities.'),
          'error_message' => $this->t('An error occurred during processing.'),
          'finished' => '\Drupal\content_radar\Form\TextSearchForm::batchFinished',
        ];

        // Process entities in chunks.
        $entities_to_process = $dry_run_result['affected_entities'];
        $chunks = array_chunk($entities_to_process, 10);
        
        foreach ($chunks as $chunk) {
          $batch['operations'][] = [
            '\Drupal\content_radar\Form\TextSearchForm::batchProcess',
            [
              $chunk,
              $search_term,
              $replace_term,
              $use_regex,
              $langcode,
              $selected_items,
              $case_sensitive,
            ],
          ];
        }

        batch_set($batch);
      }
      else {
        $this->messenger()->addWarning($this->t('No replacements were made.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('An error occurred during replacement: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Log a search query.
   */
  protected function logSearch($search_term, $use_regex, $entity_types, $content_types, $results_count, $paragraph_types = []) {
    try {
      $this->database->insert('content_radar_log')
        ->fields([
          'uid' => $this->currentUser->id(),
          'search_term' => $search_term,
          'use_regex' => $use_regex ? 1 : 0,
          'entity_types' => serialize($entity_types),
          'content_types' => serialize($content_types),
          'results_count' => $results_count,
          'timestamp' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      // Log error but don't break the search.
      \Drupal::logger('content_radar')->error('Failed to log search: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Save a replacement report.
   */
  protected function saveReport($search_term, $replace_term, $use_regex, $langcode, $total_replacements, $affected_entities) {
    $rid = $this->database->insert('content_radar_reports')
      ->fields([
        'uid' => $this->currentUser->id(),
        'created' => \Drupal::time()->getRequestTime(),
        'search_term' => $search_term,
        'replace_term' => $replace_term,
        'use_regex' => $use_regex ? 1 : 0,
        'langcode' => $langcode,
        'total_replacements' => $total_replacements,
        'affected_entities' => count($affected_entities),
        'details' => serialize($affected_entities),
      ])
      ->execute();

    return $rid;
  }

  /**
   * Batch process callback.
   */
  public static function batchProcess($chunk, $search_term, $replace_term, $use_regex, $langcode, $selected_items, $case_sensitive, &$context) {
    $text_search_service = \Drupal::service('content_radar.search_service');
    
    // Initialize context results.
    if (!isset($context['results']['total_replacements'])) {
      $context['results']['total_replacements'] = 0;
      $context['results']['affected_entities'] = [];
      $context['results']['search_term'] = $search_term;
      $context['results']['replace_term'] = $replace_term;
      $context['results']['use_regex'] = $use_regex;
      $context['results']['langcode'] = $langcode;
      $context['results']['case_sensitive'] = $case_sensitive;
    }
    
    // Process each entity in the chunk.
    foreach ($chunk as $entity_info) {
      try {
        $entity = \Drupal::entityTypeManager()
          ->getStorage($entity_info['entity_type'])
          ->load($entity_info['id']);
        
        if ($entity) {
          // Replace text in this specific entity.
          $entity_key = $entity_info['entity_type'] . ':' . $entity_info['id'];
          $entity_selected_items = [];
          
          // Filter selected items for this entity.
          foreach ($selected_items as $key => $value) {
            if (strpos($key, $entity_key) === 0) {
              $entity_selected_items[$key] = $value;
            }
          }
          
          // Log para debugging
          \Drupal::logger('content_radar')->debug('Batch process - Entity: @entity_key, Selected items: @items', [
            '@entity_key' => $entity_key,
            '@items' => print_r($entity_selected_items, TRUE),
          ]);
          
          // Solo procesar si hay elementos seleccionados para esta entidad
          if (empty($entity_selected_items) && !empty($selected_items)) {
            \Drupal::logger('content_radar')->debug('Skipping entity @entity_key - no selected items', [
              '@entity_key' => $entity_key,
            ]);
            continue;
          }
          
          $result = $text_search_service->replaceText(
            $search_term,
            $replace_term,
            $use_regex,
            [$entity_info['entity_type']],
            [],
            $langcode,
            FALSE,
            !empty($entity_selected_items) ? $entity_selected_items : [],
            [],
            $case_sensitive,
            [] // node_ids parameter - empty for batch processing individual entities
          );
          
          if ($result['replaced_count'] > 0) {
            $context['results']['total_replacements'] += $result['replaced_count'];
            $context['results']['affected_entities'] = array_merge(
              $context['results']['affected_entities'],
              $result['affected_entities']
            );
          }
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('content_radar')->error('Batch process error: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
      
      // Update progress message.
      $context['message'] = t('Processing entity: @title', [
        '@title' => isset($entity_info['title']) ? $entity_info['title'] : $entity_info['id'],
      ]);
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      if (!empty($results['total_replacements'])) {
        // Save report.
        $database = \Drupal::database();
        $rid = $database->insert('content_radar_reports')
          ->fields([
            'uid' => \Drupal::currentUser()->id(),
            'created' => \Drupal::time()->getRequestTime(),
            'search_term' => $results['search_term'],
            'replace_term' => $results['replace_term'],
            'use_regex' => $results['use_regex'] ? 1 : 0,
            'langcode' => $results['langcode'],
            'total_replacements' => $results['total_replacements'],
            'affected_entities' => count($results['affected_entities']),
            'details' => serialize($results['affected_entities']),
          ])
          ->execute();
        
        \Drupal::messenger()->addStatus(t('Successfully replaced @count occurrences in @entities entities.', [
          '@count' => $results['total_replacements'],
          '@entities' => count($results['affected_entities']),
        ]));
        
        // Redirect to report.
        $url = \Drupal\Core\Url::fromRoute('content_radar.report_detail', ['rid' => $rid]);
        $redirect = new \Symfony\Component\HttpFoundation\RedirectResponse($url->toString());
        $redirect->send();
      }
      else {
        \Drupal::messenger()->addWarning(t('No replacements were made.'));
      }
    }
    else {
      \Drupal::messenger()->addError(t('An error occurred during the batch process.'));
    }
  }

}