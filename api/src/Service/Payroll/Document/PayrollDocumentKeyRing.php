<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use PDO;

/**
 * Datové klíče mzdových dokumentů — obálkové šifrování s krypto-výmazem.
 *
 * Master klíč instance (`cfg.app.secret_encryption_key`) je společný pro celou
 * databázi, takže se nedá zahodit kvůli jedné osobě. Mezi něj a dokument se
 * proto vkládá datový klíč (DEK) vázaný na SUBJEKT — konkrétní osobu, nebo
 * `0` pro dokumenty firmy. DEK je uložený zabalený master klíčem; obsah
 * dokumentu je zašifrovaný DEKem.
 *
 * Výmaz osobních údajů pak nemusí sáhnout na append-only archiv ani na soubor
 * na disku: stačí zahodit DEK ({@see destroy()}) a všechny dokumenty subjektu
 * jsou nevratně nečitelné. Řádky v `payroll_generated_documents`, jejich hashe
 * i evidence vydání zůstávají beze změny, takže neměnnost archivu podle § 35
 * odst. 3 zákona č. 563/1991 Sb. drží dál a čl. 17 GDPR je přesto splnitelný.
 *
 * Rozdíl proti mazání souboru je jen jeden a je ve prospěch archivu: zůstává
 * prokazatelné, ŽE dokument existoval, jaký měl hash a komu se vydal.
 */
final class PayrollDocumentKeyRing
{
    /** Dokumenty firmy (rekapitulace, měsíční balíček) — klíč se nezahazuje. */
    public const COMPANY_SUBJECT_ID = 0;

    private const KEY_BYTES = 32;
    private const CONTEXT_PREFIX = 'payroll-document-data-key';

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $encryption,
    ) {}

    /**
     * DEK pro zápis. Neexistuje-li, založí se; je-li zahozený, zápis se
     * odmítne — do znečitelněného archivu se nesmí přidávat čitelný obsah.
     */
    public function dataKeyForWrite(
        int $supplierId,
        int $subjectId,
        ?int $actorUserId = null,
    ): string {
        self::assertIdentity($supplierId, $subjectId);
        $row = $this->row($supplierId, $subjectId);
        if ($row !== null) {
            if ($row['destroyed_at'] !== null) {
                throw new PayrollDocumentKeyDestroyedException(sprintf(
                    'Datový klíč mzdových dokumentů subjektu %d byl zahozen '
                        . 'při výmazu osobních údajů — nový dokument už '
                        . 'vydat nelze.',
                    $subjectId,
                ));
            }

            return $this->unwrap($supplierId, $subjectId, (string) $row['wrapped_key']);
        }

        $key = random_bytes(self::KEY_BYTES);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_document_data_keys
                (supplier_id, subject_id, wrapped_key, created_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE supplier_id = supplier_id',
        );
        $stmt->execute([
            $supplierId,
            $subjectId,
            $this->encryption->encryptFor(
                base64_encode($key),
                self::context($supplierId, $subjectId),
            ),
            $actorUserId,
        ]);
        // Souběžný zápis mohl klíč založit dřív — rozhoduje vždycky ten
        // v databázi, jinak by dva požadavky uložily soubory pod dvěma klíči.
        $row = $this->row($supplierId, $subjectId);
        if ($row === null) {
            throw new \RuntimeException(
                'Datový klíč mzdových dokumentů se nepodařilo založit.',
            );
        }
        if ($row['destroyed_at'] !== null) {
            throw new PayrollDocumentKeyDestroyedException(
                'Datový klíč mzdových dokumentů byl mezitím zahozen.',
            );
        }

        return $this->unwrap($supplierId, $subjectId, (string) $row['wrapped_key']);
    }

    /**
     * DEK pro čtení.
     *
     * @throws PayrollDocumentKeyDestroyedException klíč byl zahozen výmazem
     * @throws PayrollDocumentKeyMissingException klíč nikdy neexistoval
     *         (dokument je z doby před zavedením šifrování — čte se legacy
     *         větví, ne přes klíč)
     */
    public function dataKeyForRead(int $supplierId, int $subjectId): string
    {
        self::assertIdentity($supplierId, $subjectId);
        $row = $this->row($supplierId, $subjectId);
        if ($row === null) {
            throw new PayrollDocumentKeyMissingException(sprintf(
                'Datový klíč mzdových dokumentů subjektu %d neexistuje.',
                $subjectId,
            ));
        }
        if ($row['destroyed_at'] !== null) {
            throw new PayrollDocumentKeyDestroyedException(sprintf(
                'Mzdové dokumenty subjektu %d jsou nečitelné — jejich datový '
                    . 'klíč byl zahozen při výmazu osobních údajů '
                    . '(%s).',
                $subjectId,
                (string) $row['destroyed_at'],
            ));
        }

        return $this->unwrap($supplierId, $subjectId, (string) $row['wrapped_key']);
    }

    /** Je klíč subjektu zahozený? Pro obrazovky, ne pro rozhodování o čtení. */
    public function isDestroyed(int $supplierId, int $subjectId): bool
    {
        self::assertIdentity($supplierId, $subjectId);
        $row = $this->row($supplierId, $subjectId);

        return $row !== null && $row['destroyed_at'] !== null;
    }

    /**
     * Krypto-výmaz: zahodí datový klíč subjektu.
     *
     * Vrací počet zahozených klíčů (0 nebo 1) — nula znamená, že subjekt žádné
     * šifrované dokumenty nemá, nebo že klíč už zahozený byl. Operace je
     * idempotentní a NEVRATNÁ; zabalený DEK se přepíše prázdnou hodnotou, ne
     * jen označí příznakem, aby ho nešlo obnovit z řádku.
     *
     * Klíč firemních dokumentů (`subject_id = 0`) zahodit nelze — hlídá to
     * i CHECK v databázi.
     */
    public function destroy(
        int $supplierId,
        int $subjectId,
        ?int $actorUserId,
        string $reason,
    ): int {
        self::assertIdentity($supplierId, $subjectId);
        if ($subjectId === self::COMPANY_SUBJECT_ID) {
            throw new \InvalidArgumentException(
                'Klíč firemních mzdových dokumentů nelze zahodit.',
            );
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException(
                'Krypto-výmaz vyžaduje důvod.',
            );
        }
        $stmt = $this->db->pdo()->prepare(
            "UPDATE payroll_document_data_keys
                SET wrapped_key = '',
                    destroyed_at = NOW(),
                    destroyed_by = ?,
                    destroy_reason = ?
              WHERE supplier_id = ? AND subject_id = ?
                AND destroyed_at IS NULL",
        );
        $stmt->execute([
            $actorUserId,
            mb_substr($reason, 0, 255),
            $supplierId,
            $subjectId,
        ]);

        return $stmt->rowCount();
    }

    /** @return array<string,mixed>|null */
    private function row(int $supplierId, int $subjectId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT wrapped_key, destroyed_at
               FROM payroll_document_data_keys
              WHERE supplier_id = ? AND subject_id = ?',
        );
        $stmt->execute([$supplierId, $subjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function unwrap(
        int $supplierId,
        int $subjectId,
        string $wrapped,
    ): string {
        $decoded = base64_decode(
            $this->encryption->decryptFor(
                $wrapped,
                self::context($supplierId, $subjectId),
            ),
            true,
        );
        if ($decoded === false || strlen($decoded) !== self::KEY_BYTES) {
            throw new \RuntimeException(
                'Datový klíč mzdových dokumentů je poškozený.',
            );
        }

        return $decoded;
    }

    private static function context(int $supplierId, int $subjectId): string
    {
        return self::CONTEXT_PREFIX . ':' . $supplierId . ':' . $subjectId;
    }

    private static function assertIdentity(int $supplierId, int $subjectId): void
    {
        if ($supplierId <= 0 || $subjectId < 0) {
            throw new \InvalidArgumentException(
                'Identita datového klíče mzdových dokumentů není platná.',
            );
        }
    }
}
