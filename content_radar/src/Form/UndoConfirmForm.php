<?php

namespace Drupal\content_radar\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Database\Connection;
use Drupal\content_radar\Service\TextSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides a confirmation form for undo operations.
 */
class UndoConfirmForm extends ConfirmFormBase {

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
   * Selected nodes.
   *
   * @var array
   */
  protected $selectedNodes;

  /**
   * Constructs a new UndoConfirmForm.
   */
  public function __construct(Connection $database, TextSearchService $text_search_service, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->textSearchService = $text_search_service;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('content_radar.search_service'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_radar_undo_confirm_form';
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
    $count = count($this->selectedNodes);
    return $this->t('This will revert replacements in @count nodes by searching for "@search" and replacing it with "@replace".', [
      '@count' => $count,
      '@search' => $this->report->replace_term,
      '@replace' => $this->report->search_term,
    ]);
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
    return new Url('content_radar.report_undo', ['rid' => $this->rid]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $rid = NULL) {
    $this->rid = $rid;
    
    // Get selected nodes from session.
    $session = \Drupal::request()->getSession();
    $this->selectedNodes = $session->get('content_radar_undo_nodes', []);
    
    if (empty($this->selectedNodes)) {
      $this->messenger()->addError($this->t('No nodes selected.'));
      return $this->redirect('content_radar.report_undo', ['rid' => $rid]);
    }
    
    // Load the report.
    $this->report = $this->database->select('content_radar_reports', 'r')
      ->fields('r')
      ->condition('rid', $rid)
      ->execute()
      ->fetchObject();

    if (!$this->report) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Set up batch.
    $batch = [
      'title' => $this->t('Undoing replacements'),
      'operations' => [],
      'finished' => '\Drupal\content_radar\Form\UndoConfirmForm::batchFinished',
      'init_message' => $this->t('Starting undo operation...'),
      'progress_message' => $this->t('Processing @current of @total nodes...'),
      'error_message' => $this->t('An error occurred during the undo operation.'),
    ];

    // Create operations for each selected node.
    foreach ($this->selectedNodes as $nid) {
      $batch['operations'][] = [
        '\Drupal\content_radar\Form\UndoConfirmForm::processNode',
        [
          $nid,
          $this->report->replace_term,
          $this->report->search_term,
          $this->report->use_regex,
          $this->report->langcode,
          $this->rid,
        ],
      ];
    }

    batch_set($batch);
    
    // Clear session.
    $session = \Drupal::request()->getSession();
    $session->remove('content_radar_undo_nodes');
  }

  /**
   * Batch operation: Process a single node.
   */
  public static function processNode($nid, $search_term, $replace_term, $use_regex, $langcode, $original_rid, &$context) {
    // Initialize results.
    if (!isset($context['results']['total_replacements'])) {
      $context['results']['total_replacements'] = 0;
      $context['results']['nodes_processed'] = 0;
      $context['results']['nodes_modified'] = [];
      $context['results']['errors'] = [];
      $context['results']['original_rid'] = $original_rid;
      $context['results']['search_term'] = $search_term;
      $context['results']['replace_term'] = $replace_term;
      $context['results']['use_regex'] = $use_regex;
      $context['results']['langcode'] = $langcode;
    }

    try {
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $node = $node_storage->load($nid);
      
      if (!$node) {
        $context['results']['errors'][] = t('Node @nid not found.', ['@nid' => $nid]);
        return;
      }

      $text_search_service = \Drupal::service('content_radar.search_service');
      $replacements = 0;
      
      // Process based on language.
      if (!empty($langcode) && $node->hasTranslation($langcode)) {
        // Process specific language.
        $translation = $node->getTranslation($langcode);
        $replacements = $text_search_service->replaceInNode($translation, $search_term, $replace_term, $use_regex);
        if ($replacements > 0) {
          $translation->save();
        }
      } else {
        // Process all translations.
        foreach ($node->getTranslationLanguages() as $lang_code => $language) {
          $translation = $node->getTranslation($lang_code);
          $lang_replacements = $text_search_service->replaceInNode($translation, $search_term, $replace_term, $use_regex);
          if ($lang_replacements > 0) {
            $translation->save();
            $replacements += $lang_replacements;
          }
        }
      }
      
      $context['results']['nodes_processed']++;
      
      if ($replacements > 0) {
        $context['results']['total_replacements'] += $replacements;
        $context['results']['nodes_modified'][$nid] = [
          'nid' => $nid,
          'title' => $node->getTitle(),
          'type' => $node->bundle(),
          'langcode' => $node->language()->getId(),
          'count' => $replacements,
        ];
        
        $context['message'] = t('Reverted @count replacements in: @title', [
          '@count' => $replacements,
          '@title' => $node->getTitle(),
        ]);
        
        \Drupal::logger('content_radar')->notice('Undo: Reverted @count replacements in node @nid', [
          '@count' => $replacements,
          '@nid' => $nid,
        ]);
      } else {
        \Drupal::logger('content_radar')->warning('Undo: No replacements found in node @nid', [
          '@nid' => $nid,
        ]);
      }
      
    } catch (\Exception $e) {
      $context['results']['errors'][] = t('Error processing node @nid: @error', [
        '@nid' => $nid,
        '@error' => $e->getMessage(),
      ]);
      \Drupal::logger('content_radar')->error('Undo error: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      if ($results['total_replacements'] > 0) {
        // Save undo report.
        try {
          $database = \Drupal::database();
          $details = $results['nodes_modified'];
          $details['undone_from'] = $results['original_rid'];
          
          $rid = $database->insert('content_radar_reports')
            ->fields([
              'uid' => \Drupal::currentUser()->id(),
              'created' => \Drupal::time()->getRequestTime(),
              'search_term' => $results['search_term'],
              'replace_term' => $results['replace_term'],
              'use_regex' => $results['use_regex'] ? 1 : 0,
              'langcode' => $results['langcode'] ?: '',
              'total_replacements' => $results['total_replacements'],
              'nodes_affected' => count($results['nodes_modified']),
              'details' => serialize($details),
            ])
            ->execute();
          
          \Drupal::messenger()->addStatus(t('Successfully reverted @total replacements in @count nodes.', [
            '@total' => $results['total_replacements'],
            '@count' => count($results['nodes_modified']),
          ]));
          
          $url = Url::fromRoute('content_radar.report_details', ['rid' => $rid]);
          \Drupal::messenger()->addStatus(t('A report of this undo operation has been saved. <a href="@url">View report</a>', [
            '@url' => $url->toString(),
          ]));
        } catch (\Exception $e) {
          \Drupal::logger('content_radar')->error('Failed to save undo report: @error', ['@error' => $e->getMessage()]);
        }
      } else {
        \Drupal::messenger()->addWarning(t('No replacements were made. The content may have been modified since the original operation.'));
      }
      
      // Show errors.
      if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
          \Drupal::messenger()->addError($error);
        }
      }
    } else {
      \Drupal::messenger()->addError(t('The undo operation failed.'));
    }
    
    // Clear cache.
    \Drupal::cache()->deleteAll();
  }

}