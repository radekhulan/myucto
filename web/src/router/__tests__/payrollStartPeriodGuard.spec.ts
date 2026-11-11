import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RouteLocationNormalized } from 'vue-router'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  supplierId: 2,
  userId: 7 as number | null,
}))

vi.mock('@/api/client', () => ({ api: { get: m.get } }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: m.supplierId }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: m.userId === null ? null : { id: m.userId } }),
}))
vi.mock('@/workspace/supplierContext', () => ({ getWindowSupplierScope: () => null }))

import {
  payrollStartPeriodGuard,
  resetPayrollStartPeriodCache,
  startAwarePayrollPeriod,
} from '@/router/payrollStartPeriodGuard'
import { clearPayrollPeriodSession, readPayrollPeriod } from '@/utils/payrollPeriodSession'

function route(name: string, query: Record<string, string> = {}, params: Record<string, string> = {}): RouteLocationNormalized {
  return { name, query, params, path: '/', fullPath: '/', hash: '', matched: [], meta: {}, redirectedFrom: undefined } as unknown as RouteLocationNormalized
}

describe('výchozí období mzdových obrazovek u převzaté firmy', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 8, 27, 10, 0, 0))
    resetPayrollStartPeriodCache()
    m.get.mockReset()
    m.supplierId = 2
    m.userId = 7
    clearPayrollPeriodSession()
  })

  it('první měsíc vedení mezd má přednost před převzatým předchozím měsícem', () => {
    expect(startAwarePayrollPeriod('2026-09')).toBe('2026-09')
    expect(startAwarePayrollPeriod('2026-01')).toBe('2026-08')
    expect(startAwarePayrollPeriod(null)).toBe('2026-08')
  })

  it('bez ?period doplní u převzaté firmy první měsíc vedení mezd', async () => {
    m.get.mockResolvedValue({ data: { state: { start_period: '2026-09' } } })

    const result = await payrollStartPeriodGuard(route('payroll-submissions-tab', {}, { tab: 'jmhz' }))

    expect(result).toMatchObject({ query: { period: '2026-09' } })
  })

  it('výslovné období, zdravotní záložku ani běžnou firmu nepřepisuje', async () => {
    m.get.mockResolvedValue({ data: { state: { start_period: '2026-01' } } })

    expect(await payrollStartPeriodGuard(route('payroll-time', { period: '2026-05' }))).toBe(true)
    expect(await payrollStartPeriodGuard(route('payroll-submissions-tab', {}, { tab: 'health' }))).toBe(true)
    // Výslovné období výš si strážce zapamatoval; běžná firma bez volby se nepřepisuje.
    clearPayrollPeriodSession()
    expect(await payrollStartPeriodGuard(route('payroll-time'))).toBe(true)
    expect(await payrollStartPeriodGuard(route('invoices'))).toBe(true)
  })
})

/*
 * Položky menu období nenesou: po práci na lednu 2027 se běhy otevřely na
 * prosinci 2026 a přehled podání na říjnu. Naposledy zvolené období teď drží
 * napříč mzdovými stránkami v rámci sezení.
 */
describe('naposledy zvolené mzdové období drží mezi stránkami mezd', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 10, 11, 10, 0, 0))
    resetPayrollStartPeriodCache()
    m.get.mockReset()
    m.get.mockResolvedValue({ data: { state: { start_period: '2026-12' } } })
    m.supplierId = 2
    m.userId = 7
    clearPayrollPeriodSession()
  })

  it('období zvolené na běhu se z menu otevře i na podáních, dokumentech a platbách', async () => {
    expect(await payrollStartPeriodGuard(route('payroll-runs', { period: '2027-01' }))).toBe(true)

    for (const [name, params] of [
      ['payroll-runs', {}],
      ['payroll-submissions', {}],
      ['payroll-submissions-tab', { tab: 'jmhz' }],
      ['payroll-documents', {}],
      ['payroll-payments', {}],
      ['payroll-quick-inputs', {}],
      ['payroll-time', {}],
    ] as const) {
      expect(await payrollStartPeriodGuard(route(name, {}, params)), name)
        .toMatchObject({ query: { period: '2027-01' } })
    }
    // Zapamatované období má přednost i před prvním měsícem vedení mezd.
    expect(m.get).not.toHaveBeenCalled()
  })

  it('ostatní parametry adresy při doplnění období zůstanou', async () => {
    await payrollStartPeriodGuard(route('payroll-runs', { period: '2027-01' }))

    expect(await payrollStartPeriodGuard(route('payroll-payments', { bank_batch: '12' })))
      .toMatchObject({ query: { bank_batch: '12', period: '2027-01' } })
  })

  it('pozdější volba přepíše dřívější', async () => {
    await payrollStartPeriodGuard(route('payroll-runs', { period: '2027-01' }))
    await payrollStartPeriodGuard(route('payroll-time', { period: '2026-04' }))

    expect(await payrollStartPeriodGuard(route('payroll-runs'))).toMatchObject({ query: { period: '2026-04' } })
  })

  it('odkaz s remember=0 ani neplatné období zapamatovanou volbu nemění', async () => {
    await payrollStartPeriodGuard(route('payroll-runs', { period: '2027-01' }))
    await payrollStartPeriodGuard(route('payroll-documents', { period: '2026-03', remember: '0' }))
    await payrollStartPeriodGuard(route('payroll-documents', { period: 'monthly' }))

    expect(readPayrollPeriod('7.2')).toBe('2027-01')
  })

  it('zdravotní záložka zůstává na dnešním měsíci a cizí stránky se nepamatují', async () => {
    await payrollStartPeriodGuard(route('invoices', { period: '2025-05' }))
    expect(readPayrollPeriod('7.2')).toBeNull()

    await payrollStartPeriodGuard(route('payroll-runs', { period: '2027-01' }))
    expect(await payrollStartPeriodGuard(route('payroll-submissions-tab', {}, { tab: 'health' }))).toBe(true)
  })

  it('období se pamatuje zvlášť pro firmu a uživatele', async () => {
    await payrollStartPeriodGuard(route('payroll-runs', { period: '2027-01' }))

    m.supplierId = 3
    m.get.mockResolvedValue({ data: { state: { start_period: '2026-01' } } })
    expect(await payrollStartPeriodGuard(route('payroll-runs'))).toBe(true)

    m.supplierId = 2
    m.userId = 8
    expect(await payrollStartPeriodGuard(route('payroll-submissions'))).not.toMatchObject({ query: { period: '2027-01' } })
  })

  it('bez zapamatované volby platí dál první měsíc vedení mezd', async () => {
    expect(await payrollStartPeriodGuard(route('payroll-runs'))).toMatchObject({ query: { period: '2026-12' } })
  })

  it('odhlášení zapamatované období zahodí', async () => {
    await payrollStartPeriodGuard(route('payroll-runs', { period: '2027-01' }))
    clearPayrollPeriodSession()

    expect(readPayrollPeriod('7.2')).toBeNull()
  })
})
