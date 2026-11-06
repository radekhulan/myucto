import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AttendanceProfile } from '@/api/payrollImports'

const m = vi.hoisted(() => ({
  restore: vi.fn(),
  download: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: { value: 'cs' },
    te: () => true,
    t: (key: string, params?: Record<string, unknown>) => `${key}${params ? JSON.stringify(params) : ''}`,
  }),
}))
vi.mock('@/api/client', () => ({ api: {} }))
vi.mock('@/api/payrollImports', () => ({
  payrollImportsApi: { restoreAttendanceSampleProfile: m.restore, downloadAttendanceTemplate: m.download },
}))
vi.mock('@/pages/payroll/payrollComponentsUi', () => ({ payrollWorkingPeriod: () => '2026-06' }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ availableSuppliers: [], currentSupplierId: 1 }) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, warning: vi.fn(), error: m.error }),
}))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_: unknown, fallback: string) => fallback }))

import AttendanceMappingPanel from '../AttendanceMappingPanel.vue'
import AttendanceTemplateDownload from '../AttendanceTemplateDownload.vue'
import { provideAttendanceWorkspace, type AttendanceWorkspace } from '../attendanceWorkspace'
import { ATTENDANCE_PAMICA_TEMPLATE_FILENAME, ATTENDANCE_TEMPLATE_FILENAME, missingSampleKeys, profileSampleKey } from '../importHelpers'

function profile(id: number, overrides: Partial<AttendanceProfile> = {}): AttendanceProfile {
  return {
    id,
    name: `Profil ${id}`,
    rules: [{ sheet: null, header: 'Jméno', meaning: 'person_name', unit: null, component_code: null }],
    components: [],
    is_sample: false,
    sample_key: null,
    sample_version: null,
    updated_at: '2026-06-01 10:00:00',
    ...overrides,
  }
}

const giriton = () => profile(1, { name: 'Vzor GIRITON', is_sample: true, sample_key: 'giriton', sample_version: 9 })
const myucto = () => profile(2, { name: 'Vzor MyÚčto', is_sample: true, sample_key: 'myucto', sample_version: 1 })
const pamica = () => profile(4, { name: 'Vzor PAMICA', is_sample: true, sample_key: 'pamica', sample_version: 1 })

function workspace(profiles: AttendanceProfile[]): AttendanceWorkspace {
  const list = ref(profiles)
  return {
    period: ref('2026-06'),
    files: ref([]),
    profiles: list,
    profilesLoading: ref(false),
    profilesError: ref(''),
    profileRevision: ref(null),
    mappingFocus: ref(null),
    sampleUpgrade: ref(null),
    loadProfiles: vi.fn(),
    upsertProfile: (saved: AttendanceProfile) => { list.value = [...list.value.filter(item => item.id !== saved.id), saved] },
    removeProfile: vi.fn(),
    payloadFiles: vi.fn(),
    openMapping: vi.fn(),
  }
}

function mountPanel(profiles: AttendanceProfile[], props = { canManage: true, canPreview: true }) {
  const host = defineComponent({
    setup() {
      provideAttendanceWorkspace(workspace(profiles))
      return () => h(AttendanceMappingPanel, props)
    },
  })
  return mount(host, {
    global: {
      stubs: {
        ImportFilesDropzone: true,
        AttendanceMappingStep: true,
        AttendanceRecognitionStrip: true,
        AttendanceRuleEditor: true,
        AttendanceProfileCopyDialog: true,
        ActionBar: true,
      },
    },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})

describe('vestavěné vzory profilu', () => {
  it('vzor bez klíče ze staršího serveru je GIRITON a chybějící vzory jdou obnovit', () => {
    expect(profileSampleKey(profile(5, { is_sample: true, sample_key: undefined }))).toBe('giriton')
    expect(profileSampleKey(profile(6))).toBeNull()
    expect(missingSampleKeys([giriton()])).toEqual(['myucto', 'pamica'])
    expect(missingSampleKeys([giriton(), myucto(), pamica(), profile(3)])).toEqual([])
    expect(missingSampleKeys([profile(3)])).toEqual(['giriton', 'myucto', 'pamica'])
  })

  it('nabízí obnovu jen smazaného vzoru a obnoví ho podle klíče', async () => {
    m.restore.mockResolvedValue({ profile: myucto(), restored: true })
    const wrapper = mountPanel([giriton(), pamica()])
    await flushPromises()

    expect(wrapper.find('[data-testid="attendance-profile-restore-sample-giriton"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="attendance-profile-restore-sample-pamica"]').exists()).toBe(false)
    const button = wrapper.get('[data-testid="attendance-profile-restore-sample-myucto"]')
    expect(button.text()).toBe('payroll_imports.mapping.restore_sample_myucto')
    await button.trigger('click')
    await flushPromises()

    expect(m.restore).toHaveBeenCalledWith('myucto')
    expect(m.success).toHaveBeenCalledWith('payroll_imports.mapping.sample_restored{"name":"Vzor MyÚčto"}')
    expect(wrapper.find('[data-testid="attendance-profile-restore-sample-myucto"]').exists()).toBe(false)
  })

  it('bez vzorů nabízí obnovu GIRITON, MyÚčto i PAMICA, bez práva nastavení žádnou', async () => {
    const wrapper = mountPanel([profile(3)])
    await flushPromises()
    expect(wrapper.find('[data-testid="attendance-profile-restore-sample-giriton"]').text()).toBe('payroll_imports.mapping.restore_sample')
    expect(wrapper.find('[data-testid="attendance-profile-restore-sample-myucto"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="attendance-profile-restore-sample-pamica"]').text()).toBe('payroll_imports.mapping.restore_sample_pamica')

    const readOnly = mountPanel([profile(3)], { canManage: false, canPreview: true })
    await flushPromises()
    expect(readOnly.find('[data-testid^="attendance-profile-restore-sample"]').exists()).toBe(false)
  })

  it('u Vzoru MyÚčto nabízí šablonu XLSX, u GIRITON ne', async () => {
    m.download.mockResolvedValue({})
    const wrapper = mountPanel([giriton(), myucto()])
    await flushPromises()
    // První profil v seznamu je GIRITON.
    expect(wrapper.find('[data-testid="attendance-profile-myucto-hint"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="attendance-template-download"]').exists()).toBe(false)

    await wrapper.get('[data-testid="attendance-profile-2"]').trigger('click')
    await flushPromises()
    const hint = wrapper.get('[data-testid="attendance-profile-myucto-hint"]')
    expect(hint.text()).toContain('payroll_imports.mapping.sample_hint_myucto')
    await hint.get('[data-testid="attendance-template-download"]').trigger('click')
    await flushPromises()
    expect(m.download).toHaveBeenCalledWith(ATTENDANCE_TEMPLATE_FILENAME, 'myucto', undefined)
  })

  it('u Vzoru PAMICA nabízí ukázkový XML za vybraný měsíc', async () => {
    m.download.mockResolvedValue({})
    const wrapper = mountPanel([giriton(), pamica()])
    await flushPromises()
    await wrapper.get('[data-testid="attendance-profile-4"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="attendance-profile-myucto-hint"]').exists()).toBe(false)
    const hint = wrapper.get('[data-testid="attendance-profile-pamica-hint"]')
    expect(hint.text()).toContain('payroll_imports.mapping.sample_hint_pamica')
    const button = hint.get('[data-testid="attendance-template-download"]')
    expect(button.text()).toBe('payroll_imports.template.pamica_download')
    await button.trigger('click')
    await flushPromises()
    expect(m.download).toHaveBeenCalledWith(ATTENDANCE_PAMICA_TEMPLATE_FILENAME, 'pamica', '2026-06')
  })

  it('bez práva na import šablonu u vzoru nenabízí', async () => {
    const wrapper = mountPanel([myucto()], { canManage: true, canPreview: false })
    await flushPromises()
    expect(wrapper.find('[data-testid="attendance-profile-myucto-hint"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="attendance-template-download"]').exists()).toBe(false)
  })
})

describe('tlačítko šablony XLSX', () => {
  it('stáhne šablonu a chybu ohlásí', async () => {
    m.download.mockRejectedValueOnce(new Error('síť'))
    const wrapper = mount(AttendanceTemplateDownload)
    const button = wrapper.get('[data-testid="attendance-template-download"]')
    expect(button.text()).toBe('payroll_imports.template.download')
    expect(button.attributes('title')).toBe('payroll_imports.template.hint')

    await button.trigger('click')
    await flushPromises()
    expect(m.download).toHaveBeenCalledWith('podklady-mzdy-myucto.xlsx', 'myucto', undefined)
    expect(m.error).toHaveBeenCalledWith('payroll_imports.template.failed')
  })

  it('zakázané tlačítko nic nestahuje', async () => {
    const wrapper = mount(AttendanceTemplateDownload, { props: { disabled: true } })
    await wrapper.get('[data-testid="attendance-template-download"]').trigger('click')
    expect(m.download).not.toHaveBeenCalled()
  })
})
