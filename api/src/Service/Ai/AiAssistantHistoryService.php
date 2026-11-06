<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Historie dotazů AI asistenta. Konverzace vidí jen její autor v rámci firmy (stejně
 * jako cizí rozpracovaný dotaz by nikomu jinému nepatřil). U odpovědi se drží značky,
 * které návrhy už uživatel použil, aby po znovuotevření konverzace nešly zaúčtovat
 * nebo založit podruhé.
 */
final class AiAssistantHistoryService
{
    public const MAX_LIST = 100;
    public const CONTEXT_MESSAGES = 10;
    private const APPLIED_KINDS = ['journal_entry', 'other_item', 'internal_document', 'document'];

    public function __construct(private readonly Connection $db) {}

    /** Ověří, že konverzace patří uživateli; jinak 404 (cizí konverzace „neexistuje"). */
    public function assertOwned(int $supplierId, int $userId, int $conversationId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM ai_assistant_conversations WHERE id = ? AND supplier_id = ? AND user_id = ?');
        $stmt->execute([$conversationId, $supplierId, $userId]);
        if (!$stmt->fetchColumn()) {
            throw new AccountingAssistantException('ai_assistant.conversation_not_found', 'Konverzace nebyla nalezena.', 404);
        }
    }

    /**
     * Posledních několik zpráv konverzace jako kontext pro další dotaz.
     *
     * @return list<array{role:string, content:string}>
     */
    public function contextHistory(int $supplierId, int $userId, int $conversationId): array
    {
        $this->assertOwned($supplierId, $userId, $conversationId);
        $stmt = $this->db->pdo()->prepare('SELECT role, content FROM ai_assistant_messages
            WHERE conversation_id = ? AND supplier_id = ? ORDER BY id DESC LIMIT ' . self::CONTEXT_MESSAGES);
        $stmt->execute([$conversationId, $supplierId]);
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        return array_map(static fn (array $r): array => [
            'role' => (string) $r['role'],
            'content' => mb_substr((string) $r['content'], 0, AccountingAssistantService::MAX_QUESTION_CHARS),
        ], $rows);
    }

    /**
     * Uloží dotaz a odpověď; bez konverzace založí novou s názvem podle dotazu.
     *
     * @param list<array<string,mixed>> $attachments  [{kind:'file', name} | {kind:'document', id}]
     * @param array<string,mixed>       $result       odpověď AccountingAssistantService::ask()
     * @return array{conversation_id:int, message_id:int}
     */
    public function record(int $supplierId, int $userId, ?int $conversationId, string $question, array $attachments, array $result): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            if ($conversationId === null) {
                $title = trim((string) preg_replace('/\s+/u', ' ', $question));
                $title = mb_strlen($title) > 120 ? mb_substr($title, 0, 117) . '…' : $title;
                $pdo->prepare('INSERT INTO ai_assistant_conversations (supplier_id, user_id, title) VALUES (?,?,?)')
                    ->execute([$supplierId, $userId, $title !== '' ? $title : 'Dotaz']);
                $conversationId = (int) $pdo->lastInsertId();
            } else {
                $this->assertOwned($supplierId, $userId, $conversationId);
                $pdo->prepare('UPDATE ai_assistant_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$conversationId]);
            }
            $insert = $pdo->prepare('INSERT INTO ai_assistant_messages
                (conversation_id, supplier_id, role, content, attachments_json, response_json, model, input_tokens, output_tokens)
                VALUES (?,?,?,?,?,?,?,?,?)');
            $insert->execute([$conversationId, $supplierId, 'user', $question,
                $attachments !== [] ? json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                null, null, 0, 0]);
            $response = $result;
            unset($response['answer'], $response['usage'], $response['provider'], $response['model']);
            $insert->execute([$conversationId, $supplierId, 'assistant', (string) ($result['answer'] ?? ''), null,
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                mb_substr((string) ($result['model'] ?? ''), 0, 100) ?: null,
                (int) ($result['usage']['input_tokens'] ?? 0), (int) ($result['usage']['output_tokens'] ?? 0)]);
            $messageId = (int) $pdo->lastInsertId();
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['conversation_id' => $conversationId, 'message_id' => $messageId];
    }

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT c.id, c.title, c.created_at, c.updated_at,
                (SELECT COUNT(*) FROM ai_assistant_messages m WHERE m.conversation_id = c.id AND m.role = \'user\') AS question_count
            FROM ai_assistant_conversations c
            WHERE c.supplier_id = ? AND c.user_id = ?
            ORDER BY c.updated_at DESC, c.id DESC LIMIT ' . self::MAX_LIST);
        $stmt->execute([$supplierId, $userId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'title' => (string) $r['title'],
            'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at'],
            'question_count' => (int) $r['question_count'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    public function get(int $supplierId, int $userId, int $conversationId): array
    {
        $this->assertOwned($supplierId, $userId, $conversationId);
        $conv = $this->db->pdo()->prepare('SELECT id, title, created_at, updated_at FROM ai_assistant_conversations WHERE id = ?');
        $conv->execute([$conversationId]);
        $row = $conv->fetch(PDO::FETCH_ASSOC);
        $stmt = $this->db->pdo()->prepare('SELECT id, role, content, attachments_json, response_json, applied_json, model, created_at
            FROM ai_assistant_messages WHERE conversation_id = ? AND supplier_id = ? ORDER BY id');
        $stmt->execute([$conversationId, $supplierId]);
        $messages = array_map(static fn (array $m): array => [
            'id' => (int) $m['id'],
            'role' => (string) $m['role'],
            'content' => (string) $m['content'],
            'attachments' => $m['attachments_json'] !== null ? json_decode((string) $m['attachments_json'], true) : [],
            'response' => $m['response_json'] !== null ? json_decode((string) $m['response_json'], true) : null,
            'applied' => $m['applied_json'] !== null ? json_decode((string) $m['applied_json'], true) : [],
            'model' => $m['model'],
            'created_at' => (string) $m['created_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return [
            'id' => (int) $row['id'], 'title' => (string) $row['title'],
            'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at'],
            'messages' => $messages,
        ];
    }

    public function delete(int $supplierId, int $userId, int $conversationId): void
    {
        $this->assertOwned($supplierId, $userId, $conversationId);
        $this->db->pdo()->prepare('DELETE FROM ai_assistant_conversations WHERE id = ? AND supplier_id = ? AND user_id = ?')
            ->execute([$conversationId, $supplierId, $userId]);
    }

    /**
     * Poznamená u odpovědi použitý návrh (zaúčtovaný zápis, založená položka, nahraný soubor).
     *
     * @return array<string,array<string,int>> aktualizované značky {kind: {index: id}}
     */
    public function markApplied(int $supplierId, int $userId, int $messageId, string $kind, int $index, int $targetId): array
    {
        if (!in_array($kind, self::APPLIED_KINDS, true) || $index < 0 || $index > 200 || $targetId <= 0) {
            throw AccountingAssistantException::invalid('Neplatná značka použitého návrhu.');
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT m.applied_json FROM ai_assistant_messages m
                JOIN ai_assistant_conversations c ON c.id = m.conversation_id
                WHERE m.id = ? AND m.supplier_id = ? AND c.user_id = ? AND m.role = 'assistant' FOR UPDATE");
            $stmt->execute([$messageId, $supplierId, $userId]);
            $raw = $stmt->fetchColumn();
            if ($raw === false) {
                throw new AccountingAssistantException('ai_assistant.conversation_not_found', 'Zpráva nebyla nalezena.', 404);
            }
            $applied = is_string($raw) ? (array) json_decode($raw, true) : [];
            $applied[$kind][(string) $index] = $targetId;
            $pdo->prepare('UPDATE ai_assistant_messages SET applied_json = ? WHERE id = ?')
                ->execute([json_encode($applied, JSON_THROW_ON_ERROR), $messageId]);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $applied;
    }
}
