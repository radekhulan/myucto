/**
 * Filtr sestav na víc hodnot dimenzí. V URL i v API jde jako jeden parametr
 * `dimension_value_id` s hodnotami oddělenými čárkou (`12,34`); jediná hodnota má
 * stejný tvar jako dřív, takže staré odkazy platí. Hodnoty různých typů musí platit
 * zároveň, hodnoty stejného typu stačí kterákoli (rozhoduje server).
 */
export const DIMENSION_FILTER_MAX_VALUES = 10

export function parseDimensionValueIds(raw: unknown): number[] {
  const text = Array.isArray(raw) ? raw.join(',') : typeof raw === 'number' ? String(raw) : typeof raw === 'string' ? raw : ''
  const ids: number[] = []
  for (const part of text.split(',')) {
    const id = Number(part.trim())
    if (Number.isSafeInteger(id) && id > 0 && !ids.includes(id)) ids.push(id)
  }
  return ids.slice(0, DIMENSION_FILTER_MAX_VALUES)
}

export function dimensionValueIdsParam(ids: readonly number[]): string | undefined {
  return ids.length > 0 ? ids.join(',') : undefined
}

/** Část `useDimensions()`, ze které se skládá popisek filtru. */
export interface DimensionFilterLabelSource {
  valueById: { value: ReadonlyMap<number, { type_id: number }> }
  typeById: { value: ReadonlyMap<number, { name: string }> }
  valueLabel: (valueId: number) => string
}

/**
 * Popisek a hodnota chipu filtru dimenze (deník, seznamy faktur). Jedna hodnota má
 * jako popisek název svého typu, víc hodnot vypíše „Typ: hodnota" za sebou.
 */
export function dimensionFilterChip(
  dims: DimensionFilterLabelSource,
  ids: readonly number[],
  descendants: boolean,
  t: (key: string) => string,
): { label: string, value: string } {
  const typeName = (id: number) => {
    const value = dims.valueById.value.get(id)
    return value ? dims.typeById.value.get(value.type_id)?.name : undefined
  }
  const first = ids[0]
  return {
    label: (ids.length === 1 && first !== undefined && typeName(first)) || t('dimensions.filter_type'),
    value: (ids.length === 1 && first !== undefined
      ? dims.valueLabel(first)
      : ids.map(id => [typeName(id), dims.valueLabel(id)].filter(Boolean).join(': ')).join(' · '))
      + (descendants ? '' : ` (${t('accounting.journal.filter_dimension_only_value')})`),
  }
}
