<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\TwigCache;
use PDO;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Stejnopis evidenčního listu pro zaměstnance (§ 38 odst. 5 zákona
 * č. 582/1991 Sb. ve znění do 31. 12. 2025: druhý stejnopis vydá zaměstnavatel
 * občanovi nejpozději v den předložení listu).
 *
 * Tiskne se ze ZMRAZENÉHO listu ({@see EldpStatementService::statement()}),
 * ne z nového sestavení: kopie musí říkat přesně to, co šlo na ČSSZ.
 */
final class EldpStatementCopyService
{
    public const VERSION = 'mz-eldp-copy-2026-v1';

    private ?Environment $twig = null;

    public function __construct(
        private readonly Connection $db,
        private readonly EldpStatementService $statements,
    ) {}

    /** @return array{pdf:string,filename:string} */
    public function render(int $supplierId, string $environment, int $employmentId, int $year): array
    {
        $statement = $this->statements->statement($supplierId, $environment, $employmentId, $year);
        if ($statement === null) {
            throw new \OutOfBoundsException('Za tento rok a vztah zatím žádný evidenční list zmrazený není.');
        }
        $payload = $statement['payload'];
        $scope = is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
        $form = is_array($payload['form'] ?? null) ? $payload['form'] : [];
        $template = [
            'statement_id' => (int) $statement['id'],
            'year' => $year,
            'environment' => $environment,
            'eldp_type' => (string) ($form['eldp_type'] ?? ''),
            'employed_from' => is_string($form['employed_from'] ?? null) ? $form['employed_from'] : null,
            'prepared_on' => is_string($form['prepared_on'] ?? null) ? $form['prepared_on'] : null,
            'period_from' => (string) ($scope['period_from'] ?? ''),
            'period_to' => (string) ($scope['period_to'] ?? ''),
            'sections' => self::sections($payload),
            'employer' => $this->employer($supplierId),
            'employee' => $this->employee($supplierId, (int) ($scope['employee_id'] ?? 0)),
            'renderer_version' => self::VERSION,
            'manifest_sha256' => (string) ($statement['xml_sha256'] ?? ''),
        ];
        $template['totals'] = [
            'insurance_days' => array_sum(array_column($template['sections'], 'insurance_days')),
            'excluded_days_total' => array_sum(array_column($template['sections'], 'excluded_days_total')),
            'deducted_days_total' => array_sum(array_column($template['sections'], 'deducted_days_total')),
            'assessment_base_czk' => array_sum(array_column($template['sections'], 'assessment_base_czk')),
        ];
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Stejnopis evidenčního listu důchodového pojištění');
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->AddCustomProperty('PayrollRendererVersion', self::VERSION);
        $mpdf->WriteHTML($this->html($template));
        $pdf = $mpdf->Output('', 'S');
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new \UnexpectedValueException('mPDF nevytvořilo platný stejnopis evidenčního listu.');
        }

        return [
            'pdf' => $pdf,
            'filename' => sprintf('stejnopis-eldp-%d-%d.pdf', $year, (int) $statement['id']),
        ];
    }

    /**
     * Řádky stejnopisu ze zmrazených sekcí listu.
     *
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    public static function sections(array $payload): array
    {
        return array_map(
            static fn (array $section): array => [
                'code' => (string) $section['code'],
                'valid_from' => is_string($section['valid_from'] ?? null) ? $section['valid_from'] : null,
                'valid_to' => is_string($section['valid_to'] ?? null) ? $section['valid_to'] : null,
                'insurance_days' => (int) $section['insurance_days'],
                'months_without_insurance' => array_map(intval(...), (array) ($section['months_without_insurance'] ?? [])),
                'whole_year_without_insurance' => ($section['whole_year_without_insurance'] ?? false) === true,
                'excluded_days_total' => (int) $section['excluded_days_total'],
                'deducted_days_total' => (int) ($section['deducted_days_total'] ?? 0),
                'assessment_base_czk' => (int) $section['assessment_base_czk'],
            ],
            array_values(array_filter((array) ($payload['eldp_sections'] ?? []), is_array(...))),
        );
    }

    /** @param array<string,mixed> $template */
    public function html(array $template): string
    {
        return $this->twig()->render('eldp-copy.twig', $template);
    }

    /** @return array{name:string,identification_number:string,address:string} */
    private function employer(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(TRIM(display_name), ""), TRIM(company_name)) AS name,
                    TRIM(COALESCE(ic, "")) AS ic,
                    TRIM(CONCAT_WS(", ", NULLIF(TRIM(street), ""), NULLIF(TRIM(CONCAT_WS(" ", zip, city)), ""))) AS address
               FROM supplier WHERE id = ?'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \OutOfBoundsException('Zaměstnavatel nenalezen.');
        }

        return [
            'name' => (string) $row['name'],
            'identification_number' => (string) $row['ic'],
            'address' => (string) $row['address'],
        ];
    }

    /** @return array{name:string,birth_date:?string} */
    private function employee(int $supplierId, int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT full_name, birth_date FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employeeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            ? ['name' => (string) $row['full_name'], 'birth_date' => $row['birth_date'] === null ? null : (string) $row['birth_date']]
            : ['name' => '', 'birth_date' => null];
    }

    private function mpdf(): Mpdf
    {
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'L',
            'margin_left' => 11,
            'margin_right' => 11,
            'margin_top' => 10,
            'margin_bottom' => 12,
            'tempDir' => $tmpDir,
            ...MpdfFontConfig::options(),
        ]);
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(
                new FilesystemLoader([Bootstrap::rootDir() . '/api/templates/payroll']),
                ['autoescape' => 'html', 'strict_variables' => true] + TwigCache::options('payroll'),
            );
            $this->twig->addFilter(new \Twig\TwigFilter(
                'cz_date',
                static fn (string $date): string => (new \DateTimeImmutable($date))->format('d.m.Y'),
            ));
            $this->twig->addFilter(new \Twig\TwigFilter(
                'czk',
                static fn (int $amount): string => number_format($amount, 0, ',', ' '),
            ));
        }

        return $this->twig;
    }
}
