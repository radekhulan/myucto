import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AttendancePreview } from '@/api/payrollImports'
import type {
  AttendanceApplyWithTimeResult,
  AttendanceTimeApproval,
  AttendanceTimeIssue,
} from '@/api/payrollAttendanceApproval'

const m = vi.hoisted(() => ({
  apply: vi.fn(),
  approve: vi.fn(),
  preview: vi.fn(),
  batches: vi.fn(),
  success: vi.fn(),
  warning: vi.fn(),
  error: vi.fn(),
}))

vi.mock('vue-router', async () => {
  const { defineComponent: define, h: render } = await import('vue')
  return {
    RouterLink: define({
      name: 'RouterLink',
      props: { to: { type: [String, Object], required: true } },
      setup(props, { slots, attrs }) {
        return () => render('a', { ...attrs, 'data-to': JSON.stringify(props.to) }, slots.default?.())
      },
    }),
  }
})
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: { value: 'cs' },
    te: () => true,
    t: (key: string, params?: Record<string, unknown>) => `${key}${params ? JSON.stringify(params) : ''}`,
  }),
}))
vi.mock('@/api/client', () => ({ api: {} }))
vi.mock('@/api/payrollAttendanceApproval', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/payrollAttendanceApproval')>()),
  payrollAttendanceApprovalApi: { applyAttendance: m.apply, approveCleanTimeMonths: m.approve },
}))
vi.mock('@/api/payrollImports', () => ({
  payrollImportsApi: {
    previewAttendance: m.preview,
    attendanceBatches: m.batches,
    attendanceBatch: vi.fn(),
    attendanceProfiles: vi.fn().mockResolvedValue([]),
    createAttendancePersons: vi.fn(),
  },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, warning: m.warning, error: m.error }),
}))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_: unknown, fallback: string) => fallback }))

import {
  groupTimeIssues,
  summaryTimeIssues,
  timeIssueFixLinks,
} from '@/api/payrollAttendanceApproval'
import AttendanceTimeApprovalResult from '../AttendanceTimeApprovalResult.vue'
import AttendanceBatchHistory from '../AttendanceBatchHistory.vue'
import AttendanceImportPanel from '../AttendanceImportPanel.vue'
import { createAttendanceWorkspace, provideAttendanceWorkspace } from '../attendanceWorkspace'

const ABSENCE_MESSAGE = 'Podklady uvádějí nemoc jen jako součet hodin, bez dat od–do.'

function issue(employmentId: number, code: string, message: string): AttendanceTimeIssue {
  return {
    employment_id: employmentId,
    name: `Syntetická osoba ${employmentId}`,
    employment_code: `SYN-${employmentId}`,
    code,
    message,
  }
}

function approval(overrides: Partial<AttendanceTimeApproval> = {}): AttendanceTimeApproval {
  return {
    approved: 12,
    already_approved: 3,
    written: 15,
    replayed: 0,
    exceptions: [
      issue(501, 'absence_hours_without_dates', ABSENCE_MESSAGE),
      issue(502, 'absence_hours_without_dates', ABSENCE_MESSAGE),
      issue(503, 'weekly_hours_missing', 'Pracovní vztah nemá týdenní pracovní dobu.'),
      issue(504, 'approval_failed', 'Syntetická chyba A.'),
      issue(505, 'approval_failed', 'Syntetická chyba B.'),
    ],
    warnings: [issue(506, 'worked_days_not_provided', 'Podklady neuvádějí odpracované dny.')],
    ...overrides,
  }
}

describe('pomocné funkce výsledku schválení', () => {
  it('seskupí výjimky podle kódu a společný text vrátí jen při shodě', () => {
    const groups = groupTimeIssues(approval().exceptions)
    expect(groups.map(group => [group.code, group.items.length])).toEqual([
      ['absence_hours_without_dates', 2],
      ['weekly_hours_missing', 1],
      ['approval_failed', 2],
    ])
    expect(groups[0].message).toBe(ABSENCE_MESSAGE)
    expect(groups[2].message).toBeNull()
  })

  it('výsledek samotného zápisu doplní jména z náhledu', () => {
    const issues = summaryTimeIssues({
      written: 1,
      replayed: 0,
      exceptions: [{ employment_id: 501, message: 'Měsíc je uzamčený.' }],
      warnings: [{ employment_id: 502, code: 'import_fund_mismatch', message: 'Fond nesedí.' }],
    }, new Map([[501, { name: 'Syntetická Alfa', code: 'SYN-1' }]]))
    expect(issues.exceptions).toEqual([{
      employment_id: 501, name: 'Syntetická Alfa', employment_code: 'SYN-1', code: 'summary_not_written', message: 'Měsíc je uzamčený.',
    }])
    expect(issues.warnings[0]).toMatchObject({ employment_id: 502, name: '', code: 'import_fund_mismatch' })
  })

  it('odkaz na nápravu vede na kartu vztahu, absence nebo docházku za období', () => {
    expect(timeIssueFixLinks('weekly_hours_missing', 7, '2026-08').map(link => link.to)).toEqual([
      { name: 'payroll-people', query: { employment: '7', panel: 'employment_terms' } },
      { name: 'payroll-time', query: { employment: '7', period: '2026-08' } },
    ])
    expect(timeIssueFixLinks('absence_hours_without_dates', 7, '2026-08')[0].to)
      .toEqual({ name: 'payroll-absences', query: { employment: '7' } })
    expect(timeIssueFixLinks('approval_conflict', 7, '2026-08').map(link => link.label)).toEqual(['time'])
  })
})

describe('AttendanceTimeApprovalResult', () => {
  it('ukáže počty, text výjimky jednou a osoby s odkazem na nápravu', () => {
    const wrapper = mount(AttendanceTimeApprovalResult, { props: { approval: approval(), period: '2026-08' } })

    expect(wrapper.get('[data-testid="attendance-time-approved"]').text()).toContain('12')
    expect(wrapper.get('[data-testid="attendance-time-exception-count"]').text()).toContain('5')
    const groups = wrapper.findAll('[data-testid="attendance-time-exception-group"]')
    expect(groups).toHaveLength(3)
    expect(groups[0].findAll('[data-testid="attendance-time-group-message"]')).toHaveLength(1)
    expect(groups[0].text().split(ABSENCE_MESSAGE)).toHaveLength(2)
    expect(groups[0].text()).toContain('Syntetická osoba 501')
    expect(groups[0].text()).toContain('Syntetická osoba 502')
    // Různé hlášky téhož kódu patří k jednotlivým osobám.
    expect(groups[2].find('[data-testid="attendance-time-group-message"]').exists()).toBe(false)
    expect(groups[2].text()).toContain('Syntetická chyba A.')
    expect(groups[2].text()).toContain('Syntetická chyba B.')

    const terms = groups[1].get('[data-testid="attendance-time-fix-terms"]')
    expect(JSON.parse(terms.attributes('data-to') ?? '')).toEqual({
      name: 'payroll-people', query: { employment: '503', panel: 'employment_terms' },
    })
    expect(JSON.parse(groups[1].get('[data-testid="attendance-time-fix-time"]').attributes('data-to') ?? ''))
      .toEqual({ name: 'payroll-time', query: { employment: '503', period: '2026-08' } })
  })

  it('varování drží zvlášť mimo výjimky', () => {
    const wrapper = mount(AttendanceTimeApprovalResult, { props: { approval: approval(), period: '2026-08' } })
    const warnings = wrapper.get('[data-testid="attendance-time-warnings"]')
    expect(warnings.text()).toContain('Syntetická osoba 506')
    expect(wrapper.get('[data-testid="attendance-time-exceptions"]').text()).not.toContain('Syntetická osoba 506')
  })

  it('dlouhý seznam osob sbalí', () => {
    const many = Array.from({ length: 30 }, (_, index) => issue(600 + index, 'absence_hours_without_dates', ABSENCE_MESSAGE))
    const wrapper = mount(AttendanceTimeApprovalResult, {
      props: { approval: approval({ exceptions: many, warnings: [] }), period: '2026-08' },
    })
    const group = wrapper.get('[data-testid="attendance-time-exception-group"]')
    expect(group.text()).toContain('Syntetická osoba 600')
    expect(group.text()).not.toContain('Syntetická osoba 629')
    expect(group.find('[data-test="attendance-time-exception-people-absence_hours_without_dates-toggle"]').exists()).toBe(true)
  })

  it('bez schválení řekne, že se měsíce neschvalovaly', () => {
    const wrapper = mount(AttendanceTimeApprovalResult, {
      props: { approval: null, summary: { written: 4, replayed: 0, exceptions: [], warnings: [] }, period: '2026-08' },
    })
    expect(wrapper.find('[data-testid="attendance-time-approved"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="attendance-time-written"]').text()).toContain('4')
    expect(wrapper.find('[data-testid="attendance-time-not-approved"]').exists()).toBe(true)
  })
})

const batch = {
  id: 77,
  period: '2026-08',
  source_system: 'attendance',
  created_at: '2026-09-01 10:00:00',
  created_by_name: null,
  files: [{ name: 'dochazka-syntetická.xlsx', sha256: 'a'.repeat(64) }],
  person_count: 15,
  metric_count: 60,
  input_count: 0,
}

describe('AttendanceBatchHistory — dodatečné schválení', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.batches.mockResolvedValue([batch])
  })

  it('bez oprávnění schvalovat akci nenabídne', async () => {
    const wrapper = mount(AttendanceBatchHistory, { props: { period: '2026-08' } })
    await flushPromises()
    expect(wrapper.find('[data-testid="attendance-history-approve-time"]').exists()).toBe(false)
  })

  it('schválí bezchybné měsíce dávky a pod dávkou ukáže výsledek', async () => {
    m.approve.mockResolvedValue(approval())
    const wrapper = mount(AttendanceBatchHistory, { props: { period: '2026-08', canApprove: true } })
    await flushPromises()
    await wrapper.get('[data-testid="attendance-history-approve-time"]').trigger('click')
    await flushPromises()

    expect(m.approve).toHaveBeenCalledWith(77)
    expect(m.warning).toHaveBeenCalledWith(expect.stringContaining('"approved":12'))
    expect(wrapper.get('[data-testid="attendance-history-approval"]').text()).toContain('Syntetická osoba 503')
  })

  it('chybu schválení ukáže u dávky', async () => {
    m.approve.mockRejectedValue(new Error('403'))
    const wrapper = mount(AttendanceBatchHistory, { props: { period: '2026-08', canApprove: true } })
    await flushPromises()
    await wrapper.get('[data-testid="attendance-history-approve-time"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="attendance-history-approval"] [role="alert"]').text())
      .toBe('payroll_imports.attendance_time.approve_failed')
  })
})

const preview = {
  period: '2026-08',
  content_hash: 'h',
  profile: { id: null, name: null, auto: true },
  files: [],
  sheets: [],
  unrecognized_columns: [],
  rules: [],
  employment_options: [{ employment_id: 501, employee_id: 401, label: 'Syntetická Alfa', code: 'SYN-1', status: 'active' }],
  persons: [{
    key: 'p1',
    display_name: 'Syntetická Alfa',
    personal_number: null,
    birth_number_masked: null,
    relation_label: null,
    department: null,
    cost_center: null,
    position: null,
    weekly_hours: null,
    start_end_note: null,
    monthly_wage: null,
    sources: [],
    match: {
      status: 'matched', matched_by: 'name', employment_id: 501, employee_id: 401,
      employee_name: 'Syntetická Alfa', employment_code: 'SYN-1', candidates: [],
    },
    metrics: [],
    components: [],
    reference: { gross_minor: null, net_minor: null, hours: null },
    warnings: [],
  }],
  component_checks: [],
  wage_changes: [],
  upgrade_available: null,
  summary: { persons: 1, matched: 1, ambiguous: 0, not_found: 0, metrics: 0, components: 0, amount_minor_total: 0 },
} as unknown as AttendancePreview

function applyResult(overrides: Partial<AttendanceApplyWithTimeResult> = {}): AttendanceApplyWithTimeResult {
  return {
    replayed: false,
    batch,
    inputs: { import_id: 9, created: 1, updated: 2, overridden: 1, duplicates: 0, errors: [] },
    links_saved: 0,
    skipped_persons: [],
    personal_numbers_adopted: 0,
    personal_number_conflicts: [],
    monthly_wages_adopted: 0,
    wage_conflicts: [],
    runs_needing_refresh: [],
    time_summary: { written: 15, replayed: 0, exceptions: [], warnings: [] },
    time_approval: approval(),
    ...overrides,
  }
}

const HistoryStub = defineComponent({
  name: 'AttendanceBatchHistory',
  props: { period: { type: String, required: true }, canApprove: { type: Boolean, default: false } },
  setup(_, { expose }) {
    expose({ reload: () => Promise.resolve() })
    return () => h('div')
  },
})

function mountPanel(canApproveTime: boolean) {
  const Host = defineComponent({
    setup() {
      const workspace = createAttendanceWorkspace({ onOpenMapping: () => {}, errorMessage: () => 'x' })
      workspace.files.value = [new File(['syntetická docházka'], 'dochazka.csv')]
      workspace.payloadFiles = async () => []
      provideAttendanceWorkspace(workspace)
      return () => h(AttendanceImportPanel, { canWrite: true, canCreatePersons: false, canApproveTime })
    },
  })
  return mount(Host, {
    global: {
      stubs: {
        AttendancePersonsStep: true,
        AttendanceSummaryStep: true,
        AttendanceRecognitionStrip: true,
        ImportFilesDropzone: true,
        AttendanceBatchHistory: HistoryStub,
      },
    },
  })
}

async function loadToSummary(wrapper: ReturnType<typeof mountPanel>) {
  await wrapper.get('[data-testid="attendance-load"]').trigger('click')
  await flushPromises()
  await wrapper.get('[data-testid="attendance-to-summary"]').trigger('click')
}

describe('AttendanceImportPanel — souhrn a schválení pracovních měsíců', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.preview.mockResolvedValue(preview)
    m.apply.mockResolvedValue(applyResult())
  })

  it('podklady bez hodin a dnů souhrn nezapisují ani neschvalují', async () => {
    const wrapper = mountPanel(true)
    await loadToSummary(wrapper)
    await wrapper.get('[data-testid="attendance-apply"]').trigger('click')
    await flushPromises()

    expect(m.apply).toHaveBeenCalledWith(expect.objectContaining({
      write_time_summary: false,
      approve_clean_time_months: false,
    }))
  })

  it('podklady s hodinami zapisují souhrn výchozí a vypnutí jasně varuje', async () => {
    m.preview.mockResolvedValue({ ...preview, summary: { ...preview.summary, metrics: 1, worked_days: 1 } })
    const wrapper = mountPanel(true)
    await loadToSummary(wrapper)
    const toggle = wrapper.get('[data-testid="attendance-write-time-summary"]')
    expect((toggle.element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.find('[data-testid="attendance-write-time-summary-off"]').exists()).toBe(false)
    await toggle.setValue(false)
    expect(wrapper.get('[data-testid="attendance-write-time-summary-off"]').text())
      .toBe('payroll_imports.attendance_time.options.write_summary_off')
    await toggle.setValue(true)
    await wrapper.get('[data-testid="attendance-apply"]').trigger('click')
    await flushPromises()

    expect(m.apply).toHaveBeenCalledWith(expect.objectContaining({ write_time_summary: true, approve_clean_time_months: false }))
  })

  it('převzetí osobních čísel neslibuje číslo, které náhled k párování nepoužil', async () => {
    const shared = { ...preview.persons[0], personal_number: '1', number_ambiguous: true }
    m.preview.mockResolvedValue({ ...preview, persons: [shared, { ...shared, key: 'p2' }] })
    const wrapper = mountPanel(true)
    await loadToSummary(wrapper)
    expect(wrapper.get('[data-testid="attendance-adopt-ambiguous"]').text())
      .toBe('payroll_imports.attendance.summary.adopt_personal_numbers_ambiguous{"numbers":"1"}')
  })

  it('pošle obě volby a ukáže výsledek schválení i počty vstupů', async () => {
    const wrapper = mountPanel(true)
    await loadToSummary(wrapper)
    const approve = wrapper.get('[data-testid="attendance-approve-clean-time-months"]')
    expect(approve.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="attendance-write-time-summary"]').setValue(true)
    expect(approve.attributes('disabled')).toBeUndefined()
    await approve.setValue(true)
    await wrapper.get('[data-testid="attendance-apply"]').trigger('click')
    await flushPromises()

    expect(m.apply).toHaveBeenCalledWith(expect.objectContaining({
      links: [{ person_key: 'p1', employment_id: 501 }],
      write_time_summary: true,
      approve_clean_time_months: true,
    }))
    expect(wrapper.get('[data-testid="attendance-inputs-updated"]').text()).toContain('"count":2')
    expect(wrapper.get('[data-testid="attendance-inputs-overridden"]').text()).toContain('"count":1')
    expect(wrapper.get('[data-testid="attendance-time-approved"]').text()).toContain('12')
    expect(wrapper.findAll('[data-testid="attendance-time-exception-group"]')).toHaveLength(3)
  })

  it('vypnutím zápisu souhrnu se zruší i schválení', async () => {
    const wrapper = mountPanel(true)
    await loadToSummary(wrapper)
    await wrapper.get('[data-testid="attendance-write-time-summary"]').setValue(true)
    await wrapper.get('[data-testid="attendance-approve-clean-time-months"]').setValue(true)
    await wrapper.get('[data-testid="attendance-write-time-summary"]').setValue(false)
    expect((wrapper.get('[data-testid="attendance-approve-clean-time-months"]').element as HTMLInputElement).checked).toBe(false)
  })

  it('bez oprávnění schvalovat řekne proč a schválení nepošle', async () => {
    const wrapper = mountPanel(false)
    await loadToSummary(wrapper)
    await wrapper.get('[data-testid="attendance-write-time-summary"]').setValue(true)
    expect(wrapper.get('[data-testid="attendance-approve-blocked"]').text())
      .toBe('payroll_imports.attendance_time.options.approve_no_permission')
    await wrapper.get('[data-testid="attendance-approve-clean-time-months"]').setValue(true)
    await wrapper.get('[data-testid="attendance-apply"]').trigger('click')
    await flushPromises()

    expect(m.apply).toHaveBeenCalledWith(expect.objectContaining({ write_time_summary: true, approve_clean_time_months: false }))
    expect(wrapper.find('[data-testid="attendance-result-approve-time"]').exists()).toBe(false)
  })

  it('z výsledku jde dávku dodatečně schválit a výsledek se nahradí', async () => {
    m.apply.mockResolvedValue(applyResult({ time_approval: null }))
    m.approve.mockResolvedValue(approval({ approved: 14, exceptions: [], warnings: [] }))
    const wrapper = mountPanel(true)
    await loadToSummary(wrapper)
    await wrapper.get('[data-testid="attendance-write-time-summary"]').setValue(true)
    await wrapper.get('[data-testid="attendance-apply"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="attendance-time-not-approved"]').exists()).toBe(true)

    await wrapper.get('[data-testid="attendance-result-approve-time"]').trigger('click')
    await flushPromises()

    expect(m.approve).toHaveBeenCalledWith(77)
    expect(m.success).toHaveBeenCalledWith(expect.stringContaining('"approved":14'))
    expect(wrapper.get('[data-testid="attendance-time-approved"]').text()).toContain('14')
    expect(wrapper.find('[data-testid="attendance-time-all-approved"]').exists()).toBe(true)
    expect(wrapper.findComponent(HistoryStub).props('canApprove')).toBe(true)
  })
})

describe('AttendanceImportPanel — náhrady mzdy z hodin nepřítomnosti', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.preview.mockResolvedValue(preview)
    m.apply.mockResolvedValue(applyResult())
  })

  it('volba jde zapnout jen se zápisem souhrnu a bez něj se neposílá', async () => {
    const wrapper = mountPanel(true)
    await loadToSummary(wrapper)
    const compensations = wrapper.get('[data-testid="attendance-materialize-absence-compensations"]')
    expect(compensations.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="attendance-write-time-summary"]').setValue(true)
    expect(compensations.attributes('disabled')).toBeUndefined()
    await compensations.setValue(true)
    await wrapper.get('[data-testid="attendance-write-time-summary"]').setValue(false)
    expect((compensations.element as HTMLInputElement).checked).toBe(false)

    await wrapper.get('[data-testid="attendance-apply"]').trigger('click')
    await flushPromises()
    expect(m.apply).toHaveBeenCalledWith(expect.objectContaining({ materialize_absence_compensations: false }))
  })

  it('pošle volbu a ukáže počty i osoby bez výpočtu', async () => {
    m.apply.mockResolvedValue(applyResult({
      absence_compensation: {
        created: 3,
        updated: 1,
        unchanged: 2,
        cancelled: 0,
        rates: { vacation_hours: 100, doctor_hours: 100, obstacle_employer_hours: 80 },
        skipped: [{ employment_id: 501, meaning: 'sick_hours', reason: 'Náhradu mzdy při DPN nejde ověřit.' }],
        warnings: [{ employment_id: 502, meaning: 'doctor_hours', message: 'Zkontrolujte podklad.' }],
      },
    }))
    const wrapper = mountPanel(true)
    await loadToSummary(wrapper)
    await wrapper.get('[data-testid="attendance-write-time-summary"]').setValue(true)
    await wrapper.get('[data-testid="attendance-materialize-absence-compensations"]').setValue(true)
    await wrapper.get('[data-testid="attendance-apply"]').trigger('click')
    await flushPromises()

    expect(m.apply).toHaveBeenCalledWith(expect.objectContaining({
      write_time_summary: true,
      materialize_absence_compensations: true,
    }))
    expect(wrapper.get('[data-testid="attendance-absence-compensation-counts"]').text()).toContain('"created":3')
    expect(wrapper.get('[data-testid="attendance-absence-compensation-skipped"]').text()).toContain('Náhradu mzdy při DPN nejde ověřit.')
    expect(wrapper.get('[data-testid="attendance-absence-compensation-warnings"]').text()).toContain('Zkontrolujte podklad.')
  })

  it('bez volby výsledek náhrad neukazuje', async () => {
    const wrapper = mountPanel(true)
    await loadToSummary(wrapper)
    await wrapper.get('[data-testid="attendance-apply"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="attendance-absence-compensation"]').exists()).toBe(false)
  })
})
