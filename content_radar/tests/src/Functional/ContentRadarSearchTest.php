<?php

namespace Drupal\Tests\content_radar\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests the Content Radar search functionality.
 *
 * @group content_radar
 */
class ContentRadarSearchTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'text',
    'content_radar',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with permission to search and replace content.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a content type.
    $this->createContentType([
      'type' => 'article',
      'name' => 'Article',
    ]);

    // Create a user with necessary permissions.
    $this->adminUser = $this->drupalCreateUser([
      'access content radar',
      'search content radar',
      'replace content radar',
      'access content',
      'create article content',
      'edit any article content',
    ]);
  }

  /**
   * Tests basic search functionality.
   */
  public function testBasicSearch() {
    // Create test content.
    $node1 = Node::create([
      'type' => 'article',
      'title' => 'Test Article One',
      'body' => [
        'value' => 'This is a test article with some content to search.',
        'format' => 'plain_text',
      ],
    ]);
    $node1->save();

    $node2 = Node::create([
      'type' => 'article',
      'title' => 'Another Test Article',
      'body' => [
        'value' => 'This article also contains test content for searching.',
        'format' => 'plain_text',
      ],
    ]);
    $node2->save();

    // Login as admin user.
    $this->drupalLogin($this->adminUser);

    // Access the search page.
    $this->drupalGet('admin/content/content-radar');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Search term');

    // Perform a search.
    $edit = [
      'search_term' => 'test',
    ];
    $this->submitForm($edit, 'Search');

    // Check results.
    $this->assertSession()->pageTextContains('Found 2 results');
    $this->assertSession()->pageTextContains('Test Article One');
    $this->assertSession()->pageTextContains('Another Test Article');
    $this->assertSession()->pageTextContains('article with some content');
    $this->assertSession()->pageTextContains('article also contains');
  }

  /**
   * Tests case-sensitive search.
   */
  public function testCaseSensitiveSearch() {
    // Create test content with mixed case.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Case Test Article',
      'body' => [
        'value' => 'This has Test, test, and TEST words.',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/content/content-radar');

    // Search case-insensitive (default).
    $edit = [
      'search_term' => 'test',
      'search_options[case_sensitive]' => FALSE,
    ];
    $this->submitForm($edit, 'Search');
    $this->assertSession()->pageTextContains('Found 1 results');

    // Search case-sensitive.
    $edit = [
      'search_term' => 'test',
      'search_options[case_sensitive]' => TRUE,
    ];
    $this->submitForm($edit, 'Search');
    $this->assertSession()->pageTextContains('Found 1 results');
    // Should only find lowercase "test".
  }

  /**
   * Tests regex search functionality.
   */
  public function testRegexSearch() {
    // Create content with patterns.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Contact Information',
      'body' => [
        'value' => 'Call us at 123-456-7890 or email test@example.com',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/content/content-radar');

    // Search for phone pattern.
    $edit = [
      'search_term' => '\d{3}-\d{3}-\d{4}',
      'search_options[use_regex]' => TRUE,
    ];
    $this->submitForm($edit, 'Search');
    $this->assertSession()->pageTextContains('123-456-7890');

    // Search for email pattern.
    $edit = [
      'search_term' => '[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}',
      'search_options[use_regex]' => TRUE,
    ];
    $this->submitForm($edit, 'Search');
    $this->assertSession()->pageTextContains('test@example.com');
  }

  /**
   * Tests replace functionality.
   */
  public function testReplaceContent() {
    // Create test content.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Article to Replace',
      'body' => [
        'value' => 'This old content needs to be replaced with new content.',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/content/content-radar');

    // Search first.
    $edit = [
      'search_term' => 'old content',
    ];
    $this->submitForm($edit, 'Search');
    $this->assertSession()->pageTextContains('Found 1 results');

    // Now search and replace.
    $edit = [
      'search_term' => 'old content',
      'replace_term' => 'new content',
      'replace_mode' => 'all',
      'replace_confirm' => TRUE,
    ];
    $this->submitForm($edit, 'Replace');

    // Verify the content was replaced.
    $node = Node::load($node->id());
    $this->assertStringContainsString('new content needs to be replaced with new content', $node->body->value);
    $this->assertStringNotContainsString('old content', $node->body->value);
  }

  /**
   * Tests access permissions.
   */
  public function testAccessPermissions() {
    // Test anonymous user.
    $this->drupalGet('admin/content/content-radar');
    $this->assertSession()->statusCodeEquals(403);

    // Create user with only access permission.
    $viewUser = $this->drupalCreateUser(['access content radar']);
    $this->drupalLogin($viewUser);
    $this->drupalGet('admin/content/content-radar');
    $this->assertSession()->statusCodeEquals(403);

    // Create user with search but not replace permission.
    $searchUser = $this->drupalCreateUser([
      'access content radar',
      'search content radar',
    ]);
    $this->drupalLogin($searchUser);
    $this->drupalGet('admin/content/content-radar');
    $this->assertSession()->statusCodeEquals(200);
    
    // Should not see replace options.
    $this->assertSession()->fieldNotExists('replace_term');
  }

}