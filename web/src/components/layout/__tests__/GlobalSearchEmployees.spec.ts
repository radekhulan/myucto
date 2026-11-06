import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

// Alt+Q najde zaměstnance i v jiné firmě uživatele; volba firmu přepne a otevře kartu osoby.

const m = vi.hoisted(() => ({ switchTo: vi.fn(), navigate: vi.fn() }))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params?.code ? `${key}:${params.code}` : key) }) }))
vi.mock('@/api/search', () => ({
  searchApi: {
    query: vi.fn(async (q: string) => ({
      q,
      clients: [],
      invoices: [],
      purchase_invoices: [],
      employees: [
        { id: 7, supplier_id: 1, supplier_name: 'Alfa Stavby s.r.o.', full_name: 'Petr Novák', is_active: true, code: 'Z0007' },
        { id: 9, supplier_id: 2, supplier_name: 'Beta Účetní s.r.o.', full_name: 'Pavel Novák', is_active: false, code: null },
      ],
    })),
  },
}))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ navigate: m.navigate, openExternal: vi.fn() }) }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: 1, hasMultiple: true, availableSuppliers: [] }),
}))
vi.mock('@/composables/useSupplierSwitch', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/composables/useSupplierSwitch')>()),
  useSupplierSwitch: () => ({ switching: { value: false }, switchTo: m.switchTo }),
}))

import GlobalSearch from '../GlobalSearch.vue'

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers()
})
afterEach(() => vi.useRealTimers())

async function search(q: string) {
  const w = mount(GlobalSearch, { props: { menuItems: [] } })
  await w.find('input').setValue(q)
  await vi.advanceTimersByTimeAsync(300)
  await flushPromises()
  return w
}

describe('Alt+Q — zaměstnanci', () => {
  it('ukáže skupinu zaměstnanců a u cizí firmy její název', async () => {
    const w = await search('novák')
    expect(w.text()).toContain('search.group_employees')
    expect(w.text()).toContain('search.employee_code:Z0007')
    expect(w.text()).toContain('Beta Účetní s.r.o.')
    expect(w.text()).not.toContain('Alfa Stavby s.r.o.')
  })

  it('osobu z otevřené firmy otevře bez přepnutí', async () => {
    const w = await search('novák')
    await w.findAll('button').find(b => b.text().includes('Petr Novák'))!.trigger('mousedown')
    expect(m.navigate).toHaveBeenCalledWith('/payroll/people?person=7')
    expect(m.switchTo).not.toHaveBeenCalled()
  })

  it('osobu z jiné firmy otevře až po přepnutí firmy', async () => {
    const w = await search('novák')
    await w.findAll('button').find(b => b.text().includes('Pavel Novák'))!.trigger('mousedown')
    expect(m.switchTo).toHaveBeenCalledWith(2, '/payroll/people?person=9')
    expect(m.navigate).not.toHaveBeenCalled()
  })
})
