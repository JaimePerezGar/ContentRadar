<?php

namespace Drupal\content_radar\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Database\Connection;
use Drupal\content_radar\Service\TextSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a form to undo text replacements from a report.
 */
class UndoReportForm extends ConfirmFormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

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
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The report ID.
   *
   * @var int
   */
  protected $rid;

  /**
   * The report data.
   *
   * @var object
   */
  protected $report;

  /**
   * Constructs a new UndoReportForm.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\content_radar\Service\TextSearchService $text_search_service
   *   The text search service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(Connection $database, TextSearchService $text_search_service, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, AccountInterface $current_user) {
    $this->database = $database;
    $this->textSearchService = $text_search_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('content_radar.search_service'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_radar_undo_report_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to undo this replacement operation?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    if ($this->report) {
      return $this->t('This will revert the replacement of "@search" with "@replace" in @count nodes. The original text will be restored.', [
        '@search' => $this->report->search_term,
        '@replace' => $this->report->replace_term,
        '@count' => $this->report->nodes_affected,
      ]);
    }
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Undo replacements');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('content_radar.report_details', ['rid' => $this->rid]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $rid = NULL) {
    $this->rid = $rid;
    
    // Load the report.
    $this->report = $this->database->select('content_radar_reports', 'r')
      ->fields('r')
      ->condition('rid', $rid)
      ->execute()
      ->fetchObject();

    if (!$this->report) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    // Check if this report has already been undone.
    if ($this->database->select('content_radar_reports', 'r')
        ->fields('r', ['rid'])
        ->condition('details', '%"undone_from":' . $rid . '%', 'LIKE')
        ->countQuery()
        ->execute()
        ->fetchField() > 0) {
      $this->messenger()->addWarning($this->t('This replacement operation has already been undone.'));
      return $this->redirect('content_radar.report_details', ['rid' => $rid]);
    }

    $form = parent::buildForm($form, $form_state);

    // Add warning about the operation.
    $form['warning'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#weight' => -10,
    ];

    $form['warning']['message'] = [
      '#markup' => '<strong>' . $this->t('Warning:') . '</strong> ' . 
                   $this->t('This operation will modify content. Make sure you have a backup before proceeding.'),
    ];

    // Add information about the undo operation.
    $form['info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--info']],
      '#weight' => -9,
    ];

    $form['info']['message'] = [
      '#markup' => $this->t('This will search for <strong>"@search"</strong> and replace it with <strong>"@replace"</strong> to revert the original operation.', [
        '@search' => $this->report->replace_term,
        '@replace' => $this->report->search_term,
      ]),
    ];

    // Add details about what will be undone.
    $form['details'] = [
      '#type' => 'details',
      '#title' => $this->t('Select content to revert'),
      '#open' => TRUE,
      '#weight' => -5,
    ];

    $details = unserialize($this->report->details);
    if (!empty($details)) {
      // Add select all checkbox.
      $form['details']['select_all'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select all'),
        '#default_value' => TRUE,
        '#attributes' => [
          'class' => ['select-all-nodes'],
        ],
      ];

      $form['details']['nodes'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Nodes to revert'),
        '#title_display' => 'invisible',
        '#options' => [],
        '#default_value' => [],
        '#attributes' => [
          'class' => ['node-checkboxes'],
        ],
      ];

      $node_storage = $this->entityTypeManager->getStorage('node');
      
      foreach ($details as $nid => $node_data) {
        // Skip if this is metadata.
        if (!is_numeric($nid)) {
          continue;
        }
        
        // Load node to check current content.
        $node = $node_storage->load($node_data['nid']);
        if ($node) {
          // Check if the replace term still exists in the node.
          $preview_text = $this->getPreviewText($node, $this->report->replace_term, $this->report->langcode);
          
          $option_text = $this->t('@title (Node @nid)', [
            '@title' => $node_data['title'],
            '@nid' => $node_data['nid'],
          ]);
          
          if ($preview_text) {
            $option_text .= ' - ' . $this->t('Found @count occurrences', ['@count' => $preview_text['count']]);
          } else {
            $option_text .= ' - <em>' . $this->t('No occurrences found (may have been modified)') . '</em>';
          }
          
          $form['details']['nodes']['#options'][$node_data['nid']] = $option_text;
          
          // Default to checked only if text is found.
          if ($preview_text && $preview_text['count'] > 0) {
            $form['details']['nodes']['#default_value'][] = $node_data['nid'];
          }
        }
      }

      // Add JavaScript for select all functionality.
      $form['#attached']['library'][] = 'content_radar/undo-form';
    }

    return $form;
  }

  /**
   * Get preview text showing what will be reverted.
   */
  protected function getPreviewText($node, $search_term, $langcode) {
    $count = 0;
    $preview = [];
    
    // Get the appropriate translation.
    if (!empty($langcode) && $node->hasTranslation($langcode)) {
      $node = $node->getTranslation($langcode);
    }
    
    // Check title.
    $title = $node->getTitle();
    if (stripos($title, $search_term) !== FALSE) {
      $count += substr_count(strtolower($title), strtolower($search_term));
    }
    
    // Check fields.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $node->bundle());
    foreach ($field_definitions as $field_name => $field_definition) {
      if (in_array($field_definition->getType(), ['string', 'string_long', 'text', 'text_long', 'text_with_summary'])) {
        if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
          $field_values = $node->get($field_name)->getValue();
          foreach ($field_values as $value) {
            if (isset($value['value']) && stripos($value['value'], $search_term) !== FALSE) {
              $count += substr_count(strtolower($value['value']), strtolower($search_term));
            }
          }
        }
      }
    }
    
    return $count > 0 ? ['count' => $count] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Get selected nodes.
    $selected_nodes = array_filter($form_state->getValue('nodes', []));
    
    if (empty($selected_nodes)) {
      $this->messenger()->addError($this->t('Please select at least one node to revert.'));
      return;
    }
    
    // Set up batch operation for undo.
    $batch = [
      'title' => $this->t('Undoing text replacements'),
      'operations' => [],
      'finished' => '\Drupal\content_radar\Form\UndoReportForm::batchFinished',
      'init_message' => $this->t('Starting undo operation...'),
      'progress_message' => $this->t('Processed @current out of @total items.'),
      'error_message' => $this->t('An error occurred during the undo process.'),
    ];

    // Get the details of nodes to process.
    $details = unserialize($this->report->details);
    if (!empty($details)) {
      foreach ($selected_nodes as $nid) {
        if (isset($details[$nid])) {
          $node_data = $details[$nid];
        } else {
          // Try to find the node data.
          foreach ($details as $key => $data) {
            if (is_array($data) && isset($data['nid']) && $data['nid'] == $nid) {
              $node_data = $data;
              break;
            }
          }
        }
        
        if (isset($node_data)) {
          $batch['operations'][] = [
            '\Drupal\content_radar\Form\UndoReportForm::batchProcessUndo',
            [
              $nid,
              $this->report->replace_term, // This becomes the search term
              $this->report->search_term,  // This becomes the replace term (reverting)
              $this->report->use_regex,
              $this->report->langcode,
              $this->rid,
            ],
          ];
        }
      }
    }

    batch_set($batch);
    
    // Store the report info in session for the batch finished callback.
    $session = \Drupal::request()->getSession();
    $session->set('content_radar_undo_report', [
      'rid' => $this->rid,
      'search_term' => $this->report->search_term,
      'replace_term' => $this->report->replace_term,
      'use_regex' => $this->report->use_regex,
      'langcode' => $this->report->langcode,
      'selected_nodes' => count($selected_nodes),
      'total_nodes' => count($details),
    ]);
  }

  /**
   * Batch process callback for undoing replacements.
   */
  public static function batchProcessUndo($nid, $search_term, $replace_term, $use_regex, $langcode, $original_rid, &$context) {
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
      $context['results']['original_rid'] = $original_rid;
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

      \Drupal::logger('content_radar')->notice('UNDO: Processing node @nid. Searching for "@search" to replace with "@replace". Language: "@lang"', [
        '@nid' => $nid,
        '@search' => $search_term,
        '@replace' => $replace_term,
        '@lang' => $langcode ?: 'all',
      ]);

      // First, let's check if the search term exists in the node
      $preview_count = 0;
      if (!empty($langcode) && $node->hasTranslation($langcode)) {
        $check_node = $node->getTranslation($langcode);
      } else {
        $check_node = $node;
      }
      
      // Check title
      $title = $check_node->getTitle();
      if (stripos($title, $search_term) !== FALSE) {
        $preview_count += substr_count(strtolower($title), strtolower($search_term));
      }
      
      \Drupal::logger('content_radar')->debug('UNDO: Found @count occurrences of "@search" in node @nid before replacement', [
        '@count' => $preview_count,
        '@search' => $search_term,
        '@nid' => $nid,
      ]);

      // Process based on language.
      if (!empty($langcode) && $node->hasTranslation($langcode)) {
        $translation = $node->getTranslation($langcode);
        \Drupal::logger('content_radar')->debug('UNDO: Processing specific language translation: @lang', ['@lang' => $langcode]);
        
        $count = $text_search_service->replaceInNode($translation, $search_term, $replace_term, $use_regex);
        if ($count > 0) {
          $translation->save();
          $node_modified = TRUE;
          \Drupal::logger('content_radar')->notice('UNDO: Successfully replaced @count occurrences in node @nid (@lang)', [
            '@count' => $count,
            '@nid' => $nid,
            '@lang' => $langcode,
          ]);
        } else {
          \Drupal::logger('content_radar')->warning('UNDO: No replacements made in node @nid (@lang)', [
            '@nid' => $nid,
            '@lang' => $langcode,
          ]);
        }
      }
      elseif (empty($langcode)) {
        // Process all translations
        \Drupal::logger('content_radar')->debug('UNDO: Processing all languages');
        
        foreach ($node->getTranslationLanguages() as $lang_code => $language) {
          $translation = $node->getTranslation($lang_code);
          $translation_count = $text_search_service->replaceInNode($translation, $search_term, $replace_term, $use_regex);
          
          if ($translation_count > 0) {
            $translation->save();
            $count += $translation_count;
            $node_modified = TRUE;
            \Drupal::logger('content_radar')->notice('UNDO: Replaced @count occurrences in node @nid (@lang)', [
              '@count' => $translation_count,
              '@nid' => $nid,
              '@lang' => $lang_code,
            ]);
          }
        }
        
        if ($count == 0) {
          \Drupal::logger('content_radar')->warning('UNDO: No replacements made in any language for node @nid', ['@nid' => $nid]);
        }
      }

      if ($node_modified) {
        $context['results']['replaced_count'] += $count;
        $context['message'] = t('Reverted @count replacements in: @title', [
          '@count' => $count,
          '@title' => $node->getTitle()
        ]);

        // Store details for the undo report.
        $context['results']['node_details'][$nid] = [
          'nid' => $nid,
          'title' => $node->getTitle(),
          'type' => $node->bundle(),
          'langcode' => !empty($langcode) ? $langcode : $node->language()->getId(),
          'count' => $count,
          'fields' => ['Multiple fields' => $count],
        ];
      }

      $context['results']['processed_nodes']++;

    } catch (\Exception $e) {
      $context['results']['errors'][] = t('Error processing node @nid: @error', [
        '@nid' => $nid,
        '@error' => $e->getMessage(),
      ]);
      \Drupal::logger('content_radar')->error('Undo process error: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    $messenger = \Drupal::messenger();
    
    if ($success) {
      if (!empty($results['replaced_count'])) {
        // Get session data for additional info
        $session = \Drupal::request()->getSession();
        $undo_info = $session->get('content_radar_undo_report');
        
        $message = \Drupal::translation()->formatPlural(
          $results['replaced_count'],
          'Successfully reverted 1 replacement in @node_count content items.',
          'Successfully reverted @count replacements in @node_count content items.',
          [
            '@node_count' => $results['processed_nodes'],
          ]
        );
        
        if ($undo_info && isset($undo_info['selected_nodes']) && isset($undo_info['total_nodes'])) {
          if ($undo_info['selected_nodes'] < $undo_info['total_nodes']) {
            $message .= ' ' . t('(@selected of @total nodes were selected for reverting)', [
              '@selected' => $undo_info['selected_nodes'],
              '@total' => $undo_info['total_nodes'],
            ]);
          }
        }
        
        $messenger->addStatus($message);

        // Save the undo operation as a new report.
        try {
          $database = \Drupal::database();
          $node_details = isset($results['node_details']) ? $results['node_details'] : [];
          
          // Add reference to the original report in the details.
          $details_with_undo = $node_details;
          $details_with_undo['undone_from'] = $results['original_rid'];
          
          $rid = $database->insert('content_radar_reports')
            ->fields([
              'uid' => \Drupal::currentUser()->id(),
              'created' => \Drupal::time()->getRequestTime(),
              'search_term' => $results['search_term'],
              'replace_term' => $results['replace_term'],
              'use_regex' => $results['use_regex'] ? 1 : 0,
              'langcode' => isset($results['langcode']) ? $results['langcode'] : '',
              'total_replacements' => $results['replaced_count'],
              'nodes_affected' => count($node_details),
              'details' => serialize($details_with_undo),
            ])
            ->execute();

          $report_url = \Drupal\Core\Url::fromRoute('content_radar.report_details', ['rid' => $rid]);
          $messenger->addStatus(t('The undo operation has been saved as a new report. <a href="@url">View report</a>', [
            '@url' => $report_url->toString(),
          ]));
        } catch (\Exception $e) {
          \Drupal::logger('content_radar')->error('Failed to save undo report: @error', ['@error' => $e->getMessage()]);
        }

        // Clear caches.
        \Drupal::cache()->deleteAll();
      } else {
        $messenger->addWarning(t('No replacements were reverted.'));
      }

      // Show any errors.
      if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
          $messenger->addError($error);
        }
      }
    } else {
      $messenger->addError(t('The undo process encountered an error.'));
    }

    // Clear session data.
    $session = \Drupal::request()->getSession();
    $session->remove('content_radar_undo_report');
    
    // Clear the destination parameter.
    \Drupal::request()->query->remove('destination');
  }

}