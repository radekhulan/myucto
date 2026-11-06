import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { EpoAttempt, TaxIsdsDispatch, TaxSubmission } from '@/api/epoSubmissions'

/**
 * Odeslání datovou schránkou je třetí cesta vedle EPO. Obě se vylučují: backend
 * odmítne EPO při rozpracované zprávě datovky (`isds_dispatch_active`) a datovku
 * při živém EPO pokusu, tak je UI nesmí nabízet zároveň.
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

function dispatch(state: TaxIsdsDispatch['dispatch_state']): TaxIsdsDispatch {
  return {
    id: 501,
    dispatch_state: state,
    dispatch_mode: null,
    recipient_id: 5,
    recipient_box_id: 'abc1234',
    recipient_name: 'Finanční úřad pro Testovací kraj',
    subject: 'KH 2026-09',
    correlation_reference: 'MU-TAX-1',
    external_message_id: state === 'sent' ? '9000001' : null,
    sent_at: null,
    delivered_at: null,
    receipt_document_id: null,
    receipt_attached_at: null,
    last_error_code: null,
    last_error_message: null,
    created_at: NOW.toISOString(),
  }
}

function submission(overrides: Partial<TaxSubmission> = {}): TaxSubmission {
  return {
    id: 1103,
    form_code: 'dphkh1',
    period_year: 2026,
    period_month: 9,
    period_quarter: null,
    xml_size_bytes: 2048,
    xml_sha256: 'e43f2c6559baab9e',
    validation_status: 'passed',
    validation_errors: [],
    status: 'downloaded',
    submitted_at: null,
    submission_ref: null,
    summary: null,
    generated_at: NOW.toISOString(),
    notes: null,
    attempts: [],
    artifacts: [],
    deletable: true,
    delete_blocker: null,
    delete_needs_acknowledgement: false,
    isds_dispatch: null,
    ...overrides,
  } as TaxSubmission
}

async function mountWith(item: TaxSubmission, scope: 'tax' | 'payroll' = 'tax') {
  m.list.mockResolvedValue({
    data: [item],
    meta: {
      total: 1,
      limit: 50,
      offset: 0,
      stats: { total: 1, waiting: 0, submitted: 0, problems: 0 },
      form_codes: [item.form_code],
    },
  })
  const wrapper = mount(TaxSubmissions, {
    props: { scope },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
  await flushPromises()
  const vm = wrapper.vm as unknown as {
    canSendIsds: (item: TaxSubmission) => boolean
    canHandoff: (item: TaxSubmission) => boolean
    canOfferDirectActions: (item: TaxSubmission) => boolean
    isdsSendLabel: (item: TaxSubmission) => string
  }
  return { wrapper, vm }
}

describe('TaxSubmissions.vue — odeslání datovou schránkou', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    m.isClientRole = false
    m.canWrite = true
    m.isdsInbox.mockReset().mockResolvedValue([])
    m.list.mockReset()
    m.credentials.mockReset()
    m.settings.mockReset()
    m.credentials.mockResolvedValue([])
    m.settings.mockResolvedValue({ epo_environment: 'production' })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it.each([false, true])('klient s právem odesílání %s nenačítá interní zprávy FÚ', async canWrite => {
    m.isClientRole = true
    m.canWrite = canWrite
    const item = submission({ isds_dispatch: dispatch('sent') })
    const { wrapper } = await mountWith(item)
    ;(wrapper.vm as any).toggleDetail(item)
    await flushPromises()
    expect(m.isdsInbox).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('reports.submissions.isds.inbox_title')
    expect(wrapper.text()).not.toContain('reports.submissions.isds.open_outbox')
  })

  it('interní čtenář nadále vidí zprávy FÚ', async () => {
    m.canWrite = false
    const item = submission({ isds_dispatch: dispatch('sent') })
    const { wrapper } = await mountWith(item)
    ;(wrapper.vm as any).toggleDetail(item)
    await flushPromises()
    expect(m.isdsInbox).toHaveBeenCalledWith(item.id)
    expect(wrapper.text()).toContain('reports.submissions.isds.inbox_title')
  })

  it('nabídne datovku vedle EPO u podporovaného výkazu', async () => {
    const item = submission()
    const { wrapper, vm } = await mountWith(item)

    expect(vm.canSendIsds(item)).toBe(true)
    expect(vm.canHandoff(item)).toBe(true)
    expect(wrapper.find('[data-test="isds-send-1103"]').text()).toContain('reports.submissions.isds.send')
  })

  it('zprávu ve frontě nabídne dokončit a EPO schová', async () => {
    const item = submission({ isds_dispatch: dispatch('ready') })
    const { wrapper, vm } = await mountWith(item)

    expect(vm.canSendIsds(item)).toBe(true)
    expect(vm.isdsSendLabel(item)).toBe('reports.submissions.isds.finish_send')
    expect(vm.canHandoff(item)).toBe(false)
    expect(vm.canOfferDirectActions(item)).toBe(false)
    expect(wrapper.find('[data-test="isds-state-1103"]').text()).toContain('reports.submissions.isds.state_ready')
  })

  it('odeslanou zprávu znovu neposílá a ukáže její ID', async () => {
    const item = submission({ isds_dispatch: dispatch('sent') })
    const { wrapper, vm } = await mountWith(item)

    expect(vm.canSendIsds(item)).toBe(false)
    expect(vm.canHandoff(item)).toBe(false)
    expect(wrapper.find('[data-test="isds-send-1103"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="isds-state-1103"]').text()).toContain('9000001')
  })

  it('po zrušené zprávě je EPO zase volné', async () => {
    const item = submission({ isds_dispatch: dispatch('cancelled') })
    const { vm } = await mountWith(item)

    expect(vm.canHandoff(item)).toBe(true)
    expect(vm.canSendIsds(item)).toBe(true)
  })

  it('OSS, neprošlá validace ani živý EPO pokus datovku nenabídnou', async () => {
    const { vm } = await mountWith(submission())
    const assisted = {
      id: 9,
      channel: 'epo_assisted',
      status: 'awaiting_confirmation',
      epo_environment: 'production',
      requested_at: NOW.toISOString(),
      updated_at: NOW.toISOString(),
      handoff_expires_at: new Date(NOW.getTime() + 10 * 60_000).toISOString(),
    } as EpoAttempt

    expect(vm.canSendIsds(submission({ form_code: 'ossei1' }))).toBe(false)
    expect(vm.canSendIsds(submission({ validation_status: 'failed' }))).toBe(false)
    expect(vm.canSendIsds(submission({ status: 'submitted' }))).toBe(false)
    expect(vm.canSendIsds(submission({ attempts: [assisted] }))).toBe(false)
  })

  it('mzdová vyúčtování datovkou nikdy', async () => {
    const item = submission({ form_code: 'dpzvd6' })
    const { vm } = await mountWith(item, 'payroll')

    expect(vm.canSendIsds(item)).toBe(false)
    expect(vm.canSendIsds(submission())).toBe(false)
  })
})
