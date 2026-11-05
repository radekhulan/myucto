import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import WorkReportModal from './WorkReportModal.vue'
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
    billingPending: boolean
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
    try {
      await vm.save()
      expect(saveReport).toHaveBeenCalledWith(42, expect.objectContaining({ billing_snapshot: snapshot, items: [] }))
      expect(update).toHaveBeenCalledWith(42, expect.objectContaining({ prices_include_vat: true, items: [] }))
      expect(transfer).toHaveBeenCalledTimes(pending ? 1 : 0)
      if (pending) expect(transfer).toHaveBeenCalledWith(42, 'current', false, undefined)
    } finally { [get, saveReport, materials, update, preview, transfer].forEach(mock => mock.mockRestore()) }
  })
})
