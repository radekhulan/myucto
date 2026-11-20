import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

// Alt+Q i Ctrl+K hledají stejně: menu po slovech i po písmenech, rychlé akce,
// zaměstnance i klienty a doklady z jiné firmy. Výsledek z jiné firmy se otevře
// až po přepnutí do ní.

const m = vi.hoisted(() => ({ switchTo: vi.fn(), navigate: vi.fn() }))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/search', () => ({
  searchApi: {
    query: vi.fn(async (q: string) => ({
      q,
      clients: [{ id: 3, supplier_id: 2, supplier_name: 'Beta Účetní s.r.o.', company_name: 'Syntetický odběratel', main_email: null, is_customer: true, is_vendor: false }],
      invoices: [{ id: 5, varsymbol: '2026000123', invoice_type: 'invoice', status: 'sent', issue_date: '2026-11-02', total_with_vat: 100, currency: 'CZK', company_name: 'Syntetický odběratel' }],
      purchase_invoices: [{ id: 8, supplier_id: 2, supplier_name: 'Beta Účetní s.r.o.', varsymbol: null, vendor_invoice_number: 'FV-2026-77', document_kind: 'invoice', status: 'received', issue_date: '2026-11-03', total_with_vat: 50, currency: 'CZK', company_name: 'Syntetický dodavatel' }],
      employees: [{ id: 9, supplier_id: 2, supplier_name: 'Beta Účetní s.r.o.', full_name: 'Pavel Syntetický', is_active: true, code: null }],
    })),
  },
}))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ navigate: m.navigate, openExternal: vi.fn() }) }))
vi.mock('@/composables/useTips', () => ({ markTipUsed: vi.fn() }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: vi.fn() }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: 1, hasMultiple: true, availableSuppliers: [] }),
}))
vi.mock('@/composables/useSupplierSwitch', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/composables/useSupplierSwitch')>()),
  useSupplierSwitch: () => ({ switching: { value: false }, switchTo: m.switchTo }),
}))

import GlobalSearch from '../GlobalSearch.vue'
import CommandPalette from '../CommandPalette.vue'

const menuItems = [
  { to: '/invoices', label: 'Vydané faktury', icon: 'M0', section: 'Prodej', accent: 'primary' },
  { to: '/bank', label: 'Bankovní účty', icon: 'M0', section: 'Peníze', accent: 'success' },
]
const quickActions = [{ to: '/invoices/new', label: 'Vydaná faktura', icon: 'M0' }]

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers()
})
afterEach(() => {
  vi.useRealTimers()
  document.body.innerHTML = ''
})

async function field(q: string) {
  const w = mount(GlobalSearch, { props: { menuItems, quickActions } })
  await w.find('input').setValue(q)
  await vi.advanceTimersByTimeAsync(300)
  await flushPromises()
  return { text: () => w.text(), pick: async (label: string) => w.findAll('button').find(b => b.text().includes(label))!.trigger('mousedown') }
}

async function palette(q: string) {
  const w = mount(CommandPalette, { props: { navItems: menuItems, quickActions }, attachTo: document.body })
  await (w.vm as unknown as { show: () => Promise<void> }).show()
  const input = document.body.querySelector('input') as HTMLInputElement
  input.value = q
  input.dispatchEvent(new Event('input'))
  await vi.advanceTimersByTimeAsync(300)
  await flushPromises()
  return {
    text: () => document.body.textContent ?? '',
    pick: async (label: string) => [...document.body.querySelectorAll('button')].find(b => b.textContent?.includes(label))!.click(),
  }
}

describe.each([['Alt+Q', field], ['Ctrl+K', palette]])('%s — sjednocené hledání', (_name, open) => {
  it('najde položku menu po písmenech i rychlou akci', async () => {
    const view = await open('vyfa')
    expect(view.text()).toContain('Vydané faktury')
    expect(view.text()).toContain('Vydaná faktura')
    expect(view.text()).not.toContain('Bankovní účty')
  })

  it('ukáže zaměstnance i klienta a doklady s názvem cizí firmy', async () => {
    const view = await open('syntet')
    for (const group of ['search.group_clients', 'search.group_employees', 'search.group_invoices', 'search.group_purchase']) {
      expect(view.text()).toContain(group)
    }
    expect(view.text()).toContain('Beta Účetní s.r.o.')
  })

  it('klienta a přijatou fakturu z jiné firmy otevře po přepnutí firmy', async () => {
    await (await open('syntet')).pick('Syntetický odběratel')
    expect(m.switchTo).toHaveBeenCalledWith(2, '/clients/3')
    await (await open('syntet')).pick('FV-2026-77')
    expect(m.switchTo).toHaveBeenCalledWith(2, '/purchase-invoices/8')
    expect(m.navigate).not.toHaveBeenCalled()
  })

  it('doklad aktivní firmy otevře bez přepnutí', async () => {
    await (await open('syntet')).pick('2026000123')
    expect(m.navigate).toHaveBeenCalledWith('/invoices/5')
    expect(m.switchTo).not.toHaveBeenCalled()
  })
})
