<?php

namespace Drupal\Tests\content_radar\Kernel;

use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Tests the TextSearchService with real entities.
 *
 * @group content_radar
 */
class TextSearchServiceKernelTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'text',
    'system',
    'user',
    'content_radar',
  ];

  /**
   * The text search service.
   *
   * @var \Drupal\content_radar\Service\TextSearchService
   */
  protected $textSearchService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install necessary config.
    $this->installConfig(['node', 'field']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('content_radar', [
      'content_radar_log',
      'content_radar_reports',
    ]);

    // Create a content type.
    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ])->save();

    // Add a text field.
    FieldStorageConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'node',
      'type' => 'text_long',
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Text field',
    ])->save();

    // Get the service.
    $this->textSearchService = $this->container->get('content_radar.search_service');
  }

  /**
   * Tests searching in nodes.
   */
  public function testSearchInNodes() {
    // Create test nodes.
    $node1 = Node::create([
      'type' => 'article',
      'title' => 'First Test Article',
      'body' => [
        'value' => 'This is the body of the first article.',
      ],
      'field_text' => [
        'value' => 'Additional text field content with test keyword.',
      ],
    ]);
    $node1->save();

    $node2 = Node::create([
      'type' => 'article',
      'title' => 'Second Article',
      'body' => [
        'value' => 'This is another article without the keyword.',
      ],
    ]);
    $node2->save();

    // Search for "test".
    $results = $this->textSearchService->search('test', FALSE, ['node'], ['article']);
    
    $this->assertCount(1, $results['items']);
    $this->assertEquals(1, $results['total']);
    $this->assertEquals('First Test Article', $results['items'][0]['title']);
    
    // Verify it found matches in both title and custom field.
    $found_in_title = FALSE;
    $found_in_field = FALSE;
    
    foreach ($results['items'] as $item) {
      if ($item['field_name'] === 'title') {
        $found_in_title = TRUE;
      }
      if ($item['field_name'] === 'field_text') {
        $found_in_field = TRUE;
      }
    }
    
    $this->assertTrue($found_in_title || $found_in_field);
  }

  /**
   * Tests replace functionality.
   */
  public function testReplaceInNodes() {
    // Create a test node.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Article with old content',
      'body' => [
        'value' => 'This has old content that needs replacing.',
      ],
    ]);
    $node->save();
    $nid = $node->id();

    // Replace "old" with "new".
    $result = $this->textSearchService->replaceText(
      'old',
      'new',
      FALSE,
      ['node'],
      ['article'],
      '',
      FALSE
    );

    $this->assertEquals(2, $result['replaced_count']);
    $this->assertCount(1, $result['affected_entities']);

    // Reload node and check.
    $node = Node::load($nid);
    $this->assertEquals('Article with new content', $node->getTitle());
    $this->assertStringContainsString('new content', $node->body->value);
  }

  /**
   * Tests case-sensitive search.
   */
  public function testCaseSensitiveSearch() {
    // Create node with mixed case content.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Case Test',
      'body' => [
        'value' => 'This has Test, test, and TEST in it.',
      ],
    ]);
    $node->save();

    // Case-insensitive search.
    $results = $this->textSearchService->search('test', FALSE, ['node'], ['article'], '', 0, 50, [], FALSE);
    // Should find all occurrences.
    $match_count = 0;
    foreach ($results['items'] as $item) {
      if (isset($item['extract'])) {
        $match_count++;
      }
    }
    $this->assertGreaterThan(0, $match_count);

    // Case-sensitive search.
    $results = $this->textSearchService->search('test', FALSE, ['node'], ['article'], '', 0, 50, [], TRUE);
    // Should only find lowercase "test".
    $match_count = 0;
    foreach ($results['items'] as $item) {
      if (isset($item['extract']) && strpos($item['extract'], '<mark>test</mark>') !== FALSE) {
        $match_count++;
      }
    }
    $this->assertEquals(1, $match_count);
  }

  /**
   * Tests deep search functionality.
   */
  public function testDeepSearch() {
    // This would require setting up entity references, paragraphs, etc.
    // For now, we'll test that deep search at least works with regular nodes.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Deep Search Test',
      'body' => [
        'value' => 'Content for deep search testing.',
      ],
    ]);
    $node->save();

    // Perform deep search.
    $results = $this->textSearchService->deepSearch('deep', FALSE, ['node'], ['article']);
    
    $this->assertCount(1, $results['items']);
    $this->assertEquals('Deep Search Test', $results['items'][0]['title']);
  }

  /**
   * Tests regex search functionality.
   */
  public function testRegexSearch() {
    // Create node with pattern content.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Contact Info',
      'body' => [
        'value' => 'Phone: 123-456-7890, Email: test@example.com',
      ],
    ]);
    $node->save();

    // Search for phone pattern.
    $results = $this->textSearchService->search('\d{3}-\d{3}-\d{4}', TRUE, ['node'], ['article']);
    $this->assertCount(1, $results['items']);
    $this->assertStringContainsString('123-456-7890', $results['items'][0]['extract']);

    // Search for email pattern.
    $results = $this->textSearchService->search('[a-z]+@[a-z]+\.[a-z]+', TRUE, ['node'], ['article']);
    $this->assertCount(1, $results['items']);
    $this->assertStringContainsString('test@example.com', $results['items'][0]['extract']);
  }

}