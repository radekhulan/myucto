import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { DataBoxCredential, SharedCertificateOption } from '@/api/dataBox'

/**
 * Systémový certifikát datové schránky se vybírá ze sdíleného trezoru.
 *
 * Proč to hlídat testem: aplikace má JEDEN trezor certifikátů (Systém →
 * Elektronické podpisy) a mzdová podání z něj jen vybírají. Datovka si dosud
 * držela vlastní kopii, takže uživatel nahrával týž klíč podruhé — a při obnově
 * ho měnil na víc místech, aniž by kterékoli z nich řeklo, že ta zbylá jsou
 * prošlá. Test drží čtyři věci, na kterých to celé stojí:
 *   1. nabídka existuje a odešle `credential_id`, ne soubor,
 *   2. nahrání souboru zůstává jako dosud a `credential_id` neposílá,
 *   3. prošlý certifikát je v nabídce vidět jako prošlý,
 *   4. osiřelý odkaz se přizná v kartě, ne až v odmítnutém podání.
 */

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  sharedCertificates: vi.fn(),
  saveCredential: vi.fn(),
  recipients: vi.fn(),
  outbox: vi.fn(),
  inbox: vi.fn(),
  mobileKeyProfile: vi.fn(),
  unmatchedReceipts: vi.fn(),
  defectNotices: vi.fn(),
  gatewayCapabilities: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  toastInfo: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    credentials: m.credentials,
    sharedCertificates: m.sharedCertificates,
    saveCredential: m.saveCredential,
    recipients: m.recipients,
    outbox: m.outbox,
    inbox: m.inbox,
    mobileKeyProfile: m.mobileKeyProfile,
    unmatchedReceipts: m.unmatchedReceipts,
    defectNotices: m.defectNotices,
    gatewayCapabilities: m.gatewayCapabilities,
  },
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatUtcDateTime: (value: string) => value }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, info: m.toastInfo }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { company_name: 'Testovací firma' } }),
}))

import DataBox from '../DataBox.vue'

function credential(overrides: Partial<DataBoxCredential> = {}): DataBoxCredential {
  return {
    id: 1,
    supplier_id: 1,
    environment: 'production',
    channel: 'isds',
    label: 'Naše schránka',
    box_id: 'abcdefg',
    auth_mode: 'certificate',
    credential_id: null,
    credential_label: null,
    credential_subject: null,
    credential_missing: false,
    certificate_fingerprint: null,
    certificate_valid_to: null,
    last_verified_at: null,
    inbox_polling_enabled: false,
    inbox_polling_enabled_at: null,
    inbox_polling_enabled_by: null,
    ...overrides,
  }
}

const valid: SharedCertificateOption = {
  id: 7,
  label: 'Kvalifikovaný certifikát 2026',
  subject: 'CN=Testovaci firma',
  fingerprint: 'a1b2c3',
  valid_from: '2026-01-01 00:00:00',
  valid_to: '2027-01-01 00:00:00',
  expired: false,
  valid_now: true,
}

const expired: SharedCertificateOption = {
  id: 8,
  label: 'Starý certifikát',
  subject: 'CN=Testovaci firma',
  fingerprint: 'd4e5f6',
  valid_from: '2023-01-01 00:00:00',
  valid_to: '2024-01-01 00:00:00',
  expired: true,
  valid_now: false,
}

beforeEach(() => {
  vi.clearAllMocks()
  m.credentials.mockResolvedValue([])
  m.sharedCertificates.mockResolvedValue([valid, expired])
  m.saveCredential.mockResolvedValue(credential())
  m.recipients.mockResolvedValue([])
  m.outbox.mockResolvedValue([])
  m.inbox.mockResolvedValue({ items: [], state: null })
  m.unmatchedReceipts.mockResolvedValue([])
  m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
  m.defectNotices.mockResolvedValue({ items: [], supported: true, notice: null })
  m.gatewayCapabilities.mockResolvedValue([])

  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { assign: vi.fn(), search: '', pathname: '/admin/databox' },
  })
  Object.defineProperty(window, 'history', {
    configurable: true,
    value: { replaceState: vi.fn() },
  })
})

afterEach(() => {
  vi.clearAllMocks()
})

/** Stránka startuje rovnou na záložce Přístup, kde certifikát je. */
async function mountPage() {
  const wrapper = mount(DataBox)
  await flushPromises()
  await wrapper.vm.$nextTick()

  return wrapper
}

async function fillIdentification(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  const inputs = wrapper.findAll('input[type="text"]')
  await inputs[0].setValue('Naše schránka')
  await inputs[1].setValue('abcdefg')
}

async function save(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  const button = wrapper.findAll('button').find(b => b.text().includes('common.save'))
  await button!.trigger('click')
  await flushPromises()
}

describe('výběr certifikátu ze sdíleného trezoru', () => {
  it('nabídne certifikáty z trezoru a odešle jen jejich id, ne soubor', async () => {
    const wrapper = await mountPage()

    const select = wrapper.find('[data-test="databox-shared-certificate"]')
    expect(select.exists()).toBe(true)
    expect(select.findAll('option').length).toBe(3)

    await fillIdentification(wrapper)
    await select.setValue('7')
    await save(wrapper)

    expect(m.saveCredential).toHaveBeenCalledTimes(1)
    const form = m.saveCredential.mock.calls[0][0] as FormData
    expect(form.get('credential_id')).toBe('7')
    expect(form.get('certificate')).toBeNull()
    expect(form.get('certificate_password')).toBeNull()
  })

  it('ukáže u vybraného certifikátu, do kdy platí', async () => {
    const wrapper = await mountPage()

    await wrapper.find('[data-test="databox-shared-certificate"]').setValue('7')
    await wrapper.vm.$nextTick()

    const detail = wrapper.find('[data-test="databox-shared-certificate-detail"]')
    expect(detail.text()).toContain('databox.access.validTo')
    expect(detail.text()).toContain('2027-01-01 00:00:00')
    expect(detail.text()).not.toContain('databox.access.sharedCertificateExpired')
  })

  it('prošlý certifikát v nabídce odliší', async () => {
    const wrapper = await mountPage()

    const options = wrapper.find('[data-test="databox-shared-certificate"]').findAll('option')
    expect(options[1].text()).toContain('databox.access.sharedCertificateOption')
    expect(options[2].text()).toContain('databox.access.sharedCertificateExpiredOption')

    await wrapper.find('[data-test="databox-shared-certificate"]').setValue('8')
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-test="databox-shared-certificate-detail"]').text())
      .toContain('databox.access.sharedCertificateExpired')
  })

  it('bez vybraného certifikátu neuloží nic a řekne proč', async () => {
    const wrapper = await mountPage()

    await fillIdentification(wrapper)
    await save(wrapper)

    expect(m.saveCredential).not.toHaveBeenCalled()
    expect(m.toastError).toHaveBeenCalledWith('databox.errors.certificateSelectionRequired')
  })

  it('nahrání souboru zůstává a odkaz do trezoru neposílá', async () => {
    const wrapper = await mountPage()

    await wrapper.find('[data-test="databox-cert-source-file"]').setValue()
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-test="databox-shared-certificate"]').exists()).toBe(false)
    const fileInput = wrapper.find('input[type="file"]')
    expect(fileInput.exists()).toBe(true)

    await fillIdentification(wrapper)
    // Bez souboru se neukládá — chování zůstává jako dosud.
    await save(wrapper)
    expect(m.saveCredential).not.toHaveBeenCalled()
    expect(m.toastError).toHaveBeenCalledWith('databox.errors.certificateRequired')
  })

  it('když v trezoru nic použitelného není, zbude nahrání souboru', async () => {
    m.sharedCertificates.mockResolvedValue([])
    const wrapper = await mountPage()

    expect(wrapper.find('[data-test="databox-shared-certificate"]').exists()).toBe(false)
    expect(wrapper.find('input[type="file"]').exists()).toBe(true)
  })

  it('osiřelý odkaz přizná karta, ne až odmítnuté podání', async () => {
    m.credentials.mockResolvedValue([
      credential({ credential_id: 99, credential_label: null, credential_missing: true }),
    ])
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('databox.access.sharedCertificateMissing')
  })

  it('u navázaného přístupu ukáže, který certifikát z trezoru se používá', async () => {
    m.credentials.mockResolvedValue([
      credential({
        credential_id: 7,
        credential_label: 'Kvalifikovaný certifikát 2026',
        credential_subject: 'CN=Testovaci firma',
        certificate_valid_to: '2027-01-01 00:00:00',
      }),
    ])
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('databox.access.sharedCertificateInUse')
    expect(wrapper.text()).toContain('2027-01-01 00:00:00')
    expect(wrapper.text()).not.toContain('databox.access.sharedCertificateMissing')
  })
})
