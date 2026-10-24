import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { EpoAttempt, TaxSubmission } from '@/api/epoSubmissions'
import { loadEpoHandoffLinks, saveEpoHandoffLinks } from '@/utils/epoHandoffCache'

/**
 * Regrese k „Podepsat a podat" zůstávalo zašedlé i po úspěšném produkčním testu.
 *
 * Blokoval ho starší asistovaný pokus („Čeká na P7S"), jehož okno platnosti ještě
 * běželo — a u záznamu bez `handoff_expires_at` dokonce běželo napořád. Nový
 * úspěšný přímý test je novější rozhodnutí uživatele a starší asistované předání
 * překlápí na překonané; rozdělané předání založené AŽ PO testu blokovat má.
 */

const m = vi.hoisted(() => ({
  list: vi.fn(),
  credentials: vi.fn(),
  settings: vi.fn(),
  submitDirect: vi.fn(),
  uploadCredential: vi.fn(),
  canWrite: true,
  isClientRole: false,
  canManageOnly: false,
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
      uploadCredential: m.uploadCredential,
      xmlUrl: (id: number) => `/api/reports/submissions/${id}/xml`,
      artifactDownloadUrl: (id: number) => `/api/documents/${id}`,
    },
  }
})

vi.mock('@/api/auth', () => ({ authApi: {} }))
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
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    isClientRole: m.isClientRole,
    user: { id: 1, totp_enabled: false, passkey_count: 0, mfa_methods: [] },
    canWrite: (permission: string) => m.canManageOnly ? permission === 'reports.submissions.manage' : m.canWrite,
    canRead: () => true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: 1 }),
}))

import TaxSubmissions from '@/pages/reports/TaxSubmissions.vue'

const NOW = new Date('2026-08-20T21:26:00Z')

function iso(minutesBeforeNow: number): string {
  return new Date(NOW.getTime() - minutesBeforeNow * 60_000).toISOString()
}

function attempt(overrides: Partial<EpoAttempt> & Pick<EpoAttempt, 'id' | 'channel' | 'status'>): EpoAttempt {
  const requestedAt = overrides.requested_at ?? iso(10)
  return {
    epo_environment: 'production',
    request_sha256: 'abc',
    signing_credential_id: null,
    signing_fingerprint: null,
    response_http_status: null,
    test_passed: null,
    test_messages: [],
    tested_at: null,
    error_code: null,
    error_message: null,
    requested_by: 1,
    requested_at: requestedAt,
    handoff_expires_at: null,
    submitted_at: null,
    remote_submission_ref: null,
    remote_status: null,
    last_status_at: null,
    confirmed_at: null,
    poll_count: 0,
    next_poll_at: null,
    status_query_available: false,
    refresh_available: false,
    confirmation_recovery_available: false,
    resolution_code: null,
    resolution_note: null,
    resolved_by: null,
    resolved_at: null,
    updated_at: requestedAt,
    ...overrides,
  } as EpoAttempt
}

function submission(attempts: EpoAttempt[]): TaxSubmission {
  return {
    id: 1103,
    form_code: 'dphkh1',
    period_year: 2026,
    period_month: 7,
    period_quarter: null,
    xml_size_bytes: 2048,
    xml_sha256: 'e43f2c6559baab9e'.repeat(4),
    validation_status: 'passed',
    validation_errors: [],
    status: 'downloaded',
    submitted_at: null,
    submission_ref: null,
    summary: null,
    generated_at: iso(60),
    notes: null,
    attempts,
    artifacts: [],
    deletable: true,
    delete_blocker: null,
    delete_needs_acknowledgement: false,
  } as TaxSubmission
}

async function mountWith(attempts: EpoAttempt[]) {
  m.list.mockResolvedValue({
    data: [submission(attempts)],
    meta: {
      total: 1,
      limit: 50,
      offset: 0,
      stats: { total: 1, waiting: 0, submitted: 0, problems: 0 },
      form_codes: ['dphdp3'],
    },
  })
  const wrapper = mount(TaxSubmissions)
  await flushPromises()
  const vm = wrapper.vm as unknown as {
    canDirectSubmit: (item: TaxSubmission) => boolean
    directSubmitDisabledReason: (item: TaxSubmission) => string | undefined
    openSubmitModal: (item: TaxSubmission) => void
    submitDirect: () => Promise<void>
    submitBlockingHandoff: EpoAttempt | null
    submitSupersedeConfirmed: boolean
    submitPassword: string
  }
  return { wrapper, vm, item: submission(attempts) }
}

/** Pokusy tak, jak je vrací API — sestupně podle času. */
const passedProductionTest = () => attempt({
  id: 15,
  channel: 'epo_direct',
  status: 'test_passed',
  test_passed: true,
  requested_at: iso(1),
  updated_at: iso(1),
})

describe('TaxSubmissions.vue — dostupnost „Podepsat a podat"', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    localStorage.clear()
    m.canWrite = true
    m.isClientRole = false
    m.canManageOnly = false
    m.uploadCredential.mockReset()
    m.list.mockReset()
    m.credentials.mockReset()
    m.settings.mockReset()
    m.submitDirect.mockReset()
    m.credentials.mockResolvedValue([{
      id: 7,
      label: 'ZAREP',
      fingerprint_sha256: 'ff',
      subject_dn: 'CN=Test',
      issuer_dn: 'CN=CA',
      serial_hex: '01',
      valid_from: iso(10000),
      valid_to: iso(-10000),
      ik_mpsv_present: false,
      epo_verified: true,
      epo_verified_at: iso(100),
      valid_now: true,
      created_at: iso(10000),
      enabled_for_supplier: true,
      linked_profiles_count: 1,
      linked_supplier_profiles_count: 1,
    }])
    m.settings.mockResolvedValue({ epo_environment: 'production' })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('read-only client cannot continue a cached EPO handoff after losing send permission', async () => {
    m.isClientRole = true
    m.canWrite = false
    const link = {
      url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
      expiresAt: iso(-10),
      attemptId: 25,
      xmlSha256: submission([]).xml_sha256,
    }
    saveEpoHandoffLinks(localStorage, 1, 1, { 1103: link })
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    const { wrapper, item } = await mountWith([
      attempt({ id: 25, channel: 'epo_assisted', status: 'awaiting_confirmation', handoff_expires_at: link.expiresAt }),
    ])
    const vm = wrapper.vm as any
    expect(vm.pendingHandoffLink(item)).toEqual(link)
    expect.soft(wrapper.findAll('button').filter(button => button.text() === 'reports.submissions.continue_epo')).toHaveLength(0)
    vm.openPendingHandoff(item)
    expect(open).not.toHaveBeenCalled()
    expect(loadEpoHandoffLinks(localStorage, 1, 1)[item.id]?.consumedAt).toBeUndefined()
  })

  it('read-only client loads the prepared archive without signing dependencies or write controls', async () => {
    m.isClientRole = true
    m.canWrite = false
    const { wrapper } = await mountWith([])
    const vm = wrapper.vm as any
    vm.toggleDetail(submission([]))
    await flushPromises()
    expect(m.list).toHaveBeenCalledOnce()
    expect(m.credentials).not.toHaveBeenCalled()
    expect(m.settings).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('reports.submissions.settings')
    expect(wrapper.text()).not.toContain('reports.submissions.certificates')
    expect(wrapper.text()).not.toContain('reports.submissions.upload_title')
    const actions = vm.submissionActions as Array<{ key: string; show?: boolean }>
    expect(actions.filter(action => action.show !== false).map(action => action.key)).toEqual(['download_xml'])
  })

  it('client submitter keeps send actions while management and source evidence stay hidden', async () => {
    m.isClientRole = true
    const { wrapper } = await mountWith([
      attempt({ id: 22, channel: 'epo_direct', status: 'uncertain', confirmation_recovery_available: true }),
    ])
    const vm = wrapper.vm as any
    vm.toggleDetail(submission([]))
    await flushPromises()
    const actions = vm.submissionActions as Array<{ key: string; show?: boolean }>
    for (const key of ['delete', 'resolve_not_submitted', 'isds_outbox', 'kh_evidence']) {
      expect(actions.find(action => action.key === key)?.show, key).toBe(false)
    }
    expect(wrapper.text()).not.toContain('reports.submissions.settings')
    expect(wrapper.text()).toContain('reports.submissions.certificates')
    expect(m.credentials).toHaveBeenCalledOnce()
    expect(m.settings).toHaveBeenCalledOnce()
  })

  it('client certificate controls keep supplier selection without global vault deletion', async () => {
    m.isClientRole = true
    const own = (await m.credentials())[0]
    m.credentials.mockResolvedValue([{ ...own, linked_profiles_count: 0, linked_supplier_profiles_count: 0 }])
    const { wrapper } = await mountWith([])
    await (wrapper.vm as any).openCredentialModal()
    await flushPromises()
    expect(wrapper.findAll('button').some(button => button.text() === 'common.delete')).toBe(false)
    expect(wrapper.findAll('button').some(button => button.text() === 'reports.submissions.disable_for_company')).toBe(true)
    expect(wrapper.text()).toContain('reports.submissions.own_certificate_upload')
  })

  it('staff archive management without send permission cannot force release evidence', async () => {
    m.canManageOnly = true
    const uncertain = attempt({ id: 30, channel: 'epo_direct', status: 'uncertain' })
    const { wrapper, item } = await mountWith([uncertain])
    const vm = wrapper.vm as any
    vm.toggleDetail(item)
    await flushPromises()
    expect(vm.canManage).toBe(true)
    expect.soft(vm.canResolveDirectAttempt(uncertain)).toBe(false)
    expect.soft(vm.submissionActions.find((action: { key: string }) => action.key === 'resolve_not_submitted').show).toBe(false)
    vm.openRecoveryModal(item, 'not_submitted')
    expect(vm.recoveryModalOpen).toBe(false)
  })

  it('client can upload an owned certificate using the existing step-up proof without settings navigation', async () => {
    m.isClientRole = true
    const { wrapper } = await mountWith([])
    const vm = wrapper.vm as any
    await vm.openCredentialModal()
    await flushPromises()
    expect(wrapper.find('a[href="/admin/electronic-signatures"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('reports.submissions.own_certificate_title')
    const file = new File(['synthetic certificate'], 'synthetic.p12')
    vm.credentialFile = file
    vm.credentialLabel = 'Synthetic client certificate'
    vm.credentialPfxPassword = 'synthetic-pfx-password'
    vm.stepPassword = 'synthetic-account-password'
    m.uploadCredential.mockResolvedValue({ id: 7 })
    await vm.uploadOwnCredential()
    expect(m.uploadCredential).toHaveBeenCalledWith(file, 'Synthetic client certificate', 'synthetic-pfx-password',
      expect.objectContaining({ password: 'synthetic-account-password' }))
    expect(vm.credentialPfxPassword).toBe('')
    expect(vm.stepPassword).toBe('')
  })

  it('(a) starší asistovaný pokus „Čeká na P7S" v živém okně novější úspěšný test nepřebije', async () => {
    const { vm, item } = await mountWith([
      passedProductionTest(),
      attempt({
        id: 13,
        channel: 'epo_assisted',
        status: 'awaiting_confirmation',
        requested_at: iso(9),
        updated_at: iso(9),
        // Okno ještě běží (vyprší za 11 minut) — přesně stav z hlášené chyby.
        handoff_expires_at: new Date(NOW.getTime() + 11 * 60_000).toISOString(),
      }),
    ])

    expect(vm.canDirectSubmit(item)).toBe(true)
    expect(vm.directSubmitDisabledReason(item)).toBeUndefined()
  })

  it('(b) zaseknutý asistovaný pokus bez okna platnosti neblokuje napořád', async () => {
    const { vm, item } = await mountWith([
      passedProductionTest(),
      attempt({
        id: 13,
        channel: 'epo_assisted',
        status: 'awaiting_confirmation',
        requested_at: iso(600),
        updated_at: iso(600),
        handoff_expires_at: null,
      }),
    ])

    expect(vm.canDirectSubmit(item)).toBe(true)
  })

  it('(c) předání založené AŽ PO testu podání nezašedí, dialog ale vyžaduje vědomé potvrzení', async () => {
    const { vm, item } = await mountWith([
      attempt({
        id: 16,
        channel: 'epo_assisted',
        status: 'awaiting_confirmation',
        requested_at: iso(0),
        updated_at: iso(0),
        handoff_expires_at: new Date(NOW.getTime() + 20 * 60_000).toISOString(),
      }),
      passedProductionTest(),
    ])

    expect(vm.canDirectSubmit(item)).toBe(true)
    expect(vm.directSubmitDisabledReason(item)).toBeUndefined()

    vm.openSubmitModal(item)
    await flushPromises()
    expect(vm.submitBlockingHandoff?.id).toBe(16)
    expect(vm.submitSupersedeConfirmed).toBe(false)

    m.submitDirect.mockResolvedValue({ status: 'submitted', environment: 'production' })
    vm.submitSupersedeConfirmed = true
    vm.submitPassword = 'heslo'
    await vm.submitDirect()
    expect(m.submitDirect).toHaveBeenCalledWith(1103, 15, expect.anything(), true)
  })

  it('(c2) bez otevřeného předání se podání neposílá jako přebití odkazu', async () => {
    const { vm, item } = await mountWith([passedProductionTest()])

    vm.openSubmitModal(item)
    await flushPromises()
    expect(vm.submitBlockingHandoff).toBeNull()

    m.submitDirect.mockResolvedValue({ status: 'submitted', environment: 'production' })
    vm.submitPassword = 'heslo'
    await vm.submitDirect()
    expect(m.submitDirect).toHaveBeenCalledWith(1103, 15, expect.anything(), false)
  })

  it('(d) potvrzené produkční přímé podání zůstává zašedlé s vlastním důvodem', async () => {
    const { vm, item } = await mountWith([
      attempt({
        id: 17,
        channel: 'epo_direct',
        status: 'confirmed',
        requested_at: iso(0),
        updated_at: iso(0),
      }),
      passedProductionTest(),
    ])

    expect(vm.canDirectSubmit(item)).toBe(false)
    expect(vm.directSubmitDisabledReason(item))
      .toBe('reports.submissions.direct_reason_unresolved')
  })

  it('(e) bez úspěšného testu tlačítko zašedne s důvodem o chybějícím testu', async () => {
    const { vm, item } = await mountWith([
      attempt({
        id: 18,
        channel: 'epo_direct',
        status: 'test_failed',
        requested_at: iso(1),
        updated_at: iso(1),
      }),
    ])

    expect(vm.canDirectSubmit(item)).toBe(false)
    expect(vm.directSubmitDisabledReason(item))
      .toContain('reports.submissions.direct_reason_test_required')
  })

  it('(f) asistované podání s dodejkou nabízí obnovu stavu jen jednou a bez přímého pokusu nic dalšího', async () => {
    const { wrapper, item } = await mountWith([
      attempt({
        id: 27,
        channel: 'epo_assisted',
        status: 'confirmed',
        status_query_available: true,
        remote_submission_ref: '568763535',
        confirmed_at: iso(1),
      }),
    ])
    const vm = wrapper.vm as unknown as {
      toggleDetail: (item: TaxSubmission) => void
      submissionActions: Array<{ key: string; show?: unknown }>
    }
    vm.toggleDetail(item)
    await flushPromises()

    const visible = vm.submissionActions
      .filter(a => a.show === undefined || !!a.show)
      .map(a => a.key)
    expect(visible).toContain('refresh_status_assisted')
    expect(visible).not.toContain('refresh_status')
    expect(visible).not.toContain('recover_confirmation')
  })
})
