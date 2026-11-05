import { nextTick } from 'vue'
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
  return { wrapper, vm: wrapper.vm as unknown as { form: { items: InvoiceItem[]; project_id: number | null }; projects: { id: number; hourly_rate: number }[]; wrItems: import('@/api/invoices').WorkReportItem[]; wrOpen: boolean; wrBillingSnapshot: WorkReportBillingSnapshot | null; wrBillingStale: boolean; wrBillingReportId: number | null; wrBillingCurrentPreview: WorkReportBillingPreview | null; wrBillingLegacyItemId: number | null; editedStatus: string; loaded: boolean; submit: () => Promise<void>; blankItem: () => InvoiceItem; error: string; hasNonPositiveAmountToPay: boolean; wrBillingPending: boolean; queueWrBilling: (preview: WorkReportBillingPreview) => void } }
}

describe('InvoiceEditor prázdný koncept', () => {
  it.each(['/invoices/new', '/invoices/42'])('uloží prázdný koncept %s i bez připraveného přenosu', async path => {
    const { vm, wrapper } = await mountEditor(path)
    vm.form.items = []
    const saved = { id: 42, project_id: null, items: [] } as any
    const create = vi.spyOn(invoicesApi, 'create').mockResolvedValue(saved)
    const update = vi.spyOn(invoicesApi, 'update').mockResolvedValue(saved)
    try {
      expect(vm.hasNonPositiveAmountToPay).toBe(false)
      await vm.submit()
      expect(vm.error).toBe('')
      if (path.endsWith('new')) expect(create).toHaveBeenCalledWith(expect.objectContaining({ items: [] }))
      else expect(update).toHaveBeenCalledWith(42, expect.objectContaining({ items: [] }), false)
    } finally { create.mockRestore(); update.mockRestore(); wrapper.unmount() }
  })
  it('prázdný vystavený doklad dál vyžaduje kladnou částku i při připraveném přenosu', async () => {
    const { vm, wrapper } = await mountEditor('/invoices/42')
    vm.form.items = []
    vm.editedStatus = 'sent'
    vm.queueWrBilling({ total_amount: 5000 } as WorkReportBillingPreview)
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    wrapper.unmount()
  })
  it('nulová běžná položka se nestává prázdným konceptem', async () => {
    const { vm, wrapper } = await mountEditor('/invoices/42')
    vm.form.items = [{ description: 'Služba', quantity: 1, unit: 'ks', unit_price_without_vat: 0, vat_rate_id: 1, order_index: 0 }]
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
  it('po potvrzení uloží zastaralý výkaz k prázdnému konceptu bez přenosu a bez chyby kladné částky', async () => {
    const { vm, wrapper } = await mountEditor('/invoices/42')
    vm.editedStatus = 'draft'
    vm.form.items = []
    vm.wrOpen = true
    vm.wrBillingSnapshot = { ...newBillingSnapshot(), name: 'Paušál', fixed_fee: 5000, included_hours: 5 }
    vm.wrBillingReportId = 7
    vm.wrBillingStale = true
    vm.wrBillingPending = false
    const update = vi.spyOn(invoicesApi, 'update').mockResolvedValue({ id: 42, project_id: null, items: [] } as any)
    const saveReport = vi.spyOn(invoicesApi, 'saveWorkReport').mockResolvedValue({} as any)
    const preview = vi.spyOn(workReportBillingApi, 'preview').mockResolvedValue({} as any)
    const transfer = vi.spyOn(workReportBillingApi, 'transfer').mockResolvedValue({} as any)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    try {
      await vm.submit()
      expect(confirm).toHaveBeenCalledWith('work_report_billing.save_unsynced_confirm')
      expect(update).toHaveBeenCalledWith(42, expect.objectContaining({ items: [] }), false)
      expect(saveReport).toHaveBeenCalledWith(42, expect.objectContaining({ items: [], billing_snapshot: vm.wrBillingSnapshot }), false)
      expect(preview).not.toHaveBeenCalled()
      expect(transfer).not.toHaveBeenCalled()
      expect(vm.error).toBe('')
    } finally { [update, saveReport, preview, transfer, confirm].forEach(mock => mock.mockRestore()); wrapper.unmount() }
  })

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


describe('InvoiceEditor přechod z původního výkazu na pásma', () => {
  it('po smazání původního řádku uloží oba připravené řádky přes server bez zaniklého legacy ID', async () => {
    const { vm, wrapper } = await mountEditor('/invoices/42')
    const oldRow: InvoiceItem = { id: 99, description: 'Původní výkaz', quantity: 1, unit: 'ks', unit_price_without_vat: 1000, vat_rate_id: 1, order_index: 0 }
    let storedItems: InvoiceItem[] = [oldRow]
    vm.form.items = [oldRow]
    vm.wrBillingLegacyItemId = oldRow.id!
    vm.form.items = []
    vm.wrOpen = true
    vm.wrBillingSnapshot = { ...newBillingSnapshot(), name: 'Nové účtování', fixed_fee: 5000, included_hours: 5, fixed_description: 'Paušál', bands: [{ up_to_hours: null, rate: 1500, description: 'Pásmo navíc' }] }
    vm.wrItems = [{ description: 'Práce', hours: 7, duration_minutes: 420, rate: 0, order_index: 0 }]
    const rows = [
      { description: 'Paušál', quantity: 1, unit: 'ks', unit_price_without_vat: 5000, vat_rate_id: 1, billing_key: 'fixed', total_amount: 5000 },
      { description: 'Pásmo navíc', quantity: 2, unit: 'h', unit_price_without_vat: 1500, vat_rate_id: 1, billing_key: 'band:0', total_amount: 3000 },
    ]
    const canonical: WorkReportBillingPreview = { items: rows, total_hours: 7, included_hours: 5, extra_hours: 2, total_amount: 8000, fingerprint: 'aktuální' }
    const update = vi.spyOn(invoicesApi, 'update').mockImplementation(async (_id, payload) => {
      storedItems = payload.items as InvoiceItem[]
      return { id: 42, project_id: null, items: storedItems } as any
    })
    const saveReport = vi.spyOn(invoicesApi, 'saveWorkReport').mockResolvedValue({} as any)
    const preview = vi.spyOn(workReportBillingApi, 'preview').mockResolvedValue(canonical)
    const transfer = vi.spyOn(workReportBillingApi, 'transfer').mockImplementation(async (_id, _fingerprint, _force, legacyId) => {
      expect(legacyId).toBeUndefined()
      storedItems = rows.map((row, index) => ({ ...row, id: 100 + index, linked_work_report_id: 7, work_report_billing_key: row.billing_key, order_index: index }))
      return { work_report: {} as any, items: storedItems, preview: canonical }
    })
    try {
      vm.loaded = true
      vm.queueWrBilling(canonical)
      await nextTick()
      expect(wrapper.find('[data-test="pending-billing-items"]').exists()).toBe(true)
      expect(wrapper.findComponent({ name: 'BillingRecap' }).props('preview').items).toHaveLength(2)
      expect(update).not.toHaveBeenCalled()
      expect(transfer).not.toHaveBeenCalled()
      expect(vm.form.items).toEqual([])
      await vm.submit()
      expect(vm.error).toBe('')
      expect(update).toHaveBeenCalledWith(42, expect.objectContaining({ items: [] }), false)
      expect(saveReport).toHaveBeenCalledTimes(1)
      expect(transfer).toHaveBeenCalledWith(42, 'aktuální', false, undefined)
      expect(storedItems.map(item => item.description)).toEqual(['Paušál', 'Pásmo navíc'])
      expect(storedItems).toHaveLength(2)
    } finally { [update, saveReport, preview, transfer].forEach(mock => mock.mockRestore()); wrapper.unmount() }
  })
})
