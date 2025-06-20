# Content Radar

[![Drupal Version](https://img.shields.io/badge/Drupal-10%20%7C%2011-blue.svg)](https://www.drupal.org)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF.svg)](https://php.net)

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
- **Performance**: Efficient search with pagination
- **Secure**: Permission-based access with input validation

## Requirements

- Drupal 10 or 11
- PHP 7.4 or higher
- Standard Drupal modules: Field, Node, User, System

## Installation

1. Download the module from this repository
2. Place the `content_radar` folder in your `modules/custom` directory
3. Enable the module: `drush en content_radar`
4. Configure permissions at `/admin/people/permissions#module-content_radar`

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

## Development

This module follows Drupal coding standards:
- PSR-4 autoloading
- Dependency injection
- Theme system integration
- Proper permission handling
- Database abstraction layer
- Translation support

## Support

For issues or feature requests, please use the [GitHub issues](https://github.com/JaimePerezGar/ContentRadar/issues) page.

## License

This project is licensed under the GPL-2.0+ License - see the LICENSE file for details.