import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AgreementImportPerson, AgreementImportPreview } from '@/api/payrollImports'

const m = vi.hoisted(() => ({
  preview: vi.fn(),
  apply: vi.fn(),
  template: vi.fn(),
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
      setup(props, { slots }) {
        return () => render('a', { 'data-to': JSON.stringify(props.to) }, slots.default?.())
      },
    }),
  }
})
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string, params?: Record<string, unknown>) => `${key}${params ? JSON.stringify(params) : ''}`,
  }),
}))
vi.mock('@/api/client', () => ({ api: {} }))
vi.mock('@/api/payrollImports', () => ({
  payrollImportsApi: { previewAgreements: m.preview, applyAgreements: m.apply, downloadAgreementTemplate: m.template },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, warning: m.warning, error: m.error }),
}))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_: unknown, fallback: string) => fallback }))

import AgreementImportPanel from '../AgreementImportPanel.vue'

function person(key: string, overrides: Partial<AgreementImportPerson> = {}): AgreementImportPerson {
  return {
    key,
    display_name: `Syntetická ${key}`,
    personal_number: null,
    birth_number_masked: '••••••••08',
    birth_date: null,
    existing_person: null,
    warnings: [],
    errors: [],
    agreements: [{
      key: `Dohody!${key}`,
      sheet: 'Dohody',
      row: 2,
      relation_type: 'dpp',
      relation_label: 'DPP',
      start_on: '2026-12-01',
      end_on: '2026-12-31',
      status: 'create',
      note: null,
      errors: [],
      warnings: [],
      registration: [{ key: 'work_mode_code', value: '1' }],
    }],
    ...overrides,
  }
}

function preview(persons: AgreementImportPerson[]): AgreementImportPreview {
  return {
    persons,
    files: [{ name: 'dohody.xlsx', rows: persons.length, error: null }],
    summary: { persons: persons.length, new_persons: persons.length, existing_persons: 0, confirm: 0, create: persons.length, exists: 0, errors: 0 },
  }
}

const DropzoneStub = defineComponent({
  name: 'ImportFilesDropzoneStub',
  props: { files: { type: Array, default: () => [] } },
  emits: ['update:files'],
  setup: () => () => h('div'),
})

async function mountWithPreview() {
  const wrapper = mount(AgreementImportPanel, {
    props: { canWrite: true },
    global: { stubs: { ImportFilesDropzone: DropzoneStub } },
  })
  wrapper.findComponent(DropzoneStub).vm.$emit('update:files', [new File(['x'], 'dohody.xlsx')])
  await flushPromises()
  await wrapper.get('[data-testid="agreement-preview"]').trigger('click')
  await vi.waitFor(() => expect(wrapper.find('[data-testid="agreement-apply"]').exists()).toBe(true))
  return wrapper
}

describe('AgreementImportPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.apply.mockResolvedValue({ results: [], counts: { created: 1, existing: 0, skipped: 0, failed: 0 } })
  })

  it('blocks creation until a name and birth date match is confirmed, then sends the decision', async () => {
    m.preview.mockResolvedValue(preview([
      person('osoba-1', {
        existing_person: { employee_id: 42, employee_name: 'Syntetická Olga', matched_by: 'name_birth_date', requires_confirmation: true },
      }),
    ]))
    const wrapper = await mountWithPreview()
    const apply = wrapper.get('[data-testid="agreement-apply"]')
    expect((apply.element as HTMLButtonElement).disabled).toBe(true)
    expect(wrapper.text()).toContain('payroll_imports.agreements.reason.unconfirmed')
    expect(wrapper.text()).toContain('payroll_imports.attendance.registration.work_mode_code 1')

    await wrapper.get('[data-testid="agreement-person-osoba-1"] input[type="radio"]').setValue(true)
    expect((apply.element as HTMLButtonElement).disabled).toBe(false)
    await apply.trigger('click')
    await vi.waitFor(() => expect(m.preview).toHaveBeenCalledTimes(2))

    expect(m.apply).toHaveBeenCalledTimes(1)
    const payload = m.apply.mock.calls[0][0]
    expect(payload.confirmations).toEqual({ 'osoba-1': 42 })
    expect(payload.person_keys).toEqual(['osoba-1'])
    expect(m.success).toHaveBeenCalled()
    expect(m.preview).toHaveBeenCalledTimes(2)
  })

  it('creates agreements in chunks of 50 persons and skips persons without anything to create', async () => {
    const persons = Array.from({ length: 150 }, (_, index) => person(`osoba-${index + 1}`))
    persons.push(person('chyba', { errors: ['Chybí jméno nebo příjmení osoby.'] }))
    m.preview.mockResolvedValue(preview(persons))
    const wrapper = await mountWithPreview()
    await wrapper.get('[data-testid="agreement-apply"]').trigger('click')
    await vi.waitFor(() => expect(wrapper.find('[data-testid="agreement-result"]').exists()).toBe(true))

    expect(m.apply).toHaveBeenCalledTimes(3)
    expect(m.apply.mock.calls.map(call => call[0].person_keys.length)).toEqual([50, 50, 50])
    expect(m.apply.mock.calls.flatMap(call => call[0].person_keys)).not.toContain('chyba')
    expect(wrapper.get('[data-testid="agreement-result"]').text()).toContain('"created":3')
  })
})
