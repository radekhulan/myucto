<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;

final class AccountingDecreeService
{
    public function __construct(private readonly Connection $db) {}

    public function forDate(int $supplierId, string $date): string
    {
        $mode = (new AccountingModeRepository($this->db))->forDate($supplierId, $date);
        if ($mode === 'double_entry') {
            $frameworks = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
            return match ($frameworks->forDate($supplierId, $date)) {
                AccountingFrameworkService::CZ_504 => '504',
                default => '500',
            };
        }

        $stmt = $this->db->pdo()->prepare('SELECT tax_accounting_decree FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return trim((string) ($stmt->fetchColumn() ?: TaxpayerTypeCodebook::SUPPORTED_DECREE));
    }

    public function forYear(int $supplierId, int $year): string
    {
        $period = (new AccountingPeriodRepository($this->db))->findByYear($supplierId, $year);
        return $this->forDate($supplierId, (string) ($period['starts_on'] ?? sprintf('%04d-01-01', $year)));
    }

    public function assertDppoSupported(int $supplierId, int $year, string $operation = Dppo504CapabilityService::COMPUTE): string
    {
        $period = (new AccountingPeriodRepository($this->db))->findByYear($supplierId, $year);
        return $this->assertDppoPeriodSupported(
            $supplierId,
            (string) ($period['starts_on'] ?? sprintf('%04d-01-01', $year)),
            (string) ($period['ends_on'] ?? sprintf('%04d-12-31', $year)),
            $operation,
        );
    }

    public function assertDppoPeriodSupported(int $supplierId, string $startsOn, string $endsOn, string $operation = Dppo504CapabilityService::COMPUTE, string $returnType = 'A'): string
    {
        $decree = $this->forDate($supplierId, $startsOn);
        $dates = [$startsOn, $endsOn, ...(new AccountingModeRepository($this->db))->effectiveDatesBetween($supplierId, $startsOn, $endsOn)];
        foreach ((new AccountingFrameworkRepository($this->db))->listForTenant($supplierId) as $row) if ($row['effective_from'] > $startsOn && $row['effective_from'] < $endsOn) $dates[] = $row['effective_from'];
        foreach (array_unique($dates) as $date) if ($this->forDate($supplierId, $date) !== $decree) throw new TaxReturnException('nonprofit_dppo_mixed_framework', 'DPPO vyžaduje stejný účetní rámec v celém skutečném zdaňovacím období.', 422);
        if ($decree === '504') {
            foreach (array_unique($dates) as $date) if ((new AccountingModeRepository($this->db))->forDate($supplierId, $date) !== 'double_entry') throw new TaxReturnException('nonprofit_dppo_accounting_mode_required', 'Nativní504 vyžaduje podvojné účetnictví v celém skutečném období.',422);
            $q=$this->db->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id=? AND starts_on=? AND ends_on=?');$q->execute([$supplierId,$startsOn,$endsOn]);
            if ($q->fetchColumn() === false) throw new TaxReturnException('nonprofit_dppo_period_required', 'DPPO504 vyžaduje celé skutečné účetní období.',422);
            $accepted = $operation !== Dppo504CapabilityService::COMPUTE
                && (new \MyInvoice\Service\Tax\Nonprofit\NonprofitDppoAcceptanceService($this->db))->isAccepted($supplierId);
            (new Dppo504CapabilityService())->assert($operation, $startsOn, $endsOn, $returnType, $accepted);
        } else self::assertSupportedDecree($decree);
        return $decree;
    }

    public function assertDppoSubmissionSupported(int $supplierId, array $submission, bool $requireCurrent = false): void
    {
        $this->assertDppoArtifactSupported($supplierId, $submission, Dppo504CapabilityService::SUBMIT, $requireCurrent);
    }

    public function assertDppoExportSupported(int $supplierId, array $submission, bool $requireCurrent = false): void
    {
        $this->assertDppoArtifactSupported($supplierId, $submission, Dppo504CapabilityService::EXPORT_XML, $requireCurrent);
    }

    private function assertDppoArtifactSupported(int $supplierId, array $submission, string $operation, bool $requireCurrent): void
    {
        if (strtolower((string) ($submission['form_code'] ?? '')) !== 'dppdp9') {
            return;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = (string) ($submission['xml_content'] ?? '');
            $loaded = $xml !== '' && $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $header = $loaded ? (new \DOMXPath($dom))->query('/Pisemnost/DPPDP9/VetaD')?->item(0) : null;
        if ($header instanceof \DOMElement) {
            $declaredDecree = $header->getAttribute('uv_vyhl');
            $startsOn = $this->xmlDate($header->getAttribute('zdobd_od'));
            $endsOn = $this->xmlDate($header->getAttribute('zdobd_do'));
            // Přiznání 500 bez přílohy závěrky atribut uv_vyhl nenese; neuvedený rámec
            // je v rozporu jen s obdobím 504, které uv_vyhl vždy zapisuje.
            $effectiveDecree = $startsOn === null ? null : $this->forDate($supplierId, $startsOn);
            if ($effectiveDecree !== null && ($declaredDecree === '' ? $effectiveDecree === '504' : $effectiveDecree !== $declaredDecree)) {
                throw new TaxReturnException('dppo_accounting_framework_not_supported', 'Účetní rámec v DPPO neodpovídá rámci platnému pro skutečné zdaňovací období.', 422);
            }
            if ($header->getAttribute('uv_vyhl') === '504') {
                if ($startsOn === null || $endsOn === null) throw new TaxReturnException('nonprofit_dppo_period_required', 'DPPO 504 vyžaduje platný skutečný interval účetního období.', 422);
                $this->assertDppoPeriodSupported($supplierId, $startsOn, $endsOn, $operation, $header->getAttribute('typ_dapdpp') ?: 'A');
                (new \MyInvoice\Service\Tax\Nonprofit\NonprofitDppoFilingGate($this->db))->submission($supplierId, $submission, $requireCurrent);
                return;
            }
            self::assertSupportedDecree($header->getAttribute('uv_vyhl'));
            if ($startsOn !== null && $endsOn !== null) {
                $this->assertDppoPeriodSupported($supplierId, $startsOn, $endsOn);
                return;
            }
        }
        $this->assertDppoSupported($supplierId, (int) $submission['period_year'], $operation);
    }

    private function xmlDate(string $date): ?string
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/D', trim($date), $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', (int) $parts[3], (int) $parts[2], (int) $parts[1]);
    }

    public static function assertSupportedDecree(string $decree): void
    {
        if ($decree === '504') {
            throw new TaxReturnException(
                'dppo_accounting_framework_not_supported',
                'Výpočet, export a podání DPPO podle vyhlášky 504 zatím nejsou podporovány. Přiznání zpracujte externě; potvrzenou daň lze zaúčtovat ručně.',
                422,
            );
        }
    }
}
