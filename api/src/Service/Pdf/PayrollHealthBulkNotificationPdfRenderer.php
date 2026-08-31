<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\CachedHealthOfficialFormProvider;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthBulkNotificationPayload;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationChange;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthOfficialFormCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthOfficialFormDecision;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthOfficialFormProvider;

/**
 * Hromadné oznámení zaměstnavatele (HOZ) v PDF.
 *
 * Dvě podoby, mezi kterými rozhoduje {@see HealthOfficialFormCatalog}:
 *
 * 1. **Úřední tiskopis** jednotného vydání 2026 (`UNI 73.51/2026`), připnutý
 *    v repozitáři a vyplněný přes {@see HealthOfficialFormFiller}. Dostanou ho
 *    pojišťovny, u kterých je doložené, že jde o jejich tiskopis.
 * 2. **Vlastní čitelná sestava** pro zbytek — a vždy s jednovětným důvodem,
 *    proč tiskopis nešel použít. Důvod se tiskne do patky dokumentu a zároveň
 *    se vrací z {@see self::decide()} do odpovědi API, takže se nikde neztratí.
 *
 * Vlastní sestava není náhražka „než na to dojde": jsou pojišťovny, u kterých
 * je to jediná doložená cesta (ČPZP bere výhradně XML, RBP připouští vlastní
 * tisk jen shodný se svým vlastním formulářem, který zveřejňuje jen jako
 * dynamické XFA). Rozhodnutí je proto data, ne TODO.
 */
final class PayrollHealthBulkNotificationPdfRenderer extends ReportPdfRendererBase
{
    private const FORM_ID = CachedHealthOfficialFormProvider::FORM_BULK_NOTIFICATION;

    public function __construct(
        private readonly ?HealthOfficialFormCatalog $forms = null,
        private readonly ?HealthOfficialFormProvider $templates = null,
        private readonly ?HealthOfficialFormFiller $filler = null,
    ) {}

    public function render(array $data): string
    {
        $body = $this->renderTemplate('payroll_health_bulk_notification.twig', $data);
        $mpdf = $this->mpdf([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle('Hromadné oznámení zaměstnavatele');
        $mpdf->WriteHTML($body);

        return $mpdf->Output('', 'S');
    }

    /** Použije se u tohohle oznámení úřední tiskopis? A když ne, proč. */
    public function decide(
        HealthBulkNotificationPayload $payload,
    ): HealthOfficialFormDecision {
        return ($this->forms ?? new HealthOfficialFormCatalog())->decide(
            $payload->insurerCode,
            self::FORM_ID,
            count($payload->changes),
        );
    }

    public function renderPayload(
        HealthBulkNotificationPayload $payload,
        ?string $insurerName,
        string $filledOn,
        ?string $period = null,
    ): string {
        $decision = $this->decide($payload);
        if ($decision->usesOfficialForm()) {
            return $this->renderOfficialForm($payload, $filledOn, $period);
        }

        return $this->render([
            'insurer_code' => $payload->insurerCode,
            'insurer_name' => $insurerName,
            'employer' => $payload->employer->toArray(),
            'internal_reference' => $payload->internalReference,
            'filled_on' => $filledOn,
            'period' => $period,
            'official_form_reason' => $decision->reason,
            'changes' => array_map(
                static fn (HealthNotificationChange $change): array => [
                    'change_code' => $change->changeCode,
                    'changed_on' => $change->changedOn,
                    'insurance_number' => $change->insuranceNumber,
                    'first_name' => $change->firstName,
                    'last_name' => $change->lastName,
                ],
                $payload->changes,
            ),
        ]);
    }

    public function templateReference(
        ?HealthBulkNotificationPayload $payload = null,
    ): string {
        if ($payload === null || !$this->decide($payload)->usesOfficialForm()) {
            return 'payroll-health-bulk-notification.v2';
        }

        return ($this->templates ?? new CachedHealthOfficialFormProvider())
            ->form(self::FORM_ID)
            ->reference();
    }

    private function renderOfficialForm(
        HealthBulkNotificationPayload $payload,
        string $filledOn,
        ?string $period,
    ): string {
        $form = ($this->templates ?? new CachedHealthOfficialFormProvider())
            ->form(self::FORM_ID);
        $employer = $payload->employer;
        $values = [
            'ObdPoj' => self::formatPeriod($period),
            'ZamNaz' => $employer->name,
            'ZamUli' => $employer->street,
            'ZamCpCo' => $employer->houseNumber,
            'ZamIC' => $employer->payerNumber,
            'ZamPSC' => $employer->postalCode,
            'ZamObe' => $employer->city,
            'ZamTel' => $employer->normalizedPhone(),
            'DatVyp' => self::formatDate($filledOn),
        ];
        foreach (array_values($payload->changes) as $index => $change) {
            $row = $index + 1;
            $values['PojKod_' . $row] = $change->changeCode;
            $values['PojCis_' . $row] = $change->insuranceNumber;
            $values['PojDatZme_' . $row] = self::formatDate($change->changedOn);
            $values['PojPri_' . $row] = $change->lastName;
            $values['PojJme_' . $row] = $change->firstName;
            // Adresa je ve větě volitelná; prázdné pole se prostě nevyplní.
            $values['PojUli_' . $row] = $change->address?->street ?? '';
            $values['PojCpCo_' . $row] = $change->address?->houseNumber ?? '';
            $values['PojPSC_' . $row] = $change->address?->postalCode ?? '';
            $values['PojObe_' . $row] = $change->address?->city ?? '';
        }

        return ($this->filler ?? new HealthOfficialFormFiller())->fill(
            $form,
            $values,
            [],
            'Hromadné oznámení zaměstnavatele',
        );
    }

    /** `RRRR-MM` z období podání na `MM/RRRR`, jak žádá hlavička tiskopisu. */
    private static function formatPeriod(?string $period): string
    {
        if ($period === null
            || preg_match('/^(\d{4})-(\d{2})$/', $period, $matches) !== 1
        ) {
            return '';
        }

        return $matches[2] . '/' . $matches[1];
    }

    private static function formatDate(string $isoDate): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate);
        if ($date === false || $date->format('Y-m-d') !== $isoDate) {
            return '';
        }

        return $date->format('d.m.Y');
    }
}
