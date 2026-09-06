#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-time B24 setup: create UF_ custom fields on deals and contacts.
 * Idempotent — lists existing fields first, skips if already present.
 *
 * Usage: php bin/setup-b24.php
 *
 * Run once before first sync. Requires B24_WEBHOOK_URL in .env with
 * appropriate permissions (crm scope).
 */

require_once dirname(__DIR__) . '/autoload.php';

use KintB24\Config;
use KintB24\B24Client;

try {
    $cfg = new Config();
} catch (\RuntimeException $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$b24 = new B24Client($cfg->b24WebhookUrl());

// ── Field definitions ─────────────────────────────────────────────────────────

$dealFields = [
    // Booking
    ['FIELD_NAME' => 'UF_KINT_BOOKING_ID',    'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: ID брони']],
    ['FIELD_NAME' => 'UF_KINT_SANATORIUM',     'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Санаторий']],
    ['FIELD_NAME' => 'UF_KINT_GUESTS',         'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Гостей']],
    ['FIELD_NAME' => 'UF_KINT_DATE_FROM',      'USER_TYPE_ID' => 'date',    'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Дата заезда']],
    ['FIELD_NAME' => 'UF_KINT_DATE_TO',        'USER_TYPE_ID' => 'date',    'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Дата выезда']],
    ['FIELD_NAME' => 'UF_KINT_ROOM',           'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Номер комнаты']],
    ['FIELD_NAME' => 'UF_KINT_ROOM_CATEGORY',  'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Категория номера']],
    ['FIELD_NAME' => 'UF_KINT_BOOKED',         'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Забронировано']],
    ['FIELD_NAME' => 'UF_KINT_TO_PROCESS',     'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: К обработке']],
    // Invoice
    ['FIELD_NAME' => 'UF_KINT_INVOICE_ID',     'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: ID счёта']],
    ['FIELD_NAME' => 'UF_KINT_INVOICE_NUMBER', 'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Номер счёта']],
    ['FIELD_NAME' => 'UF_KINT_INVOICE_DATE',   'USER_TYPE_ID' => 'date',    'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Дата счёта']],
    ['FIELD_NAME' => 'UF_KINT_INVOICE_AMOUNT', 'USER_TYPE_ID' => 'double',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Сумма счёта']],
    ['FIELD_NAME' => 'UF_KINT_PAYMENT_LINK',   'USER_TYPE_ID' => 'url',     'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Ссылка оплаты']],
    ['FIELD_NAME' => 'UF_KINT_PAYMENT_QR',     'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: QR оплаты']],
    // Payment
    ['FIELD_NAME' => 'UF_KINT_PAYMENT_ID',     'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: ID платежа']],
    ['FIELD_NAME' => 'UF_KINT_PAYMENT_NUMBER', 'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Номер платежа']],
    ['FIELD_NAME' => 'UF_KINT_PAYMENT_DATE',   'USER_TYPE_ID' => 'date',    'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Дата платежа']],
    ['FIELD_NAME' => 'UF_KINT_PAID_AMOUNT',    'USER_TYPE_ID' => 'double',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Сумма платежа']],
    ['FIELD_NAME' => 'UF_KINT_INVOICED_TOTAL', 'USER_TYPE_ID' => 'double',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Итого начислено']],
    ['FIELD_NAME' => 'UF_KINT_PAID_TOTAL',     'USER_TYPE_ID' => 'double',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Итого оплачено']],
    ['FIELD_NAME' => 'UF_KINT_PAYMENT_STATUS', 'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Статус оплаты']],
    // Guest card
    ['FIELD_NAME' => 'UF_KINT_GUEST_CARD_ID',  'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: ID карты гостя']],
    ['FIELD_NAME' => 'UF_KINT_GC_NUMBER',      'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Номер карты гостя']],
    ['FIELD_NAME' => 'UF_KINT_GC_ARRIVAL',     'USER_TYPE_ID' => 'date',    'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Дата прибытия']],
    ['FIELD_NAME' => 'UF_KINT_GC_DEPARTURE',   'USER_TYPE_ID' => 'date',    'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Дата выбытия']],
    ['FIELD_NAME' => 'UF_KINT_STAY_VARIANT',   'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Вариант пребывания']],
    ['FIELD_NAME' => 'UF_KINT_TREATMENT',      'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Лечение']],
    ['FIELD_NAME' => 'UF_KINT_MEAL',           'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Питание']],
    ['FIELD_NAME' => 'UF_KINT_GC_ROOM',        'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Комната гостя']],
    // Checkout
    ['FIELD_NAME' => 'UF_KINT_ACTUAL_DEPARTURE',  'USER_TYPE_ID' => 'date', 'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Фактический выезд']],
    ['FIELD_NAME' => 'UF_KINT_EARLY_DEPARTURE',   'USER_TYPE_ID' => 'date', 'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Ранний выезд']],
    ['FIELD_NAME' => 'UF_KINT_PLANNED_DEPARTURE',  'USER_TYPE_ID' => 'date', 'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Плановый выезд']],
];

$contactFields = [
    ['FIELD_NAME' => 'UF_KINT_GUEST_ID', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: ID гостя']],
    ['FIELD_NAME' => 'UF_KINT_GENDER', 'USER_TYPE_ID' => 'string',  'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Пол']],
    ['FIELD_NAME' => 'UF_KINT_AGE',    'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => ['ru' => 'КИНТ: Возраст']],
];

// ── Helpers ───────────────────────────────────────────────────────────────────

function existingUfFields(B24Client $b24, string $entity): array
{
    $list = $b24->call("crm.{$entity}.userfield.list", []);
    $names = [];
    foreach ((array)$list as $f) {
        $names[] = $f['FIELD_NAME'];
    }
    return $names;
}

function ensureFields(B24Client $b24, string $entity, array $fields): int
{
    echo "  Loading existing {$entity} UF fields...\n";
    $existing = existingUfFields($b24, $entity);
    $failures = 0;

    foreach ($fields as $def) {
        if (in_array($def['FIELD_NAME'], $existing, true)) {
            echo "  [SKIP] {$def['FIELD_NAME']} already exists\n";
            continue;
        }
        try {
            $b24->call("crm.{$entity}.userfield.add", ['fields' => $def]);
            echo "  [OK]   {$def['FIELD_NAME']} created\n";
        } catch (\Throwable $e) {
            $failures++;
            echo "  [FAIL] {$def['FIELD_NAME']}: " . $e->getMessage() . "\n";
        }
    }
    return $failures;
}

// ── Run ───────────────────────────────────────────────────────────────────────

echo "=== КИНТ B24 Setup ===\n\n";

echo "Creating deal UF fields...\n";
$failures = ensureFields($b24, 'deal', $dealFields);

echo "\nCreating contact UF fields...\n";
$failures += ensureFields($b24, 'contact', $contactFields);

echo <<<MANUAL

=== MANUAL STEPS REQUIRED ===

Create deal pipeline stages in Bitrix24 CRM → Settings → Sales Funnel:

  Stage code      Label
  ─────────────── ──────────────────
  NEW             Новое
  INVOICE_SENT    Счёт выставлен
  PARTIALLY_PAID  Частичная оплата
  PAID            Оплачено
  CHECKED_IN      Заезд
  CHECKED_OUT     Выезд
  EARLY_CHECKOUT  Ранний выезд
  CANCELED        Отменено (failed)

Set STAGE_ID values in FieldMapper::dealStageMapping() to match
the actual stage symbolic codes from your pipeline if they differ.

=== Field setup finished; check failures and manual stages ===
MANUAL;

exit($failures > 0 ? 1 : 0);
