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
    $undone_count = 0;
    $failed_count = 0;

    // Perform the undo by replacing back.
    foreach ($details as $entity_info) {
      try {
        $entity = $this->entityTypeManager
          ->getStorage($entity_info['entity_type'])
          ->load($entity_info['id']);

        if ($entity) {
          // Use the service to replace back.
          $result = $this->textSearchService->replaceText(
            $this->report['replace_term'],
            $this->report['search_term'],
            (bool) $this->report['use_regex'],
            [$entity_info['entity_type']],
            [],
            $entity_info['langcode'],
            FALSE,
            []
          );

          if ($result['replaced_count'] > 0) {
            $undone_count++;
          }
        }
      }
      catch (\Exception $e) {
        $failed_count++;
        \Drupal::logger('content_radar')->error('Failed to undo changes for entity @type:@id: @message', [
          '@type' => $entity_info['entity_type'],
          '@id' => $entity_info['id'],
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Update the report to mark as undone.
    $this->database->update('content_radar_reports')
      ->fields(['details' => serialize(['undone' => TRUE, 'undone_time' => time()])])
      ->condition('rid', $this->rid)
      ->execute();

    if ($undone_count > 0) {
      $this->messenger()->addStatus($this->t('Successfully undone changes in @count entities.', [
        '@count' => $undone_count,
      ]));
    }

    if ($failed_count > 0) {
      $this->messenger()->addWarning($this->t('Failed to undo changes in @count entities.', [
        '@count' => $failed_count,
      ]));
    }

    $form_state->setRedirect('content_radar.reports');
  }

}