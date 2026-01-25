<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

use ReflectionMethod;

class PhpDocAnalyzer
{
    /**
     * Extract PHPDoc information from a method
     *
     * @return array{description: string|null, params: array<string, array{type: string|null, description: string|null}>, return: array{type: string|null, description: string|null}|null}
     */
    public function extractFromMethod(ReflectionMethod $method): array
    {
        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return [
                'description' => null,
                'params' => [],
                'return' => null,
            ];
        }

        return $this->parseDocComment($docComment);
    }

    /**
     * Parse PHPDoc comment
     *
     * @return array{description: string|null, params: array<string, array{type: string|null, description: string|null}>, return: array{type: string|null, description: string|null}|null}
     */
    protected function parseDocComment(string $docComment): array
    {
        $lines = explode("\n", $docComment);
        $description = [];
        $params = [];
        $return = null;
        $inDescription = true;

        foreach ($lines as $line) {
            $line = trim($line);
            // Remove comment markers
            $line = preg_replace('/^\/\*\*|\*\/$|\*\/?/', '', $line);
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Check for @param tag
            if (preg_match('/@param\s+(\S+)\s+\$(\w+)(?:\s+(.+))?/', $line, $matches)) {
                $inDescription = false;
                $type = $matches[1] ?? null;
                $name = $matches[2] ?? null;
                $paramDescription = $matches[3] ?? null;

                if ($name) {
                    $params[$name] = [
                        'type' => $type,
                        'description' => $paramDescription,
                    ];
                }

                continue;
            }

            // Check for @return tag
            if (preg_match('/@return\s+(\S+)(?:\s+(.+))?/', $line, $matches)) {
                $inDescription = false;
                $returnType = $matches[1] ?? null;
                $returnDescription = $matches[2] ?? null;

                $return = [
                    'type' => $returnType,
                    'description' => $returnDescription,
                ];

                continue;
            }

            // Check for @description tag
            if (preg_match('/@description\s+(.+)/', $line, $matches)) {
                $inDescription = false;
                $description = [trim($matches[1])];

                continue;
            }

            // Regular description line
            if ($inDescription && ! preg_match('/^@/', $line)) {
                $description[] = $line;
            }
        }

        // Clean up description
        $descriptionText = ! empty($description) ? implode(' ', $description) : null;
        $descriptionText = $descriptionText ? trim($descriptionText) : null;

        // Check for @deprecated tag
        $deprecated = null;
        if (preg_match('/@deprecated(?:\s+(.+))?/', $docComment, $matches)) {
            $deprecated = [
                'deprecated' => true,
                'message' => trim($matches[1] ?? 'This route is deprecated'),
            ];
        }

        // Check for PHP 8 #[Deprecated] attribute (would need reflection)
        // This is handled in the command when analyzing the method

        return [
            'description' => $descriptionText,
            'params' => $params,
            'return' => $return,
            'deprecated' => $deprecated,
        ];
    }
}
