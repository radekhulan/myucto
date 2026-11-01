import type { OtherItem, OtherItemKind } from '@/api/otherItems'

/**
 * Popisek druhu ostatní položky: název vlastního druhu, jinak překlad vestavěného podle
 * strany. Mimo `@/api/otherItems`, aby jmenný prostor `other_items` netáhly moduly, které
 * API jen volají (banka, AI asistent).
 */
export function otherItemKindLabel(t: (key: string) => string, item: Pick<OtherItem, 'side' | 'kind' | 'kind_name'>): string {
  return item.kind_name || t(`other_items.kind_by_side.${item.side}.${item.kind}`)
}

/** Popisek položky seznamu druhů (vestavěný i vlastní). */
export function kindSettingLabel(t: (key: string) => string, kind: Pick<OtherItemKind, 'side' | 'builtin_code' | 'name'>): string {
  return kind.name || t(`other_items.kind_by_side.${kind.side}.${kind.builtin_code}`)
}
