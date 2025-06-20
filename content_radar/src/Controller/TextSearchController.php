<?php

namespace Drupal\content_radar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\content_radar\Service\TextSearchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for Content Radar text search.
 */
class TextSearchController extends ControllerBase {

  /**
   * The text search service.
   *
   * @var \Drupal\content_radar\Service\TextSearchService
   */
  protected $textSearchService;

  /**
   * Constructs a new TextSearchController.
   */
  public function __construct(TextSearchService $text_search_service) {
    $this->textSearchService = $text_search_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content_radar.search_service')
    );
  }

  /**
   * Export search results to CSV.
   */
  public function export(Request $request) {
    $search_term = $request->query->get('search_term', '');
    $use_regex = (bool) $request->query->get('use_regex', 0);
    $entity_types = $request->query->get('entity_types', []);
    $content_types = $request->query->get('content_types', []);
    $langcode = $request->query->get('langcode', '');

    if (empty($search_term)) {
      throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Search term is required.');
    }

    // Generate CSV.
    $csv_content = $this->textSearchService->exportToCsv(
      $search_term,
      $use_regex,
      $entity_types,
      $content_types,
      $langcode
    );

    // Create response.
    $response = new Response($csv_content);
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="content_radar_results.csv"');

    return $response;
  }

}