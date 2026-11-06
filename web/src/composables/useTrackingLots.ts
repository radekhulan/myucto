import { computed, ref } from 'vue'
import { stockApi } from '@/api/stock'
import { apiErrorMessage } from '@/api/errors'

export interface TrackingLotRow {
  id: number
  lot_code: string
  expires_on: string | null
  savedLot: string
  savedExpires: string
  error: string
}

const PAGE_SIZE = 50

/**
 * Šarže karty v editoru karty: kód a datum spotřeby se přepisují v poli a ukládají
 * společným Uložit (po uložení karty). Řádky jsou šarže karty včetně těch s nulovým
 * stavem, načítané po stránkách z lehkého seznamu (bez historie pohybů) s hledáním
 * v kódu. Změněná šarže zůstane v paměti i po novém hledání nebo dalším načtení a uloží
 * se společně; ukládá se jen změněná šarže přes PUT .../tracking-units/{unitId}.
 */
export function useTrackingLots(itemId: () => number) {
  /** Všechny dosud načtené šarže (i mimo aktuální hledání), aby se neztratily změny. */
  const known = ref<TrackingLotRow[]>([])
  const visibleIds = ref<number[]>([])
  const total = ref(0)
  const query = ref('')
  const loaded = ref(false)
  const loadError = ref('')
  let page = 1
  let token = 0

  const rows = computed(() => visibleIds.value
    .map(id => known.value.find(row => row.id === id))
    .filter((row): row is TrackingLotRow => row !== undefined))
  const dirty = computed(() => known.value.some(isChanged))
  const hasMore = computed(() => visibleIds.value.length < total.value)

  function isChanged(row: TrackingLotRow): boolean {
    return row.lot_code.trim() !== row.savedLot || (row.expires_on ?? '') !== row.savedExpires
  }

  /** Přidá načtené šarže mezi známé; rozeditovaná šarže si nechá svou hodnotu. */
  function merge(units: Array<{ id: number; lot_code: string; expires_on: string | null }>): number[] {
    return units.map((unit) => {
      const expires = unit.expires_on ? String(unit.expires_on) : ''
      const existing = known.value.find(row => row.id === unit.id)
      if (existing === undefined) {
        known.value.push({ id: unit.id, lot_code: unit.lot_code, expires_on: expires, savedLot: unit.lot_code, savedExpires: expires, error: '' })
      } else if (!isChanged(existing)) {
        Object.assign(existing, { lot_code: unit.lot_code, expires_on: expires, savedLot: unit.lot_code, savedExpires: expires })
      }
      return unit.id
    })
  }

  async function fetchPage(nextPage: number, append: boolean): Promise<void> {
    const mine = ++token
    try {
      const result = await stockApi.listTrackingUnits(itemId(), { q: query.value.trim(), page: nextPage, per_page: PAGE_SIZE })
      if (mine !== token) return
      const ids = merge(result.data)
      visibleIds.value = append ? [...visibleIds.value, ...ids.filter(id => !visibleIds.value.includes(id))] : ids
      total.value = result.meta.total
      page = nextPage
      loaded.value = true
      loadError.value = ''
    } catch (e) {
      if (mine !== token) return
      loadError.value = apiErrorMessage(e)
      if (!append) {
        visibleIds.value = []
        total.value = 0
        loaded.value = false
      }
    }
  }

  /** První stránka šarží, volitelně jen těch s hledaným textem v kódu. */
  async function load(search = ''): Promise<void> {
    query.value = search
    await fetchPage(1, false)
  }

  /** Další stránka aktuálního hledání. */
  async function loadMore(): Promise<void> {
    if (hasMore.value) await fetchPage(page + 1, true)
  }

  /** Kód šarže je povinný; true = všechny změněné řádky jsou v pořádku. */
  function validate(): boolean {
    return !known.value.some(row => isChanged(row) && row.lot_code.trim() === '')
  }

  /**
   * Uloží změněné šarže jednu po druhé. Šarže, kterou server odmítne, zůstane změněná
   * s hláškou u řádku. Vrací první chybovou hlášku, nebo null.
   */
  async function save(): Promise<string | null> {
    let firstError: string | null = null
    for (const row of known.value.filter(isChanged)) {
      row.error = ''
      try {
        const result = await stockApi.updateTrackingUnit(itemId(), row.id, {
          lot_code: row.lot_code.trim(),
          expires_on: row.expires_on ? row.expires_on : null,
        })
        row.lot_code = result.unit.lot_code
        row.expires_on = result.unit.expires_on ?? ''
        row.savedLot = row.lot_code
        row.savedExpires = row.expires_on
      } catch (e) {
        row.error = apiErrorMessage(e)
        firstError ??= row.error
      }
    }
    return firstError
  }

  return { rows, loaded, loadError, query, total, hasMore, dirty, load, loadMore, validate, save }
}
