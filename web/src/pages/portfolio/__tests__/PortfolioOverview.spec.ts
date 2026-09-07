import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PortfolioCompany } from '@/api/portfolio'

// Regrese: sloupec „K doúčtování" sčítá tři různé entity (FV / PF / bankovní pohyby),
// ale proklik byl natvrdo `/invoices?booked=0`. Na ostrých datech tvořilo číslo 7
// sedm bankovních pohybů → uživatel přistál na prázdném seznamu vydaných faktur.

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  push: vi.fn(),
  setSupplier: vi.fn(),
  currentSupplierId: 2,
}))

vi.mock('@/api/portfolio', () => ({ portfolioApi: { overview: m.overview } }))

vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))

vi.mock('vue-router', () => ({ useRouter: () => ({ push: m.push }) }))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    get currentSupplierId() { return m.currentSupplierId },
    setSupplier: m.setSupplier,
  }),
}))

vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { cycle: 'M0 0' },
  btnOutline: () => 'btn-outline',
}))

import PortfolioOverview from '../PortfolioOverview.vue'

function company(over: Partial<PortfolioCompany> = {}): PortfolioCompany {
  return {
    supplier_id: 2,
    company_name: 'Testovací s.r.o.',
    ic: '12345678',
    is_vat_payer: true,
    accounting_mode: 'double_entry',
    next_deadline: null,
    unbooked_documents: 0,
    unbooked_breakdown: [],
    unmatched_bank_transactions: 0,
    purchase_drafts: 0,
    period_status: null,
    last_bank_import_at: null,
    ...over,
  }
}

async function mountWith(c: PortfolioCompany) {
  m.overview.mockResolvedValue({ companies: [c], total: 1, generated_at: '2026-08-02T10:00:00+02:00' })
  const w = mount(PortfolioOverview)
  await flushPromises()
  return w
}

describe('PortfolioOverview — proklik „K doúčtování"', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.currentSupplierId = 2
  })

  it('vede na banku, když číslo tvoří jen bankovní pohyby', async () => {
    const w = await mountWith(company({
      unbooked_documents: 7,
      unbooked_breakdown: [{ key: 'bank', count: 7, link: '/bank?tab=posting' }],
    }))

    const cell = w.findAll('tbody td')[2]
    expect(cell.text()).toContain('7')
    await cell.find('button').trigger('click')

    expect(m.push).toHaveBeenCalledWith('/bank?tab=posting')
  })

  it('vede na vydané faktury, jen když v čísle opravdu jsou', async () => {
    const w = await mountWith(company({
      unbooked_documents: 3,
      unbooked_breakdown: [{ key: 'invoices', count: 3, link: '/invoices?booked=0' }],
    }))

    await w.findAll('tbody td')[2].find('button').trigger('click')

    expect(m.push).toHaveBeenCalledWith('/invoices?booked=0')
  })

  it('míchané typy rozpadne na samostatné prokliky', async () => {
    const w = await mountWith(company({
      unbooked_documents: 9,
      unbooked_breakdown: [
        { key: 'purchase_invoices', count: 2, link: '/purchase-invoices?booked=0' },
        { key: 'bank', count: 7, link: '/bank?tab=posting' },
      ],
    }))

    const buttons = w.findAll('tbody td')[2].findAll('button')
    // 1× celkové číslo + 1 tlačítko na typ
    expect(buttons).toHaveLength(3)

    await buttons[0].trigger('click')
    expect(m.push).toHaveBeenLastCalledWith('/purchase-invoices?booked=0')

    await buttons[2].trigger('click')
    expect(m.push).toHaveBeenLastCalledWith('/bank?tab=posting')
  })

  it('u jediného typu rozpad nezobrazuje', async () => {
    const w = await mountWith(company({
      unbooked_documents: 7,
      unbooked_breakdown: [{ key: 'bank', count: 7, link: '/bank?tab=posting' }],
    }))

    expect(w.findAll('tbody td')[2].findAll('button')).toHaveLength(1)
  })
})
