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
    ];

    $form['content_types_container']['content_types'] = [
      '#type' => 'checkboxes',
      '#options' => $options,
      '#default_value' => $form_state->getValue('content_types', []),
    ];

    $form['sort_alphabetically'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sort content types alphabetically'),
      '#description' => $this->t('Group and sort results by content type in alphabetical order.'),
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
        '#description' => $this->t('Enter the text that will replace all occurrences of your search term.'),
        '#size' => 60,
        '#maxlength' => 255,
        '#default_value' => $form_state->getValue('replace_term', ''),
        '#placeholder' => $this->t('Enter replacement text...'),
      ];
      
      $form['replace_container']['replace_confirm'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('I understand this will modify content'),
        '#description' => $this->t('Check this box to confirm you want to replace text. This action cannot be undone.'),
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
        '#value' => $this->t('Replace All'),
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

      // Group results by content type for card view.
      $grouped_results = [];
      if ($results['total'] > 0 && !empty($results['items'])) {
        foreach ($results['items'] as $item) {
          $content_type = $item['content_type'];
          if (!isset($grouped_results[$content_type])) {
            $grouped_results[$content_type] = [];
          }
          
          // Prepare item data for template.
          $entity = $item['entity'];
          $view_url = $entity->toUrl()->setOption('attributes', ['target' => '_blank']);
          $edit_url = NULL;
          if ($entity->access('update', $this->currentUser)) {
            $edit_url = $entity->toUrl('edit-form')->setOption('attributes', ['target' => '_blank']);
          }
          
          // Get usage/references.
          $usage = $this->textSearchService->getEntityUsage($entity);
          $usage_prepared = [];
          foreach ($usage as $ref) {
            $usage_prepared[] = [
              'title' => $ref['title'],
              'url' => Url::fromRoute('entity.node.canonical', ['node' => $ref['id']])
                ->setOption('attributes', ['target' => '_blank']),
            ];
          }
          
          $grouped_results[$content_type][] = [
            'id' => $item['id'],
            'title' => $item['title'],
            'language' => $item['language'] ?? $this->t('Unknown'),
            'field_label' => $item['field_label'],
            'extract' => $item['extract'],
            'status' => $item['status'],
            'changed' => is_object($item['changed']) ? $item['changed']->format('Y-m-d H:i') : date('Y-m-d H:i', $item['changed']),
            'view_url' => $view_url,
            'edit_url' => $edit_url,
            'usage' => $usage_prepared,
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

      // Use theme function for better presentation.
      $form['results_container']['results'] = [
        '#theme' => 'content_radar_results',
        '#results' => $results['items'],
        '#grouped_results' => $grouped_results,
        '#total' => $results['total'],
        '#search_term' => $form_state->getValue('search_term'),
        '#is_regex' => $form_state->getValue('use_regex'),
        '#langcode' => $form_state->getValue('langcode'),
        '#sort_alphabetically' => $form_state->getValue('sort_alphabetically', TRUE),
        '#export_url' => $export_url,
        '#pager' => [
          '#type' => 'pager',
        ],
      ];
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
    
    // Collect unique nodes from search results.
    $nodes_to_process = [];
    foreach ($results['items'] as $item) {
      $nid = $item['id'];
      if (!isset($nodes_to_process[$nid])) {
        $nodes_to_process[$nid] = [
          'nid' => $nid,
          'title' => $item['title'],
          'type' => $item['entity']->bundle(),
          'langcode' => $item['langcode'],
        ];
      }
    }
    
    // Log what we're about to process
    \Drupal::logger('content_radar')->notice('Starting batch replacement: @count nodes, search: "@search", replace: "@replace", langcode: "@lang"', [
      '@count' => count($nodes_to_process),
      '@search' => $search_term,
      '@replace' => $replace_term,
      '@lang' => $langcode ?: 'all',
    ]);
    
    if (empty($nodes_to_process)) {
      $this->messenger()->addWarning($this->t('No content to process.'));
      return;
    }
    
    // Set up batch operation.
    $batch = [
      'title' => $this->t('Replacing text across content'),
      'operations' => [],
      'finished' => '\Drupal\content_radar\Form\TextSearchForm::batchFinished',
      'init_message' => $this->t('Starting text replacement...'),
      'progress_message' => $this->t('Processed @current out of @total items.'),
      'error_message' => $this->t('An error occurred during processing.'),
    ];
    
    // Process each node individually for better progress feedback.
    foreach ($nodes_to_process as $nid => $node_info) {
      $batch['operations'][] = [
        '\Drupal\content_radar\Form\TextSearchForm::batchProcessSingle',
        // Pass the search langcode (not the node's langcode) to ensure we replace in the same language we searched in
        [$nid, $search_term, $replace_term, $use_regex, $langcode],
      ];
    }
    
    batch_set($batch);
    
    // Store the search parameters in session for redirect after batch.
    $session = \Drupal::request()->getSession();
    $session->set('content_radar_search_params', [
      'search_term' => $search_term,
      'use_regex' => $use_regex,
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
    $content_types = array_filter($form_state->getValue(['content_types_container', 'content_types'], []));
    $langcode = $form_state->getValue('langcode', '');

    // Create cache key.
    $cache_key = 'content_radar:' . md5($search_term . ':' . ($use_regex ? '1' : '0') . ':' . implode(',', $content_types) . ':' . $langcode);
    
    // Check if we should force fresh results (after replacement)
    $force_fresh = $form_state->get('force_fresh_results');
    
    // Check cache only if not forcing fresh results.
    if (!$force_fresh) {
      $cache = $this->cache->get($cache_key);
      if ($cache && $cache->data) {
        $results = $cache->data;
      } else {
        // Perform search.
        $results = $this->textSearchService->search($search_term, $use_regex, $content_types, $langcode);
        
        // Cache results for 15 minutes.
        $this->cache->set($cache_key, $results, time() + 900);
      }
    } else {
      // Force fresh search.
      $results = $this->textSearchService->search($search_term, $use_regex, $content_types, $langcode);
      
      // Cache results for 15 minutes.
      $this->cache->set($cache_key, $results, time() + 900);
      
      // Reset the flag.
      $form_state->set('force_fresh_results', FALSE);
    }

    $form_state->set('results', $results);
    $form_state->setRebuild();
  }

  /**
   * Batch process callback for single node replacement.
   */
  public static function batchProcessSingle($nid, $search_term, $replace_term, $use_regex, $langcode, &$context) {
    // Initialize results in context.
    if (!isset($context['results']['replaced_count'])) {
      $context['results']['replaced_count'] = 0;
      $context['results']['processed_nodes'] = 0;
      $context['results']['errors'] = [];
      $context['results']['node_details'] = [];
      $context['results']['search_term'] = $search_term;
      $context['results']['replace_term'] = $replace_term;
      $context['results']['use_regex'] = $use_regex;
      $context['results']['langcode'] = $langcode;
    }
    
    $text_search_service = \Drupal::service('content_radar.search_service');
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    
    try {
      $node = $node_storage->load($nid);
      if (!$node) {
        $context['results']['errors'][] = t('Node @nid not found.', ['@nid' => $nid]);
        return;
      }
      
      $count = 0;
      $node_modified = FALSE;
      
      \Drupal::logger('content_radar')->debug('Processing node @nid for replacement. Language: @lang', [
        '@nid' => $nid,
        '@lang' => $langcode ?: 'all',
      ]);
      
      // If searching with specific language, only process that translation.
      if (!empty($langcode) && $node->hasTranslation($langcode)) {
        $translation = $node->getTranslation($langcode);
        \Drupal::logger('content_radar')->debug('Processing translation @lang of node @nid', [
          '@lang' => $langcode,
          '@nid' => $nid,
        ]);
        
        $count = $text_search_service->replaceInNode($translation, $search_term, $replace_term, $use_regex);
        if ($count > 0) {
          $translation->save();
          $node_modified = TRUE;
          \Drupal::logger('content_radar')->notice('Saved @count replacements in node @nid translation @lang', [
            '@count' => $count,
            '@nid' => $nid,
            '@lang' => $langcode,
          ]);
        }
      }
      // If no specific language, process default language first then other translations.
      elseif (empty($langcode)) {
        // Process default language
        $default_lang = $node->language()->getId();
        $count = $text_search_service->replaceInNode($node, $search_term, $replace_term, $use_regex);
        if ($count > 0) {
          $node->save();
          $node_modified = TRUE;
          \Drupal::logger('content_radar')->notice('Saved @count replacements in node @nid default language @lang', [
            '@count' => $count,
            '@nid' => $nid,
            '@lang' => $default_lang,
          ]);
        }
        
        // Process other translations
        foreach ($node->getTranslationLanguages() as $lang_code => $language) {
          if ($lang_code != $default_lang) {
            $translation = $node->getTranslation($lang_code);
            $translation_count = $text_search_service->replaceInNode($translation, $search_term, $replace_term, $use_regex);
            if ($translation_count > 0) {
              $translation->save();
              $count += $translation_count;
              $node_modified = TRUE;
              \Drupal::logger('content_radar')->notice('Saved @count replacements in node @nid translation @lang', [
                '@count' => $translation_count,
                '@nid' => $nid,
                '@lang' => $lang_code,
              ]);
            }
          }
        }
      }
      
      if ($node_modified) {
        $context['results']['replaced_count'] += $count;
        $context['message'] = t('Replaced @count occurrences in: @title', [
          '@count' => $count,
          '@title' => $node->getTitle()
        ]);
        
        // Store details for the report
        $context['results']['node_details'][$nid] = [
          'nid' => $nid,
          'title' => $node->getTitle(),
          'type' => $node->bundle(),
          'langcode' => !empty($langcode) ? $langcode : $node->language()->getId(),
          'count' => $count,
          'fields' => ['Multiple fields' => $count], // Simplified for now
        ];
      }
      
      $context['results']['processed_nodes']++;
      
    } catch (\Exception $e) {
      $context['results']['errors'][] = t('Error processing node @nid: @error', [
        '@nid' => $nid,
        '@error' => $e->getMessage(),
      ]);
      \Drupal::logger('content_radar')->error('Batch process error: @error', ['@error' => $e->getMessage()]);
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
        $messenger->addStatus(\Drupal::translation()->formatPlural(
          $results['replaced_count'],
          'Successfully replaced 1 occurrence in @node_count content items.',
          'Successfully replaced @count occurrences in @node_count content items.',
          [
            '@node_count' => $results['processed_nodes'],
          ]
        ));
        
        // Save the report to database
        try {
          $database = \Drupal::database();
          $node_details = isset($results['node_details']) ? $results['node_details'] : [];
          $rid = $database->insert('content_radar_reports')
            ->fields([
              'uid' => \Drupal::currentUser()->id(),
              'created' => \Drupal::time()->getRequestTime(),
              'search_term' => isset($results['search_term']) ? $results['search_term'] : '',
              'replace_term' => isset($results['replace_term']) ? $results['replace_term'] : '',
              'use_regex' => isset($results['use_regex']) && $results['use_regex'] ? 1 : 0,
              'langcode' => isset($results['langcode']) ? $results['langcode'] : '',
              'total_replacements' => $results['replaced_count'],
              'nodes_affected' => count($node_details),
              'details' => serialize($node_details),
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
        \Drupal::logger('content_radar')->notice('Batch replacement completed: @count replacements in @nodes nodes', [
          '@count' => $results['replaced_count'],
          '@nodes' => $results['processed_nodes'],
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