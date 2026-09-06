<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
class PagedFixture extends KintB24\KintClient {
    public int $calls=0;
    public function __construct(public mixed $response) {}
    public function get(string $method,array $params=[]):mixed {
        if (++$this->calls > 3) throw new RuntimeException('fixture guard');
        return $this->response;
    }
}
$fail=0;
foreach (['bad-shape'=>['unexpected'=>'value'],'repeated-page'=>[['ID'=>'same']]] as $name=>$response) {
    $c=new PagedFixture($response);
    try {$c->getPaged('GetCatalog',[], '',1);echo "FAIL $name accepted\n";$fail++;}
    catch (RuntimeException $e) {if($e->getMessage()==='fixture guard'){echo "FAIL pagination did not stop\n";$fail++;}}
}
$c=new PagedFixture([]);
if($c->getPaged('GetCatalog')!==[])$fail++;
$client=new KintB24\KintClient('https://example.invalid','fixture','fixture');
$method=new ReflectionMethod(KintB24\KintClient::class,'buildUrl');
$url=$method->invoke($client,'GetInvoices',['Основание'=>['ID'=>'ref'],'Fields'=>'СуммаДокумента']);
parse_str(parse_url($url, PHP_URL_QUERY),$query);
if(json_decode($query['Основание'],true)!==['ID'=>'ref']){$fail++;echo "FAIL reference not JSON\n";}
echo "KINT pagination/reference failures=$fail\n";
exit($fail ? 1:0);
