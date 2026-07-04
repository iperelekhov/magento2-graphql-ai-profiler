<?php

declare(strict_types=1);

namespace Prlkhv\GraphQlAiProfiler\Model;

class SpanCollector
{
    private const STATUS_UNSET = 0;
    private const STATUS_ERROR = 2;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $spans = [];

    /**
     * @var string[]
     */
    private array $stack = [];

    private ?string $traceId = null;

    public function __construct(
        private readonly Clock $clock
    ) {
    }

    /**
     * @param array<string, string> $attributes
     */
    public function startSpan(string $name, array $attributes, ?string $parentSpanId = null): string
    {
        $spanId = $this->generateSpanId();
        $this->spans[$spanId] = [
            'spanId' => $spanId,
            'parentSpanId' => $parentSpanId ?? $this->currentSpanId(),
            'name' => $name,
            'startNs' => $this->clock->nowNs(),
            'endNs' => null,
            'attributes' => $attributes,
            'status' => self::STATUS_UNSET,
        ];
        $this->stack[] = $spanId;

        return $spanId;
    }

    public function endSpan(string $spanId, ?\Throwable $error = null): void
    {
        if (!isset($this->spans[$spanId])) {
            return;
        }

        $this->spans[$spanId]['endNs'] = $this->clock->nowNs();
        if ($error !== null) {
            $this->spans[$spanId]['status'] = self::STATUS_ERROR;
            $this->spans[$spanId]['attributes']['error.message'] = $error->getMessage();
        }

        // Pop the matching frame, not blindly the top: deferred completion may close spans out of order.
        $index = array_search($spanId, $this->stack, true);
        if ($index !== false) {
            unset($this->stack[$index]);
            $this->stack = array_values($this->stack);
        }
    }

    public function currentSpanId(): ?string
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    /**
     * @param array<string, string> $attributes
     */
    public function addLeafSpan(string $name, int $startNs, int $endNs, array $attributes, ?string $parentSpanId): void
    {
        $spanId = $this->generateSpanId();
        $this->spans[$spanId] = [
            'spanId' => $spanId,
            'parentSpanId' => $parentSpanId,
            'name' => $name,
            'startNs' => $startNs,
            'endNs' => $endNs,
            'attributes' => $attributes,
            'status' => self::STATUS_UNSET,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSpans(): array
    {
        return array_values($this->spans);
    }

    public function getTraceId(): string
    {
        if ($this->traceId === null) {
            $this->traceId = bin2hex(random_bytes(16));
        }

        return $this->traceId;
    }

    private function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
