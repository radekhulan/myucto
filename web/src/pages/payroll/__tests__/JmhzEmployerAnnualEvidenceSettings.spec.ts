import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  load: vi.fn(),
  save: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzEmployerAnnualEvidence: m.load,
    saveJmhzEmployerAnnualEvidence: m.save,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs-CZ' } }),
}))

import JmhzEmployerAnnualEvidenceSettings from '@/pages/payroll/JmhzEmployerAnnualEvidenceSettings.vue'

const emptyView = {
  evidence: null,
  offices: [{ id: 17, code: 'HQ', name: 'Hlavní' }],
  collective_agreement_types: [
    { item_code: '0', label: 'neexistuje', ordinal: 1 },
    { item_code: '1', label: 'podniková', ordinal: 2 },
  ],
  ownership_forms: [
    { item_code: '2', label: 'soukromé', ordinal: 2 },
  ],
}

describe('JmhzEmployerAnnualEvidenceSettings', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.load.mockResolvedValue(emptyView)
    m.save.mockResolvedValue({
      ...emptyView,
      evidence: {
        id: 91,
        report_year: 2026,
        revision_no: 1,
        previous_revision_id: null,
        schema_reference: 'payroll-jmhz-employer-annual-evidence.v1',
        collective_agreement_types: ['1'],
        ownership_form: '2',
        average_headcount_hundredths: 1250,
        average_disabled_headcount_hundredths: 75,
        disabled_share_hundredths: 600,
        ozp_reporting_office_id: null,
        evidence_reference: null,
        payload_sha256: 'a'.repeat(64),
        created_at: '2026-12-31 12:00:00',
      },
    })
  })

  it('uloží přesné roční hodnoty a nepovolí kombinovat kód 0', async () => {
    const wrapper = mount(JmhzEmployerAnnualEvidenceSettings, {
      props: { canWrite: true },
    })
    await flushPromises()

    await wrapper.get('[data-test="jmhz-annual-collective-0"]').setValue(true)
    await wrapper.get('[data-test="jmhz-annual-collective-1"]').setValue(true)
    await wrapper.get('[data-test="jmhz-annual-ownership"]').setValue('2')
    await wrapper.get('[data-test="jmhz-annual-headcount"]').setValue('12,50')
    await wrapper.get('[data-test="jmhz-annual-disabled-headcount"]').setValue('0,75')
    await wrapper.get('[data-test="jmhz-employer-annual-save"]').trigger('click')
    await flushPromises()

    expect(m.save).toHaveBeenCalledWith(expect.any(Number), {
      expected_revision_id: null,
      collective_agreement_types: ['1'],
      ownership_form: '2',
      average_headcount: '12,50',
      average_disabled_headcount: '0,75',
      ozp_reporting_office_id: null,
      evidence_reference: null,
    })
    expect(m.success).toHaveBeenCalled()
  })

  it('prázdný formulář pojmenuje chybějící pole, tlačítko nezašedne', async () => {
    const wrapper = mount(JmhzEmployerAnnualEvidenceSettings, {
      props: { canWrite: true },
    })
    await flushPromises()

    // Zašedlé tlačítko bez věty byla slepá ulička: čtyři pole, žádná stopa
    // po tom, které z nich chybí.
    expect(wrapper.get('[data-test="jmhz-employer-annual-save"]').attributes('disabled'))
      .toBeUndefined()

    await wrapper.get('[data-test="jmhz-employer-annual-save"]').trigger('click')
    await flushPromises()

    expect(m.save).not.toHaveBeenCalled()
    const problems = wrapper.get('[data-test="jmhz-annual-validation"]')
      .findAll('li').map(node => node.text())
    expect(problems).toEqual([
      'payroll.employer.jmhz_annual.validation.collective_types',
      'payroll.employer.jmhz_annual.validation.ownership',
      'payroll.employer.jmhz_annual.validation.average_headcount',
      'payroll.employer.jmhz_annual.validation.average_disabled_headcount',
    ])
  })

  it('selhané načtení nabídne opakování, ne prázdnou sekci', async () => {
    m.load.mockRejectedValueOnce(new Error('offline'))
    const wrapper = mount(JmhzEmployerAnnualEvidenceSettings, {
      props: { canWrite: true },
    })
    await flushPromises()

    expect(wrapper.find('[data-test="jmhz-annual-load-failed"]').exists()).toBe(true)

    await wrapper.get('[data-test="jmhz-annual-load-failed"] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="jmhz-annual-load-failed"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="jmhz-annual-ownership"]').exists()).toBe(true)
  })

  it('v režimu jen pro čtení nedovolí vytvořit revizi', async () => {
    const wrapper = mount(JmhzEmployerAnnualEvidenceSettings, {
      props: { canWrite: false },
    })
    await flushPromises()

    expect(wrapper.get('[data-test="jmhz-employer-annual-save"]').attributes('disabled'))
      .toBeDefined()
    expect(m.save).not.toHaveBeenCalled()
  })
})
