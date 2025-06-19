<?php

namespace Drupal\content_radar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for Content Radar reports.
 */
class ReportsController extends ControllerBase {

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
   * Constructs a ReportsController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(Connection $database, DateFormatterInterface $date_formatter) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter')
    );
  }

  /**
   * Displays the reports page.
   *
   * @return array
   *   A render array.
   */
  public function reportsPage() {
    $build = [];

    // Add description.
    $build['description'] = [
      '#markup' => '<p>' . $this->t('This page shows all text replacement operations performed by Content Radar.') . '</p>',
    ];

    // Check if the table exists
    if (!$this->database->schema()->tableExists('content_radar_reports')) {
      $this->messenger()->addError($this->t('The reports table has not been created yet. Please run database updates.'));
      $build['error'] = [
        '#markup' => '<p>' . $this->t('To fix this issue:') . '</p>' .
                     '<ol>' .
                     '<li>' . $this->t('Run <code>drush updb</code> in your terminal') . '</li>' .
                     '<li>' . $this->t('Or visit <a href="@url">the update page</a>', ['@url' => '/update.php']) . '</li>' .
                     '<li>' . $this->t('Clear caches after running updates') . '</li>' .
                     '</ol>',
      ];
      return $build;
    }

    // Get reports from database.
    $query = $this->database->select('content_radar_reports', 'r')
      ->fields('r')
      ->orderBy('created', 'DESC')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->limit(25);

    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      $build['no_results'] = [
        '#markup' => '<p>' . $this->t('No replacement operations have been performed yet.') . '</p>',
      ];
      return $build;
    }

    // Build the table.
    $header = [
      $this->t('Date'),
      $this->t('User'),
      $this->t('Search Term'),
      $this->t('Replace Term'),
      $this->t('Language'),
      $this->t('Replacements'),
      $this->t('Nodes Affected'),
      $this->t('Actions'),
    ];

    $rows = [];
    foreach ($results as $report) {
      $user = $this->entityTypeManager()->getStorage('user')->load($report->uid);
      $username = $user ? $user->getDisplayName() : $this->t('Anonymous');

      // Format language.
      $language = $report->langcode ? $report->langcode : $this->t('All languages');

      // Create view details link.
      $view_link = Link::fromTextAndUrl(
        $this->t('View details'),
        Url::fromRoute('content_radar.report_details', ['rid' => $report->rid])
      );

      // Create export link.
      $export_link = Link::fromTextAndUrl(
        $this->t('Export CSV'),
        Url::fromRoute('content_radar.report_export', ['rid' => $report->rid])
      );

      $rows[] = [
        $this->dateFormatter->format($report->created, 'short'),
        $username,
        $report->search_term,
        $report->replace_term,
        $language,
        $report->total_replacements,
        $report->nodes_affected,
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'view' => [
                'title' => $this->t('View details'),
                'url' => Url::fromRoute('content_radar.report_details', ['rid' => $report->rid]),
              ],
              'export' => [
                'title' => $this->t('Export CSV'),
                'url' => Url::fromRoute('content_radar.report_export', ['rid' => $report->rid]),
              ],
            ],
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No reports found.'),
      '#attributes' => [
        'class' => ['content-radar-reports-table'],
      ],
    ];

    // Add pager.
    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  /**
   * Displays report details.
   *
   * @param int $rid
   *   The report ID.
   *
   * @return array
   *   A render array.
   */
  public function reportDetails($rid) {
    $report = $this->database->select('content_radar_reports', 'r')
      ->fields('r')
      ->condition('rid', $rid)
      ->execute()
      ->fetchObject();

    if (!$report) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    // Prepare summary data.
    $user = $this->entityTypeManager()->getStorage('user')->load($report->uid);
    $username = $user ? $user->getDisplayName() : $this->t('Anonymous');

    $summary = [
      'date' => $this->dateFormatter->format($report->created, 'long'),
      'user' => $username,
      'search_term' => $report->search_term,
      'replace_term' => $report->replace_term,
      'language' => $report->langcode ?: $this->t('All languages'),
      'use_regex' => $report->use_regex ? $this->t('Yes') : $this->t('No'),
      'total_replacements' => $report->total_replacements,
      'nodes_affected' => $report->nodes_affected,
    ];

    // Prepare details data.
    $details_data = [];
    $details = unserialize($report->details);
    if (!empty($details)) {
      foreach ($details as $node_data) {
        $details_data[] = [
          'nid' => $node_data['nid'],
          'title' => $node_data['title'],
          'type' => $node_data['type'],
          'langcode' => $node_data['langcode'],
          'count' => $node_data['count'],
          'url' => Url::fromRoute('entity.node.canonical', ['node' => $node_data['nid']])->toString(),
          'edit_url' => Url::fromRoute('entity.node.edit_form', ['node' => $node_data['nid']])->toString(),
        ];
      }
    }

    return [
      '#theme' => 'content_radar_report_details',
      '#report' => $report,
      '#summary' => $summary,
      '#details' => $details_data,
      '#back_url' => Url::fromRoute('content_radar.reports')->toString(),
      '#export_url' => Url::fromRoute('content_radar.report_export', ['rid' => $rid])->toString(),
    ];
  }

  /**
   * Exports a report to CSV.
   *
   * @param int $rid
   *   The report ID.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   The CSV file response.
   */
  public function exportReport($rid) {
    $report = $this->database->select('content_radar_reports', 'r')
      ->fields('r')
      ->condition('rid', $rid)
      ->execute()
      ->fetchObject();

    if (!$report) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $response = new StreamedResponse();
    $response->setCallback(function () use ($report) {
      $handle = fopen('php://output', 'w+');

      // Add BOM for Excel UTF-8 compatibility.
      fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

      // Write report summary.
      fputcsv($handle, [$this->t('Content Radar Replacement Report')]);
      fputcsv($handle, []);
      fputcsv($handle, [$this->t('Date'), $this->dateFormatter->format($report->created, 'long')]);
      fputcsv($handle, [$this->t('Search Term'), $report->search_term]);
      fputcsv($handle, [$this->t('Replace Term'), $report->replace_term]);
      fputcsv($handle, [$this->t('Language'), $report->langcode ?: $this->t('All languages')]);
      fputcsv($handle, [$this->t('Total Replacements'), $report->total_replacements]);
      fputcsv($handle, [$this->t('Nodes Affected'), $report->nodes_affected]);
      fputcsv($handle, []);

      // Write details header.
      fputcsv($handle, [
        $this->t('Node ID'),
        $this->t('Title'),
        $this->t('Content Type'),
        $this->t('Language'),
        $this->t('URL'),
        $this->t('Fields Modified'),
        $this->t('Replacements'),
      ]);

      // Write details.
      $details = unserialize($report->details);
      if (!empty($details)) {
        foreach ($details as $node_data) {
          $fields_modified = [];
          foreach ($node_data['fields'] as $field_name => $count) {
            $fields_modified[] = $field_name . ' (' . $count . ')';
          }

          $url = Url::fromRoute('entity.node.canonical', ['node' => $node_data['nid']], ['absolute' => TRUE])->toString();

          fputcsv($handle, [
            $node_data['nid'],
            $node_data['title'],
            $node_data['type'],
            $node_data['langcode'],
            $url,
            implode(', ', $fields_modified),
            $node_data['count'],
          ]);
        }
      }

      fclose($handle);
    });

    $filename = 'content_radar_report_' . $rid . '_' . date('Y-m-d_H-i-s') . '.csv';
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

}