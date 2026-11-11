import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createWorkspaceRoutes } from '@/router/workspaceRoutes'
import { PAYROLL_SUBMISSION_TABS } from '@/pages/payroll/payrollSubmissionTabs'

function workspaceRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', children: createWorkspaceRoutes() }, { path: '/:pathMatch(.*)*', name: 'not-found', component: {} }],
  })
}

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return name === '__tests__' || path.endsWith(join('src', 'api')) ? [] : sourceFiles(path)
    return /\.(ts|vue)$/.test(name) ? [path] : []
  })
}

/** Klíče záložek, na které aplikace odkazuje adresou nebo pojmenovanou routou (bez volání API v src/api). */
function linkedTabs(): string[] {
  const found = new Set<string>()
  for (const file of sourceFiles(resolve(process.cwd(), 'src'))) {
    const source = readFileSync(file, 'utf8')
    for (const match of source.matchAll(/['"`]\/payroll\/submissions\/([A-Za-z0-9_]+)/g)) found.add(match[1]!)
    for (const match of source.matchAll(/name:\s*'payroll-submissions-tab',\s*params:\s*\{\s*tab:\s*'([^']+)'/g)) found.add(match[1]!)
  }
  return [...found].sort()
}

describe('routa záložek Podání a hlášení', () => {
  it('každá záložka stránky projde parametrem routy (registration_a1 nesmí skončit na 404)', () => {
    const router = workspaceRouter()
    for (const tab of PAYROLL_SUBMISSION_TABS) {
      expect(router.resolve(`/payroll/submissions/${tab}`).name, tab).toBe('payroll-submissions-tab')
      expect(router.resolve({ name: 'payroll-submissions-tab', params: { tab } }).fullPath).toBe(`/payroll/submissions/${tab}`)
    }
  })

  it('odkazy aplikace vedou jen na existující záložky', () => {
    const tabs = linkedTabs()
    expect(tabs).toContain('transport')
    expect(tabs.filter(tab => !(PAYROLL_SUBMISSION_TABS as readonly string[]).includes(tab))).toEqual([])
  })
})
