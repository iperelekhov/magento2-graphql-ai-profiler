<?php

declare(strict_types=1);

namespace Prlkhv\GraphQlAiProfiler\Plugin;

use Prlkhv\GraphQlAiProfiler\Model\Config;
use Prlkhv\GraphQlAiProfiler\Model\Otlp\Serializer;
use Prlkhv\GraphQlAiProfiler\Model\SpanCollector;
use Magento\Framework\GraphQl\Query\QueryProcessor;

readonly class ResponseInjectorPlugin
{
    public function __construct(
        private Config        $config,
        private SpanCollector $collector,
        private Serializer    $serializer
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterProcess(QueryProcessor $subject, array $result): array
    {
        if (!$this->config->isActive()) {
            return $result;
        }

        $traceId = $this->collector->getTraceId();
        $spans = $this->collector->getSpans();

        $result['extensions']['profiling'] = $this->config->isAiFormat()
            ? $this->serializer->toOtlpCompact($traceId, $spans, $this->config->isSqlStatementRequested())
            : $this->serializer->toOtlp($traceId, $spans);

        return $result;
    }
}
