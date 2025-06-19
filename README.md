# ContentRadar 🎯

[![Drupal Version](https://img.shields.io/badge/Drupal-10%20%7C%2011-blue.svg)](https://www.drupal.org)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF.svg)](https://php.net)

ContentRadar is an advanced content search and analysis tool for Drupal administrators. It provides powerful search capabilities across all text fields with multilingual support, content replacement features, and comprehensive reporting.

## ✨ Features

- 🔍 **Advanced Search**: Search across all text fields in your content
- 🌐 **Multilingual Support**: Search and replace in specific languages or all translations
- 🎯 **Regular Expressions**: Support for complex pattern matching
- 📊 **Grouped Results**: Organized display by content type with visual cards
- 🔄 **Find & Replace**: Safely replace text across your content with preview mode
- 📈 **Content Analytics**: See where content is used and referenced
- 💾 **Export to CSV**: Export search results for external analysis
- 🎨 **Modern UI**: Clean, responsive interface with excellent UX
- ⚡ **Performance**: Built-in caching for fast repeated searches
- 🔒 **Secure**: Permission-based access with input validation

## 📋 Requirements

- Drupal 10 or 11
- PHP 7.4 or higher
- Standard Drupal modules: Field, Node, User, System

## 🚀 Installation

### Using Composer (Recommended)

```bash
composer require jaimeperezgar/content_radar
drush en content_radar
```

### Manual Installation

1. Download the module to your `modules/custom` directory:
   ```bash
   cd modules/custom
   git clone https://github.com/JaimePerezGar/ContentRadar.git content_radar
   ```

2. Enable the module:
   ```bash
   drush en content_radar
   ```

3. Clear caches:
   ```bash
   drush cr
   ```

## 🔧 Configuration

1. Navigate to **People > Permissions** (`/admin/people/permissions`)
2. Grant permissions:
   - **Use ContentRadar**: Allows searching content
   - **Replace text with ContentRadar**: Allows find & replace (use with caution)
3. Access the tool at **Content > ContentRadar** (`/admin/content/content-radar`)

## 📖 Usage

### Basic Search

1. Enter your search term in the search field
2. Select the language to search in (or leave empty for all languages)
3. Choose specific content types (optional)
4. Click "Search" to see results

### Advanced Search with Regular Expressions

Enable the "Use regular expressions" checkbox to use patterns like:
- `\bword\b` - Match whole word only
- `test.*ing` - Match "test" followed by any characters and "ing"
- `(foo|bar)` - Match either "foo" or "bar"
- `\d{3}-\d{4}` - Match phone number patterns
- `^Title:` - Match text at the beginning of a field

### Find and Replace

1. Enter your search term
2. Open the "Find and Replace" section
3. Enter the replacement text
4. Click "Preview Replace" to see what would change
5. Review the preview carefully
6. Check the confirmation box
7. Click "Replace All" to apply changes

⚠️ **Warning**: Replace operations modify content directly. Always backup before bulk replacements.

### Export Results

Click the "Export CSV" button to download search results. The CSV includes:
- Content type
- Node ID
- Title
- Language
- Field name
- Text extract
- Publication status
- Last modified date
- Content URL

## 🔌 API Usage

ContentRadar provides a service for programmatic usage:

```php
// Get the service
$contentRadar = \Drupal::service('content_radar.search_service');

// Simple search
$results = $contentRadar->search('search term', FALSE, ['article', 'page'], 'en');

// Search with regex
$results = $contentRadar->search('\d{3}-\d{4}', TRUE, [], 'es');

// Replace text (with dry run)
$replaceResults = $contentRadar->replaceText(
  'old text',
  'new text',
  FALSE, // use regex
  ['article'], // content types
  'en', // language
  TRUE // dry run (preview only)
);

// Export to CSV
$csv = $contentRadar->exportToCsv('search term', FALSE, ['page'], 'en');
```

## 🎨 Theming

ContentRadar uses a custom theme template that can be overridden:

1. Copy `templates/content-radar-results.html.twig` to your theme
2. Customize as needed
3. Clear caches

Available variables in the template:
- `results`: Array of search results
- `grouped_results`: Results organized by content type
- `total`: Total number of matches
- `search_term`: The search query
- `is_regex`: Whether regex was used
- `langcode`: Language code searched

## 🐛 Troubleshooting

### No results found
- Verify you have the correct permissions
- Check that content exists in the selected language
- If using regex, validate your pattern syntax

### Performance issues
- ContentRadar caches results for 15 minutes
- For very large sites, consider searching specific content types
- Use pagination to limit results per page

### Replace not working
- Ensure you have the "Replace text with ContentRadar" permission
- Check that content is not locked by another user
- Verify you have edit permissions for the content

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Follow Drupal coding standards
4. Add tests for new functionality
5. Submit a pull request

### Coding Standards

```bash
# Check coding standards
phpcs --standard=Drupal,DrupalPractice modules/custom/content_radar

# Fix coding standards
phpcbf --standard=Drupal,DrupalPractice modules/custom/content_radar
```

## 📄 License

This project is licensed under the GNU General Public License v2.0 or later - see the [LICENSE](LICENSE) file for details.

## 👥 Credits

- **Author**: Jaime Pérez García
- **GitHub**: [@JaimePerezGar](https://github.com/JaimePerezGar)

## 🆘 Support

- **Issues**: [GitHub Issues](https://github.com/JaimePerezGar/ContentRadar/issues)
- **Documentation**: [Wiki](https://github.com/JaimePerezGar/ContentRadar/wiki)

---

Made with ❤️ for the Drupal community