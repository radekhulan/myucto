<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RoleRepository;
use MyInvoice\Repository\SystemRoleLocked;
use MyInvoice\Service\License\SeatPolicy;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kdo zabírá licenční místo.
 *
 * Dvě cesty, kterými se dal limit obejít, a obě vedly přes běžné obrazovky
 * administrace, ne přes hack:
 *
 *   1. účet „Pouze pro čtení" dostal přes přiřazení firem override roli
 *      „Účetní" — kontrolovala se jen shoda `role_type`, a ta je u obou `staff`;
 *   2. systémová role „Pouze pro čtení" se dala přes API přepsat na zapisující
 *      a `system_key` ji dál vyřazoval z počtu.
 *
 * Testy jsou psané tak, aby NEBYLY zelené omylem: u každého tvrzení se ověřuje
 * i opačný případ, takže „nezabírá místo" neprojde jen proto, že by metoda
 * vracela false vždycky.
 */
#[Group('integration')]
final class SeatPolicyTest extends TestCase
{
    private Connection $db;
    private SeatPolicy $seats;
    private bool $inTx = false;

    private int $readonlyRoleId = 0;
    private int $accountantRoleId = 0;
    private int $superadminRoleId = 0;
    private int $clientRoleId = 0;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->ping() || !$this->db->hasTable('role_permissions')) {
            $this->markTestSkipped('Migrace 1074 (dynamické role) neproběhla.');
        }
        $this->seats = new SeatPolicy($this->db);

        $pdo = $this->db->pdo();
        $this->readonlyRoleId   = $this->roleIdByKey('readonly');
        $this->accountantRoleId = $this->roleIdByKey('accountant');
        $this->superadminRoleId = $this->roleIdByKey('superadmin');
        $this->clientRoleId = $this->roleIdByKey('client');
        if ($this->readonlyRoleId === 0 || $this->accountantRoleId === 0
            || $this->superadminRoleId === 0 || $this->clientRoleId === 0
        ) {
            $this->markTestSkipped('Systémové role nejsou naseedované.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── role samotné ──────────────────────────────────────────────────────

    public function testReadOnlyRoleDoesNotOccupyASeatButAccountantDoes(): void
    {
        self::assertFalse(
            $this->seats->roleGrantsWrite($this->readonlyRoleId),
            'Vestavěná čtecí role nemá právo zápisu mimo správu vlastních tokenů.',
        );
        self::assertTrue(
            $this->seats->roleGrantsWrite($this->accountantRoleId),
            'Účetní má právo zápisu, tedy zabírá místo — jinak by test nic neověřoval.',
        );
    }

    /**
     * Superadmin nemá v `role_permissions` ani řádek — jeho přístup je
     * implicitní. Kdyby se počítalo jen podle uložených práv, jediný účet,
     * který instalaci opravdu ovládá, by se do počtu nikdy nezapočítal.
     */
    public function testSuperadminOccupiesASeatDespiteHavingNoStoredPermissions(): void
    {
        $stored = (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM role_permissions WHERE role_id = ' . $this->superadminRoleId
        )->fetchColumn();
        self::assertSame(0, $stored, 'Superadmin nemá uložená práva — na tom test stojí.');

        self::assertTrue($this->seats->roleGrantsWrite($this->superadminRoleId));
    }

    public function testWritingClientRoleOccupiesASeat(): void
    {
        self::assertTrue(
            $this->seats->roleGrantsWrite($this->clientRoleId),
            'Systémová klientská role má business WRITE a typ client ji nesmí vyjmout z licence.',
        );

        $before = $this->seats->countActiveSeats();
        $this->createUser($this->clientRoleId);
        self::assertSame($before + 1, $this->seats->countActiveSeats());
    }

    public function testActiveUserWithInactiveCustomRoleStillOccupiesASeat(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO roles (system_key, name, role_type, is_active) VALUES (NULL, ?, 'staff', 1)")
            ->execute(['Seat inactive ' . bin2hex(random_bytes(4))]);
        $roleId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, 'invoices', 2)")
            ->execute([$roleId]);

        $before = $this->seats->countActiveSeats();
        $this->createUser($roleId);
        self::assertSame($before + 1, $this->seats->countActiveSeats());

        $pdo->prepare('UPDATE roles SET is_active = 0 WHERE id = ?')->execute([$roleId]);
        self::assertFalse($this->seats->roleGrantsWrite($roleId));
        self::assertSame($before + 1, $this->seats->countActiveSeats());
    }

    public function testSelfServiceExceptionIsExplicitAndNarrow(): void
    {
        self::assertFalse(SeatPolicy::isSelfServicePermission('profile'));
        self::assertTrue(SeatPolicy::isSelfServicePermission('profile.tokens'));
        self::assertFalse(SeatPolicy::isSelfServicePermission('invoices'));
    }

    // ── obejití přes per-firemní override ─────────────────────────────────

    public function testReadOnlyUserWithAccountantOverrideOccupiesASeat(): void
    {
        self::assertFalse(
            $this->seats->occupiesSeat($this->readonlyRoleId, []),
            'Bez override je to účet jen pro čtení.',
        );
        self::assertTrue(
            $this->seats->occupiesSeat($this->readonlyRoleId, [$this->accountantRoleId]),
            'Override rolí Účetní účet získá právo zápisu, a tím i licenční místo.',
        );
    }

    public function testSeatCountIncludesUsersWhoOnlyWriteThroughAnOverride(): void
    {
        $before = $this->seats->countActiveSeats();

        $userId = $this->createUser($this->readonlyRoleId);
        self::assertSame($before, $this->seats->countActiveSeats(), 'Účet jen pro čtení místo nezabírá.');

        $supplierId = (int) $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        if ($supplierId <= 0) {
            $this->markTestSkipped('V testovací DB není žádná firma.');
        }
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$userId, $supplierId, $this->accountantRoleId]);

        self::assertSame(
            $before + 1,
            $this->seats->countActiveSeats(),
            'Po přiřazení zapisující role nad firmou účet místo zabírá.',
        );
    }

    /** Deaktivovaný účet místo nedrží — jinak by nešlo uvolnit místo. */
    public function testInactiveUserDoesNotOccupyASeat(): void
    {
        $before = $this->seats->countActiveSeats();
        $userId = $this->createUser($this->accountantRoleId);
        self::assertSame($before + 1, $this->seats->countActiveSeats());

        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
        self::assertSame($before, $this->seats->countActiveSeats());
    }

    // ── obejití přepsáním systémové role ──────────────────────────────────

    public function testReadOnlySystemRoleCannotBeGivenWriteAccess(): void
    {
        $repo = Bootstrap::buildContainer()->get(RoleRepository::class);
        $role = $repo->find($this->readonlyRoleId);
        self::assertIsArray($role);

        $this->expectException(SystemRoleLocked::class);
        $repo->update(
            $this->readonlyRoleId,
            (string) $role['name'],
            true,
            ['invoices' => 2],
            (string) $role['updated_at'],
        );
    }

    public function testCustomReadOnlyStaffAndClientRolesStillOccupySeats(): void
    {
        $pdo = $this->db->pdo();
        foreach (['staff', 'client'] as $type) {
            $pdo->prepare('INSERT INTO roles (system_key, name, role_type, is_active) VALUES (NULL, ?, ?, 1)')
                ->execute(['Pouze pro čtení', $type]);
            $roleId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, 'invoices', 1)")->execute([$roleId]);
            $before = $this->seats->countActiveSeats();
            $this->createUser($roleId);
            self::assertSame($before + 1, $this->seats->countActiveSeats(), $type);
            self::assertTrue($this->seats->occupiesSeat($roleId));
        }
    }

    public function testNonWritingOverrideRemovesBuiltInReadOnlyExemption(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE role_permissions SET access_level=1 WHERE role_id=? AND permission_key <> 'profile.tokens'")->execute([$this->readonlyRoleId]);
        $pdo->prepare("INSERT INTO roles (system_key, name, role_type, is_active) VALUES (NULL, 'Auditor', 'staff', 1)")->execute();
        $roleId = (int) $pdo->lastInsertId();
        $before = $this->seats->countActiveSeats();
        $userId = $this->createUser($this->readonlyRoleId);
        self::assertSame($before, $this->seats->countActiveSeats());
        $supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $supplierId);
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')->execute([$userId, $supplierId, $roleId]);
        self::assertSame($before + 1, $this->seats->countActiveSeats());
        self::assertTrue($this->seats->occupiesSeat($this->readonlyRoleId, [$roleId]));
    }

    public function testBuiltInReadOnlyCannotReceiveProfileWrite(): void
    {
        $repo = Bootstrap::buildContainer()->get(RoleRepository::class);
        $role = $repo->find($this->readonlyRoleId);
        self::assertIsArray($role);
        $this->expectException(SystemRoleLocked::class);
        $repo->update($this->readonlyRoleId, (string) $role['name'], true, ['profile' => 2], (string) $role['updated_at']);
    }

    public function testBuiltInReadOnlyCanManageOwnTokensWithoutASeat(): void
    {
        $repo = Bootstrap::buildContainer()->get(RoleRepository::class);
        $role = $repo->find($this->readonlyRoleId);
        self::assertIsArray($role);
        $repo->update($this->readonlyRoleId, (string) $role['name'], true, ['profile' => 1, 'profile.tokens' => 2], (string) $role['updated_at']);
        $before = $this->seats->countActiveSeats();
        $this->createUser($this->readonlyRoleId);
        self::assertSame($before, $this->seats->countActiveSeats());
        self::assertFalse($this->seats->occupiesSeat($this->readonlyRoleId));
        self::assertFalse($this->seats->roleGrantsWrite($this->readonlyRoleId));
    }

    public function testDisablingBuiltInReadOnlyRoleDoesNotAddSeats(): void
    {
        $before = $this->seats->countActiveSeats();
        $this->createUser($this->readonlyRoleId);
        $this->db->pdo()->prepare('UPDATE roles SET is_active = 0 WHERE id = ?')->execute([$this->readonlyRoleId]);
        self::assertSame($before, $this->seats->countActiveSeats());
        self::assertFalse($this->seats->occupiesSeat($this->readonlyRoleId));
    }

    public function testCorruptedBuiltInReadOnlyRoleWithWriteConsumesSeats(): void
    {
        $before = $this->seats->countActiveSeats();
        $this->createUser($this->readonlyRoleId);
        self::assertSame($before, $this->seats->countActiveSeats());
        $this->db->pdo()->prepare("UPDATE role_permissions SET access_level = 2 WHERE role_id = ? AND permission_key = 'invoices'")->execute([$this->readonlyRoleId]);
        self::assertTrue($this->seats->occupiesSeat($this->readonlyRoleId));
        self::assertSame($before + 1, $this->seats->countActiveSeats());
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function roleIdByKey(string $key): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ? LIMIT 1');
        $stmt->execute([$key]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function createUser(int $roleId): int
    {
        $email = 'seat-' . bin2hex(random_bytes(6)) . '@example.test';
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active)
             VALUES (?, 'x', 'Seat test', 'readonly', ?, 'cs', 1)"
        );
        $stmt->execute([$email, $roleId]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
