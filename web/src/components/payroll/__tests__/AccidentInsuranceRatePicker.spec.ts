import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  accidentInsuranceRateSchedule: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { accidentInsuranceRateSchedule: m.accidentInsuranceRateSchedule },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import AccidentInsuranceRatePicker from '@/components/payroll/AccidentInsuranceRatePicker.vue'

const LEGAL = {
  decree: '125/1993 Sb.',
  annex: 'Příloha č. 2',
  annex_title: 'Sazby pojistného podle převažující činnosti vykonávané zaměstnavatelem',
  rates_source: 'vyhláška č. 487/2001 Sb., čl. I bod 3',
  rates_effective_from: '2002-01-01',
  activity_list_source: 'původní znění vyhlášky č. 125/1993 Sb.',
  activity_list_effective_from: '1993-04-22',
  classification: 'OKEČ',
  classification_note: 'Členění ekonomických činností bylo převzato z OKEČ.',
  classification_retired_on: '2007-12-31',
  classification_successor: 'CZ-NACE',
  minimum_quarterly_premium_czk: 100,
  minimum_quarterly_premium_source: 'poslední věta přílohy č. 2',
  rate_selection_rule: '§ 12 odst. 2 vyhlášky',
  source_url: 'https://www.zakonyprolidi.cz/cs/1993-125',
}

function schedule(overrides: Record<string, unknown> = {}) {
  return {
    schedule: {
      groups: [
        {
          ordinal: 1,
          key: 'rate-8-40',
          rate_per_mille: '8.40',
          kind: 'classified' as const,
          label: null,
          activities: [
            { ordinal: 1, okec_code: '36.1', label: 'Výroba nábytku' },
            { ordinal: 2, okec_code: '90', label: 'Odstraňování odpadu a odvod odpadních vod' },
          ],
        },
        {
          ordinal: 2,
          key: 'rate-10-50',
          rate_per_mille: '10.50',
          kind: 'hazard' as const,
          label: 'Činnosti, ve kterých se pracuje s výbušninami',
          activities: [],
        },
        {
          ordinal: 3,
          key: 'rate-5-60',
          rate_per_mille: '5.60',
          kind: 'residual' as const,
          label: 'Ostatní ekonomické činnosti',
          activities: [],
        },
      ],
      legal: LEGAL,
      codebook: {
        package_key: 'cz-accident-insurance-annex-2-v1',
        manifest_sha256: 'x',
        schema_version: 'accident-insurance-rate-schedule.v1',
        group_count: 3,
        activity_count: 2,
      },
    },
    nace: { code: '310000', display: '31.00.00', name: 'Výroba nábytku', status: 'active' },
    suggestions: [
      {
        group_key: 'rate-8-40',
        rate_per_mille: '8.40',
        okec_code: '36.1',
        label: 'Výroba nábytku',
        score: 1,
      },
    ],
    suggestions_binding: false as const,
    ...overrides,
  }
}

async function open(props: { canWrite?: boolean; currentRate?: string } = {}) {
  const wrapper = mount(AccidentInsuranceRatePicker, {
    props: { canWrite: props.canWrite ?? true, currentRate: props.currentRate ?? '' },
  })
  await flushPromises()
  await wrapper.find('button[aria-expanded]').trigger('click')
  await flushPromises()
  return wrapper
}

describe('AccidentInsuranceRatePicker', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.accidentInsuranceRateSchedule.mockResolvedValue(schedule())
  })

  it('vypíše sazebník včetně skupin bez kódu OKEČ', async () => {
    const wrapper = await open()

    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(4)
    expect(rows[0].text()).toContain('36.1')
    expect(rows[0].text()).toContain('8.40')
    // Zbytková a „nebezpečná" skupina kód nemají — bez nich by sazebník vypadal
    // jako uzavřený výčet kódů, kterým není.
    expect(rows[2].text()).toContain('10.50')
    expect(rows[2].text()).toContain('—')
    expect(rows[3].text()).toContain('Ostatní ekonomické činnosti')
  })

  it('upozorní na zrušenou klasifikaci OKEČ dřív, než ukáže data', async () => {
    const wrapper = await open()

    expect(wrapper.find('[data-testid="accident-rate-okec-warning"]').exists()).toBe(true)
  })

  it('sazbu jen vydá jako událost, sám nic neukládá', async () => {
    const wrapper = await open()

    await wrapper.findAll('tbody tr')[0].find('button').trigger('click')

    expect(wrapper.emitted('select')).toEqual([['8.40']])
  })

  it('nabídne návrh podle názvu CZ-NACE a označí ho jako nezávazný', async () => {
    const wrapper = await open()
    const hint = wrapper.find('[data-testid="accident-rate-nace-hint"]')

    expect(hint.text()).toContain('nace_is')
    expect(hint.text()).toContain('31.00.00 — Výroba nábytku')
    expect(hint.text()).toContain('suggestion_disclaimer')
  })

  it('bez CZ-NACE firmy neříká „nic jsme nenašli", ale že nemá z čeho', async () => {
    m.accidentInsuranceRateSchedule.mockResolvedValue(
      schedule({ nace: null, suggestions: [] }),
    )
    const wrapper = await open()
    const hint = wrapper.find('[data-testid="accident-rate-nace-hint"]')

    expect(hint.text()).toContain('nace_missing')
    expect(hint.text()).not.toContain('no_suggestion')
  })

  it('rozliší selhání dotazu od prázdného sazebníku', async () => {
    m.accidentInsuranceRateSchedule.mockRejectedValue(new Error('500'))
    const wrapper = await open()

    expect(wrapper.find('[data-testid="accident-rate-schedule-failed"]').exists()).toBe(true)
    expect(wrapper.find('tbody').exists()).toBe(false)
  })

  it('filtruje podle názvu bez ohledu na diakritiku i podle kódu', async () => {
    const wrapper = await open()

    await wrapper.find('input[type="search"]').setValue('odpadu')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)

    await wrapper.find('input[type="search"]').setValue('naby')
    expect(wrapper.findAll('tbody tr')[0].text()).toContain('Výroba nábytku')

    await wrapper.find('input[type="search"]').setValue('36.')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
  })

  it('bez práva zápisu sazbu nenabízí k převzetí', async () => {
    const wrapper = await open({ canWrite: false })

    expect(wrapper.findAll('tbody button')).toHaveLength(0)
  })
})
