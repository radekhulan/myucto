import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  workplaceBulkPreview: vi.fn(),
  workplaceBulkApply: vi.fn(),
  employmentJmhzEvidenceOptions: vi.fn(() => Promise.resolve({ countries: [{ code: 'CZ', label: 'Česko' }] })),
  searchJmhzMunicipalities: vi.fn(() => Promise.resolve([])),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    workplaceBulkPreview: m.workplaceBulkPreview,
    workplaceBulkApply: m.workplaceBulkApply,
    employmentJmhzEvidenceOptions: m.employmentJmhzEvidenceOptions,
    searchJmhzMunicipalities: m.searchJmhzMunicipalities,
  },
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      (params ? `${key} ${Object.values(params).join(' ')}` : key),
    locale: ref('cs-CZ'),
  }),
}))

import PayrollWorkplaceBulkFillDialog from '@/components/payroll/PayrollWorkplaceBulkFillDialog.vue'

function preview(overrides: Record<string, unknown> = {}) {
  return {
    period_start: '2026-08-01',
    summary: { employments: 2, missing: 2, verified: 0, invalid: 0, excluded: 0 },
    suggestions: [
      { municipality_code: '554782', municipality_name: 'Praha', country_code: 'CZ', employments: 2 },
    ],
    missing_employment_ids: [12, 13],
    items: [
      { employment_id: 12, employee_id: 5, full_name: 'Syntetická osoba', employment_code: 'SYNTH-HPP', state: 'missing', reason: null, municipality_code: null, municipality_name: null, country_code: null },
      { employment_id: 13, employee_id: 6, full_name: 'Druhá syntetická osoba', employment_code: 'SYNTH-DPC', state: 'missing', reason: null, municipality_code: null, municipality_name: null, country_code: null },
    ],
    ...overrides,
  }
}

const MOUNT = { global: { stubs: { teleport: true, RouterLink: { props: ['to'], template: '<a><slot /></a>' } } } }

describe('PayrollWorkplaceBulkFillDialog', () => {
  beforeEach(() => vi.clearAllMocks())

  it('načte náhled, předvybere nejčastější pracoviště a odešle apply na chybějící vztahy', async () => {
    m.workplaceBulkPreview.mockResolvedValue(preview())
    m.workplaceBulkApply.mockResolvedValue({
      period_start: '2026-08-01',
      municipality_code: '554782',
      municipality_name: 'Praha',
      country_code: 'CZ',
      counts: { applied: 2, skipped: 0, failed: 0 },
      applied: [{ employment_id: 12 }, { employment_id: 13 }],
      skipped: [],
      failed: [],
    })

    const wrapper = mount(PayrollWorkplaceBulkFillDialog, {
      ...MOUNT,
      props: { periodStart: '2026-08-01', employmentIds: null },
    })
    await flushPromises()

    expect(m.workplaceBulkPreview).toHaveBeenCalledWith({
      period_start: '2026-08-01',
      employment_ids: null,
    })
    expect((wrapper.get('[data-test="workplace-bulk-municipality"] input').element as HTMLInputElement).value)
      .toContain('Praha')

    await wrapper.get('[data-test="workplace-bulk-apply"]').trigger('click')
    await flushPromises()

    expect(m.workplaceBulkApply).toHaveBeenCalledWith({
      period_start: '2026-08-01',
      municipality_code: '554782',
      country_code: 'CZ',
      employment_ids: [12, 13],
    })
    expect(wrapper.get('[data-test="workplace-bulk-applied"]').text()).toBe('2')
  })

  /* Vyplněné neověřené pracoviště se nepřepisuje, ale musí být vidět jménem i po uložení. */
  it('jmenovitě ukáže vyplněná, ale neověřená pracoviště i ve výsledku', async () => {
    const unverified = [{ employment_id: 14, employee_id: 7, full_name: 'Neověřená osoba', employment_code: 'SYNTH-DPP', work_place: 'Praha', municipality_code: null }]
    m.workplaceBulkPreview.mockResolvedValue(preview({ unverified }))
    m.workplaceBulkApply.mockResolvedValue({
      period_start: '2026-08-01',
      municipality_code: '554782',
      municipality_name: 'Praha',
      country_code: 'CZ',
      counts: { applied: 2, skipped: 0, failed: 0 },
      applied: [{ employment_id: 12 }, { employment_id: 13 }],
      skipped: [],
      failed: [],
      unverified: [],
    })

    const wrapper = mount(PayrollWorkplaceBulkFillDialog, {
      ...MOUNT,
      props: { periodStart: '2026-08-01', employmentIds: null },
    })
    await flushPromises()

    const block = wrapper.get('[data-test="workplace-bulk-unverified"]')
    expect(block.text()).toContain('payroll.workplace_bulk.unverified_title 1')
    expect(block.get('[data-test="workplace-bulk-unverified-14"]').text()).toContain('Neověřená osoba')
    expect(block.get('[data-test="workplace-bulk-unverified-14"]').text()).toContain('Praha')

    await wrapper.get('[data-test="workplace-bulk-apply"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="workplace-bulk-result"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="workplace-bulk-unverified-14"]').exists()).toBe(true)
  })

  it('bez návrhu nedovolí odeslat, dokud se obec nezvolí', async () => {
    m.workplaceBulkPreview.mockResolvedValue(preview({ suggestions: [] }))

    const wrapper = mount(PayrollWorkplaceBulkFillDialog, {
      ...MOUNT,
      props: { periodStart: '2026-08-01', employmentIds: null },
    })
    await flushPromises()

    expect(wrapper.get('[data-test="workplace-bulk-apply"]').attributes('disabled')).toBeDefined()
    expect(m.workplaceBulkApply).not.toHaveBeenCalled()
  })
})
