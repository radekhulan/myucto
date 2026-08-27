import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  candidates: vi.fn(),
  importRequest: vi.fn(),
  requestDetail: vi.fn(),
  casesPage: vi.fn(),
  previewResponse: vi.fn(),
  freezeResponse: vi.fn(),
  enqueueResponse: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payrollEnforcement', () => ({
  payrollEnforcementApi: {
    cooperationCandidates: m.candidates,
    importCooperationRequest: m.importRequest,
    cooperationRequestDetail: m.requestDetail,
    casesPage: m.casesPage,
    previewCooperationResponse: m.previewResponse,
    freezeCooperationResponse: m.freezeResponse,
    enqueueCooperationResponse: m.enqueueResponse,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }),
}))

import PayrollEnforcementCooperation from '@/pages/payroll/PayrollEnforcementCooperation.vue'

const candidate = {
  inbox_message_id: 17,
  document_id: 27,
  document_file_id: 37,
  external_message_id: 'synthetic-17',
  sender_box_id: 'abc1234',
  sender_name: 'Syntetický exekutor',
  subject: 'Součinnost XMLZAM',
  delivered_at: '2026-08-20 10:00:00',
  fetched_at: '2026-08-20 10:05:00',
  original_name: 'xmlzam.xml',
  mime_type: 'application/xml',
  size_bytes: 1234,
  sha256: 'a'.repeat(64),
}

const detail = {
  id: 47,
  environment: 'production',
  request_identifier: 'REQ-47',
  case_reference: 'EX 47/26',
  issued_on: '2026-08-20',
  requested_scopes: ['vyse_srazek', 'trvani_praconiho_pomeru', 'poradi_exekuce'],
  executor_box_id: 'abc1234',
  employee: { id: 3, full_name: 'Syntetická osoba', is_active: true },
  source: { inbox_message_id: 17, document_id: 27, document_file_id: 37, sha256: 'a'.repeat(64) },
  recipient_match_status: 'matched',
  recipient: { id: 57, code: 'EXE', name: 'Syntetický exekutor', kind: 'other', isds_box_id: 'abc1234' },
  imported_at: '2026-08-20 10:10:00',
}

describe('PayrollEnforcementCooperation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.candidates.mockResolvedValue([candidate])
    m.importRequest.mockResolvedValue({ id: 47, employee_id: 3, created: true, request_identifier: 'REQ-47' })
    m.requestDetail.mockResolvedValue(detail)
    m.casesPage.mockResolvedValue({
      cases: [{ id: 67, employee_id: 3, full_name: 'Syntetická osoba', case_kind: 'enforcement', status: 'remit', effective_from: '2026-01-01', effective_to: null, evidence_complete: true, recipient_verified: true, row_version: 2, claim_count: 1, outstanding_minor_units: 10000, created_at: '', updated_at: '' }],
      total: 1,
      limit: 100,
      offset: 0,
    })
    m.previewResponse.mockResolvedValue({
      request_id: 47,
      case_id: 67,
      response_identifier: 'RES-47',
      includes_wages: true,
      source_manifest: [{ period: '2026-07', revision_id: 77, revision_no: 1, input_hash: 'i', result_hash: 'r', enforcement_input_hash: 'e' }],
      xml: '<Odpoved/>',
      xml_sha256: 'b'.repeat(64),
      priority: 1,
      shared_priority: false,
      employment: { active: true, start: '2026-01-01', end: null },
      wages: [{ period: '2026-07', gross_minor: 5000000, withheld_minor: 10000, dependants: 0 }],
    })
    m.freezeResponse.mockResolvedValue({ id: 87, created: true, xml_sha256: 'b'.repeat(64) })
    m.enqueueResponse.mockResolvedValue({ outbox_id: 97, created: true, dispatch_id: 107 })
  })

  it('keeps preview, freeze and databox queue as three explicit steps', async () => {
    const wrapper = mount(PayrollEnforcementCooperation)
    await flushPromises()

    await wrapper.get('[data-test="xmlzam-import"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="xmlzam-period"]').setValue('2026-07')
    await wrapper.get('[data-test="xmlzam-add-period"]').trigger('click')
    await wrapper.get('[data-test="xmlzam-preview"]').trigger('click')
    await flushPromises()

    expect(m.previewResponse).toHaveBeenCalledWith(47, 'production', 67, ['2026-07'])
    expect(m.freezeResponse).not.toHaveBeenCalled()
    expect(m.enqueueResponse).not.toHaveBeenCalled()

    await wrapper.get('[data-test="xmlzam-freeze"]').trigger('click')
    await flushPromises()
    expect(m.freezeResponse).toHaveBeenCalledWith(47, 'production', 67, ['2026-07'], expect.any(String))
    expect(m.enqueueResponse).not.toHaveBeenCalled()

    await wrapper.get('[data-test="xmlzam-enqueue"]').trigger('click')
    await flushPromises()
    expect(m.enqueueResponse).toHaveBeenCalledWith(87, 'production', 57)
    expect(wrapper.text()).toContain('payroll.enforcement_cooperation.queued_hint')
  })

  it('does not offer queueing when the exact active recipient is missing', async () => {
    m.requestDetail.mockResolvedValue({ ...detail, recipient_match_status: 'missing', recipient: null })
    const wrapper = mount(PayrollEnforcementCooperation)
    await flushPromises()

    await wrapper.get('[data-test="xmlzam-import"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="xmlzam-enqueue"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.enforcement_cooperation.recipient_missing')
  })
})
