<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Contract Storage Path
    |--------------------------------------------------------------------------
    |
    | This is the path where the generated API contract JSON file will be
    | stored. The default is storage/api-contracts/api.json
    |
    */
    'contract_path' => env('MCP_TOOLS_CONTRACT_PATH', storage_path('api-contracts/api.json')),

    /*
    |--------------------------------------------------------------------------
    | Resources Directory
    |--------------------------------------------------------------------------
    |
    | The directory where Laravel Resources are located. This is used to
    | scan for available resources when generating the API contract.
    |
    */
    'resources_path' => env('MCP_TOOLS_RESOURCES_PATH', app_path('Http/Resources')),

    /*
    |--------------------------------------------------------------------------
    | Resources Namespace
    |--------------------------------------------------------------------------
    |
    | The base namespace for Laravel Resources. This is used when resolving
    | resource class names.
    |
    */
    'resources_namespace' => env('MCP_TOOLS_RESOURCES_NAMESPACE', 'App\\Http\\Resources'),

    /*
    |--------------------------------------------------------------------------
    | Models Namespace
    |--------------------------------------------------------------------------
    |
    | The base namespace for Laravel Models. This is used when resolving
    | model class names from resource names.
    |
    */
    'models_namespace' => env('MCP_TOOLS_MODELS_NAMESPACE', 'App\\Models'),
];
