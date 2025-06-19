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

    $form['content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types'),
      '#description' => $this->t('Select content types to search in. Leave empty to search all.'),
      '#options' => $options,
      '#default_value' => $form_state->getValue('content_types', []),
    ];

    // Replace functionality - only show if user has permission.
    if ($this->currentUser->hasPermission('replace content radar')) {
      $form['replace_container'] = [
        '#type' => 'details',
        '#title' => $this->t('Find and Replace'),
        '#open' => FALSE,
        '#attributes' => ['class' => ['content-radar-replace-container']],
      ];
      
      $form['replace_container']['replace_term'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Replace with'),
        '#description' => $this->t('Enter the text to replace matches with. Leave empty to only search.'),
        '#size' => 60,
        '#maxlength' => 255,
        '#default_value' => $form_state->getValue('replace_term', ''),
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
    
    if ($this->currentUser->hasPermission('replace content radar')) {
      $form['actions']['preview_replace'] = [
        '#type' => 'submit',
        '#value' => $this->t('Preview Replace'),
        '#submit' => ['::previewReplace'],
        '#states' => [
          'visible' => [
            ':input[name="replace_term"]' => ['filled' => TRUE],
          ],
        ],
      ];
      
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
            'content_types' => implode(',', array_filter($form_state->getValue('content_types', []))),
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
    $content_types = array_filter($form_state->getValue('content_types', []));
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
    $content_types = array_filter($form_state->getValue('content_types', []));
    $langcode = $form_state->getValue('langcode', '');
    
    if (!$form_state->getValue('replace_confirm')) {
      $this->messenger()->addError($this->t('You must confirm the replacement action.'));
      $form_state->setRebuild();
      return;
    }
    
    try {
      // Perform the actual replacement.
      $result = $this->textSearchService->replaceText($search_term, $replace_term, $use_regex, $content_types, $langcode, FALSE);
      
      if ($result['replaced_count'] > 0) {
        $this->messenger()->addStatus($this->formatPlural(
          $result['replaced_count'],
          'Successfully replaced 1 occurrence in @node_count content item.',
          'Successfully replaced @count occurrences in @node_count content items.',
          ['@node_count' => count($result['affected_nodes'])]
        ));
        
        // Log the replacement action.
        \Drupal::logger('content_radar')->notice('Replaced "@search" with "@replace" - @count occurrences in @nodes nodes', [
          '@search' => $search_term,
          '@replace' => $replace_term,
          '@count' => $result['replaced_count'],
          '@nodes' => count($result['affected_nodes']),
        ]);
        
        // Clear caches.
        $this->cache->deleteAll();
      } else {
        $this->messenger()->addWarning($this->t('No matches found for replacement.'));
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error during replacement: @message', ['@message' => $e->getMessage()]));
    }
    
    // Clear the form.
    $form_state->setValues([]);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $search_term = $form_state->getValue('search_term');
    $use_regex = $form_state->getValue('use_regex');
    $content_types = array_filter($form_state->getValue('content_types', []));
    $langcode = $form_state->getValue('langcode', '');

    // Create cache key.
    $cache_key = 'content_radar:' . md5($search_term . ':' . ($use_regex ? '1' : '0') . ':' . implode(',', $content_types) . ':' . $langcode);
    
    // Check cache.
    $cache = $this->cache->get($cache_key);
    if ($cache && $cache->data) {
      $results = $cache->data;
    } else {
      // Perform search.
      $results = $this->textSearchService->search($search_term, $use_regex, $content_types, $langcode);
      
      // Cache results for 15 minutes.
      $this->cache->set($cache_key, $results, time() + 900);
    }

    $form_state->set('results', $results);
    $form_state->setRebuild();
  }

}