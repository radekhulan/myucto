<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\SourceCorpus;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class SavepointFulltextGuardTest extends TestCase
{
    /** @var array<string,string> symbol => důvod, proč částečný rollback neohrozí FULLTEXT */
    private const ALLOWED = [
        'MyInvoice\Action\Bank\BankPaymentCandidatesAction::batch'
            => 'Párování platby může vložit položky nového DDKP či finální faktury. Uložené texty položek nemění.',
        'MyInvoice\Action\Bank\BankPaymentCandidatesAction::match'
            => 'Párování platby může vložit položky nového DDKP či finální faktury. Uložené texty položek nemění.',
        'MyInvoice\Action\Bank\BankStatementAction::createPurchaseInvoiceWithCurrentSource'
            => 'Položky nové faktury vzniknou před savepointem. Ten chrání jen vazbu platby a stav pohybu.',
        'MyInvoice\Action\Bank\BankStatementAction::manualMatchWithCurrentSource'
            => 'DDKP a finální faktura vznikají s novými položkami. Existující položky se nepřepisují.',
        'MyInvoice\Action\Bank\BankStatementAction::performManualMatch'
            => 'DDKP a finální faktura vznikají s novými položkami. Existující položky se nepřepisují.',
        'MyInvoice\Action\Bank\BankStatementAction::manualMatchSplit'
            => 'DDKP a finální faktura vznikají s novými položkami. Existující položky se nepřepisují.',
        'MyInvoice\Action\Invoice\SetInvoiceAccrualAction::__invoke'
            => 'Mění jen accrual_from a accrual_to. Popis řádku se nemění.',
        'MyInvoice\Action\PurchaseInvoice\ChangePurchaseInvoiceVatTreatmentAction::__invoke'
            => 'Oprava mění na položkách jen vat_classification_code. Popis zůstává nedotčený.',
        'MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceProjectAction::__invoke'
            => 'Přiřazuje zakázku v hlavičce a upravuje dimenze deníku.',
        'MyInvoice\Repository\InvoiceRepository::createDraft'
            => 'Savepoint vkládá hlavičku faktury a přiřazuje štítky. Položky s FULLTEXT indexem vytváří volající až mimo tento savepoint.',
        'MyInvoice\Repository\InvoiceRepository::updateDraft'
            => 'Savepoint upravuje hlavičku faktury a přiřazení štítků. Přepis položek s FULLTEXT indexem provádí volající mimo tento savepoint.',
        'MyInvoice\Repository\PurchaseInvoiceRepository::updateDraft'
            => 'Upravuje hlavičku a případně pomocné sloupce import_tax_* v položkách. Přepis položek provádí až volající mimo tento savepoint.',
        'MyInvoice\Repository\PurchaseInvoiceRepository::setStatus'
            => 'Přechod stavu a odvození úhrady mění jen hlavičku přijaté faktury.',
        'MyInvoice\Repository\PurchaseInvoiceRepository::updateDocumentKind'
            => 'Mění document_kind v hlavičce přijaté faktury.',
        'MyInvoice\Service\Accounting\Bulk\DeleteOperation::run'
            => 'Náhled končí před DELETE hlavičky a kaskádou na položky. Ostrý zápis tímto savepointem neprochází.',
        'MyInvoice\Service\Accounting\Bulk\SetNoteOperation::evaluate'
            => 'Tělo obsahuje jen poznámku. Uložení nedostane položky ani změnu slevy a nemůže je přepsat.',
        'MyInvoice\Service\Accounting\Bulk\SetNoteOperation::apply'
            => 'Tělo obsahuje jen poznámku. Uložení nedostane položky ani změnu slevy a nemůže je přepsat.',
        'MyInvoice\Service\Accounting\Bulk\SetNoteOperation::rollback'
            => 'Tělo obsahuje jen poznámku. Uložení nedostane položky ani změnu slevy a nemůže je přepsat.',
        'MyInvoice\Service\Accounting\Bulk\SetNoteOperation::save'
            => 'Tělo obsahuje jen poznámku. Uložení nedostane položky ani změnu slevy a nemůže je přepsat.',
        'MyInvoice\Service\Accounting\Bulk\SetPostingRuleOperation::evaluate'
            => 'Mění klíč předkontace a účty položek, bez výměny řádků a změny jejich popisu.',
        'MyInvoice\Service\Accounting\Bulk\SetPostingRuleOperation::apply'
            => 'Mění klíč předkontace a účty položek, bez výměny řádků a změny jejich popisu.',
        'MyInvoice\Service\Accounting\Bulk\SetPostingRuleOperation::rollback'
            => 'Mění klíč předkontace a účty položek, bez výměny řádků a změny jejich popisu.',
        'MyInvoice\Service\Accounting\Bulk\SetPostingRuleOperation::transaction'
            => 'Mění klíč předkontace a účty položek, bez výměny řádků a změny jejich popisu.',
        'MyInvoice\Service\Accounting\Expense\PurchaseDiscountReclass::candidates'
            => 'Volá run s apply=false. Případná zkušební změna se týká jen expense_kind.',
        'MyInvoice\Service\Accounting\Expense\PurchaseDiscountReclass::run'
            => 'Mění expense_kind, karty majetku a související deník. Popis položky se nemění.',
        'MyInvoice\Service\Accounting\Setup\AccountingHistoryReclassificationService::applyReplacement'
            => 'Transakce dokladu zahrnuje deník a neindexované expense_kind, expense_account_code a is_fixed_asset.',
        'MyInvoice\Service\Accounting\Setup\AccountingHistoryReclassificationService::rollback'
            => 'Transakce dokladu zahrnuje deník a neindexované expense_kind, expense_account_code a is_fixed_asset.',
        'MyInvoice\Service\Accounting\Setup\AccountingHistoryReclassificationService::beginDocumentTransaction'
            => 'Transakce dokladu zahrnuje deník a neindexované expense_kind, expense_account_code a is_fixed_asset.',
        'MyInvoice\Service\Accounting\Setup\AccountingHistoryReclassificationService::commitDocumentTransaction'
            => 'Transakce dokladu zahrnuje deník a neindexované expense_kind, expense_account_code a is_fixed_asset.',
        'MyInvoice\Service\Accounting\Setup\AccountingHistoryReclassificationService::rollBackDocumentTransaction'
            => 'Transakce dokladu zahrnuje deník a neindexované expense_kind, expense_account_code a is_fixed_asset.',
        'MyInvoice\Service\Bank\Match\MatchSuggestionService::afterMatch'
            => 'Automatické párování může založit DDKP či finální fakturu s novými položkami.',
        'MyInvoice\Service\Bank\Match\MatchSuggestionService::afterMatchLocked'
            => 'Automatické párování může založit DDKP či finální fakturu s novými položkami.',
        'MyInvoice\Service\Bank\StatementMatcher::match'
            => 'Párování může přidat položky jen k nově založeným fakturám. Na popisy existujících položek nesahá.',
        'MyInvoice\Service\Bank\StatementMatcher::matchBatchIds'
            => 'Párování může přidat položky jen k nově založeným fakturám. Na popisy existujících položek nesahá.',
        'MyInvoice\Service\Bank\StatementMatcher::doMatch'
            => 'Párování může přidat položky jen k nově založeným fakturám. Na popisy existujících položek nesahá.',
        'MyInvoice\Service\Bank\StatementMatcher::doMatchCurrent'
            => 'Párování může přidat položky jen k nově založeným fakturám. Na popisy existujících položek nesahá.',
        'MyInvoice\Service\Invoice\PaymentTaxDocumentCreator::createForPayment'
            => 'Vkládá nové položky nového DDKP. Přepočet mění jen jejich částky.',
        'MyInvoice\Service\Migration\Abra\AbraAccountingImporter::import'
            => 'Vkládá nové položky. Na existujících mění sazbu, částky, klasifikaci a daňové otisky, bez změny popisu.',
        'MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter::run'
            => 'Přílohy exekucí dostávají nové ID dokumentu. OCR zapisuje do právě vloženého řádku.',
        'MyInvoice\Service\Migration\Pohoda\PohodaImporter::run'
            => 'Vkládá nové položky. Označení drobného majetku na existujícím řádku mění jen expense_kind.',
        'MyInvoice\Service\Migration\Premier\PayrollImporter::import'
            => 'Přílohy exekucí se zakládají jako nové dokumenty. OCR zapisuje pouze do nových řádků.',
        'MyInvoice\Service\Migration\Premier\PremierImporter::run'
            => 'Vkládá nové položky dokladů a přílohy mezd. OCR pracuje na právě vloženém novém dokumentu.',
        'MyInvoice\Service\Migration\StereoNx\StereoNxImporter::run'
            => 'Vkládá položky nových faktur. Už mapované faktury přeskočí.',
        'MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionDeletionService::delete'
            => 'Koš podání nastavuje deleted_at a deleted_by. Fyzické mazání dokumentů běží zvlášť.',
        'MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionTransferService::move'
            => 'Cílový dokument je nový nebo se použije duplikát bez přepisu. U zdroje se nastaví příznak koše.',
    ];

    private const SAVEPOINT_HELPERS = [
        'MyInvoice\Service\Accounting\Bulk\BulkTransaction::run',
        'MyInvoice\Service\Accounting\Bulk\BulkRecordCall::run',
        'MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard::transaction',
    ];

    private const WRITERS = [
        'MyInvoice\Action\Invoice\CreateInvoiceAction' => ['__invoke'],
        'MyInvoice\Action\Invoice\UpdateInvoiceAction' => ['__invoke'],
        'MyInvoice\Action\Invoice\DeleteInvoiceAction' => ['__invoke'],
        'MyInvoice\Action\Bank\BankStatementAction' => ['manualMatch'],
        'MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction' => ['__invoke'],
        'MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction' => ['__invoke'],
        'MyInvoice\Action\PurchaseInvoice\DeletePurchaseInvoiceAction' => ['__invoke'],
        'MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction' => ['__invoke'],
        'MyInvoice\Service\Document\DocumentIngestService' => ['ingestUploadedTemp', 'ingestOriginalTemp', 'ingestZfoBytes', 'reextractZfoAttachments'],
        'MyInvoice\Service\Migration\MoneyS3\DocumentRefresh' => ['update'],
        'MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer' => ['run'],
        'MyInvoice\Service\Migration\MoneyS3\InvoiceImporter' => ['importIssued', 'importPurchases'],
        'MyInvoice\Service\Migration\Pohoda\InvoiceImporter' => ['importIssued', 'importPurchases'],
        'MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollDeductionsWriter' => ['write'],
        'MyInvoice\Service\Migration\Premier\InvoiceImporter' => ['importIssued', 'importPurchases'],
        'MyInvoice\Service\Migration\Abra\AbraExistingDocumentTaxUpdater' => ['update'],
        'MyInvoice\Service\Migration\Shared\MigratedDocumentWriter' => ['insertIssuedItem', 'insertIssuedItems', 'insertPurchaseItem', 'insertPurchaseItems'],
        'MyInvoice\Service\Payroll\Migration\PayrollTakeoverDeductionsWriter' => ['write'],
        'MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService' => ['submitBytes'],
        'MyInvoice\Service\Invoice\PaymentTaxDocumentCreator' => ['createForPayment'],
        'MyInvoice\Service\Invoice\InvoicePaymentService' => ['recordPayment'],
        'MyInvoice\Service\Invoice\FinalFromProformaCreator' => ['create'],
        'MyInvoice\Service\Bank\StatementMatcher' => ['match', 'matchCardDocument'],
    ];

    private const REPOSITORIES = [
        'MyInvoice\Repository\InvoiceRepository',
        'MyInvoice\Repository\PurchaseInvoiceRepository',
        'MyInvoice\Repository\DocumentRepository',
        'MyInvoice\Repository\DocumentFileRepository',
        'MyInvoice\Repository\DocumentFolderRepository',
    ];

    private const FTS_SQL = '/^\s*(?:(?:UPDATE(?:\s+(?:LOW_PRIORITY|IGNORE))*|'
        . '(?:INSERT|REPLACE)(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*\s+INTO|'
        . 'DELETE(?:\s+(?:LOW_PRIORITY|QUICK|IGNORE))*(?:\s+\S+)?\s+FROM)\s+'
        . '(?:`?\w+`?\.)?`?(?:purchase_invoice_items|invoice_items|documents)\b'
        . '|DELETE\s+FROM\s+`?(?:purchase_invoices|invoices)\b)/i';

    public function testSavepointWithFulltextWritesRequiresReviewedSymbol(): void
    {
        $violations = array_diff_key(self::sourceFindings(), self::ALLOWED);
        self::assertSame([], $violations, 'Prověřte rozsah rollbacku pro zápisy do FULLTEXT tabulek: '
            . implode(', ', array_map(static fn (string $symbol, string $location): string => "$symbol ($location)",
                array_keys($violations), array_values($violations))));
    }

    public function testEveryAllowlistEntryIsLoadBearing(): void
    {
        $findings = self::sourceFindings();
        foreach (self::ALLOWED as $symbol => $reason) {
            self::assertNotSame('', trim($reason), $symbol);
            self::assertArrayHasKey($symbol, $findings, "Výjimka $symbol už nic nekryje.");
        }
    }

    public function testDetectsSqlAndCallsAcrossMethodsButIgnoresComments(): void
    {
        $source = <<<'SOURCE'
            <?php
            namespace Test;
            use MyInvoice\Repository\DocumentRepository as Documents;
            use MyInvoice\Service\Accounting\Bulk\BulkTransaction as Tx;

            class CommentOnly {
                public function debug(): void {
                    // $pdo->exec('SAVEPOINT trial');
                    $pdo->exec('DELETE FROM documents');
                }
            }

            class CommentWrite {
                public function debug(): void {
                    $pdo->exec('SAVEPOINT trial');
                    /* $pdo->exec('UPDATE documents SET title = ?'); */
                }
            }

            class Example {
                public function preview(\PDO $pdo): void {
                    $this->start($pdo);
                    $this->replace($pdo);
                }

                private function start(\PDO $pdo): void {
                    $name = 'trial';
                    $pdo->exec("SAVEPOINT {$name}");
                }

                private function replace(\PDO $pdo): void {
                    $pdo->exec('DELETE i FROM `invoice_items` i WHERE invoice_id = 1');
                }
            }

            class ViaRepository {
                public function __construct(private Documents $docs) {}
                public function preview(\PDO $pdo): void {
                    Tx::run($pdo, fn () => $this->docs->setText(1, null, 'failed'), true);
                }
                public function read(\PDO $pdo): void {
                    Tx::run($pdo, fn () => $this->docs->findRaw(1, 2, $viewer), true);
                }
            }
            SOURCE;
        self::assertSame(['Test\Example::preview', 'Test\Example::start', 'Test\ViaRepository::preview'],
            array_keys(self::findings($source, 'fixture.php')));
    }

    /** @return array<string,string> */
    private static function sourceFindings(): array
    {
        static $findings = null;
        if ($findings !== null) {
            return $findings;
        }
        $findings = [];
        foreach (SourceCorpus::sources(dirname(__DIR__, 2) . '/src') as $file => $source) {
            if (preg_match('/savepoint|rollback\s+to|BulkTransaction|BulkRecordCall|DimensionPeriodGuard|beginUnit/i', $source)) {
                $findings += self::findings($source, $file);
            }
        }
        return $findings;
    }

    /** @return array<string,string> */
    private static function findings(string $source, string $file): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $nodes = (new NodeTraverser(new NameResolver()))->traverse($parser->parse($source));
        $finder = new NodeFinder();
        $out = [];
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $class) {
            if (!isset($class->namespacedName)) {
                continue;
            }
            $className = $class->namespacedName->toString();
            $properties = [];
            foreach ($class->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    $properties[$prop->name->toString()] = self::type($property->type);
                }
            }
            foreach ($class->getMethod('__construct')?->params ?? [] as $param) {
                if ($param->isPromoted()) {
                    $properties[$param->var->name] = self::type($param->type);
                }
            }

            $methods = [];
            $classSqlWrite = false;
            foreach ($class->getMethods() as $method) {
                $name = $method->name->toString();
                $locals = ['this' => $className];
                foreach ($method->params as $param) {
                    $locals[$param->var->name] = self::type($param->type);
                }
                foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\Assign::class) as $assignment) {
                    if ($assignment->var instanceof Node\Expr\Variable && is_string($assignment->var->name)) {
                        $type = self::receiverType($assignment->expr, $className, $properties, $locals);
                        if ($type !== '') {
                            $locals[$assignment->var->name] = $type;
                        }
                    }
                }
                $calls = [];
                $literals = [];
                foreach ($finder->find($method->stmts ?? [], static fn (Node $node): bool => $node instanceof Node\Expr\CallLike
                    || $node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\InterpolatedString
                    || $node instanceof Node\Expr\BinaryOp\Concat) as $node) {
                    if ($node instanceof Node\Expr\CallLike) {
                        $call = self::call($node, $className, $properties, $locals);
                        if ($call !== null) {
                            $calls[] = $call;
                        }
                    } else {
                        $literals[] = self::literal($node);
                    }
                }
                $hasSavepoint = array_any($literals, static fn (string $sql): bool =>
                    preg_match('/^\s*(?:ROLLBACK\s+TO(?:\s+SAVEPOINT)?|RELEASE\s+SAVEPOINT|SAVEPOINT)\s+/i', $sql) === 1);
                $helper = array_any($calls, static fn (array $call): bool =>
                    in_array($call[0] . '::' . $call[1], self::SAVEPOINT_HELPERS, true)
                    || in_array(strtolower($call[1]), ['savepoint', 'rollbacktosavepoint', 'beginunit'], true));
                $classSqlWrite = $classSqlWrite || array_any($literals, static fn (string $sql): bool => preg_match(self::FTS_SQL, $sql) === 1);
                $writes = array_any($calls, static fn (array $call): bool =>
                    in_array($call[1], self::WRITERS[$call[0]] ?? [], true)
                    || (in_array($call[0], self::REPOSITORIES, true)
                        && preg_match('/^(?:insert|replace|update|delete|hardDelete|softDelete|save|set|reconcile|restore|move|add|remove|purge)/i', $call[1])));
                $localCalls = array_column(array_filter($calls, static fn (array $call): bool => $call[0] === $className), 1);
                $methods[$name] = [$hasSavepoint, $helper, $writes, $localCalls, $method->getStartLine()];
            }

            foreach ($methods as $name => [$sqlSavepoint, $helper, , $calls, $line]) {
                $opensScope = $sqlSavepoint || $helper || array_any($calls, static fn (string $call): bool =>
                    ($methods[$call][0] ?? false) || ($methods[$call][1] ?? false));
                if ($opensScope && ($classSqlWrite || self::reachesWrite($methods, $name))) {
                    $out["$className::$name"] = "$file:$line";
                }
            }
        }
        return $out;
    }

    private static function reachesWrite(array $methods, string $name, array $visited = []): bool
    {
        if (isset($visited[$name]) || !isset($methods[$name])) {
            return false;
        }
        $visited[$name] = true;
        return $methods[$name][2] || array_any($methods[$name][3], static fn (string $call): bool =>
            self::reachesWrite($methods, $call, $visited));
    }

    private static function type(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }
        return $type instanceof Node\Name ? $type->toString() : null;
    }

    private static function literal(Node $node): string
    {
        return match (true) {
            $node instanceof Node\Scalar\String_, $node instanceof Node\InterpolatedStringPart => $node->value,
            $node instanceof Node\Scalar\InterpolatedString => implode('', array_map(self::literal(...), $node->parts)),
            $node instanceof Node\Expr\BinaryOp\Concat => self::literal($node->left) . self::literal($node->right),
            default => "\0",
        };
    }

    /** @return array{string,string}|null */
    private static function call(Node\Expr\CallLike $call, string $class, array $properties, array $locals): ?array
    {
        if ($call instanceof Node\Expr\StaticCall) {
            $receiver = $call->class;
        } elseif ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) {
            $receiver = $call->var;
        } elseif ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Expr) {
            $receiver = $call->name;
            return [self::receiverType($receiver, $class, $properties, $locals), '__invoke'];
        } else {
            return null;
        }
        return $call->name instanceof Node\Identifier
            ? [self::receiverType($receiver, $class, $properties, $locals), $call->name->toString()]
            : null;
    }

    private static function receiverType(Node $receiver, string $class, array $properties, array $locals): string
    {
        if ($receiver instanceof Node\Name) {
            return in_array($receiver->toLowerString(), ['self', 'static'], true) ? $class : $receiver->toString();
        }
        if ($receiver instanceof Node\Expr\Variable && is_string($receiver->name)) {
            return $locals[$receiver->name] ?? '';
        }
        if ($receiver instanceof Node\Expr\PropertyFetch && $receiver->name instanceof Node\Identifier
            && $receiver->var instanceof Node\Expr\Variable && $receiver->var->name === 'this') {
            return $properties[$receiver->name->toString()] ?? '';
        }
        if ($receiver instanceof Node\Expr\New_) {
            return self::type($receiver->class) ?? '';
        }
        return '';
    }
}
