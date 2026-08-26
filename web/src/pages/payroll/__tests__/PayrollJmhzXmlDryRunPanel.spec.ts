import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  freeze: vi.fn(),
  dryRun: vi.fn(),
  offices: vi.fn(),
  context: vi.fn(),
  canWrite: vi.fn(() => true),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    freezeJmhzPreparation: m.freeze,
    jmhzXmlDryRun: m.dryRun,
    jmhzPvpojOffices: m.offices,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: { context: m.context },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import PayrollJmhzXmlDryRunPanel from '@/pages/payroll/PayrollJmhzXmlDryRunPanel.vue'

const run = {
  id: 8,
  revision_id: 18,
  revision_no: 2,
  revision_status: 'approved',
  period_start: '2026-08-01',
}

const xml = '<?xml version="1.0" encoding="UTF-8"?>\n<jmhz verze="1.4.3"/>'

describe('PayrollJmhzXmlDryRunPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.freeze.mockResolvedValue({ id: 77, readiness_status: 'source_ready' })
    m.context.mockResolvedValue([])
    m.offices.mockResolvedValue([{
      office_id: 4,
      code: 'UC4',
      name: 'Mzdová účtárna 4',
      social_security_variable_symbol: '1234567890',
      submittable: true,
    }])
  })

  /**
   * Měsíční hlášení se podává ZA REGISTRACI u OSSZ. Revize přes víc mzdových
   * účtáren proto nesmí nacvičovat naslepo: dokud si uživatel účtárnu nezvolí,
   * test se nespustí — vykázat lidi jedné účtárny pod variabilním symbolem
   * druhé je horší než nic nespustit.
   */
  it('u dvou registrací žádá volbu účtárny a předá ji do testu', async () => {
    m.offices.mockResolvedValue([
      {
        office_id: 4,
        code: 'UC4',
        name: 'Mzdová účtárna 4',
        social_security_variable_symbol: '1234567890',
        submittable: true,
      },
      {
        office_id: 5,
        code: 'UC5',
        name: 'Mzdová účtárna 5',
        social_security_variable_symbol: '9990001234',
        submittable: true,
      },
    ])
    m.dryRun.mockResolvedValue({
      status: 'dry_run_valid',
      preparation_id: 77,
      office_id: 5,
      blockers: [],
      xml,
      xml_sha256: 'b'.repeat(64),
      schema: {
        package_key: 'jmhz-1.4.3.4',
        data_version: '1.4.3',
        bundle_sha256: 'c'.repeat(64),
        document_sha256: 'd'.repeat(64),
      },
      official_submission: {
        supported: false,
        reason_code: 'jmhz_dry_run_is_not_a_submission',
        reason: 'x',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    const start = wrapper.get('[data-test="jmhz-dry-run-start-18"]')
    expect(start.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="jmhz-dry-run-office-18"] select')
      .setValue('5')
    await start.trigger('click')
    await flushPromises()

    expect(m.dryRun).toHaveBeenCalledWith(77, 'test', 5)
  })

  it('zmrazí přípravu a ukáže XML ověřené proti připnutému schématu', async () => {
    m.dryRun.mockResolvedValue({
      status: 'dry_run_valid',
      preparation_id: 77,
      blockers: [],
      xml,
      xml_sha256: 'b'.repeat(64),
      schema: {
        package_key: 'jmhz-1.4.3.4',
        data_version: '1.4.3',
        bundle_sha256: 'c'.repeat(64),
        document_sha256: 'd'.repeat(64),
      },
      official_submission: {
        supported: false,
        reason_code: 'jmhz_dry_run_is_not_a_submission',
        reason: 'Jde o lokální test, ne o podání.',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
      global: {
        stubs: {
          RouterLink: {
            props: ['to'],
            template: '<a data-test="router-link" :data-to="JSON.stringify(to)"><slot /></a>',
          },
        },
      },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(m.freeze).toHaveBeenCalledWith(18, expect.any(String))
    expect(m.dryRun).toHaveBeenCalledWith(77, 'test', 4)
    expect(wrapper.text()).toContain('jmhz_dry_run_valid')
    expect(wrapper.find('pre').exists()).toBe(false)

    await wrapper.findAll('button')[1].trigger('click')
    expect(wrapper.get('pre').text()).toContain('jmhz')
  })

  it('blokovaný dokument vypíše důvody a nenabídne XML', async () => {
    m.context.mockResolvedValue([
      {
        id: 12,
        employee_id: 13,
        code: 'DPP-13',
        relation_type: 'dpp',
        status: 'active',
        full_name: 'Dana Testovací',
      },
      {
        id: 14,
        employee_id: 11,
        code: 'HPP-11',
        relation_type: 'employment',
        status: 'active',
        full_name: 'Adam Testovací',
      },
    ])
    m.dryRun.mockResolvedValue({
      status: 'blocked',
      preparation_id: 77,
      blockers: [
        {
          code: 'jmhz_taxpayer_declaration_unresolved',
          entity_type: 'person',
          entity_id: 11,
          attribute_ids: ['10419'],
        },
        {
          code: 'jmhz_taxpayer_declaration_unresolved',
          entity_type: 'person',
          entity_id: 13,
          attribute_ids: ['10419'],
        },
        {
          code: 'some_internal_code_not_translated_yet',
          entity_type: 'employment',
          entity_id: 12,
          attribute_ids: [],
        },
      ],
      official_submission: {
        supported: false,
        reason_code: 'jmhz_dry_run_is_not_a_submission',
        reason: 'Jde o lokální test, ne o podání.',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
      global: {
        stubs: {
          RouterLink: {
            props: ['to'],
            template: '<a data-test="router-link" :data-to="JSON.stringify(to)"><slot /></a>',
          },
        },
      },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('jmhz_dry_run_blocked')
    expect(wrapper.text()).toContain('jmhz_dry_run_blockers.unknown')
    expect(wrapper.text()).toContain('jmhz_dry_run_blocker_occurrences')
    expect(wrapper.findAll('[data-test="jmhz-dry-run-blocker"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-test="jmhz-dry-run-technical-detail"]')).toHaveLength(2)
    expect(wrapper.find('[data-test="jmhz-dry-run-remediation-list"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="router-link"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Adam Testovací')
    expect(wrapper.text()).toContain('Dana Testovací')
    expect(wrapper.text()).not.toContain('jmhz_dry_run_actions.record')
    expect(wrapper.find('[data-test="jmhz-dry-run-remediation"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="router-link"]').attributes('data-to'))
      .toContain('payroll-people')
    expect(wrapper.text()).not.toContain('some_internal_code_not_translated_yet')
    expect(wrapper.find('pre').exists()).toBe(false)
  })

  it('u velké firmy nevyrenderuje stovky odkazů na jednotlivé zaměstnance', async () => {
    m.dryRun.mockResolvedValue({
      status: 'blocked',
      preparation_id: 77,
      blockers: Array.from({ length: 50 }, (_, index) => ({
        code: 'jmhz_taxpayer_declaration_unresolved',
        entity_type: 'person',
        entity_id: 10_001 + index,
        attribute_ids: ['10419'],
      })),
      official_submission: {
        supported: false,
        reason_code: 'jmhz_dry_run_is_not_a_submission',
        reason: 'Jde o lokální test, ne o podání.',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
      global: {
        stubs: {
          RouterLink: {
            props: ['to'],
            template: '<a data-test="router-link" :data-to="JSON.stringify(to)"><slot /></a>',
          },
        },
      },
    })
    await flushPromises()
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-test="jmhz-dry-run-remediation"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="jmhz-dry-run-remediation-list"]').exists())
      .toBe(false)
    expect(wrapper.get('[data-test="jmhz-dry-run-remediation"]').attributes('data-to'))
      .toBe('{"name":"payroll-people"}')
    expect(wrapper.text()).not.toContain('10050')
  })

  it('vypíše nepropustné vady z katalogu kontrol i s kódem chyby', async () => {
    m.dryRun.mockResolvedValue({
      status: 'dry_run_incomplete',
      preparation_id: 77,
      blockers: [],
      xml,
      xml_sha256: 'b'.repeat(64),
      schema: {
        package_key: 'jmhz-1.4.3.4',
        data_version: '1.4.3',
        bundle_sha256: 'c'.repeat(64),
        document_sha256: 'd'.repeat(64),
      },
      controls: {
        schema_reference: 'payroll-jmhz-control-evaluation.v1',
        catalog_key: 'jmhz-controls-1.4.2.7-source-v3',
        catalog_manifest_sha256: 'e'.repeat(64),
        submittable: false,
        counts: {
          passed: 70,
          failed: 1,
          not_applicable: 90,
          not_evaluable: 33,
          unimplemented: 5,
        },
        blocking: [
          {
            control_id: 8,
            name: 'Pojistné za zaměstnavatele',
            outcome: 'failed',
            scope: 'pvpoj',
            passability: 'blocking',
            technical: false,
            part: 'pvpoj',
            form_ordinal: null,
            message: 'Pojistné 10024 neodpovídá sazbě.',
            attribute_ids: ['10024', '10023'],
            error_code: 20008,
          },
        ],
        warnings: [],
        coverage_gaps: [
          {
            control_id: 59,
            name: 'Vyměřovací základ s podmínkami',
            outcome: 'unimplemented',
            scope: 'employee_form',
            passability: 'blocking',
            technical: false,
            part: 'submission',
            form_ordinal: 0,
            message: 'Kontrola dopadá na podání, ale implementaci nemá.',
            attribute_ids: ['10245'],
            error_code: 20059,
          },
        ],
        evaluated: [],
      },
      official_submission: {
        supported: false,
        reason_code: 'jmhz_dry_run_is_not_a_submission',
        reason: 'Jde o lokální test, ne o podání.',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('jmhz_dry_run_incomplete')
    expect(wrapper.get('[data-test="jmhz-controls-blocking"]').text()).toContain(
      'Pojistné 10024 neodpovídá sazbě.',
    )
    expect(wrapper.get('[data-test="jmhz-controls-blocking"]').text()).toContain('20008')
    expect(wrapper.get('[data-test="jmhz-controls-gaps"]').text()).toContain('20059')
    expect(wrapper.find('[data-test="jmhz-controls-warnings"]').exists()).toBe(false)
  })

  it('v režimu jen pro čtení nespustí test', async () => {
    m.canWrite.mockReturnValue(false)

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })

    expect(
      wrapper.get('[data-test="jmhz-dry-run-start-18"]').attributes('disabled'),
    ).toBeDefined()
    expect(m.freeze).not.toHaveBeenCalled()
  })
})
