<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Config\RuntimePaths;

final class CachedHealthPaymentOverviewPdfTemplateProvider implements HealthPaymentOverviewPdfTemplateProvider
{
    public const VZP_URL = 'https://www.vzp.cz/formulare/prehled-o-platbe-pojistneho-zamestnavatele.pdf';
    public const VZP_SHA256 = 'c742e17ff44a79236638e5860a13ffff335805fa06a24890c5235b2c1ef322e3';

    private const MAX_BYTES = 5_000_000;

    public function __construct(private readonly ?Client $http = null) {}

    public function vzpPaymentOverview(): HealthPaymentOverviewPdfTemplate
    {
        $cachePath = RuntimePaths::storage(
            'cache/payroll-forms/vzp-ppz-' . self::VZP_SHA256 . '.pdf',
        );
        $bytes = is_file($cachePath) ? file_get_contents($cachePath) : false;
        if (is_string($bytes) && $this->isExpected($bytes)) {
            return $this->template($bytes);
        }
        if (is_file($cachePath)) {
            @unlink($cachePath);
        }

        try {
            $response = ($this->http ?? new Client())->request('GET', self::VZP_URL, [
                'connect_timeout' => 5.0,
                'timeout' => 15.0,
                'http_errors' => true,
                'headers' => ['Accept' => 'application/pdf'],
            ]);
            $length = $response->getHeaderLine('Content-Length');
            if ($length !== '' && (int) $length > self::MAX_BYTES) {
                throw new \RuntimeException('Oficiální formulář je neočekávaně velký.');
            }
            $bytes = (string) $response->getBody();
        } catch (\Throwable) {
            throw new HealthNotificationException(
                'zp_vzp_pdf_template_unavailable',
                'Oficiální formulář VZP se nepodařilo bezpečně načíst.',
            );
        }

        if (!$this->isExpected($bytes)) {
            throw new HealthNotificationException(
                'zp_vzp_pdf_template_changed',
                'VZP změnila oficiální PDF formulář. MyÚčto jej odmítlo použít, dokud nebude nová verze ověřena.',
            );
        }

        $directory = dirname($cachePath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new HealthNotificationException(
                'zp_vzp_pdf_template_cache_failed',
                'Oficiální formulář VZP se nepodařilo uložit do bezpečné cache.',
            );
        }
        $temporary = tempnam($directory, 'vzp-ppz-');
        if ($temporary === false || file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new HealthNotificationException(
                'zp_vzp_pdf_template_cache_failed',
                'Oficiální formulář VZP se nepodařilo uložit do bezpečné cache.',
            );
        }
        if (!@rename($temporary, $cachePath)) {
            @unlink($temporary);
            throw new HealthNotificationException(
                'zp_vzp_pdf_template_cache_failed',
                'Oficiální formulář VZP se nepodařilo uložit do bezpečné cache.',
            );
        }

        return $this->template($bytes);
    }

    private function isExpected(string $bytes): bool
    {
        return strlen($bytes) <= self::MAX_BYTES
            && str_starts_with($bytes, '%PDF-')
            && hash_equals(self::VZP_SHA256, hash('sha256', $bytes));
    }

    private function template(string $bytes): HealthPaymentOverviewPdfTemplate
    {
        return new HealthPaymentOverviewPdfTemplate(
            $bytes,
            self::VZP_URL,
            self::VZP_SHA256,
        );
    }
}
