<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use PHPUnit\Framework\TestCase;

/**
 * Tajný údaj nesmí uniknout do výpisu, do JSONu, do serializace ani do logu.
 *
 * Test je záměrně otravně důkladný: prochází VŠECHNY běžné cesty, kterými se
 * hodnota z objektu dostane ven. Bezpečnostní audit knihovny ISDS našel přesně
 * takový únik — `Account` má `__debugInfo()` s redakcí, ale nemá
 * `__serialize()`, takže `serialize()` i `var_export()` vypíšou heslo doslova.
 * Redakce jen v jedné cestě je horší než žádná, protože budí důvěru.
 */
final class SensitiveValueTest extends TestCase
{
    private const SECRET = 'HESLO-K-CERTIFIKATU-XYZ';

    public function testRevealReturnsTheValue(): void
    {
        $value = SensitiveValue::fromProducer(static fn (): string => self::SECRET);
        self::assertSame(self::SECRET, $value->reveal());
    }

    public function testVarDumpDoesNotLeak(): void
    {
        $value = SensitiveValue::fromProducer(static fn (): string => self::SECRET);

        ob_start();
        var_dump($value);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::SECRET, $dump);
    }

    public function testPrintRDoesNotLeak(): void
    {
        $value = SensitiveValue::fromProducer(static fn (): string => self::SECRET);
        self::assertStringNotContainsString(self::SECRET, print_r($value, true));
    }

    public function testVarExportDoesNotLeak(): void
    {
        // Tohle je ta cesta, kterou audit našel u knihovní třídy `Account`.
        $value = SensitiveValue::fromProducer(static fn (): string => self::SECRET);
        self::assertStringNotContainsString(self::SECRET, var_export($value, true));
    }

    public function testJsonEncodeRedacts(): void
    {
        $value = SensitiveValue::fromProducer(static fn (): string => self::SECRET);
        $json = (string) json_encode(['password' => $value]);

        self::assertStringNotContainsString(self::SECRET, $json);
        self::assertStringContainsString(SensitiveValue::REDACTED, $json);
    }

    public function testStringCastRedacts(): void
    {
        $value = SensitiveValue::fromProducer(static fn (): string => self::SECRET);
        self::assertSame(SensitiveValue::REDACTED, (string) $value);
        self::assertStringNotContainsString(self::SECRET, "heslo je: {$value}");
    }

    public function testSerializeIsRefused(): void
    {
        $value = SensitiveValue::fromProducer(static fn (): string => self::SECRET);

        $this->expectException(\LogicException::class);
        serialize($value);
    }

    /**
     * Plaintext se nikdy nesmí stát ARGUMENTEM volání — PHP dává argumenty do
     * stack trace, takže by ho tam první výjimka o pár řádků níž vynesla.
     * Proto je jedinou cestou k instanci producer, který hodnotu VRACÍ.
     */
    public function testValueNeverAppearsInAStackTrace(): void
    {
        $value = SensitiveValue::fromProducer(static fn (): string => self::SECRET);

        try {
            (static function () use ($value): void {
                $value->reveal();
                throw new \RuntimeException('cokoliv se pokazilo');
            })();
            self::fail('Výjimka nebyla vyhozena.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getTraceAsString());
            self::assertStringNotContainsString(self::SECRET, (string) $e);
        }
    }

    public function testCredentialsAndContextNeverLeakThroughAnyDumpingPath(): void
    {
        $credentials = new ChannelCredentials(
            boxId: 'abcdefg',
            authMode: 'certificate',
            certificate: SensitiveValue::fromProducer(static fn (): string => 'CERTIFIKAT-BAJTY'),
            certificatePassphrase: SensitiveValue::fromProducer(static fn (): string => self::SECRET),
        );
        $context = new ChannelContext(7, 'test', $credentials);

        foreach ([print_r($context, true), var_export($context, true), (string) json_encode($context)] as $rendered) {
            self::assertStringNotContainsString(self::SECRET, $rendered);
            self::assertStringNotContainsString('CERTIFIKAT-BAJTY', $rendered);
        }

        // Kontext pro log nese jen to, co je veřejné.
        self::assertSame(
            ['supplier_id' => 7, 'environment' => 'test', 'box_id' => 'abcdefg', 'auth_mode' => 'certificate'],
            $context->toLogContext(),
        );
    }

    /**
     * Pověření se nesmí dostat do session, cache ani do fronty úloh — odtud by
     * šlo certifikát vytáhnout. Do fronty patří `supplier_id`.
     */
    public function testChannelCredentialsCannotBeSerialized(): void
    {
        $credentials = new ChannelCredentials('abcdefg', 'certificate');

        $this->expectException(\LogicException::class);
        serialize($credentials);
    }

    /** Jednorázové přihlášení smí existovat jen v neredukovatelném tajném obalu. */
    public function testTransientLoginCredentialsAreSensitiveAndCannotBeSerialized(): void
    {
        $credentials = new ChannelCredentials(
            boxId: '',
            authMode: 'password',
            username: SensitiveValue::fromProducer(static fn (): string => 'isds-user'),
            password: SensitiveValue::fromProducer(static fn (): string => self::SECRET),
        );

        foreach ([print_r($credentials, true), var_export($credentials, true), (string) json_encode($credentials)] as $rendered) {
            self::assertStringNotContainsString('isds-user', $rendered);
            self::assertStringNotContainsString(self::SECRET, $rendered);
        }
        self::assertSame(['box_id' => '', 'auth_mode' => 'password'], $credentials->toLogContext());

        $this->expectException(\LogicException::class);
        serialize($credentials);
    }
}
