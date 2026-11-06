import { paymentDecimal } from '../paymentWorkflowForm'
import { appIsoDate } from '@/utils/date'

export class FormError extends Error {
  constructor(public code: string) { super(code) }
}

export function failureMessage(e: unknown, fallback: string): string {
  const error = (e as { response?: { data?: { error?: { message?: string; details?: unknown } } } }).response?.data?.error
  const message = error?.message ?? fallback
  const details = error?.details
  const lines: string[] = []
  if (typeof details === 'string') lines.push(details)
  else if (Array.isArray(details)) { for (const item of details) if (typeof item === 'string') lines.push(item) }
  else if (details && typeof details === 'object') { for (const item of Object.values(details)) if (typeof item === 'string') lines.push(item) }
  return lines.length ? `${message} ${lines.join(' ')}` : message
}

export function formErrorKey(e: unknown): string | null {
  return e instanceof FormError ? `propertyPayments.formErrors.${e.code}` : null
}

export function moneyText(cents: string | null | undefined, unknown: string): string {
  return cents === null || cents === undefined ? unknown : `${paymentDecimal(cents)} CZK`
}

export function partyNames(parties: Array<{ label: string }>): string {
  return parties.map(p => p.label).join(', ')
}

export function isoDay(instant: string): string {
  const date = new Date(instant)
  return Number.isFinite(date.getTime()) ? appIsoDate(date) : ''
}

export function debtLabel(debt: { unit_label: string; title_ref: string; due_date: string } | undefined, fallback: string): string {
  return debt ? `${debt.unit_label} · ${debt.title_ref} · ${debt.due_date}` : fallback
}

export function propertyUnitLabel(unit: { code: string; revision: { state: object } | null }, label: (number: string) => string): string {
  const state = unit.revision?.state
  return state && 'unit_no' in state ? label(String((state as { unit_no: string }).unit_no)) : unit.code
}

export function flattenSnapshot(value: unknown, unknown: string, prefix = ''): Array<[string, string]> {
  if (value === null || value === undefined) return [[prefix, unknown]]
  if (Array.isArray(value)) return value.length ? value.flatMap((item, index) => flattenSnapshot(item, unknown, `${prefix}[${index + 1}]`)) : [[prefix, '—']]
  if (typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>)
    return entries.length ? entries.flatMap(([key, item]) => flattenSnapshot(item, unknown, prefix ? `${prefix} / ${key}` : key)) : [[prefix, '—']]
  }
  const text = String(value)
  return [[prefix, /_cents(\]|$)/.test(prefix) && /^-?(0|[1-9][0-9]*)$/.test(text) ? moneyText(text, unknown) : text]]
}
