import type { BankTransaction } from '@/api/bank'
import type { DimensionMap, DimensionType, DimensionValue } from '@/api/dimensions'

/**
 * Dimenze, které nové pravidlo smí převzít: typ zobrazený na dokladech a otevřená hodnota
 * téhož typu. Uzavřenou nebo skrytou hodnotu by uživatel ve formuláři neviděl a server by
 * ji u nového pravidla odmítl.
 */
export function usableDimensions(
  dims: DimensionMap,
  documentTypes: Pick<DimensionType, 'id'>[],
  valueById: Map<number, Pick<DimensionValue, 'type_id' | 'is_active'>>,
): Record<number, number> {
  const types = new Set(documentTypes.map(ty => ty.id))
  const out: Record<number, number> = {}
  for (const [typeId, valueId] of Object.entries(dims)) {
    if (valueId == null) continue
    const value = valueById.get(valueId)
    if (types.has(Number(typeId)) && value?.is_active && value.type_id === Number(typeId)) out[Number(typeId)] = valueId
  }
  return out
}

/**
 * Dimenze pohybu, které smí předvyplnit nové pravidlo účtování: jen ručně zadané.
 * Hodnoty, které pohybu zapsalo jiné pravidlo (`rule_dimension_types`), ani dimenze
 * zděděné z hrazených dokladů (ty se k pohybu neukládají) nejsou rozhodnutím o tomto
 * druhu pohybu.
 */
export function manualMovementDimensions(tx: Pick<BankTransaction, 'dimensions' | 'rule_dimension_types'>): Record<number, number> {
  const ruleTypes = new Set(tx.rule_dimension_types ?? [])
  const out: Record<number, number> = {}
  for (const [typeId, valueId] of Object.entries(tx.dimensions ?? {})) {
    if (valueId != null && !ruleTypes.has(Number(typeId))) out[Number(typeId)] = valueId
  }
  return out
}
