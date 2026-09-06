#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace KintB24;

final class CurlStub
{
    public static array $responses = [];
    public static array $requests = [];
    public static array $sleeps = [];

    public static function reset(array $responses): void
    {
        self::$responses = $responses;
        self::$requests = [];
        self::$sleeps = [];
    }
}

final class CurlStubHandle
{
    public array $options = [];
    public array $response = [];

    public function __construct(public string $url) {}
}

function curl_init(string $url): CurlStubHandle
{
    $handle = new CurlStubHandle($url);
    CurlStub::$requests[] = $handle;
    return $handle;
}

function curl_setopt(CurlStubHandle $handle, int $option, mixed $value): bool
{
    $handle->options[$option] = $value;
    return true;
}

function curl_setopt_array(CurlStubHandle $handle, array $options): bool
{
    $handle->options += $options;
    return true;
}

function curl_exec(CurlStubHandle $handle): string|false
{
    $handle->response = array_shift(CurlStub::$responses) ?? [];
    return $handle->response['raw'] ?? false;
}

function curl_errno(CurlStubHandle $handle): int
{
    return $handle->response['errno'] ?? 0;
}

function curl_error(CurlStubHandle $handle): string
{
    return $handle->response['error'] ?? '';
}

function curl_getinfo(CurlStubHandle $handle, int $option): int
{
    return $handle->response['status'] ?? 0;
}

function curl_close(CurlStubHandle $handle): void {}

function sleep(int $seconds): int
{
    CurlStub::$sleeps[] = $seconds;
    return 0;
}

require_once dirname(__DIR__) . '/autoload.php';

$failures = [];

function check(string $name, bool $condition, mixed $detail = null): void
{
    global $failures;
    if ($condition) {
        echo "[PASS] {$name}\n";
        return;
    }

    $failures[] = $name;
    echo "[FAIL] {$name}: " . json_encode($detail, JSON_UNESCAPED_UNICODE) . "\n";
}

function catches(string $class, callable $callback): ?\Throwable
{
    try {
        $callback();
    } catch (\Throwable $e) {
        return $e instanceof $class ? $e : null;
    }
    return null;
}

$client = new B24Client('https://example.test/rest/1/token/');

CurlStub::reset([['status' => 200, 'raw' => '{"result":91}']]);
$result = $client->call('crm.deal.add', ['fields' => ['TITLE' => 'Тест']]);
check('method parameters are the root JSON object', CurlStub::$requests[0]->options[CURLOPT_POSTFIELDS] === '{"fields":{"TITLE":"Тест"}}', CurlStub::$requests[0]->options[CURLOPT_POSTFIELDS]);
check('valid add result is returned', $result === 91, $result);

CurlStub::reset([
    ['status' => 500, 'raw' => 'temporary'],
    ['status' => 200, 'raw' => '{"result":true}'],
]);
check('idempotent update retries after 5xx', $client->call('crm.deal.update', ['id' => 91, 'fields' => ['TITLE' => 'Новый']]) === true);
check('idempotent update made two attempts', count(CurlStub::$requests) === 2, count(CurlStub::$requests));

foreach ([
    'network error' => ['errno' => 28, 'error' => 'timeout', 'status' => 0, 'raw' => false],
    'HTTP 5xx' => ['status' => 503, 'raw' => 'temporary'],
    'invalid JSON' => ['status' => 200, 'raw' => '<html>proxy error</html>'],
] as $label => $response) {
    CurlStub::reset([$response, ['status' => 200, 'raw' => '{"result":92}']]);
    $error = catches(\RuntimeException::class, fn() => $client->call('crm.contact.add', ['fields' => ['NAME' => 'Иван']]));
    check("add does not retry after {$label}", count(CurlStub::$requests) === 1, count(CurlStub::$requests));
    check("add reports ambiguous outcome after {$label}", $error !== null && str_contains($error->getMessage(), 'ambiguous outcome'), $error?->getMessage());
}

CurlStub::reset([
    ['status' => 503, 'raw' => 'temporary'],
    ['status' => 200, 'raw' => '{"result":{"result":{"create":93},"result_error":[]}}'],
]);
$error = catches(\RuntimeException::class, fn() => $client->batch([
    'create' => ['method' => 'crm.deal.add', 'params' => ['fields' => ['TITLE' => 'Тест']]],
]));
check('batch containing add is not retried', $error !== null && count(CurlStub::$requests) === 1, ['message' => $error?->getMessage(), 'attempts' => count(CurlStub::$requests)]);

CurlStub::reset([['status' => 200, 'raw' => '{"result":{"unexpected":"shape"}}']]);
$error = catches(\RuntimeException::class, fn() => $client->call('crm.deal.add', ['fields' => ['TITLE' => 'Тест']]));
check('entity create rejects a result without a positive ID', $error !== null && str_contains($error->getMessage(), 'ambiguous outcome'), $error?->getMessage());

CurlStub::reset([['status' => 200, 'raw' => '{"result":true}']]);
check('relation add may return boolean success', $client->call('crm.deal.contact.add', ['id' => 91, 'fields' => ['CONTACT_ID' => 12]]) === true);

CurlStub::reset([['status' => 200, 'raw' => '{"error":"INVALID_ARG","error_description":"bad fields"}']]);
$error = catches(B24Exception::class, fn() => $client->call('crm.deal.update', ['id' => 91]));
check('API errors are not retried', $error !== null && count(CurlStub::$requests) === 1, ['message' => $error?->getMessage(), 'attempts' => count(CurlStub::$requests)]);

CurlStub::reset([['status'=>200,'raw'=>'{"result":{"result":{},"result_error":{"bad":{"error":"INVALID_ARG"}}}}']]);
$error = catches(B24Exception::class, fn() => $client->batchAll(['bad'=>['method'=>'crm.deal.update','params'=>['id'=>91]]]));
check('batchAll exposes failed aliases', $error !== null && str_contains($error->getMessage(), 'bad'));

if ($failures !== []) {
    echo "\nFAILED: " . implode(', ', $failures) . "\n";
    exit(1);
}

echo "\nAll B24 transport tests passed.\n";
