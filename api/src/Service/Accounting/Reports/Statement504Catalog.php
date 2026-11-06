<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

final class Statement504Catalog
{
    public const VERSION = 'vyhl504-2002/2016';

    /** @return list<array<string,mixed>> */
    public static function rows(string $sentence): array
    {
        static $catalog;
        $catalog ??= json_decode(
            file_get_contents(dirname(__DIR__, 4) . '/resources/accounting/statement-504-2024.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        return array_values(array_filter($catalog['rows'], static fn (array $r): bool => $r['sentence'] === $sentence));
    }

    /** @return array<string,int> */
    public static function profitMap(): array
    {
        return [
            '501' => 3, '502' => 3, '503' => 3, '504' => 4,
            '511' => 5, '512' => 6, '513' => 7, '518' => 8,
            '561' => 10, '562' => 10, '563' => 10, '564' => 10,
            '571' => 11, '572' => 11, '573' => 12, '574' => 12,
            '521' => 14, '524' => 15, '525' => 16, '527' => 17, '528' => 18,
            '531' => 20, '532' => 20, '538' => 20,
            '541' => 22, '542' => 22, '543' => 23, '544' => 24, '545' => 25,
            '546' => 26, '548' => 27, '549' => 28,
            '551' => 30, '552' => 31, '553' => 32, '554' => 33, '556' => 34, '559' => 34,
            '581' => 36, '582' => 36, '591' => 38, '595' => 38,
            '691' => 42, '681' => 44, '682' => 45, '684' => 46,
            '601' => 47, '602' => 47, '604' => 47,
            '641' => 49, '642' => 49, '643' => 50, '644' => 51, '645' => 52,
            '648' => 53, '649' => 54, '652' => 56, '653' => 57, '654' => 58,
            '655' => 59, '657' => 60,
        ];
    }

    /** @return array<string,string> */
    public static function balanceMap(): array
    {
        return [
            '012' => 'UR.3', '013' => 'UR.4', '014' => 'UR.5', '018' => 'UR.6', '019' => 'UR.7',
            '041' => 'UR.8', '051' => 'UR.9', '031' => 'UR.11', '032' => 'UR.12',
            '021' => 'UR.13', '022' => 'UR.14', '025' => 'UR.15', '026' => 'UR.16',
            '028' => 'UR.17', '029' => 'UR.18', '042' => 'UR.19', '052' => 'UR.20',
            '061' => 'UR.22', '062' => 'UR.23', '063' => 'UR.24', '066' => 'UR.25',
            '067' => 'UR.26', '069' => 'UR.27',
            '072' => 'UR.30', '073' => 'UR.31', '074' => 'UR.32', '078' => 'UR.33', '079' => 'UR.34',
            '081' => 'UR.35', '082' => 'UR.36', '085' => 'UR.37', '086' => 'UR.38',
            '088' => 'UR.39', '089' => 'UR.40',
            '111' => 'UR.43', '112' => 'UR.43', '119' => 'UR.44', '121' => 'UR.45',
            '122' => 'UR.46', '123' => 'UR.47', '124' => 'UR.48', '131' => 'UR.49',
            '132' => 'UR.49', '139' => 'UR.50', '151' => 'UR.51', '152' => 'UR.51',
            '311' => 'UR.53', '312' => 'UR.54', '313' => 'UR.55', '314' => 'UR.56',
            '315' => 'UR.57', '335' => 'UR.58', '358' => 'UR.66', '375' => 'UR.68',
            '378' => 'UR.69', '388' => 'UR.70', '391' => 'UR.71',
            '211' => 'UR.73', '213' => 'UR.74', '221' => 'UR.75',
            '251' => 'UR.76', '253' => 'UR.77', '256' => 'UR.78', '261' => 'UR.80',
            '381' => 'UR.82', '385' => 'UR.83',
            '901' => 'US.3', '911' => 'US.4', '921' => 'US.5',
            '933' => 'US.7', '931' => 'US.8', '932' => 'US.9', '941' => 'US.12',
            '951' => 'US.14', '953' => 'US.15', '954' => 'US.16', '955' => 'US.17',
            '958' => 'US.18', '959' => 'US.20',
            '321' => 'US.22', '322' => 'US.23', '324' => 'US.24', '325' => 'US.25',
            '331' => 'US.26', '333' => 'US.27', '367' => 'US.35', '368' => 'US.36',
            '379' => 'US.38', '231' => 'US.39', '232' => 'US.40', '241' => 'US.41',
            '255' => 'US.42', '389' => 'US.43', '249' => 'US.44',
            '383' => 'US.46', '384' => 'US.47',
        ];
    }

    public static function balanceRow(string $code, int $balance): ?string
    {
        $dual = [
            '336' => [59, 28], '341' => [60, 29], '342' => [61, 30], '343' => [62, 31],
            '345' => [63, 32], '346' => [64, 33], '348' => [65, 34], '373' => [67, 37],
        ];
        $synthetic = substr($code, 0, 3);
        if (isset($dual[$synthetic])) {
            return $balance >= 0 ? 'UR.' . $dual[$synthetic][0] : 'US.' . $dual[$synthetic][1];
        }
        if ($synthetic === '221' && $balance < 0) {
            return 'US.39';
        }
        return self::balanceMap()[$synthetic] ?? null;
    }

    public static function accountMap(string $type): array
    {
        $out = [];
        $add = static function (string $code, string $row, string $condition = 'any') use (&$out): void {
            $out[] = ['account_prefix' => $code, 'row_code' => $row, 'target' => 'gross',
                'balance_condition' => $condition,
                'sign' => str_starts_with($row, 'US.') || str_starts_with($code, '6') ? -1 : 1];
        };
        if ($type === 'income_statement') {
            foreach (self::profitMap() as $code => $number) $add((string) $code, 'UT.' . $number);
        } else {
            foreach (self::balanceMap() as $code => $row) {
                $add((string) $code, $row, (string) $code === '221' ? 'debit' : 'any');
            }
            $add('221', 'US.39', 'credit');
            foreach (['336', '341', '342', '343', '345', '346', '348', '373'] as $code) {
                $add($code, self::balanceRow($code, 1), 'debit');
                $add($code, self::balanceRow($code, -1), 'credit');
            }
        }
        return $out;
    }
}
