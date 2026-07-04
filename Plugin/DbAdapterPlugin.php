<?php

declare(strict_types=1);

namespace Prlkhv\GraphQlAiProfiler\Plugin;

use Prlkhv\GraphQlAiProfiler\Model\Clock;
use Prlkhv\GraphQlAiProfiler\Model\Config;
use Prlkhv\GraphQlAiProfiler\Model\SpanCollector;
use Magento\Framework\DB\Adapter\Pdo\Mysql;

class DbAdapterPlugin
{
    private bool $inside = false;

    public function __construct(
        private readonly Config $config,
        private readonly SpanCollector $collector,
        private readonly Clock $clock
    ) {
    }

    /**
     * @param callable $proceed
     * @param string|\Magento\Framework\DB\Select $sql
     * @param mixed $bind
     * @return mixed
     */
    public function aroundQuery(Mysql $subject, callable $proceed, $sql, $bind = [])
    {
        return $this->instrument($proceed, $sql, $bind);
    }

    /**
     * @param callable $proceed
     * @param string|\Magento\Framework\DB\Select $sql
     * @param mixed $bind
     * @return mixed
     */
    public function aroundMultiQuery(Mysql $subject, callable $proceed, $sql, $bind = [])
    {
        return $this->instrument($proceed, $sql, $bind);
    }

    /**
     * @param callable $proceed
     * @param string|\Magento\Framework\DB\Select $sql
     * @param mixed $bind
     * @return mixed
     */
    private function instrument(callable $proceed, $sql, $bind)
    {
        // Reentrancy guard MUST wrap isActive() too: resolving config lazily
        // issues its own queries (e.g. cache-state lookups), which would re-enter
        // this plugin and recurse forever. Set the flag before touching config,
        // so any query fired while we are deciding/recording passes straight through.
        if ($this->inside) {
            return $proceed($sql, $bind);
        }

        $this->inside = true;
        try {
            if (!$this->config->isActive()) {
                return $proceed($sql, $bind);
            }

            $start = $this->clock->nowNs();
            try {
                return $proceed($sql, $bind);
            } finally {
                $end = $this->clock->nowNs();
                $this->collector->addLeafSpan(
                    'db.query',
                    $start,
                    $end,
                    [
                        'db.system' => 'mysql',
                        'db.statement' => $this->truncate((string) $sql),
                        'db.statement.hash' => hash('xxh128', $this->normalize((string) $sql)),
                    ],
                    $this->collector->currentSpanId()
                );
            }
        } finally {
            $this->inside = false;
        }
    }

    private function truncate(string $sql): string
    {
        $max = $this->config->getSqlStatementMaxLength();

        return mb_strlen($sql) > $max ? mb_substr($sql, 0, $max) . '…' : $sql;
    }

    private function normalize(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
    }
}
