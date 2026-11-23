import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  create: vi.fn(),
  preview: vi.fn(),
  prepare: vi.fn(),
  requestEnd: vi.fn(),
  recordReceipt: vi.fn(),
  recordEmployeeInformed: vi.fn(),
  person: vi.fn(),
  canWrite: vi.fn(() => true),
}))

vi.mock('@/api/payrollDiscountIntents', () => ({
  payrollDiscountIntentsApi: {
    list: m.list,
    create: m.create,
    preview: m.preview,
    prepare: m.prepare,
    requestEnd: m.requestEnd,
    recordReceipt: m.recordReceipt,
    recordEmployeeInformed: m.recordEmployeeInformed,
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
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    te: () => true,
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
    m.canWrite.mockReturnValue(true)
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

  /**
   * § 7a odst. 5 věta druhá: záměr nelze oznámit dřív než přihlášku. Když
   * aplikace den podání přihlášky nezná, musí to říct dřív, než se podá.
   */
  it('upozorní, že den podání přihlášky není známý', async () => {
    m.list.mockResolvedValue([intent({ status: 'draft', registration_submitted_on: null })])

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="discount-intent-registration-missing-1"]').exists())
      .toBe(true)
  })

  it('převzatý záměr předchozího programu označí a o přihlášce nevaruje', async () => {
    m.list.mockResolvedValue([intent({
      status: 'accepted',
      accepted_on: '2026-08-20',
      evidences_discount: true,
      registration_submitted_on: null,
      predecessor_source: 'ozuspoj_xml',
    })])

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="discount-intent-predecessor-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="discount-intent-registration-missing-1"]').exists())
      .toBe(false)
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

  /**
   * Čtenář bez práva zápisu dostával „Nejdřív opište den doručení z protokolu
   * ČSSZ" — vyplnil datum, tlačítko zůstalo zhasnuté a hláška tvrdila totéž.
   * Důvod musí popisovat SKUTEČNOU příčinu.
   */
  it('bez práva zápisu neříká, že chybí datum, ale že chybí oprávnění', async () => {
    m.canWrite.mockReturnValue(false)
    m.list.mockResolvedValue([intent()])

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.discountIntents.hints.readOnly')
    expect(wrapper.text()).not.toContain('payroll.discountIntents.hints.acceptedOnRequired')
  })

  /** Datumy z evidence patří do stejného tvaru jako všude jinde v appce. */
  it('datumy záměru ukáže česky, ne v ISO', async () => {
    m.list.mockResolvedValue([intent()])

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    const card = wrapper.get('[data-test="discount-intent-1"]').text()
    expect(card).toContain('01. 09. 2026')
    expect(card).toContain('20. 10. 2026')
    expect(card).not.toContain('2026-09-01')
  })

  /**
   * § 23d odst. 2: poučení zaměstnance před prvním uplatněním slevy se dá
   * zapsat i dodatečně, jinak by upozornění v mzdovém běhu nešlo odstranit.
   */
  it('dodatečně zapíše den poučení zaměstnance', async () => {
    m.list.mockResolvedValue([intent({ status: 'accepted', accepted_on: '2026-08-20' })])

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    const action = () => wrapper.findAll('button')
      .find(button => button.text().includes('payroll.discountIntents.actions.recordInformed'))
    expect(action()?.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="discount-intent-informed-on-1"]').setValue('2026-08-25')
    await flushPromises()
    expect(action()?.attributes('disabled')).toBeUndefined()

    await action()?.trigger('click')
    await flushPromises()

    expect(m.recordEmployeeInformed).toHaveBeenCalledWith(1, 'production', '2026-08-25')
  })

  it('u záměru s doloženým poučením a u převzatého záměru zápis poučení nenabízí', async () => {
    m.list.mockResolvedValue([
      intent({ id: 1, employee_informed_on: '2026-08-01' }),
      intent({ id: 2, predecessor_source: 'ozuspoj_xml', status: 'accepted' }),
    ])

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="discount-intent-informed-on-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="discount-intent-informed-on-2"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="discount-intent-informed-1"]').text())
      .toContain('01. 08. 2026')
  })

  /** Rozbalené XML se nedalo zavřít a překrývalo zbytek karty. */
  it('náhled XML jde zavřít', async () => {
    m.list.mockResolvedValue([intent()])
    m.preview.mockResolvedValue({ xml: '<ozuspoj/>' })

    const wrapper = mount(PayrollDiscountIntentsPanel)
    await flushPromises()

    const preview = wrapper.findAll('button')
      .find(button => button.text().includes('payroll.discountIntents.actions.preview'))
    await preview?.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="discount-intent-preview-1"]').exists()).toBe(true)
    await wrapper.get('[data-test="discount-intent-preview-close-1"]').trigger('click')
    expect(wrapper.find('[data-test="discount-intent-preview-1"]').exists()).toBe(false)
  })

  /*
   * Chyba náhledu „chybí kód OSSZ“ říkala jen „Doplňte ho v Nastavení mezd“.
   * Pod hláškou musí být proklik přímo na registraci zaměstnavatele.
   */
  it('chyba záměru nese proklik na místo nápravy', async () => {
    m.list.mockResolvedValue([intent({ status: 'draft' })])
    m.preview.mockRejectedValue(Object.assign(new Error('fail'), {
      isAxiosError: true,
      response: { data: { error: { code: 'ozuspoj_ossz_code_missing', message: 'Zaměstnavatel nemá vyplněný kód OSSZ.' } } },
    }))

    const wrapper = mount(PayrollDiscountIntentsPanel, {
      global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="to"><slot /></a>' } } },
    })
    await flushPromises()
    const preview = wrapper.findAll('button')
      .find(button => button.text().includes('payroll.discountIntents.actions.preview'))
    await preview?.trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="discount-intents-error"]').text()).toContain('kód OSSZ')
    expect(wrapper.get('[data-test="discount-intents-error-remediation"]').attributes('data-to'))
      .toBe('/payroll/settings?tab=employer#payroll-employer-registration')
  })
})
