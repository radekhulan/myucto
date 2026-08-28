import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollDependantsResponse } from '@/api/payroll'

const mocks = vi.hoisted(() => ({
  personDependants: vi.fn(),
  createPersonDependant: vi.fn(),
  savePersonDependant: vi.fn(),
  createPersonDependantClaim: vi.fn(),
  savePersonDependantClaim: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    personDependants: mocks.personDependants,
    createPersonDependant: mocks.createPersonDependant,
    savePersonDependant: mocks.savePersonDependant,
    createPersonDependantClaim: mocks.createPersonDependantClaim,
    savePersonDependantClaim: mocks.savePersonDependantClaim,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: mocks.success, error: mocks.error }),
}))

// `useTablePrefs` táhne @/i18n, které volá skutečné `createI18n` — továrna
// proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

// `useTablePrefs` jde přes Pinii a API; v testu stačí prázdné výchozí předvolby.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PayrollPersonDependantsPanel from '@/pages/payroll/PayrollPersonDependantsPanel.vue'

function response(overrides: Partial<PayrollDependantsResponse> = {}): PayrollDependantsResponse {
  return {
    employee_id: 21,
    effective_on: '2026-06-01',
    frozen_through: null,
    dependants: [{
      id: 7,
      relation: 'child_own',
      full_name: 'Syntetické Dítě',
      given_name: 'Syntetické',
      family_name: 'Dítě',
      birth_date: '2015-02-02',
      birth_number_masked: '••••••0003',
      has_birth_number: true,
      ztp_p: false,
      student: false,
      existence_from: '2015-02-02',
      existence_to: null,
      note: null,
      can_claim_monthly: true,
      row_version: 1,
      claims: [{
        id: 91,
        child_reference: 'dependant-7',
        child_order: 1,
        claim_reason: 'own_household',
        ztp_p: false,
        evidence_status: 'unverified',
        evidence_reference: null,
        shared_household_confirmed: false,
        other_claimant_excluded: false,
        effective_from: '2026-01-01',
        effective_to: null,
        superseded_by_id: null,
        is_frozen: false,
        blockers: ['evidence_unverified', 'shared_household_unconfirmed'],
        credit: {
          status: 'calculated',
          rate_key: 'credit.child.first.monthly',
          monthly_credit_minor_units: 126_700,
          manual_review_reason: null,
        },
        row_version: 2,
      }],
    }],
    ...overrides,
  }
}

function mountPanel(canWrite = true) {
  return mount(PayrollPersonDependantsPanel, {
    props: { personId: 21, canWrite },
    global: { stubs: { ActionBar: true, SearchableSelect: true } },
  })
}

describe('PayrollPersonDependantsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.personDependants.mockResolvedValue(response())
  })

  it('shows the masked birth number and never a plaintext one', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.text()).toContain('••••••0003')
    expect(wrapper.text()).not.toContain('150202/0003')
  })

  it('renders both the desktop table and the mobile cards', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-layout="desktop"] table').exists()).toBe(true)
    expect(wrapper.find('[data-layout="mobile"]').exists()).toBe(true)
  })

  it('explains why a claim cannot be applied', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.find('tbody [aria-expanded]').trigger('click')

    const blockers = wrapper.find('[data-test="claim-blockers-91"]')
    expect(blockers.exists()).toBe(true)
    expect(blockers.text()).toContain('payroll.people.dependants.blockers.evidence_unverified')
    expect(blockers.text()).toContain(
      'payroll.people.dependants.blockers.shared_household_unconfirmed',
    )
  })

  it('saves a new claim through a single save button', async () => {
    mocks.createPersonDependantClaim.mockResolvedValue(response())
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.find('tbody [aria-expanded]').trigger('click')
    await wrapper.find('[data-test="add-claim-7"]').trigger('click')

    const editor = wrapper.find('[data-test="dependant-editor"]')
    expect(editor.exists()).toBe(true)
    expect(editor.findAll('[data-test="save-dependant"]')).toHaveLength(1)

    await wrapper.find('[data-test="claim-evidence-reference"]').setValue('document:birth')
    await wrapper.find('[data-test="claim-effective-from"]').setValue('2026-01-01')
    await editor.trigger('submit')
    await flushPromises()

    expect(mocks.createPersonDependantClaim).toHaveBeenCalledWith(21, 7, expect.objectContaining({
      evidence_reference: 'document:birth',
      effective_from: '2026-01-01',
    }))
    expect(mocks.success).toHaveBeenCalled()
  })

  it('uloží ověřený nárok i bez odkazu na doklad', async () => {
    mocks.createPersonDependantClaim.mockResolvedValue(response())
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.find('tbody [aria-expanded]').trigger('click')
    await wrapper.find('[data-test="add-claim-7"]').trigger('click')
    await wrapper.find('[data-test="claim-effective-from"]').setValue('2026-01-01')
    await wrapper.find('[data-test="dependant-editor"]').trigger('submit')
    await flushPromises()

    expect(mocks.createPersonDependantClaim).toHaveBeenCalledWith(21, 7, expect.objectContaining({
      evidence_status: 'verified',
      evidence_reference: null,
    }))
  })

  it('surfaces the API validation message instead of a generic failure', async () => {
    mocks.createPersonDependantClaim.mockRejectedValue({
      response: { data: { error: { code: 'validation_failed', message: 'Pořadí dítěte 1 už je obsazené.' } } },
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.find('tbody [aria-expanded]').trigger('click')
    await wrapper.find('[data-test="add-claim-7"]').trigger('click')
    await wrapper.find('[data-test="dependant-editor"]').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="dependant-error"]').text())
      .toContain('Pořadí dítěte 1 už je obsazené.')
  })

  it('hides write actions without permission', async () => {
    const wrapper = mountPanel(false)
    await flushPromises()
    await wrapper.find('tbody [aria-expanded]').trigger('click')

    expect(wrapper.find('[data-test="add-claim-7"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="edit-dependant-7"]').exists()).toBe(false)
  })
})
