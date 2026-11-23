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

  it('bez sazby ukáže pravděpodobný výdělek z odměny za odpracované hodiny', async () => {
    const iva = person('iva', {
      display_name: 'Výdělková Iva',
      probable_earning: { source: 'amount', hourly_minor: 3_333, amount_minor: 10_000, hours: '3.00' },
    })
    const wrapper = mount(AttendancePersonsStep, {
      props: { persons: [iva], options: [], manualLinks: {}, period: '2026-06', canCreate: true },
    })
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true)
    const details = wrapper.get('[data-testid="attendance-create-details"]').text()
    expect(details).toContain('payroll_imports.attendance.persons.details.probable_earning')
    expect(details).toMatch(/33,33/)
  })

  it('u osoby z evidence řekne, že vznikne jen nový vztah', async () => {
    const anna = person('anna', {
      display_name: 'Lednová Anna',
      existing_person: { employee_id: 5, employee_name: 'Anna Lednová', matched_by: 'employment_code', relation_type: 'dpp' },
    })
    const wrapper = mount(AttendancePersonsStep, {
      props: { persons: [anna], options: [], manualLinks: {}, period: '2026-02', canCreate: true },
    })
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true)
    expect(wrapper.get('[data-testid="attendance-create-existing"]').text())
      .toBe('payroll_imports.attendance.persons.existing_person{"name":"Anna Lednová"}')
  })

  it('shodu jména a data narození nechá potvrdit; bez volby osobu nezaloží', async () => {
    const eva = person('eva', {
      display_name: 'Shodná Eva',
      existing_person: { employee_id: 42, employee_name: 'Eva Shodná', matched_by: 'name_birth_date', relation_type: 'dpp', requires_confirmation: true },
    })
    const wrapper = mount(AttendancePersonsStep, {
      props: { persons: [eva], options: [], manualLinks: {}, period: '2026-04', canCreate: true },
    })
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true)
    expect(wrapper.find('[data-testid="attendance-create-existing"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="attendance-existing-confirm"]').text()).toContain('Eva Shodná')
    const submit = wrapper.get('[data-testid="attendance-create-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="attendance-existing-confirm-existing"]').setValue(true)
    await wrapper.get('[data-testid="attendance-create-submit"]').trigger('click')
    const [payload] = wrapper.emitted('create')?.[0] ?? []
    expect(payload).toMatchObject([{ person_key: 'eva', confirm_existing_person: 42 }])
  })

  it('rozpor rodného a osobního čísla počítá ve filtru a k založení nenabídne', () => {
    const rozpor = person('rozpor', {
      display_name: 'Rozporná Iva',
      personal_number: '1',
      match: { status: 'conflict', matched_by: null, employment_id: null, employee_id: null, employee_name: null, employment_code: null, conflict: 'Rozpor v podkladech.', candidates: [] },
      errors: ['Rozpor v podkladech.'],
    })
    const wrapper = mount(AttendancePersonsStep, {
      props: { persons: [rozpor], options: [], manualLinks: {}, period: '2026-04', canCreate: true },
    })
    expect(wrapper.text()).toContain('payroll_imports.attendance.persons.filters.conflict')
    expect(wrapper.text()).toContain('payroll_imports.attendance.persons.status.conflict')
    expect(wrapper.get('[data-testid="attendance-person-errors"]').text()).toContain('Rozpor v podkladech.')
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
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

  it('hromadný výběr všech nenalezených zachová druh vztahu z podkladů, společná volba platí jen bez údaje', async () => {
    const dpp = person('b001', { display_name: 'Brigádní Adam', relation_label: 'DPP' })
    const dpc = person('b002', { display_name: 'Brigádní Bedřich', personal_number: 'B002', relation_label: 'Dohoda o pracovní činnosti' })
    const unknown = person('b003', { display_name: 'Brigádní Cyril', personal_number: 'B003', relation_label: null })
    const returning = person('b004', {
      display_name: 'Brigádní Dana', personal_number: 'B004', relation_label: null,
      existing_person: { employee_id: 9, employee_name: 'Dana Brigádní', matched_by: 'employment_code', relation_type: 'dpp' },
    })
    const wrapper = mount(AttendancePersonsStep, {
      props: { persons: [dpp, dpc, unknown, returning], options: [], manualLinks: {}, period: '2026-04', canCreate: true },
    })

    await wrapper.get('[data-testid="attendance-select-all-creatable"]').trigger('click')
    expect(wrapper.get('[data-testid="attendance-create-relation-type-hint"]').text())
      .toBe('payroll_imports.attendance.persons.relation_type_fallback_hint{"count":1}')
    const relations = wrapper.findAll('[data-testid="attendance-create-relation"]').map(item => item.text())
    expect(relations[0]).toContain('payroll_imports.relation_types.dpp')
    expect(relations[0]).toContain('payroll_imports.attendance.persons.relation_origin_source')
    expect(relations[2]).toContain('payroll_imports.attendance.persons.relation_origin_common')

    await wrapper.get('[data-testid="attendance-create-relation-type"]').setValue('employment')
    await wrapper.get('[data-testid="attendance-create-submit"]').trigger('click')
    const payload = (wrapper.emitted('create')?.[0]?.[0] ?? []) as AttendancePersonCreate[]
    expect(payload.map(item => [item.person_key, item.relation_type])).toEqual([
      ['b001', 'dpp'], ['b002', 'dpc'], ['b003', 'employment'], ['b004', 'dpp'],
    ])
  })

  it('řádek s neplatným rodným číslem řekne, že se přeskočí, a nabídne ruční založení', async () => {
    const invalid = person('b040', {
      display_name: 'Chybný Bohdan', personal_number: 'B040',
      errors: ['Rodné číslo: v podkladech není platné (nesedí kontrolní číslice), novou osobu s ním aplikace nezaloží.'],
    })
    const wrapper = mount(AttendancePersonsStep, {
      props: { persons: [invalid], options: [], manualLinks: {}, period: '2026-04', canCreate: true },
    })
    const next = wrapper.get('[data-testid="attendance-person-error-next"]')
    expect(next.text()).toContain('payroll_imports.attendance.persons.error_row_next{"number":"B040"}')
    expect(next.find('[data-testid="attendance-person-create-manually"]').exists()).toBe(true)

    await wrapper.setProps({ manualLinks: { b040: 7 } })
    expect(wrapper.find('[data-testid="attendance-person-error-next"]').exists()).toBe(false)
  })
})
