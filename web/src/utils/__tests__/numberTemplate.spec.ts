import { describe, expect, it } from 'vitest'
import cases from '../../../../api/tests/Fixtures/number-template-cases.json'
import {
  normalizeTemplateBrackets,
  numberTemplateState,
  validateNumberTemplate,
} from '@/utils/numberTemplate'

type Case = {
  template: string
  allowPrefix: boolean
  allowPp: boolean
  period: 'year' | 'month' | 'none' | null
  errors: string[]
  normalized?: string
}

// Tutéž sadu prochází PHPUnit (NumberTemplateRulesTest), takže se frontend a backend nerozejdou.
describe('numberTemplate: sdílená sada vzorků s backendem', () => {
  it('sada není prázdná', () => {
    expect((cases as Case[]).length).toBeGreaterThan(25)
  })
  it.each((cases as Case[]).map((c, i) => [`${i}: ${c.template}`, c] as const))('%s', (_name, c) => {
    const template = normalizeTemplateBrackets(c.template)
    if (c.normalized !== undefined) expect(template).toBe(c.normalized)
    expect(validateNumberTemplate(template, { allowPrefix: c.allowPrefix, allowPp: c.allowPp, period: c.period })).toEqual(c.errors)
  })
})

describe('numberTemplate: převod závorek', () => {
  it('kulaté i hranaté závorky převede na složené', () => {
    expect(normalizeTemplateBrackets('(YY)[MM](CCC)')).toBe('{YY}{MM}{CCC}')
    expect(normalizeTemplateBrackets('FA-[CCCC]')).toBe('FA-{CCCC}')
  })
  it('složené a ostatní znaky nechá', () => {
    expect(normalizeTemplateBrackets('{YY}-{CCC}/A')).toBe('{YY}-{CCC}/A')
  })
})

const t = (key: string, params?: Record<string, unknown>) => `${key}${params?.allowed ? `|${params.allowed}` : ''}`

describe('numberTemplate: stav pole', () => {
  const now = new Date(2026, 10, 9)
  it('{YYx} je chyba s výčtem dovolených symbolů', () => {
    const r = numberTemplateState(t, '{YYx}{CCCC}', {}, now)
    expect(r.invalid).toBe(true)
    expect(r.error).toBe('number_template.errors.placeholder|{YYYY} {YY} {MM} {C…}')
    expect(r.preview).toBe('')
  })
  it('platný tvar dá náhled pro dnešek a čítač 1', () => {
    const r = numberTemplateState(t, '{YY}{MM}{CCCC}', {}, now)
    expect(r.invalid).toBe(false)
    expect(r.preview).toBe('26110001')
  })
  it('u řady se do náhledu dosadí prefix', () => {
    const r = numberTemplateState(t, '{PREFIX}{YY}{CCC}', { allowPrefix: true, prefix: 'FA' }, now)
    expect(r.preview).toBe('FA26001')
  })
  it('chybějící měsíc k období je varování, ne chyba', () => {
    const r = numberTemplateState(t, '{YY}{CCC}', { period: 'month' }, now)
    expect(r.invalid).toBe(false)
    expect(r.warning).toContain('number_template.errors.period')
  })
  it('prázdné pole je v pořádku (dědí se)', () => {
    expect(numberTemplateState(t, '  ', {}, now)).toEqual({ error: '', warning: '', preview: '', invalid: false })
  })
})
