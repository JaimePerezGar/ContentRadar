<?php

namespace Drupal\content_radar\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Service for searching text across content fields.
 */
class TextSearchService {
  use StringTranslationTrait;

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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Text field types to search.
   *
   * @var array
   */
  protected $searchableFieldTypes = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
  ];

  /**
   * Constructs a new TextSearchService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->database = $database;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Search for text across all content fields.
   *
   * @param string $search_term
   *   The search term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   * @param array $content_types
   *   Content types to search in.
   * @param string $langcode
   *   The language code to search in. Empty for all languages.
   * @param int $page
   *   The page number.
   * @param int $limit
   *   Items per page.
   *
   * @return array
   *   Array with 'items' and 'total' keys.
   */
  public function search($search_term, $use_regex = FALSE, array $content_types = [], $langcode = '', $page = 0, $limit = 50) {
    $results = [];
    $total = 0;

    try {
      // Get all node types if none specified.
      if (empty($content_types)) {
        $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
        $content_types = array_keys($node_types);
      }

      // Search in each content type.
      foreach ($content_types as $content_type) {
        $results = array_merge($results, $this->searchContentType($content_type, $search_term, $use_regex, $langcode));
      }

      // Sort by relevance and timestamp.
      usort($results, function ($a, $b) {
        // Handle both DateTime objects and timestamps
        $timestampA = is_object($a['changed']) ? $a['changed']->getTimestamp() : $a['changed'];
        $timestampB = is_object($b['changed']) ? $b['changed']->getTimestamp() : $b['changed'];
        return $timestampB - $timestampA;
      });

      $total = count($results);

      // Apply pagination.
      $offset = $page * $limit;
      $results = array_slice($results, $offset, $limit);

    } catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Search error: @message', ['@message' => $e->getMessage()]);
    }

    return [
      'items' => $results,
      'total' => $total,
    ];
  }

  /**
   * Search within a specific content type.
   *
   * @param string $content_type
   *   The content type.
   * @param string $search_term
   *   The search term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   * @param string $langcode
   *   The language code to search in.
   *
   * @return array
   *   Array of results.
   */
  protected function searchContentType($content_type, $search_term, $use_regex, $langcode = '') {
    $results = [];
    
    // Get field definitions for this content type.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $content_type);
    
    // Load all nodes of this type.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $content_type)
      ->accessCheck(TRUE);
    
    $nids = $query->execute();
    
    if (empty($nids)) {
      return $results;
    }
    
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    
    foreach ($nodes as $node) {
      // If specific language is requested, check if node has translation.
      if (!empty($langcode)) {
        if ($node->hasTranslation($langcode)) {
          $node = $node->getTranslation($langcode);
        } else {
          // Skip this node if it doesn't have the requested translation.
          continue;
        }
      }
      // If no specific language, search in all translations.
      else {
        $languages = $node->getTranslationLanguages();
        foreach ($languages as $translation_langcode => $language) {
          $translation = $node->getTranslation($translation_langcode);
          $this->searchNodeTranslation($translation, $search_term, $use_regex, $field_definitions, $results);
        }
        continue;
      }
      
      // Search in the specific node/translation.
      $this->searchNodeTranslation($node, $search_term, $use_regex, $field_definitions, $results);
    }
    
    return $results;
  }

  /**
   * Search within a node translation.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity (specific translation).
   * @param string $search_term
   *   The search term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   * @param array $field_definitions
   *   Field definitions for the content type.
   * @param array &$results
   *   Results array to append to.
   */
  protected function searchNodeTranslation(NodeInterface $node, $search_term, $use_regex, array $field_definitions, array &$results) {
    // Search in title.
    $title_matches = $this->searchInText($node->getTitle(), $search_term, $use_regex);
    if (!empty($title_matches)) {
      foreach ($title_matches as $match) {
        $results[] = $this->createResultItem($node, 'title', $this->t('Title'), $match);
      }
    }
    
    // Search in fields.
    foreach ($field_definitions as $field_name => $field_definition) {
      if (!in_array($field_definition->getType(), $this->searchableFieldTypes)) {
        continue;
      }
      
      if (!$node->hasField($field_name)) {
        continue;
      }
      
      $field_values = $node->get($field_name)->getValue();
      foreach ($field_values as $delta => $value) {
        $text = $value['value'] ?? '';
        if (empty($text)) {
          continue;
        }
        
        $matches = $this->searchInText($text, $search_term, $use_regex);
        if (!empty($matches)) {
          foreach ($matches as $match) {
            $results[] = $this->createResultItem($node, $field_name, $field_definition->getLabel(), $match);
          }
        }
      }
    }
    
    // Search in paragraph fields if they exist.
    $this->searchParagraphs($node, $search_term, $use_regex, $results);
  }

  /**
   * Search within paragraph fields.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param string $search_term
   *   The search term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   * @param array &$results
   *   Results array to append to.
   */
  protected function searchParagraphs(NodeInterface $node, $search_term, $use_regex, array &$results) {
    // Check if paragraphs module is enabled.
    if (!\Drupal::moduleHandler()->moduleExists('paragraphs')) {
      return;
    }
    
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $node->bundle());
    
    foreach ($field_definitions as $field_name => $field_definition) {
      if ($field_definition->getType() === 'entity_reference_revisions' && 
          $field_definition->getSetting('target_type') === 'paragraph') {
        
        if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
          continue;
        }
        
        $paragraphs = $node->get($field_name)->referencedEntities();
        foreach ($paragraphs as $paragraph) {
          if ($paragraph) {
            $this->searchParagraphEntity($paragraph, $node, $search_term, $use_regex, $results);
          }
        }
      }
    }
  }

  /**
   * Search within a paragraph entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $paragraph
   *   The paragraph entity.
   * @param \Drupal\node\NodeInterface $node
   *   The parent node.
   * @param string $search_term
   *   The search term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   * @param array &$results
   *   Results array to append to.
   */
  protected function searchParagraphEntity(EntityInterface $paragraph, NodeInterface $node, $search_term, $use_regex, array &$results, $parent_label = '') {
    $paragraph_fields = $this->entityFieldManager->getFieldDefinitions('paragraph', $paragraph->bundle());
    
    // Get paragraph type label.
    $para_type_label = $this->t('Paragraph');
    if ($paragraph->getEntityType() && $paragraph->bundle()) {
      $para_bundle_entity = $this->entityTypeManager
        ->getStorage('paragraphs_type')
        ->load($paragraph->bundle());
      if ($para_bundle_entity) {
        $para_type_label = $para_bundle_entity->label();
      }
    }
    
    // Build full label path.
    $current_label = $parent_label ? $parent_label . ' > ' . $para_type_label : $para_type_label;
    
    foreach ($paragraph_fields as $field_name => $field_definition) {
      // Check for text fields.
      if (in_array($field_definition->getType(), $this->searchableFieldTypes)) {
        if (!$paragraph->hasField($field_name)) {
          continue;
        }
        
        $field_values = $paragraph->get($field_name)->getValue();
        foreach ($field_values as $value) {
          $text = $value['value'] ?? '';
          if (empty($text)) {
            continue;
          }
          
          $matches = $this->searchInText($text, $search_term, $use_regex);
          if (!empty($matches)) {
            foreach ($matches as $match) {
              $field_label = $this->t('@para_type > @field', [
                '@para_type' => $current_label,
                '@field' => $field_definition->getLabel(),
              ]);
              $results[] = $this->createResultItem($node, $field_name, $field_label, $match);
            }
          }
        }
      }
      // Check for nested paragraphs.
      elseif ($field_definition->getType() === 'entity_reference_revisions' && 
              $field_definition->getSetting('target_type') === 'paragraph') {
        
        if (!$paragraph->hasField($field_name) || $paragraph->get($field_name)->isEmpty()) {
          continue;
        }
        
        $nested_paragraphs = $paragraph->get($field_name)->referencedEntities();
        foreach ($nested_paragraphs as $nested_paragraph) {
          if ($nested_paragraph) {
            // Recursive call for nested paragraphs.
            $this->searchParagraphEntity($nested_paragraph, $node, $search_term, $use_regex, $results, $current_label);
          }
        }
      }
    }
  }

  /**
   * Search for text within a string.
   *
   * @param string $text
   *   The text to search in.
   * @param string $search_term
   *   The search term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   *
   * @return array
   *   Array of matches with context.
   */
  protected function searchInText($text, $search_term, $use_regex) {
    $matches = [];
    
    if ($use_regex) {
      if (preg_match_all('/' . $search_term . '/i', $text, $preg_matches, PREG_OFFSET_CAPTURE)) {
        foreach ($preg_matches[0] as $match) {
          $matches[] = $this->extractContext($text, $match[1], strlen($match[0]));
        }
      }
    } else {
      $search_term_lower = mb_strtolower($search_term);
      $text_lower = mb_strtolower($text);
      $offset = 0;
      
      while (($pos = mb_strpos($text_lower, $search_term_lower, $offset)) !== FALSE) {
        $matches[] = $this->extractContext($text, $pos, mb_strlen($search_term));
        $offset = $pos + 1;
      }
    }
    
    return $matches;
  }

  /**
   * Extract context around a match.
   *
   * @param string $text
   *   The full text.
   * @param int $position
   *   Position of the match.
   * @param int $length
   *   Length of the match.
   * @param int $context_length
   *   Context length on each side.
   *
   * @return array
   *   Array with 'extract' and 'highlighted' keys.
   */
  protected function extractContext($text, $position, $length, $context_length = 100) {
    $start = max(0, $position - $context_length);
    $end = min(strlen($text), $position + $length + $context_length);
    
    $before = substr($text, $start, $position - $start);
    $match = substr($text, $position, $length);
    $after = substr($text, $position + $length, $end - $position - $length);
    
    // Add ellipsis if needed.
    if ($start > 0) {
      $before = '...' . $before;
    }
    if ($end < strlen($text)) {
      $after = $after . '...';
    }
    
    return [
      'extract' => $before . '<mark>' . htmlspecialchars($match) . '</mark>' . $after,
      'position' => $position,
    ];
  }

  /**
   * Create a result item.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param string $field_name
   *   The field name.
   * @param string $field_label
   *   The field label.
   * @param array $match
   *   The match details.
   *
   * @return array
   *   The result item.
   */
  protected function createResultItem(NodeInterface $node, $field_name, $field_label, array $match) {
    $node_type = $node->getType();
    $type_label = $this->t('Unknown');
    
    // Safely get the content type label.
    if ($node_type && method_exists($node_type, 'label')) {
      $type_label = $node_type->label();
    }
    elseif ($node->bundle()) {
      $type_storage = $this->entityTypeManager->getStorage('node_type');
      $type_entity = $type_storage->load($node->bundle());
      if ($type_entity) {
        $type_label = $type_entity->label();
      }
    }
    
    // Get language information.
    $langcode = $node->language()->getId();
    $language_name = $node->language()->getName();
    
    return [
      'entity' => $node,
      'content_type' => $type_label,
      'id' => $node->id(),
      'title' => $node->getTitle(),
      'field_name' => $field_name,
      'field_label' => $field_label,
      'extract' => $match['extract'],
      'status' => $node->isPublished(),
      'changed' => $node->getChangedTimeAcrossTranslations(),
      'langcode' => $langcode,
      'language' => $language_name,
    ];
  }

  /**
   * Get entity usage/references.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return array
   *   Array of referencing entities.
   */
  public function getEntityUsage(EntityInterface $entity) {
    $usage = [];
    
    try {
      // Find all entity reference fields that could reference this entity.
      $entity_type = $entity->getEntityTypeId();
      $bundle = $entity->bundle();
      
      // Query for nodes that reference this entity.
      $query = $this->database->select('node_field_data', 'n');
      $query->fields('n', ['nid', 'title', 'type']);
      
      // Get all entity reference fields.
      $field_map = $this->entityFieldManager->getFieldMapByFieldType('entity_reference');
      
      foreach ($field_map as $referencing_entity_type => $fields) {
        if ($referencing_entity_type !== 'node') {
          continue;
        }
        
        foreach ($fields as $field_name => $field_info) {
          $table_name = 'node__' . $field_name;
          if ($this->database->schema()->tableExists($table_name)) {
            $subquery = $this->database->select($table_name, 'f');
            $subquery->fields('f', ['entity_id']);
            $subquery->condition('f.' . $field_name . '_target_id', $entity->id());
            
            $nids = $subquery->execute()->fetchCol();
            if (!empty($nids)) {
              $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
              foreach ($nodes as $node) {
                $usage[] = [
                  'id' => $node->id(),
                  'title' => $node->getTitle(),
                  'type' => $node->bundle(),
                ];
              }
            }
          }
        }
      }
    } catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error getting entity usage: @message', ['@message' => $e->getMessage()]);
    }
    
    return $usage;
  }

  /**
   * Replace text in content fields.
   *
   * @param string $search_term
   *   The search term.
   * @param string $replace_term
   *   The replacement term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   * @param array $content_types
   *   Content types to search in.
   * @param string $langcode
   *   The language code to replace in.
   * @param bool $dry_run
   *   If TRUE, only simulate the replacement without saving.
   *
   * @return array
   *   Array with 'replaced_count' and 'affected_nodes' keys.
   */
  public function replaceText($search_term, $replace_term, $use_regex = FALSE, array $content_types = [], $langcode = '', $dry_run = FALSE) {
    $replaced_count = 0;
    $affected_nodes = [];
    
    try {
      // Get all node types if none specified.
      if (empty($content_types)) {
        $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
        $content_types = array_keys($node_types);
      }
      
      // Process each content type.
      foreach ($content_types as $content_type) {
        $result = $this->replaceInContentType($content_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run);
        $replaced_count += $result['count'];
        $affected_nodes = array_merge($affected_nodes, $result['nodes']);
      }
      
    } catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Replace error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
    
    return [
      'replaced_count' => $replaced_count,
      'affected_nodes' => $affected_nodes,
    ];
  }

  /**
   * Replace text in a single node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param string $search_term
   *   The search term.
   * @param string $replace_term
   *   The replacement term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   *
   * @return int
   *   Number of replacements made.
   */
  public function replaceInNode(NodeInterface $node, $search_term, $replace_term, $use_regex = FALSE) {
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $node->bundle());
    $result = $this->replaceInNodeTranslation($node, $search_term, $replace_term, $use_regex, $field_definitions, FALSE);
    return $result['count'];
  }

  /**
   * Replace text within a specific content type.
   *
   * @param string $content_type
   *   The content type.
   * @param string $search_term
   *   The search term.
   * @param string $replace_term
   *   The replacement term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   * @param string $langcode
   *   The language code to replace in.
   * @param bool $dry_run
   *   If TRUE, only simulate the replacement.
   *
   * @return array
   *   Array with 'count' and 'nodes' keys.
   */
  protected function replaceInContentType($content_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run) {
    $count = 0;
    $affected_nodes = [];
    
    // Get field definitions for this content type.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $content_type);
    
    // Load all nodes of this type.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $content_type)
      ->accessCheck(TRUE);
    
    $nids = $query->execute();
    
    if (empty($nids)) {
      return ['count' => 0, 'nodes' => []];
    }
    
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    
    foreach ($nodes as $node) {
      // If specific language is requested, process only that translation.
      if (!empty($langcode)) {
        if ($node->hasTranslation($langcode)) {
          $translation = $node->getTranslation($langcode);
          $result = $this->replaceInNodeTranslation($translation, $search_term, $replace_term, $use_regex, $field_definitions, $dry_run);
          if ($result['modified']) {
            $count += $result['count'];
            $affected_nodes[] = [
              'nid' => $node->id(),
              'title' => $translation->getTitle(),
              'type' => $node->bundle(),
              'langcode' => $langcode,
            ];
          }
        }
      }
      // If no specific language, replace in all translations.
      else {
        $languages = $node->getTranslationLanguages();
        foreach ($languages as $translation_langcode => $language) {
          $translation = $node->getTranslation($translation_langcode);
          $result = $this->replaceInNodeTranslation($translation, $search_term, $replace_term, $use_regex, $field_definitions, $dry_run);
          if ($result['modified']) {
            $count += $result['count'];
            $affected_nodes[] = [
              'nid' => $node->id(),
              'title' => $translation->getTitle(),
              'type' => $node->bundle(),
              'langcode' => $translation_langcode,
            ];
          }
        }
      }
    }
    
    return ['count' => $count, 'nodes' => $affected_nodes];
  }

  /**
   * Replace text in a node translation.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity (specific translation).
   * @param string $search_term
   *   The search term.
   * @param string $replace_term
   *   The replacement term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   * @param array $field_definitions
   *   Field definitions for the content type.
   * @param bool $dry_run
   *   If TRUE, only simulate the replacement.
   *
   * @return array
   *   Array with 'modified' and 'count' keys.
   */
  protected function replaceInNodeTranslation(NodeInterface $node, $search_term, $replace_term, $use_regex, array $field_definitions, $dry_run) {
    $count = 0;
    $node_modified = FALSE;
    
    // Replace in title.
    $title = $node->getTitle();
    $new_title = $this->performReplace($title, $search_term, $replace_term, $use_regex);
    if ($title !== $new_title) {
      if (!$dry_run) {
        $node->setTitle($new_title);
      }
      $node_modified = TRUE;
      $count++;
    }
    
    // Replace in fields.
    foreach ($field_definitions as $field_name => $field_definition) {
      if (!in_array($field_definition->getType(), $this->searchableFieldTypes)) {
        continue;
      }
      
      if (!$node->hasField($field_name)) {
        continue;
      }
      
      $field_values = $node->get($field_name)->getValue();
      $field_modified = FALSE;
      
      foreach ($field_values as $delta => &$value) {
        if (isset($value['value'])) {
          $original = $value['value'];
          $new_value = $this->performReplace($original, $search_term, $replace_term, $use_regex);
          
          if ($original !== $new_value) {
            $value['value'] = $new_value;
            $field_modified = TRUE;
            $node_modified = TRUE;
            $count++;
          }
        }
      }
      
      if ($field_modified && !$dry_run) {
        $node->set($field_name, $field_values);
      }
    }
    
    // Replace in paragraphs if they exist.
    if (\Drupal::moduleHandler()->moduleExists('paragraphs')) {
      $para_result = $this->replaceInParagraphs($node, $search_term, $replace_term, $use_regex, $dry_run);
      if ($para_result > 0) {
        $count += $para_result;
        $node_modified = TRUE;
      }
    }
    
    // Save the node if modified.
    if ($node_modified && !$dry_run) {
      $node->save();
    }
    
    return ['modified' => $node_modified, 'count' => $count];
  }

  /**
   * Replace text in paragraph fields.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param string $search_term
   *   The search term.
   * @param string $replace_term
   *   The replacement term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   * @param bool $dry_run
   *   If TRUE, only simulate the replacement.
   *
   * @return int
   *   Number of replacements made.
   */
  protected function replaceInParagraphs(NodeInterface $node, $search_term, $replace_term, $use_regex, $dry_run) {
    $count = 0;
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $node->bundle());
    
    foreach ($field_definitions as $field_name => $field_definition) {
      if ($field_definition->getType() === 'entity_reference_revisions' && 
          $field_definition->getSetting('target_type') === 'paragraph') {
        
        if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
          continue;
        }
        
        $paragraphs = $node->get($field_name)->referencedEntities();
        foreach ($paragraphs as $paragraph) {
          if ($paragraph) {
            $count += $this->replaceInParagraphEntity($paragraph, $search_term, $replace_term, $use_regex, $dry_run);
          }
        }
      }
    }
    
    return $count;
  }

  /**
   * Replace text in a paragraph entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $paragraph
   *   The paragraph entity.
   * @param string $search_term
   *   The search term.
   * @param string $replace_term
   *   The replacement term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   * @param bool $dry_run
   *   If TRUE, only simulate the replacement.
   *
   * @return int
   *   Number of replacements made.
   */
  protected function replaceInParagraphEntity(EntityInterface $paragraph, $search_term, $replace_term, $use_regex, $dry_run) {
    $count = 0;
    $paragraph_fields = $this->entityFieldManager->getFieldDefinitions('paragraph', $paragraph->bundle());
    $paragraph_modified = FALSE;
    
    foreach ($paragraph_fields as $field_name => $field_definition) {
      // Handle text fields.
      if (in_array($field_definition->getType(), $this->searchableFieldTypes)) {
        if (!$paragraph->hasField($field_name)) {
          continue;
        }
        
        $field_values = $paragraph->get($field_name)->getValue();
        $field_modified = FALSE;
        
        foreach ($field_values as $delta => &$value) {
          if (isset($value['value'])) {
            $original = $value['value'];
            $new_value = $this->performReplace($original, $search_term, $replace_term, $use_regex);
            
            if ($original !== $new_value) {
              $value['value'] = $new_value;
              $field_modified = TRUE;
              $paragraph_modified = TRUE;
              $count++;
            }
          }
        }
        
        if ($field_modified && !$dry_run) {
          $paragraph->set($field_name, $field_values);
        }
      }
      // Handle nested paragraphs.
      elseif ($field_definition->getType() === 'entity_reference_revisions' && 
              $field_definition->getSetting('target_type') === 'paragraph') {
        
        if (!$paragraph->hasField($field_name) || $paragraph->get($field_name)->isEmpty()) {
          continue;
        }
        
        $nested_paragraphs = $paragraph->get($field_name)->referencedEntities();
        foreach ($nested_paragraphs as $nested_paragraph) {
          if ($nested_paragraph) {
            // Recursive call for nested paragraphs.
            $nested_count = $this->replaceInParagraphEntity($nested_paragraph, $search_term, $replace_term, $use_regex, $dry_run);
            if ($nested_count > 0) {
              $count += $nested_count;
              $paragraph_modified = TRUE;
            }
          }
        }
      }
    }
    
    // Save the paragraph if modified.
    if ($paragraph_modified && !$dry_run) {
      $paragraph->save();
    }
    
    return $count;
  }

  /**
   * Perform the actual text replacement.
   *
   * @param string $text
   *   The text to search in.
   * @param string $search_term
   *   The search term.
   * @param string $replace_term
   *   The replacement term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   *
   * @return string
   *   The text with replacements made.
   */
  protected function performReplace($text, $search_term, $replace_term, $use_regex) {
    if ($use_regex) {
      // Validate the regex pattern first.
      if (@preg_match('/' . $search_term . '/', '') === FALSE) {
        throw new \InvalidArgumentException('Invalid regular expression pattern.');
      }
      return preg_replace('/' . $search_term . '/', $replace_term, $text);
    }
    else {
      return str_replace($search_term, $replace_term, $text);
    }
  }

  /**
   * Export results to CSV.
   *
   * @param string $search_term
   *   The search term.
   * @param bool $use_regex
   *   Whether to use regular expressions.
   * @param array $content_types
   *   Content types to search in.
   * @param string $langcode
   *   The language code to search in.
   *
   * @return string
   *   The CSV content.
   */
  public function exportToCsv($search_term, $use_regex, array $content_types, $langcode = '') {
    $results = $this->search($search_term, $use_regex, $content_types, $langcode, 0, 10000);
    
    // Add BOM for UTF-8 compatibility with Excel.
    $output = "\xEF\xBB\xBF";
    
    $csv = [];
    $csv[] = [
      'Content Type',
      'ID',
      'Title',
      'Language',
      'Field',
      'Extract',
      'Status',
      'Modified',
      'URL',
    ];
    
    foreach ($results['items'] as $item) {
      $entity = $item['entity'];
      $csv[] = [
        $item['content_type'],
        $item['id'],
        $item['title'],
        isset($item['language']) ? $item['language'] : 'Unknown',
        $item['field_label'],
        strip_tags($item['extract']),
        $item['status'] ? 'Published' : 'Unpublished',
        is_object($item['changed']) ? $item['changed']->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', $item['changed']),
        $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ];
    }
    
    // Use proper CSV formatting.
    $handle = fopen('php://temp', 'r+');
    foreach ($csv as $row) {
      fputcsv($handle, $row);
    }
    rewind($handle);
    $output .= stream_get_contents($handle);
    fclose($handle);
    
    return $output;
  }

}