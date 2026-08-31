<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService;
use MyInvoice\Service\Submission\SubmissionCredentialService;

/**
 * Jde tohle podání odeslat datovkou, a pokud ano, kým se to potvrzuje?
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč tahle třída vůbec existuje
 * ═══════════════════════════════════════════════════════════════════════════
 * `JmhzIsdsSubmissionService` a `HealthInsuranceIsdsSubmissionService` měly
 * tenhle výpočet zkopírovaný slovo od slova a obě se ptaly jen na odesílací
 * bránu ({@see IsdsGatewayRegistrationService}). Mobilní klíč
 * ({@see \MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport})
 * vznikl až POTOM, takže obě kopie mlčely o cestě, kterou aplikace přitom umí
 * — a UI tvrdilo „odešlete si to sami", i když šlo odeslat z aplikace po
 * potvrzení v mobilu. Jedna třída na jednom místě aspoň zaručuje, že se
 * příště rozejdou obě, nebo žádná.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Tři různé odpovědi, ne dvě
 * ═══════════════════════════════════════════════════════════════════════════
 *   `gateway`       — odešle se BEZ součinnosti účetní (provozovatel má
 *                      zaregistrovanou a zapnutou odesílací bránu),
 *   `mobile_key`     — odešle se z aplikace, ale účetní musí potvrdit relaci
 *                      v Mobilním klíči (`automatic: false`, ale NENÍ to totéž
 *                      jako „nejde to" — rozdíl musí zůstat vidět v UI),
 *   `manual_upload`  — ani jedno; účetní stáhne přílohu a odešle ji ze své
 *                       datové schránky ručně.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Jak se pozná dostupnost Mobilního klíče bez živé relace
 * ═══════════════════════════════════════════════════════════════════════════
 * Nejde to zjistit najisto — relaci potvrzuje člověk v mobilu až v okamžiku
 * odesílání, žádný trvalý stav to nepředpovídá. Jako doklad, že firma datovku
 * vůbec MÁ, slouží {@see SubmissionCredentialService::hasDataBox()}: záznam
 * z Firma → Datová schránka. Mobilní klíč na něm technicky nezávisí (viz
 * docblock té metody), ale je to jediný podklad, který bez síťového volání
 * máme. Firma bez uloženého ID schránky dostane poctivé „ručně", ne slib,
 * který nemáme čím podložit.
 */
final readonly class IsdsTransportAvailabilityResolver
{
    public function __construct(
        private ?IsdsGatewayRegistrationService $gateway,
        private SubmissionCredentialService $credentials,
    ) {}

    /** @return array{automatic:bool,channel:string,reason:?string} */
    public function resolve(int $supplierId, string $environment): array
    {
        if ($this->gateway !== null && $this->gateway->isUsable($environment)) {
            return ['automatic' => true, 'channel' => 'gateway', 'reason' => null];
        }
        if ($this->credentials->hasDataBox($supplierId, $environment)) {
            return ['automatic' => false, 'channel' => 'mobile_key', 'reason' => null];
        }

        return [
            'automatic' => false,
            'channel' => 'manual_upload',
            'reason' => 'isds_transport_unavailable',
        ];
    }
}
