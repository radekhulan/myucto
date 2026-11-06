import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// Certifikát je osobní a firmě se jen povoluje. Účetní s desítkami firem ho při
// obnově nahraje jednou a zaškrtne „Uložit i do dalších firem", volba je ale
// výchozí VYPNUTÁ, aby se certifikát nikam nerozšířil bez vědomého rozhodnutí.

const m = vi.hoisted(() => ({
  uploadCertificate: vi.fn(),
  shareCertificateWithOtherSuppliers: vi.fn(),
  listCertificates: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/settings', () => ({
  settingsApi: {
    getSigningSettings: vi.fn().mockResolvedValue({ accountant_profiles_enabled: false }),
    listCertificates: m.listCertificates,
    listPersonalSigningCertificates: vi.fn().mockResolvedValue([]),
    uploadCertificate: m.uploadCertificate,
    shareCertificateWithOtherSuppliers: m.shareCertificateWithOtherSuppliers,
  },
}))
vi.mock('@/api/auth', () => ({ authApi: {} }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_e: unknown, fallback: string) => fallback }))
vi.mock('@/security/webauthn', () => ({ isWebAuthnAvailable: () => false, getCredential: vi.fn() }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    isCompanyAdminRole: false,
    canRead: () => true,
    user: { id: 7, totp_enabled: false, mfa_methods: [], passkey_count: 0 },
  }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, warning: vi.fn() }),
}))
vi.mock('@/components/ui/EmptyState.vue', () => ({
  default: { name: 'EmptyState', props: ['dense', 'icon', 'title'], template: '<div />' },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import ElectronicSignatures from '@/pages/admin/ElectronicSignatures.vue'

const certificate = {
  id: 41,
  label: 'Syntetický podpis',
  subject_dn: 'CN=Synthetic',
  issuer_dn: 'CN=Synthetic CA',
  serial_hex: '0A',
  fingerprint_sha256: 'ab',
  valid_from: '2026-01-01 00:00:00',
  valid_to: '2027-01-01 00:00:00',
  valid_now: true,
  enabled_for_supplier: true,
  linked_profiles_count: 0,
}

async function mountPage() {
  const wrapper = mount(ElectronicSignatures, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
  await flushPromises()
  return wrapper
}

async function fillUpload(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  const input = wrapper.find('input[type="file"]')
  Object.defineProperty(input.element, 'files', {
    value: [new File(['synthetic'], 'podpis.p12', { type: 'application/x-pkcs12' })],
  })
  await input.trigger('change')
  await wrapper.find('input[autocomplete="new-password"]').setValue('heslo-k-pfx')
  await wrapper.find('input[autocomplete="current-password"]').setValue('heslo-do-aplikace')
}

describe('ElectronicSignatures: uložit i do dalších firem', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listCertificates.mockResolvedValue([])
  })

  it('volba je výchozí vypnutá a nahrání bez ní nic nerozšiřuje', async () => {
    m.uploadCertificate.mockResolvedValue({ ...certificate })
    const wrapper = await mountPage()

    const share = wrapper.find('[data-testid="certificate-share-others"]')
    expect((share.element as HTMLInputElement).checked).toBe(false)

    await fillUpload(wrapper)
    await wrapper.find('[data-testid="certificate-upload"]').trigger('click')
    await flushPromises()

    expect(m.uploadCertificate).toHaveBeenCalledTimes(1)
    expect(m.uploadCertificate.mock.calls[0][0]).toMatchObject({
      shareWithOtherSuppliers: false,
      shareOnlyWithoutValid: false,
    })
    expect(wrapper.find('[data-testid="certificate-sharing-results"]').exists()).toBe(false)
  })

  it('zaškrtnutá volba pošle požadavek a ukáže souhrn po firmách', async () => {
    m.uploadCertificate.mockResolvedValue({
      ...certificate,
      supplier_sharing: [
        { supplier_id: 2, name: 'Synthetic A', status: 'enabled' },
        { supplier_id: 3, name: 'Synthetic B', status: 'skipped_has_valid' },
        { supplier_id: 4, name: 'Synthetic C', status: 'skipped_no_permission' },
      ],
    })
    const wrapper = await mountPage()

    await wrapper.find('[data-testid="certificate-share-others"]').setValue(true)
    await wrapper.find('[data-testid="certificate-share-only-without-valid"]').setValue(true)
    await fillUpload(wrapper)
    await wrapper.find('[data-testid="certificate-upload"]').trigger('click')
    await flushPromises()

    expect(m.uploadCertificate.mock.calls[0][0]).toMatchObject({
      shareWithOtherSuppliers: true,
      shareOnlyWithoutValid: true,
    })
    const results = wrapper.find('[data-testid="certificate-sharing-results"]')
    expect(results.exists()).toBe(true)
    expect(results.text()).toContain('Synthetic A')
    expect(results.text()).toContain('settings.certificate_vault_share_status_enabled')
    expect(results.text()).toContain('settings.certificate_vault_share_status_skipped_has_valid')
    expect(results.text()).toContain('settings.certificate_vault_share_status_skipped_no_permission')
  })

  it('už uložený certifikát jde povolit v dalších firmách s ověřením', async () => {
    m.listCertificates.mockResolvedValue([certificate])
    m.shareCertificateWithOtherSuppliers.mockResolvedValue([
      { supplier_id: 2, name: 'Synthetic A', status: 'already_enabled' },
    ])
    const wrapper = await mountPage()

    await wrapper.find('input[autocomplete="current-password"]').setValue('heslo-do-aplikace')
    await wrapper.find('[data-testid="certificate-share-41"]').trigger('click')
    await flushPromises()

    expect(m.shareCertificateWithOtherSuppliers).toHaveBeenCalledWith(
      41,
      false,
      expect.objectContaining({ password: 'heslo-do-aplikace' }),
      null,
    )
    expect(wrapper.find('[data-testid="certificate-sharing-results"]').text())
      .toContain('settings.certificate_vault_share_status_already_enabled')
  })
})

describe('ElectronicSignatures: nahradit certifikát ve všech firmách', () => {
  const old = { ...certificate, id: 40, label: 'Starý podpis', valid_to: '2026-11-01 00:00:00' }

  beforeEach(() => {
    vi.clearAllMocks()
    m.listCertificates.mockResolvedValue([certificate, old])
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('nový certifikát nahradí vybraný starý a výsledek ukáže, co se přepojilo', async () => {
    m.shareCertificateWithOtherSuppliers.mockResolvedValue([
      { supplier_id: 2, name: 'Synthetic A', status: 'replaced', replaced: { data_box: 1, signing_profiles: 0, payroll: 1 } },
    ])
    const wrapper = await mountPage()

    await wrapper.find('[data-testid="certificate-replace"]').setValue('40')
    await wrapper.find('input[autocomplete="current-password"]').setValue('heslo-do-aplikace')
    await wrapper.find('[data-testid="certificate-share-41"]').trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalledTimes(1)
    expect(m.shareCertificateWithOtherSuppliers).toHaveBeenCalledWith(41, false, expect.anything(), 40)
    const results = wrapper.find('[data-testid="certificate-sharing-results"]').text()
    expect(results).toContain('settings.certificate_vault_share_status_replaced')
    expect(results).toContain('settings.certificate_vault_replace_counts')
    expect(m.toastSuccess).toHaveBeenCalledWith('settings.certificate_vault_replace_done')
  })

  it('bez potvrzení nic nepošle', async () => {
    vi.mocked(window.confirm).mockReturnValue(false)
    const wrapper = await mountPage()

    await wrapper.find('[data-testid="certificate-replace"]').setValue('40')
    await wrapper.find('input[autocomplete="current-password"]').setValue('heslo-do-aplikace')
    await wrapper.find('[data-testid="certificate-share-41"]').trigger('click')
    await flushPromises()

    expect(m.shareCertificateWithOtherSuppliers).not.toHaveBeenCalled()
  })

  it('certifikát nenahradí sám sebe', async () => {
    const wrapper = await mountPage()

    await wrapper.find('[data-testid="certificate-replace"]').setValue('41')
    await wrapper.find('input[autocomplete="current-password"]').setValue('heslo-do-aplikace')
    await wrapper.find('[data-testid="certificate-share-41"]').trigger('click')
    await flushPromises()

    expect(m.shareCertificateWithOtherSuppliers).not.toHaveBeenCalled()
    expect(m.toastError).toHaveBeenCalledWith('settings.certificate_vault_replace_same')
  })

  it('nahrání nového certifikátu pošle, který starý má nahradit', async () => {
    m.uploadCertificate.mockResolvedValue({ ...certificate, id: 42, supplier_sharing: [] })
    const wrapper = await mountPage()

    await wrapper.find('[data-testid="certificate-replace"]').setValue('40')
    await fillUpload(wrapper)
    await wrapper.find('[data-testid="certificate-upload"]').trigger('click')
    await flushPromises()

    expect(m.uploadCertificate.mock.calls[0][0]).toMatchObject({ replaceCredentialId: 40 })
  })
})
