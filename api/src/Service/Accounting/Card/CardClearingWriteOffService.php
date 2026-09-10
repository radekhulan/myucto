<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\Card\CardPaymentOverview;
use PDO;

/**
 * „Uzavřít bez dokladu" — platba kartou, ke které doklad nebude, se z mezičlenu odúčtuje
 * interním dokladem (`card_writeoff`, source_id = pohyb):
 *
 *   - `expense` → MD 548 (výchozí nedaňová analytika 548.x) / D 378.x — nedaňový náklad
 *     bez DPH,
 *   - `holder`  → MD 335.x / D 378.x — k tíži držitele karty.
 *
 * Bankovní zápis platby zůstává. Dorazí-li doklad později, vypořádání uzavření samo
 * stornuje (viz BankPostingService::syncCardSettlement) — uzavření tedy není konečné.
 */
final class CardClearingWriteOffService
{
    public const TARGETS = ['expense', 'holder'];

    public function __construct(
        private readonly Connection $db,
        private readonly BankPostingService $bankPosting,
        private readonly CardSettlementService $settlements,
        private readonly CardClearingRegime $regime,
        private readonly CardClearingSettingsService $settingsService,
        private readonly CardPaymentOverview $overview,
    ) {}

    /** @return array{entry_id:int, account_code:string} */
    public function writeOff(int $supplierId, int $txId, string $target, ?int $accountId, ?int $userId): array
    {
        if (!in_array($target, self::TARGETS, true)) {
            throw new PostingException('invalid_target', 'Neplatný způsob uzavření platby.', 422, ['field' => 'target']);
        }
        $tx = $this->overview->findCardTransaction($supplierId, $txId);
        if ($tx === null) {
            throw new PostingException('not_found', 'Platba kartou nenalezena.', 404);
        }
        $clearing = $this->bankPosting->liveCardClearingLine($supplierId, $txId);
        if ($clearing === null) {
            throw new PostingException('not_card_clearing', 'Platba není zaúčtovaná přes mezičlen karty.', 409);
        }
        if ($this->hasAllocation($supplierId, $txId)
            || $this->settlements->hasLive($supplierId, $txId, CardSettlementService::SOURCE_SETTLEMENT)) {
            throw new PostingException('has_document', 'K platbě už je spárovaný doklad — uzavření bez dokladu nedává smysl.', 409);
        }

        $code = $this->accountCode($supplierId, $target, $accountId);
        $accountSide = $clearing['side'];
        $clearingSide = $accountSide === 'debit' ? 'credit' : 'debit';
        $clearingLine = ['account_code' => $clearing['code'], 'side' => $clearingSide, 'amount' => $clearing['amount']];
        if ($clearing['currency_code'] !== null) {
            $clearingLine['currency_code'] = $clearing['currency_code'];
            $clearingLine['fx_rate'] = $clearing['fx_rate'];
            $clearingLine['amount_foreign'] = $clearing['amount_foreign'];
        }
        $lines = [
            ['account_code' => $code, 'side' => $accountSide, 'amount' => $clearing['amount']],
            $clearingLine,
        ];
        $res = $this->settlements->sync($supplierId, $txId, CardSettlementService::SOURCE_WRITEOFF, $lines, [
            'txDate'      => substr($tx['posted_at'], 0, 10),
            'description' => $target === 'holder'
                ? 'Platba kartou bez dokladu k tíži držitele karty'
                : 'Platba kartou bez dokladu — nedaňový náklad',
            'document_no' => 'KARTA-' . $txId,
            'user_id'     => $userId,
        ]);
        if (!isset($res['entry_id']) || ($res['action'] ?? '') === 'skipped') {
            throw new PostingException('period_closed', 'Období platby je uzavřené — uzavření nelze zaúčtovat.', 409);
        }
        return ['entry_id' => (int) $res['entry_id'], 'account_code' => $code];
    }

    /** Zrušení uzavření (storno). Vrací id storna, null když uzavření neexistuje. */
    public function cancel(int $supplierId, int $txId, ?int $userId): ?int
    {
        if ($this->overview->findCardTransaction($supplierId, $txId) === null) {
            throw new PostingException('not_found', 'Platba kartou nenalezena.', 404);
        }
        return $this->settlements->reverseLive($supplierId, $txId, CardSettlementService::SOURCE_WRITEOFF, ['user_id' => $userId]);
    }

    private function accountCode(int $supplierId, string $target, ?int $accountId): string
    {
        $prefix = $target === 'holder' ? '335' : '5';
        if ($accountId !== null && $accountId > 0) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT account_code FROM chart_of_accounts WHERE id = ? AND supplier_id = ? AND is_active = 1'
            );
            $stmt->execute([$accountId, $supplierId]);
            $code = $stmt->fetchColumn();
            if (!is_string($code) || !str_starts_with($code, $prefix)) {
                throw new PostingException('invalid_account', 'Účet se pro uzavření platby nehodí.', 422, ['field' => 'account_id']);
            }
            return $code;
        }
        $settings = $this->regime->settings($supplierId);
        if ($target === 'holder') {
            return (string) (($settings['holder_account_code'] ?? null) ?: '335');
        }
        return (string) (($settings['writeoff_account_code'] ?? null) ?: $this->settingsService->defaultWriteoffCode($supplierId));
    }

    private function hasAllocation(int $supplierId, int $txId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $txId]);
        return $stmt->fetchColumn() !== false;
    }
}
