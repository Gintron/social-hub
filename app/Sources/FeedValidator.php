<?php

declare(strict_types=1);

namespace App\Sources;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Validates a decoded Social Feed payload against docs/social-feed-v1.schema.json.
 */
final class FeedValidator
{
    private ?object $schema = null;

    public function __construct(private readonly Validator $validator = new Validator) {}

    /**
     * @return list<string> Human readable errors ("/items/0/url: The data must match the 'https?://' pattern"); empty when valid.
     */
    public function errors(mixed $payload): array
    {
        // Opis validates object graphs, not associative arrays.
        $data = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $result = $this->validator->validate($data, $this->schema());

        if ($result->isValid()) {
            return [];
        }

        $formatted = (new ErrorFormatter)->format($result->error());

        $errors = [];
        foreach ($formatted as $path => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = ($path === '/' ? '' : $path).': '.$message;
            }
        }

        return $errors;
    }

    private function schema(): object
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $path = (string) config('hub.feed_schema_path');
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Social Feed schema not readable at {$path}");
        }

        return $this->schema = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }
}
