<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PaymentOrderRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Export\ExportFilename;
use MyInvoice\Service\Pdf\PaymentOrderPdfRenderer;
use MyInvoice\Support\PaymentMethods;

/**
 * Platební příkazy (payment orders) — hromadné generování příkazu k úhradě z
 * nezaplacených přijatých faktur.
 *
 * Tok: kandidáti (nezaplacené faktury + ověření účtu) → výběr + účet plátce + datum
 * → snapshot dávky (payment_orders + items) → export CSV / PDF / ABO(KPC) / SEPA.
 * Po vytvoření se faktury označí `payment_ordered_at` („Zařazeno k úhradě");
 * status se NEpřeklápí na paid (to dělá až párování výpisu), pokud uživatel výslovně
 * nezvolí `mark_paid`.
 *
 * ABO/KPC je tuzemský CZK platební styk → dávka má jednu měnu (= měna účtu plátce);
 * cizí měny (EUR…) lze exportovat do CSV/PDF, a je-li plátce i příjemce identifikován
 * přes IBAN, i do SEPA XML (`SepaPaymentOrderWriter`, ISO 20022 pain.001.001.03).
 */
final class PaymentOrderService
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $invoices,
        private readonly PaymentOrderRepository $orders,
        private readonly CrpDphClient $crpdph,
        private readonly AboPaymentOrderWriter $abo,
        private readonly PaymentOrderCsvWriter $csv,
        private readonly PaymentOrderPdfRenderer $pdf,
        private readonly SepaPaymentOrderWriter $sepa,
        private readonly IbanValidator $ibanValidator,
        private readonly Connection $db,
    ) {}

    /**
     * Kandidáti do příkazu + dostupné účty plátce. Volitelný filtr měny. Stránkovaně
     * (server-side pagination) — `$perPage`/`$offset` řídí LIMIT/OFFSET nad kandidáty,
     * `total` je COUNT přes STEJNÝ filtr (bez LIMIT).
     *
     * Defaultně jen faktury hrazené převodem — inkaso (SIPO) si dodavatel strhne sám a
     * příkaz by znamenal dvojí platbu. `$includeNonTransfer` filtr vypne (opt-out), aby
     * šla najít a opravit faktura s chybně nastavenou formou úhrady.
     *
     * @return array{payer_accounts: list<array<string,mixed>>, candidates: list<array<string,mixed>>, total: int}
     */
    public function candidates(int $supplierId, ?string $currency = null, int $perPage = 50, int $offset = 0, bool $includeNonTransfer = false): array
    {
        $payerAccounts = $this->orders->payerAccounts($supplierId);
        $rows = $this->invoices->listPaymentCandidates($supplierId, $currency, $perPage, $offset, $includeNonTransfer);
        $total = $this->invoices->countPaymentCandidates($supplierId, $currency, $includeNonTransfer);

        $candidates = [];
        foreach ($rows as $r) {
            $payee = [
                'account_number' => $r['payment_account_number'] ?? null,
                'bank_code'      => $r['payment_bank_code'] ?? null,
                'iban'           => $r['payment_iban'] ?? null,
                'bic'            => $r['payment_bic'] ?? null,
            ];
            $hasCz   = ($payee['account_number'] ?? '') !== '' && ($payee['bank_code'] ?? '') !== '';
            $hasIban = ($payee['iban'] ?? '') !== '';

            $candidates[] = [
                'id'                     => $r['id'],
                'vendor_id'              => $r['vendor_id'],
                'vendor_company_name'    => $r['vendor_company_name'],
                'vendor_dic'             => $r['vendor_dic'],
                'vendor_invoice_number'  => $r['vendor_invoice_number'],
                'varsymbol'              => $r['varsymbol'],
                'document_kind'          => $r['document_kind'],
                'issue_date'             => $r['issue_date'],
                'due_date'               => $r['due_date'],
                'currency'               => $r['currency'],
                'currency_symbol'        => $r['currency_symbol'],
                // Částka PO zaokrouhlení dokladu (issue #166) — zaokrouhlení se
                // vede mimo amount_to_pay, doplníme ho zde, ať UI i příkaz ukazují
                // a předepisují skutečnou úhradu.
                'amount_to_pay'          => round((float) $r['amount_to_pay'] + (float) ($r['rounding'] ?? 0), 2),
                'total_with_vat'         => $r['total_with_vat'],
                'account_number'         => $payee['account_number'],
                'bank_code'              => $payee['bank_code'],
                'iban'                   => $payee['iban'],
                'bic'                    => $payee['bic'],
                'variable_symbol'        => $this->variableSymbol($r),
                'constant_symbol'        => $r['payment_constant_symbol'] ?? null,
                'payment_account_source' => $r['payment_account_source'] ?? null,
                'payment_ordered_at'     => $r['payment_ordered_at'] ?? null,
                'payment_method'         => PaymentMethods::normalize($r['payment_method'] ?? null),
                'payment_method_source'  => PaymentMethods::normalizeSource($r['payment_method_source'] ?? null),
                'has_account'            => $hasCz || $hasIban,
                'has_pdf'                => (bool) ($r['has_pdf'] ?? false),
                // Otevírá náhled dokladu v drawer; null u nezaúčtovaných dokladů
                // a v daňové evidenci, kde deník neexistuje — tam se jde na detail.
                'journal_entry_id'       => $r['journal_entry_id'] ?? null,
                'abo_eligible'           => $hasCz && strtoupper((string) $r['currency']) === 'CZK',
                'sepa_eligible'          => $hasIban && $this->ibanValidator->isValid((string) $payee['iban']),
                'can_verify'             => $this->canVerify((string) ($r['vendor_dic'] ?? '')),
                'account_verified'       => $this->verify((string) ($r['vendor_dic'] ?? ''), $payee),
            ];
        }

        return ['payer_accounts' => $payerAccounts, 'candidates' => $candidates, 'total' => $total];
    }

    /**
     * Vytvoří (uloží) platební příkaz ze zvolených faktur.
     *
     * @param array{invoice_ids?:list<int>, payer_currency_id?:int, payment_date?:string,
     *              constant_symbol?:?string, note?:?string, mark_paid?:bool} $input
     * @return array{order_id:int, view:array<string,mixed>, skipped:list<array{id:int,reason:string}>,
     *               clamped_date:bool}
     *
     * @throws \InvalidArgumentException při neplatném vstupu / prázdné dávce
     */
    public function create(int $supplierId, array $input, ?int $userId): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($input['invoice_ids'] ?? []))));
        if ($ids === []) {
            throw new \InvalidArgumentException('Není vybrána žádná faktura.');
        }
        if (count($ids) > 500) {
            throw new \InvalidArgumentException('Najednou lze zařadit maximálně 500 faktur.');
        }

        $payerCurrencyId = (int) ($input['payer_currency_id'] ?? 0);
        $payer = $payerCurrencyId > 0 ? $this->orders->payerAccount($payerCurrencyId, $supplierId) : null;
        if ($payer === null) {
            throw new \InvalidArgumentException('Vyberte platný účet plátce.');
        }
        $orderCurrency = strtoupper((string) $payer['code']);

        // Datum splatnosti: ABO nesmí mít datum v minulosti → ořízni na dnešek.
        $today = date('Y-m-d');
        $paymentDate = (string) ($input['payment_date'] ?? '');
        $paymentDate = $paymentDate !== '' ? date('Y-m-d', strtotime($paymentDate)) : $today;
        $clamped = false;
        if ($paymentDate < $today) {
            $paymentDate = $today;
            $clamped = true;
        }

        $batchKs = $this->digitsOrNull((string) ($input['constant_symbol'] ?? ''));

        $items = [];
        $skipped = [];
        $validIds = [];
        foreach ($ids as $id) {
            $inv = $this->invoices->find($id, $supplierId);
            if ($inv === null) {
                $skipped[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }
            if (strtoupper((string) ($inv['currency'] ?? '')) !== $orderCurrency) {
                $skipped[] = ['id' => $id, 'reason' => 'currency_mismatch'];
                continue;
            }
            // Pojistka proti dvojí platbě: faktura hrazená jinak než převodem (inkaso/SIPO,
            // karta, dobírka, zápočet) se do příkazu NIKDY nedostane, ani když ji uživatel
            // vybere ve výpisu se zapnutým opt-outem (`include_non_transfer`) nebo když se
            // forma úhrady změnila mezi načtením seznamu a odesláním výběru.
            if (!PaymentMethods::isBankTransfer($inv['payment_method'] ?? null)) {
                $skipped[] = ['id' => $id, 'reason' => 'direct_debit'];
                continue;
            }
            $amount = (float) ($inv['amount_to_pay'] ?? 0);
            if ($amount <= 0) {
                $skipped[] = ['id' => $id, 'reason' => 'nothing_to_pay'];
                continue;
            }
            // Zaokrouhlení dokladu se vede mimo amount_to_pay → přičti ho, ať
            // se předepíše částka PO zaokrouhlení (issue #166).
            $amount = round($amount + (float) ($inv['rounding'] ?? 0), 2);
            $account = $inv['payment_account_number'] ?? null;
            $bank    = $inv['payment_bank_code'] ?? null;
            $iban    = $inv['payment_iban'] ?? null;
            $hasCz   = ($account ?? '') !== '' && ($bank ?? '') !== '';
            if (!$hasCz && ($iban ?? '') === '') {
                $skipped[] = ['id' => $id, 'reason' => 'no_account'];
                continue;
            }

            $payee = ['account_number' => $account, 'bank_code' => $bank, 'iban' => $iban, 'bic' => $inv['payment_bic'] ?? null];
            $vs = $this->variableSymbol($inv);
            $message = (string) ($inv['vendor_invoice_number'] ?? '');
            if ($message === '') {
                $message = $vs;
            }

            $items[] = [
                'purchase_invoice_id' => $id,
                'payee_name'          => $inv['vendor_company_name'] ?? null,
                'payee_account_number' => $account,
                'payee_bank_code'     => $bank,
                'payee_iban'          => $iban,
                'payee_bic'           => $inv['payment_bic'] ?? null,
                'amount'              => $amount,
                'currency'            => $orderCurrency,
                'variable_symbol'     => $vs !== '' ? $vs : null,
                'constant_symbol'     => $batchKs ?? $this->digitsOrNull((string) ($inv['payment_constant_symbol'] ?? '')),
                'specific_symbol'     => null,
                'message'             => $message !== '' ? $message : null,
                'account_verified'    => $this->verify((string) ($inv['vendor_dic'] ?? ''), $payee),
            ];
            $validIds[] = $id;
        }

        if ($items === []) {
            throw new \InvalidArgumentException('Žádná z vybraných faktur není pro příkaz použitelná.');
        }

        $total = 0.0;
        foreach ($items as $it) {
            $total += (float) $it['amount'];
        }

        $orderId = $this->orders->create([
            'supplier_id'          => $supplierId,
            'currency'             => $orderCurrency,
            'payer_currency_id'    => $payerCurrencyId,
            'payer_account_number' => $payer['account_number'] ?? null,
            'payer_bank_code'      => $payer['bank_code'] ?? null,
            'payer_iban'           => $payer['iban'] ?? null,
            'payer_bic'            => $payer['bic'] ?? null,
            'payer_account_label'  => $payer['label'] ?? null,
            'payment_date'         => $paymentDate,
            'total_amount'         => $total,
            'note'                 => $this->nullableString($input['note'] ?? null),
            'mark_paid'            => !empty($input['mark_paid']),
            'created_by_user_id'   => $userId,
        ], $items);

        // „Zařazeno k úhradě" + volitelný flip na zaplaceno.
        $this->invoices->markPaymentOrdered($validIds, $supplierId);
        if (!empty($input['mark_paid'])) {
            foreach ($validIds as $id) {
                $this->invoices->setStatus($id, 'paid', $supplierId, $paymentDate);
            }
        }

        $view = $this->view($orderId, $supplierId);

        return [
            'order_id'     => $orderId,
            'view'         => $view ?? [],
            'skipped'      => $skipped,
            'clamped_date' => $clamped,
        ];
    }

    /**
     * Kanonický pohled na uloženou dávku (pro frontend i writery). NULL když neexistuje.
     *
     * @return array<string,mixed>|null
     */
    public function view(int $orderId, int $supplierId): ?array
    {
        $order = $this->orders->find($orderId, $supplierId);
        if ($order === null) {
            return null;
        }
        $supplier = $this->supplierInfo($supplierId);

        $items = [];
        foreach ((array) $order['items'] as $it) {
            $items[] = [
                'purchase_invoice_id' => $it['purchase_invoice_id'],
                'payee_name'          => $it['payee_name'],
                'account_number'      => $it['payee_account_number'],
                'bank_code'           => $it['payee_bank_code'],
                'iban'                => $it['payee_iban'],
                'bic'                 => $it['payee_bic'],
                'amount'              => $it['amount'],
                'currency'            => $it['currency'],
                'variable_symbol'     => $it['variable_symbol'],
                'constant_symbol'     => $it['constant_symbol'],
                'specific_symbol'     => $it['specific_symbol'],
                'message'             => $it['message'],
                'account_verified'    => $it['account_verified'],
            ];
        }

        return [
            'id'           => $order['id'],
            'currency'     => $order['currency'],
            'payment_date' => $order['payment_date'],
            'created_at'   => $order['created_at'],
            'note'         => $order['note'],
            'mark_paid'    => $order['mark_paid'],
            'total_amount' => $order['total_amount'],
            'item_count'   => $order['item_count'],
            'payer'        => [
                'account_number' => $order['payer_account_number'],
                'bank_code'      => $order['payer_bank_code'],
                'iban'           => $order['payer_iban'],
                'bic'            => $order['payer_bic'],
                'label'          => $order['payer_account_label'],
            ],
            'supplier'     => $supplier,
            'items'        => $items,
        ];
    }

    /**
     * Historie dávek (bez položek), stránkovaně.
     *
     * @return array{0: list<array<string,mixed>>, 1: int} [rows, total]
     */
    public function history(int $supplierId, int $perPage = 50, int $offset = 0): array
    {
        return [
            $this->orders->history($supplierId, $perPage, $offset),
            $this->orders->countHistory($supplierId),
        ];
    }

    /**
     * „Jen označit" — zařadí vybrané faktury k úhradě (payment_ordered_at) BEZ vytvoření
     * dávky/exportu. Volitelně rovnou paid. Vrací počet skutečně označených (vlastněných).
     *
     * @param list<int> $invoiceIds
     */
    public function markOrdered(int $supplierId, array $invoiceIds, bool $markPaid): int
    {
        $valid = [];
        foreach (array_unique(array_map('intval', $invoiceIds)) as $id) {
            $invoice = $this->invoices->find($id, $supplierId);
            if ($invoice === null) {
                continue;
            }
            // Faktura hrazená jinak než převodem (inkaso, karta, zápočet…) se do příkazu
            // nezařazuje — a nesmí tedy dostat ani razítko „Zařazeno k úhradě". Bez téhle
            // pojistky by ji tam dostalo „Jen označit" ve chvíli, kdy má uživatel zapnuté
            // zobrazení nepřevodových faktur (opt-out u kandidátů).
            if (!PaymentMethods::isBankTransfer($invoice['payment_method'] ?? null)) {
                continue;
            }
            $valid[] = $id;
        }
        if ($valid === []) {
            return 0;
        }
        $this->invoices->markPaymentOrdered($valid, $supplierId);
        if ($markPaid) {
            foreach ($valid as $id) {
                $this->invoices->setStatus($id, 'paid', $supplierId);
            }
        }
        return count($valid);
    }

    /**
     * Vyrenderuje dávku do zvoleného formátu.
     *
     * @return array{filename:string, content_type:string, bytes:string}|null
     * @throws \RuntimeException při nepodporovaném formátu / nevhodných datech pro ABO / SEPA
     */
    public function download(int $orderId, int $supplierId, string $format): ?array
    {
        $view = $this->view($orderId, $supplierId);
        if ($view === null) {
            return null;
        }
        $datePart = ExportFilename::sanitize((string) $view['payment_date'], 'prikaz');
        $base = 'platebni-prikaz-' . $orderId . '-' . $datePart;

        return match ($format) {
            'csv' => [
                'filename'     => $base . '.csv',
                'content_type' => 'text/csv; charset=utf-8',
                'bytes'        => $this->csv->build($view),
            ],
            'pdf' => [
                'filename'     => $base . '.pdf',
                'content_type' => 'application/pdf',
                'bytes'        => $this->pdf->render($view),
            ],
            'abo', 'kpc' => [
                'filename'     => $base . '.kpc',
                'content_type' => 'text/plain; charset=utf-8',
                'bytes'        => $this->abo->build($this->toAboInput($view)),
            ],
            'sepa' => [
                'filename'     => $base . '.xml',
                'content_type' => 'application/xml; charset=utf-8',
                'bytes'        => $this->sepa->build($this->toSepaInput($view)),
            ],
            default => throw new \RuntimeException('Nepodporovaný formát: ' . $format),
        };
    }

    /**
     * Mapuje kanonický pohled na vstup pro SepaPaymentOrderWriter (ISO 20022 pain.001.001.03).
     *
     * @param array<string,mixed> $view
     * @return array<string,mixed>
     */
    private function toSepaInput(array $view): array
    {
        // SEPA Credit Transfer je EUR-only scheme (skutečná SEPA clearing síť platí
        // jen pro EUR) — zrcadlí guard toAboInput() pro CZK. Bez tohoto by přímé
        // API volání (mimo FE gate na EUR účtu plátce) vygenerovalo XML s
        // Ccy="CZK" + SvcLvl/Cd=SEPA, které banka odmítne nebo zpracuje chybně.
        if (strtoupper((string) $view['currency']) !== 'EUR') {
            throw new \RuntimeException('SEPA export je možný jen pro EUR příkazy.');
        }
        $payer = (array) $view['payer'];
        $supplier = (array) $view['supplier'];

        $items = [];
        foreach ((array) $view['items'] as $it) {
            $items[] = [
                'payee_name'      => $it['payee_name'],
                'iban'            => $it['iban'],
                'bic'             => $it['bic'],
                'amount'          => $it['amount'],
                'variable_symbol' => $it['variable_symbol'],
                'message'         => $it['message'],
            ];
        }

        return [
            'order_id'       => $view['id'],
            'initiator_name' => $supplier['company_name'] ?? null,
            'payer_name'     => $supplier['company_name'] ?? null,
            'payer_iban'     => (string) ($payer['iban'] ?? ''),
            'payer_bic'      => $payer['bic'] ?? null,
            'payment_date'   => (string) $view['payment_date'],
            'currency'       => (string) $view['currency'],
            'items'          => $items,
        ];
    }

    /**
     * Mapuje kanonický pohled na vstup pro AboPaymentOrderWriter.
     *
     * @param array<string,mixed> $view
     * @return array<string,mixed>
     */
    private function toAboInput(array $view): array
    {
        if (strtoupper((string) $view['currency']) !== 'CZK') {
            throw new \RuntimeException('ABO/KPC export je možný jen pro CZK příkazy.');
        }
        $payer = (array) $view['payer'];
        $supplier = (array) $view['supplier'];

        $items = [];
        foreach ((array) $view['items'] as $it) {
            $items[] = [
                'account_number'  => $it['account_number'],
                'bank_code'       => $it['bank_code'],
                'amount'          => $it['amount'],
                'variable_symbol' => $it['variable_symbol'],
                'constant_symbol' => $it['constant_symbol'],
                'specific_symbol' => $it['specific_symbol'],
                'message'         => $it['message'],
                // Dodavatelský příkaz smí jít i bez VS (dobropis, platba na
                // základě smlouvy) — writer je od té doby fail-closed, takže
                // se to musí povolit výslovně. Mzdové odvody tuhle výjimku
                // NEMAJÍ, tam je symbol povinný.
                'allow_missing_variable_symbol' => true,
            ];
        }

        return [
            'client_name'          => $supplier['company_name'] ?? '',
            'client_number'        => $supplier['abo_client_number'] ?? null,
            'file_number'          => str_pad((string) ((int) $view['id'] % 1000000), 6, '0', STR_PAD_LEFT),
            'payer_account_number' => (string) ($payer['account_number'] ?? ''),
            'payer_bank_code'      => (string) ($payer['bank_code'] ?? ''),
            'payment_date'         => (string) $view['payment_date'],
            'items'                => $items,
        ];
    }

    /**
     * On-demand kontrola účtu jedné faktury proti zveřejněným účtům plátce DPH (CRPDPH).
     * Vrací stav + seznam zveřejněných účtů (k ručnímu porovnání). NULL když faktura není.
     *
     * @return array{account_verified:string, found:bool, unreliable:?bool,
     *               accounts:list<string>, dic:?string}|null
     */
    public function verifyInvoiceAccount(int $supplierId, int $invoiceId): ?array
    {
        $inv = $this->invoices->find($invoiceId, $supplierId);
        if ($inv === null) {
            return null;
        }
        $dic = (string) ($inv['vendor_dic'] ?? '');
        $payee = [
            'account_number' => $inv['payment_account_number'] ?? null,
            'bank_code'      => $inv['payment_bank_code'] ?? null,
            'iban'           => $inv['payment_iban'] ?? null,
        ];

        if (!$this->canVerify($dic)) {
            return ['account_verified' => 'na', 'found' => false, 'unreliable' => null, 'accounts' => [], 'dic' => $dic ?: null];
        }

        $res = $this->crpdph->lookup($dic);
        $verified = $this->verify($dic, $payee);
        $accounts = array_values(array_filter(array_map(
            static fn ($a) => (string) ($a['display'] ?? ''),
            (array) ($res['accounts'] ?? [])
        )));

        return [
            'account_verified' => $verified,
            'found'            => (bool) ($res['found'] ?? false),
            'unreliable'       => $res['unreliable'] ?? null,
            'accounts'         => $accounts,
            'dic'              => $dic,
        ];
    }

    /** Lze ověřit přes CRPDPH? (tuzemské DIČ — 8–10 číslic). */
    private function canVerify(string $dic): bool
    {
        return preg_match('/^\d{8,10}$/', (string) preg_replace('/\D/', '', $dic)) === 1;
    }

    /**
     * Ověření účtu příjemce proti registru plátců DPH (CRPDPH). Vrací:
     *   verified   = zveřejněný účet plátce,
     *   not_listed = plátce nalezen, ale účet není mezi zveřejněnými,
     *   unreliable = nespolehlivý plátce (riziko ručení za DPH),
     *   na         = nelze ověřit (ne-CZ DIČ, prázdné, služba nedostupná).
     *
     * @param array{account_number?:?string, bank_code?:?string, iban?:?string} $payee
     */
    private function verify(string $vendorDic, array $payee): string
    {
        $digits = (string) preg_replace('/\D/', '', $vendorDic);
        if (strlen($digits) < 8) {
            return 'na';
        }
        $res = $this->crpdph->lookup($vendorDic);
        if (($res['source'] ?? '') === 'error' || empty($res['found'])) {
            return 'na';
        }
        if (($res['unreliable'] ?? null) === true) {
            return 'unreliable';
        }
        return $this->accountMatches($payee, (array) ($res['accounts'] ?? [])) ? 'verified' : 'not_listed';
    }

    /**
     * @param array{account_number?:?string, bank_code?:?string, iban?:?string} $payee
     * @param list<array{prefix:string,number:string,bank_code:string,iban:?string}> $accounts
     */
    private function accountMatches(array $payee, array $accounts): bool
    {
        $payeeIban = strtoupper((string) preg_replace('/\s+/', '', (string) ($payee['iban'] ?? '')));
        [$pPrefix, $pNumber] = $this->splitAccount((string) ($payee['account_number'] ?? ''));
        $pBank = (string) preg_replace('/\D/', '', (string) ($payee['bank_code'] ?? ''));

        foreach ($accounts as $a) {
            $aIban = strtoupper((string) ($a['iban'] ?? ''));
            if ($payeeIban !== '' && $aIban !== '' && $payeeIban === $aIban) {
                return true;
            }
            $aNumber = (string) ($a['number'] ?? '');
            $aBank   = (string) ($a['bank_code'] ?? '');
            $aPrefix = (string) ($a['prefix'] ?? '');
            if ($pNumber !== '' && $aNumber !== ''
                && ltrim($pNumber, '0') === ltrim($aNumber, '0')
                && $pBank === $aBank
                && ltrim($pPrefix, '0') === ltrim($aPrefix, '0')) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:string,1:string} [prefix, number] (jen číslice, bez paddingu) */
    private function splitAccount(string $account): array
    {
        $account = (string) preg_replace('/\s+/', '', $account);
        $slash = strpos($account, '/');
        if ($slash !== false) {
            $account = substr($account, 0, $slash);
        }
        if (str_contains($account, '-')) {
            [$p, $n] = explode('-', $account, 2);
        } else {
            $p = '';
            $n = $account;
        }
        return [(string) preg_replace('/\D/', '', $p), (string) preg_replace('/\D/', '', $n)];
    }

    /** VS pro platbu: payment_variable_symbol → vendor_invoice_number → varsymbol. */
    private function variableSymbol(array $inv): string
    {
        foreach ([
            (string) ($inv['payment_variable_symbol'] ?? ''),
            (string) ($inv['vendor_invoice_number'] ?? ''),
            (string) ($inv['varsymbol'] ?? ''),
        ] as $c) {
            $vs = VariableSymbolNormalizer::forPayment($c);
            if ($vs !== '') {
                return $vs;
            }
        }
        return '';
    }

    /** @return array{company_name:?string, abo_client_number:?string} */
    private function supplierInfo(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, abo_client_number FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'company_name'      => $row['company_name'] ?? null,
            'abo_client_number' => $row['abo_client_number'] ?? null,
        ];
    }

    private function digitsOrNull(string $s): ?string
    {
        $d = (string) preg_replace('/\D/', '', $s);
        return $d === '' ? null : $d;
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : mb_substr($s, 0, 255);
    }
}
