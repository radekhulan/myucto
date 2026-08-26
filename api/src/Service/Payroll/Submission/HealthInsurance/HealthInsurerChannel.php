<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

final readonly class HealthInsurerChannel
{
    /**
     * @param list<array{from:string,to:?string,format:HealthInsurerIsdsAttachmentFormat}> $isdsAttachmentRules
     */
    public function __construct(
        public string $insurerCode,
        public HealthInsurerChannelKind $kind,
        public ?string $portalUrl,
        public array $isdsAttachmentRules,
        public bool $automatedDispatchDocumented,
        public string $undocumentedReasonCode,
        public string $note,
    ) {}

    public function isdsAttachmentFormatOn(
        string $date,
    ): HealthInsurerIsdsAttachmentFormat {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1) {
            throw new \InvalidArgumentException(
                'Datum účinnosti přílohy ISDS není platné.',
            );
        }
        foreach ($this->isdsAttachmentRules as $rule) {
            if ($date >= $rule['from']
                && ($rule['to'] === null || $date <= $rule['to'])
            ) {
                return $rule['format'];
            }
        }

        return HealthInsurerIsdsAttachmentFormat::None;
    }

    /** @return array<string,mixed> */
    public function toArray(
        ?array $recipient = null,
        ?string $onDate = null,
    ): array
    {
        $onDate ??= (new \DateTimeImmutable(
            'now',
            new \DateTimeZone('Europe/Prague'),
        ))->format('Y-m-d');
        $format = $this->isdsAttachmentFormatOn($onDate);

        return [
            'insurer_code' => $this->insurerCode,
            'insurer_name' => $recipient['name'] ?? null,
            'kind' => $this->kind->value,
            'data_box_id' => $recipient['isds_box_id'] ?? null,
            'business_id' => $recipient['business_id'] ?? null,
            'address' => $recipient['address'] ?? null,
            'recipient_source' => $recipient === null
                ? 'missing'
                : (($recipient['is_system'] ?? false) ? 'system' : 'company'),
            'portal_url' => $this->portalUrl,
            'isds_attachment_format' => $format->value,
            'isds_attachment_rules' => array_map(
                static fn (array $rule): array => [
                    'from' => $rule['from'],
                    'to' => $rule['to'],
                    'format' => $rule['format']->value,
                ],
                $this->isdsAttachmentRules,
            ),
            'accepts_shared_data_message' =>
                $format === HealthInsurerIsdsAttachmentFormat::Xml,
            'automated_dispatch_documented' =>
                $this->automatedDispatchDocumented,
            'undocumented_reason_code' => $this->undocumentedReasonCode,
            'note' => $this->note,
        ];
    }
}
