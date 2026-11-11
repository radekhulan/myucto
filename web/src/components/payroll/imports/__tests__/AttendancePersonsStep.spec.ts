import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import type { AttendancePerson, AttendancePersonCreate } from '@/api/payrollImports'

vi.mock('vue-router', async () => {
  const { defineComponent: define, h: render } = await import('vue')
  return {
    RouterLink: define({
      name: 'RouterLink',
      props: { to: { type: [String, Object], required: true } },
      setup(_, { slots }) {
        return () => render('a', {}, slots.default?.())
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

import AttendancePersonsStep from '../AttendancePersonsStep.vue'

function person(key: string, overrides: Partial<AttendancePerson> = {}): AttendancePerson {
  return {
    key,
    display_name: 'Sazbová Alena',
    personal_number: 'B001',
    birth_number_masked: null,
    relation_label: 'DPP',
    department: null,
    cost_center: null,
    position: null,
    weekly_hours: null,
    start_end_note: null,
    monthly_wage: null,
    sources: [],
    match: {
      status: 'not_found',
      matched_by: null,
      employment_id: null,
      employee_id: null,
      employee_name: null,
      employment_code: null,
      candidates: [],
    },
    metrics: [],
    components: [],
    reference: { gross_minor: null, net_minor: null, hours: null },
    warnings: [],
    ...overrides,
  }
}

describe('AttendancePersonsStep — náhled zakládání osob', () => {
  it('ukáže převzatou sazbu, konec dohody a maskovaný účet a pošle je k založení', async () => {
    const alena = person('alena', {
      hourly_rate: '180,50', end_date: '31. 12. 2026', agreed_end_on: '2026-12-31',
      bank_account_masked: '••••••0005/0100', start_on: '2026-06-15',
    })
    const bela = person('bela', { display_name: 'Chybná Běla', personal_number: 'B002', hourly_rate: 'dle dohody' })
    const wrapper = mount(AttendancePersonsStep, {
      props: { persons: [alena, bela], options: [], manualLinks: {}, period: '2026-06', canCreate: true },
    })

    const boxes = wrapper.findAll('input[type="checkbox"]')
    await boxes[0].setValue(true)
    await boxes[1].setValue(true)

    const details = wrapper.findAll('[data-testid="attendance-create-details"]')
    expect(details).toHaveLength(2)
    const first = details[0].text()
    expect(first).toContain('payroll_imports.attendance.persons.details.hourly_rate')
    expect(first).toMatch(/180,50/)
    expect(first).toContain('payroll_imports.attendance.persons.details.agreed_end_on')
    expect(first).toMatch(/31\. ?12\. ?2026/)
    expect(first).toContain('••••••0005/0100')
    expect(details[1].text()).toContain('payroll_imports.attendance.persons.details.unreadable{"value":"dle dohody"}')
    expect(details[1].find('.text-warning-700').exists()).toBe(true)

    await wrapper.find('[data-testid="attendance-create-submit"]').trigger('click')
    const [payload] = wrapper.emitted('create')?.[0] ?? []
    expect(payload).toMatchObject([
      { person_key: 'alena', hourly_rate: '180,50', fixed_term_end_on: '31. 12. 2026', planned_start_on: '2026-06-15' },
      { person_key: 'bela', hourly_rate: 'dle dohody', fixed_term_end_on: null },
    ])
  })

  it('ukáže údaje pro registraci ČSSZ, které se nové osobě zapíšou', async () => {
    const hana = person('hana', {
      display_name: 'Registrační Hana',
      registration: [{ key: 'address', value: 'Praha (CZ)' }, { key: 'cz_isco_code', value: '43111' }],
    })
    const wrapper = mount(AttendancePersonsStep, {
      props: { persons: [hana], options: [], manualLinks: {}, period: '2026-06', canCreate: true },
    })
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true)
    const text = wrapper.get('[data-testid="attendance-create-registration"]').text()
    expect(text).toContain('payroll_imports.attendance.persons.registration_label')
    expect(text).toContain('payroll_imports.attendance.registration.address Praha (CZ)')
    expect(text).toContain('payroll_imports.attendance.registration.cz_isco_code 43111')
  })

  it('u osoby s otevřenou DPP nabídne použít ji a jako výchozí pošle její použití', async () => {
    const eva = person('eva', {
      display_name: 'Ukázková Eva',
      open_agreement: {
        employment_id: 77, employee_id: 5, employee_name: 'Eva Ukázková', code: 'B001', status: 'active',
        start_date: '2026-05-01', end_date: '2026-06-30', reusable: true, extend_to: '2026-12-31',
      },
    })
    const wrapper = mount(AttendancePersonsStep, {
      props: { persons: [eva], options: [], manualLinks: {}, period: '2026-06', canCreate: true },
    })
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true)

    const box = wrapper.get('[data-testid="attendance-open-agreement"]')
    expect(box.text()).toContain('payroll_imports.attendance.persons.open_agreement.guidance')
    expect(box.text()).toContain('payroll_imports.attendance.persons.open_agreement.reuse_extend')
    expect((wrapper.get('[data-testid="attendance-open-agreement-reuse"]').element as HTMLInputElement).checked).toBe(true)

    await wrapper.get('[data-testid="attendance-create-submit"]').trigger('click')
    expect(wrapper.emitted('create')?.[0]?.[0]).toMatchObject([{ person_key: 'eva', relation_type: 'dpp', use_open_agreement: 77 }])

    await wrapper.get('[data-testid="attendance-open-agreement-new"]').setValue(true)
    await wrapper.get('[data-testid="attendance-create-submit"]').trigger('click')
    const payload = (wrapper.emitted('create')?.[1]?.[0] ?? []) as AttendancePersonCreate[]
    expect(payload).toMatchObject([{ person_key: 'eva', create_new_agreement: true }])
    expect(payload?.[0]).not.toHaveProperty('use_open_agreement')
  })
})
