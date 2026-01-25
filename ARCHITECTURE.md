# Architecture

## Overview

MCP Tools is built with a modular architecture that separates concerns into distinct analyzers, services, and tools.

## Core Components

### Analyzers

Analyzers are responsible for extracting information from different parts of the Laravel application:

- **RouteAnalyzer**: Extracts route information (parameters, auth, rate limits, middleware)
- **FormRequestAnalyzer**: Parses validation rules and generates request schemas
- **ResourceAnalyzer**: Analyzes API Resources and generates response schemas
- **PhpDocAnalyzer**: Extracts PHPDoc comments for descriptions
- **MiddlewareAnalyzer**: Analyzes middleware applied to routes
- **ResponseCodeAnalyzer**: Detects possible HTTP status codes

### Services

Services provide shared functionality:

- **AnalysisCacheService**: Caches analysis results with file modification time validation
- **AstCacheService**: Caches AST parsing results
- **JsonSchemaValidator**: Validates schemas against JSON Schema
- **SchemaTransformerRegistry**: Manages custom schema transformers
- **ExampleGenerator**: Generates example data from schemas

### Commands

Artisan commands for CLI operations:

- **GenerateApiContractCommand**: Main command for generating API contracts
- **ClearCacheCommand**: Clears analysis cache
- **ExportOpenApiCommand**: Exports contracts to OpenAPI format
- **ContractVersionCommand**: Manages contract versions
- **HealthCheckCommand**: Performs health checks
- **MetricsCommand**: Displays contract metrics
- **ViewLogsCommand**: Views recent logs

### MCP Tools

MCP tools for external integration:

- **ListApiRoutes**: Lists and filters API routes
- **DescribeApiRoute**: Describes a single API route

## Data Flow

1. **Route Discovery**: Routes are discovered from Laravel's route registry
2. **Analysis**: Each route is analyzed by multiple analyzers
3. **Caching**: Results are cached for performance
4. **Schema Generation**: Schemas are generated from analyzed data
5. **Transformation**: Custom transformers can modify schemas
6. **Validation**: Schemas are validated (optional)
7. **Contract Generation**: Final contract is assembled and saved

## Caching Strategy

- **File-based invalidation**: Cache is invalidated when source files change
- **Hash-based keys**: Fast lookup using file content hashes
- **Type-specific caching**: Separate cache for routes, resources, FormRequests

## Error Handling

Custom exceptions provide detailed error information:

- **AnalysisException**: Base exception for all analysis errors
- **RouteAnalysisException**: Route-specific errors
- **FormRequestAnalysisException**: FormRequest-specific errors
- **ResourceAnalysisException**: Resource-specific errors

## Extension Points

- **Schema Transformers**: Transform schemas before final generation
- **Custom Analyzers**: Add new analyzers for additional information
- **Custom Exceptions**: Extend exception handling
