<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

use ReflectionMethod;

class ResponseCodeAnalyzer
{
    /**
     * Analyze controller method to detect possible HTTP status codes
     *
     * @return array<int, string>
     */
    public function analyze(ReflectionMethod $reflection): array
    {
        $statusCodes = [];

        try {
            $fileName = $reflection->getFileName();
            if (! $fileName) {
                return $statusCodes;
            }

            $content = file_get_contents($fileName);
            if ($content === false) {
                return $statusCodes;
            }

            $startLine = $reflection->getStartLine();
            $endLine = $reflection->getEndLine();

            if ($startLine === false || $endLine === false) {
                return $statusCodes;
            }

            $lines = explode("\n", $content);
            $methodBody = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

            // Detect status codes from response()->json(..., 201)
            if (preg_match_all('/response\(\)->json\([^,]+,\s*(\d{3})\)/', $methodBody, $matches)) {
                foreach ($matches[1] as $code) {
                    $statusCodes[(int) $code] = $this->getStatusCodeDescription((int) $code);
                }
            }

            // Detect abort(404) or abort(403)
            if (preg_match_all('/abort\((\d{3})\)/', $methodBody, $matches)) {
                foreach ($matches[1] as $code) {
                    $statusCodes[(int) $code] = $this->getStatusCodeDescription((int) $code);
                }
            }

            // Detect return response()->status(201)
            if (preg_match_all('/response\(\)->status\((\d{3})\)/', $methodBody, $matches)) {
                foreach ($matches[1] as $code) {
                    $statusCodes[(int) $code] = $this->getStatusCodeDescription((int) $code);
                }
            }

            // Detect Resource responses (typically 200, 201, 204)
            if (preg_match('/Resource::(make|collection)/', $methodBody)) {
                $statusCodes[200] = 'OK';
                // POST typically returns 201
                if (preg_match('/function\s+\w+\s*\([^)]*Request/', $methodBody)) {
                    $statusCodes[201] = 'Created';
                }
            }

            // Default codes based on HTTP method
            $methodName = mb_strtolower($reflection->getName());
            if (str_contains($methodName, 'store') || str_contains($methodName, 'create')) {
                $statusCodes[201] = 'Created';
            } elseif (str_contains($methodName, 'update')) {
                $statusCodes[200] = 'OK';
            } elseif (str_contains($methodName, 'destroy') || str_contains($methodName, 'delete')) {
                $statusCodes[204] = 'No Content';
            } else {
                $statusCodes[200] = 'OK';
            }

            // Always include common error codes
            $statusCodes[400] = 'Bad Request';
            $statusCodes[401] = 'Unauthorized';
            $statusCodes[404] = 'Not Found';
            $statusCodes[422] = 'Unprocessable Entity';
            $statusCodes[500] = 'Internal Server Error';
        } catch (\Throwable) {
            // Return default codes on error
            return [200 => 'OK', 400 => 'Bad Request', 404 => 'Not Found', 500 => 'Internal Server Error'];
        }

        return $statusCodes;
    }

    /**
     * Get description for HTTP status code
     */
    protected function getStatusCodeDescription(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => "HTTP {$code}",
        };
    }
}
