# ContentRadar Undo Functionality - Complete Redesign

## Overview
The undo functionality has been completely redesigned to address the issues where replacements were not being reverted and to add individual node selection capabilities.

## Changes Made

### 1. New Controller-Based Approach
- **File**: `src/Controller/UndoController.php` (NEW)
- Changed from modal/popup to full page listing
- Shows preview of text occurrences in each node
- Provides checkbox selection for individual nodes
- Added CSRF token protection for form submission

### 2. Updated Routing
- **File**: `content_radar.routing.yml`
- Changed route `content_radar.report_undo` from form to controller
- Kept `content_radar.report_undo_confirm` as confirmation form

### 3. New JavaScript for Selection
- **File**: `js/undo-page.js` (NEW)
- Handles "Select All" functionality
- Updates checkbox states properly

### 4. Library Definitions
- **File**: `content_radar.libraries.yml`
- Added `undo-page` library for the new JavaScript

### 5. Enhanced CSS
- **File**: `css/content-radar.css`
- Added styles for undo table preview
- Added color coding for success/warning states
- Added highlight styles for search term occurrences

### 6. Updated Form Processing
- **File**: `src/Form/UndoConfirmForm.php`
- Enhanced to work with session-stored node selections
- Improved batch processing with better error handling

## Key Features

### Individual Node Selection
- Each node shows a checkbox for selection
- Only nodes with occurrences are checked by default
- Preview shows where text will be replaced
- "Select All" checkbox for bulk operations

### Better UX
- Full page listing instead of modal (better for many nodes)
- Shows occurrence count for each node
- Color-coded status (green = found, orange = not found)
- Preview snippets with highlighted terms
- Direct links to view/edit each node

### Improved Processing
- Session-based data passing between pages
- Proper CSRF protection
- Batch processing for large operations
- Detailed logging for debugging

## How It Works

1. **Selection Page** (`/admin/reports/content-radar/{rid}/undo`)
   - Loads report details
   - Checks each node for current occurrences
   - Displays table with checkboxes
   - Stores selections in session on submit

2. **Confirmation Page** (`/admin/reports/content-radar/{rid}/undo/confirm`)
   - Retrieves selections from session
   - Shows summary of operation
   - Processes batch on confirmation

3. **Batch Processing**
   - Uses `TextSearchService::replaceInNode()`
   - Case-insensitive replacement
   - Saves each node after modification
   - Creates new report for undo operation

## Technical Details

### Entity Dependencies
- Uses EntityTypeManager for node loading
- Uses EntityFieldManager for field inspection
- Proper dependency injection throughout

### Security
- CSRF token validation on form submission
- Access control via permissions
- Session data cleared after use

### Performance
- Batch processing for scalability
- Efficient field checking
- Minimal database queries

## Troubleshooting

If replacements are still not working:
1. Clear Drupal cache
2. Check file permissions
3. Verify nodes haven't been modified
4. Check Drupal logs for errors
5. Ensure proper permissions are set

## Next Steps

To use the new functionality:
1. Navigate to a report detail page
2. Click "Undo Replacements"
3. Select nodes to revert
4. Review and confirm
5. Monitor batch progress

The system will create a new report showing what was undone, making it possible to undo the undo if needed.