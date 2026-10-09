<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerIdentifierSql;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\TwigCache;
use PDO;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Stejnopisy evidenčního listu (§ 38 odst. 5 zákona č. 582/1991 Sb. ve znění
 * do 31. 12. 2025; Všeobecné zásady ELDP, Hlavní zásady): zaměstnavatel
 * vyhotoví dva. Jeden předloží občanovi k podpisu a založí do své evidence
 * (§ 35a odst. 4 písm. a), varianta {@see self::VARIANT_EMPLOYER} s polem
 * pro datum a podpis pojištěnce); druhý s podpisem pověřeného zaměstnance
 * a razítkem vydá občanovi nejpozději v den předložení listu (varianta
 * {@see self::VARIANT_EMPLOYEE}).
 *
 * Tiskne se ze ZMRAZENÉHO listu ({@see EldpStatementService::statement()}),
 * ne z nového sestavení: kopie musí říkat přesně to, co šlo na ČSSZ.
 */
final class EldpStatementCopyService
{
    public const VERSION = 'mz-eldp-copy-2026-v1';

    public const VARIANT_EMPLOYEE = 'employee';
    public const VARIANT_EMPLOYER = 'employer';

    private ?Environment $twig = null;

    public function __construct(
        private readonly Connection $db,
        private readonly EldpStatementService $statements,
        private readonly PayrollSensitiveData $sensitiveData,
    ) {}

    /** @return array{pdf:string,filename:string} */
    public function render(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $year,
        string $variant = self::VARIANT_EMPLOYEE,
    ): array {
        $template = $this->template($supplierId, $environment, $employmentId, $year, $variant);
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
            'filename' => sprintf(
                $variant === self::VARIANT_EMPLOYER ? 'stejnopis-eldp-%d-%d-evidence.pdf' : 'stejnopis-eldp-%d-%d.pdf',
                $year,
                (int) $template['statement_id'],
            ),
        ];
    }

    /**
     * Údaje stejnopisu: zmrazené řádky listu a identifikace podle § 38 odst. 4
     * písm. a) a b) zákona č. 582/1991 Sb. ve znění do 31. 12. 2025 (občan:
     * jméno, příjmení, rodné příjmení, rodné číslo, datum a místo narození,
     * trvalý pobyt; zaměstnavatel: název, IČ, sídlo a variabilní symbol).
     * Chybí-li některý údaj, stejnopis se nevydá: doplňovat ho odhadem nejde.
     *
     * @return array<string,mixed>
     */
    public function template(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $year,
        string $variant = self::VARIANT_EMPLOYEE,
    ): array {
        if (!in_array($variant, [self::VARIANT_EMPLOYEE, self::VARIANT_EMPLOYER], true)) {
            throw new \InvalidArgumentException('Stejnopis evidenčního listu je pro zaměstnance, nebo pro evidenci zaměstnavatele.');
        }
        $statement = $this->statements->statement($supplierId, $environment, $employmentId, $year);
        if ($statement === null) {
            throw new \OutOfBoundsException('Za tento rok a vztah zatím žádný evidenční list zmrazený není.');
        }
        $payload = $statement['payload'];
        $scope = is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
        $form = is_array($payload['form'] ?? null) ? $payload['form'] : [];
        $missing = [];
        $employer = $this->employer($supplierId, $employmentId, $environment, $missing);
        $employee = $this->employee(
            $supplierId,
            (int) ($scope['employee_id'] ?? 0),
            (string) ($scope['period_to'] ?? sprintf('%04d-12-31', $year)),
            $missing,
        );
        if ($missing !== []) {
            throw new EldpValidationException(
                'eldp_copy_identity_incomplete',
                'Stejnopis evidenčního listu musí nést identifikaci občana a zaměstnavatele podle '
                    . '§ 38 odst. 4 zákona č. 582/1991 Sb. Chybí: ' . implode(', ', $missing)
                    . '. Doplňte je v kartě zaměstnance (Identita a adresy), u zaměstnavatele '
                    . 'v Nastavení mezd → Zaměstnavatel a účtárny.',
                [[
                    'code' => 'eldp_copy_identity_incomplete',
                    'message' => 'Chybí: ' . implode(', ', $missing) . '.',
                    'detail' => ['missing' => $missing],
                ]],
            );
        }
        $template = [
            'variant' => $variant,
            'statement_id' => (int) $statement['id'],
            'year' => $year,
            'environment' => $environment,
            'eldp_type' => (string) ($form['eldp_type'] ?? ''),
            'employed_from' => is_string($form['employed_from'] ?? null) ? $form['employed_from'] : null,
            'prepared_on' => is_string($form['prepared_on'] ?? null) ? $form['prepared_on'] : null,
            'period_from' => (string) ($scope['period_from'] ?? ''),
            'period_to' => (string) ($scope['period_to'] ?? ''),
            'sections' => self::sections($payload),
            'employer' => $employer,
            'employee' => $employee,
            'renderer_version' => self::VERSION,
            'manifest_sha256' => (string) ($statement['xml_sha256'] ?? ''),
        ];
        $template['totals'] = [
            'insurance_days' => array_sum(array_column($template['sections'], 'insurance_days')),
            'excluded_days_total' => array_sum(array_column($template['sections'], 'excluded_days_total')),
            'deducted_days_total' => array_sum(array_column($template['sections'], 'deducted_days_total')),
            'assessment_base_czk' => array_sum(array_column($template['sections'], 'assessment_base_czk')),
        ];

        return $template;
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
                // Údaj MR: A u zaměstnání malého rozsahu, jinak N (i DPP).
                'small_scale' => ($section['small_scale'] ?? false) === true ? 'A' : 'N',
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

    /**
     * Zaměstnavatel podle § 38 odst. 4 písm. b): název, jak je zapsán
     * v rejstříku (ne zobrazovaný název firmy), IČ, sídlo a variabilní symbol
     * účtárny vztahu pro dané prostředí ({@see PayrollEmployerIdentifierSql}).
     *
     * @param list<string> $missing
     * @return array{name:string,identification_number:string,address:string,variable_symbol:string}
     */
    private function employer(int $supplierId, int $employmentId, string $environment, array &$missing): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT TRIM(COALESCE(supplier.company_name, "")) AS name,
                    TRIM(COALESCE(supplier.ic, "")) AS ic,
                    TRIM(CONCAT_WS(", ", NULLIF(TRIM(supplier.street), ""), NULLIF(TRIM(CONCAT_WS(" ", supplier.zip, supplier.city)), ""))) AS address,
                    ' . PayrollEmployerIdentifierSql::SELECT . '
               FROM supplier
               JOIN payroll_employments employment
                 ON employment.supplier_id = supplier.id AND employment.id = ?
               ' . PayrollEmployerIdentifierSql::JOINS . '
              WHERE supplier.id = ?'
        );
        $statement->execute([$employmentId, $supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \OutOfBoundsException('Zaměstnavatel nebo pracovní vztah nenalezen.');
        }
        $row = PayrollEmployerIdentifierSql::resolveVariableSymbol($row, $environment);
        $variableSymbol = trim((string) ($row['employer_variable_symbol'] ?? ''));
        foreach ([
            'název zaměstnavatele' => (string) $row['name'],
            'IČ zaměstnavatele' => (string) $row['ic'],
            'sídlo zaměstnavatele' => (string) $row['address'],
            'variabilní symbol zaměstnavatele' => $variableSymbol,
        ] as $label => $value) {
            if ($value === '') {
                $missing[] = $label;
            }
        }

        return [
            'name' => (string) $row['name'],
            'identification_number' => (string) $row['ic'],
            'address' => (string) $row['address'],
            'variable_symbol' => $variableSymbol,
        ];
    }

    /**
     * Občan podle § 38 odst. 4 písm. a): údaje platné ke dni „Do" listu.
     * Rodné číslo se odhaluje jako náležitost stejnopisu; nemá-li ho občan
     * přidělené, nese stejnopis datum narození (jako tiskopis ELDP).
     *
     * @param list<string> $missing
     * @return array<string,?string>
     */
    private function employee(int $supplierId, int $employeeId, string $periodTo, array &$missing): array
    {
        $pdo = $this->db->pdo();
        $identity = $pdo->prepare(
            'SELECT first_name, last_name, full_name, birth_surname, birth_date, birth_place, birth_country_code
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ? AND effective_from <= ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $identity->execute([$supplierId, $employeeId, $periodTo]);
        $person = $identity->fetch(PDO::FETCH_ASSOC);
        $person = is_array($person) ? $person : [];
        $employee = $pdo->prepare('SELECT full_name, birth_date FROM payroll_employees WHERE supplier_id = ? AND id = ?');
        $employee->execute([$supplierId, $employeeId]);
        $employeeRow = $employee->fetch(PDO::FETCH_ASSOC);
        $employeeRow = is_array($employeeRow) ? $employeeRow : [];

        $address = $pdo->prepare(
            'SELECT street_line, city, postal_code, country_code
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ? AND address_type = "residence"
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $address->execute([$supplierId, $employeeId, $periodTo, $periodTo]);
        $addressRow = $address->fetch(PDO::FETCH_ASSOC);

        $identifier = $pdo->prepare(
            'SELECT id, value_ciphertext FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ? AND identifier_type = "birth_number"
              ORDER BY id DESC
              LIMIT 1'
        );
        $identifier->execute([$supplierId, $employeeId]);
        $identifierRow = $identifier->fetch(PDO::FETCH_ASSOC);
        $birthNumber = is_array($identifierRow)
            ? $this->sensitiveData->reveal(
                (string) $identifierRow['value_ciphertext'],
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
                (int) $identifierRow['id'],
                PayrollRevealPurpose::DOCUMENT_PENSION_RECORD_COPY,
            )
            : null;

        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $birthPlace = $text($person['birth_place'] ?? null);
        $birthCountry = $text($person['birth_country_code'] ?? null);
        $result = [
            'name' => $text($person['full_name'] ?? null) ?? (string) ($employeeRow['full_name'] ?? ''),
            'first_name' => $text($person['first_name'] ?? null),
            'last_name' => $text($person['last_name'] ?? null),
            'birth_surname' => $text($person['birth_surname'] ?? null),
            'birth_number' => $text($birthNumber),
            'birth_date' => $text($person['birth_date'] ?? null) ?? $text($employeeRow['birth_date'] ?? null),
            'birth_place' => $birthPlace === null
                ? null
                : ($birthCountry !== null && $birthCountry !== 'CZ' ? $birthPlace . ', ' . $birthCountry : $birthPlace),
            'address' => is_array($addressRow)
                ? implode(', ', array_filter([
                    $text($addressRow['street_line'] ?? null),
                    $text(trim((string) ($addressRow['postal_code'] ?? '') . ' ' . (string) ($addressRow['city'] ?? ''))),
                    ($addressRow['country_code'] ?? 'CZ') !== 'CZ' ? (string) $addressRow['country_code'] : null,
                ]))
                : null,
        ];
        foreach ([
            'first_name' => 'jméno občana',
            'last_name' => 'příjmení občana',
            'birth_surname' => 'rodné příjmení občana',
            'birth_date' => 'datum narození občana',
            'birth_place' => 'místo narození občana',
            'address' => 'adresa trvalého pobytu občana',
        ] as $key => $label) {
            if ($result[$key] === null || $result[$key] === '') {
                $missing[] = $label;
            }
        }

        return $result;
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
