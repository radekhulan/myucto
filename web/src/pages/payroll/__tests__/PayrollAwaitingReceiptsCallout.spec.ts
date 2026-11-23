import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  operationalHealth: vi.fn(), choices: vi.fn(), download: vi.fn(), mobileKeyProfile: vi.fn(),
}))

vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('@/api/isdsAccess', () => ({ isdsAccessApi: { choices: m.choices } }))

vi.mock('@/api/payroll', () => ({
  payrollApi: { operationalHealth: m.operationalHealth },
}))
vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    mobileKeyProfile: m.mobileKeyProfile,
    downloadReceiptsBatchWithPassword: m.download,
    startMobileKeyOutboxBatch: vi.fn(),
    downloadReceiptsBatchWithMobileKey: vi.fn(),
  },
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
  }),
}))

import PayrollAwaitingReceiptsCallout from '../PayrollAwaitingReceiptsCallout.vue'

function health(awaiting: number) {
  return { isds_outbox: { failed: 0, send_uncertain: 0, rejected: 0, awaiting_receipt: awaiting } }
}

describe('PayrollAwaitingReceiptsCallout', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.choices.mockResolvedValue([])
    m.mobileKeyProfile.mockResolvedValue({ saved: false })
  })

  it('upozorní na odeslané zprávy bez doručenky a nabídne jedno tlačítko', async () => {
    m.operationalHealth.mockResolvedValue(health(3))

    const wrapper = mount(PayrollAwaitingReceiptsCallout)
    await flushPromises()

    expect(wrapper.get('[data-test="payroll-awaiting-receipts"]').text())
      .toContain('payroll.dashboard.awaiting_receipts.title 3')
    expect(wrapper.get('[data-test="mobile-key-receipts-action"]').text()).toContain('3')
  })

  it('použije potvrzený sdílený přístup ke stažení doručenek', async () => {
    m.operationalHealth.mockResolvedValue(health(3))
    m.choices.mockResolvedValue([{ id: 42, label: 'Kancelář', box_id: 'fil8271', scope: 'shared', method: 'password' }])
    m.download.mockResolvedValue({ attached: 1, pending: 2, failed: 0, items: [] })
    const wrapper = mount(PayrollAwaitingReceiptsCallout)
    await flushPromises()

    await wrapper.get('[data-test="mobile-key-receipts-action"]').trigger('click')
    await flushPromises()
    expect(m.choices).toHaveBeenCalledWith('production', 'receipts')
    await wrapper.get('input[value="saved"]').setValue(true)
    await wrapper.get('[data-test="isds-saved-access-select"]').setValue('42')
    const submit = wrapper.get<HTMLButtonElement>('[data-test="payroll-saved-receipts-submit"]')
    expect(submit.element.disabled).toBe(true)
    await wrapper.get('[data-test="isds-saved-access-confirm"]').setValue(true)
    await submit.trigger('click')
    await flushPromises()
    expect(m.download).toHaveBeenCalledWith('production', { access_id: 42, authorized: true })
    expect(wrapper.get('[data-test="payroll-awaiting-receipts-result"]').text()).toContain('1 2')
    wrapper.unmount()
  })

  it('bez čekajících zpráv se neukáže', async () => {
    m.operationalHealth.mockResolvedValue(health(0))

    const wrapper = mount(PayrollAwaitingReceiptsCallout)
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-awaiting-receipts"]').exists()).toBe(false)
  })
})
