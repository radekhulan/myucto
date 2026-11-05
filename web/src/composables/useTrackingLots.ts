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

/**
 * Šarže karty v editoru karty: kód a datum spotřeby se přepisují v poli a ukládají
 * společným Uložit (po uložení karty). Řádky jsou šarže ze skladové knihy včetně těch
 * s nulovým stavem; ukládá se jen změněná šarže přes PUT .../tracking-units/{unitId}.
 */
export function useTrackingLots(itemId: () => number) {
  const rows = ref<TrackingLotRow[]>([])
  const loaded = ref(false)

  const dirty = computed(() => loaded.value && rows.value.some(isChanged))

  function isChanged(row: TrackingLotRow): boolean {
    return row.lot_code.trim() !== row.savedLot || (row.expires_on ?? '') !== row.savedExpires
  }

  async function load(): Promise<void> {
    try {
      const overview = await stockApi.itemTracking(itemId())
      const byId = new Map<number, { lot_code: string; expires_on: string }>()
      const sources: Array<Record<string, unknown>> = [...overview.inventory, ...overview.history]
      for (const source of sources) {
        const id = Number(source.stock_tracking_unit_id)
        if (source.tracking_type !== 'lot' || !(id > 0) || byId.has(id)) continue
        byId.set(id, { lot_code: String(source.lot_code ?? ''), expires_on: source.expires_on ? String(source.expires_on) : '' })
      }
      rows.value = [...byId.entries()]
        .sort((a, b) => a[1].lot_code.localeCompare(b[1].lot_code))
        .map(([id, lot]) => ({ id, ...lot, savedLot: lot.lot_code, savedExpires: lot.expires_on, error: '' }))
      loaded.value = true
    } catch {
      rows.value = []
      loaded.value = false
    }
  }

  /** Kód šarže je povinný; true = všechny změněné řádky jsou v pořádku. */
  function validate(): boolean {
    return !rows.value.some(row => isChanged(row) && row.lot_code.trim() === '')
  }

  /**
   * Uloží změněné šarže jednu po druhé. Šarže, kterou server odmítne, zůstane změněná
   * s hláškou u řádku. Vrací první chybovou hlášku, nebo null.
   */
  async function save(): Promise<string | null> {
    let firstError: string | null = null
    for (const row of rows.value.filter(isChanged)) {
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

  return { rows, loaded, dirty, load, validate, save }
}
