import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { jmhzEvidenceCodes, jmhzEvidenceGuidance } from '../jmhzEvidenceGuidance'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import PayrollJmhzOrdinaryEvidencePanel from '../PayrollJmhzOrdinaryEvidencePanel.vue'

const mocks = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/api/payroll', () => ({ payrollApi: { jmhzOrdinaryEvidence: mocks.get } }))
vi.mock('@/composables/useFormat', () => ({ formatPeriod: () => 'srpen 2026' }))

async function render(code: string, context: Record<string, string> = {}, locale = 'cs') {
  mocks.get.mockResolvedValue({ scopes: [{
    employee_id: 11, employment_id: 101, employee_name: 'Osoba A',
    confirmed: false, resolution: 'attention_required', attention_code: code,
    attention_context: context, attention_message: 'Ordinary evidence neodpovida pripnute specifikaci JMHZ.',
  }], evidences: [] })
  const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
    props: { runs: [{ id: 8, revision_id: 18, revision_no: 2, period_start: '2026-08-01' }] as never[] },
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })],
      stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } },
    },
  })
  await flushPromises()
  return wrapper
}

describe('JMHZ srozumitelná náprava', () => {
  it('vysvětlí změnu verze a nevyžaduje opravit zaměstnance nebo znovu odeslat přijaté hlášení', async () => {
    const wrapper = await render('jmhz_ordinary_evidence_specification_mismatch', {
      stored_xsd: '1.4.2', current_xsd: '1.4.3.6', stored_controls: '20260801', current_controls: '20260901',
    })
    expect(wrapper.text()).toContain('Uložené podklady byly vytvořeny podle jiné verze pravidel JMHZ')
    expect(wrapper.text()).toContain('1.4.2')
    expect(wrapper.text()).toContain('1.4.3.6')
    expect(wrapper.text()).toContain('Již přijatá hlášení kvůli tomuto upozornění znovu neodesílejte')
    expect(wrapper.get('a').attributes('href')).toBe('/payroll/runs?period=2026-08')
    expect(wrapper.get('details').attributes('open')).toBeUndefined()
    expect(wrapper.get('[data-test="jmhz-evidence-guidance"]').text()).not.toContain('Ordinary evidence')
  })

  it('neznámou chybu předá podpoře bez technického textu v hlavním vysvětlení', async () => {
    const wrapper = await render('jmhz_future_internal_failure')
    expect(wrapper.get('a').attributes('href')).toBe('/admin/support')
    expect(wrapper.get('[data-test="jmhz-evidence-guidance"]').text()).toContain('podporu')
    expect(wrapper.get('[data-test="jmhz-evidence-guidance"]').text()).not.toContain('Ordinary evidence')
  })

  it('pojmenuje skutečnou výjimku a neslibuje neexistující měsíční editor', async () => {
    const wrapper = await render('jmhz_ordinary_evidence_monthly_exception_required', { field: 'deep_mining_work_applies' })
    expect(wrapper.get('[data-test="jmhz-evidence-guidance"]').text()).toContain('Práce v hlubinném hornictví')
    expect(wrapper.text()).toContain('Pokud údaj odpovídá skutečnosti, neměňte jej na Ne')
    expect(wrapper.get('a').attributes('href')).toBe('/payroll/people?employment=101&panel=jmhz_profile&field=jmhz_deep_mining_work_applies')
  })

  it('rozliší chybějící druh činnosti od nepodporovaného scénáře', async () => {
    const missing = await render('jmhz_ordinary_evidence_scenario_unsupported', { reason: 'jmhz_scenario_activity_code_missing' })
    expect(missing.text()).toContain('Chybí druh činnosti')
    expect(missing.get('a').attributes('href')).toBe('/payroll/people?employment=101&panel=employment_terms&field=activity_code')
    const unsupported = await render('jmhz_ordinary_evidence_scenario_unsupported')
    expect(unsupported.get('a').attributes('href')).toBe('/admin/support')
  })

  it('vysvětlí chybu integrity i při selhání celého načtení', async () => {
    mocks.get.mockRejectedValueOnce({ response: { data: { error: {
      code: 'jmhz_ordinary_evidence_hash_mismatch', message: 'Citlivý ordinary snapshot má jiný otisk.',
    } } } })
    const wrapper = await render('unused')
    expect(wrapper.get('[role="alert"]').text()).toContain('Nelze ověřit neporušenost')
    expect(wrapper.get('a').attributes('href')).toBe('/admin/support')
    expect(wrapper.get('details').attributes('open')).toBeUndefined()
    expect(wrapper.get('[role="alert"] > p').text()).not.toContain('snapshot')
  })

  it('nabízí lidské vysvětlení také anglicky', async () => {
    const wrapper = await render('jmhz_ordinary_evidence_specification_mismatch', {}, 'en')
    expect(wrapper.text()).toContain('different version of the JMHZ rules')
    expect(wrapper.text()).not.toContain('payroll.submissions.overview.jmhz_guidance.')
  })
})


describe('úplnost katalogu JMHZ', () => {
  it('pokrývá každý emitovaný kód builderu, kontroly použitelnosti a služby', () => {
    const codes = new Set<string>()
    for (const name of ['Builder', 'Applicability', 'Service']) {
      const source = readFileSync(resolve(process.cwd(), `../api/src/Service/Payroll/Submission/Jmhz/JmhzOrdinaryEvidence${name}.php`), 'utf8')
      for (const match of source.matchAll(/(?:invalid|JmhzOrdinaryEvidenceException)\(\s*'(jmhz_[a-z_]+)'/g)) codes.add(match[1]!)
    }
    expect(codes.size).toBeGreaterThanOrEqual(28)
    expect([...codes].filter(code => !Object.hasOwn(jmhzEvidenceCodes, code))).toEqual([])
  })

  it.each(Object.keys(jmhzEvidenceCodes))('vykreslí %s lidsky v obou jazycích', async code => {
    for (const locale of ['cs', 'en']) {
      const wrapper = await render(code, {}, locale)
      const guidance = wrapper.get('[data-test="jmhz-evidence-guidance"]')
      expect(guidance.text()).not.toMatch(/jmhz_|ordinary evidence|snapshot|SHA-256|manifest/i)
      expect(guidance.findAll('p').length).toBeGreaterThanOrEqual(2)
      expect(guidance.get('a').attributes('href')).not.toContain('undefined')
    }
  })

  it('rozliší všechny příčiny srážek a obě dostupné agendy', async () => {
    const title = await render('jmhz_ordinary_evidence_deduction_conflict', { reason: 'missing_title' })
    expect(title.text()).toContain('pro kterou chybí dohoda')
    expect(title.findAll('a').map(link => link.attributes('href'))).toEqual([
      '/payroll/enforcement?person=11', '/payroll/deduction-agreements?person=11',
    ])
    const register = await render('jmhz_ordinary_evidence_deduction_conflict', { reason: 'register_unverified' })
    expect(register.text()).toContain('ověření evidence exekučních pohledávek')
    expect(register.findAll('a')).toHaveLength(1)
    const calculation = await render('jmhz_ordinary_evidence_deduction_conflict', { reason: 'calculation_incomplete' })
    expect(calculation.text()).toContain('Výpočet srážek vyžaduje kontrolu')
  })

  it('nepovažuje zděděné názvy objektu za známé chyby', () => {
    const guidance = jmhzEvidenceGuidance({ attention_code: 'toString' } as never, '2026-08-01')
    expect(guidance.path).toBe('/admin/support')
    expect(guidance.problemKey).toContain('.unknown')
  })
})
