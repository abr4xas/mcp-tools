# Extending MCP Tools

## Custom Schema Transformers

Schema transformers allow you to modify schemas before they are included in the final contract.

### Creating a Transformer

```php
<?php

namespace App\Transformers;

use Abr4xas\McpTools\Interfaces\SchemaTransformerInterface;

class CustomTransformer implements SchemaTransformerInterface
{
    public function transform(array $schema): array
    {
        // Add custom logic to transform schema
        foreach ($schema as $key => $value) {
            if (isset($value['type']) && $value['type'] === 'string') {
                $schema[$key]['format'] = 'custom-format';
            }
        }

        return $schema;
    }

    public function getPriority(): int
    {
        return 100; // Higher priority = applied first
    }
}
```

### Registering a Transformer

In your service provider:

```php
use Abr4xas\McpTools\Services\SchemaTransformerRegistry;

public function boot(): void
{
    $registry = $this->app->make(SchemaTransformerRegistry::class);
    $registry->register(new CustomTransformer());
}
```

## Custom Analyzers

You can create custom analyzers to extract additional information.

### Example Analyzer

```php
<?php

namespace App\Analyzers;

use Illuminate\Routing\Route;

class CustomAnalyzer
{
    public function analyze(Route $route): array
    {
        // Extract custom information
        return [
            'custom_field' => 'value',
        ];
    }
}
```

### Using in GenerateApiContractCommand

Extend the command and inject your analyzer:

```php
protected CustomAnalyzer $customAnalyzer;

public function __construct(
    // ... existing dependencies
    CustomAnalyzer $customAnalyzer
) {
    // ...
    $this->customAnalyzer = $customAnalyzer;
}
```

## Custom MCP Tools

Create custom MCP tools by extending the base Tool class:

```php
<?php

namespace App\Tools;

use Abr4xas\McpTools\Tool;

class CustomTool extends Tool
{
    public function name(): string
    {
        return 'custom-tool';
    }

    public function description(): string
    {
        return 'Custom tool description';
    }

    public function handle(array $arguments): array
    {
        // Implement tool logic
        return ['result' => 'data'];
    }
}
```

Register in your service provider:

```php
use Abr4xas\McpTools\McpToolsServiceProvider;

McpToolsServiceProvider::registerTool(CustomTool::class);
```
