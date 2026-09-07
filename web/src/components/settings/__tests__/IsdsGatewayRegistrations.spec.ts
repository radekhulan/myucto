import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { IsdsGatewayRegistration } from '@/api/dataBox'

/**
 * Správa registrací odesílací brány ISDS.
 *
 * Co tenhle test hlídá — samé věci, které je tady drahé zkazit:
 *   1. založení pošle přesně ta pole, která brána vyžaduje, a heslo
 *      k certifikátu se do formuláře nikdy nevrací zpět,
 *   2. zapnutí ani smazání se nestane na první kliknutí — obojí je výrazný
 *      krok a potvrzuje se dialogem, ne `confirm()`,
 *   3. `login_policy = unknown` je legitimní stav, ne porucha: obrazovka na něm
 *      nespadne a řekne nahlas, že to zatím není ověřené,
 *   4. návratová adresa je autentizovaný frontend callback, ne endpoint API.
 */

const m = vi.hoisted(() => ({
  gatewaySettings: vi.fn(),
  saveGatewayRegistration: vi.fn(),
  setGatewayActive: vi.fn(),
  deleteGatewayRegistration: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  toastInfo: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    gatewaySettings: m.gatewaySettings,
    saveGatewayRegistration: m.saveGatewayRegistration,
    setGatewayActive: m.setGatewayActive,
    deleteGatewayRegistration: m.deleteGatewayRegistration,
  },
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, info: m.toastInfo }),
}))

import IsdsGatewayRegistrations from '../IsdsGatewayRegistrations.vue'

const DEFAULT_HOSTS = {
  production: { portal: 'datovka.gov.cz', service: 'cert.datovka.gov.cz' },
  test: { portal: 'datovka-test.gov.cz', service: 'cert.datovka-test.gov.cz' },
}

function registration(overrides: Partial<IsdsGatewayRegistration> = {}): IsdsGatewayRegistration {
  return {
    id: 1,
    environment: 'test',
    ats_id: 'ATS-1',
    label: 'MyÚčto brána',
    return_url: 'https://dev.example.test/isds-gateway/callback',
    error_url: null,
    concept_ttl_seconds: 900,
    portal_host: 'datovka-test.gov.cz',
    service_host: 'cert.datovka-test.gov.cz',
    user_login_policy: 'unknown',
    certificate_fingerprint: 'ab'.repeat(32),
    certificate_valid_to: '2030-01-01 00:00:00',
    is_active: false,
    ...overrides,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  m.gatewaySettings.mockResolvedValue({ items: [], default_hosts: DEFAULT_HOSTS })
  m.saveGatewayRegistration.mockResolvedValue(registration())
  m.setGatewayActive.mockResolvedValue({ environment: 'test', active: true })
  m.deleteGatewayRegistration.mockResolvedValue({ deleted: true })
})

async function mountSection(items: IsdsGatewayRegistration[] = []) {
  m.gatewaySettings.mockResolvedValue({ items, default_hosts: DEFAULT_HOSTS })
  const wrapper = mount(IsdsGatewayRegistrations)
  await flushPromises()

  return wrapper
}

function findButton(wrapper: ReturnType<typeof mount>, text: string) {
  return wrapper.findAll('button').find(b => b.text().includes(text))
}

/** jsdom neumí `input.files` naplnit uživatelsky — nastavíme ho a pošleme change. */
async function chooseCertificate(wrapper: ReturnType<typeof mount>, file: File) {
  const input = wrapper.find('[data-test="gw-certificate"]')
  Object.defineProperty(input.element, 'files', { configurable: true, value: [file] })
  await input.trigger('change')
}

describe('registrace odesílací brány — založení', () => {
  it('ukáže přesnou návratovou adresu na frontend, ne na API', async () => {
    const wrapper = await mountSection()

    const expected = `${window.location.origin}/isds-gateway/callback`
    expect(wrapper.text()).toContain(expected)
    expect(wrapper.text()).not.toContain('/api/submissions/gateway/callback')
  })

  it('uloží registraci se všemi poli brány a se souborem certifikátu', async () => {
    const wrapper = await mountSection()

    await findButton(wrapper, 'databox.gateway.registrations.add')!.trigger('click')
    await flushPromises()

    const file = new File(['pkcs12'], 'gateway.p12')
    await wrapper.find('[data-test="gw-label"]').setValue('Provozní brána')
    await wrapper.find('[data-test="gw-ats-id"]').setValue('ATS-42')
    await wrapper.find('[data-test="gw-ttl"]').setValue('600')
    await wrapper.find('[data-test="gw-password"]').setValue('tajne-heslo')
    await chooseCertificate(wrapper, file)

    await findButton(wrapper, 'common.save')!.trigger('click')
    await flushPromises()

    expect(m.saveGatewayRegistration).toHaveBeenCalledWith(expect.objectContaining({
      environment: 'test',
      ats_id: 'ATS-42',
      label: 'Provozní brána',
      return_url: `${window.location.origin}/isds-gateway/callback`,
      concept_ttl_seconds: 600,
      portal_host: 'datovka-test.gov.cz',
      service_host: 'cert.datovka-test.gov.cz',
      user_login_policy: 'unknown',
      certificate: file,
      certificate_password: 'tajne-heslo',
    }))
  })

  /**
   * Heslo ani certifikát se do formuláře nikdy nepředvyplňují — z API se
   * nevracejí a vracet se ani nemají. Úprava registrace je proto vždy nové
   * nahrání souboru.
   */
  it('úprava existující registrace nepředvyplní heslo ani certifikát', async () => {
    const wrapper = await mountSection([registration()])

    await findButton(wrapper, 'databox.gateway.registrations.edit')!.trigger('click')
    await flushPromises()

    expect((wrapper.find('[data-test="gw-ats-id"]').element as HTMLInputElement).value).toBe('ATS-1')
    expect((wrapper.find('[data-test="gw-password"]').element as HTMLInputElement).value).toBe('')
    expect((wrapper.find('[data-test="gw-certificate"]').element as HTMLInputElement).value).toBe('')

    await findButton(wrapper, 'common.save')!.trigger('click')
    await flushPromises()

    expect(m.saveGatewayRegistration).not.toHaveBeenCalled()
    expect(m.toastError).toHaveBeenCalledWith('databox.gateway.registrations.certificateRequired')
  })

  it('bez certifikátu se neuloží nic', async () => {
    const wrapper = await mountSection()

    await findButton(wrapper, 'databox.gateway.registrations.add')!.trigger('click')
    await flushPromises()
    await findButton(wrapper, 'common.save')!.trigger('click')
    await flushPromises()

    expect(m.saveGatewayRegistration).not.toHaveBeenCalled()
    expect(m.toastError).toHaveBeenCalledWith('databox.gateway.registrations.certificateRequired')
  })

  /** Prohlížeč nesmí heslo k provozovatelskému certifikátu napovídat. */
  it('pole hesla nenabízí autofill', async () => {
    const wrapper = await mountSection()

    await findButton(wrapper, 'databox.gateway.registrations.add')!.trigger('click')
    await flushPromises()

    const password = wrapper.find('input[type="password"]')
    expect(password.exists()).toBe(true)
    expect(password.attributes('autocomplete')).toBe('new-password')
  })
})

describe('registrace odesílací brány — přepnutí aktivní', () => {
  it('zapnutí se nestane na první kliknutí, ale až po potvrzení', async () => {
    const wrapper = await mountSection([registration()])

    await findButton(wrapper, 'databox.gateway.registrations.activate')!.trigger('click')
    await flushPromises()

    expect(m.setGatewayActive).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('databox.gateway.registrations.activateTitle')

    const confirm = wrapper.findAll('button')
      .filter(b => b.text().includes('databox.gateway.registrations.activate'))
      .at(-1)
    await confirm!.trigger('click')
    await flushPromises()

    expect(m.setGatewayActive).toHaveBeenCalledWith('test', true)
    expect(wrapper.emitted('changed')).toBeTruthy()
  })

  it('vypnutí zapnuté registrace se také potvrzuje', async () => {
    const wrapper = await mountSection([registration({ is_active: true })])

    await findButton(wrapper, 'databox.gateway.registrations.deactivate')!.trigger('click')
    await flushPromises()
    expect(m.setGatewayActive).not.toHaveBeenCalled()

    const confirm = wrapper.findAll('button')
      .filter(b => b.text().includes('databox.gateway.registrations.deactivate'))
      .at(-1)
    await confirm!.trigger('click')
    await flushPromises()

    expect(m.setGatewayActive).toHaveBeenCalledWith('test', false)
  })

  /** Prošlý certifikát bránu nezapne — a UI to musí říct dřív než backend. */
  it('prošlý certifikát zapnout nedovolí', async () => {
    const wrapper = await mountSection([registration({ certificate_valid_to: '2000-01-01 00:00:00' })])

    expect(wrapper.text()).toContain('databox.gateway.registrations.expired')
    expect(findButton(wrapper, 'databox.gateway.registrations.activate')!.attributes('disabled')).toBeDefined()
  })
})

describe('registrace odesílací brány — smazání', () => {
  it('smaže až po potvrzení dialogem', async () => {
    const wrapper = await mountSection([registration()])

    await findButton(wrapper, 'common.delete')!.trigger('click')
    await flushPromises()

    expect(m.deleteGatewayRegistration).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('databox.gateway.registrations.deleteTitle')

    const confirm = wrapper.findAll('button').filter(b => b.text().includes('common.delete')).at(-1)
    await confirm!.trigger('click')
    await flushPromises()

    expect(m.deleteGatewayRegistration).toHaveBeenCalledWith('test')
    expect(wrapper.emitted('changed')).toBeTruthy()
  })
})

describe('registrace odesílací brány — neověřená přihlašovací politika', () => {
  /**
   * `unknown` je pojmenovaná nejistota, ne chybějící hodnota. Obrazovka na ní
   * nesmí spadnout ani ji zamlčet.
   */
  it('unknown vykreslí a řekne nahlas, že to ověřené není', async () => {
    const wrapper = await mountSection([registration({ user_login_policy: 'unknown' })])

    expect(wrapper.text()).toContain('databox.gateway.registrations.policies.unknown')
    expect(wrapper.text()).toContain('databox.gateway.registrations.policyUnknownHint')
  })

  it('u ověřené politiky se nejistota nepřipomíná', async () => {
    const wrapper = await mountSection([registration({ user_login_policy: 'password_required' })])

    expect(wrapper.text()).toContain('databox.gateway.registrations.policies.password_required')
    expect(wrapper.text()).not.toContain('databox.gateway.registrations.policyUnknownHint')
  })

  /** Formulář musí umět všechny tři hodnoty, aby šla nejistota po pokusu uzavřít. */
  it('formulář nabízí všechny tři politiky včetně unknown', async () => {
    const wrapper = await mountSection()

    await findButton(wrapper, 'databox.gateway.registrations.add')!.trigger('click')
    await flushPromises()

    const options = wrapper.findAll('option').map(o => o.attributes('value'))
    expect(options).toContain('unknown')
    expect(options).toContain('password_required')
    expect(options).toContain('portal_sso_or_password')
  })
})

describe('registrace odesílací brány — tajné údaje', () => {
  /** O certifikátu smí být vidět jen otisk a platnost, nic dalšího. */
  it('nezobrazuje nic, co by mohlo být klíčem nebo heslem', async () => {
    const wrapper = await mountSection([registration()])
    const text = wrapper.text()

    expect(text).toContain('databox.gateway.registrations.fingerprint')
    expect(text).not.toContain('BEGIN PRIVATE KEY')
    expect(text).not.toContain('certificate_ciphertext')
    expect(text).not.toContain('certificate_passphrase')
  })
})
