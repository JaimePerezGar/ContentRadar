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
      '#description' => $this->t('Select the language to search in.'),
      '#options' => $language_options,
      '#default_value' => $form_state->getValue('langcode', ''),
    ];

    // Entity types selection.
    $form['entity_types_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Entity types'),
      '#open' => FALSE,
      '#description' => $this->t('Select entity types to search in. Leave unchecked to search all.'),
    ];

    $entity_type_options = [
      'node' => $this->t('Content'),
      'block_content' => $this->t('Custom blocks'),
      'taxonomy_term' => $this->t('Taxonomy terms'),
      'user' => $this->t('Users'),
      'media' => $this->t('Media'),
      'menu_link_content' => $this->t('Menu links'),
      'comment' => $this->t('Comments'),
    ];

    // Filter out non-existent entity types.
    $definitions = $this->entityTypeManager->getDefinitions();
    foreach ($entity_type_options as $entity_type_id => $label) {
      if (!isset($definitions[$entity_type_id])) {
        unset($entity_type_options[$entity_type_id]);
      }
    }

    $form['entity_types_container']['entity_types'] = [
      '#type' => 'checkboxes',
      '#options' => $entity_type_options,
      '#default_value' => $form_state->getValue(['entity_types_container', 'entity_types'], []),
    ];

    // Content types selection.
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $options = [];
    foreach ($content_types as $type) {
      $options[$type->id()] = $type->label();
    }

    $form['content_types_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Content types'),
      '#open' => FALSE,
      '#description' => $this->t('Select specific content types to search in.'),
      '#states' => [
        'visible' => [
          ':input[name="entity_types[node]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['content_types_container']['content_types'] = [
      '#type' => 'checkboxes',
      '#options' => $options,
      '#default_value' => $form_state->getValue(['content_types_container', 'content_types'], []),
    ];

    // Replace functionality.
    $results = $form_state->get('results');
    if ($this->currentUser->hasPermission('replace content radar') && $results && $results['total'] > 0) {
      $form['replace_container'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Find and Replace'),
        '#attributes' => ['class' => ['messages', 'messages--warning']],
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
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $search_term = $form_state->getValue('search_term');
    $use_regex = $form_state->getValue('use_regex');
    $langcode = $form_state->getValue('langcode');
    $entity_types = array_filter($form_state->getValue(['entity_types_container', 'entity_types'], []));
    $content_types = array_filter($form_state->getValue(['content_types_container', 'content_types'], []));

    // Get current page from query parameter.
    $page = \Drupal::request()->query->get('page', 0);

    // Perform search.
    $results = $this->textSearchService->search(
      $search_term,
      $use_regex,
      array_keys($entity_types),
      array_keys($content_types),
      $langcode,
      $page,
      50
    );

    // Store results in form state.
    $form_state->set('results', $results);
    $form_state->setRebuild();

    // Log the search.
    $this->logSearch($search_term, $use_regex, $entity_types, $content_types, $results['total']);

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
    $use_regex = $form_state->getValue('use_regex');
    $langcode = $form_state->getValue('langcode');
    $entity_types = array_filter($form_state->getValue(['entity_types_container', 'entity_types'], []));
    $content_types = array_filter($form_state->getValue(['content_types_container', 'content_types'], []));
    $replace_mode = $form_state->getValue('replace_mode', 'selected');

    // Get selected items.
    $selected_items = [];
    if ($replace_mode === 'selected') {
      $selected_items = $form_state->getValue('selected_items', []);
      $selected_items = array_filter($selected_items);
    }

    // Perform replacement.
    try {
      $result = $this->textSearchService->replaceText(
        $search_term,
        $replace_term,
        $use_regex,
        array_keys($entity_types),
        array_keys($content_types),
        $langcode,
        FALSE,
        $selected_items
      );

      if ($result['replaced_count'] > 0) {
        // Save report.
        $rid = $this->saveReport(
          $search_term,
          $replace_term,
          $use_regex,
          $langcode,
          $result['replaced_count'],
          $result['affected_entities']
        );

        $this->messenger()->addStatus($this->t('Successfully replaced @count occurrences in @entities entities.', [
          '@count' => $result['replaced_count'],
          '@entities' => count($result['affected_entities']),
        ]));

        // Redirect to report.
        $form_state->setRedirect('content_radar.report_detail', ['rid' => $rid]);
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
  protected function logSearch($search_term, $use_regex, $entity_types, $content_types, $results_count) {
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

}