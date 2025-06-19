<?php

namespace Drupal\content_radar\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\content_radar\Service\TextSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides a simple form to undo text replacements.
 */
class SimpleUndoForm extends FormBase {

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
   * Constructs a new SimpleUndoForm.
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
    return 'content_radar_simple_undo_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $rid = NULL) {
    // Load the report.
    $report = $this->database->select('content_radar_reports', 'r')
      ->fields('r')
      ->condition('rid', $rid)
      ->execute()
      ->fetchObject();

    if (!$report) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    // Store report data.
    $form_state->set('report', $report);
    $form_state->set('rid', $rid);

    // Add CSS and JavaScript.
    $form['#attached']['library'][] = 'content_radar/undo-form';

    $form['info'] = [
      '#markup' => '<div class="messages messages--warning">' .
                   '<strong>' . $this->t('Undo Operation') . '</strong><br>' .
                   $this->t('This will search for "@search" and replace it with "@replace" in selected nodes.', [
                     '@search' => $report->replace_term,
                     '@replace' => $report->search_term,
                   ]) . '</div>',
    ];

    // Get nodes from report details.
    $details = unserialize($report->details);
    
    if (!empty($details)) {
      $form['nodes'] = [
        '#type' => 'details',
        '#title' => $this->t('Select nodes to revert'),
        '#open' => TRUE,
      ];

      // Add select all checkbox.
      $form['nodes']['select_all'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select all nodes'),
        '#attributes' => ['class' => ['select-all-nodes']],
      ];

      $form['nodes']['node_list'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Nodes'),
        '#title_display' => 'invisible',
        '#options' => [],
        '#default_value' => [],
        '#attributes' => ['class' => ['node-checkboxes']],
      ];

      $node_storage = $this->entityTypeManager->getStorage('node');
      
      foreach ($details as $key => $node_data) {
        if (!is_numeric($key) || !isset($node_data['nid'])) {
          continue;
        }
        
        $node = $node_storage->load($node_data['nid']);
        if ($node) {
          // Check if the replace term still exists in the node.
          $preview = $this->getNodePreview($node, $report->replace_term, $report->langcode);
          
          $label = $this->t('@title (Node @nid, @type)', [
            '@title' => $node->getTitle(),
            '@nid' => $node->id(),
            '@type' => $node->bundle(),
          ]);
          
          if ($preview['found']) {
            $label .= ' - ' . $this->t('<span class="color-success">✓ @count occurrences found</span>', [
              '@count' => $preview['count'],
            ]);
            $form['nodes']['node_list']['#default_value'][] = $node_data['nid'];
          } else {
            $label .= ' - ' . $this->t('<span class="color-warning">⚠ No occurrences found</span>');
          }
          
          $form['nodes']['node_list']['#options'][$node_data['nid']] = $label;
        }
      }
      
      if (empty($form['nodes']['node_list']['#options'])) {
        $form['nodes']['empty'] = [
          '#markup' => '<p>' . $this->t('No nodes found in the report.') . '</p>',
        ];
      }
    }

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand this will modify content'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Undo replacements'),
      '#button_type' => 'danger',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('content_radar.report_details', ['rid' => $rid]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * Check if node contains the search term.
   */
  protected function getNodePreview($node, $search_term, $langcode) {
    $count = 0;
    
    // Get appropriate translation.
    if (!empty($langcode) && $node->hasTranslation($langcode)) {
      $node = $node->getTranslation($langcode);
    }
    
    // Check title.
    $title = $node->getTitle();
    if (stripos($title, $search_term) !== FALSE) {
      $count += substr_count(strtolower($title), strtolower($search_term));
    }
    
    // Check fields.
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $node->bundle());
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
    
    return [
      'found' => $count > 0,
      'count' => $count,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $report = $form_state->get('report');
    $rid = $form_state->get('rid');
    
    // Get selected nodes.
    $selected_nodes = array_filter($form_state->getValue('node_list', []));
    
    if (empty($selected_nodes)) {
      $this->messenger()->addError($this->t('Please select at least one node to revert.'));
      return;
    }
    
    $total_replacements = 0;
    $nodes_modified = 0;
    $errors = [];
    $modified_nodes = [];
    
    foreach ($selected_nodes as $nid) {
        try {
          $node = $this->entityTypeManager->getStorage('node')->load($nid);
          if (!$node) {
            $errors[] = $this->t('Node @nid not found.', ['@nid' => $nid]);
            continue;
          }
          
          // Perform undo replacement.
          $count = 0;
          
          // Process based on language.
          if (!empty($report->langcode) && $node->hasTranslation($report->langcode)) {
            $translation = $node->getTranslation($report->langcode);
            $count = $this->textSearchService->replaceInNode(
              $translation, 
              $report->replace_term,  // Search for what was replaced
              $report->search_term,   // Replace with original
              $report->use_regex
            );
            if ($count > 0) {
              $translation->save();
              $nodes_modified++;
              $total_replacements += $count;
            }
          } else {
            // Process all translations.
            foreach ($node->getTranslationLanguages() as $lang_code => $language) {
              $translation = $node->getTranslation($lang_code);
              $lang_count = $this->textSearchService->replaceInNode(
                $translation,
                $report->replace_term,  // Search for what was replaced
                $report->search_term,   // Replace with original
                $report->use_regex
              );
              if ($lang_count > 0) {
                $translation->save();
                $count += $lang_count;
              }
            }
            
            if ($count > 0) {
              $nodes_modified++;
              $total_replacements += $count;
              $modified_nodes[$nid] = [
                'nid' => $nid,
                'title' => $node->getTitle(),
                'type' => $node->bundle(),
                'langcode' => $node->language()->getId(),
                'count' => $count,
              ];
            }
          }
          
        } catch (\Exception $e) {
          $errors[] = $this->t('Error processing node @nid: @error', [
            '@nid' => $nid,
            '@error' => $e->getMessage(),
          ]);
        }
    }
    
    // Show results.
    if ($total_replacements > 0) {
      $this->messenger()->addStatus($this->t('Successfully reverted @total replacements in @count nodes.', [
        '@total' => $total_replacements,
        '@count' => $nodes_modified,
      ]));
      
      // Save undo report.
      try {
        $details = $modified_nodes;
        $details['undone_from'] = $rid;
        
        $undo_rid = $this->database->insert('content_radar_reports')
          ->fields([
            'uid' => \Drupal::currentUser()->id(),
            'created' => \Drupal::time()->getRequestTime(),
            'search_term' => $report->replace_term,
            'replace_term' => $report->search_term,
            'use_regex' => $report->use_regex,
            'langcode' => $report->langcode ?: '',
            'total_replacements' => $total_replacements,
            'nodes_affected' => $nodes_modified,
            'details' => serialize($details),
          ])
          ->execute();
          
        $this->messenger()->addStatus($this->t('Undo operation saved as report #@rid.', ['@rid' => $undo_rid]));
      } catch (\Exception $e) {
        \Drupal::logger('content_radar')->error('Failed to save undo report: @error', ['@error' => $e->getMessage()]);
      }
    } else {
      $this->messenger()->addWarning($this->t('No replacements were reverted. The content may have been modified since the original operation.'));
    }
    
    // Show errors.
    foreach ($errors as $error) {
      $this->messenger()->addError($error);
    }
    
    // Clear cache.
    drupal_flush_all_caches();
    
    // Redirect back.
    $form_state->setRedirect('content_radar.report_details', ['rid' => $rid]);
  }

}