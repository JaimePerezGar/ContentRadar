<?php

namespace Drupal\content_radar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\content_radar\Service\TextSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Controller for ContentRadar.
 */
class TextSearchController extends ControllerBase {

  /**
   * The text search service.
   *
   * @var \Drupal\content_radar\Service\TextSearchService
   */
  protected $textSearchService;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new TextSearchController.
   *
   * @param \Drupal\content_radar\Service\TextSearchService $text_search_service
   *   The text search service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(TextSearchService $text_search_service, LoggerChannelFactoryInterface $logger_factory) {
    $this->textSearchService = $text_search_service;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content_radar.search_service'),
      $container->get('logger.factory')
    );
  }

  /**
   * Export search results to CSV.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The CSV response.
   */
  public function exportResults(Request $request) {
    $search_term = $request->query->get('search_term', '');
    $use_regex = $request->query->get('use_regex', FALSE);
    $content_types = $request->query->get('content_types', '');
    $langcode = $request->query->get('langcode', '');
    
    if (empty($search_term)) {
      return new Response('No search term provided', 400);
    }
    
    // Convert string boolean to actual boolean.
    $use_regex = ($use_regex === 'true' || $use_regex === '1' || $use_regex === TRUE);
    
    // Parse content types.
    $content_types = !empty($content_types) ? explode(',', $content_types) : [];
    
    try {
      // Generate CSV.
      $csv_content = $this->textSearchService->exportToCsv($search_term, $use_regex, $content_types, $langcode);
      
      // Create response.
      $response = new Response($csv_content);
      $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
      $response->headers->set('Content-Disposition', 'attachment; filename="content-radar-results-' . date('Y-m-d-H-i-s') . '.csv"');
      
      // Log the export.
      $this->loggerFactory->get('content_radar')->info('Exported search results for term: @term in language: @lang', [
        '@term' => $search_term,
        '@lang' => $langcode ?: 'all',
      ]);
      
      return $response;
      
    } catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Export error: @message', ['@message' => $e->getMessage()]);
      return new Response('Error generating export: ' . $e->getMessage(), 500);
    }
  }

}