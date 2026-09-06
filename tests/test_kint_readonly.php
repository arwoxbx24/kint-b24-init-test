<?php
declare(strict_types=1);
namespace KintB24;
require dirname(__DIR__).'/autoload.php';
$requests=[];
function curl_init(string $url): object {global $requests;$h=(object)['url'=>$url,'options'=>[]];$requests[]=$h;return $h;}
function curl_setopt_array(object $h,array $options):bool {$h->options=$options;return true;}
function curl_exec(object $h):string {return '{"Success":true,"Result":[]}';}
function curl_errno(object $h):int {return 0;}
function curl_error(object $h):string {return '';}
function curl_getinfo(object $h,int $option):int {return 200;}
function curl_close(object $h):void {}
$fail=0;$checks=0;
function check(bool $ok,string $name):void {global $fail,$checks;$checks++;if(!$ok){$fail++;echo "FAIL $name\n";}}
$client=new KintClient('https://example.invalid/publication','fixture','fixture');
check(!method_exists($client,'post'),'POST method removed');
foreach ([
 ['GetBookingInvoice',[]], ['PostData',['Method'=>'PostBooking']],
 ['GetData',['Method'=>'GetBookingInvoice']], ['GetData',[]],
 ['GetData',['Method'=>['GetInvoices']]], ['GetInvoices/../PostBooking',[]],
 ['GetInvoices?Method=PostBooking',[]], ['getinvoices',[]], ['UnknownList',[]],
 ['GetInvoices',['Method'=>'PostBooking']], ['GetBookingStatus',['PrintForm'=>['ID'=>'x']]],
 ['GetCatalog',['_method'=>'POST']], ['QRCode',[]],
 ['GetData',['Method'=>'ИзменитьСтатусУчастникаМероприятия']],
 ['GetData',['Method'=>'GetInvoices','method'=>'PostBooking']],
 ['GetInvoices%3fMethod=PostBooking',[]],
 ['КартыГостя',['стрРеквизиты'=>'Дата']],
] as [$method,$params]) {
 $before=count($requests);$blocked=false;
 try{$client->get($method,$params);}catch(\LogicException $e){$blocked=true;}
 check($blocked && count($requests)===$before,"blocked before HTTP: $method");
}
$client->get('GetInvoices',['Основание'=>['ID'=>'ref'],'Fields'=>'СуммаДокумента']);
$h=end($requests);parse_str(parse_url($h->url, PHP_URL_QUERY),$q);
check(json_decode($q['Основание'],true)===['ID'=>'ref'],'reference kept as JSON');
check(($h->options[CURLOPT_HTTPGET]??false)===true,'GET explicitly selected');
check(($h->options[CURLOPT_FOLLOWLOCATION]??null)===false,'redirects disabled');
$client->get('GetData',['Method'=>'PaymentStatusByDocument','Document'=>['ID'=>'ref']]);
check(str_contains(end($requests)->url,'/PaymentStatusByDocument?'),'legacy dispatcher canonicalized');
foreach (['http://example.invalid','https://example.invalid/?Method=PostBooking','https://example.invalid/#fragment'] as $base) {
 $before=count($requests);$blocked=false;
 try{(new KintClient($base,'fixture','fixture'))->get('GetInvoices');}catch(\LogicException $e){$blocked=true;}
 check($blocked&&count($requests)===$before,'invalid endpoint blocked');
}
foreach (['GetBookingList','GetBookingStatus','GetInvoices','GetAcceptances','PaymentStatusByDocument','КартыГостя','GetGuestData','GetCatalog','RelatedDocuments','GetAvailableRooms'] as $read) {
 $before=count($requests);$client->get($read);check(count($requests)===$before+1, 'allowed read '.$read);
}
$client->get('GetInvoices',['Основание'=>['ID'=>'ref&Method=PostBooking']]);
parse_str(parse_url(end($requests)->url,PHP_URL_QUERY),$query);
check(!isset($query['Method']) && json_decode($query['Основание'],true)['ID']==='ref&Method=PostBooking','query value cannot inject dispatch');
$before=count($requests);$client->getPaged('GetBookingList');
check(count($requests)===$before+1 && !str_contains(end($requests)->url,'CountOnPage'),'non-paginated list has no undocumented pagination controls');
$allowed=false;
try {
 $client->get('GetCatalog',['CatalogName'=>'Контрагенты','AdditionalProperties'=>'Телефон,ЭлектроннаяПочта']);
 parse_str(parse_url(end($requests)->url,PHP_URL_QUERY),$query);
 $allowed=($query['AdditionalProperties']??'')==='Телефон,ЭлектроннаяПочта';
} catch(\LogicException $e) {}
check($allowed,'documented GetCatalog additional properties are readable');
echo "KINT read-only: $checks checks, $fail failures\n";
exit($fail?1:0);
