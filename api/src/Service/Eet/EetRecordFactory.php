<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Vznik neměnného EET záznamu v transakci platebního příkazu.
 *
 * Snapshot nese vše, co tvoří identitu tržby (EIČ, číslo jednotky z DIS+, kód
 * zařízení, pořadové číslo, čas, částka) i prostředí. Pozdější změna nastavení,
 * jednotky nebo zařízení už záznam nezmění, retry odešle stejná Data.
 *
 * Pořadové číslo: `{rok}/{pořadí:06d}` z čítače zařízení zamčeného FOR UPDATE.
 * Rok se bere z času tržby, ale čítač se nikdy nevrací do staršího roku: tržba
 * s časem z 31. 12. zapsaná po přechodu čítače na nový rok dostane číslo v řadě
 * nového roku (jinak by se opakovalo už použité číslo). Použité číslo se nevrací
 * ani při rollbacku jiné části příkazu — rollback vrátí i čítač, takže číslo
 * nevznikne vůbec.
 *
 * Pověření (`eic_poverujiciho`, `povereni_vice_popl`) se přebírá ze zařízení, oprava
 * ho dostává z původní tržby (`delegation`): záporná tržba patří stejnému pověření
 * jako tržba, kterou opravuje, i když jde přes jiné zařízení.
 */
final class EetRecordFactory
{
    public const DEADLINE_HOURS = 48;

    /** Krátké odložení pro záložní worker: první pokus po commitu dělá dispatchNow. */
    public const INTERACTIVE_GRACE_SECONDS = 15;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $settings řádek eet_settings
     * @param array{device_id?:?int, sale_at:\DateTimeImmutable, total_amount:string, kind?:string,
     *              credit_topup_amount?:?string, credit_draw_amount?:?string,
     *              corrects_record_id?:?int, correction_reason?:?string,
     *              policy_decision?:?string, policy_reason?:?string, created_by?:?int,
     *              delegation?:?EetDelegation, device_field?:string, now?:?\DateTimeImmutable} $input
     * @return array{id:int, public_id:string, sequence_number:string, unit_id:int, device_id:int}
     * @throws EetCommandException
     */
    public function create(int $supplierId, int $paymentEventId, array $settings, array $input): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('EET záznam vzniká jen v transakci platebního příkazu.');
        }

        $eic = strtoupper(trim((string) ($settings['eic'] ?? '')));
        if (preg_match('/^CZ\d{8,10}$/D', $eic) !== 1) {
            throw EetCommandException::notConfigured('V nastavení EET chybí platné EIČ.');
        }
        $environment = (string) ($settings['environment'] ?? '');
        if (!in_array($environment, ['playground', 'production'], true)) {
            throw EetCommandException::notConfigured('V nastavení EET chybí prostředí.');
        }

        $saleAt = EetValues::local($input['sale_at']);
        // Pole chyb zařízení podle volajícího (platba device_id, pokladní doklad eet_device_id).
        $field = (string) ($input['device_field'] ?? 'device_id');
        $explicitDevice = (int) ($input['device_id'] ?? 0) > 0;
        $deviceId = (int) ($input['device_id'] ?? 0) ?: (int) ($settings['default_device_id'] ?? 0);
        if ($deviceId <= 0) {
            throw EetCommandException::notConfigured('Není zvolené pokladní zařízení EET a firma nemá výchozí zařízení.');
        }
        $device = $this->lockDevice($supplierId, $deviceId, $field);
        $day = $saleAt->format('Y-m-d');
        if ((int) $device['active'] !== 1) {
            throw EetCommandException::validation('Pokladní zařízení EET není aktivní.', $field);
        }
        if ((string) $device['valid_from'] > $day || ($device['valid_to'] !== null && (string) $device['valid_to'] < $day)) {
            throw EetCommandException::validation('Provozovna (evidenční jednotka) zařízení není k času tržby platná.', $field);
        }

        $override = ($input['delegation'] ?? null) instanceof EetDelegation ? $input['delegation'] : null;
        $delegation = $override ?? EetDelegation::fromDevice($device);
        // Tržba v pověření jen na výslovně zvolené zařízení: výchozí zařízení dostává
        // vlastní tržby bez volby obsluhy (oprava nese pověření původní tržby).
        if ($override === null && !$explicitDevice && $delegation->isDelegated()) {
            throw EetCommandException::validation(
                'Výchozí zařízení eviduje v pověření a vlastní tržba by tiše odešla jako tržba v pověření. Zvolte zařízení výslovně.',
                $field,
            );
        }
        $violation = $delegation->violation($eic);
        if ($violation !== null) {
            throw EetCommandException::validation($violation['message'] . ' Opravte pověření v nastavení pokladního zařízení EET.', $field);
        }

        $saleYear = (int) $saleAt->format('Y');
        $counterYear = $device['sequence_year'] !== null ? (int) $device['sequence_year'] : null;
        $year = $counterYear !== null ? max($saleYear, $counterYear) : $saleYear;
        $sequence = $counterYear === $year ? max(1, (int) $device['next_sequence']) : 1;
        $sequenceNumber = sprintf('%d/%06d', $year, $sequence);
        $pdo->prepare('UPDATE eet_devices SET sequence_year = ?, next_sequence = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$year, $sequence + 1, $deviceId, $supplierId]);

        $total = EetValues::amount($input['total_amount'], 'amount');
        $cents = EetValues::toCents($total);
        if ($cents === 0 || abs($cents) > EetValues::MAX_ABS_CENTS) {
            throw EetCommandException::validation('Částka tržby musí být nenulová a v absolutní hodnotě pod 100 milionů Kč.', 'amount');
        }

        $topUp = self::creditAmount($input['credit_topup_amount'] ?? null, $cents, 'credit_topup_amount');
        $draw = self::creditAmount($input['credit_draw_amount'] ?? null, $cents, 'credit_draw_amount');

        $now = EetValues::local($input['now'] ?? new \DateTimeImmutable());
        $deadline = $saleAt->modify('+' . self::DEADLINE_HOURS . ' hours');
        $publicId = EetValues::uuid4();
        $pdo->prepare(
            'INSERT INTO eet_records
               (public_id, supplier_id, payment_event_id, kind, corrects_record_id, correction_reason, environment,
                eic, delegating_eic, multiple_delegators, unit_id, unit_number, device_id, device_code, sequence_number,
                sale_at, sale_at_offset, total_amount, credit_topup_amount, credit_draw_amount,
                policy_decision, policy_reason, policy_version, status, deadline_at, overdue, next_attempt_at,
                created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?, ?)'
        )->execute([
            $publicId, $supplierId, $paymentEventId,
            (string) ($input['kind'] ?? 'sale'),
            $input['corrects_record_id'] ?? null,
            $input['correction_reason'] ?? null,
            $environment, $eic,
            $delegation->delegatingEic, $delegation->multipleDelegators() ? 1 : 0,
            (int) $device['unit_id'], (int) $device['unit_number'],
            $deviceId, (string) $device['code'],
            $sequenceNumber,
            EetValues::dbSecond($saleAt), $saleAt->format('P'),
            $total, $topUp, $draw,
            $input['policy_decision'] ?? null, $input['policy_reason'] ?? null, EetRecordingPolicy::VERSION,
            EetValues::dbSecond($deadline),
            // Pozdě pořízená tržba se eviduje, jen hned s příznakem po lhůtě.
            $deadline < $now ? 1 : 0,
            EetValues::dbTime($now->modify('+' . self::INTERACTIVE_GRACE_SECONDS . ' seconds')),
            $input['created_by'] ?? null,
        ]);

        return [
            'id' => (int) $pdo->lastInsertId(),
            'public_id' => $publicId,
            'sequence_number' => $sequenceNumber,
            'unit_id' => (int) $device['unit_id'],
            'device_id' => $deviceId,
        ];
    }

    /**
     * Záznam tržby, kterou evidoval jiný systém (e-shop s vlastní EET). MyÚčto ho jen
     * drží v evidenci se stavem `external`: nemá číslo z řady zařízení MyÚčta, nikdy se
     * neodesílá a nepatří do fronty. Údaje externí evidence (EIČ, jednotka, pokladna,
     * pořadové číslo, čas, částka, FIK) jsou volitelné a už ověřené volajícím; zařízení
     * ani jednotka v MyÚčtu existovat nemusí. Trojice EIČ + pokladna + pořadové číslo je
     * u firmy jedinečná (`external_key`).
     *
     * @param array{sale_at:\DateTimeImmutable, total_amount:string, eic?:?string, unit_number?:?int,
     *              device_code?:?string, sequence_number?:?string, pok?:?string, external_key?:?string, delegation?:?EetDelegation,
     *              policy_reason?:?string, created_by?:?int, now?:?\DateTimeImmutable} $input
     * @param array<string,mixed> $settings řádek eet_settings
     * @return array{id:int, public_id:string}
     */
    public function createExternal(int $supplierId, int $paymentEventId, array $settings, array $input): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('EET záznam vzniká jen v transakci platebního příkazu.');
        }
        $eic = strtoupper(trim((string) ($input['eic'] ?? $settings['eic'] ?? '')));
        if (!EetIdentifiers::isEic($eic)) {
            throw EetCommandException::notConfigured('V nastavení EET chybí platné EIČ a externí evidence ho neuvedla.');
        }
        $environment = (string) ($settings['environment'] ?? 'production');
        $environment = in_array($environment, EetIdentifiers::ENVIRONMENTS, true) ? $environment : 'production';
        $saleAt = EetValues::local($input['sale_at']);
        $total = EetValues::amount($input['total_amount'], 'amount');
        $cents = EetValues::toCents($total);
        if ($cents === 0 || abs($cents) > EetValues::MAX_ABS_CENTS) {
            throw EetCommandException::validation('Částka tržby musí být nenulová a v absolutní hodnotě pod 100 milionů Kč.', 'amount');
        }
        $delegation = ($input['delegation'] ?? null) instanceof EetDelegation ? $input['delegation'] : EetDelegation::none();
        if (($violation = $delegation->violation($eic)) !== null) {
            throw EetCommandException::validation($violation['message'], 'external_eet.eic_poverujiciho');
        }
        $publicId = EetValues::uuid4();
        $pdo->prepare(
            "INSERT INTO eet_records
               (public_id, supplier_id, payment_event_id, kind, environment, eic, delegating_eic, multiple_delegators, unit_id, unit_number,
                device_id, device_code, sequence_number, external_key, sale_at, sale_at_offset, total_amount, policy_decision, policy_reason,
                policy_version, status, deadline_at, overdue, next_attempt_at, pok, created_by)
             VALUES (?, ?, ?, 'sale', ?, ?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, 'external', ?, ?, 'external', ?, 0, NULL, ?, ?)"
        )->execute([
            $publicId, $supplierId, $paymentEventId, $environment, $eic,
            $delegation->delegatingEic, $delegation->multipleDelegators() ? 1 : 0,
            $input['unit_number'] ?? null, $input['device_code'] ?? null, $input['sequence_number'] ?? null, $input['external_key'] ?? null,
            EetValues::dbSecond($saleAt), $saleAt->format('P'), $total,
            $input['policy_reason'] ?? 'external_system', EetRecordingPolicy::VERSION,
            EetValues::dbSecond($saleAt->modify('+' . self::DEADLINE_HOURS . ' hours')),
            $input['pok'] ?? null,
            $input['created_by'] ?? null,
        ]);
        return ['id' => (int) $pdo->lastInsertId(), 'public_id' => $publicId];
    }

    /**
     * Částka kreditu (`urceno_cerp_zuct` / `cerp_zuct`) k tržbě: stejné znaménko jako
     * celková tržba a nejvýš její velikost. Nula = pole se neuvádí.
     *
     * @throws EetCommandException
     */
    private static function creditAmount(mixed $value, int $totalCents, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $amount = EetValues::amount($value, $field);
        $cents = EetValues::toCents($amount);
        if ($cents === 0) {
            return null;
        }
        if (($cents > 0) !== ($totalCents > 0) || abs($cents) > abs($totalCents)) {
            throw EetCommandException::validation('Částka kreditu musí mít stejné znaménko jako tržba a nesmí ji přesáhnout.', $field);
        }
        return $amount;
    }

    /**
     * Zařízení s jednotkou firmy, zamčené pro alokaci čísla.
     *
     * @return array<string,mixed>
     * @throws EetCommandException
     */
    private function lockDevice(int $supplierId, int $deviceId, string $field = 'device_id'): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.id, d.unit_id, d.code, d.active, d.sequence_year, d.next_sequence, d.delegation, d.delegating_eic,
                    u.unit_number, u.valid_from, u.valid_to
               FROM eet_devices d
               JOIN eet_units u ON u.id = d.unit_id AND u.supplier_id = d.supplier_id
              WHERE d.id = ? AND d.supplier_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$deviceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw EetCommandException::validation('Pokladní zařízení EET nenalezeno.', $field);
        }
        return $row;
    }
}
