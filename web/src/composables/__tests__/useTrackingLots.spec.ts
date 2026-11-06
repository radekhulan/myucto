import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ listTrackingUnits: vi.fn(), updateTrackingUnit: vi.fn() }))
vi.mock('@/api/stock', () => ({ stockApi: api }))

import { useTrackingLots } from '../useTrackingLots'

const page = (data: Array<{ id: number; lot_code: string; expires_on: string | null }>, total = data.length) =>
  ({ data, meta: { total, page: 1, per_page: 50, pages: 1 } })

const units = [
  { id: 3, lot_code: 'L-A', expires_on: null },
  { id: 7, lot_code: 'L-B', expires_on: '2027-01-31' },
]

describe('useTrackingLots', () => {
  beforeEach(() => vi.clearAllMocks())

  it('lists lots from the light paged endpoint, not from the movement history', async () => {
    api.listTrackingUnits.mockResolvedValue(page(units))
    const lots = useTrackingLots(() => 5)
    await lots.load()
    expect(api.listTrackingUnits).toHaveBeenCalledWith(5, { q: '', page: 1, per_page: 50 })
    expect(lots.rows.value.map(r => [r.id, r.lot_code, r.expires_on])).toEqual([[3, 'L-A', ''], [7, 'L-B', '2027-01-31']])
    expect(lots.loaded.value).toBe(true)
    expect(lots.hasMore.value).toBe(false)
    expect(lots.dirty.value).toBe(false)
  })

  it('saves only the changed lot to the right item and unit, then is clean', async () => {
    api.listTrackingUnits.mockResolvedValue(page(units))
    api.updateTrackingUnit.mockResolvedValue({ unit: { id: 7, lot_code: 'L-NEW', expires_on: '2027-03-01' }, updated: {} })
    const lots = useTrackingLots(() => 5)
    await lots.load()
    lots.rows.value[1]!.lot_code = 'L-NEW'
    lots.rows.value[1]!.expires_on = '2027-03-01'
    expect(lots.dirty.value).toBe(true)

    expect(await lots.save()).toBeNull()
    expect(api.updateTrackingUnit).toHaveBeenCalledTimes(1)
    expect(api.updateTrackingUnit).toHaveBeenCalledWith(5, 7, { lot_code: 'L-NEW', expires_on: '2027-03-01' })
    expect(lots.dirty.value).toBe(false)
  })

  it('keeps a rejected lot changed with the server message and still saves the others', async () => {
    api.listTrackingUnits.mockResolvedValue(page(units))
    api.updateTrackingUnit
      .mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'tracking_unit_exists', message: 'Šarže se stejným kódem a datem spotřeby už na kartě existuje.' } } } })
      .mockResolvedValueOnce({ unit: { id: 7, lot_code: 'L-X', expires_on: '2027-01-31' }, updated: {} })
    const lots = useTrackingLots(() => 5)
    await lots.load()
    lots.rows.value[0]!.lot_code = 'L-B'
    lots.rows.value[1]!.lot_code = 'L-X'

    expect(await lots.save()).toContain('už na kartě existuje')
    expect(lots.rows.value[0]!.error).toContain('už na kartě existuje')
    expect(lots.rows.value[1]!.error).toBe('')
    expect(lots.dirty.value).toBe(true)
  })

  it('sends null when the expiry date is cleared and rejects an empty code', async () => {
    api.listTrackingUnits.mockResolvedValue(page(units))
    api.updateTrackingUnit.mockResolvedValue({ unit: { id: 7, lot_code: 'L-B', expires_on: null }, updated: {} })
    const lots = useTrackingLots(() => 5)
    await lots.load()
    lots.rows.value[1]!.expires_on = null
    await lots.save()
    expect(api.updateTrackingUnit).toHaveBeenCalledWith(5, 7, { lot_code: 'L-B', expires_on: null })
    lots.rows.value[0]!.lot_code = '  '
    expect(lots.validate()).toBe(false)
  })

  it('loads further pages and keeps an edited lot across a new search', async () => {
    api.listTrackingUnits
      .mockResolvedValueOnce(page([units[0]!], 2))
      .mockResolvedValueOnce(page([units[1]!], 2))
      .mockResolvedValueOnce(page([{ id: 9, lot_code: 'Q-1', expires_on: null }]))
    api.updateTrackingUnit.mockResolvedValue({ unit: { id: 3, lot_code: 'L-EDIT', expires_on: null }, updated: {} })
    const lots = useTrackingLots(() => 5)
    await lots.load()
    expect(lots.hasMore.value).toBe(true)
    lots.rows.value[0]!.lot_code = 'L-EDIT'

    await lots.loadMore()
    expect(api.listTrackingUnits).toHaveBeenLastCalledWith(5, { q: '', page: 2, per_page: 50 })
    expect(lots.rows.value.map(r => r.id)).toEqual([3, 7])
    expect(lots.hasMore.value).toBe(false)

    await lots.load('Q')
    expect(api.listTrackingUnits).toHaveBeenLastCalledWith(5, { q: 'Q', page: 1, per_page: 50 })
    expect(lots.rows.value.map(r => r.id)).toEqual([9])
    expect(lots.dirty.value).toBe(true)
    expect(await lots.save()).toBeNull()
    expect(api.updateTrackingUnit).toHaveBeenCalledWith(5, 3, { lot_code: 'L-EDIT', expires_on: null })
    expect(lots.dirty.value).toBe(false)
  })

  it('reports a failed load instead of silently pretending there are no lots', async () => {
    api.listTrackingUnits.mockRejectedValue({ response: { status: 500, data: { error: { code: 'server', message: 'Chyba serveru' } } } })
    const lots = useTrackingLots(() => 5)
    await lots.load()
    expect(lots.loaded.value).toBe(false)
    expect(lots.loadError.value).not.toBe('')
  })
})
