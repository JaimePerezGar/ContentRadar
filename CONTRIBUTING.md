# Contributing to ContentRadar

First off, thank you for considering contributing to ContentRadar! It's people like you that make ContentRadar such a great tool.

## Code of Conduct

This project and everyone participating in it is governed by our Code of Conduct. By participating, you are expected to uphold this code.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check existing issues as you might find out that you don't need to create one. When you are creating a bug report, please include as many details as possible:

* **Use a clear and descriptive title**
* **Describe the exact steps which reproduce the problem**
* **Provide specific examples to demonstrate the steps**
* **Describe the behavior you observed after following the steps**
* **Explain which behavior you expected to see instead and why**
* **Include screenshots if possible**
* **Include your Drupal version, PHP version, and browser information**

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, please include:

* **Use a clear and descriptive title**
* **Provide a step-by-step description of the suggested enhancement**
* **Provide specific examples to demonstrate the steps**
* **Describe the current behavior and explain which behavior you expected to see instead**
* **Explain why this enhancement would be useful**

### Pull Requests

1. Fork the repo and create your branch from `main`
2. If you've added code that should be tested, add tests
3. Ensure your code follows Drupal coding standards
4. Make sure your code passes all tests
5. Issue that pull request!

## Development Setup

1. Clone your fork:
   ```bash
   git clone https://github.com/your-username/ContentRadar.git
   cd ContentRadar
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Run coding standards checks:
   ```bash
   vendor/bin/phpcs --standard=Drupal,DrupalPractice content_radar
   ```

4. Fix coding standards:
   ```bash
   vendor/bin/phpcbf --standard=Drupal,DrupalPractice content_radar
   ```

## Coding Standards

* Follow [Drupal Coding Standards](https://www.drupal.org/docs/develop/standards)
* Write meaningful commit messages
* Comment your code where necessary
* Update documentation for any changed functionality

## Testing

* Write tests for any new functionality
* Ensure all tests pass before submitting PR
* Include both unit tests and functional tests where applicable

## Documentation

* Update the README.md with details of changes to the interface
* Update the CHANGELOG.md following the existing format
* Comment your code following Drupal documentation standards

## Questions?

Feel free to open an issue with your question or contact the maintainers directly.

Thank you for contributing!