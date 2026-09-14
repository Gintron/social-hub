<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Meta\GraphClient;

/**
 * Reads Graph insights metrics by name and maps them onto the hub's columns.
 *
 * Meta retires and renames metrics between versions (impressions became views in 2025), and one
 * unknown name fails the whole request. So the names live in config/meta.php, and a failed batch is
 * retried one metric at a time: a post still reports what it can.
 */
final class GraphInsights
{
    public function __construct(private readonly GraphClient $graph) {}

    /**
     * @param  array<string, string>  $metrics  Graph metric => hub column
     * @param  array<string, mixed>  $raw  Filled with the responses, for the record.
     * @return array<string, int> hub column => value
     */
    public function read(string $path, array $metrics, string $token, array &$raw): array
    {
        if ($metrics === []) {
            return [];
        }

        try {
            $response = $this->graph->get($path, ['metric' => implode(',', array_keys($metrics))], $token, 'metrics.insights');
            $raw[$path] = $response;

            return $this->map($response, $metrics);
        } catch (PermanentPublishException $e) {
            // Only an unknown metric is worth asking around. A dead token or a rate limit would fail
            // every single-metric call too, and must reach the caller rather than read as "no data".
            if (count($metrics) === 1) {
                $raw[$path.':'.array_key_first($metrics)] = ['error' => $e->getMessage()];

                return [];
            }
        }

        $values = [];

        foreach ($metrics as $metric => $column) {
            $values += $this->read($path, [$metric => $column], $token, $raw);
        }

        return $values;
    }

    /**
     * Plain fields of an object (`reactions.summary(total_count)` and the like), for the numbers
     * Graph reports outside insights.
     *
     * @return array<string, mixed>
     */
    public function fields(string $path, string $fields, string $token, array &$raw): array
    {
        try {
            $response = $this->graph->get($path, ['fields' => $fields], $token, 'metrics.fields');
            $raw[$path.'?fields'] = $response;

            return $response;
        } catch (PermanentPublishException $e) {
            $raw[$path.'?fields'] = ['error' => $e->getMessage()];

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, string>  $metrics
     * @return array<string, int>
     */
    private function map(array $response, array $metrics): array
    {
        $values = [];

        foreach ((array) ($response['data'] ?? []) as $row) {
            $column = $metrics[$row['name'] ?? ''] ?? null;
            $value = $row['total_value']['value'] ?? ($row['values'][0]['value'] ?? null);

            // Some metrics come back as a breakdown object (reactions by type); only plain counts map.
            if ($column !== null && is_numeric($value)) {
                $values[$column] = (int) $value;
            }
        }

        return $values;
    }
}
