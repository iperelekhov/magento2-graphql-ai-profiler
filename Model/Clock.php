<?php

declare(strict_types=1);

namespace Prlkhv\GraphQlAiProfiler\Model;

class Clock
{
    private ?int $epochBaseNs = null;

    private ?int $monoBase = null;

    public function nowNs(): int
    {
        if ($this->epochBaseNs === null) {
            $this->epochBaseNs = (int) round(microtime(true) * 1e9);
            $this->monoBase = hrtime(true);
        }

        return $this->epochBaseNs + (hrtime(true) - $this->monoBase);
    }
}
