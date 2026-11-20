import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

// Vedlejší náklad se při uložení konceptu rozpustí do extra_cost řádků.
// Další uložení nebo zaúčtování ho nesmí přičíst znovu.

const m = vi.hoisted(() => ({
  getDocument: vi.fn(),
  updateDocument: vi.fn(),
  postDocument: vi.fn(),
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}))

vi.mock('@/api/stock', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/stock')>(),
  stockApi: new Proxy({ getDocument: m.getDocument, updateDocument: m.updateDocument, postDocument: m.postDocument } as Record<string, unknown>, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m.toast }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }),
}))

import DocumentEditor from '../DocumentEditor.vue'

afterEach(() => { vi.clearAllMocks() })

describe('DocumentEditor: vedlejší náklady', () => {
  it('po uložení konceptu je zaúčtování nepřičte podruhé', async () => {
    let extraCost = '0'
    const draft = () => ({
      id: 5, status: 'draft', doc_type: 'receipt', warehouse_id: 3, warehouse_to_id: null,
      doc_date: '2026-11-09', description: 'Příjem', partner_name: null,
      lines: [{ stock_item_id: 7, qty: '2', unit_cost: '100', extra_cost: extraCost, sku: 'M1', name: 'Motorka', unit: 'ks', tracking_mode: 'none', tracking_allocations: [] }],
    })
    m.getDocument.mockImplementation(async () => draft())
    m.updateDocument.mockImplementation(async (_id: number, payload: { lines: Array<{ extra_cost?: string }> }) => {
      extraCost = payload.lines[0].extra_cost ?? '0'
      return draft()
    })
    m.postDocument.mockImplementation(async () => ({ ...draft(), status: 'posted' }))

    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/stock/documents/:id', component: { render: () => null } }] })
    await router.push('/stock/documents/5')
    const wrapper = mount({ ...DocumentEditor, render: () => null }, { global: { plugins: [router] } })
    const vm = wrapper.vm as unknown as {
      form: { warehouse_id: number | null }
      landedCosts: Array<{ description: string; amount: string; allocation: 'by_value' | 'by_qty' }>
      saveDraft: () => Promise<void>
      post: () => Promise<void>
    }

    await vi.waitFor(() => expect(vm.form.warehouse_id).toBe(3))
    vm.landedCosts.push({ description: 'Doprava', amount: '1000', allocation: 'by_qty' })
    await vm.saveDraft()
    await flushPromises()
    expect(m.updateDocument.mock.calls[0][1].lines[0].extra_cost).toBe('1000.00')
    expect(vm.landedCosts).toEqual([])

    await vm.post()
    await flushPromises()
    expect(m.updateDocument.mock.calls[1][1].lines[0].extra_cost).toBe('1000.00')
    wrapper.unmount()
  })

  it('u příjemky s uloženými náklady pošle přidaný náklad v seznamu, ne do extra_cost', async () => {
    const stored = { id: 9, description: 'Freight Charge', amount: '122.28', allocation: 'by_value', purchase_invoice_id: 4, purchase_invoice_item_id: 41 }
    const draft = () => ({
      id: 6, status: 'draft', doc_type: 'receipt', origin: 'purchase_invoice', purchase_invoice_id: 4, warehouse_id: 3, warehouse_to_id: null,
      doc_date: '2026-11-04', description: 'Příjem z faktury', partner_name: null, landed_costs: [stored],
      lines: [{ stock_item_id: 7, qty: '1', unit_cost: '1000', extra_cost: '122.28', sku: 'M1', name: 'Motorka', unit: 'ks', tracking_mode: 'none', tracking_allocations: [], purchase_invoice_item_id: 40 }],
    })
    m.getDocument.mockImplementation(async () => draft())
    m.updateDocument.mockImplementation(async () => draft())

    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/stock/documents/:id', component: { render: () => null } }] })
    await router.push('/stock/documents/6')
    const wrapper = mount({ ...DocumentEditor, render: () => null }, { global: { plugins: [router] } })
    const vm = wrapper.vm as unknown as {
      form: { warehouse_id: number | null }
      landedCosts: Array<{ description: string; amount: string; allocation: 'by_value' | 'by_qty' }>
      saveDraft: () => Promise<void>
    }

    await vi.waitFor(() => expect(vm.landedCosts).toHaveLength(1))
    vm.landedCosts.push({ description: 'Doprava', amount: '38000', allocation: 'by_qty' })
    await vm.saveDraft()
    await flushPromises()

    const payload = m.updateDocument.mock.calls[0][1]
    expect(payload.landed_costs).toEqual([
      { description: 'Freight Charge', amount: 122.28, allocation: 'by_value', purchase_invoice_id: 4, purchase_invoice_item_id: 41 },
      { description: 'Doprava', amount: 38000, allocation: 'by_qty' },
    ])
    // Dopravu rozpustí server, klient ji do extra_cost řádku nepřičte.
    expect(payload.lines[0].extra_cost).toBe('122.28')
    wrapper.unmount()
  })

  it('když znovunačtení po uložení selže, další uložení uložené náklady nesmaže', async () => {
    const stored = { id: 9, description: 'Clo', amount: '40.00', allocation: 'by_qty', purchase_invoice_id: null, purchase_invoice_item_id: null }
    const draft = () => ({
      id: 8, status: 'draft', doc_type: 'receipt', warehouse_id: 3, warehouse_to_id: null,
      doc_date: '2026-11-04', description: 'Příjem z faktury', partner_name: null, landed_costs: [stored],
      lines: [{ stock_item_id: 7, qty: '1', unit_cost: '100', extra_cost: '40.00', sku: 'M1', name: 'Motorka', unit: 'ks', tracking_mode: 'none', tracking_allocations: [] }],
    })
    m.getDocument.mockImplementationOnce(async () => draft()).mockRejectedValue(new Error('network'))
    m.updateDocument.mockImplementation(async () => draft())

    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/stock/documents/:id', component: { render: () => null } }] })
    await router.push('/stock/documents/8')
    const wrapper = mount({ ...DocumentEditor, render: () => null }, { global: { plugins: [router] } })
    const vm = wrapper.vm as unknown as {
      landedCosts: Array<{ description: string; amount: string; allocation: 'by_value' | 'by_qty' }>
      saveDraft: () => Promise<void>
    }

    await vi.waitFor(() => expect(vm.landedCosts).toHaveLength(1))
    await vm.saveDraft()
    await flushPromises()
    await vm.saveDraft()
    await flushPromises()

    expect(m.updateDocument.mock.calls[1][1].landed_costs).toEqual([{ description: 'Clo', amount: 40, allocation: 'by_qty' }])
    wrapper.unmount()
  })

  it('příjemka bez uložených nákladů seznam neposílá', async () => {
    const draft = () => ({
      id: 7, status: 'draft', doc_type: 'receipt', warehouse_id: 3, warehouse_to_id: null,
      doc_date: '2026-11-04', description: 'Ruční příjem', partner_name: null, landed_costs: [],
      lines: [{ stock_item_id: 7, qty: '1', unit_cost: '100', extra_cost: '0', sku: 'M1', name: 'Motorka', unit: 'ks', tracking_mode: 'none', tracking_allocations: [] }],
    })
    m.getDocument.mockImplementation(async () => draft())
    m.updateDocument.mockImplementation(async () => draft())

    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/stock/documents/:id', component: { render: () => null } }] })
    await router.push('/stock/documents/7')
    const wrapper = mount({ ...DocumentEditor, render: () => null }, { global: { plugins: [router] } })
    const vm = wrapper.vm as unknown as {
      form: { warehouse_id: number | null }
      landedCosts: Array<{ description: string; amount: string; allocation: 'by_value' | 'by_qty' }>
      saveDraft: () => Promise<void>
    }

    await vi.waitFor(() => expect(vm.form.warehouse_id).toBe(3))
    vm.landedCosts.push({ description: 'Doprava', amount: '50', allocation: 'by_qty' })
    await vm.saveDraft()
    await flushPromises()

    const payload = m.updateDocument.mock.calls[0][1]
    expect(payload.landed_costs).toBeUndefined()
    expect(payload.lines[0].extra_cost).toBe('50.00')
    wrapper.unmount()
  })
})
