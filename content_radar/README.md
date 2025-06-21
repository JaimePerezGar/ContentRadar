# Content Radar

Content Radar is a powerful Drupal module for searching and replacing text across all entities in your Drupal site.

## Features

- **Universal Search**: Search text across all entity types (nodes, blocks, terms, users, media, etc.)
- **Field Coverage**: Searches all text fields including titles, body, and custom fields
- **Regular Expression Support**: Use regex patterns for advanced searches
- **Multilingual**: Full support for multilingual content
- **Selective Replacement**: Choose specific occurrences to replace
- **Reports & Undo**: Track all changes with detailed reports and undo capability
- **CSV Export**: Export search results and reports to CSV
- **Clean Theme Integration**: Uses Drupal's theme system without custom styles

## Requirements

- Drupal 10 or 11
- PHP 7.4 or higher

## Installation

1. Copy the module to your `modules/custom` directory
2. Enable the module: `drush en content_radar`
3. Configure permissions at `/admin/people/permissions#module-content_radar`

## Usage

### Searching Content

1. Navigate to **Content > Content Radar**
2. Enter your search term
3. Optionally:
   - Enable **case sensitive** for exact case matching
   - Enable **regular expressions** for pattern matching
   - Enable **deep search** to find text in Layout Builder blocks and nested entities
   - Select specific languages
   - Filter by entity types
   - Filter by content types or bundles
4. Click **Search**

### Usage Examples

#### Basic Text Search
```
Search term: "old content"
Finds: All occurrences of "old content" (case-insensitive)
```

#### Case-Sensitive Search
```
Search term: "Test"
Case sensitive: ✓
Finds: Only "Test", not "test" or "TEST"
```

#### Regular Expression Examples
```
# Find phone numbers
Pattern: \d{3}-\d{3}-\d{4}
Finds: 123-456-7890, 555-123-4567

# Find email addresses  
Pattern: [a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}
Finds: user@example.com, admin@site.org

# Find prices
Pattern: \$\d+(\.\d{2})?
Finds: $10, $99.99, $1250.00

# Find specific HTML tags
Pattern: <h[1-6]>.*?</h[1-6]>
Finds: <h1>Title</h1>, <h3>Subtitle</h3>
```

#### Deep Search (Layout Builder/VLSuite)
```
Deep search: ✓
Searches in:
- Layout Builder inline blocks
- Custom block content
- Paragraphs at any nesting level
- Entity reference fields
- Serialized configuration data
```

### Replacing Text

1. Perform a search first
2. Enter replacement text in the "Replace with" field
3. Choose replacement mode:
   - **Replace all**: Replaces all found occurrences
   - **Replace selected**: Only replaces checked items
4. Confirm and click **Replace**

#### Replacement Examples
```
# Simple replacement
Search: "Company XYZ"
Replace: "Company ABC"

# Regex replacement (change date format)
Search: (\d{2})/(\d{2})/(\d{4})
Replace: $3-$2-$1
Changes: 12/25/2023 → 2023-25-12

# Remove HTML tags
Search: <[^>]+>
Replace: (empty)
Removes all HTML tags
```

### Viewing Reports

1. Go to **Reports > Content Radar Reports**
2. View list of all replacements made
3. Click on a report to see details
4. Export reports as CSV
5. Undo changes if needed

## Permissions

- `search content radar`: Perform searches
- `replace content radar`: Perform replacements
- `view content radar reports`: View replacement reports
- `undo content radar changes`: Undo replacements
- `administer content radar`: Module administration

## Technical Details

### Module Structure

```
content_radar/
├── content_radar.info.yml
├── content_radar.module
├── content_radar.install
├── content_radar.permissions.yml
├── content_radar.routing.yml
├── content_radar.services.yml
├── content_radar.libraries.yml
├── content_radar.links.*.yml
├── src/
│   ├── Service/
│   │   └── TextSearchService.php
│   ├── Form/
│   │   ├── TextSearchForm.php
│   │   └── UndoConfirmForm.php
│   └── Controller/
│       ├── ReportsController.php
│       └── TextSearchController.php
├── templates/
│   ├── content-radar-results.html.twig
│   ├── content-radar-result-item.html.twig
│   └── content-radar-report-details.html.twig
├── js/
│   └── content-radar.js
└── README.md
```

### Database Tables

- `content_radar_log`: Stores search history
- `content_radar_reports`: Stores replacement reports with undo data

### Coding Standards

This module follows Drupal coding standards:
- PSR-4 autoloading
- Dependency injection
- Theme system integration
- Proper permission handling
- Database abstraction layer
- Translation support

## Support

For issues or feature requests, please contact the module maintainer.