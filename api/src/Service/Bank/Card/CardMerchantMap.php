<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Card;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Naučený obchodník karetní platby: text obchodníka z pohybu → dodavatel.
 *
 * Pohyb kartou nemá číslo protiúčtu, takže `bank_counterparty_map` ho nepozná. Banka
 * místo toho uvádí text obchodníka, který se s názvem dodavatele často neshoduje
 * („www.cd.cz/eshop/" proti „České dráhy"). Vazbu proto zakládá jen ruční spárování
 * a párování kartou podle obchodníka ji použije místo shody názvu. Částka, datové okno
 * a souběh pohybů se kontrolují dál stejně.
 *
 * Klíč skládá jméno protistrany i první řádek popisu: Creditas dává do jména sebe
 * a obchodníka do popisu, jiné banky naopak. Samotné jméno banky tak klíčem nikdy není.
 */
final class CardMerchantMap
{
    public function __construct(private readonly Connection $db) {}

    public static function key(?string $counterpartyName, ?string $description): ?string
    {
        $parts = [];
        foreach (CardPaymentCandidates::merchantNames($counterpartyName, $description) as $name) {
            $normalized = preg_replace('/\s+/u', ' ', mb_strtoupper(trim($name), 'UTF-8')) ?? '';
            if ($normalized !== '') {
                $parts[] = $normalized;
            }
        }
        if ($parts === []) {
            return null;
        }
        return mb_substr(implode(' | ', $parts), 0, 190, 'UTF-8');
    }

    /**
     * Dodavatelé, ke kterým uživatel pohyb tohoto obchodníka už ručně přiřadil.
     *
     * @return list<int>
     */
    public function clientsFor(int $supplierId, string $merchantKey): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT client_id FROM bank_card_merchant_map WHERE supplier_id = ? AND merchant_key = ?'
        );
        $stmt->execute([$supplierId, $merchantKey]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @param array<string,mixed> $tx pohyb (`bank_transactions`) */
    public function recordManual(int $supplierId, array $tx, int $clientId): void
    {
        if ($clientId <= 0 || !CardNumberMask::isValidLast4((string) ($tx['card_last4'] ?? ''))) {
            return;
        }
        $key = self::key($tx['counterparty_name'] ?? null, $tx['description'] ?? null);
        if ($key === null) {
            return;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO bank_card_merchant_map (supplier_id, merchant_key, client_id, manual_count, last_match_at)
             VALUES (?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE manual_count = manual_count + 1, last_match_at = NOW()'
        )->execute([$supplierId, $key, $clientId]);
    }
}
