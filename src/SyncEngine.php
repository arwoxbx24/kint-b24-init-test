<?php

declare(strict_types=1);

namespace KintB24;

/**
 * One-tick orchestration: pulls data from КИНТ and pushes to Bitrix24.
 *
 * Each sync*() method catches per-item exceptions so one bad record
 * never aborts the entire tick.
 *
 * Idempotency: all creates are guarded by Store::getMapped().
 * Second run with the same data updates existing records, never duplicates.
 */
class SyncEngine
{
    public function __construct(
        protected KintClient $kint,
        protected B24Client  $b24,
        protected Store      $store,
        protected int        $windowDays = 30
    ) {}

    // ── Public entry points ───────────────────────────────────────────────────

    public function syncBookings(): int
    {
        $failures = 0;
        $this->store->info("syncBookings start window={$this->windowDays}d");

        $bookings = $this->fetchBookings();
        $this->store->info("syncBookings fetched=" . count($bookings));

        foreach ($bookings as $booking) {
            try {
                $this->processBooking($booking);
            } catch (\Throwable $e) {
                $failures++;
                $id = $booking['ID'] ?? $booking['Идентификатор'] ?? '?';
                $this->store->error("booking[$id] failed: " . $e->getMessage());
            }
        }

        $this->store->info('syncBookings done');
        return $failures;
    }

    public function syncInvoices(): int
    {
        $failures = 0;
        $this->store->info('syncInvoices start');

        // Get all deal→kint_booking_id mappings to find contacts
        $mapped = $this->store->getMappedDeals();

        foreach ($mapped as $row) {
            try {
                $failures += $this->processInvoicesForBooking($row['kint_id'], (int)$row['b24_id']);
            } catch (\Throwable $e) {
                $failures++;
                $this->store->error("invoice[booking={$row['kint_id']}] failed: " . $e->getMessage());
            }
        }

        $this->store->info('syncInvoices done');
        return $failures;
    }

    public function syncPayments(): int
    {
        $failures = 0;
        $this->store->info('syncPayments start');

        $mapped = $this->store->getMappedDeals();

        foreach ($mapped as $row) {
            try {
                $this->processPaymentsForBooking($row['kint_id'], (int)$row['b24_id']);
            } catch (\Throwable $e) {
                $failures++;
                $this->store->error("payment[booking={$row['kint_id']}] failed: " . $e->getMessage());
            }
        }

        $this->store->info('syncPayments done');
        return $failures;
    }

    public function syncGuestCards(): int
    {
        $failures = 0;
        $this->store->info('syncGuestCards start');

        $mapped = $this->store->getMappedDeals();

        foreach ($mapped as $row) {
            try {
                $failures += $this->processGuestCardsForBooking($row['kint_id'], (int)$row['b24_id']);
            } catch (\Throwable $e) {
                $failures++;
                $this->store->error("guestcard[booking={$row['kint_id']}] failed: " . $e->getMessage());
            }
        }

        $this->store->info('syncGuestCards done');
        return $failures;
    }

    public function syncCatalog(): int
    {
        $this->store->info('syncCatalog start');

        try {
            $catalog = $this->kint->get('GetCatalog');
            $items   = is_array($catalog) ? $catalog : [];
            $this->store->info('syncCatalog fetched items=' . count($items));
            // NOT IMPLEMENTED: crm.product sync — blocked on real catalog structure (needs KINT creds); reads only, writes nothing.
            $this->store->info('catalog sync: read-only, B24 write pending');
        } catch (\Throwable $e) {
            $this->store->error('syncCatalog failed: ' . $e->getMessage());
        }

        $this->store->info('syncCatalog done');
        return 1;
    }

    // ── Booking processing ────────────────────────────────────────────────────

    protected function fetchBookings(): array
    {
        $dateFrom = date('Y-m-d', strtotime("-{$this->windowDays} days"));
        $dateTo   = date('Y-m-d');

        return $this->kint->getPaged(
            'GetBookingList',
            ['НачалоПериода' => $dateFrom, 'КонецПериода' => $dateTo],
            ''
        );
    }

    protected function processBooking(array $booking): void
    {
        $kintId = (string)($booking['ID'] ?? $booking['Идентификатор'] ?? '');
        if ($kintId === '') {
            throw new \RuntimeException('Booking has no Идентификатор');
        }

        // Check local mapping, then reconcile after a lost/old local state.
        $dealId = $this->store->getMapped($kintId, 'deal');

        if ($dealId === null) {
            $found = $this->b24->call('crm.deal.list', [
                'filter' => ['UF_KINT_BOOKING_ID' => $kintId],
                'select' => ['ID'],
            ]);
            $dealId = $this->singleB24Id($found, 'BLOCKED_DEAL_RECONCILIATION');
            if ($dealId !== null) {
                $this->store->saveMapping($kintId, 'deal', $dealId);
            }
        }

        $contactId = $this->resolveContact($booking);
        $fields = FieldMapper::bookingToDealFields($booking, $contactId);

        if ($dealId === null) {
            $result = $this->b24->call('crm.deal.add', ['fields' => $fields]);
            $dealId = (int)($result['ID'] ?? $result);
            $this->store->saveMapping($kintId, 'deal', $dealId);
            $this->store->info("booking[$kintId] deal created id=$dealId");
        } else {
            $this->b24->call('crm.deal.update', ['id' => $dealId, 'fields' => $fields]);
            $this->store->info("booking[$kintId] deal updated id=$dealId");
        }

    }

    protected function resolveContact(array $booking): ?int
    {
        $guest = $booking['Гость'] ?? $booking['ФизЛицо'] ?? null;
        if (!is_array($guest)) {
            return null;
        }

        $guestId = (string)($guest['ID'] ?? $guest['Идентификатор'] ?? '');
        if ($guestId === '') {
            return null;
        }

        $contactId = $this->store->getMapped($guestId, 'contact');
        if ($contactId !== null) {
            $this->b24->call('crm.contact.update', [
                'id' => $contactId,
                'fields' => FieldMapper::contactFields($guest),
            ]);
            return $contactId;
        }

        $fields = FieldMapper::contactFields($guest);

        $byGuestId = $this->b24->call('crm.contact.list', [
            'filter' => ['UF_KINT_GUEST_ID' => $guestId],
            'select' => ['ID'],
        ]);
        $contactId = $this->singleB24Id($byGuestId, 'BLOCKED_CONTACT_RECONCILIATION');

        if ($contactId === null) {
            $result    = $this->b24->call('crm.contact.add', ['fields' => $fields]);
            $contactId = (int)($result['ID'] ?? $result);
        } else {
            $this->b24->call('crm.contact.update', ['id' => $contactId, 'fields' => $fields]);
        }

        $this->store->saveMapping($guestId, 'contact', $contactId);
        return $contactId;
    }

    // ── Invoice processing ────────────────────────────────────────────────────

    protected function processInvoicesForBooking(string $kintBookingId, int $dealId): int
    {
        $failures = 0;
        $invoices = $this->kint->getPaged(
            'GetInvoices',
            ['Основание' => ['ID' => $kintBookingId], 'Fields' => 'СуммаДокумента'],
            ''
        );
        if (count($invoices) > 1) {
            throw new \RuntimeException('BLOCKED_MULTIPLE_INVOICES');
        }

        foreach ($invoices as $invoice) {
            $invoiceId = (string)($invoice['Идентификатор'] ?? '');
            if ($invoiceId === '') {
                $failures++;
                $this->store->error('invoice BLOCKED_INVOICE_ID');
                continue;
            }

            $amount = array_key_exists('Сумма', $invoice) ? $invoice['Сумма'] : ($invoice['СуммаДокумента'] ?? null);
            if (!is_numeric($amount) || !is_finite((float)$amount)) {
                $failures++;
                $this->store->error('invoice BLOCKED_INVOICE_PAYLOAD');
                continue;
            }

            $fields = FieldMapper::invoiceFields($invoice);
            $stateKey = "invoice:$invoiceId";
            $hash = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
            if ($this->store->getState($stateKey) === $hash) {
                continue;
            }

            try {
                $this->b24->call('crm.deal.update', ['id' => $dealId, 'fields' => $fields]);
                $this->store->saveMapping($invoiceId, 'invoice', $dealId);
                $this->store->saveState($stateKey, $hash);
                $this->store->info("invoice[$invoiceId] → deal[$dealId]");
            } catch (\Throwable $e) {
                $failures++;
                $this->store->error("invoice[$invoiceId] failed: " . $e->getMessage());
            }
        }
        return $failures;
    }

    // ── Payment processing ────────────────────────────────────────────────────

    protected function processPaymentsForBooking(string $kintBookingId, int $dealId): void
    {
        $status = $this->kint->get('GetData', [
            'Method'   => 'PaymentStatusByDocument',
            'Document' => ['ID' => $kintBookingId],
        ]);

        if (!is_array($status) || $status === []) {
            throw new \RuntimeException('BLOCKED_PAYMENT_PAYLOAD');
        }

        $recognized = false;
        foreach (['СуммаПлатежа', 'Выставлено', 'ИтогоНачислено', 'Оплачено', 'ИтогоОплачено'] as $key) {
            if (array_key_exists($key, $status)) {
                if (!is_numeric($status[$key])) {
                    throw new \RuntimeException('BLOCKED_PAYMENT_PAYLOAD');
                }
                $recognized = true;
            }
        }
        if (array_key_exists('СтатусОплаты', $status)) {
            if (!is_string($status['СтатусОплаты']) || trim($status['СтатусОплаты']) === '') {
                throw new \RuntimeException('BLOCKED_PAYMENT_PAYLOAD');
            }
            $recognized = true;
        }
        if (!$recognized) {
            throw new \RuntimeException('BLOCKED_PAYMENT_PAYLOAD');
        }

        $paymentId = (string)($status['Идентификатор'] ?? $kintBookingId . '_pay');

        $fields = FieldMapper::paymentFields($status);
        if ($fields === []) {
            return;
        }

        $stateKey = "payment:$paymentId";
        $hash = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
        if ($this->store->getState($stateKey) === $hash) {
            return;
        }

        $this->b24->call('crm.deal.update', ['id' => $dealId, 'fields' => $fields]);
        $this->store->saveMapping($paymentId, 'payment', $dealId);
        $this->store->saveState($stateKey, $hash);
        $this->store->info("payment[$paymentId] → deal[$dealId]");
    }

    // ── Guest card processing ─────────────────────────────────────────────────

    protected function processGuestCardsForBooking(string $kintBookingId, int $dealId): int
    {
        $this->store->error("guestcard[booking=$kintBookingId] BLOCKED_KINT_CARD_RELATION");
        return 1;
    }

    protected function applyGuestCards(array $cards, int $dealId): int
    {
        if (count($cards) > 1) {
            throw new \RuntimeException('BLOCKED_MULTIPLE_GUEST_CARDS');
        }
        $failures = 0;
        foreach ($cards as $card) {
            $cardId = (string)($card['Идентификатор'] ?? '');
            if ($cardId === '') {
                continue;
            }

            $fields = FieldMapper::guestCardFields($card);
            $hash = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
            $stateKey = "guestcard:$cardId";

            // Idempotency gate: skip if already synced with identical payload
            if ($this->store->getState($stateKey) === $hash) {
                $this->store->info("guestcard[$cardId] unchanged, skip");
                continue;
            }

            try {
                $this->b24->call('crm.deal.update', ['id' => $dealId, 'fields' => $fields]);
                $this->store->saveMapping($cardId, 'guestcard', $dealId);
                $this->store->saveState($stateKey, $hash);
                $this->store->info("guestcard[$cardId] → deal[$dealId]");
            } catch (\Throwable $e) {
                $failures++;
                $this->store->error("guestcard[$cardId] failed: " . $e->getMessage());
            }
        }
        return $failures;
    }

    protected function singleB24Id(mixed $rows, string $blocker): ?int
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1) {
            throw new \RuntimeException($blocker);
        }
        if ($rows === []) {
            return null;
        }

        $rawId = is_array($rows[0]) ? ($rows[0]['ID'] ?? null) : null;
        if (!(is_int($rawId) || (is_string($rawId) && ctype_digit($rawId))) || (int)$rawId <= 0) {
            throw new \RuntimeException($blocker);
        }
        return (int)$rawId;
    }
}
