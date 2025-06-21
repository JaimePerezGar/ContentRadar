# Node ID Selection Feature

## Description
This feature allows users to limit searches and replacements to specific node IDs by entering a comma-separated list of node IDs.

## How to Use

1. In the Content Radar search form, expand the "Node Selection" section
2. Enter node IDs in the "Specific Node IDs" field (e.g., 1,5,23)
3. The search/replace will be limited to only those nodes

## Implementation Details

### Files Modified:
- `src/Form/TextSearchForm.php`: Added node_ids field and validation
- `src/Service/TextSearchService.php`: Already had support for node ID filtering

### Key Changes:
1. Added a new form field for entering comma-separated node IDs
2. Added validation to ensure only valid node IDs are entered
3. Updated the search and replace methods to pass the node_ids parameter
4. The service already had methods to handle specific node searches

## Technical Notes
- The field accepts comma-separated integers
- Spaces are automatically removed
- Empty field means all nodes will be searched (normal behavior)
- The implementation is simpler and more reliable than the previous checkbox approach