<?php

declare(strict_types=1);

namespace Prlkhv\GraphQlAiProfiler\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    private const XML_PATH_ENABLED = 'dev/graphql_profiler/enabled';
    private const XML_PATH_SECRET = 'dev/graphql_profiler/secret';
    private const XML_PATH_MODE_ALLOWLIST = 'dev/graphql_profiler/mode_allowlist';
    private const XML_PATH_SQL_MAX_LENGTH = 'dev/graphql_profiler/sql_statement_max_length';

    private const ACTIVATION_HEADER = 'X-GraphQl-Profiler';
    private const FORMAT_HEADER = 'X-GraphQl-Profiler-Format';
    private const FORMAT_AI = 'ai';
    private const SQL_HEADER = 'X-GraphQl-Profiler-Sql';

    private ?bool $active = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly State $appState,
        private readonly RequestInterface $request,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isActive(): bool
    {
        if ($this->active === null) {
            $this->active = $this->isEnabled()
                && $this->isModeAllowed()
                && $this->currentSecretOk();
        }

        return $this->active;
    }

    public function currentSecretOk(): bool
    {
        $stored = (string) $this->scopeConfig->getValue(self::XML_PATH_SECRET);
        if ($stored === '') {
            return false;
        }
        $expected = $this->encryptor->decrypt($stored);
        if ($expected === '') {
            return false;
        }

        $provided = $this->getActivationHeader();
        if ($provided === null) {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    public function isAiFormat(): bool
    {
        $value = $this->request->getHeader(self::FORMAT_HEADER);
        if ($value === false || $value === '') {
            return false;
        }

        return strcasecmp((string) $value, self::FORMAT_AI) === 0;
    }

    public function isSqlStatementRequested(): bool
    {
        $value = $this->request->getHeader(self::SQL_HEADER);

        return $value !== false && $value !== '' && $value !== '0';
    }

    public function getSqlStatementMaxLength(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_SQL_MAX_LENGTH);

        return $value > 0 ? $value : 2000;
    }

    private function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    private function isModeAllowed(): bool
    {
        $allowed = $this->splitList((string) $this->scopeConfig->getValue(self::XML_PATH_MODE_ALLOWLIST));
        if ($allowed === []) {
            $allowed = [State::MODE_DEVELOPER];
        }

        try {
            $mode = $this->appState->getMode();
        } catch (\Throwable $e) {
            return false;
        }

        return in_array($mode, $allowed, true);
    }

    private function getActivationHeader(): ?string
    {
        $value = $this->request->getHeader(self::ACTIVATION_HEADER);

        return $value === false || $value === '' ? null : (string) $value;
    }

    /**
     * @return string[]
     */
    private function splitList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
    }
}
