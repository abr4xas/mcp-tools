<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportOpenApiCommand extends Command
{
    protected $signature = 'api:export-openapi 
                            {--contract= : Path to the API contract file (default: storage/api-contracts/api.json)}
                            {--output= : Output file path (default: storage/api-contracts/openapi.json)}
                            {--format=json : Output format (json or yaml)}';

    protected $description = 'Export API contract to OpenAPI/Swagger format';

    public function handle(): int
    {
        $contractPath = $this->option('contract') ?? storage_path('api-contracts/api.json');
        $outputPath = $this->option('output') ?? storage_path('api-contracts/openapi.json');
        $format = $this->option('format') ?? 'json';

        if (! File::exists($contractPath)) {
            $this->error("Contract file not found: {$contractPath}");
            $this->info('Run "php artisan api:contract:generate" to create the contract first.');

            return self::FAILURE;
        }

        $contract = json_decode(File::get($contractPath), true);
        if (! is_array($contract)) {
            $this->error('Invalid contract file format.');

            return self::FAILURE;
        }

        $this->info('Converting contract to OpenAPI format...');

        $openApi = $this->convertToOpenApi($contract);

        if ($format === 'yaml') {
            // For YAML, we'd need a YAML library, but for now we'll output JSON
            // and suggest using a converter
            $this->warn('YAML format requires additional dependencies. Outputting JSON instead.');
            $this->info('You can convert JSON to YAML using online tools or yq command.');
            $format = 'json';
        }

        $output = json_encode($openApi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Ensure output directory exists
        $outputDir = dirname($outputPath);
        if (! File::exists($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        File::put($outputPath, $output);

        $this->info("OpenAPI contract exported to: {$outputPath}");

        return self::SUCCESS;
    }

    /**
     * Convert internal contract format to OpenAPI 3.0
     *
     * @param  array<string, array>  $contract
     * @return array<string, mixed>
     */
    protected function convertToOpenApi(array $contract): array
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => config('app.name', 'Laravel API'),
                'version' => '1.0.0',
                'description' => 'API documentation generated from Laravel routes',
            ],
            'servers' => [
                [
                    'url' => config('app.url', 'http://localhost'),
                    'description' => 'API Server',
                ],
            ],
            'paths' => [],
            'components' => [
                'securitySchemes' => $this->extractSecuritySchemes($contract),
            ],
        ];

        foreach ($contract as $path => $methods) {
            $openApiPath = $this->convertPathToOpenApi($path);
            $openApi['paths'][$openApiPath] = [];

            foreach ($methods as $httpMethod => $routeData) {
                $method = mb_strtolower($httpMethod);
                $openApi['paths'][$openApiPath][$method] = $this->convertRouteToOpenApi($routeData, $httpMethod);
            }
        }

        return $openApi;
    }

    /**
     * Convert path format to OpenAPI format
     */
    protected function convertPathToOpenApi(string $path): string
    {
        // Convert {param} to {param} (OpenAPI uses same format)
        return $path;
    }

    /**
     * Convert route data to OpenAPI operation
     *
     * @param  array<string, mixed>  $routeData
     * @return array<string, mixed>
     */
    protected function convertRouteToOpenApi(array $routeData, string $method): array
    {
        $operation = [
            'summary' => $routeData['description'] ?? ucfirst(mb_strtolower($method)) . ' endpoint',
            'operationId' => $this->generateOperationId($routeData, $method),
        ];

        // Add description if available
        if (isset($routeData['description'])) {
            $operation['description'] = $routeData['description'];
        }

        // Add tags based on path
        $operation['tags'] = $this->extractTags($routeData);

        // Add parameters (path parameters)
        if (isset($routeData['path_parameters']) && ! empty($routeData['path_parameters'])) {
            $operation['parameters'] = $this->convertPathParameters($routeData['path_parameters']);
        }

        // Add request body for POST, PUT, PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && isset($routeData['request_schema'])) {
            $operation['requestBody'] = $this->convertRequestSchema($routeData['request_schema']);
        }

        // Add responses
        $operation['responses'] = $this->convertResponseSchema($routeData);

        // Add security
        if (isset($routeData['auth']) && $routeData['auth']['type'] !== 'none') {
            $operation['security'] = $this->convertAuthToSecurity($routeData['auth']);
        }

        return $operation;
    }

    /**
     * Convert path parameters to OpenAPI parameters
     *
     * @param  array<string, array{type: string}>  $pathParams
     * @return array<int, array<string, mixed>>
     */
    protected function convertPathParameters(array $pathParams): array
    {
        $parameters = [];

        foreach ($pathParams as $name => $param) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => [
                    'type' => $param['type'] ?? 'string',
                ],
            ];
        }

        return $parameters;
    }

    /**
     * Convert request schema to OpenAPI request body
     *
     * @param  array<string, mixed>  $requestSchema
     * @return array<string, mixed>
     */
    protected function convertRequestSchema(array $requestSchema): array
    {
        $requestBody = [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => $this->convertSchemaToOpenApi($requestSchema['properties'] ?? []),
                ],
            ],
        ];

        return $requestBody;
    }

    /**
     * Convert response schema to OpenAPI responses
     *
     * @param  array<string, mixed>  $routeData
     * @return array<string, mixed>
     */
    protected function convertResponseSchema(array $routeData): array
    {
        $responses = [
            '200' => [
                'description' => 'Successful response',
            ],
        ];

        if (isset($routeData['response_schema']) && ! isset($routeData['response_schema']['undocumented'])) {
            $responses['200']['content'] = [
                'application/json' => [
                    'schema' => $this->convertSchemaToOpenApi($routeData['response_schema']),
                ],
            ];
        }

        // Add error responses
        $responses['400'] = ['description' => 'Bad Request'];
        $responses['401'] = ['description' => 'Unauthorized'];
        $responses['404'] = ['description' => 'Not Found'];
        $responses['500'] = ['description' => 'Internal Server Error'];

        return $responses;
    }

    /**
     * Convert internal schema format to OpenAPI schema
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function convertSchemaToOpenApi(array $schema): array
    {
        if (empty($schema)) {
            return ['type' => 'object'];
        }

        $openApiSchema = ['type' => 'object', 'properties' => []];

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                if (isset($value['type'])) {
                    $openApiSchema['properties'][$key] = $this->convertPropertyToOpenApi($value);
                } else {
                    // Nested object or array
                    $openApiSchema['properties'][$key] = $this->convertSchemaToOpenApi($value);
                }
            }
        }

        // Add required fields
        $required = [];
        foreach ($schema as $key => $value) {
            if (is_array($value) && isset($value['required']) && $value['required']) {
                $required[] = $key;
            }
        }
        if (! empty($required)) {
            $openApiSchema['required'] = $required;
        }

        return $openApiSchema;
    }

    /**
     * Convert property to OpenAPI property format
     *
     * @param  array<string, mixed>  $property
     * @return array<string, mixed>
     */
    protected function convertPropertyToOpenApi(array $property): array
    {
        $openApiProperty = [
            'type' => $property['type'] ?? 'string',
        ];

        if (isset($property['constraints'])) {
            foreach ($property['constraints'] as $constraint) {
                if (str_starts_with($constraint, 'min:')) {
                    $openApiProperty['minimum'] = (int) substr($constraint, 4);
                } elseif (str_starts_with($constraint, 'max:')) {
                    $openApiProperty['maximum'] = (int) substr($constraint, 4);
                } elseif ($constraint === 'email') {
                    $openApiProperty['format'] = 'email';
                } elseif ($constraint === 'url') {
                    $openApiProperty['format'] = 'uri';
                } elseif ($constraint === 'uuid') {
                    $openApiProperty['format'] = 'uuid';
                } elseif ($constraint === 'date') {
                    $openApiProperty['format'] = 'date';
                }
            }
        }

        return $openApiProperty;
    }

    /**
     * Convert auth to OpenAPI security
     *
     * @param  array<string, mixed>  $auth
     * @return array<int, array<string, array>>
     */
    protected function convertAuthToSecurity(array $auth): array
    {
        $authType = $auth['type'] ?? 'none';

        return match ($authType) {
            'bearer' => [['bearerAuth' => []]],
            'oauth2' => [['oauth2' => []]],
            'apiKey' => [['apiKey' => []]],
            'basic' => [['basicAuth' => []]],
            default => [],
        };
    }

    /**
     * Extract security schemes from contract
     *
     * @param  array<string, array>  $contract
     * @return array<string, mixed>
     */
    protected function extractSecuritySchemes(array $contract): array
    {
        $schemes = [];
        $authTypes = [];

        foreach ($contract as $methods) {
            foreach ($methods as $routeData) {
                if (isset($routeData['auth']) && $routeData['auth']['type'] !== 'none') {
                    $authType = $routeData['auth']['type'];
                    if (! in_array($authType, $authTypes, true)) {
                        $authTypes[] = $authType;
                    }
                }
            }
        }

        if (in_array('bearer', $authTypes, true)) {
            $schemes['bearerAuth'] = [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
            ];
        }

        if (in_array('oauth2', $authTypes, true)) {
            $schemes['oauth2'] = [
                'type' => 'oauth2',
                'flows' => [
                    'authorizationCode' => [
                        'authorizationUrl' => config('app.url', 'http://localhost') . '/oauth/authorize',
                        'tokenUrl' => config('app.url', 'http://localhost') . '/oauth/token',
                        'scopes' => [],
                    ],
                ],
            ];
        }

        if (in_array('apiKey', $authTypes, true)) {
            $schemes['apiKey'] = [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'X-API-Key',
            ];
        }

        if (in_array('basic', $authTypes, true)) {
            $schemes['basicAuth'] = [
                'type' => 'http',
                'scheme' => 'basic',
            ];
        }

        return $schemes;
    }

    /**
     * Generate operation ID from route data
     *
     * @param  array<string, mixed>  $routeData
     */
    protected function generateOperationId(array $routeData, string $method): string
    {
        // Simple operation ID generation
        // In a real scenario, you might want to use route names
        $path = $routeData['path'] ?? '';
        $pathParts = explode('/', trim($path, '/'));
        $lastPart = end($pathParts);
        $lastPart = str_replace(['{', '}'], '', $lastPart);

        return mb_strtolower($method) . ucfirst($lastPart);
    }

    /**
     * Extract tags from route data
     *
     * @param  array<string, mixed>  $routeData
     * @return array<int, string>
     */
    protected function extractTags(array $routeData): array
    {
        $tags = [];

        if (isset($routeData['api_version'])) {
            $tags[] = $routeData['api_version'];
        }

        return $tags;
    }
}
