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
