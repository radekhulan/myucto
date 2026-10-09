<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Tax\Return\DpfoReturnDataProvider;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\Group;

/**
 * DPFO v daňové evidenci: daňová zůstatková cena majetku prodaného nebo zlikvidovaného
 * v roce je výdajem § 24 odst. 2 písm. b) ZDP. Peněžní deník pořízení majetku z výdajů
 * vyřazuje (is_fixed_asset), takže jinudy než z karty se ZC do výdajů nedostane.
 * Dar výdajem není, škoda jen do výše náhrad (§ 24 odst. 2 písm. l) ZDP) — tu přiznání
 * nedopočítá a varuje. Pravidlo je totéž jako u můstku ZC v DPPO
 * ({@see \MyInvoice\Service\Accounting\Assets\DisposalResiduals::deductibility()}).
 *
 * Regrese: výdaje § 7 nesly jen daňové odpisy, ZC prodaného majetku chyběla.
 */
#[Group('integration')]
final class DpfoDisposalResidualTest extends CashJournalTestCase
{
    public function testTaxResidualOfSoldAssetIsSection7Expense(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)'
        )->execute([self::YEAR, json_encode(TaxConstants::forYear(2025), JSON_THROW_ON_ERROR)]);
        $this->setVatPayer($this->supplierId, false);
        $this->container->get(TaxProfileRepository::class)->upsert($this->supplierId, self::YEAR, [
            'activity_rate' => 60, 'flat_tax_band' => 'none', 'use_actual_expenses' => true,
            'is_secondary' => false, 'activities' => [],
        ]);
        $this->cashDoc('in', 'sale', 500000.0, [], null, self::YEAR . '-03-10');
        $this->cashDoc('out', 'purchase', 100000.0, [], null, self::YEAR . '-04-10');

        $this->disposedAsset('ZC-PRODEJ', 'sold');
        $this->disposedAsset('ZC-DAR', 'donated');
        $this->disposedAsset('ZC-SKODA', 'damaged');

        $data = $this->container->get(DpfoReturnDataProvider::class)->gather($this->supplierId, self::YEAR);

        // 100 000 z deníku + 3 × 22 250 odpis roku vyřazení + 155 750 ZC prodaného majetku.
        self::assertEqualsWithDelta(322500.0, $data['s7_expenses'], 0.001);
        self::assertNotEmpty(array_filter($data['warnings'], static fn (string $w): bool => str_contains($w, 'ZC-SKODA')),
            'Škoda se nedopočítá, přiznání na ni upozorní.');
    }

    private function disposedAsset(string $number, string $type): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO assets (supplier_id, inventory_number, name, kind, asset_account_code, accumulated_account_code,
                                 input_price, acquisition_date, put_into_use_date, status, tax_method, tax_group,
                                 disposal_date, disposal_type)
             VALUES (?, ?, 'Dodávka', 'tangible', '022', '082', 200000, ?, ?, 'disposed', 'straight', 2, ?, ?)"
        )->execute([$this->supplierId, $number, (self::YEAR - 1) . '-03-01', (self::YEAR - 1) . '-03-01',
            self::YEAR . '-06-30', $type]);
        $assetId = (int) $pdo->lastInsertId();
        foreach ([[self::YEAR - 1, 22000.0, 178000.0], [self::YEAR, 22250.0, 155750.0]] as [$year, $amount, $residual]) {
            $pdo->prepare(
                "INSERT INTO depreciation_entries
                    (supplier_id, asset_id, kind, fiscal_year, amount, full_amount, residual_value_end, status)
                 VALUES (?, ?, 'tax', ?, ?, ?, ?, 'confirmed')"
            )->execute([$this->supplierId, $assetId, $year, $amount, $amount, $residual]);
        }
    }
}
