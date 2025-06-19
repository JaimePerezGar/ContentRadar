<?php

namespace Drupal\content_radar\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Form for selecting nodes to undo replacements.
 */
class UndoSelectForm extends FormBase {

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
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

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
   * Constructs a new UndoSelectForm.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, DateFormatterInterface $date_formatter) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_radar_undo_select_form';
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

    // Report summary.
    $user = $this->entityTypeManager->getStorage('user')->load($this->report->uid);
    $username = $user ? $user->getDisplayName() : $this->t('Anonymous');

    $form['summary'] = [
      '#type' => 'details',
      '#title' => $this->t('Operation Summary'),
      '#open' => TRUE,
    ];

    $form['summary']['info'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Date: @date', ['@date' => $this->dateFormatter->format($this->report->created, 'long')]),
        $this->t('Performed by: @user', ['@user' => $username]),
        $this->t('Original search term: <strong>@term</strong>', ['@term' => $this->report->search_term]),
        $this->t('Replaced with: <strong>@term</strong>', ['@term' => $this->report->replace_term]),
        $this->t('Language: @lang', ['@lang' => $this->report->langcode ?: $this->t('All languages')]),
        $this->t('Total replacements: @count', ['@count' => $this->report->total_replacements]),
      ],
    ];

    // Undo operation info.
    $form['undo_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
    ];

    $form['undo_info']['message'] = [
      '#markup' => '<h3>' . $this->t('Undo Operation') . '</h3>' .
                   '<p>' . $this->t('To revert this operation, the system will search for <strong>"@search"</strong> and replace it with <strong>"@replace"</strong>.', [
                     '@search' => $this->report->replace_term,
                     '@replace' => $this->report->search_term,
                   ]) . '</p>' .
                   '<p><strong>' . $this->t('Warning:') . '</strong> ' . $this->t('This will modify content. Make sure the content has not been changed since the original replacement.') . '</p>',
    ];

    // Build node selection table.
    $form['nodes'] = [
      '#type' => 'table',
      '#header' => [
        'select' => $this->t('Select'),
        'node' => $this->t('Node'),
        'type' => $this->t('Content Type'),
        'status' => $this->t('Current Status'),
        'preview' => $this->t('Preview'),
        'actions' => $this->t('Actions'),
      ],
      '#empty' => $this->t('No nodes found in the report.'),
      '#tableselect' => TRUE,
    ];

    // Add select all checkbox.
    $form['select_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Select all with occurrences'),
      '#attributes' => [
        'id' => 'select-all-nodes',
      ],
    ];

    // Check nodes.
    $details = unserialize($this->report->details);
    $node_storage = $this->entityTypeManager->getStorage('node');
    
    $all_checked = TRUE;
    $options = [];

    if (!empty($details)) {
      foreach ($details as $key => $node_data) {
        if (!is_numeric($key)) continue;
        
        $node = $node_storage->load($node_data['nid']);
        if (!$node) continue;

        // Check current content.
        $current_status = $this->checkNodeContent($node, $this->report->replace_term, $this->report->langcode);
        
        $default_checked = $current_status['found'];
        if (!$current_status['found']) {
          $all_checked = FALSE;
        }

        $status_markup = $current_status['found'] 
          ? $this->t('<span class="color-success">✓ Found @count occurrences</span>', ['@count' => $current_status['count']])
          : $this->t('<span class="color-warning">⚠ No occurrences found</span>');

        // Build row data.
        $options[$node_data['nid']] = [
          'node' => [
            'data' => [
              '#markup' => $this->t('<strong>@title</strong><br><small>Node @nid</small>', [
                '@title' => $node->getTitle(),
                '@nid' => $node->id(),
              ]),
            ],
          ],
          'type' => $node->bundle(),
          'status' => [
            'data' => ['#markup' => $status_markup],
          ],
          'preview' => [
            'data' => $this->buildPreview($current_status['samples']),
          ],
          'actions' => [
            'data' => [
              '#type' => 'operations',
              '#links' => [
                'view' => [
                  'title' => $this->t('View'),
                  'url' => $node->toUrl(),
                  'attributes' => ['target' => '_blank'],
                ],
                'edit' => [
                  'title' => $this->t('Edit'),
                  'url' => $node->toUrl('edit-form'),
                  'attributes' => ['target' => '_blank'],
                ],
              ],
            ],
          ],
        ];

        // Set default selection.
        if ($default_checked) {
          $form['nodes']['#default_value'][$node_data['nid']] = $node_data['nid'];
        }
      }
    }

    $form['nodes']['#options'] = $options;

    // Update select all checkbox default.
    $form['select_all']['#default_value'] = $all_checked;

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue to confirmation'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('content_radar.report_details', ['rid' => $rid]),
      '#attributes' => ['class' => ['button']],
    ];

    // Add JavaScript.
    $form['#attached']['library'][] = 'content_radar/undo-page';

    return $form;
  }

  /**
   * Check if node contains the search term.
   */
  protected function checkNodeContent($node, $search_term, $langcode) {
    $count = 0;
    $samples = [];
    
    // Get appropriate translation.
    if (!empty($langcode) && $node->hasTranslation($langcode)) {
      $node = $node->getTranslation($langcode);
    }
    
    // Check title.
    $title = $node->getTitle();
    if (stripos($title, $search_term) !== FALSE) {
      $title_count = substr_count(strtolower($title), strtolower($search_term));
      $count += $title_count;
      $samples[] = [
        'field' => $this->t('Title'),
        'text' => $this->highlightText($title, $search_term),
        'count' => $title_count,
      ];
    }
    
    // Check fields.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $node->bundle());
    foreach ($field_definitions as $field_name => $field_definition) {
      if (in_array($field_definition->getType(), ['string', 'string_long', 'text', 'text_long', 'text_with_summary'])) {
        if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
          $field_values = $node->get($field_name)->getValue();
          foreach ($field_values as $value) {
            if (isset($value['value']) && stripos($value['value'], $search_term) !== FALSE) {
              $field_count = substr_count(strtolower($value['value']), strtolower($search_term));
              $count += $field_count;
              
              // Get sample text.
              $sample_text = $value['value'];
              if (strlen($sample_text) > 200) {
                $pos = stripos($sample_text, $search_term);
                $start = max(0, $pos - 50);
                $length = min(200, strlen($sample_text) - $start);
                $sample_text = '...' . substr($sample_text, $start, $length) . '...';
              }
              
              $samples[] = [
                'field' => $field_definition->getLabel(),
                'text' => $this->highlightText($sample_text, $search_term),
                'count' => $field_count,
              ];
              
              // Limit samples.
              if (count($samples) >= 3) break 2;
            }
          }
        }
      }
    }
    
    return [
      'found' => $count > 0,
      'count' => $count,
      'samples' => $samples,
    ];
  }

  /**
   * Highlight search term in text.
   */
  protected function highlightText($text, $search_term) {
    $pattern = '/(' . preg_quote($search_term, '/') . ')/i';
    return preg_replace($pattern, '<mark>$1</mark>', $text);
  }

  /**
   * Build preview markup.
   */
  protected function buildPreview($samples) {
    if (empty($samples)) {
      return ['#markup' => '<em>' . $this->t('No preview available') . '</em>'];
    }
    
    $items = [];
    foreach ($samples as $sample) {
      $items[] = $this->t('<strong>@field (@count):</strong> @text', [
        '@field' => $sample['field'],
        '@count' => $sample['count'],
        '@text' => $sample['text'],
      ]);
    }
    
    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['preview-samples']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $selected_nodes = array_filter($form_state->getValue('nodes', []));
    
    if (empty($selected_nodes)) {
      $form_state->setErrorByName('nodes', $this->t('Please select at least one node to revert.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected_nodes = array_filter($form_state->getValue('nodes', []));
    
    // Store in session and redirect to confirmation.
    $session = \Drupal::request()->getSession();
    $session->set('content_radar_undo_nodes', $selected_nodes);
    
    $form_state->setRedirect('content_radar.report_undo_confirm', ['rid' => $this->rid]);
  }

}