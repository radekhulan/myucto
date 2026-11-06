<?php

declare(strict_types=1);

namespace MyInvoice\Security;

/** Klient podává připravené DPH/KH/SH, bez přístupu ke správě nebo mzdovým výkazům. */
final class ClientTaxSubmissionPolicy
{
    public const FORM_CODES = ['dphdp3', 'dphkh1', 'dphshv'];

    public static function allowsForm(string $formCode): bool
    {
        return in_array($formCode, self::FORM_CODES, true);
    }

    public static function allowsRoute(string $method, string $path): bool
    {
        if (!preg_match('#^/api/reports/submissions(?:/|$)#D', $path)) return true;

        $method = strtoupper($method);
        if ($method === 'HEAD') $method = 'GET';
        $patterns = match ($method) {
            'GET' => [
                '#^/api/reports/submissions(?:/(?:settings|isds-routes|epo-credentials))?$#D',
                '#^/api/reports/submissions/[0-9]+(?:/(?:xml|payment)|/artifacts/[0-9]+/download)?$#D',
            ],
            'POST' => [
                '#^/api/reports/submissions/epo-credentials$#D',
                '#^/api/reports/submissions/isds-gateway/(?:outbox/[0-9]+|callback)$#D',
                '#^/api/reports/submissions/[0-9]+/(?:submit|epo-handoff|epo-test|epo-submit|epo-status|artifacts|isds|isds-cancel|isds-receipt)$#D',
                '#^/api/reports/submissions/[0-9]+/epo-attempts/[0-9]+/(?:confirmation|state-password)$#D',
            ],
            'PUT' => ['#^/api/reports/submissions/epo-credentials/[0-9]+/supplier$#D'],
            // Smazání osobního certifikátu zasahuje i do jeho použití v jiných firmách.
            // Klient smí spravovat pouze jeho povolení pro aktuální firmu přes PUT výše.
            default => [],
        };
        return array_any($patterns, static fn (string $pattern): bool => preg_match($pattern, $path) === 1);
    }
}
