<?php

declare(strict_types=1);

namespace KintB24;

/**
 * Reads .env file and exposes typed config values.
 * Fails fast if any required key is missing or empty.
 */
final class Config
{
    private array $values;

    private const REQUIRED = [
        'KINT_BASE_URL',
        'KINT_USER',
        'KINT_PASS',
        'B24_WEBHOOK_URL',
        'DB_PATH',
    ];

    public function __construct(string $envFile = null)
    {
        $envFile = $envFile ?? dirname(__DIR__) . '/.env';

        if (!is_file($envFile)) {
            throw new \RuntimeException("Config: .env not found at {$envFile}");
        }

        $this->values = [];

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            [$key, $val] = array_pad(explode('=', $line, 2), 2, '');
            $this->values[trim($key)] = trim($val);
        }

        // Merge real env vars (override .env)
        foreach (self::REQUIRED as $key) {
            if (($env = getenv($key)) !== false) {
                $this->values[$key] = $env;
            }
        }

        $missing = [];
        foreach (self::REQUIRED as $key) {
            if (empty($this->values[$key])) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException('Config: missing required keys: ' . implode(', ', $missing));
        }
    }

    public function get(string $key): string
    {
        if (!isset($this->values[$key])) {
            throw new \RuntimeException("Config: unknown key '{$key}'");
        }
        return $this->values[$key];
    }

    public function kintBaseUrl(): string  { return rtrim($this->get('KINT_BASE_URL'), '/'); }
    public function kintUser(): string     { return $this->get('KINT_USER'); }
    public function kintPass(): string     { return $this->get('KINT_PASS'); }
    public function b24WebhookUrl(): string { return rtrim($this->get('B24_WEBHOOK_URL'), '/'); }
    public function dbPath(): string       { return $this->get('DB_PATH'); }

    /** Optional int key with default */
    public function int(string $key, int $default = 0): int
    {
        return isset($this->values[$key]) ? (int)$this->values[$key] : $default;
    }
}
