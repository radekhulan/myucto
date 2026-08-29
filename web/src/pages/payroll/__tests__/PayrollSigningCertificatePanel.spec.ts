import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  signingProfile: vi.fn(),
  saveSigningProfile: vi.fn(),
  deleteSigningProfile: vi.fn(),
  canWrite: vi.fn(() => true),
  user: { totp_enabled: false, mfa_methods: [] as string[], passkey_count: 0 },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    signingProfile: m.signingProfile,
    saveSigningProfile: m.saveSigningProfile,
    deleteSigningProfile: m.deleteSigningProfile,
  },
}))

vi.mock('@/api/auth', () => ({
  authApi: {
    passkeyStepUpOptions: vi.fn(),
    passkeyStepUpVerify: vi.fn(),
  },
}))

vi.mock('@/security/webauthn', () => ({
  getCredential: vi.fn(),
  isWebAuthnAvailable: () => false,
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite, user: m.user }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    locale: { value: 'cs' },
  }),
}))

import PayrollSigningCertificatePanel from '@/pages/payroll/PayrollSigningCertificatePanel.vue'

const usable = {
  id: 5,
  label: 'ČSSZ produkce 2026',
  subject: 'CN=Synthetic Test, O=Synthetic',
  issuer: 'CN=Synthetic CA',
  serial_hex: '1f4a3b',
  serial_decimal: '2050619',
  valid_from: '2026-01-01 00:00:00',
  valid_to: '2027-06-30 00:00:00',
  expired: false,
  not_yet_valid: false,
  usable_now: true,
  expires_in_days: 320,
  enabled_for_supplier: true,
  ik_mpsv_present: true,
}

const expiredCertificate = {
  ...usable,
  id: 6,
  label: 'ČSSZ starý',
  serial_hex: 'aa01',
  serial_decimal: '43521',
  valid_to: '2026-02-01 00:00:00',
  expired: true,
  usable_now: false,
  expires_in_days: -180,
}

/** Bez uložené volby není nic předvybrané — certifikát se musí vybrat ručně. */
async function pickFirstCertificate(wrapper: ReturnType<typeof mount>) {
  const combobox = wrapper.get('[data-test="signing-certificate"] input')
  await combobox.trigger('focus')
  await combobox.trigger('keydown', { key: 'ArrowDown' })
  await combobox.trigger('keydown', { key: 'Enter' })
  await flushPromises()
}

function view(overrides: Record<string, unknown> = {}) {
  return {
    environment: 'production',
    environments: ['production', 'test'],
    storage_available: true,
    profile: null,
    certificates: [usable],
    warnings: [],
    ...overrides,
  }
}

describe('PayrollSigningCertificatePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.user.totp_enabled = false
  })

  it('ukáže zvolený certifikát včetně obou zápisů sériového čísla', async () => {
    m.signingProfile.mockResolvedValue(view({
      profile: {
        environment: 'production',
        credential_id: 5,
        owner_user_id: 1,
        cssz_registered_serial: '1f4a3b',
        row_version: 3,
        created_at: '2026-08-01 08:00:00',
        updated_at: '2026-08-02 09:00:00',
        certificate_accessible: true,
        certificate: usable,
        expired: false,
      },
    }))

    const wrapper = mount(PayrollSigningCertificatePanel)
    await flushPromises()

    expect(m.signingProfile).toHaveBeenCalledWith('production')
    expect(wrapper.get('[data-test="signing-current"]').text()).toContain('ČSSZ produkce 2026')
    const detail = wrapper.get('[data-test="signing-certificate-detail"]')
    expect(detail.text()).toContain('1f4a3b')
    expect(wrapper.get('[data-test="signing-serial-decimal"]').text()).toContain('2050619')
    expect(
      (wrapper.get('[data-test="signing-registered-serial"]').element as HTMLInputElement).value,
    ).toBe('1f4a3b')
  })

  it('selhané načtení nikdy nevykreslí jako prázdný trezor', async () => {
    m.signingProfile.mockRejectedValue({
      response: { data: { error: { message: 'Databáze je nedostupná.' } } },
    })

    const wrapper = mount(PayrollSigningCertificatePanel)
    await flushPromises()

    expect(wrapper.get('[data-test="signing-load-error"]').text())
      .toContain('Databáze je nedostupná.')
    expect(wrapper.get('[data-test="signing-load-error"]').text())
      .toContain('payroll.submissions.signing.state_unknown')
    expect(wrapper.find('[data-test="signing-vault-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="signing-current-none"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="signing-save"]').exists()).toBe(false)
  })

  it('prázdný trezor pošle uživatele tam, kde se certifikáty nahrávají', async () => {
    m.signingProfile.mockResolvedValue(view({ certificates: [] }))

    const wrapper = mount(PayrollSigningCertificatePanel)
    await flushPromises()

    expect(wrapper.get('[data-test="signing-vault-empty"]').text())
      .toContain('payroll.submissions.signing.vault.empty')
    expect(wrapper.find('[data-test="signing-current-none"]').exists()).toBe(true)
  })

  it('expirovaný certifikát označí a varování ze serveru vypíše', async () => {
    m.signingProfile.mockResolvedValue(view({
      certificates: [expiredCertificate],
      profile: {
        environment: 'production',
        credential_id: 6,
        owner_user_id: 1,
        cssz_registered_serial: null,
        row_version: 1,
        created_at: null,
        updated_at: null,
        certificate_accessible: true,
        certificate: expiredCertificate,
        expired: true,
      },
      warnings: [
        { code: 'certificate_expired', message: 'Zvolený certifikát už vypršel.' },
        { code: 'cssz_serial_missing', message: 'Není vyplněné sériové číslo.' },
      ],
    }))

    const wrapper = mount(PayrollSigningCertificatePanel)
    await flushPromises()

    const warnings = wrapper.get('[data-test="signing-warnings"]')
    expect(warnings.text()).toContain('Zvolený certifikát už vypršel.')
    expect(warnings.text()).toContain('Není vyplněné sériové číslo.')
    expect(warnings.findAll('p')[0]!.classes().join(' ')).toContain('danger')
    expect(warnings.findAll('p')[1]!.classes().join(' ')).toContain('warning')
    expect(wrapper.get('[data-test="signing-certificate-detail"]').text())
      .toContain('payroll.submissions.signing.badge.expired')
  })

  it('přepnutí prostředí načte volbu znovu — testovací certifikát bývá jiný', async () => {
    m.signingProfile.mockResolvedValue(view())

    const wrapper = mount(PayrollSigningCertificatePanel)
    await flushPromises()

    await wrapper.get('[data-test="signing-environment-test"]').trigger('click')
    await flushPromises()

    expect(m.signingProfile).toHaveBeenLastCalledWith('test')
    expect(wrapper.get('[data-test="signing-environment-note"]').text())
      .toContain('payroll.submissions.signing.environment.test_note')
  })

  // ⚠️ Panel montovaný sám o sobě tuhle chybu neukáže: `defineModel` je bez
  // vazby na rodiče lokální ref, takže se zápis přečte hned. V aplikaci ho
  // rodič váže přes `v-model:environment` a hodnota dorazí až v dalším ticku
  // — dotaz pak odešel se starým prostředím a pomohlo až ruční Obnovit.
  it('přepnutí prostředí načte volbu znovu i pod v-model rodiče', async () => {
    m.signingProfile.mockResolvedValue(view())

    const parent = defineComponent({
      components: { PayrollSigningCertificatePanel },
      setup: () => ({ environment: ref('production') }),
      template: '<PayrollSigningCertificatePanel v-model:environment="environment" />',
    })

    const wrapper = mount(parent)
    await flushPromises()

    await wrapper.get('[data-test="signing-environment-test"]').trigger('click')
    await flushPromises()

    expect(m.signingProfile).toHaveBeenLastCalledWith('test')
  })

  it('bez step-up důkazu neuloží a řekne proč', async () => {
    m.signingProfile.mockResolvedValue(view({
      profile: {
        environment: 'production',
        credential_id: 5,
        owner_user_id: 1,
        cssz_registered_serial: null,
        row_version: 2,
        created_at: null,
        updated_at: null,
        certificate_accessible: true,
        certificate: usable,
        expired: false,
      },
    }))

    const wrapper = mount(PayrollSigningCertificatePanel)
    await flushPromises()

    await wrapper.get('[data-test="signing-save"]').trigger('click')
    await flushPromises()

    expect(m.saveSigningProfile).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="signing-error"]').text())
      .toContain('payroll.submissions.signing.step_up.password_missing')
  })

  it('uloží volbu se sériovým číslem, heslem a verzí řádku', async () => {
    m.signingProfile.mockResolvedValue(view({
      profile: {
        environment: 'production',
        credential_id: 5,
        owner_user_id: 1,
        cssz_registered_serial: null,
        row_version: 2,
        created_at: null,
        updated_at: null,
        certificate_accessible: true,
        certificate: usable,
        expired: false,
      },
      warnings: [{ code: 'cssz_serial_missing', message: 'Není vyplněné sériové číslo.' }],
    }))
    m.saveSigningProfile.mockResolvedValue({
      environment: 'production',
      profile: {
        environment: 'production',
        credential_id: 5,
        owner_user_id: 1,
        cssz_registered_serial: '1f4a3b',
        row_version: 3,
        created_at: null,
        updated_at: '2026-08-14 10:00:00',
        certificate_accessible: true,
        certificate: usable,
        expired: false,
      },
      warnings: [],
    })

    const wrapper = mount(PayrollSigningCertificatePanel)
    await flushPromises()

    await wrapper.get('[data-test="signing-registered-serial"]').setValue(' 2050619 ')
    await wrapper.get('[data-test="signing-password"]').setValue('tajne-heslo')
    await wrapper.get('[data-test="signing-save"]').trigger('click')
    await flushPromises()

    expect(m.saveSigningProfile).toHaveBeenCalledWith(
      {
        environment: 'production',
        credential_id: 5,
        cssz_registered_serial: '2050619',
        row_version: 2,
      },
      { password: 'tajne-heslo', totp_code: undefined, step_up_token: undefined },
    )
    expect(wrapper.get('[data-test="signing-success"]').text())
      .toContain('payroll.submissions.signing.saved')
    expect(wrapper.find('[data-test="signing-warnings"]').exists()).toBe(false)
  })

  it('neshodu sériového čísla vypíše a stav nevyprázdní', async () => {
    m.signingProfile.mockResolvedValue(view())
    m.saveSigningProfile.mockRejectedValue({
      response: {
        data: {
          error: { message: 'Sériové číslo registrované u ČSSZ neodpovídá certifikátu.' },
        },
      },
    })

    const wrapper = mount(PayrollSigningCertificatePanel)
    await flushPromises()

    await pickFirstCertificate(wrapper)
    await wrapper.get('[data-test="signing-password"]').setValue('tajne-heslo')
    await wrapper.get('[data-test="signing-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="signing-error"]').text())
      .toContain('Sériové číslo registrované u ČSSZ neodpovídá certifikátu.')
    expect(wrapper.find('[data-test="signing-certificate-detail"]').exists()).toBe(true)
  })

  it('u nové volby row_version vůbec neposílá', async () => {
    m.signingProfile.mockResolvedValue(view())
    m.saveSigningProfile.mockResolvedValue({
      environment: 'production',
      profile: {
        environment: 'production',
        credential_id: 5,
        owner_user_id: 1,
        cssz_registered_serial: null,
        row_version: 1,
        created_at: null,
        updated_at: null,
        certificate_accessible: true,
        certificate: usable,
        expired: false,
      },
      warnings: [],
    })

    const wrapper = mount(PayrollSigningCertificatePanel)
    await flushPromises()

    await pickFirstCertificate(wrapper)
    await wrapper.get('[data-test="signing-password"]').setValue('tajne-heslo')
    await wrapper.get('[data-test="signing-save"]').trigger('click')
    await flushPromises()

    expect(m.saveSigningProfile).toHaveBeenCalledWith(
      expect.objectContaining({ credential_id: 5, row_version: null }),
      expect.anything(),
    )
  })

  it('zrušení volby vyžaduje step-up a vyčistí zobrazený stav', async () => {
    m.signingProfile.mockResolvedValue(view({
      profile: {
        environment: 'production',
        credential_id: 5,
        owner_user_id: 1,
        cssz_registered_serial: '1f4a3b',
        row_version: 2,
        created_at: null,
        updated_at: null,
        certificate_accessible: true,
        certificate: usable,
        expired: false,
      },
    }))
    m.deleteSigningProfile.mockResolvedValue({ environment: 'production', deleted: true })

    const wrapper = mount(PayrollSigningCertificatePanel)
    await flushPromises()

    await wrapper.get('[data-test="signing-remove"]').trigger('click')
    await flushPromises()
    expect(m.deleteSigningProfile).not.toHaveBeenCalled()

    await wrapper.get('[data-test="signing-password"]').setValue('tajne-heslo')
    await wrapper.get('[data-test="signing-remove"]').trigger('click')
    await flushPromises()

    expect(m.deleteSigningProfile).toHaveBeenCalledWith('production', {
      password: 'tajne-heslo',
      totp_code: undefined,
      step_up_token: undefined,
    })
    expect(wrapper.find('[data-test="signing-current-none"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="signing-remove"]').exists()).toBe(false)
  })

  it('v režimu jen pro čtení nenabídne uložení', async () => {
    m.canWrite.mockReturnValue(false)
    m.signingProfile.mockResolvedValue(view())

    const wrapper = mount(PayrollSigningCertificatePanel)
    await flushPromises()

    expect(wrapper.find('[data-test="signing-save"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.submissions.signing.read_only')
  })
})
