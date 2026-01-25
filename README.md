  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png">
    <img alt="Logo for essentials" src="art/banner-light.png">
  </picture>

# MCP Tools

A Laravel package for generating and managing API contracts with MCP (Model Context Protocol) integration.

## Features

- **Automatic API Contract Generation**: Generate comprehensive API contracts from your Laravel routes
- **MCP Tools Integration**: List and describe API routes through MCP tools
- **Advanced Analysis**: Deep analysis of routes, FormRequests, Resources, and middleware
- **OpenAPI Export**: Export contracts to OpenAPI 3.0 format
- **Caching**: Intelligent caching for improved performance
- **Validation**: JSON Schema validation for generated contracts

## Installation

```bash
composer require abr4xas/mcp-tools
```

## Usage

### Generate API Contract

```bash
php artisan api:contract:generate
```

Options:
- `--incremental`: Only update routes that have been modified
- `--log`: Enable detailed logging
- `--dry-run`: Validate without writing file
- `--validate-schemas`: Validate generated schemas against JSON Schema

### List API Routes

Use the MCP tool `list-api-routes` to query your API routes:

```json
{
  "method": ["GET", "POST"],
  "version": "v1",
  "search": "users",
  "page": 1,
  "per_page": 20
}
```

### Describe API Route

Use the MCP tool `describe-api-route` to get detailed information about a route:

```json
{
  "path": "/api/v1/users/{id}",
  "method": "GET"
}
```

### Export to OpenAPI

```bash
php artisan api:contract:export-openapi
```

### Clear Cache

```bash
php artisan api:contract:clear-cache
```

### Health Check

```bash
php artisan api:contract:health-check
```

### View Metrics

```bash
php artisan api:contract:metrics
```

## Configuration

The package automatically detects:
- Route parameters and types
- Authentication requirements
- Rate limiting
- Middleware
- Request validation rules
- Response schemas
- HTTP status codes
- Headers

## Extending

### Custom Schema Transformers

Create a transformer implementing `SchemaTransformerInterface`:

```php
use Abr4xas\McpTools\Interfaces\SchemaTransformerInterface;

class CustomTransformer implements SchemaTransformerInterface
{
    public function transform(array $schema): array
    {
        // Transform schema
        return $schema;
    }

    public function getPriority(): int
    {
        return 100;
    }
}
```

Register it in your service provider:

```php
$this->app->make(SchemaTransformerRegistry::class)
    ->register(new CustomTransformer());
```

## Testing

```bash
composer test
```

## License

MIT for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/abr4xas/mcp-tools.svg?style=flat-square)](https://packagist.org/packages/abr4xas/mcp-tools)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/abr4xas/mcp-tools/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/abr4xas/mcp-tools/actions?query=workflow%3Arun-tests+branch%3Amaster)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/abr4xas/mcp-tools/fix-php-code-style-issues.yml?branch=master&label=code%20style&style=flat-square)](https://github.com/abr4xas/mcp-tools/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amaster)
[![Total Downloads](https://img.shields.io/packagist/dt/abr4xas/mcp-tools.svg?style=flat-square)](https://packagist.org/packages/abr4xas/mcp-tools)

A growing collection of Model Context Protocol (MCP) tools designed to enhance Laravel development with AI assistance. This package provides ready-to-use MCP tools that integrate seamlessly with your Laravel MCP server.

> [!IMPORTANT]
> This package provides MCP tools that must be registered in your project's MCP server. It does not create or run an MCP server itself - you need to have [Laravel MCP](https://github.com/laravel/mcp) configured in your project.

## What's Included

Currently, this package focuses on **API development tools**, with more tools planned for future releases:

### API Tools

-   **API Contract Generation**: Automatically scan Laravel routes and generate comprehensive API documentation
-   **Route Discovery**: List and filter API routes by method, version, and search terms
-   **Route Description**: Get detailed endpoint information including auth, parameters, and schemas

### Coming Soon

More MCP tools will be added to assist with various aspects of Laravel development.

## Installation

Install the package via composer:

```bash
composer require abr4xas/mcp-tools
```

The package will automatically register its service provider and MCP tools.

## Usage

### API Contract Generation

Generate a comprehensive API contract from your Laravel routes:

```bash
php artisan api:generate-contract
```

This command will:

-   Scan all your application routes
-   Extract route information (methods, paths, parameters)
-   Analyze controller methods and FormRequest classes
-   Generate authentication requirements
-   Create a JSON file at `storage/api-contracts/api.json`

### Available MCP Tools

Once installed, the package provides these MCP tools that will be automatically discovered by your Laravel MCP server:

#### 1. `list-api-routes`

Lists all API routes with optional filtering.

**Arguments:**

-   `method` (optional): Filter by HTTP method (GET, POST, PUT, DELETE, PATCH)
-   `version` (optional): Filter by API version (v1, v2, etc.)
-   `search` (optional): Search term to filter routes by path
-   `limit` (optional): Maximum number of results (default: 50, max: 200)

**Example:**

```json
{
    "method": "GET",
    "version": "v1",
    "search": "users",
    "limit": 10
}
```

#### 2. `describe-api-route`

Get detailed information about a specific endpoint.

**Arguments:**

-   `path` (required): The API route path (e.g., `/api/v1/users/{user}`)
-   `method` (optional): HTTP method (defaults to GET)

**Example:**

```json
{
    "path": "/api/v1/users/{user}",
    "method": "GET"
}
```

**Response includes:**

-   Route description
-   API version
-   Authentication requirements
-   Path parameters with types
-   Request/response schemas (if available)

## API Contract Structure

The generated contract at `storage/api-contracts/api.json` follows this structure:

```json
{
    "/api/v1/users": {
        "GET": {
            "description": "List all users",
            "api_version": "v1",
            "auth": {
                "type": "bearer"
            },
            "path_parameters": {}
        },
        "POST": {
            "description": "Create a new user",
            "api_version": "v1",
            "auth": {
                "type": "bearer"
            },
            "request_schema": {...},
            "response_schema": {...}
        }
    },
    "/api/v1/users/{user}": {
        "GET": {
            "description": "Get user details",
            "api_version": "v1",
            "auth": {
                "type": "bearer"
            },
            "path_parameters": {
                "user": {
                    "type": "integer"
                }
            }
        }
    }
}
```

## Requirements

-   PHP 8.4+
-   Laravel 12.x
-   [Laravel MCP](https://github.com/laravel/mcp) ^0.4.2

## Testing

```bash
composer test
```

Run tests with coverage:

```bash
vendor/bin/pest --coverage
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

-   [Angel](https://github.com/abr4xas)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
