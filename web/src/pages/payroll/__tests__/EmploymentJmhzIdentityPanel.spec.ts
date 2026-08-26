import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  save: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzIdentity: m.get,
    saveJmhzIdentity: m.save,
  },
}))

vi.mock('@/api/errors', () => ({
  apiErrorMessage: (exception: unknown, fallback: string) =>
    (exception as { message?: string }).message ?? fallback,
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import EmploymentJmhzIdentityPanel from '@/pages/payroll/EmploymentJmhzIdentityPanel.vue'

const missing = {
  employee_id: 7,
  employment_id: 17,
  environment: 'test' as const,
  on_date: '2026-08-26',
  person_external_identifier: null,
  employment_external_identifier: null,
}

const complete = {
  ...missing,
  on_date: '2026-08-01',
  person_external_identifier: {
    id: 71,
    value_masked: '******0001',
    valid_from: '2026-08-01',
    valid_to: null,
    source_kind: 'verified_manual_import',
    row_version: 1,
  },
  employment_external_identifier: {
    id: 72,
    value_masked: '******************0002',
    valid_from: '2026-08-01',
    valid_to: null,
    source_kind: 'verified_manual_import',
    row_version: 1,
  },
}

function mountPanel(canWriteEmployment = true, canWritePerson = true) {
  return mount(EmploymentJmhzIdentityPanel, {
    props: {
      employmentId: 17,
      startDate: '2026-08-01',
      endDate: null,
      canWriteEmployment,
      canWritePerson,
    },
  })
}

async function openPanel(wrapper: ReturnType<typeof mountPanel>) {
  const details = wrapper.get('details')
  ;(details.element as HTMLDetailsElement).open = true
  await details.trigger('toggle')
  await flushPromises()
}

describe('EmploymentJmhzIdentityPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.get.mockResolvedValue(missing)
    m.save.mockResolvedValue({
      person_external_identifier: { created: true },
      employment_external_identifier: { created: true },
    })
  })

  it('načte identifikátory až po vědomém otevření panelu', async () => {
    const wrapper = mountPanel()
    expect(m.get).not.toHaveBeenCalled()

    await openPanel(wrapper)

    expect(m.get).toHaveBeenCalledTimes(1)
    expect(m.get).toHaveBeenCalledWith(17, 'test', expect.any(String))
    expect(wrapper.find('[data-test="jmhz-identity-form"]').exists()).toBe(true)
  })

  it('po změně prostředí zahodí rozepsané osobní identifikátory i potvrzení', async () => {
    const wrapper = mountPanel()
    await openPanel(wrapper)
    await wrapper.get('[data-test="jmhz-person-identifier"]').setValue('1000000001')
    await wrapper.get('[data-test="jmhz-employment-identifier"]').setValue('200000000000000000002')
    await wrapper.get('[data-test="jmhz-identity-confirmed"]').setValue(true)

    const environment = wrapper.findAllComponents({ name: 'SearchableSelect' })[0]
    if (environment === undefined) throw new Error('Výběr prostředí nebyl nalezen.')
    environment.vm.$emit('update:modelValue', 'production')
    await flushPromises()

    expect((wrapper.get('[data-test="jmhz-person-identifier"]').element as HTMLInputElement).value)
      .toBe('')
    expect((wrapper.get('[data-test="jmhz-employment-identifier"]').element as HTMLInputElement).value)
      .toBe('')
    expect((wrapper.get('[data-test="jmhz-identity-confirmed"]').element as HTMLInputElement).checked)
      .toBe(false)
    expect(m.get).toHaveBeenLastCalledWith(17, 'production', expect.any(String))
  })

  it('uloží oba identifikátory bez povinného odkazu na zdroj a znovu načte jen masky', async () => {
    m.get
      .mockResolvedValueOnce(missing)
      .mockResolvedValueOnce(complete)
    const wrapper = mountPanel()
    await openPanel(wrapper)
    await wrapper.get('[data-test="jmhz-person-identifier"]').setValue('1000000001')
    await wrapper.get('[data-test="jmhz-employment-identifier"]').setValue('200000000000000000002')
    await wrapper.get('[data-test="jmhz-identity-confirmed"]').setValue(true)
    await wrapper.get('[data-test="jmhz-identity-form"]').trigger('submit')
    await flushPromises()

    expect(m.save).toHaveBeenCalledWith(17, {
      environment: 'test',
      person_external_identifier: '1000000001',
      employment_external_identifier: '200000000000000000002',
      valid_from: '2026-08-01',
      source_reference: null,
      evidence_confirmed: true,
    })
    expect(m.get).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('******0001')
    expect(wrapper.text()).toContain('******************0002')
    expect(wrapper.text()).not.toContain('1000000001')
    expect(wrapper.text()).not.toContain('200000000000000000002')
  })

  it('nepovolí zápis bez obou personálních oprávnění', async () => {
    const wrapper = mountPanel(true, false)
    await openPanel(wrapper)
    await wrapper.get('[data-test="jmhz-person-identifier"]').setValue('1000000001')
    await wrapper.get('[data-test="jmhz-employment-identifier"]').setValue('200000000000000000002')
    await wrapper.get('[data-test="jmhz-identity-confirmed"]').setValue(true)

    expect(wrapper.get('[data-test="jmhz-identity-save"]').attributes('disabled'))
      .toBeDefined()
    expect(wrapper.text()).toContain('payroll.people.jmhz_identity.permission_required')
  })
})
