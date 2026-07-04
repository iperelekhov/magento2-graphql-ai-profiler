<?php

declare(strict_types=1);

namespace Prlkhv\GraphQlAiProfiler\Model\Otlp;

class Serializer
{
    private const string SERVICE_NAME = 'magento-graphql';
    private const string SCOPE_NAME = 'Prlkhv.GraphQlAiProfiler';
    private const int SPAN_KIND_INTERNAL = 1;

    private const int AI_SPAN_ID_LENGTH = 6;

    /**
     * AI-format legend: short attribute key => original attribute name.
     * See AI_FORMAT_MAPPING.md for the full, stable contract.
     *
     * @var array<string, string>
     */
    private const array AI_ATTR_MAP = [
        'graphql.field' => 'gf',
        'graphql.parent_type' => 'gp',
        'magento.resolver.class' => 'rc',
        'db.system' => 'ds',
        'db.statement' => 'dq',
        'db.statement.hash' => 'dh',
        'error.message' => 'em',
    ];

    /**
     * @param array<int, array<string, mixed>> $spans
     * @return array<string, mixed>
     */
    public function toOtlp(string $traceId, array $spans): array
    {
        $otlpSpans = [];
        foreach ($spans as $span) {
            $otlpSpans[] = [
                'traceId' => $traceId,
                'spanId' => $span['spanId'],
                'parentSpanId' => $span['parentSpanId'] ?? '',
                'name' => $span['name'],
                'kind' => self::SPAN_KIND_INTERNAL,
                'startTimeUnixNano' => (string) $span['startNs'],
                'endTimeUnixNano' => (string) ($span['endNs'] ?? $span['startNs']),
                'attributes' => $this->encodeAttributes($span['attributes'] ?? []),
                'status' => ['code' => $span['status'] ?? 0],
            ];
        }

        return [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => $this->encodeAttributes(['service.name' => self::SERVICE_NAME]),
                ],
                'scopeSpans' => [[
                    'scope' => ['name' => self::SCOPE_NAME],
                    'spans' => $otlpSpans,
                ]],
            ]],
        ];
    }

    /**
     * Compact "AI" format: a flattened, single-letter-keyed span list meant to be
     * cheap for an LLM to read. The OTLP envelope is dropped; each span is one flat
     * object. IDs are truncated, timestamps are microsecond offsets from trace start,
     * and no legend rides in the payload. See AI_FORMAT_MAPPING.md for the contract.
     *
     * @param array<int, array<string, mixed>> $spans
     * @return array<string, mixed>
     */
    public function toOtlpCompact(string $traceId, array $spans, bool $includeSql = false): array
    {
        $baseNs = $this->earliestStartNs($spans);

        $compactSpans = [];
        foreach ($spans as $span) {
            $startNs = (int) $span['startNs'];
            $endNs = (int) ($span['endNs'] ?? $span['startNs']);
            $row = [
                'i' => $this->shortenId((string) $span['spanId']),
                'p' => $this->shortenId((string) ($span['parentSpanId'] ?? '')),
                'n' => $span['name'],
                's' => (int) round(($startNs - $baseNs) / 1000),
                'd' => (int) round(($endNs - $startNs) / 1000),
            ];
            if (($span['status'] ?? 0) !== 0) {
                $row['x'] = $span['status'];
            }
            $attributes = $this->compactAttributes($span['attributes'] ?? [], $includeSql);
            if ($attributes !== []) {
                $row['a'] = $attributes;
            }
            $compactSpans[] = $row;
        }

        return [
            't' => $this->shortenId($traceId),
            'sv' => self::SERVICE_NAME,
            'sp' => $compactSpans,
        ];
    }

    private function shortenId(string $id): string
    {
        return $id === '' ? '' : substr($id, 0, self::AI_SPAN_ID_LENGTH);
    }

    private function earliestStartNs(array $spans): int
    {
        $starts = array_map(static fn (array $span): int => (int) $span['startNs'], $spans);

        return $starts === [] ? 0 : min($starts);
    }

    /**
     * @param array<string, scalar> $attributes
     * @return array<string, scalar>
     */
    private function compactAttributes(array $attributes, bool $includeSql): array
    {
        $compact = [];
        foreach ($attributes as $key => $value) {
            if ($key === 'db.statement' && !$includeSql) {
                continue;
            }
            $compact[self::AI_ATTR_MAP[$key] ?? $key] = $value;
        }

        return $compact;
    }

    /**
     * @param array<string, scalar> $attributes
     * @return array<int, array<string, mixed>>
     */
    private function encodeAttributes(array $attributes): array
    {
        $encoded = [];
        foreach ($attributes as $key => $value) {
            $encoded[] = [
                'key' => (string) $key,
                'value' => $this->encodeValue($value),
            ];
        }

        return $encoded;
    }

    /**
     * @param scalar $value
     * @return array<string, mixed>
     */
    private function encodeValue(mixed $value): array
    {
        if (is_int($value)) {
            return ['intValue' => (string) $value];
        }
        if (is_bool($value)) {
            return ['boolValue' => $value];
        }
        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        return ['stringValue' => (string) $value];
    }
}
