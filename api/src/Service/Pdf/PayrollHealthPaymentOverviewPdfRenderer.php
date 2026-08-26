<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use DragonOfMercy\PhpPdf\Form\Fill\FormFieldInfo;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldType;
use DragonOfMercy\PhpPdf\PdfEditor;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\CachedHealthPaymentOverviewPdfTemplateProvider;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPayload;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPdfTemplateProvider;

final class PayrollHealthPaymentOverviewPdfRenderer extends ReportPdfRendererBase
{
    public function __construct(
        private readonly ?HealthPaymentOverviewPdfTemplateProvider $templates = null,
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

    public function renderPayload(
        HealthPaymentOverviewPayload $payload,
        ?string $insurerName,
        string $filledOn,
    ): string {
        if ($payload->insurerCode === '111') {
            return $this->renderOfficialVzpForm($payload, $filledOn);
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
        return $insurerCode === '111'
            ? 'vzp-ppz:' . CachedHealthPaymentOverviewPdfTemplateProvider::VZP_SHA256
            : 'payroll-health-payment-overview.v1:' . $insurerCode;
    }

    private function renderOfficialVzpForm(
        HealthPaymentOverviewPayload $payload,
        string $filledOn,
    ): string {
        if ($payload->assessmentBaseMinorUnits % 100 !== 0) {
            throw new HealthNotificationException(
                'zp_vzp_assessment_base_not_whole_crowns',
                'Oficiální formulář VZP přijímá vyměřovací základ v celých korunách.',
            );
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $filledOn);
        if ($date === false || $date->format('Y-m-d') !== $filledOn) {
            throw new HealthNotificationException(
                'zp_vzp_filled_on_invalid',
                'Datum vyplnění formuláře VZP není platné.',
            );
        }

        $template = ($this->templates ?? new CachedHealthPaymentOverviewPdfTemplateProvider())
            ->vzpPaymentOverview();
        if (!str_starts_with($template->bytes, '%PDF-')
            || !hash_equals($template->sha256, hash('sha256', $template->bytes))
        ) {
            throw new HealthNotificationException(
                'zp_vzp_pdf_template_integrity_failed',
                'Oficiální formulář VZP neprošel kontrolou integrity.',
            );
        }

        try {
            $editor = PdfEditor::fromBytes($template->bytes);
            $fields = $editor->formFields();
            $this->assertOfficialVzpFields($fields);
            $values = [
                $payload->overviewKind === HealthPaymentOverviewPayload::KIND_CORRECTIVE,
                $payload->employer->city,
                $payload->employer->normalizedPhone(),
                $payload->employer->name,
                $payload->employer->street,
                $payload->employer->houseNumber,
                $payload->employer->payerNumber,
                $payload->employer->postalCode,
                $date->format('j.n.Y'),
                (string) $payload->employeeCount,
                (string) intdiv($payload->assessmentBaseMinorUnits, 100),
                (string) $payload->contributionCzk,
                sprintf('%02d/%04d', $payload->month, $payload->year),
                $payload->overviewKind === HealthPaymentOverviewPayload::KIND_REGULAR,
            ];
            foreach ($fields as $index => $field) {
                $editor->setField($field->name, $values[$index], force: true);
            }
            $pdf = $editor->output();
            $filledFields = PdfEditor::fromBytes($pdf)->formFields();
            $this->assertOfficialVzpFields($filledFields);
            foreach ($values as $index => $value) {
                if ($filledFields[$index]->value !== $value) {
                    throw new \RuntimeException('Vyplněná hodnota formuláře se po uložení změnila.');
                }
            }
        } catch (HealthNotificationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new HealthNotificationException(
                'zp_vzp_pdf_form_invalid',
                'Oficiální formulář VZP se nepodařilo bezpečně vyplnit a ověřit.',
            );
        }

        return $pdf;
    }

    /** @param list<FormFieldInfo> $fields */
    private function assertOfficialVzpFields(array $fields): void
    {
        if (count($fields) !== 14) {
            throw new HealthNotificationException(
                'zp_vzp_pdf_form_changed',
                'Struktura oficiálního formuláře VZP se změnila.',
            );
        }
        foreach ($fields as $index => $field) {
            $expected = $index === 0 || $index === 13
                ? FormFieldType::Checkbox
                : FormFieldType::Text;
            if ($field->type !== $expected) {
                throw new HealthNotificationException(
                    'zp_vzp_pdf_form_changed',
                    'Struktura oficiálního formuláře VZP se změnila.',
                );
            }
        }
    }
}
