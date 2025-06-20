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
   - Enable regular expressions
   - Select specific languages
   - Filter by entity types
   - Filter by content types (for nodes)
4. Click **Search**

### Replacing Text

1. Perform a search first
2. Enter replacement text in the "Replace with" field
3. Choose replacement mode:
   - **Replace all**: Replaces all found occurrences
   - **Replace selected**: Only replaces checked items
4. Confirm and click **Replace**

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