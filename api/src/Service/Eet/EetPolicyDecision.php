<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

final class EetPolicyDecision
{
    public const REQUIRED = 'required';
    public const VOLUNTARY = 'voluntary';
    public const NOT_SUBJECT = 'not_subject';
    public const UNKNOWN = 'unknown';
    public const UNSUPPORTED = 'unsupported';
    public const INACTIVE = 'inactive';
    /** Tržbu evidoval jiný systém (e-shop); MyÚčto ji jen zaznamená a neodesílá. */
    public const EXTERNAL = 'external';

    public function __construct(
        public readonly string $decision,
        public readonly string $reasonCode,
        public readonly string $messageCs,
    ) {}

    /** Platba podle rozhodnutí zakládá EET záznam. */
    public function createsRecord(): bool
    {
        return $this->decision === self::REQUIRED || $this->decision === self::VOLUNTARY;
    }

    /** Platbu nelze přijmout, dokud klient nedoplní kontext (unknown) nebo jde o scénář mimo MVP. */
    public function blocksAcceptance(): bool
    {
        return $this->decision === self::UNKNOWN || $this->decision === self::UNSUPPORTED;
    }

    /** @return array{decision:string, reason_code:string, message:string, policy_version:string} */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'reason_code' => $this->reasonCode,
            'message' => $this->messageCs,
            'policy_version' => EetRecordingPolicy::VERSION,
        ];
    }
}
