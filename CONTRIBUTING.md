# Contributing to MCP Tools

Thank you for considering contributing to MCP Tools! This document provides guidelines and instructions for contributing to this project.

## Code of Conduct

By participating in this project, you agree to maintain a respectful and inclusive environment for all contributors.

## How to Contribute

### Reporting Bugs

If you find a bug, please open an issue with:

- A clear, descriptive title
- Steps to reproduce the issue
- Expected behavior
- Actual behavior
- PHP version, Laravel version, and package version
- Any relevant error messages or logs

### Suggesting Features

Feature suggestions are welcome! Please open an issue with:

- A clear description of the feature
- Use cases and examples
- Why this feature would be useful

### Pull Requests

1. **Fork the repository** and create a feature branch from `develop`
2. **Make your changes** following the coding standards below
3. **Add tests** for new features or bug fixes
4. **Ensure all tests pass** (`composer test`)
5. **Update documentation** if needed
6. **Submit a pull request** targeting the `develop` branch

## Development Setup

1. Clone the repository:
```bash
git clone https://github.com/abr4xas/mcp-tools.git
cd mcp-tools
```

2. Install dependencies:
```bash
composer install
```

3. Run tests:
```bash
composer test
```

## Coding Standards

### PHP Code Style

This project uses [Laravel Pint](https://laravel.com/docs/pint) for code style. Before committing, run:

```bash
composer format
```

Or manually:
```bash
vendor/bin/pint
```

### Code Quality

We use PHPStan for static analysis. Ensure your code passes:

```bash
composer analyse
```

### Testing

- Write tests for all new features and bug fixes
- Use [Pest PHP](https://pestphp.com/) for testing
- Aim for high test coverage
- Tests should be clear and descriptive

Run tests with:
```bash
composer test
```

Run tests with coverage:
```bash
composer test-coverage
```

## Commit Messages

Follow conventional commit format:

- `fix:` for bug fixes
- `feat:` for new features
- `refactor:` for code refactoring
- `docs:` for documentation changes
- `test:` for test additions or changes
- `chore:` for maintenance tasks

Include the issue number when applicable:
```
fix(command): corregir inconsistencia en signature del comando (#004)
```

## Pull Request Process

1. Ensure your branch is up to date with `develop`
2. Make sure all tests pass
3. Ensure code style is correct (Pint)
4. Update documentation if needed
5. Write a clear PR description explaining:
   - What changes were made
   - Why the changes were made
   - How to test the changes
   - Any breaking changes

## Project Structure

- `src/` - Source code
- `tests/` - Test files

## Questions?

If you have questions, please open an issue or contact the maintainers.

Thank you for contributing! 🎉
