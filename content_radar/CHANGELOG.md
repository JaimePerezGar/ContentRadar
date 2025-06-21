# Changelog

All notable changes to the Content Radar module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2025-01-21

### Added
- Case-sensitive search and replace functionality
- Improved form structure with case_sensitive checkbox placement
- Enhanced test coverage for case sensitivity features
- Composer.json file for better package management

### Changed
- Moved case_sensitive checkbox to main form area for better visibility
- Updated all search/replace methods to support case sensitivity parameter
- Improved code documentation and examples

### Fixed
- Consistent parameter ordering across all methods
- Test alignment with actual implementation

## [1.2.0] - 2024-01-21

### Fixed
- Case-sensitive search functionality now works correctly
- Checkbox values are properly captured from form submission
- Improved form layout with case-sensitive option prominently placed

### Changed
- Moved case-sensitive checkbox outside of details container for better form processing
- Updated all tests to match new form structure
- Enhanced test coverage for case-sensitive operations

### Technical
- Fixed form value retrieval for nested checkbox elements
- Updated all method signatures to include case_sensitive parameter
- Comprehensive test suite updates

## [1.0.0] - 2024-01-21

### Added
- Initial release of Content Radar module
- Text search functionality across all entity types
- Support for regular expressions in searches
- Case-sensitive search option
- Deep search capability for nested entities and references
- Layout Builder and VLSuite block search support
- Batch processing for text replacements
- Undo functionality with new report generation
- Reports system to track all changes
- Export search results to CSV
- Multi-language support
- Comprehensive test coverage (Unit, Kernel, and Functional tests)
- Configuration schema
- Coding standards compliance

### Features
- Search text in all entity types (nodes, blocks, paragraphs, etc.)
- Search in Layout Builder inline blocks and configurations
- Search in serialized data fields
- Replace text with batch processing
- Track all replacements with detailed reports
- Undo replacements with full audit trail
- Filter by entity types and bundles
- Export results for external analysis

### Security
- Permission-based access control
- Input validation for regex patterns
- XSS prevention in templates
- Secure database operations