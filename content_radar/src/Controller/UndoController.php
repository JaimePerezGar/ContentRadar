<?php

namespace Drupal\content_radar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\content_radar\Service\TextSearchService;
use Drupal\Core\Access\CsrfTokenGenerator;

/**
 * Controller for undo operations.
 */
class UndoController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The text search service.
   *
   * @var \Drupal\content_radar\Service\TextSearchService
   */
  protected $textSearchService;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * Constructs a UndoController object.
   */
  public function __construct(Connection $database, DateFormatterInterface $date_formatter, FormBuilderInterface $form_builder, EntityTypeManagerInterface $entity_type_manager, TextSearchService $text_search_service, EntityFieldManagerInterface $entity_field_manager, CsrfTokenGenerator $csrf_token) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->formBuilder = $form_builder;
    $this->entityTypeManager = $entity_type_manager;
    $this->textSearchService = $text_search_service;
    $this->entityFieldManager = $entity_field_manager;
    $this->csrfToken = $csrf_token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('form_builder'),
      $container->get('entity_type.manager'),
      $container->get('content_radar.search_service'),
      $container->get('entity_field.manager'),
      $container->get('csrf_token')
    );
  }

  /**
   * Displays the undo page.
   */
  public function undoPage($rid, Request $request) {
    // Load the report.
    $report = $this->database->select('content_radar_reports', 'r')
      ->fields('r')
      ->condition('rid', $rid)
      ->execute()
      ->fetchObject();

    if (!$report) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    // Check if already undone.
    if ($this->database->select('content_radar_reports', 'r')
        ->fields('r', ['rid'])
        ->condition('details', '%"undone_from":' . $rid . '%', 'LIKE')
        ->countQuery()
        ->execute()
        ->fetchField() > 0) {
      $this->messenger()->addWarning($this->t('This replacement operation has already been undone.'));
      return $this->redirect('content_radar.report_details', ['rid' => $rid]);
    }

    $build = [];
    
    // Add libraries.
    $build['#attached']['library'][] = 'content_radar/undo-page';
    $build['#attached']['library'][] = 'core/jquery';
    $build['#attached']['library'][] = 'core/drupal';

    // Report summary.
    $user = $this->entityTypeManager()->getStorage('user')->load($report->uid);
    $username = $user ? $user->getDisplayName() : $this->t('Anonymous');

    $build['summary'] = [
      '#type' => 'details',
      '#title' => $this->t('Operation Summary'),
      '#open' => TRUE,
    ];

    $build['summary']['info'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Date: @date', ['@date' => $this->dateFormatter->format($report->created, 'long')]),
        $this->t('Performed by: @user', ['@user' => $username]),
        $this->t('Original search term: <strong>@term</strong>', ['@term' => $report->search_term]),
        $this->t('Replaced with: <strong>@term</strong>', ['@term' => $report->replace_term]),
        $this->t('Language: @lang', ['@lang' => $report->langcode ?: $this->t('All languages')]),
        $this->t('Total replacements: @count', ['@count' => $report->total_replacements]),
      ],
    ];

    // Undo operation info.
    $build['undo_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
    ];

    $build['undo_info']['message'] = [
      '#markup' => '<h3>' . $this->t('Undo Operation') . '</h3>' .
                   '<p>' . $this->t('To revert this operation, the system will search for <strong>"@search"</strong> and replace it with <strong>"@replace"</strong>.', [
                     '@search' => $report->replace_term,
                     '@replace' => $report->search_term,
                   ]) . '</p>' .
                   '<p><strong>' . $this->t('Warning:') . '</strong> ' . $this->t('This will modify content. Make sure the content has not been changed since the original replacement.') . '</p>',
    ];

    // Process form submission.
    if ($request->isMethod('POST')) {
      // Validate CSRF token
      $csrf_token = $request->request->get('csrf_token');
      if (!$this->csrfToken->validate($csrf_token, 'content_radar_undo_form')) {
        $this->messenger()->addError($this->t('Invalid form submission.'));
        return $this->redirect('content_radar.report_undo', ['rid' => $rid]);
      }
      
      $selected_nodes = [];
      // Get all POST data
      $post_data = $request->request->all();
      
      // Extract node IDs from checkbox submissions
      foreach ($post_data as $key => $value) {
        if (strpos($key, 'node_') === 0 && $value) {
          $nid = substr($key, 5); // Remove 'node_' prefix
          if (is_numeric($nid)) {
            $selected_nodes[] = $nid;
          }
        }
      }
      
      if (!empty($selected_nodes)) {
        // Store in session and redirect to confirmation.
        $session = $request->getSession();
        $session->set('content_radar_undo_nodes', $selected_nodes);
        return $this->redirect('content_radar.report_undo_confirm', ['rid' => $rid]);
      } else {
        $this->messenger()->addError($this->t('Please select at least one node to revert.'));
      }
    }

    // Build the form.
    $csrf_token = $this->csrfToken->get('content_radar_undo_form');
    $form_action = Url::fromRoute('content_radar.report_undo', ['rid' => $rid])->toString();
    
    $build['form'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'content-radar-undo-form-container'],
    ];
    
    $build['form']['form_start'] = [
      '#markup' => '<form method="POST" action="' . $form_action . '" id="content-radar-undo-form">' .
                   '<input type="hidden" name="csrf_token" value="' . $csrf_token . '" />',
    ];

    // Check nodes.
    $details = unserialize($report->details);
    $node_storage = $this->entityTypeManager()->getStorage('node');
    
    $header = [
      'select' => $this->t('Select'),
      'node' => $this->t('Node'),
      'type' => $this->t('Content Type'),
      'status' => $this->t('Current Status'),
      'preview' => $this->t('Preview'),
      'actions' => $this->t('Actions'),
    ];

    $rows = [];
    $all_checked = TRUE;

    if (!empty($details)) {
      foreach ($details as $key => $node_data) {
        if (!is_numeric($key)) continue;
        
        $node = $node_storage->load($node_data['nid']);
        if (!$node) continue;

        // Check current content.
        $current_status = $this->checkNodeContent($node, $report->replace_term, $report->langcode);
        
        $checkbox = [
          '#markup' => '<input type="checkbox" name="node_' . $node_data['nid'] . '" value="1" class="node-select"' . 
                       ($current_status['found'] ? ' checked="checked"' : '') . ' />',
        ];

        if (!$current_status['found']) {
          $all_checked = FALSE;
        }

        $status_markup = $current_status['found'] 
          ? $this->t('<span class="color-success">✓ Found @count occurrences</span>', ['@count' => $current_status['count']])
          : $this->t('<span class="color-warning">⚠ No occurrences found</span>');

        $rows[] = [
          'select' => [
            'data' => $checkbox,
          ],
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
                ],
                'edit' => [
                  'title' => $this->t('Edit'),
                  'url' => $node->toUrl('edit-form'),
                ],
              ],
            ],
          ],
        ];
      }
    }

    // Select all checkbox.
    $build['form']['select_all'] = [
      '#markup' => '<div class="form-item"><label><input type="checkbox" id="select-all-nodes"' . 
                   ($all_checked ? ' checked="checked"' : '') . ' /> ' . 
                   $this->t('Select all with occurrences') . '</label></div>',
    ];

    $build['form']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No nodes found in the report.'),
      '#attributes' => ['class' => ['content-radar-undo-table']],
    ];

    $build['form']['actions'] = [
      '#type' => 'actions',
    ];

    $build['form']['actions']['submit'] = [
      '#markup' => '<button type="submit" class="button button--primary">' . $this->t('Continue to confirmation') . '</button>',
    ];

    $build['form']['actions']['cancel'] = [
      '#markup' => '<a href="' . Url::fromRoute('content_radar.report_details', ['rid' => $rid])->toString() . '" class="button">' . $this->t('Cancel') . '</a>',
    ];
    
    $build['form']['form_end'] = [
      '#markup' => '</form>',
    ];

    return $build;
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

}