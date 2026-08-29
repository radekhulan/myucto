import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { EpoAttempt, TaxSubmission } from '@/api/epoSubmissions'

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
  canWrite: true,
}))

vi.mock('@/api/epoSubmissions', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/epoSubmissions')>()
  return {
    ...actual,
    epoSubmissionsApi: {
      list: m.list,
      credentials: m.credentials,
      settings: m.settings,
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
  useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }),
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
    user: { id: 1, totp_enabled: false, passkey_count: 0, mfa_methods: [] },
    canWrite: () => m.canWrite,
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
    xml_sha256: 'e43f2c6559baab9e',
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
    m.canWrite = true
    m.list.mockReset()
    m.credentials.mockReset()
    m.settings.mockReset()
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

  it('(c) asistované předání založené AŽ PO testu tlačítko zašedí a řekne proč', async () => {
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

    expect(vm.canDirectSubmit(item)).toBe(false)
    expect(vm.directSubmitDisabledReason(item))
      .toContain('reports.submissions.direct_reason_handoff_active')
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
})
