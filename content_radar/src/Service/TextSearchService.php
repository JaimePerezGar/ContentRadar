<?php

namespace Drupal\content_radar\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;

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
   */
  public function search($search_term, $use_regex = FALSE, array $entity_types = [], array $content_types = [], $langcode = '', $page = 0, $limit = 50, array $paragraph_types = []) {
    $results = [];
    $total = 0;

    try {
      // If no entity types specified, search all searchable entities.
      if (empty($entity_types)) {
        $entity_types = $this->getSearchableEntityTypes();
      }

      // Search in each entity type.
      foreach ($entity_types as $entity_type) {
        if ($entity_type === 'node' && !empty($content_types)) {
          // For nodes with specific content types.
          foreach ($content_types as $content_type) {
            $results = array_merge($results, $this->searchContentType($content_type, $search_term, $use_regex, $langcode));
          }
        }
        elseif ($entity_type === 'paragraph' && !empty($paragraph_types)) {
          // For paragraphs with specific types.
          foreach ($paragraph_types as $paragraph_type) {
            $results = array_merge($results, $this->searchParagraphType($paragraph_type, $search_term, $use_regex, $langcode));
          }
        }
        else {
          // For all entity types.
          $results = array_merge($results, $this->searchEntityType($entity_type, $search_term, $use_regex, $langcode));
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
   */
  public function deepSearch($search_term, $use_regex = FALSE, array $entity_types = [], array $content_types = [], $langcode = '', $page = 0, $limit = 50, array $paragraph_types = []) {
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
        if ($entity_type === 'node' && !empty($content_types)) {
          // For nodes with specific content types.
          foreach ($content_types as $content_type) {
            $results = array_merge($results, $this->deepSearchContentType($content_type, $search_term, $use_regex, $langcode, $processed));
          }
        }
        elseif ($entity_type === 'paragraph' && !empty($paragraph_types)) {
          // For paragraphs with specific types.
          foreach ($paragraph_types as $paragraph_type) {
            $results = array_merge($results, $this->deepSearchParagraphType($paragraph_type, $search_term, $use_regex, $langcode, $processed));
          }
        }
        else {
          // For all entity types.
          $results = array_merge($results, $this->deepSearchEntityType($entity_type, $search_term, $use_regex, $langcode, $processed));
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
      $this->loggerFactory->get('content_radar')->error('Deep search error: @message', ['@message' => $e->getMessage()]);
    }

    return [
      'items' => $results,
      'total' => $total,
    ];
  }

  /**
   * Deep search within a specific content type and all its related entities.
   */
  protected function deepSearchContentType($content_type, $search_term, $use_regex, $langcode = '', array &$processed = []) {
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
      $this->searchEntity($node, $search_term, $use_regex, $results, $processed);
    }

    return $results;
  }

  /**
   * Deep search within a specific paragraph type and all its related entities.
   */
  protected function deepSearchParagraphType($paragraph_type, $search_term, $use_regex, $langcode = '', array &$processed = []) {
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
            $this->searchEntity($paragraph, $search_term, $use_regex, $results, $processed);
          }
        }
        else {
          $this->searchEntity($paragraph, $search_term, $use_regex, $results, $processed);
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
  protected function deepSearchEntityType($entity_type, $search_term, $use_regex, $langcode = '', array &$processed = []) {
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
              $this->searchEntity($entity, $search_term, $use_regex, $results, $processed);
            }
          }
          else {
            $languages = $entity->getTranslationLanguages();
            foreach ($languages as $translation_langcode => $language) {
              $translation = $entity->getTranslation($translation_langcode);
              $this->searchEntity($translation, $search_term, $use_regex, $results, $processed);
            }
          }
        }
        else {
          $this->searchEntity($entity, $search_term, $use_regex, $results, $processed);
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
   * Replace text across entities.
   */
  public function replaceText($search_term, $replace_term, $use_regex = FALSE, array $entity_types = [], array $content_types = [], $langcode = '', $dry_run = FALSE, array $selected_items = [], array $paragraph_types = []) {
    $replaced_count = 0;
    $affected_entities = [];

    try {
      if (!empty($selected_items)) {
        // Replace only in selected items.
        $result = $this->replaceInSelectedItems($selected_items, $search_term, $replace_term, $use_regex, $dry_run);
        $replaced_count = $result['count'];
        $affected_entities = $result['entities'];
      }
      else {
        // Replace in all matching items.
        if (empty($entity_types)) {
          $entity_types = $this->getSearchableEntityTypes();
        }

        foreach ($entity_types as $entity_type) {
          if ($entity_type === 'node' && !empty($content_types)) {
            foreach ($content_types as $content_type) {
              $result = $this->replaceInContentType($content_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run);
              $replaced_count += $result['count'];
              $affected_entities = array_merge($affected_entities, $result['entities']);
            }
          }
          elseif ($entity_type === 'paragraph' && !empty($paragraph_types)) {
            foreach ($paragraph_types as $paragraph_type) {
              $result = $this->replaceInParagraphType($paragraph_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run);
              $replaced_count += $result['count'];
              $affected_entities = array_merge($affected_entities, $result['entities']);
            }
          }
          else {
            $result = $this->replaceInEntityType($entity_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run);
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
   * Search within a specific content type.
   */
  protected function searchContentType($content_type, $search_term, $use_regex, $langcode = '') {
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
      $this->searchEntity($node, $search_term, $use_regex, $results, $processed);
    }

    return $results;
  }

  /**
   * Search within a specific paragraph type.
   */
  protected function searchParagraphType($paragraph_type, $search_term, $use_regex, $langcode = '') {
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
            $this->searchEntity($paragraph, $search_term, $use_regex, $results, $processed);
          }
        }
        else {
          $processed = [];
          $this->searchEntity($paragraph, $search_term, $use_regex, $results, $processed);
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
  protected function searchEntityType($entity_type, $search_term, $use_regex, $langcode = '') {
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
              $this->searchEntity($entity, $search_term, $use_regex, $results, $processed);
            }
          }
          else {
            $languages = $entity->getTranslationLanguages();
            foreach ($languages as $translation_langcode => $language) {
              $translation = $entity->getTranslation($translation_langcode);
              $processed = [];
              $this->searchEntity($translation, $search_term, $use_regex, $results, $processed);
            }
          }
        }
        else {
          $processed = [];
          $this->searchEntity($entity, $search_term, $use_regex, $results, $processed);
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
  protected function searchEntity(EntityInterface $entity, $search_term, $use_regex, array &$results, array &$processed = []) {
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
      $matches = $this->searchInText($label, $search_term, $use_regex);
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
        $this->searchTextField($entity, $field_name, $field_definition, $search_term, $use_regex, $results);
      }
      // Handle entity reference fields (ANY type that could reference entities).
      elseif ($this->isReferenceField($field_definition)) {
        $this->searchReferenceField($entity, $field_name, $field_definition, $search_term, $use_regex, $results, $processed);
      }
      // Handle complex fields that may contain text.
      elseif (in_array($field_type, $this->complexFieldTypes)) {
        $this->searchComplexField($entity, $field_name, $field_definition, $search_term, $use_regex, $results);
      }
      // For any other field type, try to search for text content.
      else {
        $this->searchGenericField($entity, $field_name, $field_definition, $search_term, $use_regex, $results);
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
  protected function searchGenericField(EntityInterface $entity, $field_name, $field_definition, $search_term, $use_regex, array &$results) {
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
        
        // Search in all found text properties
        foreach ($properties as $property => $text) {
          $matches = $this->searchInText($text, $search_term, $use_regex);
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
  protected function searchTextField(EntityInterface $entity, $field_name, $field_definition, $search_term, $use_regex, array &$results) {
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

        $matches = $this->searchInText($text, $search_term, $use_regex);
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
  protected function searchReferenceField(EntityInterface $entity, $field_name, $field_definition, $search_term, $use_regex, array &$results, array &$processed) {
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
        $this->searchEntity($referenced_entity, $search_term, $use_regex, $results, $processed);
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
  protected function searchComplexField(EntityInterface $entity, $field_name, $field_definition, $search_term, $use_regex, array &$results) {
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
          
          $matches = $this->searchInText($text, $search_term, $use_regex);
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
   * Search for text within a string.
   */
  protected function searchInText($text, $search_term, $use_regex) {
    $matches = [];

    if ($use_regex) {
      if (@preg_match_all('/' . $search_term . '/i', $text, $preg_matches, PREG_OFFSET_CAPTURE) !== FALSE) {
        foreach ($preg_matches[0] as $match) {
          $matches[] = $this->extractContext($text, $match[1], strlen($match[0]));
        }
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

    return $matches;
  }

  /**
   * Extract context around a match.
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
  protected function replaceInSelectedItems(array $selected_items, $search_term, $replace_term, $use_regex, $dry_run) {
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
          $new_label = $this->performReplace($label, $search_term, $replace_term, $use_regex);
          if ($label !== $new_label) {
            if (!$dry_run && $label_key) {
              $entity->set($label_key, $new_label);
            }
            $entities_to_save[$entity_key]['modified'] = TRUE;
            $entities_to_save[$entity_key]['count'] += $this->countReplacements($label, $search_term, $use_regex);
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
                $new_value = $this->performReplace($original, $search_term, $replace_term, $use_regex);

                if ($original !== $new_value) {
                  if (!$dry_run) {
                    $value['value'] = $new_value;
                  }
                  $field_modified = TRUE;
                  $entities_to_save[$entity_key]['modified'] = TRUE;
                  $entities_to_save[$entity_key]['count'] += $this->countReplacements($original, $search_term, $use_regex);
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
   * Replace text within a specific content type.
   */
  protected function replaceInContentType($content_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run) {
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
          $result = $this->replaceInEntity($translation, $search_term, $replace_term, $use_regex, $dry_run);
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
        $result = $this->replaceInEntity($node, $search_term, $replace_term, $use_regex, $dry_run);
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
  protected function replaceInParagraphType($paragraph_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run) {
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
            $result = $this->replaceInEntity($translation, $search_term, $replace_term, $use_regex, $dry_run);
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
          $result = $this->replaceInEntity($paragraph, $search_term, $replace_term, $use_regex, $dry_run);
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
  protected function replaceInEntityType($entity_type, $search_term, $replace_term, $use_regex, $langcode, $dry_run) {
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
        $result = $this->replaceInEntity($entity, $search_term, $replace_term, $use_regex, $dry_run);
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
  protected function replaceInEntity(EntityInterface $entity, $search_term, $replace_term, $use_regex, $dry_run) {
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
        $count += $this->countReplacements($label, $search_term, $use_regex);
      }
    }

    // Replace in fields.
    foreach ($field_definitions as $field_name => $field_definition) {
      if (!in_array($field_definition->getType(), $this->searchableFieldTypes)) {
        continue;
      }

      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field_values = $entity->get($field_name)->getValue();
      $field_modified = FALSE;

      foreach ($field_values as $delta => &$value) {
        if (isset($value['value'])) {
          $original = $value['value'];
          $new_value = $this->performReplace($original, $search_term, $replace_term, $use_regex);

          if ($original !== $new_value) {
            if (!$dry_run) {
              $value['value'] = $new_value;
            }
            $field_modified = TRUE;
            $entity_modified = TRUE;
            $count += $this->countReplacements($original, $search_term, $use_regex);
          }
        }
      }

      if ($field_modified && !$dry_run) {
        $entity->set($field_name, $field_values);
      }
    }

    // Save the entity if modified.
    if ($entity_modified && !$dry_run) {
      $entity->save();
    }

    return ['modified' => $entity_modified, 'count' => $count];
  }

  /**
   * Perform the actual text replacement.
   */
  protected function performReplace($text, $search_term, $replace_term, $use_regex) {
    if ($use_regex) {
      // Validate the regex pattern.
      if (@preg_match('/' . $search_term . '/', '') === FALSE) {
        throw new \InvalidArgumentException('Invalid regular expression pattern.');
      }
      return preg_replace('/' . $search_term . '/i', $replace_term, $text);
    }
    else {
      return str_ireplace($search_term, $replace_term, $text);
    }
  }

  /**
   * Count the number of replacements.
   */
  protected function countReplacements($text, $search_term, $use_regex) {
    if ($use_regex) {
      if (@preg_match('/' . $search_term . '/', '') === FALSE) {
        return 0;
      }
      preg_match_all('/' . $search_term . '/i', $text, $matches);
      return count($matches[0]);
    }
    else {
      return substr_count(strtolower($text), strtolower($search_term));
    }
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
      
      if (in_array($entity_type_id, $skip_types)) {
        continue;
      }
      
      $entity_types[] = $entity_type_id;
    }

    return $entity_types;
  }

}