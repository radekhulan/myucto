import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'

const m = vi.hoisted(() => ({
  preview: vi.fn(),
  apply: vi.fn(),
  success: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { payoutDefaultsPreview: m.preview, payoutDefaultsApply: m.apply },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: vi.fn() }),
}))

import PayrollPayoutDefaultsBulkDialog from '../PayrollPayoutDefaultsBulkDialog.vue'

function mountDialog(employeeIds: number[]) {
  const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs } })
  return mount(PayrollPayoutDefaultsBulkDialog, {
    props: { employeeIds },
    global: {
      plugins: [i18n],
      stubs: { Modal: { props: ['title'], template: '<div><slot /><slot name="footer" /></div>' } },
    },
  })
}

describe('PayrollPayoutDefaultsBulkDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.preview.mockResolvedValue([
      { employee_id: 1, full_name: 'Syntetická Hotovost', payout_method: 'cash', applicable: true, destination_kind: 'cash', blocked_reason: null },
      { employee_id: 2, full_name: 'Syntetický Účet', payout_method: 'bank', applicable: true, destination_kind: 'bank', blocked_reason: null },
      { employee_id: 3, full_name: 'Syntetická Bez účtu', payout_method: 'bank', applicable: false, destination_kind: null, blocked_reason: 'Zaměstnanec nemá ověřený výplatní účet.' },
    ])
    m.apply.mockResolvedValue({ applied: [1, 2], skipped: [] })
  })

  it('ukáže, komu se pravidlo z karty doplní a komu ne, a doplní jen ty první', async () => {
    const wrapper = mountDialog([1, 2, 3])
    await flushPromises()

    expect(m.preview).toHaveBeenCalledWith([1, 2, 3])
    expect(wrapper.text()).toContain('jen tehdy, když platby')
    expect(wrapper.get('[data-test="payout-defaults-ready"]').text()).toBe('2')
    expect(wrapper.get('[data-test="payout-defaults-blocked"]').text()).toBe('1')
    expect(wrapper.get('[data-test="payout-defaults-kinds"]').text()).toContain('Výplata v hotovosti: 1')
    expect(wrapper.get('[data-test="payout-defaults-kinds"]').text()).toContain('Převod na účet z karty osoby: 1')
    expect(wrapper.get('[data-test="payout-defaults-blocked-list"]').text()).toContain('Syntetická Bez účtu')

    await wrapper.get('[data-test="payout-defaults-apply"]').trigger('click')
    await flushPromises()

    expect(m.apply).toHaveBeenCalledWith([1, 2])
    expect(wrapper.get('[data-test="payout-defaults-result"]').text()).toContain('Doplněno: 2. Nedoplněno: 0.')
    expect(wrapper.emitted('applied')).toHaveLength(1)
  })

  it('bez osoby, které jde pravidlo odvodit, doplnění nenabídne', async () => {
    m.preview.mockResolvedValue([
      { employee_id: 3, full_name: 'Syntetická Bez účtu', payout_method: 'bank', applicable: false, destination_kind: null, blocked_reason: 'Zaměstnanec nemá ověřený výplatní účet.' },
    ])
    const wrapper = mountDialog([3])
    await flushPromises()

    expect(wrapper.get('[data-test="payout-defaults-apply"]').attributes('disabled')).toBeDefined()
    expect(m.apply).not.toHaveBeenCalled()
  })
})
