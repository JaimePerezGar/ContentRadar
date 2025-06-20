<?php

namespace Drupal\content_radar\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\content_radar\Service\TextSearchService;

/**
 * Provides a confirmation form for undoing content radar changes.
 */
class UndoConfirmForm extends ConfirmFormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The text search service.
   *
   * @var \Drupal\content_radar\Service\TextSearchService
   */
  protected $textSearchService;

  /**
   * The report ID.
   *
   * @var int
   */
  protected $rid;

  /**
   * The report data.
   *
   * @var array
   */
  protected $report;

  /**
   * Constructs a new UndoConfirmForm.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, TextSearchService $text_search_service) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->textSearchService = $text_search_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('content_radar.search_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_radar_undo_confirm';
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
      ->fetchAssoc();

    if (!$this->report) {
      $this->messenger()->addError($this->t('Report not found.'));
      return $this->redirect('content_radar.reports');
    }

    $details = unserialize($this->report['details']);
    
    $form['info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
    ];

    $form['info']['message'] = [
      '#markup' => '<p>' . $this->t('This will revert all @count replacements made in this report, changing "@replace" back to "@search" in @entities entities.', [
        '@count' => $this->report['total_replacements'],
        '@replace' => $this->report['replace_term'],
        '@search' => $this->report['search_term'],
        '@entities' => $this->report['affected_entities'],
      ]) . '</p>',
    ];

    $form['entities'] = [
      '#type' => 'details',
      '#title' => $this->t('Affected entities'),
      '#open' => FALSE,
    ];

    $items = [];
    foreach ($details as $entity_info) {
      $items[] = $this->t('@type: @title (ID: @id)', [
        '@type' => $entity_info['entity_type'],
        '@title' => $entity_info['title'],
        '@id' => $entity_info['id'],
      ]);
    }

    $form['entities']['list'] = [
      '#theme' => 'item_list',
      '#items' => $items,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to undo these changes?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('content_radar.report_detail', ['rid' => $this->rid]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action cannot be undone after confirmation.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Undo changes');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $details = unserialize($this->report['details']);

    // Set up batch process for undo operation.
    $batch = [
      'title' => $this->t('Processing undo operation'),
      'operations' => [],
      'init_message' => $this->t('Starting undo operation...'),
      'progress_message' => $this->t('Processed @current out of @total entities.'),
      'error_message' => $this->t('An error occurred during undo processing.'),
      'finished' => '\Drupal\content_radar\Form\UndoConfirmForm::batchFinished',
    ];

    // Process entities in chunks.
    $chunks = array_chunk($details, 10);
    
    foreach ($chunks as $chunk) {
      $batch['operations'][] = [
        '\Drupal\content_radar\Form\UndoConfirmForm::batchProcess',
        [
          $chunk,
          $this->report['replace_term'],
          $this->report['search_term'],
          (bool) $this->report['use_regex'],
          $this->report['langcode'],
          $this->rid,
        ],
      ];
    }

    batch_set($batch);
  }

  /**
   * Batch process callback for undo operations.
   */
  public static function batchProcess($chunk, $replace_term, $search_term, $use_regex, $langcode, $original_rid, &$context) {
    $text_search_service = \Drupal::service('content_radar.search_service');
    $entity_type_manager = \Drupal::entityTypeManager();
    
    // Initialize context results.
    if (!isset($context['results']['undone_count'])) {
      $context['results']['undone_count'] = 0;
      $context['results']['failed_count'] = 0;
      $context['results']['affected_entities'] = [];
      $context['results']['search_term'] = $search_term;
      $context['results']['replace_term'] = $replace_term;
      $context['results']['use_regex'] = $use_regex;
      $context['results']['langcode'] = $langcode;
      $context['results']['original_rid'] = $original_rid;
    }
    
    // Process each entity in the chunk.
    foreach ($chunk as $entity_info) {
      try {
        $entity = $entity_type_manager
          ->getStorage($entity_info['entity_type'])
          ->load($entity_info['id']);

        if ($entity) {
          // Use the service to replace back (undo the original replacement).
          $result = $text_search_service->replaceText(
            $replace_term,
            $search_term,
            $use_regex,
            [$entity_info['entity_type']],
            [],
            $entity_info['langcode'],
            FALSE,
            []
          );

          if ($result['replaced_count'] > 0) {
            $context['results']['undone_count']++;
            $context['results']['affected_entities'] = array_merge(
              $context['results']['affected_entities'],
              $result['affected_entities']
            );
          }
        }
      }
      catch (\Exception $e) {
        $context['results']['failed_count']++;
        \Drupal::logger('content_radar')->error('Failed to undo changes for entity @type:@id: @message', [
          '@type' => $entity_info['entity_type'],
          '@id' => $entity_info['id'],
          '@message' => $e->getMessage(),
        ]);
      }
      
      // Update progress message.
      $context['message'] = t('Undoing changes in: @title', [
        '@title' => isset($entity_info['title']) ? $entity_info['title'] : $entity_info['id'],
      ]);
    }
  }

  /**
   * Batch finished callback for undo operations.
   */
  public static function batchFinished($success, $results, $operations) {
    $database = \Drupal::database();
    
    if ($success && !empty($results['undone_count'])) {
      // Create a new report for the undo operation.
      $new_rid = $database->insert('content_radar_reports')
        ->fields([
          'uid' => \Drupal::currentUser()->id(),
          'created' => \Drupal::time()->getRequestTime(),
          'search_term' => $results['replace_term'], // What we searched for (original replace term)
          'replace_term' => $results['search_term'], // What we replaced it with (original search term)
          'use_regex' => $results['use_regex'] ? 1 : 0,
          'langcode' => $results['langcode'],
          'total_replacements' => $results['undone_count'],
          'affected_entities' => count($results['affected_entities']),
          'details' => serialize($results['affected_entities']),
        ])
        ->execute();

      // Mark the original report as undone.
      $database->update('content_radar_reports')
        ->fields(['details' => serialize(['undone' => TRUE, 'undone_time' => time(), 'undo_report_id' => $new_rid])])
        ->condition('rid', $results['original_rid'])
        ->execute();

      \Drupal::messenger()->addStatus(t('Successfully undone changes in @count entities. A new report has been created.', [
        '@count' => $results['undone_count'],
      ]));

      if (!empty($results['failed_count'])) {
        \Drupal::messenger()->addWarning(t('Failed to undo changes in @count entities.', [
          '@count' => $results['failed_count'],
        ]));
      }

      // Redirect to the new report.
      $url = \Drupal\Core\Url::fromRoute('content_radar.report_detail', ['rid' => $new_rid]);
      $redirect = new \Symfony\Component\HttpFoundation\RedirectResponse($url->toString());
      $redirect->send();
    }
    else {
      \Drupal::messenger()->addError(t('An error occurred during the undo process or no changes were undone.'));
      
      // Redirect to reports list.
      $url = \Drupal\Core\Url::fromRoute('content_radar.reports');
      $redirect = new \Symfony\Component\HttpFoundation\RedirectResponse($url->toString());
      $redirect->send();
    }
  }

}