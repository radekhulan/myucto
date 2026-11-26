<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AdvanceSettlementEvidenceReader;

/**
 * Půjde zálohu odečtenou konečnou fakturou zaúčtovat? Ve firmě s podvojným účetnictvím
 * zúčtuje konečná faktura jen přijatou zálohu, kterou deník nese: úhradu zaúčtovanou bankou
 * nebo pokladnou, nebo úhradu převzatou z počátečních stavů
 * ({@see AdvanceSettlementEvidenceReader::advanceReceived()}, týž zdroj jako zaúčtování).
 * Záloha jen s ruční úhradou by konečnou fakturu nechala vystavenou a nezaúčtovatelnou,
 * proto ji odmítne už čerpání ({@see AdvancePartialDrawdown}) i vystavení faktury k záloze
 * ({@see FinalFromProformaCreator}). Daňová evidence zúčtování záloh nevede a firma
 * s vypnutým „Vést účetnictví" konečnou fakturu nezaúčtuje, tam kontrola neplatí
 * (stejná podmínka jako {@see \MyInvoice\Service\Accounting\AccountingPeriodProvisioner::hasActiveDoubleEntry()}).
 */
final class AdvanceReceiptGuard
{
    private const TOLERANCE = 0.01;

    public function __construct(private readonly Connection $db) {}

    /**
     * Pokrývá přijatá záloha (v měně proformy) částku `$required`? Nulová částka znamená
     * „aspoň nějaká přijatá záloha".
     */
    public function covers(int $supplierId, int $proformaId, float $required = 0.0, ?bool $postsAccounting = null): bool
    {
        if (!($postsAccounting ?? $this->postsAccounting($supplierId))) {
            return true;
        }
        $received = (new AdvanceSettlementEvidenceReader($this->db))->advanceReceived($supplierId, $proformaId);
        return $received >= max($required - self::TOLERANCE, self::TOLERANCE);
    }

    /** @throws AdvanceReceiptNotPostedException */
    public function assertCovers(int $supplierId, int $proformaId, string $proformaLabel, float $required = 0.0): void
    {
        if (!$this->covers($supplierId, $proformaId, $required)) {
            throw new AdvanceReceiptNotPostedException(sprintf(
                'Úhrada zálohy %s není zaúčtovaná bankou ani pokladnou, konečnou fakturu by s jejím čerpáním nešlo zaúčtovat. '
                    . 'Zaúčtujte úhradu zálohy, nebo zálohu označte jako převzatou z počátečních stavů.',
                trim($proformaLabel) !== '' ? trim($proformaLabel) : '#' . $proformaId,
            ));
        }
    }

    /** Vede firma podvojné účetnictví a má zapnuté „Vést účetnictví"? Jen tehdy se konečná faktura zaúčtuje. */
    public function postsAccounting(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM supplier WHERE id = ? AND accounting_mode = 'double_entry' AND accounting_enabled = 1"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== false;
    }
}
