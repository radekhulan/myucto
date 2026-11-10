import { describe, expect, it, vi } from 'vitest'
import type { RouteRecordRaw } from 'vue-router'
import { createWorkspaceRoutes } from '@/router/workspaceRoutes'
import { changedAreas, hasWindowDataRule, isWindowAffected, windowDataProfile } from '../dataDependencies'

vi.mock('@/stores/auth', () => ({ useAuthStore: vi.fn() }))

const affected = (route: string, api: string) => isWindowAffected(route, changedAreas(api))

function routePaths(routes: RouteRecordRaw[], prefix = ''): string[] {
  return routes.flatMap((route) => {
    const path = route.path.startsWith('/') ? route.path : `${prefix}/${route.path}`.replace(/\/+$/, '')
    return [path, ...routePaths(route.children ?? [], path)]
  })
}

describe('závislosti dat mezi okny', () => {
  it('úprava faktury dotkne seznamu, detailu, deníku, klienta, banky a přehledů', () => {
    for (const route of ['/invoices', '/invoices?year=2026', '/invoices/5', '/accounting/journal', '/accounting/saldo',
      '/accounting/trial-balance', '/clients/3', '/bank/2', '/stats', '/']) {
      expect(affected(route, '/invoices/5'), route).toBe(true)
    }
  })

  it('zaúčtování a úhrada banky dotknou dokladu, výpisu i deníku', () => {
    for (const api of ['/accounting/journal/9/reverse', '/accounting/bank-posting-suggestions/4/accept', '/bank-transactions/4/match']) {
      for (const route of ['/invoices/5', '/purchase-invoices/5', '/invoices', '/accounting/journal', '/bank/2']) {
        expect(affected(route, api), `${api} -> ${route}`).toBe(true)
      }
    }
  })

  it('nesouvisející agenda okno neobnoví', () => {
    expect(affected('/payroll/people', '/invoices/5')).toBe(false)
    expect(affected('/invoices', '/logbook/trips/1')).toBe(false)
    expect(affected('/accounting/journal', '/admin/users/1')).toBe(false)
  })

  it('výpočty a nastavení uživatele nic nezmění', () => {
    for (const api of ['/invoices/bulk-oss/preview', '/accounting/bank-posting-suggestions/bulk-preview', '/clients/lookup-ares',
      '/accounting/bank-posting-rules/dry-run', '/stock/items/quote', '/stock/reports/valuation-jobs', '/stock/reports/value-trend-jobs', '/stock/reports/value-trend-jobs/7/cancel', '/auth/login', '/user/preferences', '/license/tier/quote']) {
      expect(changedAreas(api), api).toEqual([])
      expect(affected('/', api), api).toBe(false)
    }
  })

  it('neznámá API cesta obnoví vše, co čte data, stránky bez dat nechá být', () => {
    expect(changedAreas('/neco-noveho/5')).toBe('all')
    expect(affected('/invoices', '/neco-noveho/5')).toBe(true)
    expect(affected('/profile/password', '/neco-noveho/5')).toBe(false)
    expect(affected('/invoices/new', '/neco-noveho/5')).toBe(false)
  })

  it('neznámá route čte vše, ale nikdy se nenačte sama', () => {
    expect(windowDataProfile('/uplne-nova-stranka')).toEqual({ reads: 'all', mode: 'manual' })
    expect(affected('/uplne-nova-stranka', '/invoices/5')).toBe(true)
  })

  it('formuláře a průvodci se sami nenačtou, přehledy ano', () => {
    for (const path of ['/invoices/5/edit', '/purchase-invoices/5/edit', '/clients/3/edit', '/invoices/new', '/accounting/periods',
      '/payroll/runs', '/reports/dph', '/admin/settings', '/imports']) {
      expect(windowDataProfile(path).mode, path).toBe('manual')
    }
    for (const path of ['/', '/invoices', '/invoices/5', '/purchase-invoices', '/accounting/journal', '/accounting/balance-sheet', '/bank']) {
      expect(windowDataProfile(path).mode, path).toBe('auto')
    }
  })

  it('šablony účtování mají vlastní ručně obnovovanou stránku a změny obnoví i zakázky', () => {
    expect(windowDataProfile('/admin/work-report-billing-templates')).toEqual({ reads: ['projects'], mode: 'manual' })
    expect(affected('/admin/work-report-billing-templates', '/work-report-billing-templates/7')).toBe(true)
    expect(affected('/projects', '/work-report-billing-templates/7')).toBe(true)
    expect(affected('/payroll/people', '/work-report-billing-templates/7')).toBe(false)
  })

  it('každá route aplikace je v mapě zařazená', () => {
    const missing = routePaths(createWorkspaceRoutes())
      .map(path => path.replace(/:id\([^)]*\)|:id/g, '1').replace(/:[a-zA-Z]+(\([^)]*\))?/g, '1'))
      .filter(path => !hasWindowDataRule(path))
    expect(missing).toEqual([])
  })
})
