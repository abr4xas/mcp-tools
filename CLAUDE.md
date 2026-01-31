# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

MCP Tools is a Laravel package that provides MCP (Model Context Protocol) tools for generating and managing API contracts. It scans Laravel routes and generates JSON contracts describing API endpoints with request/response schemas, authentication requirements, rate limits, and metadata.

**Requirements:** PHP 8.4+, Laravel 11.x or 12.x, Laravel MCP ^0.5.1

## Commands

### Development

```bash
composer test                    # Run Pest test suite
composer test-coverage           # Run tests with coverage
composer analyse                 # Run PHPStan (level 8)
composer format                  # Format code with Laravel Pint
```

### Single Test Execution

```bash
vendor/bin/pest tests/Feature/GenerateApiContractCommandTest.php
vendor/bin/pest tests/Feature/GenerateApiContractCommandTest.php --filter="generates API contract"
```

### Artisan Commands

```bash
php artisan api:contract:generate    # Generate API contract
php artisan api:export-openapi       # Export to OpenAPI format
php artisan api:contract:versions    # Manage contract versions
php artisan mcp-tools:health-check   # Health check
php artisan mcp-tools:metrics        # Show metrics
php artisan mcp-tools:clear-cache    # Clear analysis cache
php artisan mcp-tools:logs           # View logs
```

## Architecture

### Core Data Flow

```
Laravel Routes → GenerateApiContractCommand → Analyzers →
JSON Contract (storage/api-contracts/api.json) → MCP Tools → MCP Client
```

### Key Components

- **Analyzers** (`src/Analyzers/`): Extract information from routes, FormRequests, Resources, middleware, and PHPDoc comments
- **Commands** (`src/Commands/`): Artisan commands for contract generation and management
- **Tools** (`src/Tools/`): MCP tools that extend `Laravel\Mcp\Server\Tool` - must be manually registered in your MCP server
- **Services** (`src/Services/`): Caching services and schema transformer registry

### MCP Tools

1. `ListApiRoutes` - Lists/filters API routes with pagination
2. `DescribeApiRoute` - Detailed info about a specific route
3. `ValidateApiContract` - Validates contract is current
4. `CompareApiContracts` - Compares contract versions

MCP tools implement:
- Properties: `$name`, `$description`
- Methods: `handle(Request $request): Response`, `schema(JsonSchema $schema): array`

## Testing

**Framework:** PestPHP (NOT PHPUnit)

Tests use Pest syntax:
```php
it('command generates API contract successfully', function () {
    // test code using expect()
});
```

**Test organization:**
- `tests/Feature/` - Full command/tool tests
- `tests/Unit/` - Analyzer unit tests
- `tests/Integration/` - Tool/contract integration tests
- `tests/ArchTest.php` - Architecture tests

**Base test class** (`tests/TestCase.php`) provides:
- `createSampleContract(?array $contract)` - Creates test contract file
- `getDefaultContract()` - Returns sample contract structure
- Uses `Storage::fake('local')` for isolated storage

## Code Quality

- PHPStan level 8 - all errors must be resolved
- Laravel Pint for formatting
- Type hints required for all methods and properties

## Git Workflow

- Branch from `develop` for features/fixes
- PRs target `develop` branch
- `master` is the release branch

## Commit Convention

```
fix: for bug fixes
feat: for new features
refactor: for code refactoring
docs: for documentation changes
test: for test additions/changes
chore: for maintenance tasks
```
