import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  profile: vi.fn(),
  saveProfile: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    regzelProfile: m.profile,
    saveRegzelProfile: m.saveProfile,
  },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import RegzelProfileSettings from '@/pages/payroll/RegzelProfileSettings.vue'

describe('RegzelProfileSettings', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.profile.mockResolvedValue({
      profile: null,
      suggested_tax_office_workplace_code: '3001',
    })
  })

  it('vyžaduje explicitní potvrzení evidence a uloží všechny tři příznaky', async () => {
    m.saveProfile.mockResolvedValue({
      supplier_id: 1,
      social_enterprise: true,
      employment_agency: false,
      protected_labor_market: true,
      tax_office_code: '3000',
      tax_office_workplace_code: '3001',
      payer_reference_number: '612345678',
      is_complete: true,
      evidence_confirmed_at: '2026-08-04 12:00:00',
      row_version: 1,
      updated_at: '2026-08-04 12:00:00',
    })
    const wrapper = mount(RegzelProfileSettings, {
      props: { canWrite: true },
    })
    await flushPromises()

    await wrapper.get('[data-test="regzel-tax-office-code"]').setValue('3000')
    await wrapper.get('[data-test="regzel-use-workplace-suggestion"]').trigger('click')
    await wrapper.get('[data-test="regzel-payer-reference-number"]').setValue('612345678')
    await wrapper.get('[data-test="social-enterprise"]').setValue(true)
    await wrapper.get('[data-test="protected-labor-market"]').setValue(true)
    await wrapper.get('[data-test="regzel-profile-save"]').trigger('click')
    expect(m.saveProfile).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toContain(
      'payroll.regzel.profile.confirmation_required',
    )

    await wrapper.get('[data-test="regzel-profile-confirmation"]').setValue(true)
    await wrapper.get('[data-test="regzel-profile-save"]').trigger('click')
    await flushPromises()

    expect(m.saveProfile).toHaveBeenCalledWith({
      row_version: 0,
      social_enterprise: true,
      employment_agency: false,
      protected_labor_market: true,
      tax_office_code: '3000',
      tax_office_workplace_code: '3001',
      payer_reference_number: '612345678',
      evidence_confirmed: true,
    })
    expect(wrapper.text()).toContain('payroll.regzel.profile.confirmed_at')
  })

  it('ponechá přesnou API chybu trvale přímo u formuláře', async () => {
    m.saveProfile.mockRejectedValue({
      response: { data: { error: { message: 'Profil mezitím změnila Jana.' } } },
    })
    const wrapper = mount(RegzelProfileSettings, {
      props: { canWrite: true },
    })
    await flushPromises()

    await wrapper.get('[data-test="regzel-tax-office-code"]').setValue('3000')
    await wrapper.get('[data-test="regzel-tax-office-workplace-code"]').setValue('3001')
    await wrapper.get('[data-test="regzel-payer-reference-number"]').setValue('')
    await wrapper.get('[data-test="regzel-profile-confirmation"]').setValue(true)
    await wrapper.get('[data-test="regzel-profile-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'Profil mezitím změnila Jana.',
    )
  })

  it('vyžaduje pracoviště u běžného úřadu, ale ne u specializovaného', async () => {
    m.saveProfile.mockResolvedValue({
      supplier_id: 1,
      social_enterprise: false,
      employment_agency: false,
      protected_labor_market: false,
      tax_office_code: '4000',
      tax_office_workplace_code: null,
      payer_reference_number: null,
      is_complete: true,
      evidence_confirmed_at: '2026-08-04 12:00:00',
      row_version: 1,
      updated_at: '2026-08-04 12:00:00',
    })
    const wrapper = mount(RegzelProfileSettings, { props: { canWrite: true } })
    await flushPromises()

    await wrapper.get('[data-test="regzel-tax-office-code"]').setValue('3000')
    await wrapper.get('[data-test="regzel-profile-confirmation"]').setValue(true)
    await wrapper.get('[data-test="regzel-profile-save"]').trigger('click')
    expect(m.saveProfile).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toContain(
      'payroll.regzel.profile.tax_office_workplace_code_required',
    )

    await wrapper.get('[data-test="regzel-tax-office-code"]').setValue('4000')
    await wrapper.get('[data-test="regzel-profile-save"]').trigger('click')
    await flushPromises()
    expect(m.saveProfile).toHaveBeenCalledWith(expect.objectContaining({
      tax_office_code: '4000',
      tax_office_workplace_code: null,
    }))
  })
})
