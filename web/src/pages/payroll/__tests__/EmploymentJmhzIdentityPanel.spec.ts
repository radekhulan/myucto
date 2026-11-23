import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  save: vi.fn(),
  reveal: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzIdentity: m.get,
    saveJmhzIdentity: m.save,
    revealJmhzIdentity: m.reveal,
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

vi.mock('@/composables/useFormat', () => ({
  formatDate: (value: string) => value,
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

function mountPanel(
  canWriteEmployment = true,
  canWritePerson = true,
  canReadSensitive = false,
) {
  return mount(EmploymentJmhzIdentityPanel, {
    props: {
      employmentId: 17,
      startDate: '2026-08-01',
      endDate: null,
      canWriteEmployment,
      canWritePerson,
      canReadSensitive,
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
    setActivePinia(createPinia())
    useAuthStore().submissionTestEnvironmentAllowed = true
    m.get.mockResolvedValue(missing)
    m.save.mockResolvedValue({
      person_external_identifier: { created: true },
      employment_external_identifier: { created: true },
    })
    m.reveal.mockResolvedValue({
      person_external_identifier: { id: 71, value: '1000000001' },
      employment_external_identifier: { id: 72, value: '200000000000000000002' },
    })
  })

  /*
   * Bez „Zobrazit" vedla k hodnotě jediná cesta — „Opravit", které ji přepíše.
   * Porovnat číslo s protokolem ČSSZ tak stálo zahození správné hodnoty.
   */
  it('odkryje jen ten identifikátor, o který si uživatel řekl', async () => {
    m.get.mockResolvedValue(complete)
    const wrapper = mountPanel(true, true, true)
    await openPanel(wrapper)

    await wrapper.get('[data-test="jmhz-identity-reveal-person"]').trigger('click')
    await flushPromises()

    expect(m.reveal).toHaveBeenCalledWith(17, 'production', expect.any(String))
    expect(wrapper.text()).toContain('1000000001')
    expect(wrapper.text()).not.toContain('200000000000000000002')

    await wrapper.get('[data-test="jmhz-identity-reveal-person"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).not.toContain('1000000001')
    expect(wrapper.text()).toContain('******0001')
  })

  it('bez práva na citlivé údaje tlačítko vůbec nenabídne', async () => {
    m.get.mockResolvedValue(complete)
    const wrapper = mountPanel()
    await openPanel(wrapper)

    expect(wrapper.find('[data-test="jmhz-identity-reveal-person"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="jmhz-identity-reveal-employment"]').exists()).toBe(false)
  })

  /* Odkrytá hodnota patří k prostředí a dni, ke kterému se načetl stav. */
  it('po přepnutí prostředí odkrytou hodnotu zahodí', async () => {
    m.get.mockResolvedValue(complete)
    const wrapper = mountPanel(true, true, true)
    await openPanel(wrapper)
    await wrapper.get('[data-test="jmhz-identity-reveal-employment"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('200000000000000000002')

    await wrapper.get('[data-test="jmhz-identity-environment"] [data-test="environment-switch-test"]')
      .trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toContain('200000000000000000002')
    expect(wrapper.text()).toContain('******************0002')
  })

  it('načte identifikátory až po vědomém otevření panelu', async () => {
    const wrapper = mountPanel()
    expect(m.get).not.toHaveBeenCalled()

    await openPanel(wrapper)

    expect(m.get).toHaveBeenCalledTimes(1)
    expect(m.get).toHaveBeenCalledWith(17, 'production', expect.any(String))
    expect(wrapper.find('[data-test="jmhz-identity-form"]').exists()).toBe(true)
  })

  it('po změně prostředí zahodí rozepsané osobní identifikátory i potvrzení', async () => {
    const wrapper = mountPanel()
    await openPanel(wrapper)
    await wrapper.get('[data-test="jmhz-person-identifier"]').setValue('1000000001')
    await wrapper.get('[data-test="jmhz-employment-identifier"]').setValue('200000000000000000002')
    await wrapper.get('[data-test="jmhz-identity-confirmed"]').setValue(true)

    await wrapper.get('[data-test="jmhz-identity-environment"] [data-test="environment-switch-test"]')
      .trigger('click')
    await flushPromises()

    expect((wrapper.get('[data-test="jmhz-person-identifier"]').element as HTMLInputElement).value)
      .toBe('')
    expect((wrapper.get('[data-test="jmhz-employment-identifier"]').element as HTMLInputElement).value)
      .toBe('')
    expect((wrapper.get('[data-test="jmhz-identity-confirmed"]').element as HTMLInputElement).checked)
      .toBe(false)
    expect(m.get).toHaveBeenLastCalledWith(17, 'test', expect.any(String))
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
      environment: 'production',
      person_external_identifier: '1000000001',
      employment_external_identifier: '200000000000000000002',
      valid_from: '2026-08-01',
      source_reference: null,
      evidence_confirmed: true,
      replace_existing: false,
    })
    expect(m.get).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('******0001')
    expect(wrapper.text()).toContain('******************0002')
    expect(wrapper.text()).not.toContain('1000000001')
    expect(wrapper.text()).not.toContain('200000000000000000002')
  })

  /*
   * Obě čísla se opisují ručně z protokolu ČSSZ, takže překlep je běžný.
   * Dokud se formulář po uložení schoval, nešla chyba opravit vůbec — a chybné
   * číslo se pak vezlo v každém dalším měsíčním hlášení.
   */
  it('nabídne opravu už uloženého čísla a pošle ji jako náhradu', async () => {
    m.get.mockResolvedValue(complete)
    const wrapper = mountPanel()
    await openPanel(wrapper)

    expect(wrapper.find('[data-test="jmhz-identity-form"]').exists()).toBe(false)
    await wrapper.get('[data-test="jmhz-identity-correct-person"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="jmhz-identity-correction-hint"]').exists()).toBe(true)
    await wrapper.get('[data-test="jmhz-person-identifier"]').setValue('1000000002')
    await wrapper.get('[data-test="jmhz-identity-confirmed"]').setValue(true)
    await wrapper.get('[data-test="jmhz-identity-form"]').trigger('submit')
    await flushPromises()

    expect(m.save).toHaveBeenCalledWith(17, {
      environment: 'production',
      person_external_identifier: '1000000002',
      employment_external_identifier: null,
      valid_from: '2026-08-01',
      source_reference: null,
      evidence_confirmed: true,
      replace_existing: true,
    })
  })

  it('opravu jde vzít zpět, aniž by se cokoliv odeslalo', async () => {
    m.get.mockResolvedValue(complete)
    const wrapper = mountPanel()
    await openPanel(wrapper)

    await wrapper.get('[data-test="jmhz-identity-correct-employment"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="jmhz-employment-identifier"]').exists()).toBe(true)

    await wrapper.get('[data-test="jmhz-identity-cancel-correction"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="jmhz-identity-form"]').exists()).toBe(false)
    expect(m.save).not.toHaveBeenCalled()
  })

  it('u zašedlého Uložit řekne, co konkrétně chybí', async () => {
    const wrapper = mountPanel()
    await openPanel(wrapper)

    const reason = () => wrapper.get('[data-test="jmhz-identity-save-reason"]').text()
    expect(reason()).toBe('payroll.people.jmhz_identity.identifier_required')

    await wrapper.get('[data-test="jmhz-person-identifier"]').setValue('1000000001')
    expect(reason()).toBe('payroll.people.jmhz_identity.confirm_required')

    await wrapper.get('[data-test="jmhz-identity-confirmed"]').setValue(true)
    expect(wrapper.find('[data-test="jmhz-identity-save-reason"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="jmhz-identity-save"]').attributes('disabled'))
      .toBeUndefined()
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

  /*
   * Holé „Chybí" svádí k ručnímu opisu čísla, které ještě nikdo nepřidělil:
   * přihláška teprve čeká ve frontě nebo na protokol ČSSZ.
   */
  it('u přihlášky na cestě řekne, na co se čeká, místo výzvy k doplnění', async () => {
    m.get.mockResolvedValue({
      ...missing,
      pending_registration: { submission_id: 31, state: 'awaiting_result', submitted_on: '2026-11-11' },
    })
    const wrapper = mountPanel()
    await openPanel(wrapper)

    expect(wrapper.get('[data-test="jmhz-identity-pending-registration"]').text())
      .toBe('payroll.people.jmhz_identity.pending_awaiting')
    expect(wrapper.text()).toContain('payroll.people.jmhz_identity.pending_badge.awaiting_result')
    expect(wrapper.text()).not.toContain('payroll.people.jmhz_identity.incomplete')

    m.get.mockResolvedValue({
      ...missing,
      pending_registration: { submission_id: 31, state: 'queued', submitted_on: null },
    })
    const queued = mountPanel()
    await openPanel(queued)

    expect(queued.get('[data-test="jmhz-identity-pending-registration"]').text())
      .toBe('payroll.people.jmhz_identity.pending_queued')
    expect(queued.text()).toContain('payroll.people.jmhz_identity.pending_badge.queued')
  })

  it('bez přihlášky na cestě zůstane výzva k doplnění', async () => {
    const wrapper = mountPanel()
    await openPanel(wrapper)

    expect(wrapper.find('[data-test="jmhz-identity-pending-registration"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.people.jmhz_identity.incomplete')
  })
})
