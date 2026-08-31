<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\CachedHealthOfficialFormProvider;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthOfficialFormCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthOfficialFormDecision;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthOfficialFormProvider;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPayload;

/**
 * Přehled o platbě pojistného zaměstnavatele (PPZ) v PDF.
 *
 * Stejné rozhodování jako u hromadného oznámení
 * ({@see PayrollHealthBulkNotificationPdfRenderer}) a stejný vyplňovač —
 * jednotné vydání tiskopisu 2026 nese pro PPZ číslo `UNI 76.51/2026`
 * a jména polí přesně podle XDP šablony (`ZamNaz`, `ObdHla`, `VymZak`, …).
 *
 * ⚠️ Do 2026 se tenhle tiskopis vyplňoval jako AcroForm: hodnoty se zapsaly
 * do polí a generátor vzhledu je vykreslil. Kontrola po zápisu porovnávala
 * `/V`, takže vypadala zeleně — jenže vložené písmo tiskopisu je WinAnsi,
 * takže z „Řepařská" byla na papíře „?epa?ská". Proto se dnes hodnoty kreslí
 * (viz {@see HealthOfficialFormFiller}) a proti komolení stojí test, který
 * vytěžuje text z hotového PDF.
 */
final class PayrollHealthPaymentOverviewPdfRenderer extends ReportPdfRendererBase
{
    private const FORM_ID = CachedHealthOfficialFormProvider::FORM_PAYMENT_OVERVIEW;

    public function __construct(
        private readonly ?HealthOfficialFormCatalog $forms = null,
        private readonly ?HealthOfficialFormProvider $templates = null,
        private readonly ?HealthOfficialFormFiller $filler = null,
    ) {}

    public function render(array $data): string
    {
        $insurerCode = (string) ($data['insurer_code'] ?? '');
        $usesOwnPdfForm = in_array($insurerCode, ['209', '211'], true);
        $body = $this->renderTemplate(
            $usesOwnPdfForm
                ? 'payroll_health_payment_overview_insurer_form.twig'
                : 'payroll_health_payment_overview.twig',
            $data,
        );
        $mpdf = $this->mpdf([
            'format' => $usesOwnPdfForm ? 'A5' : 'A4',
            'orientation' => $usesOwnPdfForm ? 'L' : 'P',
            'margin_left' => $usesOwnPdfForm ? 9 : 14,
            'margin_right' => $usesOwnPdfForm ? 9 : 14,
            'margin_top' => $usesOwnPdfForm ? 8 : 14,
            'margin_bottom' => $usesOwnPdfForm ? 8 : 14,
        ]);
        $mpdf->SetTitle('Přehled o platbě pojistného zaměstnavatele');
        $mpdf->WriteHTML($body);

        return $mpdf->Output('', 'S');
    }

    /** Použije se u tohohle přehledu úřední tiskopis? A když ne, proč. */
    public function decide(string $insurerCode): HealthOfficialFormDecision
    {
        return ($this->forms ?? new HealthOfficialFormCatalog())->decide(
            $insurerCode,
            self::FORM_ID,
            1,
        );
    }

    public function renderPayload(
        HealthPaymentOverviewPayload $payload,
        ?string $insurerName,
        string $filledOn,
    ): string {
        $decision = $this->decide($payload->insurerCode);
        if ($decision->usesOfficialForm()) {
            return $this->renderOfficialForm($payload, $filledOn);
        }

        return $this->render([
            'insurer_code' => $payload->insurerCode,
            'insurer_name' => $insurerName,
            'overview_kind' => $payload->overviewKind,
            'period' => $payload->period(),
            'month' => $payload->month,
            'year' => $payload->year,
            'employee_count' => $payload->employeeCount,
            'assessment_base' => $payload->assessmentBaseDecimal(),
            'contribution_czk' => $payload->contributionCzk,
            'internal_reference' => $payload->internalReference,
            'filled_on' => $filledOn,
            'official_form_reason' => $decision->reason,
            'insurer_short_name' => match ($payload->insurerCode) {
                '209' => 'ZPŠ',
                '211' => 'ZP MV ČR',
                default => $insurerName,
            },
            'employer' => $payload->employer->toArray(),
        ]);
    }

    public function templateReference(string $insurerCode): string
    {
        if (!$this->decide($insurerCode)->usesOfficialForm()) {
            return 'payroll-health-payment-overview.v2:' . $insurerCode;
        }

        return ($this->templates ?? new CachedHealthOfficialFormProvider())
            ->form(self::FORM_ID)
            ->reference();
    }

    private function renderOfficialForm(
        HealthPaymentOverviewPayload $payload,
        string $filledOn,
    ): string {
        $form = ($this->templates ?? new CachedHealthOfficialFormProvider())
            ->form(self::FORM_ID);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $filledOn);
        if ($date === false || $date->format('Y-m-d') !== $filledOn) {
            throw new \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException(
                'zp_official_form_filled_on_invalid',
                'Datum vyplnění úředního tiskopisu není platné.',
            );
        }
        $employer = $payload->employer;

        return ($this->filler ?? new HealthOfficialFormFiller())->fill(
            $form,
            [
                'ZamNaz' => $employer->name,
                'ZamUli' => $employer->street,
                'ZamCpCo' => $employer->houseNumber,
                'ZamIC' => $employer->payerNumber,
                'ZamPSC' => $employer->postalCode,
                'ZamObe' => $employer->city,
                'ZamTel' => $employer->normalizedPhone(),
                'ObdHla' => sprintf('%02d/%04d', $payload->month, $payload->year),
                'PocZam' => (string) $payload->employeeCount,
                // Vyměřovací základ se tiskne přesně tak, jak jde do datové
                // věty, jen s desetinnou čárkou — halíře se nezaokrouhlují.
                'VymZak' => str_replace('.', ',', $payload->assessmentBaseDecimal()),
                'SumPoj' => (string) $payload->contributionCzk,
                'DatVyp' => $date->format('d.m.Y'),
            ],
            [
                $payload->overviewKind === HealthPaymentOverviewPayload::KIND_CORRECTIVE
                    ? 'Typ:/1'
                    : 'Typ:/0',
            ],
            'Přehled o platbě pojistného zaměstnavatele',
        );
    }
}
