import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  runs: vi.fn(),
  candidates: vi.fn(),
  attach: vi.fn(),
  canWrite: true,
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzExternalRoots: m.list,
    runs: m.runs,
    jmhzExternalRootCandidates: m.candidates,
    attachJmhzExternalRoot: m.attach,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => m.canWrite }),
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
  }),
}))

import PayrollJmhzExternalCorrectionRoot from '@/pages/payroll/PayrollJmhzExternalCorrectionRoot.vue'

const ROOT = {
  id: 3,
  environment: 'production',
  submission_id: 41,
  mode: 'guid_reference',
  submission_guid: '0197F4C1-8A2D-7D4E-B8C7-5F1E2D3C4B5A',
  period: '2026-07',
  form_count: 1,
  unmatched_form_count: 0,
  original_sha256: null,
  note: 'Podal předchozí program.',
  created_by: 1,
  created_at: '2026-11-12 10:00:00',
  submission_status: 'accepted',
}

function mountPanel() {
  return mount(PayrollJmhzExternalCorrectionRoot, {
    props: { environment: 'production' },
    attachTo: document.body,
    global: { stubs: { teleport: true } },
  })
}

describe('PayrollJmhzExternalCorrectionRoot', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite = true
    m.list.mockResolvedValue({ environment: 'production', items: [] })
    m.runs.mockResolvedValue([
      { id: 7, status: 'approved', revision_id: 70, revision_no: 1, office_id: 5, period_start: '2026-07-01' },
      { id: 8, status: 'calculated', revision_id: 80, revision_no: 1, office_id: 5, period_start: '2026-08-01' },
    ])
    m.candidates.mockResolvedValue({
      preparation_id: 9,
      period: '2026-07',
      variable_symbol: '1100001237',
      employments: [
        { employment_id: 11, employee_id: 1, employee_name: 'Adam Předchozí', employment_external_identifier: '200000000000000000001' },
        { employment_id: 12, employee_id: 2, employee_name: 'Běla Předchozí', employment_external_identifier: '200000000000000000002' },
      ],
    })
    m.attach.mockResolvedValue({ ...ROOT, created: true })
  })

  it('lists taken-over reports and opens the submission', async () => {
    m.list.mockResolvedValue({ environment: 'production', items: [ROOT] })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.find('[data-test="jmhz-external-root-show-3"]').trigger('click')
    expect(wrapper.emitted('open-submission')?.[0]).toEqual([{ submissionId: 41, period: '2026-07' }])
    wrapper.unmount()
  })

  it('takes over a report by GUIDs only with confirmation and a note', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.find('[data-test="jmhz-external-root-open"]').trigger('click')
    await flushPromises()
    const options = wrapper.findAll('[data-test="jmhz-external-root-run"] option')
    expect(options).toHaveLength(2)
    await wrapper.find('[data-test="jmhz-external-root-run"]').setValue('7')
    await wrapper.find('[data-test="jmhz-external-root-mode-guids"]').setValue(true)
    await flushPromises()
    expect(m.candidates).toHaveBeenCalledWith(70, 5, 'production')
    await wrapper.find('[data-test="jmhz-external-root-guid"]').setValue(ROOT.submission_guid)
    await wrapper.find('[data-test="jmhz-external-root-form-11"]').setValue('0197F4C1-8A2D-7D4E-B8C7-5F1E2D3C4B5B')
    await wrapper.find('[data-test="jmhz-external-root-note"]').setValue('Podal předchozí program, protokol přijato.')
    const submit = wrapper.find('[data-test="jmhz-external-root-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    await wrapper.find('[data-test="jmhz-external-root-confirm"]').setValue(true)
    const enabled = wrapper.find('[data-test="jmhz-external-root-submit"]')
    expect(enabled.attributes('disabled')).toBeUndefined()
    await enabled.trigger('click')
    await flushPromises()
    expect(m.attach).toHaveBeenCalledWith({
      revision_id: 70,
      office: 5,
      mode: 'guid_reference',
      note: 'Podal předchozí program, protokol přijato.',
      submission_guid: ROOT.submission_guid,
      forms: [{ employment_id: 11, form_guid: '0197F4C1-8A2D-7D4E-B8C7-5F1E2D3C4B5B' }],
      forms_confirmed: true,
    }, 'production')
    expect(wrapper.emitted('open-submission')?.[0]).toEqual([{ submissionId: 41, period: '2026-07' }])
    wrapper.unmount()
  })

  it('shows the server reason when the XML does not match', async () => {
    m.attach.mockRejectedValue({ response: { data: { error: { code: 'jmhz_external_root_scope_mismatch', message: 'Variabilní symbol nesedí.' } } } })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.find('[data-test="jmhz-external-root-open"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="jmhz-external-root-run"]').setValue('7')
    const file = new File(['<jmhz/>'], 'puvodni.xml', { type: 'application/xml' })
    const input = wrapper.find('[data-test="jmhz-external-root-file"]')
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await flushPromises()
    await wrapper.find('[data-test="jmhz-external-root-note"]').setValue('Podal předchozí program, protokol přijato.')
    await wrapper.find('[data-test="jmhz-external-root-submit"]').trigger('click')
    await flushPromises()
    expect(m.attach.mock.calls[0][0]).toMatchObject({ mode: 'original_xml', xml_base64: btoa('<jmhz/>') })
    expect(wrapper.find('[data-test="jmhz-external-root-error"]').text()).toContain('Variabilní symbol nesedí.')
    wrapper.unmount()
  })

  it('hides the action without write permission', async () => {
    m.canWrite = false
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="jmhz-external-root-open"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
