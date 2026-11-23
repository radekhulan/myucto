import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { EpoAttempt, TaxSubmission } from '@/api/epoSubmissions'
import { captureSsoFragment, rememberPendingStepUp, resetSsoState } from '@/security/oidc'

/**
 * Účet přihlášený přes Microsoft Entra ID nemá heslo. Přímé podání do EPO proto
 * musí jít potvrdit i ověřením přes účet organizace: po návratu se otevře týž
 * dialog a proof odejde jako `step_up_token`.
 */

const m = vi.hoisted(() => ({
  list: vi.fn(),
  credentials: vi.fn(),
  settings: vi.fn(),
  submitDirect: vi.fn(),
  identities: vi.fn(),
  stepUp: vi.fn(),
  navigate: vi.fn(),
  totpStepUp: vi.fn(),
}))

vi.mock('@/api/epoSubmissions', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/epoSubmissions')>()
  return {
    ...actual,
    epoSubmissionsApi: {
      list: m.list,
      credentials: m.credentials,
      settings: m.settings,
      submitDirect: m.submitDirect,
      xmlUrl: (id: number) => `/api/reports/submissions/${id}/xml`,
      artifactDownloadUrl: (id: number) => `/api/documents/${id}`,
    },
  }
})

vi.mock('@/api/oidc', () => ({ oidcApi: { identities: m.identities, stepUp: m.stepUp } }))
vi.mock('@/workspace/fullPageNavigation', () => ({ navigateFullPage: m.navigate }))
vi.mock('@/api/auth', () => ({ authApi: { totpStepUp: m.totpStepUp } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
vi.mock('@/security/webauthn', () => ({
  getCredential: vi.fn(),
  isWebAuthnAvailable: () => false,
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}|${JSON.stringify(params)}` : key,
    locale: { value: 'cs' },
  }),
}))
const authState = vi.hoisted(() => ({
  isClientRole: false,
  // Účet jen s přihlášením přes organizaci: žádná passkey, žádné TOTP.
  user: { id: 1, totp_enabled: false, passkey_count: 0, mfa_methods: [] as string[] },
  setupStatus: { sso_providers: [{ id: 'entra', strong: true }], sso_required_provider: null as string | null },
  canWrite: () => true,
  canRead: () => true,
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => authState }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: 1 }),
}))

import TaxSubmissions from '@/pages/reports/TaxSubmissions.vue'
import { invalidateSsoIdentities } from '@/composables/useSsoStepUp'

const passedTest = {
  id: 15,
  channel: 'epo_direct',
  status: 'test_passed',
  epo_environment: 'production',
  test_passed: true,
  test_messages: [],
  requested_at: '2026-08-20T21:00:00Z',
  updated_at: '2026-08-20T21:00:00Z',
  handoff_expires_at: null,
  status_query_available: false,
  refresh_available: false,
  confirmation_recovery_available: false,
} as unknown as EpoAttempt

const submission = {
  id: 1103,
  form_code: 'dphkh1',
  period_year: 2026,
  period_month: 7,
  period_quarter: null,
  xml_size_bytes: 2048,
  xml_sha256: 'ab'.repeat(32),
  validation_status: 'passed',
  validation_errors: [],
  status: 'downloaded',
  submitted_at: null,
  submission_ref: null,
  summary: null,
  generated_at: '2026-08-20T20:00:00Z',
  notes: null,
  attempts: [passedTest],
  artifacts: [],
  deletable: true,
  delete_blocker: null,
  delete_needs_acknowledgement: false,
} as unknown as TaxSubmission

describe('TaxSubmissions.vue — přímé podání ověřené přes Microsoft', () => {
  beforeEach(() => {
    resetSsoState()
    localStorage.clear()
    for (const fn of [m.list, m.credentials, m.settings, m.submitDirect, m.identities, m.stepUp, m.navigate, m.totpStepUp]) fn.mockReset()
    m.list.mockResolvedValue({
      data: [submission],
      meta: { total: 1, limit: 50, offset: 0, stats: { total: 1, waiting: 0, submitted: 0, problems: 0 }, form_codes: [] },
    })
    m.credentials.mockResolvedValue([])
    m.settings.mockResolvedValue({ epo_environment: 'production' })
    m.identities.mockResolvedValue([{ id: 3, provider: 'entra' }])
    invalidateSsoIdentities()
    authState.setupStatus.sso_required_provider = null
    authState.setupStatus.sso_providers = [{ id: 'entra', strong: true }]
    authState.user = { id: 1, totp_enabled: false, passkey_count: 0, mfa_methods: [] }
  })

  afterEach(() => {
    resetSsoState()
    vi.restoreAllMocks()
  })

  it('s propojenou identitou ověří v dialogu podání přes nové okno a dialog nechá otevřený', async () => {
    m.stepUp.mockResolvedValue('https://login.microsoftonline.com/synthetic')
    const popup = { location: { href: '' }, close: vi.fn() }
    const open = vi.spyOn(window, 'open').mockReturnValue(popup as unknown as Window)
    const wrapper = mount(TaxSubmissions)
    await flushPromises()
    const vm = wrapper.vm as any
    vm.openSubmitModal(submission)
    await flushPromises()

    const option = wrapper.find('[data-testid="sso-step-up"]')
    expect(option.exists()).toBe(true)
    await option.find('button').trigger('click')
    await flushPromises()

    expect(m.stepUp).toHaveBeenCalledWith('entra', 'epo.certificate', expect.stringMatching(/^\/sso\/step-up\?n=[0-9a-f]{32}$/))
    expect(m.navigate).not.toHaveBeenCalled()
    expect(popup.location.href).toBe('https://login.microsoftonline.com/synthetic')

    const nonce = new URLSearchParams((m.stepUp.mock.calls[0][2] as string).split('?')[1]).get('n')
    const channel = new BroadcastChannel('myucto-sso-step-up')
    channel.postMessage({ nonce, token: 'sso-proof', operation: 'epo.certificate' })
    channel.close()
    await new Promise(resolve => setTimeout(resolve, 20))
    await flushPromises()

    expect(vm.submitModalOpen).toBe(true)
    expect(vm.submitPasskeyToken).toBe('sso-proof')
    expect(wrapper.find('[data-testid="sso-step-up-verified"]').exists()).toBe(true)
    open.mockRestore()
  })

  it('po návratu otevře týž dialog a proof odešle jako step_up_token', async () => {
    rememberPendingStepUp('epo.certificate', '/reports/submissions', Date.now(), 'tax-submissions:submit:1103')
    captureSsoFragment(
      { hash: '#sso_step_up=sso-proof&sso_operation=epo.certificate', pathname: '/reports/submissions', search: '' },
      { state: null, replaceState: vi.fn() },
    )
    m.submitDirect.mockResolvedValue({ status: 'submitted', environment: 'production' })

    const wrapper = mount(TaxSubmissions)
    await flushPromises()
    const vm = wrapper.vm as any

    expect(vm.submitModalOpen).toBe(true)
    expect(vm.submitAttemptId).toBe(15)
    expect(wrapper.find('[data-testid="sso-step-up-verified"]').exists()).toBe(true)
    expect(vm.submitStepUpReady).toBe(true)

    await vm.submitDirect()
    expect(m.submitDirect).toHaveBeenCalledWith(
      1103,
      15,
      expect.objectContaining({ step_up_token: 'sso-proof', password: undefined }),
      false,
    )
  })

  it('bez propojené identity volbu nenabídne', async () => {
    m.identities.mockResolvedValue([])
    const wrapper = mount(TaxSubmissions)
    await flushPromises()
    ;(wrapper.vm as any).openSubmitModal(submission)
    await flushPromises()
    expect(wrapper.find('[data-testid="sso-step-up"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="sso-step-up-unavailable"]').exists()).toBe(false)
  })

  it('bez vynucení nabídne vedle Microsoftu heslo i přístupový klíč', async () => {
    authState.user = { id: 1, totp_enabled: true, passkey_count: 1, mfa_methods: ['passkey'] }
    const wrapper = mount(TaxSubmissions)
    await flushPromises()
    const vm = wrapper.vm as any
    vm.openSubmitModal(submission)
    await flushPromises()
    expect(wrapper.find('input[autocomplete="current-password"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('reports.submissions.step_up_passkey_unsupported')
    vm.submitPassword = 'synthetic-pass'
    vm.submitTotpCode = '123456'
    await flushPromises()
    expect(vm.submitStepUpReady).toBe(true)
  })

  it('při vynucení nabídne jen Microsoft a potvrzení povolí až s jeho proofem', async () => {
    authState.setupStatus.sso_required_provider = 'entra'
    authState.user = { id: 1, totp_enabled: true, passkey_count: 1, mfa_methods: ['passkey'] }
    const wrapper = mount(TaxSubmissions)
    await flushPromises()
    const vm = wrapper.vm as any
    vm.openSubmitModal(submission)
    await flushPromises()
    expect(wrapper.find('[data-testid="sso-step-up"]').exists()).toBe(true)
    expect(wrapper.find('input[autocomplete="current-password"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('reports.submissions.step_up_passkey_unsupported')
    expect(wrapper.text()).not.toContain('reports.submissions.step_up_verify_passkey')
    // Ani podstrčený passkey proof potvrzení nepovolí.
    vm.submitPasskeyToken = 'passkey-proof'
    await flushPromises()
    expect(vm.submitStepUpReady).toBe(false)
    vm.ssoProof = 'passkey-proof'
    await flushPromises()
    expect(vm.submitStepUpReady).toBe(true)
  })

  it('bez vynucení odejde heslo s TOTP beze změny', async () => {
    authState.user = { id: 1, totp_enabled: true, passkey_count: 0, mfa_methods: [] }
    m.submitDirect.mockResolvedValue({ status: 'submitted', environment: 'production' })
    const wrapper = mount(TaxSubmissions)
    await flushPromises()
    const vm = wrapper.vm as any
    vm.openSubmitModal(submission)
    vm.submitPassword = 'synthetic-pass'
    vm.submitTotpCode = '123456'
    await vm.submitDirect()
    expect(m.totpStepUp).not.toHaveBeenCalled()
    expect(m.submitDirect).toHaveBeenCalledWith(1103, 15,
      { password: 'synthetic-pass', totp_code: '123456', step_up_token: undefined }, false)
  })

  it('při vynucení poskytovatele jako prvního faktoru potvrdí TOTP bez hesla a pošle jen proof', async () => {
    authState.setupStatus.sso_required_provider = 'entra'
    authState.setupStatus.sso_providers = [{ id: 'entra', strong: false }]
    authState.user = { id: 1, totp_enabled: true, passkey_count: 1, mfa_methods: ['passkey'] }
    m.totpStepUp.mockResolvedValue('totp-proof')
    m.submitDirect.mockResolvedValue({ status: 'submitted', environment: 'production' })
    const wrapper = mount(TaxSubmissions)
    await flushPromises()
    const vm = wrapper.vm as any
    vm.openSubmitModal(submission)
    await flushPromises()
    expect(wrapper.find('input[autocomplete="current-password"]').exists()).toBe(false)
    expect(wrapper.find('input[autocomplete="one-time-code"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('reports.submissions.step_up_passkey_unsupported')
    expect(wrapper.find('[data-testid="sso-step-up-unavailable"]').exists()).toBe(false)
    expect(vm.submitStepUpReady).toBe(false)
    vm.submitTotpCode = '123456'
    await flushPromises()
    expect(vm.submitStepUpReady).toBe(true)
    await vm.submitDirect()
    expect(m.totpStepUp).toHaveBeenCalledWith('epo.certificate', '123456')
    expect(m.submitDirect).toHaveBeenCalledWith(1103, 15, { step_up_token: 'totp-proof' }, false)
  })

  it('při vynucení bez propojené identity ukáže vysvětlení místo prázdného dialogu', async () => {
    authState.setupStatus.sso_required_provider = 'entra'
    m.identities.mockResolvedValue([])
    const wrapper = mount(TaxSubmissions)
    await flushPromises()
    ;(wrapper.vm as any).openSubmitModal(submission)
    await flushPromises()
    expect(wrapper.find('[data-testid="sso-step-up"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="sso-step-up-unavailable"]').text()).toBe('sso.step_up_enforced_unavailable')
  })
})
