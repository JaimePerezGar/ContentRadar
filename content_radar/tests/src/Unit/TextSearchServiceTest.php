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
      FALSE
    );
    $this->assertEquals('goodbye World, goodbye universe', $result);

    // Test with regex.
    $result = $method->invoke(
      $this->textSearchService,
      'The price is $100 or $200',
      '\$\d+',
      '$XXX',
      TRUE
    );
    $this->assertEquals('The price is $XXX or $XXX', $result);
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

    // Test regular text counting.
    $count = $method->invoke(
      $this->textSearchService,
      'Hello world, hello universe, HELLO earth',
      'hello',
      FALSE
    );
    $this->assertEquals(3, $count);

    // Test regex counting.
    $count = $method->invoke(
      $this->textSearchService,
      'Contact: 123-456-7890 or 098-765-4321',
      '\d{3}-\d{3}-\d{4}',
      TRUE
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

    $result = $method->invoke(
      $this->textSearchService,
      $data,
      'hello',
      'goodbye',
      FALSE
    );

    $this->assertTrue($result['modified']);
    $this->assertEquals(3, $result['count']);
    $this->assertEquals('goodbye World', $data['title']);
    $this->assertEquals('Say goodbye to everyone', $data['nested']['body']);
    $this->assertEquals('Goodbye and goodbye again', $data['nested']['footer']);
    $this->assertEquals(123, $data['number']); // Should not change.
  }

}