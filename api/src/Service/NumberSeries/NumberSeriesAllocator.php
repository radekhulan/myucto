<?php

declare(strict_types=1);

namespace MyInvoice\Service\NumberSeries;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NumberSeriesRepository;

/**
 * Volba řady pro nový doklad a výdej čísla z nové (nativní) řady číselníku.
 *
 * Volba ({@see self::resolve()}): výslovně vybraná řada dokladu, výchozí řada uživatele,
 * výchozí řada pokladny, výchozí řada druhu. Vrátí-li převedenou řadu nebo nic, číslo
 * přidělí dosavadní generátor druhu beze změny; volající proto pracuje jen s výsledkem
 * {@see self::nativeFor()}.
 *
 * Výdej ({@see self::allocate()}) běží v transakci volajícího, která číslo zapíše na
 * doklad: `INSERT … ON DUPLICATE KEY UPDATE` zamkne řádek počítadla do commitu (dvě
 * vystavení téže řady jdou za sebou) a rollback číslo vrátí, takže v řadě nevznikne díra.
 * Mimo transakci se výdej odmítne.
 */
final class NumberSeriesAllocator
{
    /** Pojistka přeskakování obsazených čísel, stejná jako u dosavadních řad. */
    private const MAX_SKIP = 1000;

    public function __construct(
        private readonly Connection $db,
        private readonly NumberSeriesRepository $series,
    ) {}

    /**
     * Řada, ze které má nový doklad číslo, nebo null (číselník je prázdný → dosavadní cesta).
     *
     * @return array<string,mixed>|null
     * @throws NumberSeriesException vybraná řada nepatří firmě nebo druhu
     */
    public function resolve(int $supplierId, string $kind, ?int $explicitId, int $registerId = 0, ?int $userId = null): ?array
    {
        if (!$this->db->hasTable('number_series')) {
            return null;
        }
        if ($explicitId !== null && $explicitId > 0) {
            $row = $this->series->find($supplierId, $explicitId);
            if ($row === null || !self::usableFor($row, $kind, $registerId)) {
                throw new NumberSeriesException('series_invalid', 'Vybraná číselná řada nepatří k tomuto druhu dokladu.');
            }
            return $row;
        }
        $rows = $this->series->listForKind($supplierId, $kind);
        if ($rows === []) {
            return null;
        }
        if ($userId !== null) {
            foreach (array_unique([$registerId, 0]) as $scope) {
                $id = $this->series->userDefault($supplierId, $userId, $kind, $scope);
                foreach ($rows as $row) {
                    if ($row['id'] === $id && self::usableFor($row, $kind, $registerId)) {
                        return $row;
                    }
                }
            }
        }
        if ($registerId > 0) {
            foreach ($rows as $row) {
                if ($row['is_default'] && $row['scope_type'] === 'cash_register' && $row['scope_id'] === $registerId) {
                    return $row;
                }
            }
        }
        foreach ($rows as $row) {
            if ($row['is_default'] && $row['scope_type'] === 'company') {
                return $row;
            }
        }
        return null;
    }

    /**
     * Nativní řada pro nový doklad (null = číslo přidělí dosavadní generátor).
     * Ukončená řada číslo dokladu s pozdějším datem nevydá.
     *
     * @return array<string,mixed>|null
     */
    public function nativeFor(
        int $supplierId,
        string $kind,
        ?int $explicitId,
        \DateTimeInterface $date,
        int $registerId = 0,
        ?int $userId = null,
    ): ?array {
        $row = $this->resolve($supplierId, $kind, $explicitId, $registerId, $userId);
        if ($row === null) {
            return null;
        }
        self::assertOpen($row, $date);
        return $row['storage'] === 'native' ? $row : null;
    }

    /** @param array<string,mixed> $row */
    public static function assertOpen(array $row, \DateTimeInterface $date): void
    {
        if ($row['valid_to'] !== null && $date->format('Y-m-d') > (string) $row['valid_to']) {
            throw new NumberSeriesException(
                'series_ended',
                'Číselná řada je ukončena k ' . $row['valid_to'] . ', doklad s pozdějším datem z ní číslo nedostane.',
            );
        }
    }

    /**
     * Řadu lze použít pro doklad druhu `$kind` (a pokladny `$registerId`): řada pokladny jen
     * pro tu pokladnu, firemní pro všechny. Řady klientů a kategorií tržby se nevybírají,
     * použije je dosavadní generátor sám podle odběratele.
     *
     * @param array<string,mixed> $row
     */
    public static function usableFor(array $row, string $kind, int $registerId = 0): bool
    {
        if ($row['doc_kind'] !== $kind) {
            return false;
        }
        return match ($row['scope_type']) {
            'company'       => true,
            'cash_register' => $row['scope_id'] === $registerId,
            default         => false,
        };
    }

    /**
     * Vydá další číslo nativní řady. Volej ve STEJNÉ transakci, která číslo zapíše na doklad.
     *
     * @param array<string,mixed> $row řada (storage = native)
     */
    public function allocate(
        int $supplierId,
        array $row,
        \DateTimeInterface $date,
        string $taxPrefix = 'PF',
        ?int $fiscalYear = null,
    ): string {
        if ($row['storage'] !== 'native') {
            throw new \LogicException('Převedená řada se čísluje dosavadním generátorem.');
        }
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Výdej čísla řady vyžaduje transakci volajícího (zámek počítadla).');
        }
        self::assertOpen($row, $date);
        $periodKey = NumberSeriesTemplate::periodKey((string) $row['period'], $date, $fiscalYear);
        for ($attempt = 0; $attempt <= self::MAX_SKIP; $attempt++) {
            $counter = $this->series->bumpCounter((int) $row['id'], $periodKey);
            $number = NumberSeriesTemplate::render((string) $row['template'], $row['prefix'], $date, $counter, $taxPrefix, $fiscalYear);
            if (!$this->numberExists($supplierId, (string) $row['doc_kind'], $number)) {
                return $number;
            }
        }
        throw new NumberSeriesException(
            'series_exhausted',
            'Číselná řada nevydala volné číslo ani po ' . self::MAX_SKIP . ' pokusech. Zkontrolujte další číslo řady.',
            409,
        );
    }

    /**
     * Číslo, které dostane příští doklad řady (bez zápisu).
     *
     * @param array<string,mixed> $row
     */
    public function peek(int $supplierId, array $row, \DateTimeInterface $date, string $taxPrefix = 'PF', ?int $fiscalYear = null): string
    {
        $periodKey = NumberSeriesTemplate::periodKey((string) $row['period'], $date, $fiscalYear);
        $next = $this->series->counter((int) $row['id'], $periodKey)['last_number'] + 1;
        for ($attempt = 0; $attempt < self::MAX_SKIP; $attempt++) {
            $number = NumberSeriesTemplate::render((string) $row['template'], $row['prefix'], $date, $next, $taxPrefix, $fiscalYear);
            if (!$this->numberExists($supplierId, (string) $row['doc_kind'], $number)) {
                return $number;
            }
            $next++;
        }
        return NumberSeriesTemplate::render((string) $row['template'], $row['prefix'], $date, $next, $taxPrefix, $fiscalYear);
    }

    /** @return int číslo příštího dokladu (čítač) pro období data */
    public function nextCounter(array $row, \DateTimeInterface $date, ?int $fiscalYear = null): int
    {
        $periodKey = NumberSeriesTemplate::periodKey((string) $row['period'], $date, $fiscalYear);
        return $this->series->counter((int) $row['id'], $periodKey)['last_number'] + 1;
    }

    /**
     * Nastaví, jaké číslo dostane příští doklad řady v období data. Ruční začátek řady
     * (`floor_number`) zároveň říká kontrole úplnosti, že čísla pod ním v systému nevznikla.
     *
     * @param array<string,mixed> $row
     */
    public function setNextNumber(array $row, int $next, \DateTimeInterface $date, ?int $fiscalYear = null): void
    {
        if ($next < 1 || $next > 999999999) {
            throw new NumberSeriesException('next_number_invalid', 'Další číslo řady musí být celé číslo 1 až 999999999.');
        }
        $periodKey = NumberSeriesTemplate::periodKey((string) $row['period'], $date, $fiscalYear);
        $this->series->setCounter((int) $row['id'], $periodKey, $next - 1);
    }

    /**
     * Vrátí číslo smazaného dokladu do řady, je-li v ní poslední (stejně jako
     * {@see \MyInvoice\Service\Invoice\VarsymbolGenerator::releaseIfLatest()}).
     *
     * @param array<string,mixed> $row
     */
    public function releaseIfLatest(array $row, string $number, \DateTimeInterface $date, string $taxPrefix = 'PF', ?int $fiscalYear = null): bool
    {
        if ($row['storage'] !== 'native' || $number === '') {
            return false;
        }
        $periodKey = NumberSeriesTemplate::periodKey((string) $row['period'], $date, $fiscalYear);
        $current = $this->series->counter((int) $row['id'], $periodKey)['last_number'];
        if ($current <= 0) {
            return false;
        }
        $rendered = NumberSeriesTemplate::render((string) $row['template'], $row['prefix'], $date, $current, $taxPrefix, $fiscalYear);
        if ($rendered !== $number) {
            return false;
        }
        return $this->series->decrementIfLast((int) $row['id'], $periodKey, $current);
    }

    /** @return array<string,mixed>|null nativní řada podle id (null = převedená nebo neexistuje) */
    public function nativeById(int $supplierId, ?int $id): ?array
    {
        if ($id === null || $id <= 0 || !$this->db->hasTable('number_series')) {
            return null;
        }
        $row = $this->series->find($supplierId, $id);
        return $row !== null && $row['storage'] === 'native' ? $row : null;
    }

    public function numberExists(int $supplierId, string $kind, string $number): bool
    {
        $space = NumberSeriesKinds::space($kind);
        if ($space === null) {
            return false;
        }
        $s = NumberSeriesKinds::SPACES[$space];
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$s['table']} WHERE supplier_id = ? AND {$s['number']} = ? LIMIT 1");
        $stmt->execute([$supplierId, $number]);
        return $stmt->fetchColumn() !== false;
    }
}
