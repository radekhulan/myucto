<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\Transport\EetEnvironment;
use MyInvoice\Service\Eet\Transport\EetMessageHeader;
use MyInvoice\Service\Eet\Transport\EetOutcome;
use MyInvoice\Service\Eet\Transport\EetSaleData;
use MyInvoice\Service\Eet\Transport\EetTimeouts;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;

/**
 * Ověřovací test spojení s EET (`overeni=true`) nad nastavením firmy.
 *
 * Úspěch je odpověď `Chyba kod=0` — ověřuje identitu, certifikát a dostupnost,
 * nikoli evidenci tržby. Zpráva neodebírá číslo z řady zařízení, výsledek se
 * nezapisuje do `eet_records` a do auditu jde jen souhrn bez XML.
 */
final class EetDiagnosticsService
{
    private const DIAGNOSTIC_AMOUNT_CENTS = 100;
    private const MAX_CONNECT_MS = 2000;

    public function __construct(
        private readonly EetSettingsService $settings,
        private readonly EetDeviceRepository $devices,
        private readonly EetCertificateVault $certificates,
        private readonly EetTransportInterface $transport,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return array{ok:bool,outcome:string,error_code:?int,message:?string,duration_ms:int,environment:string,device_id:int,certificate_id:int}
     */
    public function run(int $supplierId, ?int $deviceId, ?int $userId): array
    {
        $settings = $this->settings->get($supplierId);
        $eic = $settings['eic'];
        if (!is_string($eic) || $eic === '') {
            throw new EetException(EetException::NOT_CONFIGURED, 'Pro ověření spojení nejdřív vyplňte DIČ poplatníka (EIČ).', 422);
        }
        $device = $this->resolveDevice($supplierId, $deviceId ?? $settings['default_device_id']);
        $environment = EetEnvironment::from((string) $settings['environment']);
        $active = $this->certificates->activeCredential($supplierId, $environment, $eic);
        if ($active === null) {
            throw new EetException(EetException::NOT_CONFIGURED, 'Chybí platný pokladní certifikát pro zvolené prostředí se shodným DIČ.', 422);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague'));
        $timeoutMs = (int) $settings['response_timeout_ms'];
        $sent = $this->transport->send(
            $environment,
            new EetSaleData(
                eic: $eic,
                unitId: (int) $device['unit_number'],
                deviceCode: (string) $device['code'],
                sequenceNumber: 'OVERENI-' . $now->format('YmdHis'),
                saleAt: $now,
                totalCents: self::DIAGNOSTIC_AMOUNT_CENTS,
            ),
            EetMessageHeader::create(true, true),
            $active->credential,
            new EetTimeouts(min(self::MAX_CONNECT_MS, $timeoutMs), $timeoutMs),
        );

        $result = [
            'ok' => $sent->outcome === EetOutcome::VerificationOk,
            'outcome' => $sent->outcome->value,
            'error_code' => $sent->errorCode,
            'message' => $sent->errorMessage,
            'duration_ms' => $sent->durationMs,
            'environment' => $environment->value,
            'device_id' => (int) $device['id'],
            'certificate_id' => $active->certificateId,
        ];
        $this->logger->log(
            'eet_diagnostics',
            $userId,
            'eet_device',
            (int) $device['id'],
            [
                'ok' => $result['ok'],
                'outcome' => $result['outcome'],
                'error_code' => $result['error_code'],
                'duration_ms' => $result['duration_ms'],
                'environment' => $result['environment'],
                'certificate_id' => $result['certificate_id'],
            ],
            null,
            null,
            $supplierId,
        );

        return $result;
    }

    /** @return array<string,mixed> */
    private function resolveDevice(int $supplierId, ?int $deviceId): array
    {
        if ($deviceId !== null) {
            $device = $this->devices->find($supplierId, $deviceId);
            if ($device === null) {
                throw EetException::notFound('Zařízení EET nebylo nalezeno.');
            }
            if (!$device['active']) {
                throw EetException::validation('Zařízení EET není aktivní.', ['field' => 'device_id']);
            }
            return $device;
        }
        foreach ($this->devices->listFor($supplierId) as $device) {
            if ($device['active']) {
                return $device;
            }
        }
        throw new EetException(EetException::NOT_CONFIGURED, 'Firma nemá žádné aktivní zařízení EET.', 422);
    }
}
