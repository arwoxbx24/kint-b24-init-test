<?php

declare(strict_types=1);

namespace KintB24;

/**
 * Maps КИНТ data structures → Bitrix24 field arrays.
 *
 * All methods are pure functions: no side effects, no network calls.
 * Source of truth: prototype/index.php FieldMapper + detail.php.
 */
final class FieldMapper
{
    // ── Stage IDs ─────────────────────────────────────────────────────────────

    /**
     * Map КИНТ booking/guest-card status → B24 deal stage ID.
     *
     * These stage IDs must exist in the pipeline configured in setup-b24.php.
     * Status strings from КИНТ: «Новое», «Счёт», «ЧастичнаяОплата», «Оплачено»,
     * «Заезд», «Выезд», «РаннийВыезд», «Отмена»
     */
    public static function dealStageMapping(): array
    {
        return [
            'Новое'           => 'NEW',
            'Счёт'            => 'INVOICE_SENT',
            'ЧастичнаяОплата' => 'PARTIALLY_PAID',
            'Оплачено'        => 'PAID',
            'Заезд'           => 'CHECKED_IN',
            'Выезд'           => 'CHECKED_OUT',
            'РаннийВыезд'     => 'EARLY_CHECKOUT',
            'Отмена'          => 'CANCELED',
        ];
    }

    public static function stageId(string $kintStatus): ?string
    {
        return self::dealStageMapping()[$kintStatus] ?? null;
    }

    // ── Booking → Deal ────────────────────────────────────────────────────────

    /**
     * Core booking fields → deal create/update fields.
     *
     * @param array $booking  КИНТ GetBookingList item
     * @param int|null $contactId  B24 contact ID (null if not yet resolved)
     */
    public static function bookingToDealFields(array $booking, ?int $contactId = null): array
    {
        $id = (string)($booking['ID'] ?? $booking['Идентификатор'] ?? $booking['Номер'] ?? '');
        $number = (string)($booking['Number'] ?? $booking['Номер'] ?? $id);
        $status = $booking['Status'] ?? $booking['Статус'] ?? '';
        $status = is_array($status) ? (string)($status['Name'] ?? '') : (string)$status;
        $sanatorium = $booking['Sanatorium'] ?? $booking['Санаторий'] ?? '';
        $sanatorium = is_array($sanatorium) ? (string)($sanatorium['Name'] ?? '') : (string)$sanatorium;
        $title = 'Бронь №' . $number;

        $dateFrom = self::isoDate($booking['ДатаНачала'] ?? $booking['ДатаЗаезда'] ?? null);
        $dateTo   = self::isoDate($booking['ДатаОкончания'] ?? $booking['ДатаВыезда'] ?? null);

        $fields = [
            'TITLE'                  => $title,
            'BEGINDATE'              => $dateFrom,
            'CLOSEDATE'              => $dateTo,
            'STAGE_ID'               => self::stageId($status),

            // Custom UF fields (must be created via setup-b24.php)
            'UF_KINT_BOOKING_ID'     => $id,
            'UF_KINT_SANATORIUM'     => $sanatorium,
            'UF_KINT_GUESTS'         => (int)($booking['КоличествоГостей'] ?? 1),
            'UF_KINT_DATE_FROM'      => $dateFrom,
            'UF_KINT_DATE_TO'        => $dateTo,
            'UF_KINT_ROOM'           => $booking['Номер']            ?? '',
            'UF_KINT_ROOM_CATEGORY'  => $booking['КатегорияНомера'] ?? '',
            'UF_KINT_BOOKED'         => (int)($booking['Booked'] ?? $booking['Забронировано'] ?? 0),
            'UF_KINT_TO_PROCESS'     => (int)($booking['ToProcess'] ?? $booking['КОбработке'] ?? 0),
        ];

        if ($contactId !== null) {
            $fields['CONTACT_ID'] = $contactId;
        }

        return array_filter($fields, fn($v) => $v !== '' && $v !== null);
    }

    // ── Invoice → Deal update ─────────────────────────────────────────────────

    public static function invoiceFields(array $invoice): array
    {
        $fields = [
            'UF_KINT_INVOICE_ID'     => (string)($invoice['Идентификатор'] ?? ''),
            'UF_KINT_INVOICE_NUMBER' => (string)($invoice['НомерСчёта']    ?? $invoice['Номер'] ?? ''),
            'UF_KINT_INVOICE_DATE'   => self::isoDate($invoice['Дата']     ?? null),
            'UF_KINT_PAYMENT_LINK'   => (string)($invoice['СсылкаОплаты']  ?? ''),
            'UF_KINT_PAYMENT_QR'     => (string)($invoice['QRКод']         ?? ''),
        ];
        $amountKey = array_key_exists('Сумма', $invoice) ? 'Сумма' : 'СуммаДокумента';
        if (array_key_exists($amountKey, $invoice)) {
            $fields['UF_KINT_INVOICE_AMOUNT'] = (float)$invoice[$amountKey];
        }
        return array_filter($fields, fn($v) => $v !== '' && $v !== null);
    }

    // ── Payment status → Deal update ──────────────────────────────────────────

    public static function paymentFields(array $payment): array
    {
        $fields = [
            'UF_KINT_PAYMENT_ID'       => (string)($payment['Идентификатор']    ?? ''),
            'UF_KINT_PAYMENT_NUMBER'   => (string)($payment['НомерПлатежа']     ?? $payment['Номер'] ?? ''),
            'UF_KINT_PAYMENT_DATE'     => self::isoDate($payment['ДатаПлатежа'] ?? null),
            'UF_KINT_PAYMENT_STATUS'   => (string)($payment['СтатусОплаты']     ?? ''),
        ];
        foreach ([
            'UF_KINT_PAID_AMOUNT' => ['СуммаПлатежа'],
            'UF_KINT_INVOICED_TOTAL' => ['Выставлено', 'ИтогоНачислено'],
            'UF_KINT_PAID_TOTAL' => ['Оплачено', 'ИтогоОплачено'],
        ] as $target => $sources) {
            foreach ($sources as $source) {
                if (array_key_exists($source, $payment)) {
                    $fields[$target] = (float)$payment[$source];
                    break;
                }
            }
        }
        return array_filter($fields, fn($v) => $v !== '' && $v !== null);
    }

    // ── Guest card → Deal update ──────────────────────────────────────────────

    public static function guestCardFields(array $card): array
    {
        return array_filter([
            'UF_KINT_GUEST_CARD_ID' => (string)($card['Идентификатор']      ?? ''),
            'UF_KINT_GC_NUMBER'     => (string)($card['НомерКарты']          ?? $card['Номер'] ?? ''),
            // Prototype primary key: ДатаЗаезда; fallback: ДатаПрибытия
            'UF_KINT_GC_ARRIVAL'    => self::isoDate($card['ДатаЗаезда']     ?? $card['ДатаПрибытия'] ?? null),
            // Prototype primary key: ДатаВыезда; fallback: ДатаВыбытия
            'UF_KINT_GC_DEPARTURE'  => self::isoDate($card['ДатаВыезда']     ?? $card['ДатаВыбытия'] ?? null),
            'UF_KINT_STAY_VARIANT'  => (string)($card['ВариантПребывания']   ?? ''),
            // Prototype primary key: ВариантЛечения; fallback: ПрограммаЛечения
            'UF_KINT_TREATMENT'     => (string)($card['ВариантЛечения']      ?? $card['ПрограммаЛечения'] ?? ''),
            'UF_KINT_MEAL'          => (string)($card['ТипПитания']          ?? ''),
            // Prototype primary key: НомерГостиницы; fallback: НомерКомнаты
            'UF_KINT_GC_ROOM'       => (string)($card['НомерГостиницы']      ?? $card['НомерКомнаты'] ?? ''),
            // Checkout fields
            'UF_KINT_ACTUAL_DEPARTURE'  => self::isoDate($card['ФактическийВыезд']   ?? null),
            'UF_KINT_EARLY_DEPARTURE'   => self::isoDate($card['РаннийВыезд']         ?? null),
            'UF_KINT_PLANNED_DEPARTURE' => self::isoDate($card['ПлановыйВыезд']       ?? null),
        ], fn($v) => $v !== '' && $v !== null);
    }

    // ── Guest → Contact ───────────────────────────────────────────────────────

    /**
     * @param array $guest  КИНТ физическое лицо
     */
    public static function contactFields(array $guest): array
    {
        $nameParts = self::splitFio($guest['ФИО'] ?? $guest['Наименование'] ?? '');

        $fields = [
            'UF_KINT_GUEST_ID' => (string)($guest['ID'] ?? $guest['Идентификатор'] ?? ''),
            'NAME'           => $nameParts['name'],
            'LAST_NAME'      => $nameParts['last'],
            'SECOND_NAME'    => $nameParts['middle'],
            'BIRTHDATE'      => self::isoDate($guest['ДатаРождения'] ?? null),
            'UF_KINT_GENDER' => $guest['Пол'] ?? '',
            'UF_KINT_AGE'    => isset($guest['Возраст']) ? (int)$guest['Возраст'] : null,
        ];

        if (!empty($guest['Телефон'])) {
            $fields['PHONE'] = [['VALUE' => $guest['Телефон'], 'VALUE_TYPE' => 'WORK']];
        }
        if (!empty($guest['Email'])) {
            $fields['EMAIL'] = [['VALUE' => $guest['Email'], 'VALUE_TYPE' => 'WORK']];
        }

        return array_filter($fields, fn($v) => $v !== '' && $v !== null && $v !== []);
    }

    // ── Catalog service → Product row ─────────────────────────────────────────

    /**
     * Map КИНТ service/catalog item → B24 productrow structure.
     */
    public static function catalogItemToProductRow(array $item, float $price = 0, int $qty = 1): array
    {
        return [
            'PRODUCT_NAME' => $item['Наименование'] ?? 'Услуга',
            'PRICE'        => $price > 0 ? $price : (float)($item['Цена'] ?? 0),
            'QUANTITY'     => $qty,
            'TAX_RATE'     => 0,
            'DISCOUNT_SUM' => 0,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Convert КИНТ date (various formats) to ISO 8601 string or null. */
    public static function isoDate(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        // Try strtotime — handles ISO, Russian dot-format etc.
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }

    /** Split "Фамилия Имя Отчество" into parts. */
    private static function splitFio(string $fio): array
    {
        $parts  = preg_split('/\s+/', trim($fio));
        return [
            'last'   => $parts[0] ?? '',
            'name'   => $parts[1] ?? '',
            'middle' => $parts[2] ?? '',
        ];
    }
}
