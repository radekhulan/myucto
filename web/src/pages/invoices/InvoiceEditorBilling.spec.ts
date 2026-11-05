import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { newBillingSnapshot, workReportBillingApi, type WorkReportBillingPreview, type WorkReportBillingSnapshot } from '@/api/workReportBilling'
import { invoicesApi, type InvoiceItem, type InvoicePayload } from '@/api/invoices'

vi.mock('@/api/codebooks', () => ({ codebooksApi: { vatRates: () => new Promise(() => {}), currencies: vi.fn(), units: vi.fn() } }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))
import InvoiceEditor from './InvoiceEditor.vue'

async function mountEditor(path = '/invoices/new') {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices/:id', component: InvoiceEditor }] })
  await router.push(path)
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: { plugins: [router, createPinia(), createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })], directives: { math: {} } },
  })
  return { wrapper, vm: wrapper.vm as unknown as { form: { items: InvoiceItem[]; project_id: number | null }; projects: { id: number; hourly_rate: number }[]; wrOpen: boolean; wrBillingSnapshot: WorkReportBillingSnapshot | null; wrBillingStale: boolean; wrBillingReportId: number | null; wrBillingCurrentPreview: WorkReportBillingPreview | null; submit: () => Promise<void>; blankItem: () => InvoiceItem; error: string; hasNonPositiveAmountToPay: boolean; wrBillingPending: boolean; queueWrBilling: (preview: WorkReportBillingPreview) => void } }
}

describe('InvoiceEditor účtování výkazu', () => {
  it('kladný kanonický přenos umožní uložit nový koncept před vznikem fakturačních řádků', async () => {
    const { vm, wrapper } = await mountEditor()
    vm.form.items = []
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    vm.queueWrBilling({ total_amount: 5000 } as WorkReportBillingPreview)
    expect(vm.hasNonPositiveAmountToPay).toBe(false)
    expect(vm.form.items).toEqual([])
    vm.wrBillingPending = false
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    wrapper.unmount()
  })
  it('nulový přenos neobchází kontrolu kladné částky', async () => {
    const { vm, wrapper } = await mountEditor()
    vm.form.items = []
    vm.queueWrBilling({ total_amount: 0 } as WorkReportBillingPreview)
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    wrapper.unmount()
  })
})


describe('InvoiceEditor uložení po přenosu výkazu', () => {
  it.each([
    ['/invoices/42', false],
    ['/invoices/42', true],
    ['/invoices/new', true],
    ['/invoices/42', 'text'],
    ['/invoices/42', 'empty'],
  ] as const)('neposílá automatický prázdný řádek při uložení %s (ruční položka %s)', async (path, withManualRow) => {
    const { vm, wrapper } = await mountEditor(path)
    vm.form.project_id = 1
    vm.projects = [{ id: 1, hourly_rate: 1500 }]
    const manual: InvoiceItem = { id: 10, description: 'Ruční služba', quantity: 2, unit: 'ks', unit_price_without_vat: 250, vat_rate_id: 1, order_index: 0 }
    if (withManualRow === 'text') Object.assign(manual, { item_kind: 'text', quantity: 0, unit_price_without_vat: 0 })
    vm.form.items = withManualRow === 'empty' ? [] : withManualRow ? [manual, vm.blankItem()] : [vm.blankItem()]
    if (withManualRow !== 'empty') expect(vm.form.items.at(-1)?.unit_price_without_vat).toBe(1500)
    vm.wrOpen = true
    vm.wrBillingSnapshot = { ...newBillingSnapshot(), name: 'Servis', fixed_fee: 5000 }
    vm.queueWrBilling({ total_amount: 5000 } as WorkReportBillingPreview)
    const saveInvoice = vi.fn(async (...args: unknown[]) => {
      const payload = args[path.endsWith('new') ? 0 : 1] as InvoicePayload
      if (payload.items.some(item => !item.description.trim())) throw new Error('Validace selhala: Popis je povinný')
      return { id: 42, project_id: 1, items: payload.items, _warnings: [] } as any
    })
    const create = vi.spyOn(invoicesApi, 'create').mockImplementation(saveInvoice)
    const update = vi.spyOn(invoicesApi, 'update').mockImplementation(saveInvoice)
    const saveReport = vi.spyOn(invoicesApi, 'saveWorkReport').mockResolvedValue({} as any)
    const preview = vi.spyOn(workReportBillingApi, 'preview').mockResolvedValue({ fingerprint: 'aktuální' } as any)
    const transfer = vi.spyOn(workReportBillingApi, 'transfer').mockResolvedValue({} as any)
    try {
      await vm.submit()
      expect(vm.error).toBe('')
      expect(saveInvoice).toHaveBeenCalledTimes(1)
      const payload = saveInvoice.mock.calls[0]![path.endsWith('new') ? 0 : 1] as InvoicePayload
      expect(payload.items).toEqual(withManualRow === 'empty' || !withManualRow ? [] : withManualRow === 'text' ? [expect.objectContaining({ item_kind: 'text', description: manual.description })] : [expect.objectContaining({ description: manual.description, quantity: manual.quantity, unit_price_without_vat: manual.unit_price_without_vat })])
      expect(transfer).toHaveBeenCalledWith(42, 'aktuální', false, undefined)
    } finally {
      [create, update, saveReport, preview, transfer].forEach(mock => mock.mockRestore())
      wrapper.unmount()
    }
  })
})


describe('InvoiceEditor ochrana ručně upravených položek při přenosu', () => {
  it.each(['price', 'quantity', 'persisted', 'stock', 'without-transfer'] as const)('zachová řádek %s a nechá server vyžadovat popis', async change => {
    const { vm, wrapper } = await mountEditor()
    vm.form.project_id = 1
    vm.projects = [{ id: 1, hourly_rate: 1500 }]
    const item = vm.blankItem()
    if (change === 'price') item.unit_price_without_vat = 1800
    if (change === 'quantity') item.quantity = 2
    if (change === 'persisted') item.id = 99
    if (change === 'stock') item.stock_item_id = 5
    vm.form.items = [item]
    if (change !== 'without-transfer') vm.queueWrBilling({ total_amount: 5000 } as WorkReportBillingPreview)
    const create = vi.spyOn(invoicesApi, 'create').mockRejectedValue(new Error('Validace selhala: Popis je povinný'))
    try {
      await vm.submit()
      expect(create).toHaveBeenCalledWith(expect.objectContaining({ items: [expect.objectContaining({ description: '', quantity: item.quantity, unit_price_without_vat: item.unit_price_without_vat, stock_item_id: item.stock_item_id ?? null })] }))
      expect(vm.error).toBe('Validace selhala: Popis je povinný')
      expect(vm.form.items).toHaveLength(1)
    } finally { create.mockRestore(); wrapper.unmount() }
  })
})


describe('InvoiceEditor potvrzení uložení bez přenosu', () => {
  it.each([
    { state: 'new', accept: false, prompt: true },
    { state: 'stale', accept: false, prompt: true },
    { state: 'stale', accept: true, prompt: true },
    { state: 'current', accept: true, prompt: false },
    { state: 'pending', accept: true, prompt: false },
    { state: 'edited', accept: false, prompt: true },
  ])('$state (pokračovat $accept)', async ({ state, accept, prompt }) => {
    const { vm, wrapper } = await mountEditor('/invoices/42')
    vm.wrOpen = true
    vm.wrBillingSnapshot = { ...newBillingSnapshot(), name: 'Paušál', fixed_fee: 5000, included_hours: 5 }
    vm.wrBillingStale = state === 'stale'
    const row = { id: 10, description: 'Paušál', quantity: 1, unit: 'ks', unit_price_without_vat: 5000, vat_rate_id: 1, billing_key: 'fixed', work_report_billing_key: 'fixed', linked_work_report_id: 7, total_amount: 5000, order_index: 0 }
    vm.form.items = [{ ...row }]
    if (state !== 'new') {
      vm.wrBillingReportId = 7
      vm.wrBillingCurrentPreview = { items: [row], total_hours: 0, included_hours: 0, extra_hours: 0, total_amount: 5000, fingerprint: 'current' }
    }
    if (state === 'pending') vm.queueWrBilling({ total_amount: 5000 } as WorkReportBillingPreview)
    if (state === 'edited') vm.form.items[0]!.description = 'Upravený popis'
    const update = vi.spyOn(invoicesApi, 'update').mockResolvedValue({ id: 42, project_id: 1, items: vm.form.items } as any)
    const saveReport = vi.spyOn(invoicesApi, 'saveWorkReport').mockResolvedValue({} as any)
    const preview = vi.spyOn(workReportBillingApi, 'preview').mockResolvedValue({ fingerprint: 'current' } as any)
    const transfer = vi.spyOn(workReportBillingApi, 'transfer').mockResolvedValue({} as any)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(accept)
    try {
      await vm.submit()
      expect(confirm).toHaveBeenCalledTimes(prompt ? 1 : 0)
      if (prompt) expect(confirm).toHaveBeenCalledWith('work_report_billing.save_unsynced_confirm')
      expect(update).toHaveBeenCalledTimes(!prompt || accept ? 1 : 0)
      expect(saveReport).toHaveBeenCalledTimes(!prompt || accept ? 1 : 0)
      expect(transfer).toHaveBeenCalledTimes(state === 'pending' ? 1 : 0)
    } finally { [update, saveReport, preview, transfer, confirm].forEach(mock => mock.mockRestore()); wrapper.unmount() }
  })
})
