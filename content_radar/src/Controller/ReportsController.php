<?php

namespace Drupal\content_radar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Response;
use Drupal\user\Entity\User;

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
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a new ReportsController.
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
   * Display list of reports.
   */
  public function reportsList() {
    $header = [
      'created' => ['data' => $this->t('Date'), 'field' => 'created', 'sort' => 'desc'],
      'user' => $this->t('User'),
      'search_term' => $this->t('Search term'),
      'replace_term' => $this->t('Replace term'),
      'total_replacements' => ['data' => $this->t('Replacements'), 'field' => 'total_replacements'],
      'affected_entities' => ['data' => $this->t('Entities'), 'field' => 'affected_entities'],
      'operations' => $this->t('Operations'),
    ];

    $query = $this->database->select('content_radar_reports', 'r')
      ->extend('\Drupal\Core\Database\Query\TableSortExtender')
      ->extend('\Drupal\Core\Database\Query\PagerSelectExtender');
    $query->fields('r');
    $query->orderByHeader($header);
    $query->limit(50);

    $results = $query->execute();

    $rows = [];
    foreach ($results as $report) {
      $user = User::load($report->uid);
      $operations = [];

      $operations['view'] = [
        'title' => $this->t('View'),
        'url' => Url::fromRoute('content_radar.report_detail', ['rid' => $report->rid]),
      ];

      if ($this->currentUser()->hasPermission('undo content radar changes')) {
        $details = unserialize($report->details);
        if (!isset($details['undone'])) {
          $operations['undo'] = [
            'title' => $this->t('Undo'),
            'url' => Url::fromRoute('content_radar.undo', ['rid' => $report->rid]),
          ];
        }
      }

      $rows[] = [
        'created' => $this->dateFormatter->format($report->created, 'short'),
        'user' => $user ? Link::fromTextAndUrl($user->getDisplayName(), $user->toUrl())->toString() : $this->t('Anonymous'),
        'search_term' => $report->search_term,
        'replace_term' => $report->replace_term,
        'total_replacements' => $report->total_replacements,
        'affected_entities' => $report->affected_entities,
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No reports available.'),
      '#attributes' => ['class' => ['content-radar-reports-table']],
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  /**
   * Display report details.
   */
  public function reportDetail($rid) {
    $report = $this->database->select('content_radar_reports', 'r')
      ->fields('r')
      ->condition('rid', $rid)
      ->execute()
      ->fetchObject();

    if (!$report) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $user = User::load($report->uid);
    $details = unserialize($report->details);

    $build = [];

    // Summary section.
    $build['summary'] = [
      '#theme' => 'content_radar_report_details',
      '#report' => $report,
      '#summary' => [
        'created' => $this->dateFormatter->format($report->created, 'long'),
        'user' => $user ? $user->getDisplayName() : $this->t('Anonymous'),
        'search_term' => $report->search_term,
        'replace_term' => $report->replace_term,
        'use_regex' => $report->use_regex ? $this->t('Yes') : $this->t('No'),
        'language' => $report->langcode ?: $this->t('All languages'),
        'total_replacements' => $report->total_replacements,
        'affected_entities' => $report->affected_entities,
      ],
      '#details' => $details,
      '#back_url' => Url::fromRoute('content_radar.reports'),
      '#export_url' => Url::fromRoute('content_radar.report_export', ['rid' => $rid]),
      '#show_undo' => $this->currentUser()->hasPermission('undo content radar changes') && !isset($details['undone']),
      '#undo_url' => Url::fromRoute('content_radar.undo', ['rid' => $rid]),
    ];

    return $build;
  }

  /**
   * Export report as CSV.
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

    $details = unserialize($report->details);

    // Create CSV content.
    $output = "\xEF\xBB\xBF"; // UTF-8 BOM
    $csv = [];
    $csv[] = ['Report ID', $report->rid];
    $csv[] = ['Date', $this->dateFormatter->format($report->created, 'custom', 'Y-m-d H:i:s')];
    $csv[] = ['Search Term', $report->search_term];
    $csv[] = ['Replace Term', $report->replace_term];
    $csv[] = ['Total Replacements', $report->total_replacements];
    $csv[] = ['Affected Entities', $report->affected_entities];
    $csv[] = [];
    $csv[] = ['Entity Type', 'Entity ID', 'Title', 'Bundle', 'Language'];

    foreach ($details as $entity_info) {
      $csv[] = [
        $entity_info['entity_type'],
        $entity_info['id'],
        $entity_info['title'],
        $entity_info['type'],
        $entity_info['langcode'],
      ];
    }

    // Generate CSV.
    $handle = fopen('php://temp', 'r+');
    foreach ($csv as $row) {
      fputcsv($handle, $row);
    }
    rewind($handle);
    $output .= stream_get_contents($handle);
    fclose($handle);

    // Create response.
    $response = new Response($output);
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="content_radar_report_' . $rid . '.csv"');

    return $response;
  }

}