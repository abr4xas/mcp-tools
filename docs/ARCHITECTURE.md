# Architecture Documentation

## Overview

This package provides Laravel MCP (Model Context Protocol) tools for generating and querying API contracts. The architecture is designed to be modular, extensible, and maintainable.

## Components

### 1. API Contract Generation (`GenerateApiContractCommand`)

The command scans Laravel routes and generates a comprehensive JSON contract describing all API endpoints.

#### Flow

1. **Route Discovery**: Scans all registered Laravel routes
2. **Route Analysis**: For each route:
   - Extracts path parameters
   - Determines authentication requirements
   - Analyzes request schemas (FormRequests)
   - Analyzes response schemas (Resources)
   - Extracts metadata (rate limits, custom headers, API version)
3. **Contract Generation**: Compiles all information into a JSON structure
4. **File Output**: Writes contract to `storage/api-contracts/api.json`

#### Key Methods

The command uses various analyzers to extract information:

- `RouteAnalyzer::extractPathParams()`: Extracts path parameters from route URI
- `RouteAnalyzer::determineAuth()`: Analyzes middleware to determine auth type
- `extractRequestSchema()`: Analyzes FormRequest classes for validation rules
- `extractResponseSchema()`: Analyzes Resource classes for response structure
- `ResourceAnalyzer::simulateResourceOutput()`: Creates mock models to generate schema examples

### 2. Contract Loading

Each MCP Tool loads contracts independently using a shared `loadContract()` method pattern.

#### Features

- **Caching**: Static cache to avoid repeated file reads
- **Validation**: Ensures contract has correct structure before use
- **Error Handling**: Returns null for invalid contracts
- **File Path**: Contracts are stored at `storage/api-contracts/api.json`

### 3. MCP Tools

The package provides four MCP tools for API contract interaction:

#### `list-api-routes` (ListApiRoutes)

Lists all API routes with filtering and pagination.

**Features:**
- Filter by HTTP method
- Filter by API version
- Search across multiple fields (path, parameters, schemas, etc.)
- Pagination support (page/offset)
- Sorting options
- Grouping by controller, prefix, or version
- Optional metadata inclusion

**Search Capabilities:**
- Path matching
- Path parameters
- API version
- Auth type
- Request/response schema fields
- Rate limit names
- Controller names
- Resource names

#### `describe-api-route` (DescribeApiRoute)

Provides detailed information about a specific API route.

**Features:**
- Exact path matching
- Pattern matching for dynamic routes
- Complete route metadata
- Request/response schemas
- Authentication requirements
- Rate limits
- Custom headers
- API version
- HTTP status codes
- Content negotiation

#### `validate-api-contract` (ValidateApiContract)

Validates that the API contract is up to date with current routes.

**Features:**
- Detects new routes not in contract
- Detects removed routes
- Detects method changes
- Provides summary statistics
- Returns validation issues with details

#### `compare-api-contracts` (CompareApiContracts)

Compares two versions of API contracts and detects changes.

**Features:**
- Detects added routes
- Detects removed routes
- Detects modified routes
- Detects schema changes
- Detects authentication changes
- Provides detailed change summary

## Data Flow

```
Laravel Routes
    ↓
GenerateApiContractCommand
    ↓
API Contract JSON (storage/api-contracts/api.json)
    ↓
MCP Tools (loadContract method with caching)
    ↓
MCP Client Response
```

## Configuration

The package uses Laravel's standard paths:

- **Contract Path**: `storage/api-contracts/api.json` (default)
- **Resources Path**: `app/Http/Resources` (Laravel default)
- **Models Path**: `app/Models` (Laravel default)

All paths can be customized by modifying the command or tool classes.

## Extension Points

### Adding Custom Analyzers

You can extend the contract generation by creating custom analyzers:

```php
class CustomAnalyzer
{
    public function analyze($route, $routeData): array
    {
        // Your custom analysis logic
        return ['custom_field' => 'value'];
    }
}
```

### Custom Contract Loaders

Each MCP Tool implements its own `loadContract()` method. You can create a base class or trait to share loading logic:

```php
trait LoadsContract
{
    protected function loadContract(): ?array
    {
        // Your custom loading logic
        $path = storage_path('api-contracts/api.json');
        // ... load and validate
    }
}
```

## Design Decisions

### Why Static Analysis?

- **No Runtime Dependencies**: Doesn't require running the application
- **Fast**: Can analyze code without executing it
- **Safe**: Doesn't modify application state

### Why JSON Contract Format?

- **Language Agnostic**: Can be consumed by any client
- **Human Readable**: Easy to debug and inspect
- **Structured**: Supports complex nested data

### Why Shared loadContract Pattern?

- **DRY Principle**: Each tool implements similar loading logic
- **Consistency**: All tools use same file path and validation
- **Maintainability**: Easy to update loading logic across tools

## Performance Considerations

- **Caching**: Contracts are cached in memory to avoid repeated file reads
- **Lazy Loading**: Contracts are only loaded when needed
- **Efficient Filtering**: Filters are applied before pagination to minimize data processing

## Security Considerations

- **Path Validation**: All paths are validated before use
- **Structure Validation**: Contracts are validated before loading
- **No Code Execution**: Static analysis doesn't execute user code

## Future Improvements

- AST-based analysis instead of regex (partially implemented)
- Support for OpenAPI/Swagger format (implemented via ExportOpenApiCommand)
- Intelligent cache invalidation (implemented with file modification time)
- Contract validation against actual routes (implemented via ValidateApiContract tool)
