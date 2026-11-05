import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import WorkReportModal from './WorkReportModal.vue'
import { projectsApi } from '@/api/projects'
import { codebooksApi } from '@/api/codebooks'
import { invoicesApi } from '@/api/invoices'
import { workReportBillingApi, newBillingSnapshot } from '@/api/workReportBilling'

type InvoiceRow = {
  description: string
  quantity: number
  duration_minutes: number | null
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  order_index: number
  stock_item_id?: number | null
  warehouse_id?: number | null
  small_asset_id?: number | null
  asset_id?: number | null
}

function modalVm() {
  const wrapper = shallowMount(WorkReportModal, {
    props: { modelValue: false, invoiceId: 42 },
    global: {
      plugins: [
        createPinia(),
        createI18n({ legacy: false, locale: 'cs', messages: { cs: {} } }),
      ],
    },
  })
  return wrapper.vm as unknown as {
    wrItems: Array<{ description: string; hours: number; duration_minutes: number | null; rate: number }>
    billingSnapshot: ReturnType<typeof newBillingSnapshot> | null
    projectName: string
    billingPending: boolean
    billingStale: boolean
    billingReportId: number | null
    billingCurrentPreview: import('@/api/workReportBilling').WorkReportBillingPreview | null
    invoiceItems: import('@/api/invoices').InvoiceItem[]
    load: () => Promise<void>
    canSave: boolean
    error: string
    save: () => Promise<void>
    totalAmount: number
    syncRow: (
      items: InvoiceRow[],
      originalTitle: string,
      newTitle: string,
      total: number,
      vatRateId: number | null,
      present: boolean,
      allowEmptyReuse: boolean,
    ) => void
  }
}

describe('WorkReportModal invoice row sync', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('sums rounded minute rows while keeping legacy aggregation unchanged', () => {
    const vm = modalVm()
    vm.wrItems = Array.from({ length: 2 }, () => ({ description: 'Minute', hours: 0.02, duration_minutes: 1, rate: 0.9 }))
    expect(vm.totalAmount).toBe(0.04)
    const rows: InvoiceRow[] = []
    vm.syncRow(rows, '', 'Práce', vm.totalAmount, 1, true, true)
    expect(rows[0]?.unit_price_without_vat).toBe(0.04)
    vm.wrItems = Array.from({ length: 2 }, () => ({ description: 'Legacy', hours: 0.5, duration_minutes: null, rate: 0.03 }))
    expect(vm.totalAmount).toBe(0.03)
  })

  it.each([
    { stock_item_id: 7, warehouse_id: 3 },
    { small_asset_id: 8 },
    { asset_id: 9 },
  ])('nepřepíše ani nesmaže navázaný řádek %o', (link) => {
    const vm = modalVm()
    const linked: InvoiceRow = {
      description: 'Práce',
      quantity: 2,
      duration_minutes: null,
      unit: 'ks',
      unit_price_without_vat: 500,
      vat_rate_id: 1,
      order_index: 0,
      ...link,
    }
    const original = { ...linked }
    const rows = [linked]

    vm.syncRow(rows, 'Práce', 'Práce', 1200, 1, true, true)
    expect(rows).toHaveLength(2)
    expect(rows[0]).toEqual(original)
    expect(rows[1]).toMatchObject({
      description: 'Práce',
      quantity: 1,
      unit: 'ks',
      unit_price_without_vat: 1200,
    })

    vm.syncRow(rows, 'Práce', 'Práce', 0, 1, false, true)
    expect(rows).toEqual([original])
  })
})


describe('WorkReportModal účtování výkazu', () => {
  it.each([false, true])('nulové hodiny drží celý paušál; přenos pouze na výslovný pokyn (%s)', async pending => {
    const vm = modalVm()
    const snapshot = { ...newBillingSnapshot(), name: 'Servis', fixed_fee: 5000, included_hours: 5 }
    vm.billingSnapshot = snapshot
    vm.billingPending = pending
    const invoice = { id: 42, invoice_type: 'invoice', client_id: 1, currency_id: 1, currency: 'CZK', items: [], prices_include_vat: true }
    const get = vi.spyOn(invoicesApi, 'get').mockResolvedValue(invoice as any)
    const saveReport = vi.spyOn(invoicesApi, 'saveWorkReport').mockResolvedValue({} as any)
    const materials = vi.spyOn(invoicesApi, 'saveWorkReportMaterials').mockResolvedValue({} as any)
    const update = vi.spyOn(invoicesApi, 'update').mockResolvedValue(invoice as any)
    const preview = vi.spyOn(workReportBillingApi, 'preview').mockResolvedValue({ fingerprint: 'current' } as any)
    const transfer = vi.spyOn(workReportBillingApi, 'transfer').mockResolvedValue({} as any)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    try {
      await vm.save()
      expect(saveReport).toHaveBeenCalledWith(42, expect.objectContaining({ billing_snapshot: snapshot, items: [] }))
      expect(update).toHaveBeenCalledWith(42, expect.objectContaining({ prices_include_vat: true, items: [] }))
      expect(transfer).toHaveBeenCalledTimes(pending ? 1 : 0)
      if (pending) expect(transfer).toHaveBeenCalledWith(42, 'current', false, undefined)
      expect(confirm).toHaveBeenCalledTimes(pending ? 0 : 1)
    } finally { [get, saveReport, materials, update, preview, transfer, confirm].forEach(mock => mock.mockRestore()) }
  })
})


describe('WorkReportModal potvrzení uložení bez přenosu', () => {
  it.each([
    { state: 'new', accept: false, prompt: true },
    { state: 'stale', accept: false, prompt: true },
    { state: 'stale', accept: true, prompt: true },
    { state: 'current', accept: true, prompt: false },
    { state: 'pending', accept: true, prompt: false },
  ])('$state (pokračovat $accept)', async ({ state, accept, prompt }) => {
    const vm = modalVm()
    vm.billingSnapshot = { ...newBillingSnapshot(), name: 'Paušál', fixed_fee: 5000, included_hours: 5 }
    vm.billingStale = state === 'stale'
    vm.billingPending = state === 'pending'
    const row = { description: 'Paušál', quantity: 1, unit: 'ks', unit_price_without_vat: 5000, vat_rate_id: 1, billing_key: 'fixed', work_report_billing_key: 'fixed', linked_work_report_id: 7, total_amount: 5000, order_index: 0 }
    if (state !== 'new') {
      vm.billingReportId = 7
      vm.billingCurrentPreview = { items: [row], total_hours: 0, included_hours: 0, extra_hours: 0, total_amount: 5000, fingerprint: 'current' }
      vm.invoiceItems = [row]
    }
    const inv = { id: 42, invoice_type: 'invoice', project_id: null, items: [row] }
    const get = vi.spyOn(invoicesApi, 'get').mockResolvedValue(inv as any)
    const saveReport = vi.spyOn(invoicesApi, 'saveWorkReport').mockResolvedValue({} as any)
    const materials = vi.spyOn(invoicesApi, 'saveWorkReportMaterials').mockResolvedValue({} as any)
    const update = vi.spyOn(invoicesApi, 'update').mockResolvedValue(inv as any)
    const preview = vi.spyOn(workReportBillingApi, 'preview').mockResolvedValue({ fingerprint: 'current' } as any)
    const transfer = vi.spyOn(workReportBillingApi, 'transfer').mockResolvedValue({} as any)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(accept)
    try {
      await vm.save()
      expect(confirm).toHaveBeenCalledTimes(prompt ? 1 : 0)
      if (prompt) expect(confirm).toHaveBeenCalledWith('work_report_billing.save_unsynced_confirm')
      const saves = !prompt || accept ? 1 : 0
      expect(get).toHaveBeenCalledTimes(saves)
      expect(saveReport).toHaveBeenCalledTimes(saves)
      expect(materials).toHaveBeenCalledTimes(saves)
      expect(update).toHaveBeenCalledTimes(saves)
      expect(transfer).toHaveBeenCalledTimes(state === 'pending' ? 1 : 0)
    } finally { [get, saveReport, materials, update, preview, transfer, confirm].forEach(mock => mock.mockRestore()) }
  })
})


describe('WorkReportModal neúspěšné načtení existujícího výkazu', () => {
  it('chyba GET se nesmí změnit na prázdný výkaz a povolit přepsání snapshotu', async () => {
    const vm = modalVm()
    vm.billingSnapshot = { ...newBillingSnapshot(), name: 'Uložený paušál', fixed_fee: 5000 }
    const get = vi.spyOn(invoicesApi, 'get').mockResolvedValue({ id: 42, items: [] } as any)
    const reportGet = vi.spyOn(invoicesApi, 'getWorkReport').mockRejectedValue(new Error('Načtení výkazu selhalo'))
    const units = vi.spyOn(codebooksApi, 'units').mockResolvedValue([])
    const vat = vi.spyOn(codebooksApi, 'vatRates').mockResolvedValue([])
    const reportSave = vi.spyOn(invoicesApi, 'saveWorkReport').mockResolvedValue({} as any)
    const update = vi.spyOn(invoicesApi, 'update').mockResolvedValue({} as any)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    try {
      await vm.load()
      expect(vm.error).toBeTruthy()
      vm.wrItems = [{ description: 'Dopsaná práce po chybě', hours: 1, duration_minutes: 60, rate: 1500 }]
      expect(vm.canSave).toBe(false)
      await vm.save()
      expect(reportSave).not.toHaveBeenCalled()
      expect(update).not.toHaveBeenCalled()
    } finally { [get, reportGet, units, vat, reportSave, update, confirm].forEach(mock => mock.mockRestore()) }
  })
})


describe('WorkReportModal výchozí účtování bez práva číst zakázky', () => {
  it.each(['inherit', 'legacy', 'snapshot', 'currency'] as const)('použije kontext faktury a zachová přednost režimu %s', async mode => {
    const vm = modalVm()
    const template = { ...newBillingSnapshot(), id: 9, name: 'Výchozí účtování', fixed_fee: 5000 }
    const custom = { ...newBillingSnapshot(), name: 'Uložený snapshot', fixed_fee: 7000 }
    const stored = mode === 'legacy' || mode === 'snapshot' ? { id: 7, title: 'Výkaz', items: [], materials: [], billing_snapshot: mode === 'snapshot' ? custom : null } : null
    const get = vi.spyOn(invoicesApi, 'get').mockResolvedValue({ id: 42, project_id: 17, project_name: 'Syntetická zakázka', project_billing_template_id: 9, currency: mode === 'currency' ? 'EUR' : 'CZK', items: [], issue_date: '2096-03-10' } as any)
    const report = vi.spyOn(invoicesApi, 'getWorkReport').mockResolvedValue(stored as any)
    const list = vi.spyOn(workReportBillingApi, 'list').mockResolvedValue([template])
    const projectGet = vi.spyOn(projectsApi, 'get').mockRejectedValue(new Error('projects.read zakázáno'))
    const projectList = vi.spyOn(projectsApi, 'listForClient').mockRejectedValue(new Error('projects.read zakázáno'))
    const units = vi.spyOn(codebooksApi, 'units').mockResolvedValue([])
    const vat = vi.spyOn(codebooksApi, 'vatRates').mockResolvedValue([])
    try {
      await vm.load()
      expect(vm.error).toBe('')
      expect(vm.projectName).toBe('Syntetická zakázka')
      expect(projectGet).not.toHaveBeenCalled()
      expect(projectList).not.toHaveBeenCalled()
      if (mode === 'inherit') expect(vm.billingSnapshot).toEqual(template)
      else if (mode === 'snapshot') expect(vm.billingSnapshot).toEqual(custom)
      else expect(vm.billingSnapshot).toBeNull()
    } finally { [get, report, list, projectGet, projectList, units, vat].forEach(mock => mock.mockRestore()) }
  })
})
