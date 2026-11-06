import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PurchaseInvoice } from '@/api/purchaseInvoices'

const m = vi.hoisted(() => ({ change: vi.fn(), success: vi.fn() }))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: { changeVatTreatment: m.change } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: () => 'chyba' }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: vi.fn() }) }))

import PurchaseVatTreatmentModal from '@/components/purchase/PurchaseVatTreatmentModal.vue'

const stubs = { Modal: { name: 'Modal', props: ['title', 'widthClass'], template: '<div><slot /></div>' } }

function invoice(overrides: Partial<PurchaseInvoice> = {}): PurchaseInvoice {
  return {
    id: 41, status: 'booked', currency: 'CZK', vat_deduction: 'full', vat_deduction_percent: 100,
    vat_classification_code: '40',
    items: [{ id: 7, description: 'Služba', vat_rate_snapshot: 21, vat_classification_code: '40' }],
    vat_allocations: [],
    locked: { is_locked: true, reasons: ['posted'], booked_at: null, journal_entry_id: 3, period_status: 'open', date_locked: false, accountant_managed: false },
    ...overrides,
  } as unknown as PurchaseInvoice
}

function filedError() {
  return { response: { data: { error: { code: 'period_filed_confirm_required', filed_period: 'přiznání k DPH za 4/2096', can_confirm: true } } } }
}

describe('PurchaseVatTreatmentModal', () => {
  beforeEach(() => {
    m.change.mockReset()
    m.success.mockReset()
  })

  it('pošle jen změněný nárok a po vyžádání potvrdí podané období', async () => {
    m.change.mockRejectedValueOnce(filedError()).mockResolvedValueOnce({ id: 41 })
    const wrapper = mount(PurchaseVatTreatmentModal, {
      props: { invoice: invoice(), vatClassifications: [], isAdmin: true },
      global: { stubs },
    })
    await wrapper.get('[data-test="vat-treatment-deduction"]').setValue('none')
    await wrapper.get('[data-test="vat-treatment-save"]').trigger('click')
    await flushPromises()

    expect(m.change).toHaveBeenNthCalledWith(1, 41, { vat_deduction: 'none' }, { force: false, forceMode: undefined })
    const confirm = wrapper.get('[data-test="vat-treatment-filed-confirm"]')
    expect(confirm.text()).toContain('purchase_invoice.vat_treatment.change.filed_confirm')
    expect(wrapper.get('[data-test="vat-treatment-save"]').attributes('disabled')).toBeDefined()

    await confirm.get('input').setValue(true)
    await wrapper.get('[data-test="vat-treatment-save"]').trigger('click')
    await flushPromises()

    expect(m.change).toHaveBeenNthCalledWith(2, 41, { vat_deduction: 'none', confirm_filed_period: true }, { force: false, forceMode: undefined })
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('zaúčtovaný doklad v uzavřeném období: správce musí zvolit storno a přeúčtování', async () => {
    m.change.mockResolvedValue({ id: 41 })
    const wrapper = mount(PurchaseVatTreatmentModal, {
      props: {
        invoice: invoice({ locked: { is_locked: true, reasons: ['posted', 'period_closed'], booked_at: null, journal_entry_id: 3, period_status: 'closed', date_locked: false, accountant_managed: false } }),
        vatClassifications: [], isAdmin: true,
      },
      global: { stubs },
    })
    await wrapper.get('[data-test="vat-treatment-deduction"]').setValue('none')
    expect(wrapper.get('[data-test="vat-treatment-save"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="vat-treatment-reconcile"] input').setValue(true)
    await wrapper.get('[data-test="vat-treatment-save"]').trigger('click')
    await flushPromises()

    expect(m.change).toHaveBeenCalledWith(41, { vat_deduction: 'none' }, { force: true, forceMode: 'reconcile' })
  })

  it('účetní v uzavřeném období dostane vysvětlení a uložit nemůže', async () => {
    const wrapper = mount(PurchaseVatTreatmentModal, {
      props: {
        invoice: invoice({ locked: { is_locked: true, reasons: ['period_closed'], booked_at: null, journal_entry_id: null, period_status: 'closed', date_locked: false, accountant_managed: false } }),
        vatClassifications: [], isAdmin: false,
      },
      global: { stubs },
    })
    await wrapper.get('[data-test="vat-treatment-deduction"]').setValue('none')

    expect(wrapper.find('[data-test="vat-treatment-closed"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="vat-treatment-save"]').attributes('disabled')).toBeDefined()
  })
})
