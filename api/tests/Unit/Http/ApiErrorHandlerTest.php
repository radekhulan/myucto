<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Http;

use MyInvoice\Action\System\HealthAction;
use MyInvoice\Bootstrap;
use MyInvoice\Http\ApiErrorHandler;
use MyInvoice\I18n\Locale;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\EncryptionKeyUnavailableException;
use MyInvoice\Service\Auth\SecretEncryption;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Slim\CallableResolver;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Nezachycená výjimka nesmí v odpovědi API prozradit soubor, řádek ani trace
 * (ani se zapnutým `app.debug`) a chybějící šifrovací klíč je stav instance
 * se srozumitelnou hláškou, ne holá 500 s anglickým textem.
 */
final class ApiErrorHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Locale::set('cs');
    }

    public function testUnhandledExceptionNeverExposesFileLineOrTraceEvenInDebug(): void
    {
        $logger = $this->logger();
        $root = rtrim(Bootstrap::rootDir(), '/\\');
        $exception = new \RuntimeException('Soubor ' . $root . '/storage/x.json nejde otevřít');

        $response = $this->handle('/api/payroll/runs', $exception, true, $logger);
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString($root, $raw);
        self::assertStringNotContainsString(basename(__FILE__), $raw);
        self::assertArrayNotHasKey('exception', $body);
        self::assertSame('internal_error', $body['error']['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $body['error']['request_id']);
        self::assertStringContainsString($body['error']['request_id'], $body['error']['message']);
        self::assertSame($body['error']['request_id'], $response->getHeaderLine('X-Request-Id'));
        self::assertSame(['type', 'message'], array_keys($body['error']['debug']));
        self::assertSame('Soubor storage/x.json nejde otevřít', $body['error']['debug']['message']);

        // Detail zůstává v logu pod stejným kódem požadavku.
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('Slim Application Error', $logger->records[0]);
        self::assertStringContainsString(basename(__FILE__), $logger->records[0]);
        self::assertStringContainsString('Request ID: ' . $body['error']['request_id'], $logger->records[0]);
    }

    public function testProductionResponseHasNoDebugDetail(): void
    {
        $response = $this->handle('/api/payroll/runs', new \LogicException('interní detail'), false, $this->logger());
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayNotHasKey('debug', $body['error']);
        self::assertStringNotContainsString('interní detail', $raw);
    }

    public function testMissingEncryptionKeyIsInstanceStateWithLocalizedMessage(): void
    {
        $exception = $this->missingKeyException();
        $response = $this->handle('/api/payroll/submissions/jmhz-preparation/6', $exception, true, $this->logger());
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('encryption_key_unavailable', $body['error']['code']);
        self::assertSame(ApiErrorHandler::ENCRYPTION_KEY_UNAVAILABLE_MESSAGE, $body['error']['message']);
        self::assertStringContainsString('správce instalace', $body['error']['message']);
        self::assertStringNotContainsString('Encryption key', $raw);
        self::assertStringNotContainsString('SecretEncryption', $raw);
        self::assertStringNotContainsString(rtrim(Bootstrap::rootDir(), '/\\'), $raw);
        self::assertArrayNotHasKey('debug', $body['error']);
        self::assertFalse($response->hasHeader('X-MyInvoice-Public-Error'));

        Locale::set('en');
        $wrapped = new \RuntimeException('Příprava selhala.', 0, $exception);
        $english = json_decode(
            (string) $this->handle('/api/payroll/submissions/registration/13', $wrapped, false, $this->logger())->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('encryption_key_unavailable', $english['error']['code']);
        self::assertStringStartsWith('Encrypted personal data cannot be read', $english['error']['message']);
        self::assertStringContainsString('administrator', $english['error']['message']);
    }

    /**
     * Definer triggeru z obnovené databáze bez práv k nové databázi: výpočet
     * mezd padal obecnou chybou 500. Odpověď teď říká, co se stalo a jak to
     * správce opraví, a nevynáší text chyby databáze.
     */
    public function testDatabasePrivilegeErrorTellsAdministratorHowToRepairDefiners(): void
    {
        foreach ([1142, 1449] as $code) {
            $pdo = new \PDOException('SQLSTATE[42000]: INSERT command denied to user x@y for table secret_table');
            $pdo->errorInfo = ['42000', $code, 'command denied'];
            $wrapped = new \RuntimeException('Výpočet selhal.', 0, $pdo);
            $response = $this->handle('/api/payroll/runs/5/calculate', $wrapped, true, $this->logger());
            $raw = (string) $response->getBody();
            $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            self::assertSame(503, $response->getStatusCode(), (string) $code);
            self::assertSame('database_privileges_missing', $body['error']['code']);
            self::assertSame(ApiErrorHandler::DATABASE_PRIVILEGES_MESSAGE, $body['error']['message']);
            self::assertStringContainsString('--repair-definers', $body['error']['message']);
            self::assertStringNotContainsString('secret_table', $raw);
            self::assertArrayNotHasKey('debug', $body['error']);
        }

        $other = new \PDOException('SQLSTATE[23000]: duplicate');
        $other->errorInfo = ['23000', 1062, 'duplicate'];
        self::assertSame(500, $this->handle('/api/payroll/runs', $other, false, $this->logger())->getStatusCode());

        Locale::set('en');
        $pdo = new \PDOException('denied');
        $pdo->errorInfo = ['42000', 1142, 'denied'];
        $english = json_decode((string) $this->handle('/api/payroll/runs', $pdo, false, $this->logger())->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('The database refused the write', $english['error']['message']);
    }

    public function testHttpExceptionKeepsStatusWithoutTrace(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/nope')
            ->withHeader('Accept', 'application/json');
        $response = $this->handler($this->logger())(
            $request,
            new HttpNotFoundException($request),
            true,
            false,
            false,
        );
        $raw = (string) $response->getBody();

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('"trace"', $raw);
        self::assertStringNotContainsString('"file"', $raw);
        self::assertStringNotContainsString(rtrim(Bootstrap::rootDir(), '/\\'), $raw);
    }

    /** Aplikace musí handler opravdu používat, ne jen mít ho v repu. */
    public function testApplicationUsesHandlerForUncaughtExceptions(): void
    {
        $app = Bootstrap::buildApp();
        // Zdravotní rozhraní je veřejné; jeho akci nahradíme takovou, která spadne.
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $container->set(HealthAction::class, new class {
            public function __invoke(): never
            {
                throw new EncryptionKeyUnavailableException('Encryption key is unavailable');
            }
        });
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/health')
            ->withHeader('Accept', 'application/json');

        $response = $app->handle($request);
        $raw = (string) $response->getBody();

        self::assertSame(503, $response->getStatusCode(), $raw);
        self::assertSame('encryption_key_unavailable', json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        self::assertStringNotContainsString('"file"', $raw);
    }

    private function missingKeyException(): EncryptionKeyUnavailableException
    {
        $current = new SecretEncryption(new Config(['app' => ['secret_encryption_key' => base64_encode(str_repeat('a', 32))]]));
        $other = new SecretEncryption(new Config(['app' => ['secret_encryption_key' => base64_encode(str_repeat('b', 32))]]));
        $sealed = $current->encryptFor('SYNTHETIC', 'payroll:test');
        try {
            $other->decryptFor($sealed, 'payroll:test');
        } catch (EncryptionKeyUnavailableException $e) {
            return $e;
        }
        self::fail('Hodnota zašifrovaná cizím klíčem musí hlásit chybějící klíč.');
    }

    private function handle(string $path, \Throwable $exception, bool $debug, object $logger): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Accept', 'application/json, text/plain, */*');

        return $this->handler($logger)($request, $exception, $debug, true, true);
    }

    private function handler(object $logger): ApiErrorHandler
    {
        return new ApiErrorHandler(new CallableResolver(), new ResponseFactory(), $logger);
    }

    private function logger(): object
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };
    }
}
