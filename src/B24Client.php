<?php

declare(strict_types=1);

namespace KintB24;

/**
 * Bitrix24 REST client via incoming webhook.
 *
 * - call($method, $params) — single REST call
 * - batch($commands)       — up to 50 commands in one request
 * Idempotent calls retry 3× on network errors / 5xx; .add calls never retry.
 */
class B24Client
{
    private const MAX_RETRIES = 3;
    private const TIMEOUT_SEC = 30;
    private const BATCH_LIMIT = 50;

    private string $webhookUrl;

    public function __construct(string $webhookUrl)
    {
        $this->webhookUrl = rtrim($webhookUrl, '/');
    }

    /**
     * Single REST call. Returns result payload.
     *
     * @throws \RuntimeException on HTTP/network errors
     * @throws B24Exception      on API-level errors
     */
    public function call(string $method, array $params = []): mixed
    {
        $url = "{$this->webhookUrl}/{$method}/";
        $creates = str_ends_with($method, '.add');
        $result = $this->request($url, $params, true, !$creates, $method);

        if (in_array($method, ['crm.deal.add', 'crm.contact.add'], true)) {
            $id = is_array($result) ? ($result['ID'] ?? null) : $result;
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int)$id <= 0) {
                throw new \RuntimeException(
                    "B24 ambiguous outcome for non-idempotent {$method}: response has no valid ID"
                );
            }
        }

        return $result;
    }

    /**
     * Batch up to BATCH_LIMIT commands.
     *
     * $commands = ['alias' => ['method' => 'crm.deal.add', 'params' => [...]], ...]
     *
     * Returns ['result' => [...], 'result_error' => [...], 'result_total' => [...]]
     */
    public function batch(array $commands): array
    {
        if (count($commands) > self::BATCH_LIMIT) {
            throw new \InvalidArgumentException(
                'B24 batch limit is ' . self::BATCH_LIMIT . ', got ' . count($commands)
            );
        }

        $cmd = [];
        foreach ($commands as $alias => $spec) {
            $cmd["cmd[{$alias}]"] = $spec['method'] . '?' . http_build_query($spec['params'] ?? []);
        }

        $url = "{$this->webhookUrl}/batch/";
        $retry = true;
        foreach ($commands as $spec) {
            if (str_ends_with($spec['method'], '.add')) {
                $retry = false;
                break;
            }
        }

        return $this->request($url, $cmd, false, $retry, 'batch');
    }

    /**
     * Chunk $commands into batches of ≤50 and collect all results.
     * Returns flat array keyed by original alias.
     */
    public function batchAll(array $commands): array
    {
        $chunks  = array_chunk($commands, self::BATCH_LIMIT, true);
        $results = [];

        foreach ($chunks as $chunk) {
            $resp = $this->batch($chunk);
            if (!empty($resp['result_error'])) {
                throw new B24Exception('B24 batch failed aliases: ' . implode(', ', array_keys($resp['result_error'])));
            }
            foreach ($resp['result'] ?? [] as $alias => $val) {
                $results[$alias] = $val;
            }
        }

        return $results;
    }

    // ── internal ──────────────────────────────────────────────────────────────

    /** @param bool $json  true → send JSON body; false → form-encoded (for batch) */
    private function request(
        string $url,
        array $body,
        bool $json = true,
        bool $retry = true,
        string $operation = 'request'
    ): mixed
    {
        $attempt = 0;
        $lastEx  = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                return $this->execute($url, $body, $json);
            } catch (B24Exception $e) {
                throw $e; // business error — no retry
            } catch (\RuntimeException $e) {
                if (!$retry) {
                    throw new \RuntimeException(
                        "B24 ambiguous outcome for non-idempotent {$operation}: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
                $lastEx = $e;
                $attempt++;
                if ($attempt < self::MAX_RETRIES) {
                    sleep(1 << ($attempt - 1));
                }
            }
        }

        throw new \RuntimeException(
            "B24 request failed after " . self::MAX_RETRIES . " attempts: " . $lastEx->getMessage(),
            0,
            $lastEx
        );
    }

    private function execute(string $url, array $body, bool $json): mixed
    {
        $ch = curl_init($url);

        if ($json) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } else {
            // batch uses form-encoded cmd[] params
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errStr = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("curl error #{$errno}: {$errStr}");
        }

        if ($status >= 500) {
            throw new \RuntimeException("B24 HTTP {$status}");
        }

        if ($status !== 200) {
            throw new B24Exception("B24 HTTP {$status}", 0);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("B24 non-JSON response: " . substr($raw, 0, 200));
        }

        if (isset($data['error'])) {
            throw new B24Exception(
                ($data['error_description'] ?? $data['error']),
                0
            );
        }

        return $data['result'] ?? $data;
    }
}
