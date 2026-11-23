<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1BatchService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Přihláška k budoucímu nástupu (1. 1. 2027 při pohledu z 11. 11. 2026):
 * částečná i plná, v testu i v produkci dostane účetní totéž jediné datum,
 * od kterého půjde připravit a podat, a co může udělat už teď. Přehled
 * přihlášek k podání ukáže stejný den.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eReg2EarlyStartTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const START_ON = '2027-01-01';
    private const EARLIEST = '24. 12. 2026';

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testEveryPathToEarlyRegistrationGivesTheSameSingleDate(): void
    {
        [, $employmentId] = $this->regPerson('Jana', 'Novoroční', 97, 'employment', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON));

        $messages = [
            'částečná (výchozí)' => $this->previewError($employmentId, ['environment' => 'test']),
            'plná, test' => $this->previewError($employmentId, ['environment' => 'test', 'registration_mode' => 'full']),
            'plná, produkce' => $this->previewError($employmentId, ['environment' => 'production', 'registration_mode' => 'full']),
        ];
        foreach ($messages as $path => $message) {
            self::assertStringContainsString('nejdřív ' . self::EARLIEST, $message, $path);
            self::assertStringContainsString('profil registrace A1', $message, $path);
            self::assertStringContainsString('Přihlášky k podání (A1)', $message, $path);
            self::assertStringNotContainsString('23. 12. 2026 nebo později', $message, $path);
            self::assertStringNotContainsString('podejte rovnou plnou registraci', $message, $path);
            self::assertDoesNotMatchRegularExpression('/\d{2}\.\d{2}\.\d{4}/', $message, $path . ': datum bez mezer');
        }
        self::assertSame(1, count(array_unique($messages)), 'Cesty se v textu rozcházejí.');

        // Přehled přihlášek k podání ukáže tentýž den a vztah zatím nenabídne k přípravě.
        $batch = $this->container->get(PayrollRegistrationA1BatchService::class);
        self::assertInstanceOf(PayrollRegistrationA1BatchService::class, $batch);
        $items = $batch->candidates($this->supplierId, 'test', '2026-11-01', '2027-01-31')['items'];
        self::assertCount(1, $items);
        self::assertSame('2026-12-24', $items[0]['deadline']['earliest_registration_on']);
        self::assertTrue($items[0]['deadline']['too_early']);
        self::assertSame(self::START_ON, $items[0]['deadline']['due_on']);

        // V den otevření okna jde plná přihláška připravit (REGZEC27).
        $this->regToday('2026-12-24');
        $items = $batch->candidates($this->supplierId, 'test', '2026-12-01', '2027-01-31')['items'];
        self::assertFalse($items[0]['deadline']['too_early']);
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);
        self::assertSame(
            'http://schemas.cssz.cz/REGZEC/2027',
            $this->regAssertXsdValid($this->regArtifactXml((int) $prepared['submission_id'])),
        );
    }

    /** @param array<string,string> $query */
    private function previewError(int $employmentId, array $query): string
    {
        $response = ($this->registration)->preview(
            $this->request('GET', "/api/payroll/submissions/registration/{$employmentId}")->withQueryParams($query),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());

        return (string) $this->json($response)['error']['message'];
    }
}
