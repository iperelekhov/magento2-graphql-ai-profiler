<?php

declare(strict_types=1);

namespace Prlkhv\GraphQlAiProfiler\Plugin;

use Prlkhv\GraphQlAiProfiler\Model\Config;
use Prlkhv\GraphQlAiProfiler\Model\SpanCollector;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

readonly class ResolverPlugin
{
    public function __construct(
        private Config        $config,
        private SpanCollector $collector
    ) {
    }

    /**
     * @param callable $proceed
     * @param mixed $context
     * @param array<string, mixed>|null $value
     * @param array<string, mixed>|null $args
     * @return mixed
     */
    public function aroundResolve(
        ResolverInterface $subject,
        callable $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$this->config->isActive()) {
            return $proceed($field, $context, $info, $value, $args);
        }

        $spanId = $this->collector->startSpan(
            $info->parentType->name . '.' . $info->fieldName,
            [
                'graphql.field' => $info->fieldName,
                'graphql.parent_type' => (string) $info->parentType->name,
                'magento.resolver.class' => get_class($subject),
            ]
        );

        try {
            $result = $proceed($field, $context, $info, $value, $args);
        } catch (\Throwable $e) {
            $this->collector->endSpan($spanId, $e);
            throw $e;
        }

        if ($result instanceof SyncPromise) {
            $collector = $this->collector;

            return $result->then(
                static function ($resolved) use ($collector, $spanId) {
                    $collector->endSpan($spanId);

                    return $resolved;
                },
                static function ($reason) use ($collector, $spanId) {
                    $collector->endSpan($spanId, $reason instanceof \Throwable ? $reason : null);
                    throw $reason;
                }
            );
        }

        $this->collector->endSpan($spanId);

        return $result;
    }
}
