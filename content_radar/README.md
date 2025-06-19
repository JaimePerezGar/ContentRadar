# ContentRadar

Advanced content search and analysis tool for Drupal administrators to find, analyze and manage content across all text fields with multilingual support.

## Features

- Search across all text fields in content (including paragraphs)
- Support for regular expressions
- Filter by content types
- View content usage and references
- Direct links to view and edit content (open in new tabs)
- **Find and replace functionality** with preview mode
- Export results to CSV
- Caching for improved performance
- Pagination for large result sets
- Shows publication status and last modified date
- Security features including permission-based access and input validation

## Requirements

- Drupal 10 or 11
- PHP 7.4 or higher

## Installation

1. Place the module in your modules directory (e.g., `/modules/custom/content_radar`)
2. Enable the module via Drush: `drush en content_radar`
3. Or enable via the admin interface at `/admin/modules`

## Permissions

The module provides two permissions:
- **Use ContentRadar**: Allows users to search and analyze content
- **Replace text with ContentRadar**: Allows users to find and replace text (use with caution)

## Usage

### Basic Search

1. Navigate to `/admin/content/content-radar` or use the menu item under Content
2. Enter your search term
3. Optionally enable regular expressions
4. Select specific content types to search (or leave empty to search all)
5. Click "Search"

### Find and Replace

1. Enter your search term
2. Open the "Find and Replace" section
3. Enter the replacement text
4. Click "Preview Replace" to see what would be changed
5. Check the confirmation box
6. Click "Replace All" to perform the replacement

**Warning**: The replace function modifies content directly. Always preview first and ensure you have backups.

### Regular Expressions

When regular expressions are enabled, you can use patterns like:
- `\bword\b` - Match whole word
- `test.*ing` - Match "test" followed by any characters and "ing"
- `(foo|bar)` - Match either "foo" or "bar"
- `\d+` - Match one or more digits
- `^Start` - Match text at the beginning of a field
- `end$` - Match text at the end of a field

## Export

Search results can be exported to CSV format by clicking the "Export to CSV" button that appears with the results.

## API

The module provides a service `content_radar.search_service` that can be used programmatically:

```php
// Search for text
$searchService = \Drupal::service('content_radar.search_service');
$results = $searchService->search('search term', FALSE, ['article', 'page']);

// Replace text (with dry run option)
$replaceResults = $searchService->replaceText(
  'old text',
  'new text',
  FALSE, // use regex
  ['article'], // content types
  TRUE // dry run
);
```

## Security Considerations

- The module follows Drupal coding standards and security best practices
- All user input is validated and sanitized
- Regular expressions are validated before execution
- Replace operations require explicit permission and confirmation
- All operations are logged for audit purposes

## Troubleshooting

- **No results found**: Ensure you have the correct permissions and that content exists
- **Regex errors**: Check your regular expression syntax
- **Performance issues**: The module uses caching, but very large sites may experience slower searches
- **Replace not working**: Ensure you have the "Replace text with ContentRadar" permission

## Contributing

This module follows Drupal.org coding standards. Before submitting patches:
- Run `phpcs` with Drupal standards
- Ensure all user input is properly sanitized
- Add appropriate test coverage
- Document any API changes

## Support

Please report issues in the issue queue.