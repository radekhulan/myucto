import { beforeEach, describe, expect, it, vi } from 'vitest'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('../client', () => ({ api: { get } }))

import { accountingApi } from '../accounting'

/**
 * `listJournal` skládá query z VÝČTU polí, ne z celého objektu filtrů. Každý nový filtr
 * se proto musí doplnit i sem — jinak ho stránka sice nabízí a chip ukáže, ale na server
 * nikdy nedorazí a seznam vrátí všechno. Přesně tak prošel filtr na stav stornování.
 */
describe('accountingApi.listJournal', () => {
  beforeEach(() => {
    get.mockReset()
    get.mockResolvedValue({ data: { items: [], total: 0, page: 1, per_page: 50 } })
  })

  function params(): Record<string, unknown> {
    return (get.mock.calls[0]?.[1] as { params: Record<string, unknown> }).params
  }

  it('posílá stav stornování na server', async () => {
    await accountingApi.listJournal({ reversal: 'reversed' })
    expect(params()).toMatchObject({ reversal: 'reversed' })
  })

  it('posílá filtr dvojic (jen složené M : N) na server', async () => {
    await accountingApi.listJournal({ pair_status: 'composite_mn' })
    expect(params()).toMatchObject({ pair_status: 'composite_mn' })
  })

  it('bez zvoleného stavu stornování parametr neposílá', async () => {
    await accountingApi.listJournal({ posted: true })
    expect(params()).not.toHaveProperty('reversal')
  })

  it('předává i ostatní filtry seznamu', async () => {
    await accountingApi.listJournal({
      document_no: 'FV-1',
      period_id: 7,
      date_from: '2099-01-01',
      date_to: '2099-12-31',
      source_type: 'bank',
      posted: false,
      reversal: 'none',
      automation: 'auto',
      q: '221',
      account_from: '221',
      account_to: '221999',
      amount_from: 10,
      amount_to: 20,
      integrity: 'amount_mismatch',
      page: 2,
      per_page: 100,
    })
    expect(params()).toEqual({
      document_no: 'FV-1',
      period_id: 7,
      date_from: '2099-01-01',
      date_to: '2099-12-31',
      source_type: 'bank',
      posted: '0',
      reversal: 'none',
      automation: 'auto',
      q: '221',
      account_from: '221',
      account_to: '221999',
      amount_from: 10,
      amount_to: 20,
      integrity: 'amount_mismatch',
      page: 2,
      per_page: 100,
    })
  })
})
