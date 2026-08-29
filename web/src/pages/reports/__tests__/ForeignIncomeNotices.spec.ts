import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  catalog: vi.fn(),
  downloadXml: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/foreignIncomeNotices', () => ({
  foreignIncomeNoticesApi: { catalog: m.catalog, downloadXml: m.downloadXml },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: (permission: string) => permission === 'reports',
    canWrite: (permission: string) => permission === 'reports.export',
  }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_e: unknown, fallback: string) => fallback }))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs-CZ' },
  }),
}))

import ForeignIncomeNotices from '@/pages/reports/ForeignIncomeNotices.vue'

function catalog() {
  return {
    income_kinds: [
      {
        code: 5,
        group: '12',
        label: 'licenční poplatky - průmyslové',
        paragraph: '§ 22 odst. 1 písm. g) bod 1',
        effective_from: '2014-01-01',
        allows_exempt: true,
      },
      {
        code: 1,
        group: '7',
        label: 'příjmy ze služeb',
        paragraph: '§ 22 odst. 1 písm. c)',
        effective_from: '2014-01-01',
        allows_exempt: false,
      },
    ],
    taxpayer_types: ['01', '02', '03', '04', '05', '06'],
    tax_id_types: ['D', 'R', 'S', 'J'],
    address_types: ['01', '02', '03'],
    notice_variants: ['R', 'N'],
    payment_modes: ['U', 'Z'],
    security_rates: ['A', 'B', 'C', 'D', 'E'],
  }
}

async function mountPanel() {
  const wrapper = mount(ForeignIncomeNotices, {
    global: {
      stubs: {
        ActionBar: {
          props: ['actions'],
          template:
            '<button v-for="a in actions" :key="a.key"'
            + ' :data-test="`action-${a.key}`" @click="a.run()" />',
        },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('ForeignIncomeNotices', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.catalog.mockResolvedValue(catalog())
    m.downloadXml.mockResolvedValue(undefined)
  })

  it('posílá oznámení § 38da s druhem příjmu z číselníku a částkami v haléřích', async () => {
    const wrapper = await mountPanel()

    await wrapper.find('[data-test="foreign-income-company"]').setValue('Beispiel GmbH')
    await wrapper.find('[data-test="foreign-income-residence-country"]').setValue('de')
    await wrapper.find('[data-test="foreign-income-city"]').setValue('München')
    await wrapper.find('[data-test="foreign-income-kind"]').setValue('5')
    await wrapper.find('[data-test="foreign-income-rate"]').setValue('10')
    await wrapper.find('[data-test="foreign-income-payment-date"]').setValue('2025-06-30')
    await wrapper.find('[data-test="foreign-income-paid-amount"]').setValue('90000,50')
    await wrapper.find('[data-test="foreign-income-tax-base"]').setValue('100000')

    await wrapper.find('[data-test="action-download"]').trigger('click')
    await flushPromises()

    expect(m.downloadXml).toHaveBeenCalledTimes(1)
    const [form, payload] = m.downloadXml.mock.calls[0]
    expect(form).toBe('dpshl1')
    expect(payload.income_kind).toBe(5)
    // Sazba jde po drátě v desetinách procenta, částka v haléřích — a desetinná
    // čárka se musí chovat stejně jako tečka.
    expect(payload.rate_tenths_of_percent).toBe(100)
    expect(payload.paid_amount_minor).toBe(9000050)
    expect(payload.payment_date).toBe('2025-06-30')
    expect(payload.payment_year).toBeNull()
    expect(payload.payee.residence_country).toBe('DE')
    expect(payload.payee.company_name).toBe('Beispiel GmbH')
    expect(payload.payee.first_name).toBeNull()
    expect(m.success).toHaveBeenCalled()
  })

  it('u hlášení § 38e posílá popis příjmu, sazbu jako znak a rozhodná data', async () => {
    const wrapper = await mountPanel()

    await wrapper.find('[data-test="foreign-income-form"]').setValue('dpszd1')
    await wrapper.find('[data-test="foreign-income-company"]').setValue('Example Trading Ltd.')
    await wrapper.find('[data-test="foreign-income-residence-country"]').setValue('GB')
    await wrapper.find('[data-test="foreign-income-city"]').setValue('London')
    await wrapper.find('[data-test="foreign-income-description"]').setValue('§ 22 odst. 1 písm. c)')
    await wrapper.find('[data-test="foreign-income-security-rate"]').setValue('B')
    await wrapper.find('[data-test="foreign-income-security-income"]').setValue('250000')
    await wrapper.find('[data-test="foreign-income-receivable-on"]').setValue('2025-05-12')
    await wrapper.find('[data-test="foreign-income-decisive-on"]').setValue('2025-06-10')

    await wrapper.find('[data-test="action-download"]').trigger('click')
    await flushPromises()

    const [form, payload] = m.downloadXml.mock.calls[0]
    expect(form).toBe('dpszd1')
    expect(payload.rate).toBe('B')
    expect(payload.income_minor).toBe(25000000)
    expect(payload.receivable_on).toBe('2025-05-12')
    expect(payload.decisive_on).toBe('2025-06-10')
  })

  it('rok úhrady nabídne jen u druhu příjmu, který smí být osvobozený', async () => {
    const wrapper = await mountPanel()

    await wrapper.find('[data-test="foreign-income-kind"]').setValue('1')
    expect(wrapper.text()).not.toContain('foreign_income.payment_year_hint')

    await wrapper.find('[data-test="foreign-income-kind"]').setValue('5')
    expect(wrapper.text()).toContain('foreign_income.payment_year_hint')
  })

  it('neplatnou částku hlásí lokálně a podání vůbec neodešle', async () => {
    const wrapper = await mountPanel()

    await wrapper.find('[data-test="foreign-income-kind"]').setValue('5')
    await wrapper.find('[data-test="foreign-income-rate"]').setValue('10')
    await wrapper.find('[data-test="foreign-income-paid-amount"]').setValue('devadesát tisíc')

    await wrapper.find('[data-test="action-download"]').trigger('click')
    await flushPromises()

    expect(m.downloadXml).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="foreign-income-error"]').exists()).toBe(true)
  })
})
