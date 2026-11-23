// Pravidla tvaru čísla dokladu (šablony typu `{YY}{CCCC}`): řady číselníku, formáty
// vydaných dokladů (firma, klient, kategorie tržeb) i přijatých dokladů. Zrcadlí PHP
// `NumberTemplateRules`; shodu hlídá společná sada vzorků
// `api/tests/Fixtures/number-template-cases.json` (PHPUnit i Vitest).
import { renderVarsymbolTemplate } from '@/utils/varsymbol'

type Translate = (key: string, params?: Record<string, unknown>) => string

export type NumberTemplateError = 'length' | 'placeholder' | 'whitespace' | 'counter' | 'period'
export type NumberTemplatePeriod = 'year' | 'month' | 'none'

export interface NumberTemplateOptions {
  /** {PREFIX} jen u řad číselníku. */
  allowPrefix?: boolean
  /** {PP} jen u přijatých dokladů. */
  allowPp?: boolean
  /** Období řady; null/undefined = neposuzuje se (zdědí se). */
  period?: NumberTemplatePeriod | null
  /** Prefix řady dosazený do náhledu za {PREFIX}. */
  prefix?: string
}

export const NUMBER_TEMPLATE_MAX_LENGTH = 60

const BRACKETS: Record<string, string> = { '(': '{', '[': '{', ')': '}', ']': '}' }

/** Kulaté a hranaté závorky převede na složené (délka se nemění, kurzor neskáče). */
export function normalizeTemplateBrackets(value: string): string {
  return value.replace(/[()[\]]/g, (c) => BRACKETS[c])
}

export function periodMissing(template: string, period: NumberTemplatePeriod): boolean {
  const hasYear = /\{(YYYY|YY)([+-]\d{1,3})?\}/.test(template)
  const hasMonth = /\{MM([+-]\d{1,3})?\}/.test(template)
  return (period === 'year' && !hasYear) || (period === 'month' && (!hasYear || !hasMonth))
}

/** Kódy chyb tvaru; prázdný seznam = platný. Pořadí odpovídá PHP `NumberTemplateRules::check()`. */
export function validateNumberTemplate(template: string, opts: NumberTemplateOptions = {}): NumberTemplateError[] {
  const errors: NumberTemplateError[] = []
  if (template === '' || template.length > NUMBER_TEMPLATE_MAX_LENGTH) errors.push('length')
  let stripped = template.replace(/\{(YYYY|YY|MM)([+-]\d{1,3})?\}/g, '').replace(/\{C+\}/g, '')
  if (opts.allowPrefix) stripped = stripped.replaceAll('{PREFIX}', '')
  if (opts.allowPp) stripped = stripped.replaceAll('{PP}', '')
  if (/[{}]/.test(stripped)) errors.push('placeholder')
  if (/\s/.test(template)) errors.push('whitespace')
  if ((template.match(/\{C+\}/g) ?? []).length !== 1) errors.push('counter')
  if (opts.period && periodMissing(template, opts.period)) errors.push('period')
  return errors
}

/** Výčet dovolených symbolů do hlášky, shodný s PHP `allowedSymbols()`. */
export function allowedSymbols(opts: NumberTemplateOptions = {}): string {
  return (opts.allowPrefix ? '{PREFIX} ' : '') + '{YYYY} {YY} {MM} {C…}' + (opts.allowPp ? ' {PP}' : '')
}

export function numberTemplateMessage(t: Translate, code: NumberTemplateError, opts: NumberTemplateOptions = {}): string {
  return t(`number_template.errors.${code}`, { allowed: allowedSymbols(opts) })
}

/**
 * Stav pole s tvarem čísla pro formulář: prázdná hodnota je v pořádku (dědí se),
 * jinak první chyba s hláškou, případně náhled vzorového čísla (dnešní datum, čítač 1).
 * `period` se u vydaných dokladů jen varuje (období se může dědit z nadřazené úrovně).
 */
export function numberTemplateState(
  t: Translate,
  value: string | null | undefined,
  opts: NumberTemplateOptions = {},
  now: Date = new Date(),
): { error: string; warning: string; preview: string; invalid: boolean } {
  const tmpl = (value ?? '').trim()
  if (tmpl === '') return { error: '', warning: '', preview: '', invalid: false }
  const { period, prefix, ...strict } = opts
  const errors = validateNumberTemplate(tmpl, strict)
  if (errors.length > 0) {
    return { error: numberTemplateMessage(t, errors[0], strict), warning: '', preview: '', invalid: true }
  }
  const warning = period && periodMissing(tmpl, period) ? numberTemplateMessage(t, 'period', strict) : ''
  const preview = renderVarsymbolTemplate(tmpl.replaceAll('{PREFIX}', prefix ?? ''), now, 1)
  return { error: '', warning, preview, invalid: false }
}

/** Handler `@input`: převede závorky přímo v poli a zachová pozici kurzoru. */
export function normalizeInputBrackets(event: Event): string {
  const el = event.target as HTMLInputElement
  const normalized = normalizeTemplateBrackets(el.value)
  if (normalized !== el.value) {
    const start = el.selectionStart
    const end = el.selectionEnd
    el.value = normalized
    if (start !== null && end !== null) el.setSelectionRange(start, end)
  }
  return normalized
}
