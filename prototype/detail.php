<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
require dirname(__DIR__) . '/autoload.php';
$APPLICATION->SetTitle("КИНТ → Битрикс24 | Детальная карта заявки");

define('KINT_URL',  'REDACTED_KINT_URL');
define('KINT_USER', 'REDACTED_KINT_USER');
define('KINT_PASS', 'REDACTED_KINT_PASS');

$searchNumber = trim($_REQUEST['number'] ?? 'TEST-BOOKING-001');

class KintApiClient
{
    private string $baseUrl;
    private string $user;
    private string $pass;
    private array $errors = [];
    private array $rawResponses = [];

    public function __construct(string $url, string $user, string $pass)
    {
        $this->baseUrl = $url;
        $this->user = $user;
        $this->pass = $pass;
    }

    public function get(string $method, array $params = []): ?array
    {
        try {
            $url = \KintB24\KintReadOnlyPolicy::url($this->baseUrl, $method, $params);
        } catch (InvalidArgumentException | LogicException $e) {
            $this->errors["GET {$method}"] = "GET {$method}: {$e->getMessage()}";
            return ['_error' => 'BLOCKED_KINT_READ_ONLY', '_method' => $method];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->user . ':' . $this->pass,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) {
            $this->errors[] = "GET {$method}: curl error — {$err}";
            return ['_error' => $err];
        }
        $this->rawResponses[$method] = ['http_code' => $code, 'body' => mb_substr($body, 0, 3000)];
        $data = json_decode($body, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = "GET {$method}: JSON decode error";
            return ['_http_code' => $code, '_raw' => mb_substr($body, 0, 500)];
        }
        if (is_array($data) && isset($data['Success']) && $data['Success'] === false) {
            $errKey = "GET {$method}";
            if (!isset($this->errors[$errKey])) {
                $this->errors[$errKey] = "{$errKey}: " . ($data['Error'] ?? ($data['Result']['Error'] ?? 'unknown'));
            }
        }
        if (is_array($data) && isset($data['Success']) && $data['Success'] === true && array_key_exists('Result', $data)) {
            $result = $data['Result'];
            if (is_string($result)) {
                $decoded = json_decode($result, true);
                return is_array($decoded) ? $decoded : ['_raw_result' => $result];
            }
            return $result;
        }
        return $data;
    }

    public function getErrors(): array { return array_values($this->errors); }
    public function getRawResponses(): array { return $this->rawResponses; }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * DATA FETCHING
 * ═══════════════════════════════════════════════════════════════════════════ */

$api = new KintApiClient(KINT_URL, KINT_USER, KINT_PASS);

function toIsoDate(string $dateStr): string {
    $dt = DateTime::createFromFormat('d.m.Y', $dateStr);
    return $dt ? $dt->format('Y-m-d\T00:00:00') : $dateStr;
}

function findBookingByNumber(KintApiClient $api, string $number): ?array {
    $result = $api->get('GetCatalog', [
        'CatalogName'   => 'ЗаявкаПокупателя',
        'CatalogType'   => 'Документ',
        'Filter'        => json_encode(['Номер' => $number], JSON_UNESCAPED_UNICODE),
        'Fields'        => 'Ссылка,Гости,Контрагент,Договор,ОрганизацияПребывания',
    ]);

    if (!is_array($result) || isset($result['_error'])) return null;
    if (empty($result)) return null;

    $b = $result[0] ?? null;
    if (!$b) return null;

    $ref = $b['Ссылка'] ?? [];
    $booking = [
        'ID' => $ref['Идентификатор'] ?? '',
        'Number' => $ref['Номер'] ?? '',
        'Date' => $ref['Дата'] ?? '',
        'Status' => '',
        'ОрганизацияПребывания' => $ref['ОрганизацияПребывания'] ?? ($b['ОрганизацияПребывания'] ?? $b['OrganizationOfStay'] ?? []),
        'Договор' => $ref['Договор'] ?? ($b['Договор'] ?? []),
        'Booked' => 0,
        'ToProcess' => 0,
        'Ссылка' => $ref,
    ];
    return $booking;
}

function fetchGuestCards(KintApiClient $api, string $personRef): array {
    if (!$personRef) return [];
    $result = $api->get('КартыГостя', [
        'ФизЛицо' => ['Идентификатор' => $personRef],
    ]);
    if (!is_array($result) || (isset($result['Success']) && $result['Success'] === false)) return [];
    return $result;
}

function fetchInvoicesByClient(KintApiClient $api, string $clientRef): array {
    if (!$clientRef) return [];
    $result = $api->get('GetInvoices', [
        'Контрагент' => json_encode(['ID' => $clientRef], JSON_UNESCAPED_UNICODE),
        'Fields'     => 'Ссылка,Дата,Номер,СуммаДокумента,Контрагент,Основание,Договор',
    ]);
    if (!is_array($result) || (isset($result['Success']) && $result['Success'] === false)) return [];
    return $result;
}

function fetchAcceptancesByClient(KintApiClient $api, string $clientRef): array {
    if (!$clientRef) return [];
    $result = $api->get('GetAcceptances', [
        'Контрагент' => json_encode(['ID' => $clientRef], JSON_UNESCAPED_UNICODE),
        'Fields'     => 'Ссылка,Дата,Номер,СуммаДокумента,Контрагент',
    ]);
    if (!is_array($result) || (isset($result['Success']) && $result['Success'] === false)) return [];
    return $result;
}

function fetchServiceAssignments(KintApiClient $api, string $guestCardRef, string $dateFrom, string $dateTo): array {
    if (!$guestCardRef) return [];
    return ['_error' => 'BLOCKED_KINT_READ_ONLY', '_method' => 'НазначенияИРезультаты'];
}

function fetchPaymentStatus(KintApiClient $api, string $documentRef): ?array {
    if (!$documentRef) return null;
    $result = $api->get('GetData', [
        'Method'   => 'PaymentStatusByDocument',
        'Document' => json_encode(['Идентификатор' => $documentRef], JSON_UNESCAPED_UNICODE),
    ]);
    return is_array($result) ? $result : null;
}

/* ═══════════════════════════════════════════════════════════════════════════
 * MAIN LOGIC
 * ═══════════════════════════════════════════════════════════════════════════ */

$booking = findBookingByNumber($api, $searchNumber);

$bookingId = '';
$clientRef = '';
$clientName = '';
$contractRef = '';
$contractName = '';
$cost = '';
$guests = [];
$guestCards = [];
$invoices = [];
$acceptances = [];
$paymentStatus = null;
$serviceAssignments = [];
$sanatorium = '';

if ($booking) {
    $bookingId   = $booking['ID'] ?? '';
    $clientRef   = $booking['Ссылка']['Контрагент']['Идентификатор'] ?? '';
    $clientName  = $booking['Ссылка']['Контрагент']['Наименование'] ?? '';
    $contractRef = $booking['Ссылка']['Договор']['Идентификатор'] ?? ($booking['Договор']['Идентификатор'] ?? '');
    $contractName = $booking['Ссылка']['Договор']['Наименование'] ?? ($booking['Договор']['Наименование'] ?? '');
    $guests      = $booking['Ссылка']['Гости'] ?? [];
    $cost        = $booking['Ссылка']['Стоимость'] ?? ($guests[0]['Стоимость'] ?? '');
    $sanatorium  = $booking['Ссылка']['ОрганизацияПребывания']['Наименование'] ?? ($booking['ОрганизацияПребывания']['Наименование'] ?? ($booking['OrganizationOfStay']['Наименование'] ?? ''));

    if ($clientRef) {
        $invoices = fetchInvoicesByClient($api, $clientRef);
        $acceptances = fetchAcceptancesByClient($api, $clientRef);
    }

    foreach ($guests as $guest) {
        $physRef = $guest['ФизЛицо']['Идентификатор'] ?? '';
        if ($physRef) {
            $cards = fetchGuestCards($api, $physRef);
            foreach ($cards as $card) {
                $cardId = $card['Идентификатор'] ?? '';
                $cardData = [
                    'Дата' => $card['Дата'] ?? '',
                    'Номер' => trim($card['Номер'] ?? ''),
                    'Идентификатор' => $cardId,
                    'ДатаЗаезда' => $card['ДатаЗаезда'] ?? ($guest['ДатаЗаезда'] ?? ''),
                    'ДатаВыезда' => $card['ДатаВыезда'] ?? ($guest['ДатаВыезда'] ?? ''),
                    'НомерГостиницы' => is_array($card['НомерГостиницы'] ?? null) ? ($card['НомерГостиницы']['Наименование'] ?? '') : ($card['НомерГостиницы'] ?? ($guest['НомерГостиницы']['Наименование'] ?? '')),
                    'ВариантПроживания' => is_array($card['ВариантПроживания'] ?? null) ? ($card['ВариантПроживания']['Наименование'] ?? '') : ($card['ВариантПроживания'] ?? ($guest['ВариантПроживания']['Наименование'] ?? '')),
                    'ВариантЛечения' => is_array($card['ВариантЛечения'] ?? null) ? ($card['ВариантЛечения']['Наименование'] ?? '') : ($card['ВариантЛечения'] ?? ($guest['ВариантЛечения']['Наименование'] ?? '')),
                    'ВариантПитания' => is_array($card['ВариантПитания'] ?? null) ? ($card['ВариантПитания']['Наименование'] ?? '') : ($card['ВариантПитания'] ?? ($guest['ВариантПитания']['Наименование'] ?? '')),
                    'Гость' => $guest['ФизЛицо']['Наименование'] ?? '',
                    'ВозрастнаяГруппа' => $guest['ВозрастнаяГруппа']['Наименование'] ?? '',
                    'СтоимостьГостя' => $guest['Стоимость'] ?? '',
                    'КатегорияПутевки' => $guest['КатегорияПутевки']['Наименование'] ?? '',
                    'КатегорияНомера' => $guest['КатегорияНомера']['Наименование'] ?? '',
                    'КоличествоДней' => $guest['КоличествоДней'] ?? '',
                    'Корпус' => $guest['Корпус']['Наименование'] ?? '',
                    'НомерБСО' => $guest['НомерБСО'] ?? '',
                    'ВремяСутокЗаезда' => $guest['ВремяСутокЗаезда']['Наименование'] ?? '',
                    'ВремяСутокВыезда' => $guest['ВремяСутокВыезда']['Наименование'] ?? '',
                ];
                $guestCards[] = $cardData;

                if ($cardId) {
                    $sa = fetchServiceAssignments($api, $cardId, '01.01.2026', '31.12.2026');
                    if (!empty($sa)) {
                        foreach ($sa as $s) {
                            if (is_array($s) && !isset($s['Success'])) $serviceAssignments[] = $s;
                        }
                    }
                }
            }
        }
    }

    if ($bookingId) {
        $paymentStatus = fetchPaymentStatus($api, $bookingId);
    }
}

$errors = $api->getErrors();

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function jsonCard($v): string { return htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8'); }
function infoBox($type, $msg): string { return "<div class=\"info-box {$type}\">{$msg}</div>"; }

?>
<style>
    h1 { text-align:center; margin-bottom:8px; font-size:22px; color:#2067b0; }
    .subtitle { text-align:center; color:#666; margin-bottom:20px; font-size:13px; }
    .search-form { text-align:center; margin-bottom:20px; }
    .search-form input { padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:14px; margin:0 4px; }
    .search-form button { padding:6px 16px; background:#2067b0; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:14px; }
    .section { background:#fff; border-radius:8px; margin-bottom:16px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,.1); }
    .section h2 { font-size:16px; color:#2067b0; margin-bottom:8px; }
    .section-desc { color:#666; font-size:13px; margin-bottom:12px; padding:8px 12px; background:#f5f7fa; border-radius:6px; border-left:3px solid #2067b0; }
    .data-table { width:100%; border-collapse:collapse; font-size:13px; }
    .data-table th { background:#2067b0; color:#fff; padding:8px 10px; text-align:left; }
    .data-table td { padding:6px 10px; border-bottom:1px solid #eee; vertical-align:top; }
    .data-table tr:nth-child(even) td { background:#f9fafb; }
    .data-table code { background:#eef; padding:1px 4px; border-radius:3px; font-size:12px; color:#c0392b; }
    .field-name { white-space:nowrap; font-weight:600; width:35%; }
    .deal-preview { background:#f0f7ff; border:1px solid #d0e0ff; border-radius:6px; padding:12px; margin:8px 0; }
    .deal-preview-title { font-size:13px; font-weight:600; color:#2067b0; margin-bottom:6px; }
    .empty-state { text-align:center; padding:16px; color:#999; font-style:italic; background:#f9f9f9; border-radius:6px; }
    .info-box { padding:10px 14px; border-radius:6px; margin:8px 0; font-size:13px; }
    .info-box.info { background:#e8f4fd; border-left:3px solid #3498db; }
    .info-box.success { background:#eafaf1; border-left:3px solid #27ae60; }
    .info-box.warning { background:#fef9e7; border-left:3px solid #f39c12; }
    .info-box.error { background:#fdedec; border-left:3px solid #e74c3c; }
    .json-card { margin:8px 0; }
    .json-card-title { font-size:12px; color:#666; margin-bottom:4px; }
    .json-pre { background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:6px; overflow-x:auto; font-size:11px; line-height:1.4; max-height:400px; overflow-y:auto; }
    .client-header { background:linear-gradient(135deg,#2067b0,#2980b9); color:#fff; border-radius:8px; padding:20px; margin-bottom:16px; }
    .client-header h2 { color:#fff; margin:0 0 8px 0; font-size:20px; }
    .client-header .meta { display:flex; flex-wrap:wrap; gap:16px; font-size:14px; }
    .client-header .meta div { opacity:0.95; }
    .client-header .meta strong { font-weight:600; }
    .error-list { list-style:none; padding:0; }
    .error-list li { background:#fee; color:#c00; padding:6px 10px; border-radius:4px; margin:4px 0; font-size:13px; border-left:3px solid #c00; }
    .nav-bar { position:sticky; top:0; z-index:100; background:#fff; padding:10px 20px; box-shadow:0 2px 4px rgba(0,0,0,.1); margin-bottom:16px; border-radius:8px; display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
    .nav-bar a { font-size:12px; padding:3px 8px; border-radius:4px; background:#f0f7ff; color:#2067b0; text-decoration:none; }
    .nav-bar a:hover { background:#2067b0; color:#fff; }
    .nav-bar .back { margin-left:auto; }
</style>

<h1>КИНТ → Битрикс24 | Детальная карта заявки</h1>
<div class="subtitle">Полная информация по заявке и контрагенту для интегратора</div>

<div class="search-form">
    <form method="get">
        <input type="text" name="number" value="<?= esc($searchNumber) ?>" placeholder="Номер заявки (TEST-BOOKING-001)" size="20">
        <button type="submit">Найти</button>
        <a href="/local/mvp/" style="margin-left:12px;font-size:14px;color:#2067b0;">← Назад к MVP</a>
    </form>
</div>

<?php if (!$booking): ?>
    <div class="section">
        <div class="info-box error">Заявка №<?= esc($searchNumber) ?> не найдена. Проверьте номер заявки.</div>
        <?php if (!empty($errors)): ?>
            <ul class="error-list"><?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
<?php else: ?>

<div class="nav-bar">
    <a href="#client">Клиент</a>
    <a href="#s1_1">1.1 Сделка</a>
    <a href="#s1_2">1.2 Счета</a>
    <a href="#s1_3">1.3 Оплаты</a>
    <a href="#s1_4">1.4 Связи</a>
    <a href="#s1_5">1.5 Услуги</a>
    <a href="#s1_7">1.7 Выезд</a>
    <a href="#s2_1">2.1 Уведомления</a>
    <a href="#debug">DEBUG</a>
    <a href="/local/mvp/" class="back">← К списку</a>
</div>

<!-- ═══ КЛИЕНТ ═══ -->
<div class="client-header" id="client">
    <h2><?= esc($clientName) ?></h2>
    <div class="meta">
        <div><strong>Заявка:</strong> <?= esc(trim($booking['Number'] ?? '')) ?></div>
        <div><strong>Дата:</strong> <?= esc($booking['Date'] ?? '') ?></div>
        <div><strong>Статус:</strong> <?= esc($booking['Status'] ?? '') ?></div>
        <div><strong>Санаторий:</strong> <?= esc($sanatorium) ?></div>
        <div><strong>Стоимость:</strong> <?= esc($cost) ?> руб.</div>
        <div><strong>Договор:</strong> <?= esc($contractName) ?></div>
        <div><strong>Гостей:</strong> <?= count($guests) ?></div>
    </div>
</div>

<!-- ═══ 1.1 СДЕЛКА ═══ -->
<div class="section" id="s1_1">
    <h2>1.1 — Данные для Создания «Сделки» в Битрикс24</h2>
    <div class="section-desc">Данные из КИНТ (GetBookingList) → маппинг в поля сделки Битрикс24</div>
    <table class="data-table"><tbody>
        <tr><td class="field-name"><code>TITLE</code></td><td>Бронь №<?= esc(trim($booking['Number'] ?? '')) ?></td></tr>
        <tr><td class="field-name"><code>BEGINDATE</code></td><td><?= esc($booking['Date'] ?? '') ?></td></tr>
        <tr><td class="field-name"><code>STAGE_ID</code></td><td>NEW</td></tr>
        <tr><td class="field-name"><code>UF_KINT_BOOKING_ID</code></td><td><?= esc($bookingId) ?></td></tr>
        <tr><td class="field-name"><code>UF_KINT_SANATORIUM</code></td><td><?= esc($sanatorium) ?></td></tr>
        <tr><td class="field-name"><code>CONTACT_ID</code></td><td>→ поиск по ФИО: <?= esc($clientName) ?></td></tr>
        <tr><td class="field-name"><code>OPPORTUNITY</code></td><td><?= esc($cost) ?> руб.</td></tr>
        <tr><td class="field-name"><code>UF_KINT_BOOKED</code></td><td><?= ($booking['Booked'] ?? 0) ? 'Да' : 'Нет' ?></td></tr>
        <tr><td class="field-name"><code>UF_KINT_TO_PROCESS</code></td><td><?= esc($booking['ToProcess'] ?? '') ?></td></tr>
    </tbody></table>

    <h3 style="margin-top:16px;font-size:14px;">Гости из заявки</h3>
    <table class="data-table">
        <thead><tr><th>№</th><th>ФИО</th><th>Заезд</th><th>Выезд</th><th>Номер</th><th>Проживание</th><th>Лечение</th><th>Питание</th><th>Возраст</th><th>Стоимость</th><th>Категория путевки</th></tr></thead>
        <tbody>
        <?php foreach ($guests as $i => $g): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= esc($g['ФизЛицо']['Наименование'] ?? '') ?></td>
                <td><?= esc($g['ДатаЗаезда'] ?? '') ?></td>
                <td><?= esc($g['ДатаВыезда'] ?? '') ?></td>
                <td><?= esc($g['НомерГостиницы']['Наименование'] ?? '') ?></td>
                <td><?= esc($g['ВариантПроживания']['Наименование'] ?? '') ?></td>
                <td><?= esc($g['ВариантЛечения']['Наименование'] ?? '') ?></td>
                <td><?= esc($g['ВариантПитания']['Наименование'] ?? '') ?></td>
                <td><?= esc($g['ВозрастнаяГруппа']['Наименование'] ?? '') ?></td>
                <td><?= esc($g['Стоимость'] ?? '') ?></td>
                <td><?= esc($g['КатегорияПутевки']['Наименование'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ═══ 1.2 СЧЕТА ═══ -->
<div class="section" id="s1_2">
    <h2>1.2 — Счета и ссылки на оплату</h2>
    <div class="section-desc">Данные из КИНТ (GetInvoices по контрагенту) + GetPaymentQRCode (MOCK)</div>
    <?php if (empty($invoices)): ?>
        <div class="empty-state">Счета не найдены</div>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Номер счёта</th><th>Дата</th><th>Сумма</th><th>Основание</th><th>ID КИНТ</th><th>Ссылка СБП</th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $inv):
                $basis = $inv['Основание'] ?? null;
                $basisStr = '';
                if (is_array($basis)) {
                    $basisStr = trim($basis['Номер'] ?? '') . ' от ' . ($basis['Дата'] ?? '');
                } else {
                    $basisStr = (string)($basis ?? '');
                }
            ?>
                <tr>
                    <td><?= esc(trim($inv['Номер'] ?? '')) ?></td>
                    <td><?= esc($inv['Дата'] ?? '') ?></td>
                    <td><?= esc($inv['СуммаДокумента'] ?? '') ?></td>
                    <td><?= esc($basisStr) ?></td>
                    <td><?= esc($inv['Идентификатор'] ?? '') ?></td>
                    <td><span style="color:#999;">[MOCK]</span> REDACTED_URL</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- ═══ 1.3 ОПЛАТЫ ═══ -->
<div class="section" id="s1_3">
    <h2>1.3 — Поступление денежных средств</h2>
    <div class="section-desc">Данные из КИНТ (GetAcceptances по контрагенту) + PaymentStatusByDocument</div>
    <?php if (empty($acceptances)): ?>
        <div class="empty-state">Платежи не найдены</div>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Номер</th><th>Дата</th><th>Сумма документа</th><th>ID КИНТ</th></tr></thead>
            <tbody>
            <?php foreach ($acceptances as $acc): ?>
                <tr>
                    <td><?= esc(trim($acc['Номер'] ?? '')) ?></td>
                    <td><?= esc($acc['Дата'] ?? '') ?></td>
                    <td><?= esc($acc['СуммаДокумента'] ?? '') ?></td>
                    <td><?= esc($acc['Идентификатор'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($paymentStatus && !isset($paymentStatus['Success'])): ?>
        <h3 style="margin-top:12px;font-size:14px;">Статус оплаты (PaymentStatusByDocument)</h3>
        <table class="data-table"><tbody>
            <tr><td class="field-name">Выставлено</td><td><?= esc($paymentStatus['Выставлено'] ?? '') ?> руб.</td></tr>
            <tr><td class="field-name">Оплачено</td><td><?= esc($paymentStatus['Оплачено'] ?? '') ?> руб.</td></tr>
            <tr><td class="field-name">Статус</td><td>
                <?php
                    $invoiced = $paymentStatus['Выставлено'] ?? 0;
                    $paid = $paymentStatus['Оплачено'] ?? 0;
                    $status = $paid >= $invoiced && $invoiced > 0 ? 'Полностью оплачено' : ($paid > 0 ? 'Частично оплачено' : 'Не оплачено');
                    echo '<strong>' . esc($status) . '</strong>';
                ?>
            </td></tr>
        </tbody></table>
    <?php endif; ?>
</div>

<!-- ═══ 1.4 СВЯЗИ ═══ -->
<div class="section" id="s1_4">
    <h2>1.4 — Связь Бронь → Карта гостя → Сделка</h2>
    <div class="section-desc">Карты гостя по физлицам из заявки (КартыГостя API)</div>
    <?php if (empty($guestCards)): ?>
        <div class="empty-state">Карты гостя не найдены</div>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Гость</th><th>Номер карты</th><th>Дата карты</th><th>Заезд</th><th>Выезд</th><th>Номер</th><th>Проживание</th><th>Лечение</th><th>Питание</th><th>ID Карты</th></tr></thead>
            <tbody>
            <?php foreach ($guestCards as $card): ?>
                <tr>
                    <td><?= esc($card['Гость'] ?? '') ?></td>
                    <td><?= esc($card['Номер'] ?? '') ?></td>
                    <td><?= esc($card['Дата'] ?? '') ?></td>
                    <td><?= esc($card['ДатаЗаезда'] ?? '') ?></td>
                    <td><?= esc($card['ДатаВыезда'] ?? '') ?></td>
                    <td><?= esc($card['НомерГостиницы'] ?? '') ?></td>
                    <td><?= esc($card['ВариантПроживания'] ?? '') ?></td>
                    <td><?= esc($card['ВариантЛечения'] ?? '') ?></td>
                    <td><?= esc($card['ВариантПитания'] ?? '') ?></td>
                    <td><?= esc($card['Идентификатор'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:12px;font-size:14px;">Поля связки в сделке Б24</h3>
        <table class="data-table"><tbody>
            <tr><td class="field-name"><code>UF_KINT_BOOKING_ID</code></td><td><?= esc($bookingId) ?></td></tr>
            <tr><td class="field-name"><code>UF_KINT_GUEST_CARD_ID</code></td><td><?= esc($guestCards[0]['Идентификатор'] ?? '(не найдена)') ?></td></tr>
            <tr><td class="field-name"><code>UF_KINT_GC_ARRIVAL</code></td><td><?= esc($guestCards[0]['ДатаЗаезда'] ?? '') ?></td></tr>
            <tr><td class="field-name"><code>UF_KINT_GC_DEPARTURE</code></td><td><?= esc($guestCards[0]['ДатаВыезда'] ?? '') ?></td></tr>
            <tr><td class="field-name"><code>UF_KINT_GC_ROOM</code></td><td><?= esc($guestCards[0]['НомерГостиницы'] ?? '') ?></td></tr>
        </tbody></table>
    <?php endif; ?>
</div>

<!-- ═══ 1.5 УСЛУГИ ═══ -->
<div class="section" id="s1_5">
    <h2>1.5 — Дополнительные услуги гостя</h2>
    <div class="section-desc">Назначения и результаты по картам гостя (НазначенияИРезультаты)</div>
    <?php if (empty($serviceAssignments)): ?>
        <div class="empty-state">Назначенные услуги не найдены</div>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Услуга</th><th>Дата сеанса</th><th>Время</th><th>Платная</th><th>Стоимость</th><th>Назначено</th><th>Пройдено</th><th>Осталось</th></tr></thead>
            <tbody>
            <?php foreach ($serviceAssignments as $s): ?>
                <tr>
                    <td><?= esc($s['Услуга'] ?? '') ?></td>
                    <td><?= esc($s['ДатаСеанса'] ?? '') ?></td>
                    <td><?= esc(($s['ВремяС'] ?? '') . '–' . ($s['ВремяДо'] ?? '')) ?></td>
                    <td><?= ($s['Платная'] ?? false) ? 'Да' : 'Нет' ?></td>
                    <td><?= esc($s['Стоимость'] ?? '') ?></td>
                    <td><?= esc($s['Назначено'] ?? '') ?></td>
                    <td><?= esc($s['Пройдено'] ?? '') ?></td>
                    <td><?= esc($s['Осталось'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:12px;font-size:14px;">Товарные позиции для сделки (только платные)</h3>
        <?php
        $paidServices = array_filter($serviceAssignments, fn($s) => !empty($s['Платная']));
        if (empty($paidServices)):
            echo '<div class="empty-state">Нет платных услуг</div>';
        else:
            foreach ($paidServices as $s):
                $svcName = is_array($s['Услуга'] ?? null) ? ($s['Услуга']['Наименование'] ?? '') : ($s['Услуга'] ?? '');
        ?>
            <div class="deal-preview">
                <div class="deal-preview-title">Услуга: <?= esc($svcName) ?></div>
                <table class="data-table"><tbody>
                    <tr><td class="field-name"><code>NAME</code></td><td><?= esc($svcName) ?></td></tr>
                    <tr><td class="field-name"><code>PRICE</code></td><td><?= esc($s['Стоимость'] ?? '') ?></td></tr>
                    <tr><td class="field-name"><code>QUANTITY</code></td><td><?= esc($s['Назначено'] ?? 1) ?></td></tr>
                </tbody></table>
            </div>
        <?php endforeach; endif; ?>
    <?php endif; ?>
</div>

<!-- ═══ 1.7 ВЫЕЗД ═══ -->
<div class="section" id="s1_7">
    <h2>1.7 — Фактический выезд</h2>
    <div class="section-desc">Данные о плановом и фактическом выезде из Карты гостя</div>
    <?php if (empty($guestCards)): ?>
        <div class="empty-state">Нет данных о выезде</div>
    <?php else: ?>
        <?php foreach ($guestCards as $card):
            $cardId = $card['Идентификатор'] ?? '';
            $planned = $card['ДатаВыезда'] ?? '';
            $actual = $card['ФактическаяДатаВыезда'] ?? ($card['ДатаФактическогоВыезда'] ?? ($card['ДатаВыездаФакт'] ?? ($card['ActualDateDeparture'] ?? '')));
            $isEarly = false;
            if ($actual && $planned) {
                $isEarly = strtotime($actual) < strtotime($planned);
            }
            $status = $actual ? ($isEarly ? 'Досрочный выезд' : 'Выезд по плану') : 'Ещё проживает';
            $extraFields = '';
            foreach (['ФактическаяДатаВыезда','ДатаФактическогоВыезда','ДатаВыездаФакт','Статус','ДатаЗаездаФакт'] as $f) {
                if (array_key_exists($f, $card) && $card[$f] !== null && $card[$f] !== '') {
                    $val = is_array($card[$f]) ? ($card[$f]['Наименование'] ?? json_encode($card[$f], JSON_UNESCAPED_UNICODE)) : $card[$f];
                    $extraFields .= '<tr><td class="field-name"><code>' . esc($f) . '</code></td><td>' . esc($val) . '</td></tr>';
                }
            }
        ?>
        <div class="deal-preview">
            <div class="deal-preview-title">Карта гостя: <?= esc($card['Гость'] ?? '') ?> (<?= esc($card['Номер'] ?? '') ?>)</div>
            <table class="data-table"><tbody>
                <tr><td class="field-name"><code>UF_KINT_PLANNED_DEPARTURE</code></td><td><?= esc($planned) ?></td></tr>
                <tr><td class="field-name"><code>UF_KINT_ACTUAL_DEPARTURE</code></td><td><?= $actual ? esc($actual) : '(нет данных)' ?></td></tr>
                <tr><td class="field-name"><code>UF_KINT_EARLY_DEPARTURE</code></td><td><?= $isEarly ? 'Да' : 'Нет' ?></td></tr>
                <tr><td class="field-name">Статус</td><td><strong><?= esc($status) ?></strong></td></tr>
                <?= $extraFields ?>
            </tbody></table>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ═══ 2.1 УВЕДОМЛЕНИЯ ═══ -->
<div class="section" id="s2_1">
    <h2>2.1 — Уведомление клиенту о поступлении ДС</h2>
    <div class="section-desc">Шаблон уведомления с заполненными данными по текущей заявке</div>
    <?php
    $invoiced = $paymentStatus['Выставлено'] ?? 0;
    $paid = $paymentStatus['Оплачено'] ?? 0;
    $remaining = $invoiced - $paid;
    $payStatus = $paid >= $invoiced && $invoiced > 0 ? 'Полностью оплачено' : ($paid > 0 ? 'Частично оплачено' : 'Не оплачено');
    $firstGuest = $guests[0] ?? [];
    $guestName = $firstGuest['ФизЛицо']['Наименование'] ?? $clientName;
    $room = $firstGuest['НомерГостиницы']['Наименование'] ?? '';
    $dateFrom = $firstGuest['ДатаЗаезда'] ?? '';
    $dateTo = $firstGuest['ДатаВыезда'] ?? '';
    $lastPayDate = '';
    if (!empty($acceptances)) {
        $lastPay = end($acceptances);
        $lastPayDate = $lastPay['Дата'] ?? '';
    }
    ?>
    <div class="info-box info">
        <strong>Тема:</strong> Поступление оплаты по бронированию №<?= esc(trim($booking['Number'] ?? '')) ?><br><br>
        Уважаемый(ая) <?= esc($guestName) ?>!<br><br>
        Поступила оплата по вашему бронированию №<?= esc(trim($booking['Number'] ?? '')) ?>.<br>
        Сумма оплаты: <?= esc($paid) ?> руб.<br>
        Дата оплаты: <?= esc($lastPayDate) ?><br>
        Остаток к оплате: <?= esc($remaining) ?> руб.<br>
        Статус оплаты: <?= esc($payStatus) ?><br><br>
        Санаторий: <?= esc($sanatorium) ?><br>
        Дата заезда: <?= esc($dateFrom) ?><br>
        Дата выезда: <?= esc($dateTo) ?><br>
        Номер: <?= esc($room) ?>
    </div>
</div>

<!-- ═══ DEBUG ═══ -->
<div class="section" id="debug" style="border:2px dashed #ccc;">
    <h2>DEBUG</h2>
    <?php if (!empty($errors)): ?>
        <h3 style="font-size:14px;color:#c00;">Ошибки API</h3>
        <ul class="error-list"><?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
    <?php else: ?>
        <div class="info-box success">Ошибок не обнаружено</div>
    <?php endif; ?>

    <h3 style="font-size:14px;margin-top:12px;">Raw JSON-ответы</h3>
    <?php foreach ($api->getRawResponses() as $method => $resp): ?>
        <div class="json-card">
            <div class="json-card-title"><?= esc($method) ?> (HTTP <?= esc($resp['http_code'] ?? '?') ?>)</div>
            <pre class="json-pre"><?= esc(mb_substr($resp['body'] ?? '', 0, 2000)) ?></pre>
        </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>
