<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\Services\Settlement\ServiceLifecycleWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServiceLifecycleCommandValidationTest extends TestCase
{
    public static function commands(): array
    {
        $scope = ['unit_id'=>1,'service_id'=>2,'period_from'=>'2026-01-01','period_to'=>'2026-12-31','policy'=>'service_balance_sheet','evidence'=>'Synthetic approved policy','service_cost_account'=>'315.210'];
        return [
            'start' => [['operation'=>'start']+$scope+['entry_ids'=>[3],'coverage_title'=>'Synthetic coverage']],
            'start_opening' => [['operation'=>'start_opening']+$scope+['opening_item_id'=>4]],
            'unwind' => [['operation'=>'unwind','case_id'=>5,'accounting_date'=>'2027-04-01']],
            'finalize' => [['operation'=>'finalize','case_id'=>5,'accounting_date'=>'2027-04-01','invoice_id'=>6,'line_id'=>7,'title'=>'Synthetic final title']],
        ];
    }

    public static function nullCommands(): array
    {
        $cases = [];
        foreach (self::commands() as $operation=>[$command]) foreach (array_keys($command) as $field) {
            $invalid = $command;
            $invalid[$field] = null;
            $cases[$operation.'.'.$field] = [$invalid, $field === 'operation' ? 'property_invalid_field' : 'property_invalid_input'];
        }
        return $cases;
    }

    private function normalize(array $command): array
    {
        $db = new Connection(new Config([]));
        $workflow = new ServiceLifecycleWorkflow($db,new ServiceLifecycleRepository($db));
        return (new \ReflectionMethod($workflow,'normalize'))->invoke($workflow,$command);
    }

    #[DataProvider('nullCommands')]
    public function testEveryRequiredNullValueIsAValidationError(array $command, string $expectedCode): void
    {
        try {
            $this->normalize($command);
            self::fail('A required null value bypassed command validation.');
        } catch (PropertyException $error) {
            self::assertSame($expectedCode,$error->errorCode);
            self::assertSame(422,$error->httpStatus);
        }
    }

    #[DataProvider('commands')]
    public function testValidCommandRetainsItsExactMeaning(array $command): void
    {
        self::assertSame($command,$this->normalize($command));
    }
}
