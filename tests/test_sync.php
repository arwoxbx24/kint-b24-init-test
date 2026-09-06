#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Smoke test for SyncEngine — no network calls.
 *
 * Uses mock subclasses with in-memory fixture data.
 * Run: php tests/test_sync.php
 * Exit 0 = green. Exit 1 = red.
 */

require_once dirname(__DIR__) . '/autoload.php';

use KintB24\KintClient;
use KintB24\B24Client;
use KintB24\Store;
use KintB24\SyncEngine;
use KintB24\FieldMapper;

// ── Helpers ───────────────────────────────────────────────────────────────────

$pass  = 0;
$fail  = 0;
$fails = [];

function assert_eq(string $label, mixed $got, mixed $expected): void
{
    global $pass, $fail, $fails;
    if ($got === $expected) {
        echo "  [PASS] {$label}\n";
        $pass++;
    } else {
        $gotStr = json_encode($got);
        $expStr = json_encode($expected);
        echo "  [FAIL] {$label}: got={$gotStr} expected={$expStr}\n";
        $fail++;
        $fails[] = $label;
    }
}

function assert_not_null(string $label, mixed $got): void
{
    global $pass, $fail, $fails;
    if ($got !== null) {
        echo "  [PASS] {$label}\n";
        $pass++;
    } else {
        echo "  [FAIL] {$label}: expected non-null, got null\n";
        $fail++;
        $fails[] = $label;
    }
}

function assert_null(string $label, mixed $got): void
{
    global $pass, $fail, $fails;
    if ($got === null) {
        echo "  [PASS] {$label}\n";
        $pass++;
    } else {
        echo "  [FAIL] {$label}: expected null, got " . json_encode($got) . "\n";
        $fail++;
        $fails[] = $label;
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

$BOOKING = [
    'Идентификатор'    => 'kint-booking-001',
    'Номер'            => 'БР-2024-001',
    'Статус'           => 'Оплачено',
    'ДатаНачала'       => '2024-09-01',
    'ДатаОкончания'    => '2024-09-10',
    'Санаторий'        => 'Тест-Санаторий',
    'КоличествоГостей' => 2,
    'КатегорияНомера'  => 'Стандарт',
    'Гость' => [
        'Идентификатор' => 'kint-guest-001',
        'ФИО'           => 'Иванов Иван Иванович',
        'ДатаРождения'  => '1985-03-15',
        'Пол'           => 'Мужской',
        'Телефон'       => '+12025550123',
        'Email'         => 'fixture@example.invalid',
    ],
];

$INVOICE = [
    'Идентификатор' => 'kint-invoice-001',
    'НомерСчёта'    => 'СЧ-2024-001',
    'Дата'          => '2024-08-25',
    'Сумма'         => 95000.0,
    'СсылкаОплаты'  => 'https://pay.example.invalid/test',
];

$PAYMENT = [
    'Идентификатор'  => 'kint-pay-001',
    'НомерПлатежа'   => 'ПЛ-2024-001',
    'ДатаПлатежа'    => '2024-08-28',
    'СуммаПлатежа'   => 95000.0,
    'ИтогоНачислено' => 95000.0,
    'ИтогоОплачено'  => 95000.0,
    'СтатусОплаты'   => 'Оплачено',
];

$GUEST_CARD = [
    'Идентификатор'    => 'kint-gc-001',
    'НомерКарты'       => 'КГ-2024-001',
    'ДатаПрибытия'     => '2024-09-01',
    'ДатаВыбытия'      => '2024-09-10',
    'ВариантПребывания'=> 'Санаторно-курортное лечение',
    'ТипПитания'       => '3-разовое',
    'НомерКомнаты'     => '215',
];

// ── Mock KintClient ───────────────────────────────────────────────────────────

class MockKintClient extends KintClient
{
    public array $calls = [];
    private array $booking;
    private array $invoice;
    private array $payment;
    private array $guestCard;

    public function __construct(array $b, array $inv, array $pay, array $gc)
    {
        // Skip parent constructor (no real URL needed)
        $this->booking   = $b;
        $this->invoice   = $inv;
        $this->payment   = $pay;
        $this->guestCard = $gc;
    }

    public function get(string $method, array $params = []): mixed
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        if ($method === 'GetData' && ($params['Method'] ?? '') === 'PaymentStatusByDocument') {
            return $this->payment;
        }
        return [];
    }

    public function getPaged(string $method, array $params = [], string $itemsKey = '', int $pageSize = 100): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params, 'itemsKey' => $itemsKey];
        if ($method === 'GetBookingList') {
            return [$this->booking];
        }
        if ($method === 'GetBookingInvoice' || $method === 'GetInvoices') {
            return [$this->invoice];
        }
        if ($method === 'КартыГостя') {
            return [$this->guestCard];
        }
        return [];
    }
}

// ── Mock B24Client ────────────────────────────────────────────────────────────

class MockB24Client extends B24Client
{
    public array $calls    = [];  // log of (method, params)
    public array $responses = [];
    public array $failMethods = [];
    private int  $nextId   = 100;

    public function __construct()
    {
        // Skip parent constructor
    }

    public function call(string $method, array $params = []): mixed
    {
        $this->calls[] = ['method' => $method, 'params' => $params];

        if (in_array($method, $this->failMethods, true)) {
            throw new RuntimeException("forced $method failure");
        }

        if (array_key_exists($method, $this->responses)) {
            return $this->responses[$method];
        }

        if ($method === 'crm.deal.add') {
            return ['ID' => $this->nextId++];
        }
        if ($method === 'crm.contact.add') {
            return ['ID' => $this->nextId++];
        }
        if ($method === 'crm.contact.list' || $method === 'crm.deal.list') {
            return [];  // no existing contact
        }
        return true;
    }

    public function batch(array $commands): array
    {
        return ['result' => [], 'result_error' => []];
    }
}

class FixtureGuestCardSyncEngine extends SyncEngine
{
    public function __construct(
        KintClient $kint,
        B24Client $b24,
        Store $store,
        private array $cards
    ) {
        parent::__construct($kint, $b24, $store);
    }

    protected function processGuestCardsForBooking(string $kintBookingId, int $dealId): int
    {
        return $this->applyGuestCards($this->cards, $dealId);
    }
}

class MultipleInvoiceKintClient extends MockKintClient
{
    public function getPaged(string $method, array $params = [], string $itemsKey = '', int $pageSize = 100): array
    {
        if ($method === 'GetInvoices') {
            $this->calls[] = ['method' => $method, 'params' => $params, 'itemsKey' => $itemsKey];
            return [
                ['Идентификатор' => 'inv-1', 'СуммаДокумента' => 1],
                ['Идентификатор' => 'inv-2', 'СуммаДокумента' => 2],
            ];
        }
        return parent::getPaged($method, $params, $itemsKey, $pageSize);
    }
}

// ── Test: FieldMapper ─────────────────────────────────────────────────────────

echo "\n--- FieldMapper ---\n";

$dealFields = FieldMapper::bookingToDealFields($BOOKING);
assert_eq('deal TITLE set',          $dealFields['TITLE'],          'Бронь №БР-2024-001');
assert_eq('deal stage PAID',         $dealFields['STAGE_ID'],        'PAID');
assert_eq('deal UF_KINT_BOOKING_ID', $dealFields['UF_KINT_BOOKING_ID'], 'kint-booking-001');
assert_eq('deal guests',             $dealFields['UF_KINT_GUESTS'],  2);
assert_eq('deal date_from',          $dealFields['UF_KINT_DATE_FROM'], '2024-09-01');

$invoiceFields = FieldMapper::invoiceFields($INVOICE);
assert_eq('invoice amount',          $invoiceFields['UF_KINT_INVOICE_AMOUNT'], 95000.0);
assert_eq('invoice number',          $invoiceFields['UF_KINT_INVOICE_NUMBER'], 'СЧ-2024-001');

$payFields = FieldMapper::paymentFields($PAYMENT);
assert_eq('payment status',          $payFields['UF_KINT_PAYMENT_STATUS'], 'Оплачено');
assert_eq('paid total',              $payFields['UF_KINT_PAID_TOTAL'], 95000.0);

$gcFields = FieldMapper::guestCardFields($GUEST_CARD);
assert_eq('gc number',               $gcFields['UF_KINT_GC_NUMBER'], 'КГ-2024-001');
assert_eq('gc room',                 $gcFields['UF_KINT_GC_ROOM'], '215');

$contactFields = FieldMapper::contactFields($BOOKING['Гость']);
assert_eq('contact last name',       $contactFields['LAST_NAME'], 'Иванов');
assert_eq('contact first name',      $contactFields['NAME'],      'Иван');
assert_eq('contact phone',           $contactFields['PHONE'][0]['VALUE'], '+12025550123');

assert_eq('stageId Оплачено', FieldMapper::stageId('Оплачено'), 'PAID');
assert_eq('stageId Заезд',    FieldMapper::stageId('Заезд'),    'CHECKED_IN');
assert_eq('stageId unknown',  FieldMapper::stageId('???'),      null);

assert_eq('isoDate dot format', FieldMapper::isoDate('15.03.1985'), '1985-03-15');
assert_eq('isoDate null',       FieldMapper::isoDate(null),          null);
assert_eq('isoDate empty',      FieldMapper::isoDate(''),             null);

// ── Test: SyncEngine idempotency ──────────────────────────────────────────────

echo "\n--- SyncEngine: first run ---\n";

$kint   = new MockKintClient($BOOKING, $INVOICE, $PAYMENT, $GUEST_CARD);
$b24    = new MockB24Client();
$store  = new Store(':memory:');
$engine = new SyncEngine($kint, $b24, $store, 30);

$engine->syncBookings();

// Deal should have been created
$dealId = $store->getMapped('kint-booking-001', 'deal');
assert_not_null('deal mapped after first run', $dealId);

// Contact should have been created
$contactId = $store->getMapped('kint-guest-001', 'contact');
assert_not_null('contact mapped after first run', $contactId);

// Count crm.deal.add calls = exactly 1
$addCalls = array_filter($b24->calls, fn($c) => $c['method'] === 'crm.deal.add');
assert_eq('crm.deal.add called once', count($addCalls), 1);

$addContactCalls = array_filter($b24->calls, fn($c) => $c['method'] === 'crm.contact.add');
assert_eq('crm.contact.add called once', count($addContactCalls), 1);

// Correct stage set in the create payload; no duplicate stage update.
$stageCall = array_values($addCalls)[0] ?? null;
assert_eq('stage PAID set', $stageCall['params']['fields']['STAGE_ID'] ?? null, 'PAID');
assert_eq('stage is not sent in duplicate update', count(array_filter($b24->calls, fn($c) =>
    $c['method'] === 'crm.deal.update' && array_key_exists('STAGE_ID', $c['params']['fields'] ?? [])
)), 0);

echo "\n--- SyncEngine: second run (idempotency) ---\n";

$b24Second  = new MockB24Client();
$engine2    = new SyncEngine($kint, $b24Second, $store, 30);
$engine2->syncBookings();

$addCalls2 = array_filter($b24Second->calls, fn($c) => $c['method'] === 'crm.deal.add');
assert_eq('crm.deal.add NOT called on second run', count($addCalls2), 0);

$addContact2 = array_filter($b24Second->calls, fn($c) => $c['method'] === 'crm.contact.add');
assert_eq('crm.contact.add NOT called on second run', count($addContact2), 0);

// Deal ID must be same
$dealId2 = $store->getMapped('kint-booking-001', 'deal');
assert_eq('deal ID same on second run', $dealId2, $dealId);

echo "\n--- SyncEngine: invoices + payments + guest cards ---\n";

$engine->syncInvoices();
assert_not_null('invoice mapped', $store->getMapped('kint-invoice-001', 'invoice'));

$engine->syncPayments();
assert_not_null('payment mapped', $store->getMapped('kint-pay-001', 'payment'));

$kint->calls = [];
assert_eq('production guestcard sync fails closed', $engine->syncGuestCards(), 1);
assert_null('production guestcard sync does not map guessed relation', $store->getMapped('kint-gc-001', 'guestcard'));
assert_eq('production guestcard sync makes no KINT card call', count(array_filter($kint->calls, fn($c) => $c['method'] === 'КартыГостя')), 0);

// ── Test: GuestCard idempotency gate ─────────────────────────────────────────

echo "\n--- GuestCard idempotency ---\n";

// Fresh environment: booking synced, then two guest card ticks with same data
$gcStore  = new Store(':memory:');
$gcKint   = new MockKintClient($BOOKING, $INVOICE, $PAYMENT, $GUEST_CARD);
$gcB24    = new MockB24Client();
$gcEngine = new FixtureGuestCardSyncEngine($gcKint, $gcB24, $gcStore, [$GUEST_CARD]);

// Sync bookings first so getMappedDeals() returns the deal
$gcEngine->syncBookings();
$gcB24->calls = [];  // reset — only count guest card calls

// (a) First tick — should call crm.deal.update once
$gcEngine->syncGuestCards();
$gcUpdates1 = array_filter($gcB24->calls, fn($c) => $c['method'] === 'crm.deal.update');
assert_eq('guestcard first tick: crm.deal.update called once', count($gcUpdates1), 1);

$gcB24->calls = [];

// (a) Second tick — same data — must NOT call crm.deal.update
$gcEngine->syncGuestCards();
$gcUpdates2 = array_filter($gcB24->calls, fn($c) => $c['method'] === 'crm.deal.update');
assert_eq('guestcard second tick same data: crm.deal.update NOT called', count($gcUpdates2), 0);

// (b) Changed data — must call crm.deal.update
$GUEST_CARD_CHANGED = array_merge($GUEST_CARD, ['НомерКомнаты' => '216']);
$gcKint2   = new MockKintClient($BOOKING, $INVOICE, $PAYMENT, $GUEST_CARD_CHANGED);
$gcEngine2 = new FixtureGuestCardSyncEngine($gcKint2, $gcB24, $gcStore, [$GUEST_CARD_CHANGED]);
$gcB24->calls = [];
$gcEngine2->syncGuestCards();
$gcUpdates3 = array_filter($gcB24->calls, fn($c) => $c['method'] === 'crm.deal.update');
assert_eq('guestcard changed data: crm.deal.update called', count($gcUpdates3), 1);

// ── Test: GC_DEPARTURE fallback chain ────────────────────────────────────────

echo "\n--- FieldMapper: GC_DEPARTURE fallback ---\n";

// Primary: ДатаВыезда wins over ДатаВыбытия
$cardBoth = ['Идентификатор' => 'gc-both', 'ДатаВыезда' => '2024-09-15', 'ДатаВыбытия' => '2024-09-10'];
$fieldsBoth = FieldMapper::guestCardFields($cardBoth);
assert_eq('GC_DEPARTURE primary ДатаВыезда', $fieldsBoth['UF_KINT_GC_DEPARTURE'], '2024-09-15');

// Fallback: only ДатаВыбытия present
$cardFallback = ['Идентификатор' => 'gc-fall', 'ДатаВыбытия' => '2024-09-10'];
$fieldsFallback = FieldMapper::guestCardFields($cardFallback);
assert_eq('GC_DEPARTURE fallback ДатаВыбытия', $fieldsFallback['UF_KINT_GC_DEPARTURE'], '2024-09-10');

// ── Regression tests: mutable sync data and safe identity ───────────────────

echo "\n--- Regression: explicit zero values ---\n";

$zeroInvoice = FieldMapper::invoiceFields(['Идентификатор' => 'inv-zero', 'Сумма' => 0]);
assert_eq('invoice explicit zero retained', $zeroInvoice['UF_KINT_INVOICE_AMOUNT'] ?? null, 0.0);
$missingInvoice = FieldMapper::invoiceFields(['Идентификатор' => 'inv-missing']);
assert_eq('invoice absent amount omitted', array_key_exists('UF_KINT_INVOICE_AMOUNT', $missingInvoice), false);

$zeroPayment = FieldMapper::paymentFields([
    'Идентификатор' => 'pay-zero',
    'СуммаПлатежа' => 0,
    'ИтогоНачислено' => 0,
    'ИтогоОплачено' => 0,
]);
assert_eq('payment explicit paid amount zero retained', $zeroPayment['UF_KINT_PAID_AMOUNT'] ?? null, 0.0);
assert_eq('payment explicit invoiced total zero retained', $zeroPayment['UF_KINT_INVOICED_TOTAL'] ?? null, 0.0);
assert_eq('payment explicit paid total zero retained', $zeroPayment['UF_KINT_PAID_TOTAL'] ?? null, 0.0);
$missingPayment = FieldMapper::paymentFields(['Идентификатор' => 'pay-missing']);
assert_eq('payment absent paid amount omitted', array_key_exists('UF_KINT_PAID_AMOUNT', $missingPayment), false);

echo "\n--- Regression: same IDs update when payload changes ---\n";

$mutableStore = new Store(':memory:');
$mutableStore->saveMapping('kint-booking-001', 'deal', 777);
$mutableB24 = new MockB24Client();
$mutableKint = new MockKintClient($BOOKING, $INVOICE, $PAYMENT, $GUEST_CARD);
(new SyncEngine($mutableKint, $mutableB24, $mutableStore))->syncInvoices();
$mutableB24->calls = [];
$changedInvoice = array_merge($INVOICE, ['Сумма' => 123.0]);
(new SyncEngine(new MockKintClient($BOOKING, $changedInvoice, $PAYMENT, $GUEST_CARD), $mutableB24, $mutableStore))->syncInvoices();
$invoiceUpdates = array_values(array_filter($mutableB24->calls, fn($c) => $c['method'] === 'crm.deal.update'));
assert_eq('same invoice ID changed amount updates deal', $invoiceUpdates[0]['params']['fields']['UF_KINT_INVOICE_AMOUNT'] ?? null, 123.0);

$mutableB24->calls = [];
(new SyncEngine($mutableKint, $mutableB24, $mutableStore))->syncPayments();
$mutableB24->calls = [];
$changedPayment = array_merge($PAYMENT, ['ИтогоОплачено' => 321.0]);
(new SyncEngine(new MockKintClient($BOOKING, $INVOICE, $changedPayment, $GUEST_CARD), $mutableB24, $mutableStore))->syncPayments();
$paymentUpdates = array_values(array_filter($mutableB24->calls, fn($c) => $c['method'] === 'crm.deal.update'));
assert_eq('same payment ID changed total updates deal', $paymentUpdates[0]['params']['fields']['UF_KINT_PAID_TOTAL'] ?? null, 321.0);

echo "\n--- Regression: guest card A-B-A update ---\n";

$gcB24->calls = [];
(new FixtureGuestCardSyncEngine($gcKint, $gcB24, $gcStore, [$GUEST_CARD]))->syncGuestCards();
$gcUpdates4 = array_values(array_filter($gcB24->calls, fn($c) => $c['method'] === 'crm.deal.update'));
assert_eq('guestcard changed back to earlier payload updates', $gcUpdates4[0]['params']['fields']['UF_KINT_GC_ROOM'] ?? null, '215');

echo "\n--- Regression: contact and deal reconciliation ---\n";

$identityStore = new Store(':memory:');
$identityB24 = new MockB24Client();
$identityB24->responses['crm.contact.list'] = [['ID' => 501]];
$identityB24->responses['crm.deal.list'] = [['ID' => 601]];
$identityEngine = new SyncEngine($kint, $identityB24, $identityStore);
assert_eq('reconciled booking reports no failures', $identityEngine->syncBookings(), 0);
assert_eq('contact external ID mapped', $identityStore->getMapped('kint-guest-001', 'contact'), 501);
assert_eq('deal external ID mapped', $identityStore->getMapped('kint-booking-001', 'deal'), 601);
$identityContactSearch = array_values(array_filter($identityB24->calls, fn($c) => $c['method'] === 'crm.contact.list'))[0] ?? null;
assert_eq('contact searched by external guest ID', $identityContactSearch['params']['filter'] ?? null, ['UF_KINT_GUEST_ID' => 'kint-guest-001']);
assert_eq('matched contact updated', count(array_filter($identityB24->calls, fn($c) => $c['method'] === 'crm.contact.update')), 1);
assert_eq('reconciled deal not created', count(array_filter($identityB24->calls, fn($c) => $c['method'] === 'crm.deal.add')), 0);

$mappedContactStore = new Store(':memory:');
$mappedContactStore->saveMapping('kint-guest-001', 'contact', 701);
$mappedContactB24 = new MockB24Client();
(new SyncEngine($kint, $mappedContactB24, $mappedContactStore))->syncBookings();
assert_eq('mapped contact fields updated', count(array_filter($mappedContactB24->calls, fn($c) => $c['method'] === 'crm.contact.update')), 1);

$homonymBooking = $BOOKING;
unset($homonymBooking['Гость']['Телефон'], $homonymBooking['Гость']['Email']);
$homonymB24 = new MockB24Client();
(new SyncEngine(new MockKintClient($homonymBooking, $INVOICE, $PAYMENT, $GUEST_CARD), $homonymB24, new Store(':memory:')))->syncBookings();
$homonymSearches = array_values(array_filter($homonymB24->calls, fn($c) => $c['method'] === 'crm.contact.list'));
assert_eq('contact without phone never searched by name', count(array_filter($homonymSearches, fn($c) => isset($c['params']['filter']['NAME']))), 0);

$phoneB24 = new MockB24Client();
(new SyncEngine($kint, $phoneB24, new Store(':memory:')))->syncBookings();
assert_eq('contact lookup uses only immutable guest ID', count(array_filter($phoneB24->calls, fn($c) => $c['method'] === 'crm.contact.list')), 1);

echo "\n--- Regression: failure aggregation ---\n";

$failureB24 = new MockB24Client();
$failureB24->failMethods = ['crm.deal.add'];
$bookingWithoutGuest = $BOOKING;
unset($bookingWithoutGuest['Гость']);
$failureEngine = new SyncEngine(new MockKintClient($bookingWithoutGuest, $INVOICE, $PAYMENT, $GUEST_CARD), $failureB24, new Store(':memory:'));
assert_eq('booking item failure returned to runner', $failureEngine->syncBookings(), 1);
assert_eq('catalog unfinished reports failure', $failureEngine->syncCatalog(), 1);

echo "\n--- Regression: safe invoice read contract ---\n";

$invoiceContractStore = new Store(':memory:');
$invoiceContractStore->saveMapping('kint-booking-001', 'deal', 801);
$invoiceContractKint = new MockKintClient(
    $BOOKING,
    ['Идентификатор' => 'inv-contract', 'СуммаДокумента' => 456.0],
    $PAYMENT,
    $GUEST_CARD
);
$invoiceContractB24 = new MockB24Client();
(new SyncEngine($invoiceContractKint, $invoiceContractB24, $invoiceContractStore))->syncInvoices();
$invoiceRead = array_values(array_filter($invoiceContractKint->calls, fn($c) => $c['method'] === 'GetInvoices'))[0] ?? null;
assert_eq('invoices use read-only GetInvoices', $invoiceRead !== null, true);
assert_eq('invoices filter by booking basis ID', $invoiceRead['params']['Основание'] ?? null, ['ID' => 'kint-booking-001']);
assert_eq('invoices request documented amount field as string', $invoiceRead['params']['Fields'] ?? null, 'СуммаДокумента');
$invoiceContractUpdate = array_values(array_filter($invoiceContractB24->calls, fn($c) => $c['method'] === 'crm.deal.update'))[0] ?? null;
assert_eq('invoice maps documented amount field', $invoiceContractUpdate['params']['fields']['UF_KINT_INVOICE_AMOUNT'] ?? null, 456.0);

echo "\n--- Regression: official booking and payment shapes ---\n";

$officialBooking = [
    'ID' => 'booking-official-1',
    'Number' => 'OFF-1',
    'Status' => ['Name' => 'Принята'],
    'Sanatorium' => ['Name' => 'Санаторий официальный'],
    'Booked' => 0,
    'ToProcess' => 42,
    'Date' => '2024-01-02',
];
$officialKint = new MockKintClient($officialBooking, $INVOICE, $PAYMENT, $GUEST_CARD);
$officialB24 = new MockB24Client();
assert_eq('official booking sync succeeds', (new SyncEngine($officialKint, $officialB24, new Store(':memory:')))->syncBookings(), 0);
$bookingRead = array_values(array_filter($officialKint->calls, fn($c) => $c['method'] === 'GetBookingList'))[0] ?? null;
assert_eq('booking uses НачалоПериода', isset($bookingRead['params']['НачалоПериода']), true);
assert_eq('booking uses КонецПериода', isset($bookingRead['params']['КонецПериода']), true);
assert_eq('booking result is direct list', $bookingRead['itemsKey'] ?? null, '');
$officialDeal = array_values(array_filter($officialB24->calls, fn($c) => $c['method'] === 'crm.deal.add'))[0] ?? null;
$officialFields = $officialDeal['params']['fields'] ?? [];
assert_eq('official booking ID mapped', $officialFields['UF_KINT_BOOKING_ID'] ?? null, 'booking-official-1');
assert_eq('official booking number mapped', $officialFields['TITLE'] ?? null, 'Бронь №OFF-1');
assert_eq('official sanatorium name mapped', $officialFields['UF_KINT_SANATORIUM'] ?? null, 'Санаторий официальный');
assert_eq('official booked zero mapped', $officialFields['UF_KINT_BOOKED'] ?? null, 0);
assert_eq('official to-process mapped', $officialFields['UF_KINT_TO_PROCESS'] ?? null, 42);
assert_eq('generic document Date is not used as stay start', array_key_exists('BEGINDATE', $officialFields), false);
assert_eq('unknown booking status does not reset deal stage', array_key_exists('STAGE_ID', $officialFields), false);
assert_eq('unknown stage has no mapping', FieldMapper::stageId('Принята'), null);

$officialPayment = ['Идентификатор' => 'payment-official', 'Выставлено' => 1000, 'Оплачено' => 250];
$officialPaymentFields = FieldMapper::paymentFields($officialPayment);
assert_eq('payment Выставлено mapped', $officialPaymentFields['UF_KINT_INVOICED_TOTAL'] ?? null, 1000.0);
assert_eq('payment Оплачено mapped', $officialPaymentFields['UF_KINT_PAID_TOTAL'] ?? null, 250.0);
$paymentContractStore = new Store(':memory:');
$paymentContractStore->saveMapping('kint-booking-001', 'deal', 901);
$paymentContractKint = new MockKintClient($BOOKING, $INVOICE, $officialPayment, $GUEST_CARD);
(new SyncEngine($paymentContractKint, new MockB24Client(), $paymentContractStore))->syncPayments();
$paymentRead = array_values(array_filter($paymentContractKint->calls, fn($c) => $c['method'] === 'GetData'))[0] ?? null;
assert_eq('payment document is ID reference', $paymentRead['params']['Document'] ?? null, ['ID' => 'kint-booking-001']);

// Schema drift must fail visibly rather than report an empty successful sync.
$badStore = new Store(':memory:');
$badStore->saveMapping('b', 'deal', 1);
$badKint = new MockKintClient($BOOKING, ['unexpected' => 'invoice'], ['unexpected' => 'payment'], $GUEST_CARD);
$badEngine = new SyncEngine($badKint, new MockB24Client(), $badStore);
assert_eq('malformed invoice reports failure', $badEngine->syncInvoices(), 1);
assert_eq('malformed payment reports failure', $badEngine->syncPayments(), 1);
$emptyPaymentStore = new Store(':memory:');
$emptyPaymentStore->saveMapping('b', 'deal', 1);
assert_eq('empty payment reports failure', (new SyncEngine(
    new MockKintClient($BOOKING, $INVOICE, [], $GUEST_CARD),
    new MockB24Client(),
    $emptyPaymentStore
))->syncPayments(), 1);
$idOnlyPaymentStore = new Store(':memory:');
$idOnlyPaymentStore->saveMapping('b', 'deal', 1);
assert_eq('payment ID without money or status reports failure', (new SyncEngine(
    new MockKintClient($BOOKING, $INVOICE, ['Идентификатор' => 'pay-only-id'], $GUEST_CARD),
    new MockB24Client(),
    $idOnlyPaymentStore
))->syncPayments(), 1);

echo "\n--- Regression: fail-closed ambiguous results ---\n";

$duplicateContactStore = new Store(':memory:');
$duplicateContactB24 = new MockB24Client();
$duplicateContactB24->responses['crm.contact.list'] = [['ID' => 11], ['ID' => 12]];
assert_eq('duplicate contacts report failure', (new SyncEngine($kint, $duplicateContactB24, $duplicateContactStore))->syncBookings(), 1);
assert_null('duplicate contacts are not mapped', $duplicateContactStore->getMapped('kint-guest-001', 'contact'));
assert_eq('duplicate contacts cause no B24 write', count(array_filter($duplicateContactB24->calls, fn($c) => !str_ends_with($c['method'], '.list'))), 0);

$duplicateDealBooking = $BOOKING;
$duplicateDealStore = new Store(':memory:');
$duplicateDealB24 = new MockB24Client();
$duplicateDealB24->responses['crm.deal.list'] = [['ID' => 21], ['ID' => 22]];
assert_eq('duplicate deals report failure', (new SyncEngine(new MockKintClient($duplicateDealBooking, $INVOICE, $PAYMENT, $GUEST_CARD), $duplicateDealB24, $duplicateDealStore))->syncBookings(), 1);
assert_null('duplicate deals are not mapped', $duplicateDealStore->getMapped('kint-booking-001', 'deal'));
assert_eq('duplicate deals cause no B24 write', count(array_filter($duplicateDealB24->calls, fn($c) => !str_ends_with($c['method'], '.list'))), 0);

$malformedDealStore = new Store(':memory:');
$malformedDealB24 = new MockB24Client();
$malformedDealB24->responses['crm.deal.list'] = [['ID' => 0]];
assert_eq('malformed deal ID reports failure', (new SyncEngine(new MockKintClient($duplicateDealBooking, $INVOICE, $PAYMENT, $GUEST_CARD), $malformedDealB24, $malformedDealStore))->syncBookings(), 1);
assert_null('malformed deal ID is not mapped', $malformedDealStore->getMapped('kint-booking-001', 'deal'));

$multipleInvoiceStore = new Store(':memory:');
$multipleInvoiceStore->saveMapping('kint-booking-001', 'deal', 1001);
$multipleInvoiceB24 = new MockB24Client();
$multipleInvoiceKint = new MultipleInvoiceKintClient($BOOKING, $INVOICE, $PAYMENT, $GUEST_CARD);
assert_eq('multiple invoices report failure', (new SyncEngine($multipleInvoiceKint, $multipleInvoiceB24, $multipleInvoiceStore))->syncInvoices(), 1);
assert_eq('multiple invoices cause no B24 write', count($multipleInvoiceB24->calls), 0);

$multipleCardStore = new Store(':memory:');
$multipleCardStore->saveMapping('kint-booking-001', 'deal', 1002);
$multipleCardB24 = new MockB24Client();
$multipleCards = [$GUEST_CARD, array_merge($GUEST_CARD, ['Идентификатор' => 'kint-gc-002'])];
$multipleCardEngine = new FixtureGuestCardSyncEngine($kint, $multipleCardB24, $multipleCardStore, $multipleCards);
assert_eq('multiple guest cards report failure', $multipleCardEngine->syncGuestCards(), 1);
assert_eq('multiple guest cards cause no B24 write', count($multipleCardB24->calls), 0);

foreach ([['Идентификатор'=>'bad-amount','СуммаДокумента'=>'invalid'], ['Идентификатор'=>'missing-amount']] as $invalidInvoice) {
    $invalidStore = new Store(':memory:');
    $invalidStore->saveMapping('b', 'deal', 1);
    $invalidB24 = new MockB24Client();
    $invalidEngine = new SyncEngine(new MockKintClient($BOOKING, $invalidInvoice, $PAYMENT, $GUEST_CARD), $invalidB24, $invalidStore);
    assert_eq('invalid invoice amount reports failure', $invalidEngine->syncInvoices(), 1);
    assert_eq('invalid invoice amount causes no B24 writes', count($invalidB24->calls), 0);
}

// ── Results ───────────────────────────────────────────────────────────────────

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";

if ($fail > 0) {
    echo "FAILED:\n";
    foreach ($fails as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "ALL GREEN\n";
exit(0);
