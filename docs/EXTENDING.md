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

Create custom MCP tools by extending Laravel MCP's Tool class:

```php
<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CustomTool extends Tool
{
    protected string $name = 'custom-tool';

    protected string $description = 'Custom tool description';

    public function handle(Request $request): Response
    {
        // Get arguments from request
        $argument = $request->get('argument');

        // Implement tool logic
        $result = ['result' => 'data'];

        // Return response (handle json_encode false case)
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return Response::text($json === false ? '{}' : $json);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'argument' => $schema->string()
                ->description('Example argument'),
        ];
    }
}
```

**Note**: MCP tools are automatically discovered by Laravel MCP server. No manual registration is needed - just ensure your tool class extends `Laravel\Mcp\Server\Tool` and is in a namespace that Laravel MCP can discover.
