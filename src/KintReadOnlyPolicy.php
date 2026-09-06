<?php

declare(strict_types=1);

namespace KintB24;

/** Shared, fail-closed KINT read boundary. No write mode or runtime override. */
final class KintReadOnlyPolicy
{
    // Only audited list/read methods. A GET verb alone does not imply read-only.
    private const READS = [
        'GetBookingList' => ['Client', 'НачалоПериода', 'КонецПериода', 'Fields'],
        'GetBookingStatus' => ['Booking', 'AdditionalProperties'],
        'GetInvoices' => ['Контрагент', 'Договор', 'Основание', 'Fields'],
        'GetAcceptances' => ['Контрагент', 'Договор', 'Счет', 'Fields'],
        'PaymentStatusByDocument' => ['Document', 'Детализация'],
        'КартыГостя' => ['ФизЛицо'],
        'GetGuestData' => ['ФизЛицо', 'Физлицо'],
        'GetCatalog' => ['Вид', 'Тип', 'CatalogName', 'CatalogType', 'Filter', 'Fields', 'Отбор', 'Реквизиты', 'AdditionalProperties'],
        'RelatedDocuments' => ['Документ', 'ВидДокумента'],
        'GetAvailableRooms' => ['DateFrom', 'DateTo', 'Qty', 'Vacant', 'RoomCategory', 'Room', 'OrganizationOfStay', 'QuotaID', 'ДополнительныеСвойства'],
    ];

    public static function url(string $serviceUrl, string $method, array $params = []): string
    {
        $parts = parse_url($serviceUrl);
        $path = rawurldecode($parts['path'] ?? '');
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || !filter_var($serviceUrl, FILTER_VALIDATE_URL)
            || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])
            || !str_ends_with($path, '/hs/KintAPI.hs')
            || preg_match('~[\\\\?#%\x00-\x20\x7f]|(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new \LogicException('BLOCKED_KINT_READ_ONLY: invalid service URL');
        }

        if ($method === 'GetData') {
            $method = $params['Method'] ?? '';
            unset($params['Method']);
        }
        if (!is_string($method) || !array_key_exists($method, self::READS)) {
            throw new \LogicException('BLOCKED_KINT_READ_ONLY: method not allowed');
        }
        $allowed = self::READS[$method];
        if (in_array($method, ['GetCatalog', 'GetAvailableRooms'], true)) {
            $allowed = array_merge($allowed, ['CountOnPage', 'PageNumber', 'КоличествоЭлементов', 'НомерСтраницы']);
        }
        foreach ($params as $name => &$value) {
            if (!in_array($name, $allowed, true)) {
                throw new \LogicException('BLOCKED_KINT_READ_ONLY: parameter not allowed');
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } elseif (!is_scalar($value) && $value !== null) {
                throw new \LogicException('BLOCKED_KINT_READ_ONLY: invalid parameter value');
            }
        }
        unset($value);
        return rtrim($serviceUrl, '/') . '/' . $method
            . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }
}
