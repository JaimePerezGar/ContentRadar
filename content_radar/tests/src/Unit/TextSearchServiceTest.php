<?php

namespace Drupal\Tests\content_radar\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\content_radar\Service\TextSearchService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Tests the TextSearchService.
 *
 * @group content_radar
 * @coversDefaultClass \Drupal\content_radar\Service\TextSearchService
 */
class TextSearchServiceTest extends UnitTestCase {

  /**
   * The text search service.
   *
   * @var \Drupal\content_radar\Service\TextSearchService
   */
  protected $textSearchService;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The mocked entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityFieldManager;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * The mocked logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $this->database = $this->createMock(Connection::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);

    $this->textSearchService = new TextSearchService(
      $this->entityTypeManager,
      $this->entityFieldManager,
      $this->database,
      $this->loggerFactory
    );
  }

  /**
   * Tests performReplace method with regular text.
   *
   * @covers ::performReplace
   */
  public function testPerformReplaceRegularText() {
    $reflection = new \ReflectionClass($this->textSearchService);
    $method = $reflection->getMethod('performReplace');
    $method->setAccessible(TRUE);

    // Test case-insensitive replacement.
    $result = $method->invoke(
      $this->textSearchService,
      'Hello World, hello universe',
      'hello',
      'goodbye',
      FALSE,
      FALSE // case_sensitive = FALSE
    );
    $this->assertEquals('goodbye World, goodbye universe', $result);

    // Test case-sensitive replacement.
    $result = $method->invoke(
      $this->textSearchService,
      'Hello World, hello universe',
      'hello',
      'goodbye',
      FALSE,
      TRUE // case_sensitive = TRUE
    );
    $this->assertEquals('Hello World, goodbye universe', $result);

    // Test with regex (case-insensitive).
    $result = $method->invoke(
      $this->textSearchService,
      'The price is $100 or $200',
      '\$\d+',
      '$XXX',
      TRUE,
      FALSE // case_sensitive = FALSE
    );
    $this->assertEquals('The price is $XXX or $XXX', $result);

    // Test with regex (case-sensitive).
    $result = $method->invoke(
      $this->textSearchService,
      'Code: ABC123 and abc456',
      'ABC\d+',
      'XXX',
      TRUE,
      TRUE // case_sensitive = TRUE
    );
    $this->assertEquals('Code: XXX and abc456', $result);
  }

  /**
   * Tests performReplace method with HTML content.
   *
   * @covers ::performReplace
   */
  public function testPerformReplaceHtml() {
    $reflection = new \ReflectionClass($this->textSearchService);
    $method = $reflection->getMethod('performReplace');
    $method->setAccessible(TRUE);

    // Test HTML content replacement (case-insensitive).
    $html = '<p>Hello World</p><div>hello universe</div><span>HELLO earth</span>';
    $result = $method->invoke(
      $this->textSearchService,
      $html,
      'hello',
      'goodbye',
      FALSE,
      FALSE // case_sensitive = FALSE
    );
    $this->assertEquals('<p>goodbye World</p><div>goodbye universe</div><span>goodbye earth</span>', $result);

    // Test HTML content replacement (case-sensitive).
    $result = $method->invoke(
      $this->textSearchService,
      $html,
      'hello',
      'goodbye',
      FALSE,
      TRUE // case_sensitive = TRUE
    );
    $this->assertEquals('<p>Hello World</p><div>goodbye universe</div><span>HELLO earth</span>', $result);
  }

  /**
   * Tests countReplacements method.
   *
   * @covers ::countReplacements
   */
  public function testCountReplacements() {
    $reflection = new \ReflectionClass($this->textSearchService);
    $method = $reflection->getMethod('countReplacements');
    $method->setAccessible(TRUE);

    // Test regular text counting (case-insensitive).
    $count = $method->invoke(
      $this->textSearchService,
      'Hello world, hello universe, HELLO earth',
      'hello',
      FALSE,
      FALSE // case_sensitive = FALSE
    );
    $this->assertEquals(3, $count);

    // Test regular text counting (case-sensitive).
    $count = $method->invoke(
      $this->textSearchService,
      'Hello world, hello universe, HELLO earth',
      'hello',
      FALSE,
      TRUE // case_sensitive = TRUE
    );
    $this->assertEquals(1, $count);

    // Test regex counting.
    $count = $method->invoke(
      $this->textSearchService,
      'Contact: 123-456-7890 or 098-765-4321',
      '\d{3}-\d{3}-\d{4}',
      TRUE,
      FALSE // case_sensitive = FALSE
    );
    $this->assertEquals(2, $count);
  }

  /**
   * Tests extractContext method.
   *
   * @covers ::extractContext
   */
  public function testExtractContext() {
    $reflection = new \ReflectionClass($this->textSearchService);
    $method = $reflection->getMethod('extractContext');
    $method->setAccessible(TRUE);

    $text = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.';
    
    // Test extraction in the middle of text.
    $result = $method->invoke(
      $this->textSearchService,
      $text,
      27, // Position of "consectetur"
      11, // Length of "consectetur"
      20  // Context length
    );

    $this->assertStringContainsString('<mark>consectetur</mark>', $result['extract']);
    $this->assertStringContainsString('...', $result['extract']);
  }

  /**
   * Tests cleanSerializedText method.
   *
   * @covers ::cleanSerializedText
   */
  public function testCleanSerializedText() {
    $reflection = new \ReflectionClass($this->textSearchService);
    $method = $reflection->getMethod('cleanSerializedText');
    $method->setAccessible(TRUE);

    // Test with serialized array.
    $serialized = 'a:2:{s:5:"title";s:10:"Test Title";s:4:"body";s:9:"Test Body";}';
    $result = $method->invoke($this->textSearchService, $serialized);
    $this->assertStringContainsString('Test Title', $result);
    $this->assertStringContainsString('Test Body', $result);

    // Test with regular text (should return as-is).
    $regular = 'This is regular text';
    $result = $method->invoke($this->textSearchService, $regular);
    $this->assertEquals($regular, $result);
  }

  /**
   * Tests searchInText method with case sensitivity.
   *
   * @covers ::searchInText
   */
  public function testSearchInTextCaseSensitive() {
    $reflection = new \ReflectionClass($this->textSearchService);
    $method = $reflection->getMethod('searchInText');
    $method->setAccessible(TRUE);

    $text = 'Hello World, hello universe, HELLO earth';

    // Test case-insensitive search.
    $matches = $method->invoke(
      $this->textSearchService,
      $text,
      'hello',
      FALSE,
      FALSE
    );
    $this->assertCount(3, $matches);

    // Test case-sensitive search.
    $matches = $method->invoke(
      $this->textSearchService,
      $text,
      'hello',
      FALSE,
      TRUE
    );
    $this->assertCount(1, $matches);

    // Test regex with case sensitivity.
    $matches = $method->invoke(
      $this->textSearchService,
      $text,
      'HELLO',
      TRUE,
      TRUE
    );
    $this->assertCount(1, $matches);
  }

  /**
   * Tests searchArrayRecursively method.
   *
   * @covers ::searchArrayRecursively
   */
  public function testSearchArrayRecursively() {
    $reflection = new \ReflectionClass($this->textSearchService);
    $method = $reflection->getMethod('searchArrayRecursively');
    $method->setAccessible(TRUE);

    // Create a mock entity for testing.
    $entity = $this->createMock(EntityInterface::class);
    $entity->expects($this->any())
      ->method('getEntityTypeId')
      ->willReturn('node');
    $entity->expects($this->any())
      ->method('id')
      ->willReturn(1);
    $entity->expects($this->any())
      ->method('label')
      ->willReturn('Test Node');

    $data = [
      'value' => 'Find this text',
      'nested' => [
        'deep' => 'And find this too',
        'deeper' => [
          'text' => 'Even find this deep text',
        ],
      ],
    ];

    $results = [];
    
    // Test case-insensitive search
    $method->invoke(
      $this->textSearchService,
      $data,
      $entity,
      'test_field',
      'Test Field',
      'find',
      FALSE,
      $results,
      '',
      FALSE // case_sensitive = FALSE
    );

    $this->assertCount(3, $results);
    
    // Test case-sensitive search
    $results2 = [];
    $method->invoke(
      $this->textSearchService,
      $data,
      $entity,
      'test_field',
      'Test Field',
      'Find',
      FALSE,
      $results2,
      '',
      TRUE // case_sensitive = TRUE
    );

    $this->assertCount(1, $results2);
  }

  /**
   * Tests replaceInArrayRecursively method.
   *
   * @covers ::replaceInArrayRecursively
   */
  public function testReplaceInArrayRecursively() {
    $reflection = new \ReflectionClass($this->textSearchService);
    $method = $reflection->getMethod('replaceInArrayRecursively');
    $method->setAccessible(TRUE);

    $data = [
      'title' => 'Hello World',
      'nested' => [
        'body' => 'Say hello to everyone',
        'footer' => 'Goodbye and hello again',
      ],
      'number' => 123,
    ];

    // Test case-insensitive replacement
    $result = $method->invoke(
      $this->textSearchService,
      $data,
      'hello',
      'goodbye',
      FALSE,
      FALSE // case_sensitive = FALSE
    );

    $this->assertTrue($result['modified']);
    $this->assertEquals(3, $result['count']);
    $this->assertEquals('goodbye World', $data['title']);
    $this->assertEquals('Say goodbye to everyone', $data['nested']['body']);
    $this->assertEquals('Goodbye and goodbye again', $data['nested']['footer']);
    $this->assertEquals(123, $data['number']); // Should not change.

    // Test case-sensitive replacement
    $data2 = [
      'title' => 'Hello World',
      'body' => 'Say hello to everyone, HELLO!',
    ];

    $result2 = $method->invoke(
      $this->textSearchService,
      $data2,
      'hello',
      'goodbye',
      FALSE,
      TRUE // case_sensitive = TRUE
    );

    $this->assertTrue($result2['modified']);
    $this->assertEquals(1, $result2['count']);
    $this->assertEquals('Hello World', $data2['title']); // Should not change (capital H)
    $this->assertEquals('Say goodbye to everyone, HELLO!', $data2['body']); // Only lowercase 'hello' replaced
  }

  /**
   * Tests isValidRegex method.
   *
   * @covers ::isValidRegex
   */
  public function testIsValidRegex() {
    $reflection = new \ReflectionClass($this->textSearchService);
    $method = $reflection->getMethod('isValidRegex');
    $method->setAccessible(TRUE);

    // Test valid regex patterns.
    $this->assertTrue($method->invoke($this->textSearchService, '\d+'));
    $this->assertTrue($method->invoke($this->textSearchService, '[a-zA-Z]+'));
    $this->assertTrue($method->invoke($this->textSearchService, 'test.*'));
    $this->assertTrue($method->invoke($this->textSearchService, '^start'));
    $this->assertTrue($method->invoke($this->textSearchService, 'end$'));

    // Test invalid regex patterns.
    $this->assertFalse($method->invoke($this->textSearchService, '['));
    $this->assertFalse($method->invoke($this->textSearchService, '(unclosed'));
    $this->assertFalse($method->invoke($this->textSearchService, '*invalid'));
    $this->assertFalse($method->invoke($this->textSearchService, '(?P<'));
  }

  /**
   * Tests comprehensive search and replace workflow.
   *
   * @covers ::searchInText
   * @covers ::performReplace
   * @covers ::countReplacements
   */
  public function testComprehensiveSearchAndReplace() {
    $reflection = new \ReflectionClass($this->textSearchService);
    
    // Get the methods we need.
    $searchMethod = $reflection->getMethod('searchInText');
    $searchMethod->setAccessible(TRUE);
    $replaceMethod = $reflection->getMethod('performReplace');
    $replaceMethod->setAccessible(TRUE);
    $countMethod = $reflection->getMethod('countReplacements');
    $countMethod->setAccessible(TRUE);

    // Test content with various cases.
    $content = 'The Product name is "Product ABC". We also have product XYZ and PRODUCT 123.';

    // First, search case-insensitive.
    $matches = $searchMethod->invoke(
      $this->textSearchService,
      $content,
      'product',
      FALSE,
      FALSE // case_sensitive = FALSE
    );
    $this->assertCount(3, $matches);

    // Count case-insensitive.
    $count = $countMethod->invoke(
      $this->textSearchService,
      $content,
      'product',
      FALSE,
      FALSE // case_sensitive = FALSE
    );
    $this->assertEquals(3, $count);

    // Replace case-insensitive.
    $replaced = $replaceMethod->invoke(
      $this->textSearchService,
      $content,
      'product',
      'item',
      FALSE,
      FALSE // case_sensitive = FALSE
    );
    $this->assertEquals('The item name is "item ABC". We also have item XYZ and item 123.', $replaced);

    // Now test case-sensitive.
    $matches = $searchMethod->invoke(
      $this->textSearchService,
      $content,
      'product',
      FALSE,
      TRUE // case_sensitive = TRUE
    );
    $this->assertCount(1, $matches);

    // Count case-sensitive.
    $count = $countMethod->invoke(
      $this->textSearchService,
      $content,
      'product',
      FALSE,
      TRUE // case_sensitive = TRUE
    );
    $this->assertEquals(1, $count);

    // Replace case-sensitive.
    $replaced = $replaceMethod->invoke(
      $this->textSearchService,
      $content,
      'product',
      'item',
      FALSE,
      TRUE // case_sensitive = TRUE
    );
    $this->assertEquals('The Product name is "Product ABC". We also have item XYZ and PRODUCT 123.', $replaced);
  }

  /**
   * Tests edge cases for searchInText method.
   *
   * @covers ::searchInText
   */
  public function testSearchInTextEdgeCases() {
    $reflection = new \ReflectionClass($this->textSearchService);
    $method = $reflection->getMethod('searchInText');
    $method->setAccessible(TRUE);

    // Test empty search term.
    $matches = $method->invoke(
      $this->textSearchService,
      'Some text',
      '',
      FALSE,
      FALSE
    );
    $this->assertEmpty($matches);

    // Test empty text.
    $matches = $method->invoke(
      $this->textSearchService,
      '',
      'search',
      FALSE,
      FALSE
    );
    $this->assertEmpty($matches);

    // Test Unicode handling.
    $text = 'Café, naïve, résumé';
    $matches = $method->invoke(
      $this->textSearchService,
      $text,
      'café',
      FALSE,
      FALSE // case_insensitive
    );
    $this->assertCount(1, $matches);

    // Test with special characters in search term.
    $text = 'Price is $100.00 (USD)';
    $matches = $method->invoke(
      $this->textSearchService,
      $text,
      '$100.00',
      FALSE,
      TRUE // case_sensitive
    );
    $this->assertCount(1, $matches);
  }

}