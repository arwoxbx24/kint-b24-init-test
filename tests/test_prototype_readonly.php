<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

function prototypeClientSource(string $file, string $namespace): string
{
    $source = file_get_contents($file);
    $start = strpos($source, 'class KintApiClient');
    if ($start === false) {
        throw new RuntimeException("KintApiClient missing in {$file}");
    }
    $brace = strpos($source, '{', $start);
    $depth = 0;
    for ($end = $brace, $length = strlen($source); $end < $length; $end++) {
        if ($source[$end] === '{') $depth++;
        if ($source[$end] === '}' && --$depth === 0) break;
    }
    $class = substr($source, $start, $end - $start + 1);
    $class = str_replace('catch (InvalidArgumentException | LogicException', 'catch (\\InvalidArgumentException | \\LogicException', $class);
    return "namespace {$namespace};\n" . $class;
}

function checkPrototype(string $file, string $namespace): int
{
    $source = file_get_contents($file);
    $GLOBALS['prototype_curl_calls'][$namespace] = [];
    eval("namespace {$namespace};"
        . 'function curl_init(string $url): object { $ch = (object) ["url" => $url]; $GLOBALS["prototype_curl_calls"][__NAMESPACE__][] = ["handle" => $ch, "url" => $url, "options" => []]; return $ch; }'
        . 'function curl_setopt_array(object $ch, array $options): bool { $i = array_key_last($GLOBALS["prototype_curl_calls"][__NAMESPACE__]); $GLOBALS["prototype_curl_calls"][__NAMESPACE__][$i]["options"] = $options; return true; }'
        . 'function curl_exec(object $ch): string { return \'{"Success":true,"Result":[]}\'; }'
        . 'function curl_getinfo(object $ch, int $option): int { return 200; }'
        . 'function curl_error(object $ch): string { return ""; }'
        . 'function curl_close(object $ch): void {}');
    eval(prototypeClientSource($file, $namespace));
    $class = $namespace . '\\KintApiClient';
    $client = new $class('https://example.test/hs/KintAPI.hs', 'fixture', 'fixture');
    $failures = 0;

    if (str_contains($source, '->post(') || str_contains($source, 'CURLOPT_POST')) {
        fwrite(STDERR, "FAIL {$file}: executable POST path remains\n");
        $failures++;
    }
    if (!str_contains($source, "['_error' => 'BLOCKED_KINT_READ_ONLY', '_method' => 'НазначенияИРезультаты']")) {
        fwrite(STDERR, "FAIL {$file}: service assignments are not explicitly blocked\n");
        $failures++;
    }
    if (method_exists($client, 'post')) {
        fwrite(STDERR, "FAIL {$file}: POST transport still exists\n");
        $failures++;
    }
    foreach ([
        ['GetBookingInvoice', []],
        ['PostData', []],
        ['GetData', ['Method' => 'PostData']],
    ] as [$method, $params]) {
        $before = count($GLOBALS['prototype_curl_calls'][$namespace]);
        $result = $client->get($method, $params);
        if (($result['_error'] ?? null) !== 'BLOCKED_KINT_READ_ONLY'
            || count($GLOBALS['prototype_curl_calls'][$namespace]) !== $before) {
            fwrite(STDERR, "FAIL {$file}: {$method} was not blocked before network\n");
            $failures++;
        }
    }

    $result = $client->get('GetCatalog', ['CatalogName' => 'КартаГостя', 'Filter' => ['ID' => 'fixture']]);
    $call = $GLOBALS['prototype_curl_calls'][$namespace][0] ?? [];
    parse_str((string) parse_url($call['url'] ?? '', PHP_URL_QUERY), $query);
    if ($result !== [] || ($call['options'][CURLOPT_HTTPGET] ?? null) !== true
        || ($call['options'][CURLOPT_SSL_VERIFYPEER] ?? null) !== true
        || ($call['options'][CURLOPT_SSL_VERIFYHOST] ?? null) !== 2
        || ($call['options'][CURLOPT_FOLLOWLOCATION] ?? null) !== false
        || json_decode($query['Filter'] ?? '', true) !== ['ID' => 'fixture']) {
        fwrite(STDERR, "FAIL {$file}: safe GET transport contract broken\n");
        $failures++;
    }
    $result = $client->get('GetData', [
        'Method' => 'PaymentStatusByDocument',
        'Document' => ['Идентификатор' => 'fixture'],
    ]);
    $legacyUrl = $GLOBALS['prototype_curl_calls'][$namespace][1]['url'] ?? '';
    if ($result !== [] || !str_contains($legacyUrl, '/PaymentStatusByDocument?') || str_contains($legacyUrl, '/GetData')) {
        fwrite(STDERR, "FAIL {$file}: safe legacy read was not canonicalized\n");
        $failures++;
    }
    return $failures;
}

$failures = checkPrototype(dirname(__DIR__) . '/prototype/index.php', 'PrototypeIndexTest');
$failures += checkPrototype(dirname(__DIR__) . '/prototype/detail.php', 'PrototypeDetailTest');
echo "Prototype read-only failures={$failures}\n";
exit($failures === 0 ? 0 : 1);
