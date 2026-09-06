<?php

declare(strict_types=1);

namespace KintB24;

/**
 * КИНТ 1С-Санаторий HTTP API client.
 *
 * All methods return unwrapped Result payload.
 * Throws KintException on Success=false or HTTP errors.
 * Retries 3× with exponential backoff on network errors and 5xx.
 */
class KintClient
{
    private const MAX_RETRIES = 3;
    private const TIMEOUT_SEC = 30;

    private string $baseUrl;
    private string $authHeader;

    public function __construct(string $baseUrl, string $user, string $pass)
    {
        $this->baseUrl    = rtrim($baseUrl, '/');
        $this->authHeader = 'Authorization: Basic ' . base64_encode("{$user}:{$pass}");
    }

    /**
     * GET request. Returns unwrapped Result.
     *
     * @param string $method   e.g. "GetBookingList"
     * @param array  $params   query params
     */
    public function get(string $method, array $params = []): mixed
    {
        $url = $this->buildUrl($method, $params);
        return $this->request($url);
    }

    /**
     * Paginated GET — iterates all pages, returns merged array of items.
     *
     * @param string $method
     * @param array  $params
     * @param string $itemsKey  key inside Result that holds the items array
     * @param int    $pageSize
     */
    public function getPaged(
        string $method,
        array $params = [],
        string $itemsKey = '',
        int $pageSize = 100
    ): array {
        if ($pageSize < 1) {
            throw new \InvalidArgumentException('Page size must be positive');
        }
        $effectiveMethod = $method === 'GetData' ? ($params['Method'] ?? '') : $method;
        $paginated = in_array($effectiveMethod, ['GetCatalog', 'GetAvailableRooms'], true);
        $all  = [];
        $page = 1;
        $previousPage = null;

        do {
            $p       = $paginated ? array_merge($params, ['CountOnPage' => $pageSize, 'PageNumber' => $page]) : $params;
            $result  = $this->get($method, $p);
            $items   = $itemsKey !== '' && is_array($result) ? ($result[$itemsKey] ?? null) : $result;
            if (!is_array($items) || !array_is_list($items)) {
                throw new \RuntimeException('KINT list response has an unexpected shape');
            }
            $fingerprint = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
            if ($items !== [] && $fingerprint === $previousPage) {
                throw new \RuntimeException('KINT pagination repeated a page');
            }
            $previousPage = $fingerprint;

            if (!is_array($items) || $items === []) {
                break;
            }

            $all  = array_merge($all, $items);
            $page++;

            // Stop if fewer items than page size — last page
        } while ($paginated && count($items) >= $pageSize);

        return $all;
    }

    // ── internal ──────────────────────────────────────────────────────────────

    private function buildUrl(string $method, array $params = []): string
    {
        return KintReadOnlyPolicy::url($this->baseUrl . '/hs/KintAPI.hs', $method, $params);
    }

    /** Execute curl with retry. Returns unwrapped Result. */
    private function request(string $url): mixed
    {
        $attempt = 0;
        $lastEx  = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                return $this->execute($url);
            } catch (KintException $e) {
                // КИНТ business error — do not retry
                throw $e;
            } catch (\RuntimeException $e) {
                $lastEx = $e;
                $attempt++;
                if ($attempt < self::MAX_RETRIES) {
                    // exponential backoff: 1s, 2s, 4s
                    sleep(1 << ($attempt - 1));
                }
            }
        }

        throw new \RuntimeException(
            "КИНТ request failed after " . self::MAX_RETRIES . " attempts: " . $lastEx->getMessage(),
            0,
            $lastEx
        );
    }

    private function execute(string $url): mixed
    {
        $ch = curl_init($url);

        $headers = [$this->authHeader, 'Accept: application/json'];

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HTTPGET        => true,
            CURLOPT_FOLLOWLOCATION => false,
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

        // Retry on 5xx
        if ($status >= 500) {
            throw new \RuntimeException("КИНТ HTTP {$status} at {$url}");
        }

        if ($status !== 200) {
            throw new KintException("КИНТ HTTP {$status} at {$url}", 0);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("КИНТ non-JSON response: " . substr($raw, 0, 200));
        }

        if (($data['Success'] ?? true) === false) {
            $err  = $data['Result']['Error']     ?? 'unknown error';
            $code = $data['Result']['КодОшибки'] ?? 0;
            throw new KintException("КИНТ error [{$code}]: {$err}", (int)$code);
        }

        return $data['Result'] ?? $data;
    }
}
