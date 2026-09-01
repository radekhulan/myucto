<?php

declare(strict_types=1);

namespace MyInvoice\Service\Qr;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use Psr\Log\LoggerInterface;
use Rikudou\CzQrPayment\QrPayment as CzQrPayment;
use Rikudou\Iban\Iban\CzechIbanAdapter;
use SepaQr\SepaQrData;

/**
 * Generuje QR kód pro platbu:
 *   CZK → SPAYD format (Rikudou\CzQrPayment, čte každá česká bankovní app)
 *   EUR / non-CZK → SEPA EPC (smhg/sepa-qr-data)
 *
 * Vrací data URI (base64-encoded PNG) vhodný jako `<img src="...">` v HTML/PDF.
 */
final class QrPaymentGenerator
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array $bank   bank_snapshot z faktury
     *                      pro CZK: ['account_number','bank_code','iban']
     *                      pro EUR: ['iban','bic']
     * @param string $supplierName   pro SEPA QR
     * @return string|null  data URI nebo null pokud nelze vygenerovat
     */
    public function generate(
        string $currency,
        float $amount,
        string $varsymbol,
        array $bank,
        string $supplierName = '',
        ?\DateTimeImmutable $dueDate = null,
        ?string $message = null,
        bool $includeDueDate = false,
    ): ?string {
        if ($amount <= 0) {
            return null;
        }
        if ($currency === 'CZK' && $includeDueDate && $dueDate === null) {
            $this->logger->warning('QR due date omitted because it is missing or invalid.', [
                'currency' => $currency,
                'varsymbol' => $varsymbol,
            ]);
        }

        try {
            $payload = $this->buildPayload(
                $currency,
                $amount,
                $varsymbol,
                $bank,
                $supplierName,
                $dueDate,
                $message,
                $includeDueDate,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('QR generation failed: ' . $e->getMessage(), [
                'currency' => $currency,
                'varsymbol' => $varsymbol,
            ]);
            return null;
        }

        if ($payload === null) {
            return null;
        }

        // PNG s pevnou velikostí — mPDF lépe handluje než SVG (SVG bez explicit size se nafukuje)
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel'        => EccLevel::M,
            'scale'           => 8,
            'imageBase64'     => true,
            'quietzoneSize'   => 2,
        ]);
        return (new QRCode($options))->render($payload);
    }

    /**
     * Sestaví testovatelný platební descriptor před převodem na PNG.
     * Datum je opt-in a bez platné hodnoty se nikdy nenahrazuje dneškem.
     *
     * @param array<string,mixed> $bank
     */
    public function buildPayload(
        string $currency,
        float $amount,
        string $varsymbol,
        array $bank,
        string $supplierName = '',
        ?\DateTimeImmutable $dueDate = null,
        ?string $message = null,
        bool $includeDueDate = false,
    ): ?string {
        if ($amount <= 0) return null;

        return $currency === 'CZK'
            ? $this->buildCzk($amount, $varsymbol, $bank, $dueDate, $message, $includeDueDate)
            : $this->buildSepa($amount, $varsymbol, $bank, $supplierName, $message);
    }

    private function buildCzk(
        float $amount,
        string $varsymbol,
        array $bank,
        ?\DateTimeImmutable $dueDate = null,
        ?string $message = null,
        bool $includeDueDate = false,
    ): ?string {
        $accountNumber = (string) ($bank['account_number'] ?? '');
        $bankCode      = (string) ($bank['bank_code'] ?? '');
        $iban          = trim(str_replace(' ', '', (string) ($bank['iban'] ?? '')));

        // Preferuj explicitní účet+kód; když máme jen IBAN (typicky přijatá faktura
        // s jen IBANem), použij IBAN přímo.
        if ($accountNumber !== '' && $bankCode !== '') {
            $payment = new CzQrPayment(new CzechIbanAdapter($accountNumber, $bankCode));
        } elseif ($iban !== '') {
            $payment = new CzQrPayment(new \Rikudou\Iban\Iban\IBAN($iban));
        } else {
            return null;
        }

        // VS pro SPAYD musí být jen číslice (max 10) — `varsymbol` může nést číslo
        // dokladu s pomlčkou/lomítkem (např. „2026-00001"), které banka odmítne.
        // Komentář naopak necháváme s původním číslem dokladu (čitelnější pro plátce).
        $vs = VariableSymbolNormalizer::forPayment($varsymbol);

        $payment->setAmount($amount)
            ->setCurrency('CZK')
            ->setConstantSymbol((string) $this->config->get('qr.czk_constant_symbol', '0308'))
            ->setComment($message ?? ('Faktura ' . $varsymbol));
        if ($includeDueDate && $dueDate !== null) {
            $payment->setDueDate($dueDate);
        }
        if ($vs !== '') {
            $payment->setVariableSymbol($vs);
        }

        return $payment->getQrString();
    }

    private function buildSepa(
        float $amount,
        string $varsymbol,
        array $bank,
        string $name,
        ?string $message = null,
    ): string {
        $iban = trim(str_replace(' ', '', (string) ($bank['iban'] ?? '')));
        if ($iban === '') {
            throw new \RuntimeException('IBAN je povinný pro SEPA QR');
        }

        $remittance = $message ?? ($varsymbol !== '' ? 'Faktura ' . $varsymbol : 'Faktura');

        return (string) (new SepaQrData())
            ->setName($name !== '' ? $name : 'MyUcto.cz')
            ->setIban($iban)
            ->setRemittanceText($remittance)
            ->setAmount($amount);
    }
}
