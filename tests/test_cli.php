<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$lockBase = tempnam(__DIR__, 'cli-check-');
register_shutdown_function(function () use ($lockBase): void {
    if (is_file($lockBase . '.lock')) unlink($lockBase . '.lock');
    unlink($lockBase);
});
function runCli(string $root, string $step, int $failures): array {
    global $lockBase;
    $code = 'namespace KintB24 { class Config {function kintBaseUrl(){return "";} function kintUser(){return "";} function kintPass(){return "";} function b24WebhookUrl(){return "";} function dbPath(){return '.var_export($lockBase,true).';}} class KintClient{} class B24Client{} class Store {function error($m){}} class SyncEngine {function syncBookings():int{return '. $failures .';}} } namespace { $argv=["sync.php","--only=bookings"]; require '.var_export($root.'/bin/sync.php',true).';}';
    $p = proc_open([PHP_BINARY, '-r', $code, '--', '--only='.$step], [1=>['pipe','w'],2=>['pipe','w']], $pipes, $root);
    $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p),$out];
}
[$rc,$out]=runCli($root,'bookings',1);
if ($rc===0 || str_contains($out,'bookings OK')) {fwrite(STDERR,"FAIL: partial failure reported success\n");exit(1);}
[$rc,$out]=runCli($root,'bookings',0);
if ($rc!==0 || !str_contains($out,'bookings OK')) {fwrite(STDERR,"FAIL: healthy step failed: $out\n");exit(1);}
$lock = fopen($lockBase . '.lock', 'c');
flock($lock, LOCK_EX | LOCK_NB);
[$rc,$out]=runCli($root,'bookings',0);
if ($rc===0 || !str_contains($out,'Cannot acquire sync lock')) {fwrite(STDERR,"FAIL: concurrent tick not rejected\n");exit(1);}
fclose($lock);
echo "CLI failure, success and overlap checks passed\n";
$code = 'namespace KintB24 { class Config {function b24WebhookUrl(){return "";}} class B24Client {function call($method,$params){if(str_ends_with($method,".add"))throw new \\RuntimeException("simulated field creation failure");return [];}} } namespace { require '.var_export($root.'/bin/setup-b24.php',true).';}';
$p=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
fclose($pipes[1]);fclose($pipes[2]);
$rc=proc_close($p);
if ($rc===0 || !str_contains($out,'simulated field creation failure')) {fwrite(STDERR,"FAIL: setup failure concealed\n");exit(1);}
echo "Setup partial-failure check passed\n";
