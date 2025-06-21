<?php

namespace Drupal\content_radar\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\layout_builder\Section;

/**
 * Service for searching and replacing text across content fields.
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
   * The logger factory.
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
   * Entity reference field types to search recursively.
   *
   * @var array
   */
  protected $referenceFieldTypes = [
    'entity_reference',
    'entity_reference_revisions',
  ];

  /**
   * Complex field types that may contain text.
   *
   * @var array
   */
  protected $complexFieldTypes = [
    'link',
    'text_with_summary',
    'formatted_text',
    'image',
    'file',
  ];

  /**
   * Constructs a new TextSearchService.
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
   * Search for text across entities.
   *
   * @param string $search_term
   *   The text to search for.
   * @param bool $use_regex
   *   Whether to use regex for searching.
   * @param array $entity_types
   *   The entity types to search in.
   * @param array $content_types
   *   The content types to search in.
   * @param string $langcode
   *   The language code to search in.
   * @param int $page
   *   The page number for pagination.
   * @param int $limit
   *   The number of results per page.
   * @param array $paragraph_types
   *   The paragraph types to search in.
   * @param bool $case_sensitive
   *   Whether the search should be case sensitive.
   * @param array $node_ids
   *   An array of specific node IDs to search within.
   *
   * @return array
   *   The search results.
   */
  public function search($search_term, $use_regex = FALSE, array $entity_types = [], array $content_types = [], $langcode = '', $page = 0, $limit = 50, array $paragraph_types = [], $case_sensitive = FALSE, array $node_ids = []) {
    $results = [];
    $total = 0;

    try {
      // If no entity types specified, search all searchable entities.
      if (empty($entity_types)) {
        $entity_types = $this->getSearchableEntityTypes();
      }

      // Search in each entity type.
      foreach ($entity_types as $entity_type) {
        if ($entity_type === 'node') {
          if (!empty($node_ids)) {
            // Search only in specific nodes
            $results = array_merge($results, $this->searchSpecificNodes($node_ids, $search_term, $use_regex, $langcode, $case_sensitive));
          }
          elseif (!empty($content_types)) {
            // For nodes with specific content types.
            foreach ($content_types as $content_type) {
              $results = array_merge($results, $this->searchContentType($content_type, $search_term, $use_regex, $langcode, $case_sensitive));
            }
          }
          else {
            // Search all nodes
            $results = array_merge($results, $this->searchEntityType($entity_type, $search_term, $use_regex, $langcode, $case_sensitive));
          }
        }
        elseif ($entity_type === 'paragraph' && !empty($paragraph_types)) {
          // For paragraphs with specific types.
          foreach ($paragraph_types as $paragraph_type) {
            $results = array_merge($results, $this->searchParagraphType($paragraph_type, $search_term, $use_regex, $langcode, $case_sensitive));
          }
        }
        else {
          // For all entity types.
          $results = array_merge($results, $this->searchEntityType($entity_type, $search_term, $use_regex, $langcode, $case_sensitive));
        }
      }

      // Sort by timestamp.
      usort($results, function ($a, $b) {
        $timestampA = isset($a['changed']) ? (is_object($a['changed']) ? $a['changed']->getTimestamp() : $a['changed']) : 0;
        $timestampB = isset($b['changed']) ? (is_object($b['changed']) ? $b['changed']->getTimestamp() : $b['changed']) : 0;
        return $timestampB - $timestampA;
      });

      $total = count($results);

      // Apply pagination.
      $offset = $page * $limit;
      $results = array_slice($results, $offset, $limit);

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Search error: @message', ['@message' => $e->getMessage()]);
    }

    return [
      'items' => $results,
      'total' => $total,
    ];
  }

  /**
   * Deep search for text across all related entities.
   *
   * @param string $search_term
   *   The text to search for.
   * @param bool $use_regex
   *   Whether to use regex for searching.
   * @param array $entity_types
   *   The entity types to search in.
   * @param array $content_types
   *   The content types to search in.
   * @param string $langcode
   *   The language code to search in.
   * @param int $page
   *   The page number for pagination.
   * @param int $limit
   *   The number of results per page.
   * @param array $paragraph_types
   *   The paragraph types to search in.
   * @param bool $case_sensitive
   *   Whether the search should be case sensitive.
   * @param array $node_ids
   *   An array of specific node IDs to search within.
   *
   * @return array
   *   The search results including all related entities.
   */
  public function deepSearch($search_term, $use_regex = FALSE, array $entity_types = [], array $content_types = [], $langcode = '', $page = 0, $limit = 50, array $paragraph_types = [], $case_sensitive = FALSE, array $node_ids = []) {
    $results = [];
    $total = 0;
    $processed = [];

    try {
      // If no entity types specified, search all searchable entities.
      if (empty($entity_types)) {
        $entity_types = $this->getSearchableEntityTypes();
      }

      // For deep search, we search ALL entities and their relationships
      foreach ($entity_types as $entity_type) {
        if ($entity_type === 'node') {
          if (!empty($node_ids)) {
            // Deep search only in specific nodes
            $results = array_merge($results, $this->deepSearchSpecificNodes($node_ids, $search_term, $use_regex, $langcode, $processed, $case_sensitive));
          }
          elseif (!empty($content_types)) {
            // For nodes with specific content types.
            foreach ($content_types as $content_type) {
              $results = array_merge($results, $this->deepSearchContentType($content_type, $search_term, $use_regex, $langcode, $processed, $case_sensitive));
            }
          }
          else {
            // Deep search all nodes
            $results = array_merge($results, $this->deepSearchEntityType($entity_type, $search_term, $use_regex, $langcode, $processed, $case_sensitive));
          }
        }
        elseif ($entity_type === 'paragraph' && !empty($paragraph_types)) {
          // For paragraphs with specific types.
          foreach ($paragraph_types as $paragraph_type) {
            $results = array_merge($results, $this->deepSearchParagraphType($paragraph_type, $search_term, $use_regex, $langcode, $processed, $case_sensitive));
          }
        }
        elseif ($entity_type === 'block_content') {
          // Special comprehensive search for block content including Layout Builder blocks
          $results = array_merge($results, $this->searchAllBlockContent($search_term, $use_regex, $langcode, $processed, $case_sensitive));
        }
        else {
          // For all entity types.
          $results = array_merge($results, $this->deepSearchEntityType($entity_type, $search_term, $use_regex, $langcode, $processed, $case_sensitive));
        }
      }
      
      // Always search ALL block content when doing deep search (for VLSuite/Layout Builder)
      if (in_array('block_content', $entity_types) || empty($entity_types)) {
        $results = array_merge($results, $this->searchAllBlockContent($search_term, $use_regex, $langcode, $processed, $case_sensitive));
      }

      // Sort by timestamp.
      usort($results, function ($a, $b) {
        $timestampA = isset($a['changed']) ? (is_object($a['changed']) ? $a['changed']->getTimestamp() : $a['changed']) : 0;
        $timestampB = isset($b['changed']) ? (is_object($b['changed']) ? $b['changed']->getTimestamp() : $b['changed']) : 0;
        return $timestampB - $timestampA;
      });

      $total = count($results);

      // Apply pagination.
      $offset = $page * $limit;
      $results = array_slice($results, $offset, $limit);

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Deep search error: @message', ['@message' => $e->getMessage()]);
    }

    return [
      'items' => $results,
      'total' => $total,
    ];
  }

  /**
   * Deep search within specific nodes and all their related entities.
   */
  protected function deepSearchSpecificNodes(array $node_ids, $search_term, $use_regex, $langcode = '', array &$processed = [], $case_sensitive = FALSE) {
    $results = [];
    
    if (empty($node_ids)) {
      return $results;
    }
    
    try {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($node_ids);
      
      foreach ($nodes as $node) {
        if (!empty($langcode)) {
          if ($node->hasTranslation($langcode)) {
            $node = $node->getTranslation($langcode);
          }
          else {
            continue;
          }
        }
        
        // Deep search includes ALL entities related to this node
        $this->searchEntity($node, $search_term, $use_regex, $results, $processed, $case_sensitive);
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error deep searching specific nodes: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    
    return $results;
  }

  /**
   * Deep search within a specific content type and all its related entities.
   */
  protected function deepSearchContentType($content_type, $search_term, $use_regex, $langcode = '', array &$processed = [], $case_sensitive = FALSE) {
    $results = [];

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $content_type)
      ->accessCheck(TRUE);

    $nids = $query->execute();

    if (empty($nids)) {
      return $results;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    foreach ($nodes as $node) {
      if (!empty($langcode)) {
        if ($node->hasTranslation($langcode)) {
          $node = $node->getTranslation($langcode);
        }
        else {
          continue;
        }
      }

      // Deep search includes ALL entities related to this node
      $this->searchEntity($node, $search_term, $use_regex, $results, $processed, $case_sensitive);
    }

    return $results;
  }

  /**
   * Deep search within a specific paragraph type and all its related entities.
   */
  protected function deepSearchParagraphType($paragraph_type, $search_term, $use_regex, $langcode = '', array &$processed = [], $case_sensitive = FALSE) {
    $results = [];

    try {
      $storage = $this->entityTypeManager->getStorage('paragraph');
      $query = $storage->getQuery()
        ->condition('type', $paragraph_type)
        ->accessCheck(FALSE);

      $paragraph_ids = $query->execute();

      if (empty($paragraph_ids)) {
        return $results;
      }

      $paragraphs = $storage->loadMultiple($paragraph_ids);

      foreach ($paragraphs as $paragraph) {
        if (!empty($langcode)) {
          if ($paragraph->hasTranslation($langcode)) {
            $paragraph = $paragraph->getTranslation($langcode);
            $this->searchEntity($paragraph, $search_term, $use_regex, $results, $processed, $case_sensitive);
          }
        }
        else {
          $this->searchEntity($paragraph, $search_term, $use_regex, $results, $processed, $case_sensitive);
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error deep searching paragraph type @type: @message', [
        '@type' => $paragraph_type,
        '@message' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Deep search within a specific entity type and all its related entities.
   */
  protected function deepSearchEntityType($entity_type, $search_term, $use_regex, $langcode = '', array &$processed = [], $case_sensitive = FALSE) {
    $results = [];

    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $query = $storage->getQuery();

      if (method_exists($query, 'accessCheck')) {
        $query->accessCheck(TRUE);
      }

      $entity_ids = $query->execute();

      if (empty($entity_ids)) {
        return $results;
      }

      $entities = $storage->loadMultiple($entity_ids);

      foreach ($entities as $entity) {
        if ($entity->getEntityType()->isTranslatable() && $entity instanceof TranslatableInterface) {
          if (!empty($langcode)) {
            if ($entity->hasTranslation($langcode)) {
              $entity = $entity->getTranslation($langcode);
              $this->searchEntity($entity, $search_term, $use_regex, $results, $processed, $case_sensitive);
            }
          }
          else {
            $languages = $entity->getTranslationLanguages();
            foreach ($languages as $translation_langcode => $language) {
              $translation = $entity->getTranslation($translation_langcode);
              $this->searchEntity($translation, $search_term, $use_regex, $results, $processed, $case_sensitive);
            }
          }
        }
        else {
          $this->searchEntity($entity, $search_term, $use_regex, $results, $processed, $case_sensitive);
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error deep searching entity type @type: @message', [
        '@type' => $entity_type,
        '@message' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Search specifically in all block content entities including Layout Builder blocks.
   */
  protected function searchAllBlockContent($search_term, $use_regex, $langcode = '', array &$processed = [], $case_sensitive = FALSE) {
    $results = [];
    
    try {
      // Search in regular block_content entities
      $block_storage = $this->entityTypeManager->getStorage('block_content');
      $query = $block_storage->getQuery()->accessCheck(FALSE);
      $block_ids = $query->execute();
      
      if (!empty($block_ids)) {
        $blocks = $block_storage->loadMultiple($block_ids);
        
        foreach ($blocks as $block) {
          if (!empty($langcode)) {
            if ($block->hasTranslation($langcode)) {
              $block = $block->getTranslation($langcode);
              $this->searchEntity($block, $search_term, $use_regex, $results, $processed, $case_sensitive);
            }
          }
          else {
            $this->searchEntity($block, $search_term, $use_regex, $results, $processed, $case_sensitive);
          }
        }
      }
      
      // Also search all revisions of block_content (for Layout Builder inline blocks)
      $revision_query = $block_storage->getQuery()
        ->allRevisions()
        ->accessCheck(FALSE);
      $revision_ids = $revision_query->execute();
      
      foreach ($revision_ids as $revision_id => $entity_id) {
        try {
          $block_revision = $block_storage->loadRevision($revision_id);
          if ($block_revision && !isset($processed['block_content:' . $revision_id])) {
            $processed['block_content:' . $revision_id] = TRUE;
            
            if (!empty($langcode)) {
              if ($block_revision->hasTranslation($langcode)) {
                $block_revision = $block_revision->getTranslation($langcode);
                $this->searchEntity($block_revision, $search_term, $use_regex, $results, $processed, $case_sensitive);
              }
            }
            else {
              $this->searchEntity($block_revision, $search_term, $use_regex, $results, $processed, $case_sensitive);
            }
          }
        }
        catch (\Exception $e) {
          // Continue if revision loading fails
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error searching all block content: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    
    return $results;
  }

  /**
   * Replace text across entities.
   *
   * @param string $search_term
   *   The text to search for.
   * @param string $replace_term
   *   The text to replace with.
   * @param bool $use_regex
   *   Whether to use regex for searching.
   * @param array $entity_types
   *   The entity types to search in.
   * @param array $content_types
   *   The content types to search in.
   * @param string $langcode
   *   The language code to search in.
   * @param bool $dry_run
   *   Whether to perform a dry run without saving.
   * @param array $selected_items
   *   Selected items to replace (for selective replacement).
   * @param array $paragraph_types
   *   The paragraph types to search in.
   * @param bool $case_sensitive
   *   Whether the search should be case sensitive.
   * @param array $node_ids
   *   An array of specific node IDs to search within.
   *
   * @return array
   *   Array containing replaced_count and affected_entities.
   */
  public function replaceText($search_term, $replace_term, $use_regex = FALSE, array $entity_types = [], array $content_types = [], $langcode = '', $dry_run = FALSE, array $selected_items = [], array $paragraph_types = [], $case_sensitive = FALSE, array $node_ids = []) {
    $replaced_count = 0;
    $affected_entities = [];

    try {
      if (!empty($selected_items)) {
        // SOLO reemplazo selectivo
        $result = $this->replaceInSelectedItems($selected_items, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive);
        $replaced_count = $result['count'];
        $affected_entities = $result['entities'];
        
        // Log para confirmar
        $this->loggerFactory->get('content_radar')->info('Selective replacement completed: @count replacements in @entities entities', [
          '@count' => $replaced_count,
          '@entities' => count($affected_entities)
        ]);
      }
      else {
        // SOLO si NO hay elementos seleccionados, hacer reemplazo masivo
        // Replace in all matching items.
        if (empty($entity_types)) {
          $entity_types = $this->getSearchableEntityTypes();
        }

        foreach ($entity_types as $entity_type) {
          if ($entity_type === 'node') {
            if (!empty($node_ids)) {
              // Replace only in specific nodes
              $result = $this->replaceInSpecificNodes($node_ids, $search_term, $replace_term, $use_regex, $langcode, $dry_run, $case_sensitive);
              $replaced_count += $result['count'];
              $affected_entities = array_merge($affected_entities, $result['entities']);
            }
            elseif (!empty($content_types)) {
              foreach ($content_types as $content_type) {
                $result = $this->replaceInContentType($content_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run, $case_sensitive);
                $replaced_count += $result['count'];
                $affected_entities = array_merge($affected_entities, $result['entities']);
              }
            }
            else {
              // Replace in all nodes
              $result = $this->replaceInEntityType($entity_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run, $case_sensitive);
              $replaced_count += $result['count'];
              $affected_entities = array_merge($affected_entities, $result['entities']);
            }
          }
          elseif ($entity_type === 'paragraph' && !empty($paragraph_types)) {
            foreach ($paragraph_types as $paragraph_type) {
              $result = $this->replaceInParagraphType($paragraph_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run, $case_sensitive);
              $replaced_count += $result['count'];
              $affected_entities = array_merge($affected_entities, $result['entities']);
            }
          }
          else {
            $result = $this->replaceInEntityType($entity_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run, $case_sensitive);
            $replaced_count += $result['count'];
            $affected_entities = array_merge($affected_entities, $result['entities']);
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Replace error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }

    return [
      'replaced_count' => $replaced_count,
      'affected_entities' => $affected_entities,
    ];
  }

  /**
   * Search within specific nodes by IDs.
   */
  protected function searchSpecificNodes(array $node_ids, $search_term, $use_regex, $langcode = '', $case_sensitive = FALSE) {
    $results = [];
    
    if (empty($node_ids)) {
      return $results;
    }
    
    try {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($node_ids);
      
      foreach ($nodes as $node) {
        if (!empty($langcode)) {
          if ($node->hasTranslation($langcode)) {
            $node = $node->getTranslation($langcode);
          }
          else {
            continue;
          }
        }
        
        $processed = [];
        $this->searchEntity($node, $search_term, $use_regex, $results, $processed, $case_sensitive);
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error searching specific nodes: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    
    return $results;
  }

  /**
   * Search within a specific content type.
   */
  protected function searchContentType($content_type, $search_term, $use_regex, $langcode = '', $case_sensitive = FALSE) {
    $results = [];

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $content_type)
      ->accessCheck(TRUE);

    $nids = $query->execute();

    if (empty($nids)) {
      return $results;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    foreach ($nodes as $node) {
      if (!empty($langcode)) {
        if ($node->hasTranslation($langcode)) {
          $node = $node->getTranslation($langcode);
        }
        else {
          continue;
        }
      }

      $processed = [];
      $this->searchEntity($node, $search_term, $use_regex, $results, $processed, $case_sensitive);
    }

    return $results;
  }

  /**
   * Search within a specific paragraph type.
   */
  protected function searchParagraphType($paragraph_type, $search_term, $use_regex, $langcode = '', $case_sensitive = FALSE) {
    $results = [];

    try {
      $storage = $this->entityTypeManager->getStorage('paragraph');
      $query = $storage->getQuery()
        ->condition('type', $paragraph_type)
        ->accessCheck(FALSE); // Paragraphs usually don't have access control

      $paragraph_ids = $query->execute();

      if (empty($paragraph_ids)) {
        return $results;
      }

      $paragraphs = $storage->loadMultiple($paragraph_ids);

      foreach ($paragraphs as $paragraph) {
        if (!empty($langcode)) {
          if ($paragraph->hasTranslation($langcode)) {
            $paragraph = $paragraph->getTranslation($langcode);
            $processed = [];
            $this->searchEntity($paragraph, $search_term, $use_regex, $results, $processed, $case_sensitive);
          }
        }
        else {
          $processed = [];
          $this->searchEntity($paragraph, $search_term, $use_regex, $results, $processed, $case_sensitive);
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error searching paragraph type @type: @message', [
        '@type' => $paragraph_type,
        '@message' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Search within a specific entity type.
   */
  protected function searchEntityType($entity_type, $search_term, $use_regex, $langcode = '', $case_sensitive = FALSE) {
    $results = [];

    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $query = $storage->getQuery();

      if (method_exists($query, 'accessCheck')) {
        $query->accessCheck(TRUE);
      }

      $entity_ids = $query->execute();

      if (empty($entity_ids)) {
        return $results;
      }

      $entities = $storage->loadMultiple($entity_ids);

      foreach ($entities as $entity) {
        if ($entity->getEntityType()->isTranslatable() && $entity instanceof TranslatableInterface) {
          if (!empty($langcode)) {
            if ($entity->hasTranslation($langcode)) {
              $entity = $entity->getTranslation($langcode);
              $processed = [];
              $this->searchEntity($entity, $search_term, $use_regex, $results, $processed, $case_sensitive);
            }
          }
          else {
            $languages = $entity->getTranslationLanguages();
            foreach ($languages as $translation_langcode => $language) {
              $translation = $entity->getTranslation($translation_langcode);
              $processed = [];
              $this->searchEntity($translation, $search_term, $use_regex, $results, $processed, $case_sensitive);
            }
          }
        }
        else {
          $processed = [];
          $this->searchEntity($entity, $search_term, $use_regex, $results, $processed, $case_sensitive);
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error searching entity type @type: @message', [
        '@type' => $entity_type,
        '@message' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Search within an entity.
   */
  protected function searchEntity(EntityInterface $entity, $search_term, $use_regex, array &$results, array &$processed = [], $case_sensitive = FALSE) {
    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $entity_id = $entity->id();
    
    // Prevent infinite recursion by tracking processed entities.
    $entity_key = $entity_type_id . ':' . $entity_id;
    if (isset($processed[$entity_key])) {
      return;
    }
    $processed[$entity_key] = TRUE;

    // Get field definitions.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);

    // Search in label/title field if it exists.
    $label_key = $entity->getEntityType()->getKey('label');
    if ($label_key && $entity->hasField($label_key)) {
      $label = $entity->get($label_key)->value;
      $matches = $this->searchInText($label, $search_term, $use_regex, $case_sensitive);
      if (!empty($matches)) {
        foreach ($matches as $match) {
          $results[] = $this->createResultItem($entity, $label_key, $this->t('Title'), $match);
        }
      }
    }

    // Search in fields.
    foreach ($field_definitions as $field_name => $field_definition) {
      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field_type = $field_definition->getType();
      
      // Handle text fields.
      if (in_array($field_type, $this->searchableFieldTypes)) {
        $this->searchTextField($entity, $field_name, $field_definition, $search_term, $use_regex, $results, $case_sensitive);
      }
      // Handle entity reference fields (ANY type that could reference entities).
      elseif ($this->isReferenceField($field_definition)) {
        $this->searchReferenceField($entity, $field_name, $field_definition, $search_term, $use_regex, $results, $processed, $case_sensitive);
      }
      // Handle complex fields that may contain text.
      elseif (in_array($field_type, $this->complexFieldTypes)) {
        $this->searchComplexField($entity, $field_name, $field_definition, $search_term, $use_regex, $results, $case_sensitive);
      }
      // Handle Layout Builder fields specifically.
      elseif ($field_type === 'layout_section') {
        $this->searchLayoutBuilderField($entity, $field_name, $field_definition, $search_term, $use_regex, $results, $processed, $case_sensitive);
      }
      // For any other field type, try to search for text content.
      else {
        $this->searchGenericField($entity, $field_name, $field_definition, $search_term, $use_regex, $results, $case_sensitive);
      }
    }
  }

  /**
   * Check if a field is a reference field that could contain entities.
   */
  protected function isReferenceField($field_definition) {
    $field_type = $field_definition->getType();
    
    // Known entity reference types
    $reference_types = [
      'entity_reference',
      'entity_reference_revisions',
      'dynamic_entity_reference',
      'field_collection',
      'layout_builder',
      'block_field',
    ];
    
    // Check if it's a known reference type
    if (in_array($field_type, $reference_types)) {
      return TRUE;
    }
    
    // Check if the field has entity target settings
    $settings = $field_definition->getSettings();
    if (isset($settings['target_type']) && !empty($settings['target_type'])) {
      return TRUE;
    }
    
    // Check field definition class for entity reference
    $field_class = $field_definition->getClass();
    if (strpos($field_class, 'EntityReference') !== FALSE) {
      return TRUE;
    }
    
    return FALSE;
  }

  /**
   * Search in any generic field for text content.
   */
  protected function searchGenericField(EntityInterface $entity, $field_name, $field_definition, $search_term, $use_regex, array &$results, $case_sensitive = FALSE) {
    try {
      $field_items = $entity->get($field_name);
      
      foreach ($field_items as $delta => $item) {
        // Try to get all properties of the field item
        $properties = [];
        
        // Common text properties to check
        $text_properties = ['value', 'title', 'alt', 'summary', 'description', 'caption', 'uri', 'options', 'settings'];
        
        foreach ($text_properties as $property) {
          if (isset($item->{$property}) && is_string($item->{$property}) && !empty($item->{$property})) {
            $properties[$property] = $item->{$property};
          }
        }
        
        // If the item itself is a string
        if (is_string($item->value) && !empty($item->value)) {
          $properties['value'] = $item->value;
        }
        
        // Try to unserialize data to search in serialized content
        if (isset($item->value) && is_string($item->value)) {
          $unserialized = @unserialize($item->value);
          if ($unserialized !== FALSE && is_array($unserialized)) {
            $this->searchArrayRecursively($unserialized, $entity, $field_name, $field_definition->getLabel(), $search_term, $use_regex, $results, '', $case_sensitive);
          }
        }
        
        // Search in all found text properties
        foreach ($properties as $property => $text) {
          $matches = $this->searchInText($text, $search_term, $use_regex, $case_sensitive);
          if (!empty($matches)) {
            foreach ($matches as $match) {
              $field_label = $field_definition->getLabel();
              if ($property !== 'value') {
                $field_label .= ' (' . ucfirst($property) . ')';
              }
              $results[] = $this->createResultItem($entity, $field_name, $field_label, $match);
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      // Log the error but continue with other fields.
      $this->loggerFactory->get('content_radar')->warning('Error searching generic field @field in entity @type:@id: @message', [
        '@field' => $field_name,
        '@type' => $entity->getEntityTypeId(),
        '@id' => $entity->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Search within a text field.
   */
  protected function searchTextField(EntityInterface $entity, $field_name, $field_definition, $search_term, $use_regex, array &$results, $case_sensitive = FALSE) {
    $field_values = $entity->get($field_name)->getValue();
    foreach ($field_values as $delta => $value) {
      // Handle different text field structures
      $searchable_parts = [];
      
      if (isset($value['value']) && !empty($value['value'])) {
        $searchable_parts['value'] = $value['value'];
      }
      
      if (isset($value['summary']) && !empty($value['summary'])) {
        $searchable_parts['summary'] = $value['summary'];
      }
      
      // If no structured parts, try direct value
      if (empty($searchable_parts) && is_string($value)) {
        $searchable_parts['value'] = $value;
      }
      
      foreach ($searchable_parts as $part_name => $text) {
        if (empty($text)) {
          continue;
        }

        $matches = $this->searchInText($text, $search_term, $use_regex, $case_sensitive);
        if (!empty($matches)) {
          foreach ($matches as $match) {
            $field_label = $field_definition->getLabel();
            if ($part_name === 'summary') {
              $field_label .= ' (Summary)';
            }
            $results[] = $this->createResultItem($entity, $field_name, $field_label, $match);
          }
        }
      }
    }
  }

  /**
   * Search within an entity reference field recursively.
   */
  protected function searchReferenceField(EntityInterface $entity, $field_name, $field_definition, $search_term, $use_regex, array &$results, array &$processed, $case_sensitive = FALSE) {
    try {
      $field_items = $entity->get($field_name);
      
      foreach ($field_items as $item) {
        // Try multiple ways to get the referenced entity
        $referenced_entity = NULL;
        
        // Method 1: Direct entity property
        if (property_exists($item, 'entity') && $item->entity) {
          $referenced_entity = $item->entity;
        }
        // Method 2: Try to load by target_id and target_type
        elseif (property_exists($item, 'target_id') && !empty($item->target_id)) {
          $target_type = NULL;
          
          // Get target type from field settings
          $settings = $field_definition->getSettings();
          if (isset($settings['target_type'])) {
            $target_type = $settings['target_type'];
          }
          // Try to get from item properties
          elseif (property_exists($item, 'target_type') && !empty($item->target_type)) {
            $target_type = $item->target_type;
          }
          
          if ($target_type && $this->entityTypeManager->hasDefinition($target_type)) {
            try {
              $storage = $this->entityTypeManager->getStorage($target_type);
              $referenced_entity = $storage->load($item->target_id);
            }
            catch (\Exception $e) {
              // Continue to next item if load fails
              continue;
            }
          }
        }
        
        if (!$referenced_entity) {
          continue;
        }

        // Search recursively in the referenced entity.
        $this->searchEntity($referenced_entity, $search_term, $use_regex, $results, $processed, $case_sensitive);
      }
    }
    catch (\Exception $e) {
      // Log the error but continue with other fields.
      $this->loggerFactory->get('content_radar')->warning('Error searching reference field @field in entity @type:@id: @message', [
        '@field' => $field_name,
        '@type' => $entity->getEntityTypeId(),
        '@id' => $entity->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Search within a complex field that may contain text.
   */
  protected function searchComplexField(EntityInterface $entity, $field_name, $field_definition, $search_term, $use_regex, array &$results, $case_sensitive = FALSE) {
    try {
      $field_items = $entity->get($field_name);
      
      foreach ($field_items as $delta => $item) {
        $field_type = $field_definition->getType();
        $searchable_values = [];
        
        switch ($field_type) {
          case 'link':
            // Search in both title and uri
            if (!empty($item->title)) {
              $searchable_values['title'] = $item->title;
            }
            if (!empty($item->uri)) {
              $searchable_values['uri'] = $item->uri;
            }
            break;
            
          case 'image':
          case 'file':
            // Search in alt text, title, description
            if (!empty($item->alt)) {
              $searchable_values['alt'] = $item->alt;
            }
            if (!empty($item->title)) {
              $searchable_values['title'] = $item->title;
            }
            if (!empty($item->description)) {
              $searchable_values['description'] = $item->description;
            }
            break;
            
          case 'text_with_summary':
            // Search in both value and summary
            if (!empty($item->value)) {
              $searchable_values['value'] = $item->value;
            }
            if (!empty($item->summary)) {
              $searchable_values['summary'] = $item->summary;
            }
            break;
            
          case 'formatted_text':
            // Search in value
            if (!empty($item->value)) {
              $searchable_values['value'] = $item->value;
            }
            break;
        }
        
        // Search in all collected values
        foreach ($searchable_values as $property => $text) {
          if (empty($text)) {
            continue;
          }
          
          $matches = $this->searchInText($text, $search_term, $use_regex, $case_sensitive);
          if (!empty($matches)) {
            foreach ($matches as $match) {
              $field_label = $field_definition->getLabel();
              if ($property !== 'value') {
                $field_label .= ' (' . ucfirst($property) . ')';
              }
              $results[] = $this->createResultItem($entity, $field_name, $field_label, $match);
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      // Log the error but continue with other fields.
      $this->loggerFactory->get('content_radar')->warning('Error searching complex field @field in entity @type:@id: @message', [
        '@field' => $field_name,
        '@type' => $entity->getEntityTypeId(),
        '@id' => $entity->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Search within Layout Builder fields for text in blocks and components.
   */
  protected function searchLayoutBuilderField(EntityInterface $entity, $field_name, $field_definition, $search_term, $use_regex, array &$results, array &$processed, $case_sensitive = FALSE) {
    try {
      $field_items = $entity->get($field_name);
      
      foreach ($field_items as $delta => $item) {
        // Get the section object
        $section = $item->section;
        if (!$section) {
          continue;
        }
        
        // Search in all components of the section
        $components = $section->getComponents();
        foreach ($components as $component) {
          $this->searchLayoutBuilderComponent($entity, $component, $field_name, $search_term, $use_regex, $results, $processed, $case_sensitive);
        }
      }
    }
    catch (\Exception $e) {
      // Log the error but continue with other fields.
      $this->loggerFactory->get('content_radar')->warning('Error searching Layout Builder field @field in entity @type:@id: @message', [
        '@field' => $field_name,
        '@type' => $entity->getEntityTypeId(),
        '@id' => $entity->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Search within a Layout Builder component for text content.
   */
  protected function searchLayoutBuilderComponent(EntityInterface $parent_entity, $component, $field_name, $search_term, $use_regex, array &$results, array &$processed, $case_sensitive = FALSE) {
    try {
      $plugin = $component->getPlugin();
      $configuration = $plugin->getConfiguration();
      
      // Get block label/title for better identification
      $block_label = 'Layout Builder Block';
      if (isset($configuration['label']) && !empty($configuration['label'])) {
        $block_label = $configuration['label'];
      }
      elseif (isset($configuration['info']) && !empty($configuration['info'])) {
        $block_label = $configuration['info'];
      }
      elseif (isset($configuration['admin_label']) && !empty($configuration['admin_label'])) {
        $block_label = $configuration['admin_label'];
      }
      
      // Add plugin ID for identification
      $plugin_id = $plugin->getPluginId();
      if ($plugin_id) {
        $block_label .= ' (' . $this->getReadablePluginId($plugin_id) . ')';
      }
      
      // Search in plugin configuration for text
      $this->searchArrayRecursively($configuration, $parent_entity, $field_name, $block_label, $search_term, $use_regex, $results, '', $case_sensitive);
      
      // If it's an inline block, search the actual block entity
      if (isset($configuration['block_revision_id']) && !empty($configuration['block_revision_id'])) {
        try {
          $block_storage = $this->entityTypeManager->getStorage('block_content');
          $block = $block_storage->loadRevision($configuration['block_revision_id']);
          
          if ($block) {
            // Search recursively in the block entity
            $this->searchEntity($block, $search_term, $use_regex, $results, $processed, $case_sensitive);
          }
        }
        catch (\Exception $e) {
          // Continue if block loading fails
        }
      }
      
      // If it's a reusable block, search by UUID
      if (isset($configuration['id']) && strpos($configuration['id'], 'block_content:') === 0) {
        try {
          $uuid = str_replace('block_content:', '', $configuration['id']);
          $block_storage = $this->entityTypeManager->getStorage('block_content');
          $blocks = $block_storage->loadByProperties(['uuid' => $uuid]);
          
          if (!empty($blocks)) {
            $block = reset($blocks);
            $this->searchEntity($block, $search_term, $use_regex, $results, $processed, $case_sensitive);
          }
        }
        catch (\Exception $e) {
          // Continue if block loading fails
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->warning('Error searching Layout Builder component: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Search recursively through an array structure for text content.
   */
  protected function searchArrayRecursively(array $data, EntityInterface $entity, $field_name, $field_label, $search_term, $use_regex, array &$results, $key_path = '', $case_sensitive = FALSE) {
    foreach ($data as $key => $value) {
      $current_path = $key_path ? $key_path . '.' . $key : $key;
      
      if (is_string($value) && !empty($value)) {
        // Skip certain keys that typically don't contain searchable text
        $skip_keys = ['id', 'uuid', 'revision_id', 'bundle', 'langcode', 'status', 'created', 'changed'];
        if (in_array($key, $skip_keys)) {
          continue;
        }
        
        $matches = $this->searchInText($value, $search_term, $use_regex, $case_sensitive);
        if (!empty($matches)) {
          foreach ($matches as $match) {
            // Create a more readable label for array paths
            $readable_path = $this->makeReadablePath($current_path);
            $label = $field_label;
            if (!empty($readable_path)) {
              $label .= ' (' . $readable_path . ')';
            }
            $results[] = $this->createResultItem($entity, $field_name, $label, $match);
          }
        }
      }
      elseif (is_array($value)) {
        $this->searchArrayRecursively($value, $entity, $field_name, $field_label, $search_term, $use_regex, $results, $current_path, $case_sensitive);
      }
    }
  }

  /**
   * Search for text within a string.
   */
  protected function searchInText($text, $search_term, $use_regex, $case_sensitive = FALSE) {
    $matches = [];
    
    // Debug logging
    static $logged = FALSE;
    if (!$logged) {
      $this->loggerFactory->get('content_radar')->debug('searchInText called with case_sensitive=@case', [
        '@case' => $case_sensitive ? 'TRUE' : 'FALSE',
      ]);
      $logged = TRUE;
    }

    if ($use_regex) {
      // Validate regex pattern for security.
      if (!$this->isValidRegex($search_term)) {
        return $matches;
      }
      $flags = $case_sensitive ? '' : 'i';
      if (@preg_match_all('/' . $search_term . '/' . $flags, $text, $preg_matches, PREG_OFFSET_CAPTURE) !== FALSE) {
        foreach ($preg_matches[0] as $match) {
          $matches[] = $this->extractContext($text, $match[1], strlen($match[0]));
        }
      }
    }
    else {
      if ($case_sensitive) {
        $offset = 0;
        while (($pos = mb_strpos($text, $search_term, $offset)) !== FALSE) {
          $matches[] = $this->extractContext($text, $pos, mb_strlen($search_term));
          $offset = $pos + 1;
        }
      }
      else {
        $search_term_lower = mb_strtolower($search_term);
        $text_lower = mb_strtolower($text);
        $offset = 0;

        while (($pos = mb_strpos($text_lower, $search_term_lower, $offset)) !== FALSE) {
          $matches[] = $this->extractContext($text, $pos, mb_strlen($search_term));
          $offset = $pos + 1;
        }
      }
    }

    return $matches;
  }

  /**
   * Extract context around a match.
   */
  protected function extractContext($text, $position, $length, $context_length = 100) {
    // Store the original match from the original text
    $original_match = mb_substr($text, $position, $length);
    
    // First, check if the text appears to be serialized data and clean it
    $cleaned_text = $this->cleanSerializedText($text);
    
    // If text was cleaned, we need to use the original match for display
    // but show cleaned context around it
    if ($cleaned_text !== $text) {
      $text = $cleaned_text;
      // Don't try to find new position - just use the cleaned text for context
      // This avoids highlighting position issues
      $match = $original_match;
    } else {
      $match = $original_match;
    }
    
    // For cleaned text, we'll just show context from the beginning since position may be invalid
    if ($cleaned_text !== $text) {
      // Show a reasonable amount of cleaned text with the match highlighted
      $text_length = mb_strlen($text);
      $excerpt_length = min($text_length, $context_length * 2);
      $excerpt = mb_substr($text, 0, $excerpt_length);
      
      // Find the match in the excerpt to highlight it properly
      $match_pos = mb_stripos($excerpt, $match);
      if ($match_pos !== FALSE) {
        $before = mb_substr($excerpt, 0, $match_pos);
        $after = mb_substr($excerpt, $match_pos + mb_strlen($match));
      } else {
        // If match not found in excerpt, just show the excerpt with match at end
        $before = $excerpt . ' ... ';
        $after = '';
      }
    } else {
      // For non-cleaned text, use the original position-based approach
      $start = max(0, $position - $context_length);
      $end = min(mb_strlen($text), $position + $length + $context_length);

      $before = mb_substr($text, $start, $position - $start);
      $after = mb_substr($text, $position + $length, $end - $position - $length);
      
      // Add ellipsis if needed for non-cleaned text
      if ($start > 0) {
        $before = '...' . $before;
      }
      if ($end < mb_strlen($text)) {
        $after = $after . '...';
      }
    }

    // Clean up the context parts
    $before = $this->cleanContextText($before);
    $after = $this->cleanContextText($after);

    return [
      'extract' => $before . '<mark>' . htmlspecialchars($match) . '</mark>' . $after,
      'position' => $position,
    ];
  }
  
  /**
   * Clean serialized text to make it more readable.
   */
  protected function cleanSerializedText($text) {
    // Check if this is serialized data
    if (preg_match('/^[aos]:\d+:/', $text)) {
      // Try to unserialize
      $unserialized = @unserialize($text);
      if ($unserialized !== FALSE) {
        return $this->convertArrayToReadableText($unserialized);
      }
    }
    
    // Clean up common serialized patterns
    $text = preg_replace('/[aos]:\d+:({|")/', '', $text);
    $text = preg_replace('/";s:\d+:"/', ' | ', $text);
    $text = preg_replace('/";i:\d+;/', '', $text);
    $text = preg_replace('/";?}/', '', $text);
    
    return $text;
  }
  
  /**
   * Convert array data to readable text.
   */
  protected function convertArrayToReadableText($data, $prefix = '') {
    if (is_string($data)) {
      return trim($data);
    }
    
    if (is_array($data)) {
      $parts = [];
      foreach ($data as $key => $value) {
        if (is_numeric($key)) {
          $readable = $this->convertArrayToReadableText($value);
          if (!empty($readable)) {
            $parts[] = $readable;
          }
        }
        else {
          $readable = $this->convertArrayToReadableText($value);
          if (!empty($readable)) {
            // Make field names more readable
            $key = str_replace('_', ' ', $key);
            $key = str_replace('field ', '', $key);
            $parts[] = ucfirst($key) . ': ' . $readable;
          }
        }
      }
      return implode(' | ', $parts);
    }
    
    return '';
  }
  
  /**
   * Clean up context text for display.
   */
  protected function cleanContextText($text) {
    // Remove multiple spaces
    $text = preg_replace('/\s+/', ' ', $text);
    
    // Remove leading/trailing punctuation
    $text = trim($text, ' .,;:{}[]"\'');
    
    return $text;
  }
  
  /**
   * Make a field path more readable for display.
   */
  protected function makeReadablePath($path) {
    // Remove numeric indices
    $path = preg_replace('/\.\d+\.?/', '.', $path);
    
    // Clean up common patterns
    $path = str_replace('..', '.', $path);
    $path = trim($path, '.');
    
    // Make field names more readable
    $parts = explode('.', $path);
    $readable_parts = [];
    
    foreach ($parts as $part) {
      // Skip common technical keys
      if (in_array($part, ['value', 'target_id', 'entity'])) {
        continue;
      }
      
      // Clean up field names
      $part = str_replace('field_', '', $part);
      $part = str_replace('_', ' ', $part);
      $part = ucwords($part);
      
      if (!empty($part)) {
        $readable_parts[] = $part;
      }
    }
    
    return implode(' > ', $readable_parts);
  }
  
  /**
   * Get a readable version of a plugin ID.
   */
  protected function getReadablePluginId($plugin_id) {
    // Common plugin ID mappings
    $mappings = [
      'inline_block' => 'Inline Block',
      'block_content' => 'Custom Block',
      'system_menu_block' => 'Menu Block',
      'views_block' => 'Views Block',
      'field_block' => 'Field Block',
    ];
    
    // Check for direct mapping
    if (isset($mappings[$plugin_id])) {
      return $mappings[$plugin_id];
    }
    
    // Clean up plugin ID
    $readable = str_replace([':', '_', '-'], ' ', $plugin_id);
    $readable = ucwords($readable);
    
    return $readable;
  }

  /**
   * Create a result item.
   */
  protected function createResultItem(EntityInterface $entity, $field_name, $field_label, array $match) {
    $entity_type = $entity->getEntityType();
    $entity_type_label = $entity_type->getLabel();
    $bundle = $entity->bundle();

    // Get bundle label.
    $bundle_label = $bundle;
    if ($entity_type->getBundleEntityType()) {
      $bundle_entity = $this->entityTypeManager
        ->getStorage($entity_type->getBundleEntityType())
        ->load($bundle);
      if ($bundle_entity) {
        $bundle_label = $bundle_entity->label();
      }
    }

    // Get entity label.
    $label = $entity->label() ?: $this->t('Untitled');

    // Get language information.
    $langcode = 'und';
    $language_name = $this->t('Language neutral');
    if (method_exists($entity, 'language')) {
      $language = $entity->language();
      $langcode = $language->getId();
      $language_name = $language->getName();
    }

    // Get changed time.
    $changed = NULL;
    if ($entity->hasField('changed')) {
      $changed = $entity->get('changed')->value;
    }
    elseif (method_exists($entity, 'getChangedTime')) {
      $changed = $entity->getChangedTime();
    }

    // Get published status.
    $status = NULL;
    if ($entity->hasField('status')) {
      $status = $entity->get('status')->value;
    }
    elseif (method_exists($entity, 'isPublished')) {
      $status = $entity->isPublished();
    }

    // Prepare URLs.
    $view_url = NULL;
    $edit_url = NULL;
    try {
      if ($entity->hasLinkTemplate('canonical')) {
        $view_url = $entity->toUrl('canonical')->toString();
      }
      if ($entity->hasLinkTemplate('edit-form')) {
        $edit_url = $entity->toUrl('edit-form')->toString();
      }
    }
    catch (\Exception $e) {
      // Fallback to standard entity routes.
      $route_prefix = 'entity.' . $entity_type->id();
      $view_url = \Drupal::url($route_prefix . '.canonical', [$entity_type->id() => $entity->id()]);
      $edit_url = \Drupal::url($route_prefix . '.edit_form', [$entity_type->id() => $entity->id()]);
    }

    return [
      'entity' => $entity,
      'entity_type' => $entity_type->id(),
      'content_type' => $bundle_label,
      'id' => $entity->id(),
      'title' => $label,
      'field_name' => $field_name,
      'field_label' => $field_label,
      'extract' => $match['extract'],
      'status' => $status,
      'changed' => $changed,
      'langcode' => $langcode,
      'language' => $language_name,
      'view_url' => $view_url,
      'edit_url' => $edit_url,
    ];
  }

  /**
   * Replace text in selected items.
   */
  protected function replaceInSelectedItems(array $selected_items, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive = FALSE) {
    // Verificación inicial
    if (empty($selected_items)) {
      $this->loggerFactory->get('content_radar')->warning('replaceInSelectedItems called with empty selection');
      return ['count' => 0, 'entities' => []];
    }
    
    $this->loggerFactory->get('content_radar')->info('Processing @count selected items for replacement', ['@count' => count($selected_items)]);
    
    $count = 0;
    $affected_entities = [];
    $entities_to_save = [];

    foreach ($selected_items as $item_key => $selected) {
      if (!$selected) {
        continue;
      }

      // Parse the item key.
      $parts = explode(':', $item_key);
      if (count($parts) < 3) {
        continue;
      }

      $entity_type = $parts[0];
      $entity_id = $parts[1];
      $field_name = $parts[2];
      $langcode = isset($parts[3]) ? $parts[3] : NULL;

      try {
        $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
        if (!$entity) {
          continue;
        }

        // Handle translation.
        if ($langcode && $entity instanceof TranslatableInterface && $entity->hasTranslation($langcode)) {
          $entity = $entity->getTranslation($langcode);
        }

        // Create unique key for this entity.
        $entity_key = $entity_type . ':' . $entity_id . ':' . ($langcode ?: 'und');

        // Initialize tracking.
        if (!isset($entities_to_save[$entity_key])) {
          $entities_to_save[$entity_key] = [
            'entity' => $entity,
            'modified' => FALSE,
            'count' => 0,
          ];
        }

        // Replace in the specific field.
        $label_key = $entity->getEntityType()->getKey('label');
        if ($field_name === 'title' || $field_name === $label_key) {
          $label = $entity->label();
          $new_label = $this->performReplace($label, $search_term, $replace_term, $use_regex, $case_sensitive);
          if ($label !== $new_label) {
            if (!$dry_run && $label_key) {
              $entity->set($label_key, $new_label);
            }
            $entities_to_save[$entity_key]['modified'] = TRUE;
            $entities_to_save[$entity_key]['count'] += $this->countReplacements($label, $search_term, $use_regex, $case_sensitive);
          }
        }
        else {
          // Regular field.
          if ($entity->hasField($field_name)) {
            $field_values = $entity->get($field_name)->getValue();
            $field_modified = FALSE;

            foreach ($field_values as $delta => &$value) {
              if (isset($value['value'])) {
                $original = $value['value'];
                $new_value = $this->performReplace($original, $search_term, $replace_term, $use_regex, $case_sensitive);

                if ($original !== $new_value) {
                  if (!$dry_run) {
                    $value['value'] = $new_value;
                  }
                  $field_modified = TRUE;
                  $entities_to_save[$entity_key]['modified'] = TRUE;
                  $entities_to_save[$entity_key]['count'] += $this->countReplacements($original, $search_term, $use_regex, $case_sensitive);
                }
              }
            }

            if ($field_modified && !$dry_run) {
              $entity->set($field_name, $field_values);
            }
          }
        }
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('content_radar')->error('Error processing selected item @key: @message', [
          '@key' => $item_key,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Save all modified entities.
    foreach ($entities_to_save as $entity_data) {
      if ($entity_data['modified']) {
        if (!$dry_run) {
          $entity_data['entity']->save();
        }
        $count += $entity_data['count'];
        $affected_entities[] = [
          'entity_type' => $entity_data['entity']->getEntityTypeId(),
          'id' => $entity_data['entity']->id(),
          'title' => $entity_data['entity']->label(),
          'type' => $entity_data['entity']->bundle(),
          'langcode' => $entity_data['entity']->language()->getId(),
        ];
      }
    }

    return ['count' => $count, 'entities' => $affected_entities];
  }

  /**
   * Replace text within specific nodes.
   */
  protected function replaceInSpecificNodes(array $node_ids, $search_term, $replace_term, $use_regex, $langcode, $dry_run, $case_sensitive = FALSE) {
    $count = 0;
    $affected_entities = [];
    
    if (empty($node_ids)) {
      return ['count' => 0, 'entities' => []];
    }
    
    try {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($node_ids);
      
      foreach ($nodes as $node) {
        if (!empty($langcode)) {
          if ($node->hasTranslation($langcode)) {
            $translation = $node->getTranslation($langcode);
            $result = $this->replaceInEntity($translation, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive);
            if ($result['modified']) {
              $count += $result['count'];
              $affected_entities[] = [
                'entity_type' => 'node',
                'id' => $node->id(),
                'title' => $translation->label(),
                'type' => $node->bundle(),
                'langcode' => $langcode,
              ];
            }
          }
        }
        else {
          $result = $this->replaceInEntity($node, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive);
          if ($result['modified']) {
            $count += $result['count'];
            $affected_entities[] = [
              'entity_type' => 'node',
              'id' => $node->id(),
              'title' => $node->label(),
              'type' => $node->bundle(),
              'langcode' => $node->language()->getId(),
            ];
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error replacing in specific nodes: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    
    return ['count' => $count, 'entities' => $affected_entities];
  }

  /**
   * Replace text within a specific content type.
   */
  protected function replaceInContentType($content_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run, $case_sensitive = FALSE) {
    $count = 0;
    $affected_entities = [];

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $content_type)
      ->accessCheck(TRUE);

    $nids = $query->execute();

    if (empty($nids)) {
      return ['count' => 0, 'entities' => []];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    foreach ($nodes as $node) {
      if (!empty($langcode)) {
        if ($node->hasTranslation($langcode)) {
          $translation = $node->getTranslation($langcode);
          $result = $this->replaceInEntity($translation, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive);
          if ($result['modified']) {
            $count += $result['count'];
            $affected_entities[] = [
              'entity_type' => 'node',
              'id' => $node->id(),
              'title' => $translation->label(),
              'type' => $node->bundle(),
              'langcode' => $langcode,
            ];
          }
        }
      }
      else {
        $result = $this->replaceInEntity($node, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive);
        if ($result['modified']) {
          $count += $result['count'];
          $affected_entities[] = [
            'entity_type' => 'node',
            'id' => $node->id(),
            'title' => $node->label(),
            'type' => $node->bundle(),
            'langcode' => $node->language()->getId(),
          ];
        }
      }
    }

    return ['count' => $count, 'entities' => $affected_entities];
  }

  /**
   * Replace text within a specific paragraph type.
   */
  protected function replaceInParagraphType($paragraph_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run, $case_sensitive = FALSE) {
    $count = 0;
    $affected_entities = [];

    try {
      $storage = $this->entityTypeManager->getStorage('paragraph');
      $query = $storage->getQuery()
        ->condition('type', $paragraph_type)
        ->accessCheck(FALSE);

      $paragraph_ids = $query->execute();

      if (empty($paragraph_ids)) {
        return ['count' => 0, 'entities' => []];
      }

      $paragraphs = $storage->loadMultiple($paragraph_ids);

      foreach ($paragraphs as $paragraph) {
        if (!empty($langcode)) {
          if ($paragraph->hasTranslation($langcode)) {
            $translation = $paragraph->getTranslation($langcode);
            $result = $this->replaceInEntity($translation, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive);
            if ($result['modified']) {
              $count += $result['count'];
              $affected_entities[] = [
                'entity_type' => 'paragraph',
                'id' => $paragraph->id(),
                'title' => $translation->label() ?: 'Paragraph ' . $paragraph->id(),
                'type' => $paragraph->bundle(),
                'langcode' => $langcode,
              ];
            }
          }
        }
        else {
          $result = $this->replaceInEntity($paragraph, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive);
          if ($result['modified']) {
            $count += $result['count'];
            $affected_entities[] = [
              'entity_type' => 'paragraph',
              'id' => $paragraph->id(),
              'title' => $paragraph->label() ?: 'Paragraph ' . $paragraph->id(),
              'type' => $paragraph->bundle(),
              'langcode' => $paragraph->language()->getId(),
            ];
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error replacing in paragraph type @type: @message', [
        '@type' => $paragraph_type,
        '@message' => $e->getMessage(),
      ]);
    }

    return ['count' => $count, 'entities' => $affected_entities];
  }

  /**
   * Replace text within a specific entity type.
   */
  protected function replaceInEntityType($entity_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run, $case_sensitive = FALSE) {
    $count = 0;
    $affected_entities = [];

    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $query = $storage->getQuery();

      if (method_exists($query, 'accessCheck')) {
        $query->accessCheck(TRUE);
      }

      $entity_ids = $query->execute();

      if (empty($entity_ids)) {
        return ['count' => 0, 'entities' => []];
      }

      $entities = $storage->loadMultiple($entity_ids);

      foreach ($entities as $entity) {
        $result = $this->replaceInEntity($entity, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive);
        if ($result['modified']) {
          $count += $result['count'];
          $affected_entities[] = [
            'entity_type' => $entity_type,
            'id' => $entity->id(),
            'title' => $entity->label(),
            'type' => $entity->bundle(),
            'langcode' => $entity->language()->getId(),
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error replacing in entity type @type: @message', [
        '@type' => $entity_type,
        '@message' => $e->getMessage(),
      ]);
    }

    return ['count' => $count, 'entities' => $affected_entities];
  }

  /**
   * Replace text in an entity.
   */
  protected function replaceInEntity(EntityInterface $entity, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive = FALSE) {
    $count = 0;
    $entity_modified = FALSE;
    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle());

    // Replace in label/title field.
    $label_key = $entity->getEntityType()->getKey('label');
    if ($label_key && $entity->hasField($label_key)) {
      $label = $entity->get($label_key)->value;
      $new_label = $this->performReplace($label, $search_term, $replace_term, $use_regex);
      if ($label !== $new_label) {
        if (!$dry_run) {
          $entity->set($label_key, $new_label);
        }
        $entity_modified = TRUE;
        $count += $this->countReplacements($label, $search_term, $use_regex, $case_sensitive);
      }
    }

    // Replace in fields.
    foreach ($field_definitions as $field_name => $field_definition) {
      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field_type = $field_definition->getType();
      
      // Handle Layout Builder fields
      if ($field_type === 'layout_section') {
        $result = $this->replaceInLayoutBuilderField($entity, $field_name, $search_term, $replace_term, $use_regex, $dry_run);
        if ($result['modified']) {
          $entity_modified = TRUE;
          $count += $result['count'];
        }
      }
      // Handle regular text fields
      elseif (in_array($field_type, $this->searchableFieldTypes)) {
        $field_values = $entity->get($field_name)->getValue();
        $field_modified = FALSE;

        foreach ($field_values as $delta => &$value) {
          if (isset($value['value'])) {
            $original = $value['value'];
            $new_value = $this->performReplace($original, $search_term, $replace_term, $use_regex, $case_sensitive);

            if ($original !== $new_value) {
              if (!$dry_run) {
                $value['value'] = $new_value;
              }
              $field_modified = TRUE;
              $entity_modified = TRUE;
              $count += $this->countReplacements($original, $search_term, $use_regex, $case_sensitive);
            }
          }
        }

        if ($field_modified && !$dry_run) {
          $entity->set($field_name, $field_values);
        }
      }
    }

    // Save the entity if modified.
    if ($entity_modified && !$dry_run) {
      $entity->save();
    }

    return ['modified' => $entity_modified, 'count' => $count];
  }

  /**
   * Replace text in Layout Builder field.
   */
  protected function replaceInLayoutBuilderField(EntityInterface $entity, $field_name, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive = FALSE) {
    $count = 0;
    $field_modified = FALSE;
    
    try {
      $sections = $entity->get($field_name)->getValue();
      
      foreach ($sections as $delta => &$section) {
        if (!isset($section['section']) || !$section['section'] instanceof Section) {
          continue;
        }
        
        $components = $section['section']->getComponents();
        foreach ($components as $component_uuid => $component) {
          $configuration = $component->get('configuration');
          $plugin_id = $component->getPluginId();
          
          // Handle inline blocks
          if ($plugin_id === 'inline_block') {
            if (isset($configuration['block_serialized'])) {
              // Search and replace in serialized block data
              $block_data = unserialize($configuration['block_serialized']);
              if ($block_data && is_array($block_data)) {
                $result = $this->replaceInArrayRecursively($block_data, $search_term, $replace_term, $use_regex, $case_sensitive);
                if ($result['modified']) {
                  if (!$dry_run) {
                    $configuration['block_serialized'] = serialize($block_data);
                    $component->setConfiguration($configuration);
                  }
                  $field_modified = TRUE;
                  $count += $result['count'];
                }
              }
            }
            
            // If there's a block revision ID, load and replace in the block entity
            if (isset($configuration['block_revision_id'])) {
              try {
                $block_storage = $this->entityTypeManager->getStorage('block_content');
                $block = $block_storage->loadRevision($configuration['block_revision_id']);
                if ($block) {
                  $result = $this->replaceInEntity($block, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive);
                  if ($result['modified']) {
                    $field_modified = TRUE;
                    $count += $result['count'];
                  }
                }
              }
              catch (\Exception $e) {
                // Continue if block loading fails
              }
            }
          }
          // Handle reusable blocks
          elseif (strpos($plugin_id, 'block_content:') === 0) {
            $uuid = str_replace('block_content:', '', $plugin_id);
            try {
              $block_storage = $this->entityTypeManager->getStorage('block_content');
              $blocks = $block_storage->loadByProperties(['uuid' => $uuid]);
              if (!empty($blocks)) {
                $block = reset($blocks);
                $result = $this->replaceInEntity($block, $search_term, $replace_term, $use_regex, $dry_run, $case_sensitive);
                if ($result['modified']) {
                  $field_modified = TRUE;
                  $count += $result['count'];
                }
              }
            }
            catch (\Exception $e) {
              // Continue if block loading fails
            }
          }
          // Handle other plugin configurations
          else {
            $result = $this->replaceInArrayRecursively($configuration, $search_term, $replace_term, $use_regex, $case_sensitive);
            if ($result['modified']) {
              if (!$dry_run) {
                $component->setConfiguration($configuration);
              }
              $field_modified = TRUE;
              $count += $result['count'];
            }
          }
        }
      }
      
      if ($field_modified && !$dry_run) {
        $entity->set($field_name, $sections);
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('content_radar')->error('Error replacing in Layout Builder field @field: @message', [
        '@field' => $field_name,
        '@message' => $e->getMessage(),
      ]);
    }
    
    return ['modified' => $field_modified, 'count' => $count];
  }

  /**
   * Replace text in array recursively.
   */
  protected function replaceInArrayRecursively(array &$data, $search_term, $replace_term, $use_regex, $case_sensitive = FALSE) {
    $count = 0;
    $modified = FALSE;
    
    foreach ($data as $key => &$value) {
      if (is_string($value) && !empty($value)) {
        $original = $value;
        $new_value = $this->performReplace($original, $search_term, $replace_term, $use_regex, $case_sensitive);
        if ($original !== $new_value) {
          $value = $new_value;
          $modified = TRUE;
          $count += $this->countReplacements($original, $search_term, $use_regex, $case_sensitive);
        }
      }
      elseif (is_array($value)) {
        $result = $this->replaceInArrayRecursively($value, $search_term, $replace_term, $use_regex, $case_sensitive);
        if ($result['modified']) {
          $modified = TRUE;
          $count += $result['count'];
        }
      }
    }
    
    return ['modified' => $modified, 'count' => $count];
  }

  /**
   * Perform the actual text replacement.
   */
  protected function performReplace($text, $search_term, $replace_term, $use_regex, $case_sensitive = FALSE) {
    if ($use_regex) {
      // Validate the regex pattern.
      if (!$this->isValidRegex($search_term)) {
        throw new \InvalidArgumentException('Invalid regular expression pattern.');
      }
      $flags = $case_sensitive ? '' : 'i';
      return preg_replace('/' . $search_term . '/' . $flags, $replace_term, $text);
    }
    else {
      if ($case_sensitive) {
        return str_replace($search_term, $replace_term, $text);
      } else {
        return str_ireplace($search_term, $replace_term, $text);
      }
    }
  }

  /**
   * Count the number of replacements.
   */
  protected function countReplacements($text, $search_term, $use_regex, $case_sensitive = FALSE) {
    if ($use_regex) {
      if (@preg_match('/' . $search_term . '/', '') === FALSE) {
        return 0;
      }
      $flags = $case_sensitive ? '' : 'i';
      preg_match_all('/' . $search_term . '/' . $flags, $text, $matches);
      return count($matches[0]);
    }
    else {
      if ($case_sensitive) {
        return substr_count($text, $search_term);
      } else {
        return substr_count(strtolower($text), strtolower($search_term));
      }
    }
  }

  /**
   * Validate a regular expression pattern.
   *
   * @param string $pattern
   *   The regex pattern to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function isValidRegex($pattern) {
    // Check for potentially dangerous patterns.
    $dangerous_patterns = [
      // Recursive patterns that could cause DoS.
      '(\(\?R\))',
      // Backreferences that could be exploited.
      '(\\g\{)',
      // PCRE verbs that could be dangerous.
      '(\(\*[A-Z]+)',
    ];
    
    foreach ($dangerous_patterns as $dangerous) {
      if (preg_match($dangerous, $pattern)) {
        return FALSE;
      }
    }
    
    // Test the pattern safely.
    $test = @preg_match('/' . $pattern . '/', '');
    return $test !== FALSE;
  }

  /**
   * Export results to CSV.
   */
  public function exportToCsv($search_term, $use_regex, array $entity_types, array $content_types, $langcode = '') {
    $results = $this->search($search_term, $use_regex, $entity_types, $content_types, $langcode, 0, 10000);

    // Add BOM for UTF-8 compatibility.
    $output = "\xEF\xBB\xBF";

    $csv = [];
    $csv[] = [
      'Entity Type',
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
      $url = '';
      try {
        if ($entity->hasLinkTemplate('canonical')) {
          $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
        }
      }
      catch (\Exception $e) {
        // Some entities may not have URLs.
      }

      $csv[] = [
        $item['entity_type'],
        $item['content_type'],
        $item['id'],
        $item['title'],
        $item['language'],
        $item['field_label'],
        strip_tags($item['extract']),
        isset($item['status']) ? ($item['status'] ? 'Published' : 'Unpublished') : 'N/A',
        isset($item['changed']) ? date('Y-m-d H:i:s', $item['changed']) : 'N/A',
        $url,
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

  /**
   * Get all searchable entity types.
   */
  protected function getSearchableEntityTypes() {
    $entity_types = [];
    $definitions = $this->entityTypeManager->getDefinitions();

    // Get ALL content entity types that could contain text
    foreach ($definitions as $entity_type_id => $definition) {
      // Only include content entities (not config entities)
      if (!$definition->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface')) {
        continue;
      }
      
      // Skip entities that typically don't contain searchable text
      $skip_types = [
        'file', // Files themselves don't contain searchable text
        'crop', // Image crops
        'image_style', // Image styles
        'view', // Views
        'shortcut', // Shortcuts
        'path_alias', // Path aliases
        'redirect', // Redirects
      ];
      
      // Always include block_content even if it was missed
      if ($entity_type_id === 'block_content') {
        $entity_types[] = $entity_type_id;
        continue;
      }
      
      if (in_array($entity_type_id, $skip_types)) {
        continue;
      }
      
      $entity_types[] = $entity_type_id;
    }

    return $entity_types;
  }

}