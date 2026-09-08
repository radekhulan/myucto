<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;

final class PayrollComponentJmhzTargetCatalog
{
    public const PACKAGE_KEY = JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY;
    public const MANIFEST_SHA256 = JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256;

    private const TARGETS = [
        ['attribute_id' => '10328', 'parent' => null, 'role' => 'catch_all_total'],
        ['attribute_id' => '10329', 'parent' => '10328', 'role' => 'detail'],
        ['attribute_id' => '10330', 'parent' => '10328', 'role' => 'detail'],
        ['attribute_id' => '10331', 'parent' => '10328', 'role' => 'detail'],
        ['attribute_id' => '10332', 'parent' => '10328', 'role' => 'catch_all_total'],
        ['attribute_id' => '10333', 'parent' => '10332', 'role' => 'detail'],
        ['attribute_id' => '10334', 'parent' => '10332', 'role' => 'detail'],
        ['attribute_id' => '10335', 'parent' => '10332', 'role' => 'detail'],
        ['attribute_id' => '10336', 'parent' => '10332', 'role' => 'detail'],
        ['attribute_id' => '10337', 'parent' => null, 'role' => 'catch_all_total'],
        ['attribute_id' => '10338', 'parent' => '10337', 'role' => 'detail'],
        ['attribute_id' => '10339', 'parent' => '10337', 'role' => 'detail'],
        ['attribute_id' => '10340', 'parent' => '10337', 'role' => 'detail'],
        ['attribute_id' => '10341', 'parent' => '10337', 'role' => 'detail'],
        /*
         * Náhrada při dočasné pracovní neschopnosti stojí VEDLE úhrnu
         * zúčtovaných náhrad (10337), ne pod ním.
         *
         * Datový slovník u 10337 žádný součtový vzorec nemá, takže rozhoduje
         * doložené chování: v přijatých hlášeních jiných mzdových systémů je
         * `nahrady.mzdyZuctovane` = 10338 + 10339 + 10340 + 10341 a
         * `nahrady.docasnaNeschopnost` k němu nepřičtená (měsíc s náhradou za
         * nemoc a bez ostatních náhrad má mzdyZuctovane = 0). Odpovídá to
         * i ISPV, kde ukazatel NAHRADY náhradu za nemoc nezahrnuje.
         *
         * Kdyby se 10342 rolovalo do 10337, měsíc s nemocí by úhrn náhrad
         * nadhodnotil o náhradu, kterou hlášení vykazuje samostatně.
         */
        ['attribute_id' => '10342', 'parent' => null, 'role' => 'detail'],
        ['attribute_id' => '10343', 'parent' => null, 'role' => 'detail'],
        /*
         * Příspěvek zaměstnavatele na produkty spoření na stáří a pojištění
         * dlouhodobé péče. 10417 je ÚHRN, ne samostatná položka: podle vlastního
         * názvu atributu sčítá produkty spoření na stáří I pojištění dlouhodobé
         * péče, tedy 10417 = 10418 + 10292 + 10293 + 10294 + 10295 + 10296.
         * Proto je `catch_all_total` — složka bez rozpoznaného produktu se dá
         * zařadit rovnou na něj a detailní uzly se do něj dopočítají samy.
         *
         * Pořadí odpovídá XSD (souhrnDataZec.prijmy.prispevekZamestnavatele).
         */
        ['attribute_id' => '10417', 'parent' => null, 'role' => 'catch_all_total'],
        ['attribute_id' => '10418', 'parent' => '10417', 'role' => 'detail'],
        ['attribute_id' => '10292', 'parent' => '10417', 'role' => 'detail'],
        ['attribute_id' => '10293', 'parent' => '10417', 'role' => 'detail'],
        ['attribute_id' => '10294', 'parent' => '10417', 'role' => 'detail'],
        ['attribute_id' => '10295', 'parent' => '10417', 'role' => 'detail'],
        ['attribute_id' => '10296', 'parent' => '10417', 'role' => 'detail'],
    ];

    /**
     * Cíle, které se vykazují v souhrnných datech zaměstnance, ne po vztazích.
     *
     * Příspěvek zaměstnavatele na produkty spoření na stáří (úhrn 10417 i jeho
     * rozpad) sedí v XSD pod `souhrnDataZec`, tedy JEDNOU ZA OSOBU na primárním
     * pracovněprávním vztahu. Kdyby se počítal po vztazích jako mzda, měl by
     * zaměstnanec se dvěma souběžnými vztahy příspěvek v hlášení dvakrát.
     *
     * Rozsah drží tenhle seznam, ne výčet ifů: přibude-li další souhrnný cíl,
     * dopisuje se na jedno místo vedle jeho řádku v {@see self::TARGETS}.
     *
     * @var list<string>
     */
    private const EMPLOYEE_SUMMARY_TARGETS = [
        '10417', '10418', '10292', '10293', '10294', '10295', '10296',
    ];

    /** @var list<array{attribute_id:string,name:string,xsd_mapping:string,data_type:string,monthly_marker:string,parent_attribute_id:?string,ancestor_attribute_ids:list<string>,aggregation_role:string,aggregation_scope:string}>|null */
    private ?array $targets = null;

    public function __construct(private readonly JmhzSpecPackageCatalog $specCatalog) {}

    /** @return list<array{attribute_id:string,name:string,xsd_mapping:string,data_type:string,monthly_marker:string,parent_attribute_id:?string,ancestor_attribute_ids:list<string>,aggregation_role:string,aggregation_scope:string}> */
    public function targets(): array
    {
        return $this->targets ??= $this->buildTargets();
    }

    /** @return array{attribute_id:string,name:string,xsd_mapping:string,data_type:string,monthly_marker:string,parent_attribute_id:?string,ancestor_attribute_ids:list<string>,aggregation_role:string,aggregation_scope:string} */
    public function requireTarget(string $attributeId): array
    {
        foreach ($this->targets() as $target) {
            if (hash_equals($target['attribute_id'], $attributeId)) {
                return $target;
            }
        }

        throw new \InvalidArgumentException('Cílový atribut JMHZ není pro mzdovou složku podporován.');
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>} */
    public function specManifest(): array
    {
        return $this->specCatalog->load(self::PACKAGE_KEY, self::MANIFEST_SHA256);
    }

    /** @return list<string> */
    public function rollupAttributeIds(string $attributeId): array
    {
        return $this->requireTarget($attributeId)['ancestor_attribute_ids'];
    }

    public function topologyHash(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'targets' => array_map(
                fn (array $definition): array => [
                    ...$definition,
                    'aggregation_scope' => $this->aggregationScope(
                        $definition['attribute_id'],
                    ),
                ],
                self::TARGETS,
            ),
        ]));
    }

    /** @return list<array{attribute_id:string,name:string,xsd_mapping:string,data_type:string,monthly_marker:string,parent_attribute_id:?string,ancestor_attribute_ids:list<string>,aggregation_role:string,aggregation_scope:string}> */
    private function buildTargets(): array
    {
        $manifest = $this->specManifest();
        $rows = $manifest['payload']['dictionary_attributes'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \UnexpectedValueException('Slovník JMHZ neobsahuje atributy.');
        }
        $targets = [];
        foreach (self::TARGETS as $definition) {
            $source = null;
            foreach ($rows as $row) {
                if (is_array($row) && ($row['attribute_id'] ?? null) === $definition['attribute_id']) {
                    $source = $row;
                    break;
                }
            }
            if ($source === null) {
                throw new \UnexpectedValueException(
                    "Slovník JMHZ neobsahuje cílový atribut {$definition['attribute_id']}.",
                );
            }
            $targets[] = $this->target($definition, $source);
        }

        return $targets;
    }

    /**
     * @param array{attribute_id:string,parent:?string,role:string} $definition
     * @param array<string,mixed> $source
     * @return array{attribute_id:string,name:string,xsd_mapping:string,data_type:string,monthly_marker:string,parent_attribute_id:?string,ancestor_attribute_ids:list<string>,aggregation_role:string,aggregation_scope:string}
     */
    private function target(array $definition, array $source): array
    {
        $name = $source['name'] ?? null;
        $mapping = $source['xsd_mapping'] ?? null;
        if (!is_string($name) || $name === '' || !is_string($mapping) || $mapping === ''
            || ($source['data_type'] ?? null) !== 'číslo'
            || ($source['monthly_marker'] ?? null) !== 'x'
        ) {
            throw new \UnexpectedValueException(
                "Cílový atribut JMHZ {$definition['attribute_id']} není úplný.",
            );
        }

        return [
            'attribute_id' => $definition['attribute_id'],
            'name' => $name,
            'xsd_mapping' => $mapping,
            'data_type' => 'číslo',
            'monthly_marker' => 'x',
            'parent_attribute_id' => $definition['parent'],
            'ancestor_attribute_ids' => $this->ancestors($definition['attribute_id']),
            'aggregation_role' => $definition['role'],
            'aggregation_scope' => $this->aggregationScope($definition['attribute_id']),
        ];
    }

    /** @return list<string> */
    private function ancestors(string $attributeId): array
    {
        $ancestors = [];
        $current = $attributeId;
        for ($depth = 0; $depth < count(self::TARGETS); ++$depth) {
            $definition = $this->definition($current);
            if ($definition['parent'] === null) {
                return $ancestors;
            }
            if (in_array($definition['parent'], $ancestors, true)) {
                throw new \LogicException('Topologie cílových atributů JMHZ obsahuje cyklus.');
            }
            $ancestors[] = $definition['parent'];
            $current = $definition['parent'];
        }

        throw new \LogicException('Topologie cílových atributů JMHZ obsahuje cyklus.');
    }

    /** @return array{attribute_id:string,parent:?string,role:string} */
    private function definition(string $attributeId): array
    {
        foreach (self::TARGETS as $definition) {
            if (hash_equals($definition['attribute_id'], $attributeId)) {
                return $definition;
            }
        }

        throw new \LogicException("Topologie JMHZ odkazuje na neznámý atribut {$attributeId}.");
    }

    private function aggregationScope(string $attributeId): string
    {
        return in_array($attributeId, self::EMPLOYEE_SUMMARY_TARGETS, true)
            ? 'employee_summary'
            : 'employment';
    }
}
