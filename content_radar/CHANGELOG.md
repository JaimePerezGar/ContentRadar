# Changelog

All notable changes to the Content Radar module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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