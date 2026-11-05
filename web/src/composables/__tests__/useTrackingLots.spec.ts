import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ itemTracking: vi.fn(), updateTrackingUnit: vi.fn() }))
vi.mock('@/api/stock', () => ({ stockApi: api }))

import { useTrackingLots } from '../useTrackingLots'

const overview = {
  inventory: [{ stock_tracking_unit_id: 7, tracking_type: 'lot', lot_code: 'L-B', expires_on: '2027-01-31' }],
  history: [
    { stock_tracking_unit_id: 7, tracking_type: 'lot', lot_code: 'L-B', expires_on: '2027-01-31' },
    { stock_tracking_unit_id: 3, tracking_type: 'lot', lot_code: 'L-A', expires_on: null },
    { stock_tracking_unit_id: 9, tracking_type: 'serial', lot_code: null, expires_on: null },
  ],
}

describe('useTrackingLots', () => {
  beforeEach(() => vi.clearAllMocks())

  it('lists lots from stock and history including zero-stock ones, without serials', async () => {
    api.itemTracking.mockResolvedValue(overview)
    const lots = useTrackingLots(() => 5)
    await lots.load()
    expect(lots.rows.value.map(r => [r.id, r.lot_code, r.expires_on])).toEqual([[3, 'L-A', ''], [7, 'L-B', '2027-01-31']])
    expect(lots.dirty.value).toBe(false)
  })

  it('saves only the changed lot to the right item and unit, then is clean', async () => {
    api.itemTracking.mockResolvedValue(overview)
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
    api.itemTracking.mockResolvedValue(overview)
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
    api.itemTracking.mockResolvedValue(overview)
    api.updateTrackingUnit.mockResolvedValue({ unit: { id: 7, lot_code: 'L-B', expires_on: null }, updated: {} })
    const lots = useTrackingLots(() => 5)
    await lots.load()
    lots.rows.value[1]!.expires_on = null
    await lots.save()
    expect(api.updateTrackingUnit).toHaveBeenCalledWith(5, 7, { lot_code: 'L-B', expires_on: null })
    lots.rows.value[0]!.lot_code = '  '
    expect(lots.validate()).toBe(false)
  })
})
