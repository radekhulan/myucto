<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Oss\OssPeriod;

/**
 * Je zdaňovací období, do kterého spadá datum plnění, už podané?
 *
 * Kontrolují se OBĚ periodicity DPH (firma může být měsíční i čtvrtletní plátce a
 * v čase se to mění), kontrolní hlášení i samotné OSS podání. Za podané se považuje
 * jen prokazatelně odevzdaný snapshot — vygenerování XML podáním není
 * ({@see TaxSubmissionRepository::findLatestForPeriod()} filtruje na
 * `submitted`/`accepted`).
 *
 * Volají ho hromadná změna OSS ({@see \MyInvoice\Action\Invoice\BulkOssUpdateAction})
 * i import vystaveného dokladu ({@see \MyInvoice\Action\Invoice\ImportIssuedInvoiceAction}):
 * co je odevzdané na finanční správě, se nemění zápisem dokladu, ale opravným nebo
 * dodatečným tvrzením.
 */
final class FiledVatPeriodLookup
{
    /**
     * Každý formulář má vlastní sadu kódů varianty. Přiznání k DPH zná B/O/D/E,
     * kontrolní hlášení ale N (následné) místo D — se sadou od přiznání by podané
     * NÁSLEDNÉ kontrolní hlášení neplatilo za podání.
     */
    private const VARIANTS_BY_FORM = [
        'dphdp3' => ['B', 'O', 'D', 'E'],
        'dphkh1' => ['B', 'O', 'N', 'E'],
        'ossei1' => ['B', 'O', 'D', 'E'],
        'dphshv' => ['R', 'N'],
    ];

    public function __construct(private readonly TaxSubmissionRepository $submissions) {}

    /**
     * Popis podaného výkazu, který dané datum plnění pokrývá, nebo `null`.
     * `$includeOss = false` pro doklad, který do OSS přiznání nevstupuje (tuzemský import).
     * `$includeSummaryReport = true` počítá i podané souhrnné hlášení (vynucená úprava
     * přes API token, která je přísnější než správce v relaci).
     */
    public function describe(int $supplierId, string $refDate, bool $includeOss = true, bool $includeSummaryReport = false): ?string
    {
        $year = (int) substr($refDate, 0, 4);
        $month = (int) substr($refDate, 5, 2);
        $quarter = (int) ceil($month / 3);

        $forms = [['dphdp3', 'přiznání k DPH'], ['dphkh1', 'kontrolní hlášení']];
        if ($includeSummaryReport) {
            $forms[] = ['dphshv', 'souhrnné hlášení'];
        }
        foreach ($forms as [$form, $label]) {
            $variants = self::VARIANTS_BY_FORM[$form];
            if ($this->submissions->findLatestForPeriod($supplierId, $form, $year, $month, null, $variants) !== null) {
                return sprintf('%s za %d/%d', $label, $month, $year);
            }
            if ($this->submissions->findLatestForPeriod($supplierId, $form, $year, null, $quarter, $variants) !== null) {
                return sprintf('%s za Q%d %d', $label, $quarter, $year);
            }
        }
        if ($includeOss && $this->submissions->findLatestForPeriod(
            $supplierId,
            'ossei1',
            $year,
            null,
            $quarter,
            self::VARIANTS_BY_FORM['ossei1'],
        ) !== null) {
            return sprintf('OSS přiznání za %s', OssPeriod::quarterCode($refDate) ?? ('Q' . $quarter . ' ' . $year));
        }

        return null;
    }
}
