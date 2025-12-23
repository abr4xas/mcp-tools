  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png">
    <img alt="Logo for essentials" src="art/banner-light.png">
  </picture>

# MCP Tools for Laravel

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

The package will automatically register its service provider. However, the MCP tools must be manually registered in your project's MCP server configuration.

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

Once installed, the package provides these MCP tools. You must register them manually in your Laravel MCP server configuration:

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

### Registering MCP Tools

The MCP tools provided by this package must be manually registered in your Laravel MCP server configuration. Here's how to register them:

1. **Locate your MCP server configuration file** (typically `config/mcp.php` or in your MCP server setup)

2. **Register the tools** by adding them to your MCP server's tool registry:

```php
use Abr4xas\McpTools\Tools\ListApiRoutes;
use Abr4xas\McpTools\Tools\DescribeApiRoute;

// In your MCP server configuration or service provider
$server->registerTool(ListApiRoutes::class);
$server->registerTool(DescribeApiRoute::class);
```

Or if you're using a configuration array:

```php
// config/mcp.php
return [
    'tools' => [
        \Abr4xas\McpTools\Tools\ListApiRoutes::class,
        \Abr4xas\McpTools\Tools\DescribeApiRoute::class,
        // ... your other tools
    ],
];
```

3. **Verify registration** by checking your MCP server's available tools list.

#### Troubleshooting

If you encounter issues registering the tools:

- **Tools not appearing**: Ensure the MCP server configuration file is being loaded correctly
- **Class not found errors**: Run `composer dump-autoload` to refresh the autoloader
- **Service provider not registered**: Check that `Abr4xas\McpTools\McpToolsServiceProvider` is in your `config/app.php` providers array (should be auto-discovered)

#### Complete Example

Here's a complete example of a typical MCP server configuration:

```php
<?php
// config/mcp.php

use Abr4xas\McpTools\Tools\ListApiRoutes;
use Abr4xas\McpTools\Tools\DescribeApiRoute;

return [
    'tools' => [
        ListApiRoutes::class,
        DescribeApiRoute::class,
        // ... your other MCP tools
    ],

    // Other MCP server configuration...
];
```

> **Note**: The service provider is automatically registered by Laravel, but the MCP tools themselves require manual registration in your MCP server configuration.

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
