<?php

declare(strict_types=1);

namespace Prlkhv\GraphQlAiProfiler\Plugin;

use Prlkhv\GraphQlAiProfiler\Model\Config;
use Prlkhv\GraphQlAiProfiler\Model\SpanCollector;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchResolverInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;

class BatchResolverPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly SpanCollector $collector
    ) {
    }

    /**
     * @param callable $proceed
     * @param BatchRequestItemInterface[] $requests
     */
    public function aroundResolve(
        BatchResolverInterface $subject,
        callable $proceed,
        ContextInterface $context,
        Field $field,
        array $requests
    ): BatchResponse {
        if (!$this->config->isActive()) {
            return $proceed($context, $field, $requests);
        }

        $spanId = $this->collector->startSpan(
            'batch.' . $field->getName(),
            [
                'graphql.field' => $field->getName(),
                'graphql.batch.request_count' => (string) count($requests),
                'magento.resolver.class' => get_class($subject),
            ]
        );

        try {
            $result = $proceed($context, $field, $requests);
        } catch (\Throwable $e) {
            $this->collector->endSpan($spanId, $e);
            throw $e;
        }

        $this->collector->endSpan($spanId);

        return $result;
    }
}
