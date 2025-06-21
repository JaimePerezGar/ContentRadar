# Node ID Selection Feature

## Description
This feature allows users to limit searches and replacements to specific node IDs by entering a comma-separated list of node IDs.

## How to Use

1. In the Content Radar search form, the "Node Selection" section is open by default
2. Enter node IDs in the "Specific Node IDs" field (e.g., 1,5,23)
3. The search/replace will be limited to only those nodes

## Implementation Details

### Files Modified:
- `src/Form/TextSearchForm.php`: Added nodes field with validation
- `src/Service/TextSearchService.php`: Enhanced documentation and already had support for node ID filtering

### Key Features:
1. **Form Field**: Added a textfield named 'nodes' for entering comma-separated node IDs
2. **Validation**: 
   - Ensures only valid node IDs are entered (numbers and commas)
   - Verifies that the node IDs actually exist in the system
   - Shows specific error messages for invalid or non-existent nodes
3. **Documentation**: Added comprehensive PHPDoc comments to all search methods
4. **Node Selection Section**: Set to open by default for better visibility

## Technical Notes
- The field accepts comma-separated integers
- Validation checks if nodes exist before searching
- Empty field means all nodes will be searched (normal behavior)
- The implementation is simpler and more reliable than the previous checkbox approach

## Performance Considerations
- For large numbers of nodes (< 1000), performance should be acceptable
- The database 'nid' field is indexed by default in Drupal
- Uses POST method to avoid URL length limitations

## Future Enhancement
See `ENTITY_AUTOCOMPLETE_EXAMPLE.md` for an alternative implementation using Drupal's entity_autocomplete field type for better user experience.