<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
require dirname(__DIR__) . '/autoload.php';
$APPLICATION->SetTitle("КИНТ → Битрикс24 | MVP интеграции");

define('KINT_URL',  'REDACTED_KINT_URL');
define('KINT_USER', 'REDACTED_KINT_USER');
define('KINT_PASS', 'REDACTED_KINT_PASS');

$dateFrom = $_REQUEST['dateFrom'] ?? date('01.m.Y');
$dateTo   = $_REQUEST['dateTo']   ?? date('t.m.Y');

/* ═══════════════════════════════════════════════════════════════════════════
 * MODULE 1: KintApiClient — HTTP-клиент для KINT API
 * ═══════════════════════════════════════════════════════════════════════════ */

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
            return ['_error' => $err, '_method' => $method];
        }

        $this->rawResponses[$method] = ['http_code' => $code, 'body' => mb_substr($body, 0, 2000)];
        $data = json_decode($body, true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = "GET {$method}: JSON decode error — " . json_last_error_msg();
            return ['_http_code' => $code, '_raw' => mb_substr($body, 0, 500)];
        }

        if (is_array($data) && isset($data['Success']) && $data['Success'] === false) {
            $errKey = "GET {$method}";
            if (!isset($this->errors[$errKey])) {
                $this->errors[$errKey] = "{$errKey}: API error — " . ($data['Error'] ?? ($data['Result']['Error'] ?? 'unknown'));
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
 * MODULE 2: DataFetcher — получение данных из КИНТ по сценариям ТЗ
 * ═══════════════════════════════════════════════════════════════════════════ */

class DataFetcher
{
    private KintApiClient $api;

    public function __construct(KintApiClient $api)
    {
        $this->api = $api;
    }

    public function fetchBookings(string $dateFrom, string $dateTo): array
    {
        $result = $this->api->get('GetBookingList', [
            'НачалоПериода' => $dateFrom,
            'КонецПериода'  => $dateTo,
            'Fields'        => 'Контрагент,Стоимость,Договор,Прайс,Гости,КатегорияНомера',
        ]);

        if (!is_array($result) || isset($result['_error'])) {
            $catalogResult = $this->api->get('GetCatalog', [
                'Вид'  => 'ЗаявкаПокупателя',
                'Тип'  => 'Документ',
                'CountOnPage' => 50,
            ]);
            return is_array($catalogResult) ? $catalogResult : [];
        }

        return $result;
    }

    public function fetchBookingStatus(array $bookingIds): array
    {
        if (empty($bookingIds)) return [];
        $result = $this->api->get('GetBookingStatus', [
            'Booking' => $bookingIds,
        ]);
        return is_array($result) ? $result : [];
    }

    public function fetchInvoices(string $basisRef = null, string $clientRef = null): array
    {
        $params = [];
        if ($clientRef) $params['Контрагент'] = json_encode(['ID' => $clientRef], JSON_UNESCAPED_UNICODE);
        if ($basisRef)  $params['Основание']  = json_encode(['Идентификатор' => $basisRef], JSON_UNESCAPED_UNICODE);
        $result = $this->api->get('GetInvoices', $params);
        return is_array($result) ? $result : [];
    }

    public function fetchPaymentQRCode(string $invoiceRef, string $type = 'СБП'): ?array
    {
        if (!$invoiceRef) return null;

        // MOCK: GetPaymentQRCode не вызывается — метод «формирует» QR,
        // что может зарегистрировать платёжную ссылку в СБП/эквайринге.
        // Возвращаем мок-данные из документации.
        if ($type === 'СБП') {
            return [
                'СБП'   => 'REDACTED_SBP_URL',
                '_mock' => true,
            ];
        }
        return [
            'Обычный' => 'iVBORw0KGgoAAAANSUhEUgAAASwAAAEsAQAAAABRBrPYAAAB...',
            '_mock'   => true,
        ];
    }

    public function fetchAcceptances(string $invoiceRef = null, string $clientRef = null): array
    {
        $params = [];
        if ($clientRef)  $params['Контрагент'] = json_encode(['ID' => $clientRef], JSON_UNESCAPED_UNICODE);
        if ($invoiceRef) $params['Счет']       = json_encode(['Идентификатор' => $invoiceRef], JSON_UNESCAPED_UNICODE);
        $result = $this->api->get('GetAcceptances', $params);
        if (!is_array($result) || (isset($result['Success']) && $result['Success'] === false)) {
            return [];
        }
        return is_array($result) ? $result : [];
    }

    public function fetchPaymentStatus(string $documentRef): ?array
    {
        if (!$documentRef) return null;
        $result = $this->api->get('GetData', [
            'Method'   => 'PaymentStatusByDocument',
            'Document' => json_encode(['Идентификатор' => $documentRef], JSON_UNESCAPED_UNICODE),
        ]);
        return is_array($result) ? $result : null;
    }

    public function fetchGuestCards(string $personRef): array
    {
        if (!$personRef) return [];
        $result = $this->api->get('КартыГостя', [
            'ФизЛицо' => ['Идентификатор' => $personRef],
        ]);
        if (!is_array($result) || (isset($result['Success']) && $result['Success'] === false)) {
            return [];
        }
        return $result;
    }

    public function fetchGuestData(string $personRef): ?array
    {
        if (!$personRef) return null;
        $result = $this->api->get('GetGuestData', [
            'ФизЛицо' => $personRef,
        ]);
        return is_array($result) ? $result : null;
    }

    public function fetchServiceAssignments(string $guestCardRef, string $dateFrom, string $dateTo): array
    {
        if (!$guestCardRef) return [];
        return ['_error' => 'BLOCKED_KINT_READ_ONLY', '_method' => 'НазначенияИРезультаты'];
    }

    public function fetchServicesCatalog(): array
    {
        $result = $this->api->get('GetCatalog', [
            'Вид' => 'Услуги',
            'CountOnPage' => 100,
        ]);
        return is_array($result) ? $result : [];
    }

    public function fetchGuestCardsCatalog(): array
    {
        $result = $this->api->get('GetCatalog', [
            'Вид'  => 'КартаГостя',
            'Тип'  => 'Документ',
            'CountOnPage' => 50,
        ]);
        return is_array($result) ? $result : [];
    }

    public function fetchGuestCardsByClient(string $clientRef): array
    {
        if (!$clientRef) return [];
        $result = $this->api->get('GetCatalog', [
            'CatalogName'   => 'КартаГостя',
            'CatalogType'   => 'Документ',
            'Filter'        => json_encode(['Контрагент' => ['ID' => $clientRef]], JSON_UNESCAPED_UNICODE),
            'Fields'        => 'Ссылка,Дата,Номер,ФизЛицо,Контрагент,НомерГостиницы,ДатаЗаезда,ДатаВыезда,ВариантПроживания,ВариантЛечения,ВариантПитания',
            'CountOnPage'   => 10,
        ]);
        return is_array($result) ? $result : [];
    }

    public function fetchVouchers(): array
    {
        $result = $this->api->get('GetCatalog', [
            'CatalogName'   => 'Путевка',
            'CatalogType'   => 'Документ',
            'Fields'        => 'Дата,Номер,ДатаЗаезда,ДатаВыезда,КоличествоДней,КоличествоЧеловек,Контрагент,Стоимость,Договор,КатегорияПутевки',
            'CountOnPage'   => 50,
        ]);
        return is_array($result) ? $result : [];
    }

    public function fetchInvoicesCatalog(): array
    {
        $result = $this->api->get('GetCatalog', [
            'CatalogName'   => 'СчетНаОплатуПокупателю',
            'CatalogType'   => 'Документ',
            'Fields'        => 'Ссылка,Дата,Номер,СуммаДокумента,Контрагент,Основание',
            'CountOnPage'   => 50,
        ]);
        return is_array($result) ? $result : [];
    }

    public function fetchRelatedDocuments(string $documentRef, string $docType = null): array
    {
        if (!$documentRef) return [];
        $params = ['Документ' => $documentRef];
        if ($docType) $params['ВидДокумента'] = $docType;
        $result = $this->api->get('RelatedDocuments', $params);
        return is_array($result) ? $result : [];
    }

    public function fetchAvailableRooms(string $dateFrom, string $dateTo): array
    {
        $result = $this->api->get('GetAvailableRooms', [
            'DateFrom' => $dateFrom,
            'DateTo'   => $dateTo,
            'Vacant'   => 'true',
        ]);
        return is_array($result) ? $result : [];
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * MODULE 3: FieldMapper — маппинг полей КИНТ → Битрикс24
 * ═══════════════════════════════════════════════════════════════════════════ */

class FieldMapper
{
    public static function bookingToDealMapping(): array
    {
        return [
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'ID',             'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_BOOKING_ID',   'b24_type' => 'Строка',    'desc' => 'ID брони КИНТ для связки'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'Number',         'b24_entity' => 'Deal', 'b24_field' => 'TITLE',                'b24_type' => 'Строка',    'desc' => 'Номер брони → заголовок сделки'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'Date',           'b24_entity' => 'Deal', 'b24_field' => 'BEGINDATE',            'b24_type' => 'Дата',      'desc' => 'Дата брони → дата начала'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'Status',         'b24_entity' => 'Deal', 'b24_field' => 'STAGE_ID',             'b24_type' => 'Список',    'desc' => 'Статус брони → стадия сделки'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'Sanatorium',     'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_SANATORIUM',   'b24_type' => 'Строка',    'desc' => 'Наименование санатория'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'Контрагент',     'b24_entity' => 'Deal', 'b24_field' => 'CONTACT_ID',           'b24_type' => 'Привязка',  'desc' => 'Контрагент → контакт Б24'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'Гости',          'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_GUESTS',       'b24_type' => 'Строка',    'desc' => 'Список гостей'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'DateFrom',       'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_DATE_FROM',    'b24_type' => 'Дата',      'desc' => 'Дата заезда'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'DateTo',         'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_DATE_TO',      'b24_type' => 'Дата',      'desc' => 'Дата выезда'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'Room',           'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_ROOM',         'b24_type' => 'Строка',    'desc' => 'Номер гостиницы'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'КатегорияНомера','b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_ROOM_CATEGORY', 'b24_type' => 'Строка',    'desc' => 'Категория номера'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'Booked',         'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_BOOKED',       'b24_type' => 'Да/Нет',    'desc' => 'Предварительно забронировано'],
            ['kint_entity' => 'ЗаявкаПокупателя', 'kint_field' => 'ToProcess',      'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_TO_PROCESS',   'b24_type' => 'Число',     'desc' => 'Осталось обработать'],
        ];
    }

    public static function invoiceToDealMapping(): array
    {
        return [
            ['kint_entity' => 'СчетНаОплату', 'kint_field' => 'Идентификатор',  'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_INVOICE_ID',     'b24_type' => 'Строка',  'desc' => 'ID счёта КИНТ'],
            ['kint_entity' => 'СчетНаОплату', 'kint_field' => 'Номер',          'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_INVOICE_NUMBER', 'b24_type' => 'Строка',  'desc' => 'Номер счёта'],
            ['kint_entity' => 'СчетНаОплату', 'kint_field' => 'Дата',           'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_INVOICE_DATE',   'b24_type' => 'Дата',    'desc' => 'Дата счёта'],
            ['kint_entity' => 'СчетНаОплату', 'kint_field' => 'СуммаДокумента', 'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_INVOICE_AMOUNT', 'b24_type' => 'Число',   'desc' => 'Сумма счёта'],
            ['kint_entity' => 'PaymentQRCode','kint_field' => 'СБП (URL)',      'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_PAYMENT_LINK',   'b24_type' => 'Ссылка',  'desc' => 'Ссылка на оплату СБП'],
            ['kint_entity' => 'PaymentQRCode','kint_field' => 'Обычный (Base64)','b24_entity'=> 'Deal', 'b24_field' => 'UF_KINT_PAYMENT_QR',     'b24_type' => 'Файл',    'desc' => 'QR-код (PNG Base64)'],
        ];
    }

    public static function paymentToDealMapping(): array
    {
        return [
            ['kint_entity' => 'ПриемПлатежей',      'kint_field' => 'Идентификатор',              'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_PAYMENT_ID',     'b24_type' => 'Строка',  'desc' => 'ID платежа КИНТ'],
            ['kint_entity' => 'ПриемПлатежей',      'kint_field' => 'Номер',                      'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_PAYMENT_NUMBER', 'b24_type' => 'Строка',  'desc' => 'Номер платежа'],
            ['kint_entity' => 'ПриемПлатежей',      'kint_field' => 'Дата',                       'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_PAYMENT_DATE',   'b24_type' => 'Дата',    'desc' => 'Дата платежа'],
            ['kint_entity' => 'ПриемПлатежей',      'kint_field' => 'СуммаДокументаБезСкидки',    'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_PAID_AMOUNT',    'b24_type' => 'Число',   'desc' => 'Сумма оплаты'],
            ['kint_entity' => 'PaymentStatus',      'kint_field' => 'Выставлено',                 'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_INVOICED_TOTAL', 'b24_type' => 'Число',   'desc' => 'Всего выставлено'],
            ['kint_entity' => 'PaymentStatus',      'kint_field' => 'Оплачено',                   'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_PAID_TOTAL',     'b24_type' => 'Число',   'desc' => 'Всего оплачено'],
            ['kint_entity' => 'PaymentStatus (calc)','kint_field' => 'IsFullyPaid',              'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_PAYMENT_STATUS', 'b24_type' => 'Список',  'desc' => 'Статус: Не оплачено / Частично / Полностью'],
        ];
    }

    public static function guestCardToDealMapping(): array
    {
        return [
            ['kint_entity' => 'КартаГостя', 'kint_field' => 'Идентификатор',       'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_GUEST_CARD_ID',  'b24_type' => 'Строка',   'desc' => 'ID карты гостя КИНТ'],
            ['kint_entity' => 'КартаГостя', 'kint_field' => 'Номер',               'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_GC_NUMBER',      'b24_type' => 'Строка',   'desc' => 'Номер карты гостя'],
            ['kint_entity' => 'КартаГостя', 'kint_field' => 'ДатаЗаезда',          'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_GC_ARRIVAL',     'b24_type' => 'Дата',     'desc' => 'Дата заезда (из карты)'],
            ['kint_entity' => 'КартаГостя', 'kint_field' => 'ДатаВыезда',          'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_GC_DEPARTURE',   'b24_type' => 'Дата',     'desc' => 'Дата выезда (из карты)'],
            ['kint_entity' => 'КартаГостя', 'kint_field' => 'ВариантПроживания',   'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_STAY_VARIANT',   'b24_type' => 'Строка',   'desc' => 'Вариант проживания'],
            ['kint_entity' => 'КартаГостя', 'kint_field' => 'ВариантЛечения',      'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_TREATMENT',      'b24_type' => 'Строка',   'desc' => 'Вариант лечения'],
            ['kint_entity' => 'КартаГостя', 'kint_field' => 'ВариантПитания',      'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_MEAL',           'b24_type' => 'Строка',   'desc' => 'Вариант питания'],
            ['kint_entity' => 'КартаГостя', 'kint_field' => 'НомерГостиницы',      'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_GC_ROOM',        'b24_type' => 'Строка',   'desc' => 'Номер гостиницы (из карты)'],
        ];
    }

    public static function servicesToProductsMapping(): array
    {
        return [
            ['kint_entity' => 'НазначениеУслуг', 'kint_field' => 'Услуга.Наименование', 'b24_entity' => 'Product', 'b24_field' => 'NAME',       'b24_type' => 'Строка',  'desc' => 'Название услуги → название товара'],
            ['kint_entity' => 'НазначениеУслуг', 'kint_field' => 'Услуга.ID',          'b24_entity' => 'Product', 'b24_field' => 'XML_ID',     'b24_type' => 'Строка',  'desc' => 'ID услуги КИНТ → внешний код товара'],
            ['kint_entity' => 'НазначениеУслуг', 'kint_field' => 'Стоимость',          'b24_entity' => 'Product', 'b24_field' => 'PRICE',      'b24_type' => 'Число',   'desc' => 'Стоимость → цена товара'],
            ['kint_entity' => 'НазначениеУслуг', 'kint_field' => 'Назначено',          'b24_entity' => 'Product', 'b24_field' => 'QUANTITY',   'b24_type' => 'Число',   'desc' => 'Количество сеансов → количество'],
            ['kint_entity' => 'НазначениеУслуг', 'kint_field' => 'Платная',            'b24_entity' => 'Product', 'b24_field' => '(фильтр)',   'b24_type' => 'Да/Нет',  'desc' => 'Только платные услуги → в товары сделки'],
        ];
    }

    public static function catalogToProductsMapping(): array
    {
        return [
            ['kint_entity' => 'Справочник.Услуги', 'kint_field' => 'Наименование', 'b24_entity' => 'Product', 'b24_field' => 'NAME',     'b24_type' => 'Строка',  'desc' => 'Название услуги'],
            ['kint_entity' => 'Справочник.Услуги', 'kint_field' => 'Код',          'b24_entity' => 'Product', 'b24_field' => 'XML_ID',   'b24_type' => 'Строка',  'desc' => 'Код услуги → внешний код'],
            ['kint_entity' => 'Справочник.Услуги', 'kint_field' => 'ID',           'b24_entity' => 'Product', 'b24_field' => 'XML_ID',   'b24_type' => 'Строка',  'desc' => 'ID услуги → внешний код (альтернатива)'],
            ['kint_entity' => 'Справочник.Услуги', 'kint_field' => 'Родитель',     'b24_entity' => 'Product', 'b24_field' => 'SECTION_ID','b24_type' => 'Привязка','desc' => 'Группа услуг → раздел каталога'],
            ['kint_entity' => 'Справочник.Услуги', 'kint_field' => 'ЭтоГруппа',    'b24_entity' => 'Product', 'b24_field' => '(раздел)',  'b24_type' => 'Да/Нет',  'desc' => 'Группа → раздел каталога, не товар'],
        ];
    }

    public static function checkoutToDealMapping(): array
    {
        return [
            ['kint_entity' => 'КартаГостя',         'kint_field' => 'ДатаВыезда (факт.)',    'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_ACTUAL_DEPARTURE', 'b24_type' => 'Дата',   'desc' => 'Фактическая дата выезда'],
            ['kint_entity' => 'КартаГостя (расчёт)','kint_field' => 'EarlyDeparture',        'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_EARLY_DEPARTURE',   'b24_type' => 'Да/Нет', 'desc' => 'Досрочный выезд (факт < план)'],
            ['kint_entity' => 'КартаГостя',         'kint_field' => 'ДатаВыезда (план.)',    'b24_entity' => 'Deal', 'b24_field' => 'UF_KINT_PLANNED_DEPARTURE', 'b24_type' => 'Дата',   'desc' => 'Плановая дата выезда'],
        ];
    }

    public static function contactMapping(): array
    {
        return [
            ['kint_entity' => 'Физлицо', 'kint_field' => 'LastNameRu + FirstNameRu + MiddleName', 'b24_entity' => 'Contact', 'b24_field' => 'NAME',      'b24_type' => 'Строка',  'desc' => 'ФИО гостя'],
            ['kint_entity' => 'Физлицо', 'kint_field' => 'Телефон',         'b24_entity' => 'Contact', 'b24_field' => 'PHONE',     'b24_type' => 'Строка',  'desc' => 'Телефон (ключ поиска)'],
            ['kint_entity' => 'Физлицо', 'kint_field' => 'ЭлектроннаяПочта','b24_entity' => 'Contact', 'b24_field' => 'EMAIL',     'b24_type' => 'Строка',  'desc' => 'Email'],
            ['kint_entity' => 'Физлицо', 'kint_field' => 'Birthday',        'b24_entity' => 'Contact', 'b24_field' => 'BIRTHDATE', 'b24_type' => 'Дата',    'desc' => 'Дата рождения'],
            ['kint_entity' => 'Физлицо', 'kint_field' => 'Gender',          'b24_entity' => 'Contact', 'b24_field' => 'UF_KINT_GENDER', 'b24_type' => 'Список','desc' => 'Пол'],
            ['kint_entity' => 'Физлицо', 'kint_field' => 'Возраст',         'b24_entity' => 'Contact', 'b24_field' => 'UF_KINT_AGE', 'b24_type' => 'Число',  'desc' => 'Возраст'],
        ];
    }

    public static function dealStageMapping(): array
    {
        return [
            ['kint_event' => 'Бронь создана (статус: Принята/В работе)', 'b24_stage' => 'NEW',             'b24_stage_name' => 'Новая',              'direction' => 'Сделка создана'],
            ['kint_event' => 'Счёт выставлен (AcceptPayment/GetInvoices)','b24_stage' => 'INVOICE_SENT',   'b24_stage_name' => 'Счёт выставлен',     'direction' => 'Счёт передан в сделку'],
            ['kint_event' => 'Оплата получена (PaymentStatus.Оплачено > 0, частично)', 'b24_stage' => 'PARTIALLY_PAID', 'b24_stage_name' => 'Частично оплачено', 'direction' => 'Частичная оплата'],
            ['kint_event' => 'Оплата получена полностью (PaymentStatus.Оплачено >= Выставлено)', 'b24_stage' => 'PAID', 'b24_stage_name' => 'Оплачено', 'direction' => 'Полная оплата'],
            ['kint_event' => 'Гость заехал (КартаГостя создана)',         'b24_stage' => 'CHECKED_IN',      'b24_stage_name' => 'Заезд',              'direction' => 'Карта гостя создана'],
            ['kint_event' => 'Гость выехал (ChangeGuestParameters: Выбытие)', 'b24_stage' => 'CHECKED_OUT', 'b24_stage_name' => 'Выезд',              'direction' => 'Фактический выезд'],
            ['kint_event' => 'Досрочный выезд (ActualDate < PlannedDate)',  'b24_stage' => 'EARLY_CHECKOUT', 'b24_stage_name' => 'Досрочный выезд',    'direction' => 'Досрочный выезд + комментарий'],
            ['kint_event' => 'Бронь отменена (CancelBooking)',              'b24_stage' => 'CANCELED',       'b24_stage_name' => 'Отменена',           'direction' => 'Бронь отменена в КИНТ'],
        ];
    }

    public static function notificationTemplate(): array
    {
        return [
            'channel'   => 'Уведомление в Битрикс24 (чат сделки) / SMS / Email',
            'trigger'   => 'PaymentStatusByDocument: Оплачено > 0',
            'subject'   => 'Поступление оплаты по бронированию №{BOOKING_NUMBER}',
            'body'      => "Уважаемый(ая) {GUEST_NAME}!\n\n"
                         . "Поступила оплата по вашему бронированию №{BOOKING_NUMBER}.\n"
                         . "Сумма оплаты: {PAID_AMOUNT} руб.\n"
                         . "Дата оплаты: {PAYMENT_DATE}\n"
                         . "Остаток к оплате: {REMAINING_AMOUNT} руб.\n"
                         . "Статус оплаты: {PAYMENT_STATUS}\n\n"
                         . "Санаторий: {SANATORIUM_NAME}\n"
                         . "Дата заезда: {DATE_FROM}\n"
                         . "Дата выезда: {DATE_TO}\n"
                         . "Номер: {ROOM}\n",
            'variables' => [
                'GUEST_NAME'        => 'GetGuestData: LastNameRu + FirstNameRu + MiddleName',
                'BOOKING_NUMBER'    => 'GetBookingStatus: Number',
                'PAID_AMOUNT'       => 'PaymentStatusByDocument: Оплачено',
                'PAYMENT_DATE'      => 'GetAcceptances: Дата (последний платёж)',
                'REMAINING_AMOUNT'  => 'PaymentStatusByDocument: Выставлено - Оплачено',
                'PAYMENT_STATUS'    => 'PaymentStatusByDocument: расчёт (Полностью/Частично)',
                'SANATORIUM_NAME'   => 'GetBookingStatus: Sanatorium',
                'DATE_FROM'         => 'GetBookingStatus / КартаГостя: ДатаЗаезда',
                'DATE_TO'           => 'GetBookingStatus / КартаГостя: ДатаВыезда',
                'ROOM'              => 'КартаГостя: НомерГостиницы',
            ],
        ];
    }

    public static function bpActivitySchema(): array
    {
        return [
            'activity_name'   => 'KintDealStageChange',
            'activity_class'  => 'CBPKintDealStageChange',
            'description'     => 'Изменение стадии сделки на основании данных из КИНТ',
            'category'        => '[Кинт] Интеграция',
            'input_fields'    => [
                ['id' => 'dealId',        'name' => 'ID сделки',              'type' => 'int',    'required' => true],
                ['id' => 'kintEvent',     'name' => 'Событие КИНТ',           'type' => 'select', 'required' => true,
                 'options' => ['payment', 'checkin', 'checkout', 'early_checkout', 'cancel']],
                ['id' => 'kintBookingId', 'name' => 'ID брони КИНТ',          'type' => 'string', 'required' => true],
                ['id' => 'paymentData',   'name' => 'Данные об оплате (JSON)','type' => 'string', 'required' => false],
            ],
            'output_fields'   => [
                ['id' => 'newStage',      'name' => 'Новая стадия',           'type' => 'string'],
                ['id' => 'result',        'name' => 'Результат выполнения',   'type' => 'string'],
            ],
            'logic'           => 'По событию КИНТ определяет новую стадию сделки (по маппингу) и обновляет STAGE_ID через CCrmDeal::Update',
            'dependencies'    => ['itbizon.service (Activity base class)', 'crm (CCrmDeal)'],
        ];
    }

    public static function fullMappingTable(): array
    {
        return array_merge(
            self::bookingToDealMapping(),
            self::invoiceToDealMapping(),
            self::paymentToDealMapping(),
            self::guestCardToDealMapping(),
            self::checkoutToDealMapping(),
            self::servicesToProductsMapping(),
            self::contactMapping(),
        );
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * MODULE 4: ViewRenderer — генерация HTML-секций страницы
 * ═══════════════════════════════════════════════════════════════════════════ */

class ViewRenderer
{
    public static function esc($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    public static function json($v): string
    {
        return htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');
    }

    public static function sectionHeader(string $num, string $title, string $desc = ''): string
    {
        $descHtml = $desc ? "<div class=\"section-desc\">{$desc}</div>" : '';
        return "<div class=\"section\" id=\"s{$num}\">
            <h2><span class=\"badge\">{$num}</span> {$title}</h2>
            {$descHtml}";
    }

    public static function sectionFooter(): string
    {
        return "</div>";
    }

    public static function mappingTable(array $mappings): string
    {
        $html = '<table class="data-table mapping-table"><thead><tr>
            <th>Сущность КИНТ</th><th>Поле КИНТ</th><th>Сущность Б24</th><th>Поле Б24</th><th>Тип</th><th>Описание</th>
        </tr></thead><tbody>';
        foreach ($mappings as $row) {
            $html .= '<tr>'
                . '<td>' . self::esc($row['kint_entity']) . '</td>'
                . '<td><code>' . self::esc($row['kint_field']) . '</code></td>'
                . '<td>' . self::esc($row['b24_entity']) . '</td>'
                . '<td><code>' . self::esc($row['b24_field']) . '</code></td>'
                . '<td>' . self::esc($row['b24_type']) . '</td>'
                . '<td>' . self::esc($row['desc']) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    public static function dataTable(array $rows, array $columns): string
    {
        if (empty($rows)) {
            return '<div class="empty-state">Нет данных за выбранный период</div>';
        }
        $html = '<table class="data-table"><thead><tr>';
        foreach ($columns as $col => $label) {
            $html .= "<th>{$label}</th>";
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $col => $label) {
                $val = $row[$col] ?? $row[$label] ?? '';
                if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                $html .= '<td>' . self::esc($val) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    public static function jsonCard(string $title, $data): string
    {
        if ($data === null || $data === []) return '';
        $json = self::json($data);
        return "<div class=\"json-card\">
            <div class=\"json-card-title\">{$title}</div>
            <pre class=\"json-pre\">{$json}</pre>
        </div>";
    }

    public static function infoBox(string $type, string $message): string
    {
        return "<div class=\"info-box {$type}\">{$message}</div>";
    }

    public static function subSection(string $title, string $content): string
    {
        return "<div class=\"subsection\">
            <h3>{$title}</h3>
            {$content}
        </div>";
    }

    public static function dealPreviewBox(string $title, array $dealFields): string
    {
        $html = "<div class=\"deal-preview\">
            <div class=\"deal-preview-title\">{$title} — данные для сделки Битрикс24</div>
            <table class=\"data-table compact\"><tbody>";
        foreach ($dealFields as $field => $value) {
            if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            $html .= '<tr><td class="field-name"><code>' . self::esc($field) . '</code></td><td>' . self::esc($value) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    public static function renderSection1_1(array $bookings): string
    {
        $html = self::sectionHeader('1.1', 'Создание «Сделки» в Битрикс24 на основании «Брони» в КИНТ',
            'При создании «Брони» в КИНТ автоматически создаётся «Сделка» в Битрикс24 с передачей основных данных бронирования.');

        $rows = [];
        $displayBookings = array_slice($bookings, 0, 10);
        foreach ($displayBookings as $b) {
            $sanatorium = $b['Sanatorium'] ?? ($b['OrganizationOfStay']['Наименование'] ?? '');
            $clientName = $b['Ссылка']['Контрагент']['Наименование'] ?? ($b['Контрагент']['Наименование'] ?? '');
            $cost = $b['Ссылка']['Стоимость'] ?? ($b['Стоимость'] ?? '');

            $firstGuest = $b['Ссылка']['Гости'][0] ?? ($b['Гости'][0] ?? []);
            $guestName = $firstGuest['ФизЛицо']['Наименование'] ?? '';
            $guestRoom = $firstGuest['НомерГостиницы']['Наименование'] ?? '';
            $guestRoomCategory = $firstGuest['КатегорияНомера']['Наименование']
                ?? ($b['Ссылка']['КатегорияНомера']['Наименование'] ?? ($b['КатегорияНомера']['Наименование'] ?? ''));
            $guestDateFrom = $firstGuest['ДатаЗаезда'] ?? '';
            $guestDateTo = $firstGuest['ДатаВыезда'] ?? '';

            $rows[] = [
                'Number' => trim($b['Number'] ?? ''),
                'Date' => $b['Date'] ?? '',
                'Status' => $b['Status'] ?? '',
                'Sanatorium' => $sanatorium,
                'Client' => $clientName,
                'Guest' => $guestName,
                'Cost' => $cost,
                'DateFrom' => $guestDateFrom,
                'DateTo' => $guestDateTo,
                'Room' => $guestRoom,
                'RoomCategory' => $guestRoomCategory,
                'Booked' => ($b['Booked'] ?? 0) ? 'Да' : 'Нет',
                'ToProcess' => $b['ToProcess'] ?? '',
                'ID' => $b['ID'] ?? '',
            ];
        }

        if (count($bookings) > 10) {
            $html .= self::infoBox('info', 'Показаны первые 10 броней из ' . count($bookings) . '. Связанные документы (путёвки, карты гостя) загружаются для первых 10 броней.');
        }

        $html .= self::subSection('Данные из КИНТ (GetBookingList + Fields=Контрагент,Стоимость,Договор,Гости,КатегорияНомера)',
            self::dataTable($rows, [
                'Number' => 'Номер', 'Date' => 'Дата', 'Status' => 'Статус',
                'Sanatorium' => 'Санаторий', 'Client' => 'Контрагент', 'Guest' => 'Гость',
                'Cost' => 'Стоимость', 'DateFrom' => 'Заезд', 'DateTo' => 'Выезд',
                'Room' => 'Номер', 'RoomCategory' => 'Категория номера',
                'Booked' => 'Бронь', 'ToProcess' => 'Осталось', 'ID' => 'ID КИНТ',
            ]));

        $dealPreviews = '';
        foreach ($displayBookings as $b) {
            $sanatorium = $b['Sanatorium'] ?? ($b['OrganizationOfStay']['Наименование'] ?? '');
            $clientName = $b['Ссылка']['Контрагент']['Наименование'] ?? ($b['Контрагент']['Наименование'] ?? '');
            $cost = $b['Ссылка']['Стоимость'] ?? ($b['Стоимость'] ?? '');
            $num = trim($b['Number'] ?? '');

            $firstGuest = $b['Ссылка']['Гости'][0] ?? ($b['Гости'][0] ?? []);
            $guestName = $firstGuest['ФизЛицо']['Наименование'] ?? '';
            $guestRoom = $firstGuest['НомерГостиницы']['Наименование'] ?? '';
            $guestRoomCategory = $firstGuest['КатегорияНомера']['Наименование']
                ?? ($b['Ссылка']['КатегорияНомера']['Наименование'] ?? ($b['КатегорияНомера']['Наименование'] ?? ''));
            $guestDateFrom = $firstGuest['ДатаЗаезда'] ?? '';
            $guestDateTo = $firstGuest['ДатаВыезда'] ?? '';

            $dealFields = [
                'TITLE' => 'Бронь №' . $num,
                'BEGINDATE' => $b['Date'] ?? '',
                'STAGE_ID' => 'NEW',
                'UF_KINT_BOOKING_ID' => $b['ID'] ?? '',
                'UF_KINT_SANATORIUM' => $sanatorium,
                'CONTACT_ID' => $guestName ? "→ поиск по ФИО: {$guestName}" : ($clientName ? "→ {$clientName}" : ''),
                'OPPORTUNITY' => $cost ? $cost . ' руб.' : '',
                'UF_KINT_DATE_FROM' => $guestDateFrom,
                'UF_KINT_DATE_TO' => $guestDateTo,
                'UF_KINT_ROOM' => $guestRoom,
                'UF_KINT_ROOM_CATEGORY' => $guestRoomCategory,
                'UF_KINT_BOOKED' => ($b['Booked'] ?? 0) ? 'Да' : 'Нет',
                'UF_KINT_TO_PROCESS' => $b['ToProcess'] ?? '',
            ];
            $dealPreviews .= self::dealPreviewBox('Бронь №' . ($num ?: '?'), $dealFields);
        }
        $html .= self::subSection('Предпросмотр данных для Сделки Б24', $dealPreviews);

        $html .= self::subSection('Маппинг полей: Бронь → Сделка',
            self::mappingTable(FieldMapper::bookingToDealMapping()));

        $html .= self::jsonCard('Raw JSON: GetBookingList', $bookings);
        $html .= self::sectionFooter();
        return $html;
    }

    public static function renderSection1_2(array $invoices, array $qrCodes): string
    {
        $html = self::sectionHeader('1.2', 'Передача информации о сформированных счетах и ссылках на оплату',
            'Из «Брони» (КИНТ) в «Сделку» (Битрикс24) передаются данные о сформированных счетах и ссылки на онлайн-оплату.');

        $html .= self::subSection('Данные из КИНТ (GetCatalog: СчетНаОплатуПокупателю — реальные данные + GetPaymentQRCode — MOCK)',
            self::dataTable($invoices, [
                'Номер' => 'Номер счёта', 'Дата' => 'Дата', 'СуммаДокумента' => 'Сумма', 'Идентификатор' => 'ID КИНТ',
            ]));

        $qrHtml = '';
        foreach ($qrCodes as $invId => $qr) {
            if ($qr && !isset($qr['_error'])) {
                $url = $qr['СБП'] ?? ($qr['Обычный'] ?? null);
                $isMock = !empty($qr['_mock']);
                if ($url) {
                    $mockLabel = $isMock ? ' <span class="mock-label">[MOCK — не реальный запрос]</span>' : '';
                    $qrHtml .= self::infoBox('success', "Ссылка СБП для счёта: <a href=\"" . self::esc($url) . "\" target=\"_blank\">" . self::esc($url) . "</a>{$mockLabel}");
                }
            }
        }
        if ($qrHtml) {
            $html .= self::subSection('Ссылки на оплату (СБП)', $qrHtml);
        }

        $html .= self::subSection('Маппинг полей: Счёт → Сделка',
            self::mappingTable(FieldMapper::invoiceToDealMapping()));

        $html .= self::jsonCard('Raw JSON: GetInvoices', $invoices);
        $html .= self::jsonCard('Raw JSON: QR-коды', $qrCodes);
        $html .= self::sectionFooter();
        return $html;
    }

    public static function renderSection1_3(array $acceptances, array $paymentStatuses): string
    {
        $html = self::sectionHeader('1.3', 'Передача информации о поступлении денежных средств',
            'Информация об оплате из «Брони» (КИНТ) передаётся в соответствующую «Сделку» (Битрикс24).');

        $html .= self::subSection('Платежи из КИНТ (GetAcceptances)',
            self::dataTable($acceptances, [
                'Номер' => 'Номер платежа', 'Дата' => 'Дата', 'СуммаДокументаБезСкидки' => 'Сумма', 'Идентификатор' => 'ID КИНТ',
            ]));

        $statusHtml = '';
        foreach ($paymentStatuses as $docId => $ps) {
            if ($ps && !isset($ps['Success'])) {
                $invoiced = $ps['Выставлено'] ?? 0;
                $paid = $ps['Оплачено'] ?? 0;
                $status = $paid >= $invoiced && $invoiced > 0 ? 'Полностью оплачено' : ($paid > 0 ? 'Частично оплачено' : 'Не оплачено');
                $statusHtml .= self::infoBox('info',
                    "Документ: " . self::esc(mb_substr($docId, -20)) . "<br>"
                    . "Выставлено: <strong>{$invoiced}</strong> руб. | "
                    . "Оплачено: <strong>{$paid}</strong> руб. | "
                    . "Статус: <strong>{$status}</strong>");
            }
        }
        if ($statusHtml) {
            $html .= self::subSection('Статус оплаты (PaymentStatusByDocument)', $statusHtml);
        }

        $html .= self::subSection('Маппинг полей: Платёж → Сделка',
            self::mappingTable(FieldMapper::paymentToDealMapping()));

        $html .= self::jsonCard('Raw JSON: GetAcceptances', $acceptances);
        $html .= self::jsonCard('Raw JSON: PaymentStatus', $paymentStatuses);
        $html .= self::sectionFooter();
        return $html;
    }

    public static function renderSection1_4(array $bookings, array $guestCardsByBooking): string
    {
        $html = self::sectionHeader('1.4', 'Наследование связи «Брони» и «Сделки» в «Карточку гостя»',
            'При создании «Карточки гостя» в КИНТ на основании «Брони» сохраняется связь с соответствующей «Сделкой» в Битрикс24.');

        $relationHtml = '';
        foreach (array_slice($bookings, 0, 10) as $b) {
            $bookingId = $b['ID'] ?? '';
            $num = trim($b['Number'] ?? '');
            $clientName = $b['Ссылка']['Контрагент']['Наименование'] ?? ($b['Контрагент']['Наименование'] ?? '');
            $cards = $guestCardsByBooking[$bookingId] ?? [];

            $cardTableHtml = self::dataTable($cards, [
                'Номер' => 'Номер карты', 'ДатаЗаезда' => 'Заезд', 'ДатаВыезда' => 'Выезд',
                'НомерГостиницы' => 'Номер', 'ВариантПроживания' => 'Проживание',
                'ВариантЛечения' => 'Лечение', 'ВариантПитания' => 'Питание',
                'Идентификатор' => 'ID КИНТ',
            ]);

            $dealFields = [
                'UF_KINT_BOOKING_ID' => $bookingId,
                'UF_KINT_GUEST_CARD_ID' => !empty($cards) ? ($cards[0]['Идентификатор'] ?? '') : '(карта гостя не найдена)',
                'UF_KINT_GC_ARRIVAL' => !empty($cards) ? ($cards[0]['ДатаЗаезда'] ?? '') : '',
                'UF_KINT_GC_DEPARTURE' => !empty($cards) ? ($cards[0]['ДатаВыезда'] ?? '') : '',
                'UF_KINT_GC_ROOM' => !empty($cards) ? ($cards[0]['НомерГостиницы'] ?? '') : '',
            ];

            $relationHtml .= self::subSection('Бронь №' . ($num ?: '?') . ($clientName ? " — {$clientName}" : ''),
                $cardTableHtml
                . self::dealPreviewBox('Поля связки в сделке Б24', $dealFields));
        }
        if (!$relationHtml) {
            $relationHtml = '<div class="empty-state">Нет данных о связях</div>';
        }
        $html .= $relationHtml;

        $html .= self::subSection('Маппинг полей: Карта гостя → Сделка',
            self::mappingTable(FieldMapper::guestCardToDealMapping()));

        $html .= self::jsonCard('Raw JSON: Карты гостя', $guestCardsByBooking);
        $html .= self::sectionFooter();
        return $html;
    }

    public static function renderSection1_5(array $serviceAssignments): string
    {
        $html = self::sectionHeader('1.5', 'Передача данных о дополнительных услугах',
            'Из «Карточки гостя» (КИНТ) в «Сделку» (Битрикс24) передаются: перечень дополнительных услуг и их стоимость.');

        $paidServices = array_filter($serviceAssignments, fn($s) => !empty($s['Платная']) || (isset($s['Платная']) && $s['Платная'] !== false));
        $allServices = $serviceAssignments;

        $html .= self::subSection('Все назначенные услуги (НазначенияИРезультаты)',
            self::dataTable($allServices, [
                'Услуга' => 'Услуга', 'ДатаСеанса' => 'Дата сеанса', 'ВремяС' => 'Время начала',
                'ВремяДо' => 'Время окончания', 'Платная' => 'Платная',
                'Стоимость' => 'Стоимость', 'Назначено' => 'Назначено',
                'Пройдено' => 'Пройдено', 'Осталось' => 'Осталось',
            ]));

        $productHtml = '';
        foreach ($allServices as $s) {
            $isPaid = !empty($s['Платная']) || (isset($s['Платная']) && $s['Платная'] !== false && $s['Платная'] !== 'false');
            if (!$isPaid) continue;
            $svcName = is_array($s['Услуга'] ?? null) ? ($s['Услуга']['Наименование'] ?? '') : ($s['Услуга'] ?? '');
            $productHtml .= self::dealPreviewBox('Услуга: ' . $svcName, [
                'NAME' => $svcName,
                'PRICE' => $s['Стоимость'] ?? 0,
                'QUANTITY' => $s['Назначено'] ?? 1,
                'XML_ID' => '(ID услуги КИНТ)',
            ]);
        }
        if ($productHtml) {
            $html .= self::subSection('Товарные позиции для сделки (только платные)', $productHtml);
        } else {
            $html .= self::subSection('Товарные позиции для сделки (только платные)',
                '<div class="empty-state">Нет платных услуг</div>');
        }

        $html .= self::subSection('Маппинг полей: Услуга → Товар сделки',
            self::mappingTable(FieldMapper::servicesToProductsMapping()));

        $html .= self::jsonCard('Raw JSON: НазначенияИРезультаты', $serviceAssignments);
        $html .= self::sectionFooter();
        return $html;
    }

    public static function renderSection1_6(array $servicesCatalog): string
    {
        $html = self::sectionHeader('1.6', 'Синхронизация услуг (товаров)',
            'Настройка соответствия номенклатуры услуг/товаров между справочниками «КИНТ» и «Битрикс24». В MVP — только визуальный вывод.');

        $flatServices = [];
        foreach ($servicesCatalog as $s) {
            $ref = $s['Ссылка'] ?? $s;
            $name = $ref['Наименование'] ?? ($s['Наименование'] ?? ($s['Name'] ?? ''));
            $isGroup = $ref['ЭтоГруппа'] ?? ($s['ЭтоГруппа'] ?? false);
            $code = trim($ref['Код'] ?? ($s['Код'] ?? ($s['Code'] ?? '')));
            $id = $ref['Идентификатор'] ?? ($s['ID'] ?? ($s['Идентификатор'] ?? ''));
            $parent = $ref['Родитель'] ?? ($s['Родитель'] ?? '');
            if (is_array($parent)) $parent = $parent['Наименование'] ?? ($parent['Name'] ?? '');
            $flatServices[] = [
                'name' => ($isGroup ? '[Группа] ' : '') . $name,
                'code' => $code,
                'id' => $id,
                'parent' => $parent ?: '',
                'b24_name' => $name,
                'b24_xml_id' => $code ?: $id,
                'b24_section' => $parent ?: '',
            ];
        }

        $html .= self::subSection('Справочник услуг КИНТ (GetCatalog: Услуги)',
            self::dataTable($flatServices, [
                'name' => 'Услуга КИНТ', 'code' => 'Код', 'id' => 'ID КИНТ', 'parent' => 'Родитель',
                'b24_name' => '→ Product.NAME', 'b24_xml_id' => '→ Product.XML_ID', 'b24_section' => '→ Section',
            ]));

        $html .= self::subSection('Маппинг полей: Справочник услуг → Товары Б24',
            self::mappingTable(FieldMapper::catalogToProductsMapping()));

        $html .= self::jsonCard('Raw JSON: GetCatalog(Услуги)', $servicesCatalog);
        $html .= self::sectionFooter();
        return $html;
    }

    public static function renderSection1_7(array $guestCards, array $checkoutData): string
    {
        $html = self::sectionHeader('1.7', 'Передача данных о фактическом выезде',
            'Из «Карточки гостя» (КИНТ) в «Сделку» (Битрикс24) передаются: дата фактического выезда и отметка «Досрочный выезд».');

        $checkoutHtml = '';
        foreach ($checkoutData as $cardId => $data) {
            $planned = $data['planned_departure'] ?? '';
            $actual = $data['actual_departure'] ?? '';
            $isEarly = $data['is_early'] ?? false;
            $status = $actual ? ($isEarly ? 'Досрочный выезд' : 'Выезд по плану') : 'Ещё проживает';

            $checkoutHtml .= self::dealPreviewBox('Карта гостя: ' . mb_substr($cardId, -20), [
                'UF_KINT_PLANNED_DEPARTURE' => $planned,
                'UF_KINT_ACTUAL_DEPARTURE' => $actual ?: '(нет данных)',
                'UF_KINT_EARLY_DEPARTURE' => $isEarly ? 'Да' : 'Нет',
                'Статус' => $status,
            ]);
        }
        if (!$checkoutHtml) {
            $checkoutHtml = '<div class="empty-state">Нет данных о выезде</div>';
        }

        $html .= self::subSection('Данные о выезде (ChangeGuestParameters: Выбытие)', $checkoutHtml);

        $html .= self::subSection('Логика определения досрочного выезда',
            self::infoBox('info', 'Досрочный выезд = (ActualDateDeparture < PlannedDateDeparture). '
                . 'Плановая дата берётся из Карты гостя (ДатаВыезда). '
                . 'Фактическая дата определяется при операции "Выбытие" (ChangeGuestParameters).'));

        $html .= self::subSection('Маппинг полей: Выезд → Сделка',
            self::mappingTable(FieldMapper::checkoutToDealMapping()));

        $html .= self::jsonCard('Raw JSON: Данные о выезде', $checkoutData);
        $html .= self::sectionFooter();
        return $html;
    }

    public static function renderSection1_8(): string
    {
        $html = self::sectionHeader('1.8', 'Синхронизация полей данных',
            'Настройка соответствия полей между «Бронью», «Сделкой» и «Карточкой гостя» для корректной передачи данных.');

        $html .= self::subSection('Полная таблица маппинга полей КИНТ → Битрикс24',
            self::mappingTable(FieldMapper::fullMappingTable()));

        $html .= self::subSection('Маппинг контактов: Физлицо → Контакт',
            self::mappingTable(FieldMapper::contactMapping()));

        $html .= self::sectionFooter();
        return $html;
    }

    public static function renderSection2_1(array $paymentData): string
    {
        $html = self::sectionHeader('2.1', 'Создание уведомлений клиенту о поступлении ДС',
            'Уведомления клиенту о поступлении денежных средств согласно данным из КИНТ. Передача нужных данных интегратору.');

        $template = FieldMapper::notificationTemplate();

        $html .= self::subSection('Канал и триггер',
            self::infoBox('info', '<strong>Канал:</strong> ' . self::esc($template['channel']) . '<br>'
                . '<strong>Триггер:</strong> <code>' . self::esc($template['trigger']) . '</code>'));

        $html .= self::subSection('Шаблон уведомления',
            '<div class="notification-template"><div class="notif-subject"><strong>Тема:</strong> '
            . self::esc($template['subject']) . '</div><pre class="notif-body">'
            . self::esc($template['body']) . '</pre></div>');

        $varRows = [];
        foreach ($template['variables'] as $var => $src) {
            $varRows[] = ['variable' => $var, 'source' => $src];
        }
        $html .= self::subSection('Переменные для интегратора (источник данных КИНТ)',
            self::dataTable($varRows, ['variable' => 'Переменная', 'source' => 'Источник в КИНТ']));

        if (!empty($paymentData)) {
            $html .= self::subSection('Пример заполненных данных (из текущего запроса)',
                self::jsonCard('Данные для уведомления', $paymentData));
        }

        $html .= self::sectionFooter();
        return $html;
    }

    public static function renderSection2_2(): string
    {
        $html = self::sectionHeader('2.2', 'Создание роботов для изменения стадий сделок',
            'Роботы для изменения стадий сделок при поступлении информации из КИНТ (оплата, заезд, выезд). Разработка действия БП.');

        $html .= self::subSection('Маппинг стадий: События КИНТ → Стадии сделки Б24',
            self::dataTable(FieldMapper::dealStageMapping(), [
                'kint_event' => 'Событие КИНТ', 'b24_stage' => 'Stage ID', 'b24_stage_name' => 'Название стадии', 'direction' => 'Действие',
            ]));

        $schema = FieldMapper::bpActivitySchema();

        $html .= self::subSection('Схема активити бизнес-процесса',
            '<div class="bp-schema">'
            . '<table class="data-table compact"><tbody>'
            . '<tr><td class="field-name">Имя активити</td><td>' . self::esc($schema['activity_name']) . '</td></tr>'
            . '<tr><td class="field-name">Класс</td><td><code>' . self::esc($schema['activity_class']) . '</code></td></tr>'
            . '<tr><td class="field-name">Описание</td><td>' . self::esc($schema['description']) . '</td></tr>'
            . '<tr><td class="field-name">Категория</td><td>' . self::esc($schema['category']) . '</td></tr>'
            . '<tr><td class="field-name">Логика</td><td>' . self::esc($schema['logic']) . '</td></tr>'
            . '<tr><td class="field-name">Зависимости</td><td>' . self::esc(implode(', ', $schema['dependencies'])) . '</td></tr>'
            . '</tbody></table></div>');

        $html .= self::subSection('Входные параметры активити',
            self::dataTable($schema['input_fields'], [
                'id' => 'ID', 'name' => 'Название', 'type' => 'Тип', 'required' => 'Обязательный',
            ]));

        $html .= self::subSection('Выходные параметры активити',
            self::dataTable($schema['output_fields'], [
                'id' => 'ID', 'name' => 'Название', 'type' => 'Тип',
            ]));

        $html .= self::subSection('Схема БП для сделки',
            '<div class="bp-flow">'
            . '<div class="bp-step">Бронь создана → <strong>NEW</strong> (Новая)</div>'
            . '<div class="bp-arrow">↓</div>'
            . '<div class="bp-step">Счёт выставлен → <strong>INVOICE_SENT</strong></div>'
            . '<div class="bp-arrow">↓</div>'
            . '<div class="bp-step">Оплата (частично) → <strong>PARTIALLY_PAID</strong></div>'
            . '<div class="bp-arrow">↓</div>'
            . '<div class="bp-step">Оплата (полностью) → <strong>PAID</strong></div>'
            . '<div class="bp-arrow">↓</div>'
            . '<div class="bp-step">Заезд (КартаГостя) → <strong>CHECKED_IN</strong></div>'
            . '<div class="bp-arrow">↓</div>'
            . '<div class="bp-step">Выезд → <strong>CHECKED_OUT</strong> или <strong>EARLY_CHECKOUT</strong></div>'
            . '</div>');

        $html .= self::sectionFooter();
        return $html;
    }

    public static function renderDebugPanel(array $errors, array $rawResponses): string
    {
        $html = '<div class="section debug-section" id="debug">
            <h2><span class="badge debug">DEBUG</span> Панель отладки</h2>';

        if (!empty($errors)) {
            $errorList = '<ul class="error-list">';
            foreach ($errors as $err) {
                $errorList .= '<li>' . self::esc($err) . '</li>';
            }
            $errorList .= '</ul>';
            $html .= self::subSection('Ошибки API', $errorList);
        } else {
            $html .= self::infoBox('success', 'Ошибок не обнаружено');
        }

        $html .= self::subSection('Raw HTTP-ответы (первые 2000 символов)',
            self::jsonCard('Все raw-ответы', $rawResponses));

        $html .= self::sectionFooter();
        return $html;
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * MODULE 5: Main Page Logic — оркестрация данных и рендеринг
 * ═══════════════════════════════════════════════════════════════════════════ */

$api = new KintApiClient(KINT_URL, KINT_USER, KINT_PASS);
$fetcher = new DataFetcher($api);

$bookings = [];
$invoices = [];
$qrCodes = [];
$acceptances = [];
$paymentStatuses = [];
$guestCardsByBooking = [];
$allGuestCards = [];
$serviceAssignments = [];
$servicesCatalog = [];
$checkoutData = [];
$paymentDataForNotif = [];

$bookings = $fetcher->fetchBookings($dateFrom, $dateTo);

if (!empty($bookings)) {

    $vouchers = $fetcher->fetchVouchers();
    $vouchersByContract = [];
    if (!empty($vouchers) && !isset($vouchers['_error'])) {
        foreach ($vouchers as $v) {
            $contractId = $v['Договор']['Идентификатор'] ?? '';
            if ($contractId) {
                $vouchersByContract[$contractId] = $v;
            }
        }
    }

    $invoices = $fetcher->fetchInvoicesCatalog();
    if (!empty($invoices) && !isset($invoices['_error'])) {
        foreach (array_slice($invoices, 0, 10) as $invoice) {
            $invRef = $invoice['Ссылка']['Идентификатор'] ?? ($invoice['Идентификатор'] ?? '');
            $invNum = $invoice['Ссылка']['Номер'] ?? ($invoice['Номер'] ?? '');
            $invDate = $invoice['Ссылка']['Дата'] ?? ($invoice['Дата'] ?? '');
            $invData = [
                'Номер' => trim($invNum ?? ''),
                'Дата' => $invDate,
                'СуммаДокумента' => $invoice['СуммаДокумента'] ?? '',
                'Идентификатор' => $invRef,
            ];
            $invoices_display[] = $invData;
            if ($invRef) {
                $qr = $fetcher->fetchPaymentQRCode($invRef, 'СБП');
                if ($qr && !isset($qr['_error'])) {
                    $qrCodes[$invRef] = $qr;
                }
                $acc = $fetcher->fetchAcceptances($invRef);
                if (!empty($acc) && !isset($acc['_error'])) {
                    foreach ($acc as $a) $acceptances[] = $a;
                }
            }
        }
        $invoices = $invoices_display;
    }

    $processedBookings = array_slice($bookings, 0, 10);
    foreach ($processedBookings as $booking) {
        $bookingId = $booking['ID'] ?? '';
        if (!$bookingId) continue;

        $contractId = $booking['Ссылка']['Договор']['Идентификатор'] ?? ($booking['Договор']['Идентификатор'] ?? '');

        $guests = $booking['Ссылка']['Гости'] ?? ($booking['Гости'] ?? []);
        if (!is_array($guests)) $guests = [];

        $guestCardsByBooking[$bookingId] = [];

        foreach ($guests as $guest) {
            $physFaceRef = $guest['ФизЛицо']['Идентификатор'] ?? '';
            $physFaceName = $guest['ФизЛицо']['Наименование'] ?? '';
            $guestRoom = $guest['НомерГостиницы']['Наименование'] ?? '';
            $guestDateFrom = $guest['ДатаЗаезда'] ?? '';
            $guestDateTo = $guest['ДатаВыезда'] ?? '';
            $guestStayVariant = $guest['ВариантПроживания']['Наименование'] ?? '';
            $guestTreatment = $guest['ВариантЛечения']['Наименование'] ?? '';
            $guestMeal = $guest['ВариантПитания']['Наименование'] ?? '';
            $guestCategory = $guest['ВозрастнаяГруппа']['Наименование'] ?? '';
            $guestCost = $guest['Стоимость'] ?? '';

            $cardId = '';
            $cardNum = '';
            $cardDate = '';

            if ($physFaceRef) {
                $cards = $fetcher->fetchGuestCards($physFaceRef);
                if (!empty($cards)) {
                    $card = $cards[0] ?? [];
                    $cardId = $card['Идентификатор'] ?? '';
                    $cardNum = $card['Номер'] ?? '';
                    $cardDate = $card['Дата'] ?? '';
                }
            }

            $cardData = [
                'Дата' => $cardDate,
                'Номер' => trim($cardNum),
                'Идентификатор' => $cardId,
                'ДатаЗаезда' => $guestDateFrom,
                'ДатаВыезда' => $guestDateTo,
                'НомерГостиницы' => $guestRoom,
                'ВариантПроживания' => $guestStayVariant,
                'ВариантЛечения' => $guestTreatment,
                'ВариантПитания' => $guestMeal,
                'Гость' => $physFaceName,
                'ВозрастнаяГруппа' => $guestCategory,
                'СтоимостьГостя' => $guestCost,
                'ФизЛицоID' => $physFaceRef,
            ];
            $guestCardsByBooking[$bookingId][] = $cardData;
            $allGuestCards[] = $cardData;

            if ($cardId) {
                $sa = $fetcher->fetchServiceAssignments($cardId, $dateFrom, $dateTo);
                if (!empty($sa) && !isset($sa['Success'])) {
                    $saResult = is_array($sa) && isset($sa[0]) ? $sa : [$sa];
                    foreach ($saResult as $s) {
                        if (is_array($s) && !isset($s['Success'])) $serviceAssignments[] = $s;
                    }
                }
            }
        }
    }
}

$servicesCatalog = $fetcher->fetchServicesCatalog();

foreach ($allGuestCards as $card) {
    $cardId = $card['Идентификатор'] ?? ($card['ID'] ?? '');
    if (!$cardId) continue;
    $plannedDep = $card['ДатаВыезда'] ?? '';
    $actualDep = $card['ActualDateDeparture'] ?? ($card['ФактическаяДатаВыезда'] ?? '');
    $isEarly = false;
    if ($actualDep && $plannedDep) {
        $plannedTs = strtotime($plannedDep);
        $actualTs = strtotime($actualDep);
        $isEarly = $actualTs < $plannedTs;
    }
    $checkoutData[$cardId] = [
        'planned_departure' => $plannedDep,
        'actual_departure' => $actualDep,
        'is_early' => $isEarly,
    ];
}

$errors = $api->getErrors();
$rawResponses = $api->getRawResponses();

?>
<style>
    h1 { text-align:center; margin-bottom:8px; font-size:24px; color:#2067b0; }
    .subtitle { text-align:center; color:#666; margin-bottom:20px; font-size:14px; }
    .section { background:#fff; border-radius:8px; margin-bottom:16px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,.1); }
    .section h2 { font-size:17px; color:#2067b0; margin-bottom:8px; display:flex; align-items:center; gap:8px; }
    .section-desc { color:#666; font-size:13px; margin-bottom:16px; padding:8px 12px; background:#f5f7fa; border-radius:6px; border-left:3px solid #2067b0; }
    .badge { display:inline-block; background:#2067b0; color:#fff; padding:2px 10px; border-radius:12px; font-size:13px; font-weight:bold; }
    .badge.debug { background:#e74c3c; }
    .subsection { margin:16px 0; }
    .subsection h3 { font-size:14px; color:#333; margin-bottom:8px; border-bottom:1px solid #eee; padding-bottom:4px; }
    .data-table { width:100%; border-collapse:collapse; font-size:13px; }
    .data-table th { background:#2067b0; color:#fff; padding:8px 10px; text-align:left; font-weight:600; }
    .data-table td { padding:6px 10px; border-bottom:1px solid #eee; vertical-align:top; }
    .data-table tr:nth-child(even) td { background:#f9fafb; }
    .data-table.compact th, .data-table.compact td { padding:4px 8px; font-size:12px; }
    .data-table code { background:#eef; padding:1px 4px; border-radius:3px; font-size:12px; color:#c0392b; }
    .mapping-table td:first-child { white-space:nowrap; }
    .field-name { white-space:nowrap; font-weight:600; width:30%; }
    .empty-state { text-align:center; padding:20px; color:#999; font-style:italic; background:#f9f9f9; border-radius:6px; }
    .info-box { padding:10px 14px; border-radius:6px; margin:8px 0; font-size:13px; }
    .info-box.info { background:#e8f4fd; border-left:3px solid #3498db; }
    .info-box.success { background:#eafaf1; border-left:3px solid #27ae60; }
    .info-box.warning { background:#fef9e7; border-left:3px solid #f39c12; }
    .json-card { margin:12px 0; }
    .json-card-title { font-size:12px; color:#666; margin-bottom:4px; font-weight:600; }
    .json-pre { background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:6px; overflow-x:auto; font-size:11px; line-height:1.4; max-height:300px; overflow-y:auto; }
    .deal-preview { background:#f0f7ff; border:1px solid #d0e0ff; border-radius:6px; padding:12px; margin:8px 0; }
    .deal-preview-title { font-size:12px; font-weight:600; color:#2067b0; margin-bottom:6px; }
    .deal-preview table { width:100%; }
    .notification-template { background:#fffbeb; border:1px solid #f0e0a0; border-radius:6px; padding:12px; margin:8px 0; }
    .notif-subject { margin-bottom:8px; font-size:14px; }
    .notif-body { background:#fff; padding:12px; border-radius:4px; font-size:13px; white-space:pre-wrap; border:1px solid #eee; }
    .bp-flow { display:flex; flex-direction:column; align-items:center; gap:4px; padding:12px; }
    .bp-step { background:#e8f4fd; border:1px solid #b0d0f0; border-radius:6px; padding:8px 16px; font-size:13px; }
    .bp-step strong { color:#2067b0; }
    .bp-arrow { color:#999; font-size:18px; }
    .bp-schema { background:#f5f7fa; border-radius:6px; padding:8px; }
    .error-list { list-style:none; padding:0; }
    .error-list li { background:#fee; color:#c00; padding:6px 10px; border-radius:4px; margin:4px 0; font-size:13px; border-left:3px solid #c00; }
    .debug-section { border:2px dashed #ccc; }
    .nav-bar { position:sticky; top:0; z-index:100; background:#fff; padding:10px 20px; box-shadow:0 2px 4px rgba(0,0,0,.1); margin-bottom:16px; border-radius:8px; display:flex; flex-wrap:wrap; gap:6px; }
    .nav-bar a { font-size:12px; padding:3px 8px; border-radius:4px; background:#f0f7ff; color:#2067b0; text-decoration:none; }
    .nav-bar a:hover { background:#2067b0; color:#fff; }
    .date-form { display:flex; gap:8px; align-items:center; margin-left:auto; }
    .date-form input { padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px; }
    .date-form button { padding:4px 12px; background:#2067b0; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; }
    .mock-label { display:inline-block; background:#e74c3c; color:#fff; padding:1px 8px; border-radius:3px; font-size:11px; font-weight:bold; margin-left:6px; }
</style>

<h1>КИНТ → Битрикс24 | MVP интеграции</h1>
<div class="subtitle">
    Санаторий «КИНТ: Управление санаторием» → CRM Bitrix24 |
    Период: <?= $dateFrom ?> — <?= $dateTo ?> |
    Найдено броней: <?= count($bookings) ?>
</div>

<div class="nav-bar">
    <a href="#s1.1">1.1 Брони→Сделки</a>
    <a href="#s1.2">1.2 Счета</a>
    <a href="#s1.3">1.3 Оплаты</a>
    <a href="#s1.4">1.4 Связи</a>
    <a href="#s1.5">1.5 Услуги</a>
    <a href="#s1.6">1.6 Синхр.товаров</a>
    <a href="#s1.7">1.7 Выезд</a>
    <a href="#s1.8">1.8 Маппинг</a>
    <a href="#s2.1">2.1 Уведомления</a>
    <a href="#s2.2">2.2 Роботы БП</a>
    <a href="#debug">DEBUG</a>
    <form class="date-form" method="get">
        <input type="text" name="dateFrom" value="<?= $dateFrom ?>" placeholder="01.06.2026" size="10">
        <input type="text" name="dateTo" value="<?= $dateTo ?>" placeholder="30.06.2026" size="10">
        <button type="submit">Обновить</button>
    </form>
</div>

<?= ViewRenderer::renderSection1_1($bookings) ?>
<?= ViewRenderer::renderSection1_2($invoices, $qrCodes) ?>
<?= ViewRenderer::renderSection1_3($acceptances, $paymentStatuses) ?>
<?= ViewRenderer::renderSection1_4($bookings, $guestCardsByBooking) ?>
<?= ViewRenderer::renderSection1_5($serviceAssignments) ?>
<?= ViewRenderer::renderSection1_6($servicesCatalog) ?>
<?= ViewRenderer::renderSection1_7($allGuestCards, $checkoutData) ?>
<?= ViewRenderer::renderSection1_8() ?>
<?= ViewRenderer::renderSection2_1($paymentDataForNotif) ?>
<?= ViewRenderer::renderSection2_2() ?>
<?= ViewRenderer::renderDebugPanel($errors, $rawResponses) ?>

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>
