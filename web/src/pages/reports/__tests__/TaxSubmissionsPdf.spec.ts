import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { TaxSubmission, TaxSubmissionReceiptEvidence } from '@/api/epoSubmissions'

/**
 * PDF odeslaného přiznání DPH/KH a panel „Doklad o podání": tlačítko jen u výkazů,
 * které server umí vytisknout, a důvod chybějící doručenky podle `receipt_evidence`.
 */

const m = vi.hoisted(() => ({
  list: vi.fn(),
  credentials: vi.fn(),
  settings: vi.fn(),
  isdsInbox: vi.fn(),
  isClientRole: false,
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
      isdsInbox: m.isdsInbox,
      xmlUrl: (id: number) => `/api/reports/submissions/${id}/xml`,
      pdfUrl: (id: number) => `/api/reports/submissions/${id}/pdf`,
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
    user: { id: 1, totp_enabled: false, passkey_count: 0, mfa_methods: [] },
    isClientRole: m.isClientRole,
    canWrite: () => m.canWrite,
    canRead: () => true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: 1 }),
}))

import TaxSubmissions from '@/pages/reports/TaxSubmissions.vue'

const NOW = new Date('2026-10-12T10:00:00Z')

function evidence(overrides: Partial<TaxSubmissionReceiptEvidence> = {}): TaxSubmissionReceiptEvidence {
  return {
    available: false,
    reason: 'submitted_outside',
    reason_label: null,
    reference: null,
    submitted_at: null,
    items: [],
    ...overrides,
  }
}

function submission(overrides: Partial<TaxSubmission> = {}): TaxSubmission {
  return {
    id: 1201,
    form_code: 'dphdp3',
    period_year: 2026,
    period_month: 9,
    period_quarter: null,
    xml_size_bytes: 2048,
    xml_sha256: 'e43f2c6559baab9e',
    validation_status: 'passed',
    validation_errors: [],
    status: 'submitted',
    submitted_at: NOW.toISOString(),
    submission_ref: null,
    summary: null,
    generated_at: NOW.toISOString(),
    notes: null,
    attempts: [],
    artifacts: [],
    deletable: false,
    delete_blocker: null,
    delete_needs_acknowledgement: false,
    isds_dispatch: null,
    receipt_evidence: evidence(),
    ...overrides,
  } as TaxSubmission
}

async function openDetail(item: TaxSubmission) {
  m.list.mockResolvedValue({
    data: [item],
    meta: {
      total: 1,
      limit: 50,
      offset: 0,
      stats: { total: 1, waiting: 0, submitted: 1, problems: 0 },
      form_codes: [item.form_code],
    },
  })
  const wrapper = mount(TaxSubmissions, {
    props: { scope: 'tax' },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
  await flushPromises()
  ;(wrapper.vm as any).toggleDetail(item)
  await flushPromises()
  const actions = (wrapper.vm as any).submissionActions as Array<{ key: string; show?: boolean; href?: string }>
  return { wrapper, pdf: actions.find(a => a.key === 'download_pdf') }
}

describe('TaxSubmissions.vue — PDF podání a doklad o podání', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    m.isClientRole = false
    m.canWrite = true
    m.isdsInbox.mockReset().mockResolvedValue([])
    m.list.mockReset()
    m.credentials.mockReset().mockResolvedValue([])
    m.settings.mockReset().mockResolvedValue({ epo_environment: 'production' })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it.each(['dphdp3', 'dphkh1'])('u %s nabídne stažení PDF podání', async form => {
    const { pdf } = await openDetail(submission({ form_code: form }))

    expect(pdf?.show).toBe(true)
    expect(pdf?.href).toBe('/api/reports/submissions/1201/pdf')
  })

  it('u výkazu bez tisku PDF nenabízí', async () => {
    const { pdf } = await openDetail(submission({ form_code: 'dppdp9' }))

    expect(pdf?.show).toBe(false)
  })

  it('bez uložené doručenky vysvětlí, že se podávalo mimo MyÚčto', async () => {
    const { wrapper } = await openDetail(submission())

    const panel = wrapper.find('[data-testid="filing-proof"]')
    expect(panel.exists()).toBe(true)
    expect(panel.text()).toContain('reports.submissions.filing_proof_submitted_outside')
  })

  it('s uloženou doručenkou odkáže na originály v seznamu souborů', async () => {
    const { wrapper } = await openDetail(submission({
      receipt_evidence: evidence({
        available: true,
        reason: null,
        items: [{ artifact_id: 7, kind: 'isds_delivery_zfo', label: 'Doručenka', name: 'dorucenka.zfo' }],
      }),
    }))

    const panel = wrapper.find('[data-testid="filing-proof"]')
    expect(panel.text()).toContain('reports.submissions.filing_proof_available')
    expect(panel.text()).not.toContain('filing_proof_submitted_outside')
  })
})
