import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  create: vi.fn(),
  preview: vi.fn(),
  prepare: vi.fn(),
  requestEnd: vi.fn(),
  recordReceipt: vi.fn(),
  person: vi.fn(),
}))

vi.mock('@/api/payrollDiscountIntents', () => ({
  payrollDiscountIntentsApi: {
    list: m.list,
    create: m.create,
    preview: m.preview,
    prepare: m.prepare,
    requestEnd: m.requestEnd,
    recordReceipt: m.recordReceipt,
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    person: m.person,
  },
}))

vi.mock('@/components/payroll/PayrollPersonSearchSelect.vue', () => ({
  default: {
    name: 'PayrollPersonSearchSelect',
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template: '<select data-test="person-search" role="combobox" />',
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (permission: string) => permission === 'payroll.submissions',
  }),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import PayrollDiscountIntentsPanel
  from '@/pages/payroll/PayrollDiscountIntentsPanel.vue'

interface IntentOverrides {
  [key: string]: unknown
}

function intent(overrides: IntentOverrides = {}): Record<string, unknown> {
  return {
    id: 1,
    employment_id: 11,
    employee_id: 42,
    employee_name: 'Testovací Zaměstnanec',
    discount_reason: 'age_55_plus',
    intent_from: '2026-09-01',
    intent_to: null,
    status: 'submitted',
    accepted_on: null,
    ended_accepted_on: null,
    rejection_reason: null,
    employee_informed_on: null,
    ossz_code: 222,
    row_version: 1,
    evidences_discount: false,
    earliest_notification_on: '2026-08-01',
    notification_due_on: '2026-10-20',
    transitional_q1_2026: false,
    ...overrides,
  }
}

describe('PayrollDiscountIntentsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.person.mockResolvedValue({ employments: [] })
    m.list.mockResolvedValue([])
  })

  it('používá dark-mode tokeny na kartách a datumových polích', async () => {
    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    expect(wrapper.html()).not.toContain('bg-white')
    expect(wrapper.get('[data-test="discount-intent-from"]').classes())
      .toContain('bg-surface')
    expect(wrapper.get('[data-test="discount-intent-informed-on"]').classes())
      .toContain('bg-surface')
    expect(wrapper.findComponent({ name: 'PayrollPersonSearchSelect' }).exists())
      .toBe(true)
  })

  /**
   * Nepřijatý záměr slevu nedokládá. Kdyby to obrazovka neřekla, uživatel by
   * z připraveného podání usoudil, že je hotovo — a sleva by se přitom
   * neuplatnila.
   */
  it('u nepřijatého záměru říká, že se sleva neuplatní', async () => {
    m.list.mockResolvedValue([intent()])

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="discount-intent-not-evidenced-1"]').exists())
      .toBe(true)
  })

  it('u přijatého záměru varování o neuplatnění nezobrazuje', async () => {
    m.list.mockResolvedValue([intent({
      status: 'accepted',
      accepted_on: '2026-08-20',
      evidences_discount: true,
    })])

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="discount-intent-not-evidenced-1"]').exists())
      .toBe(false)
  })

  /**
   * Přechodné pravidlo za 01–03/2026. Kontroly 164, 290 a 333 ho u ČSSZ
   * vyhodnotit neumí, takže tohle je jediné místo, kde se uživatel dozví, že
   * po 30. 6. 2026 se sleva za ta období neuzná.
   */
  it('varuje u období 01–03/2026 na hranici 30. 6. 2026', async () => {
    m.list.mockResolvedValue([intent({
      intent_from: '2026-01-01',
      transitional_q1_2026: true,
    })])

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="discount-intents-transitional"]').exists())
      .toBe(true)
  })

  it('u běžného období přechodné varování nezobrazuje', async () => {
    m.list.mockResolvedValue([intent()])

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="discount-intents-transitional"]').exists())
      .toBe(false)
  })

  /**
   * Přijetí se nedá zapsat bez dne doručení. Bez něj by v evidenci vznikl
   * záměr bez data, na kterém podle § 7a odst. 5 stojí celý nárok.
   */
  it('nezapíše přijetí bez dne doručení', async () => {
    m.list.mockResolvedValue([intent()])

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    const accept = wrapper.findAll('button')
      .find(button => button.text()
        .includes('payroll.discountIntents.actions.accept'))
    expect(accept?.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="discount-intent-accepted-on-1"]')
      .setValue('2026-08-20')
    await flushPromises()

    const enabled = wrapper.findAll('button')
      .find(button => button.text()
        .includes('payroll.discountIntents.actions.accept'))
    expect(enabled?.attributes('disabled')).toBeUndefined()

    await enabled?.trigger('click')
    await flushPromises()

    expect(m.recordReceipt).toHaveBeenCalledWith(1, 'production', {
      outcome: 'accepted',
      accepted_on: '2026-08-20',
    })
  })
})
