import { existsSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { createWorkspaceRoutes } from '@/router/workspaceRoutes'
import {
  PAYROLL_MANUAL_CHAPTERS,
  payrollManualChapter,
} from '@/config/payrollManualChapters'

const EXPECTED_CHAPTERS = new Map<string, string>([
  ['/payroll', '58_Uplne_mzdy'],
  ['/payroll/absences', '59_Absence_a_dovolena'],
  ['/payroll/time', '60_Dochazka_a_smeny'],
  ['/payroll/travel', '61_Cestovni_nahrady'],
  ['/payroll/quick-inputs', '62_Rychly_mesicni_vstup'],
  ['/payroll/runs', '63_Mzdove_behy'],
  ['/payroll/posting-reconciliation', '64_Shoda_uctovani_mezd'],
  ['/payroll/payments', '65_Platby_a_uhrady'],
  ['/payroll/documents', '66_Dokumenty_a_vystupy'],
  ['/payroll/annual-settlement', '67_Rocni_zuctovani'],
  ['/payroll/submissions', '68_Podani_a_hlaseni'],
  ['/payroll/people', '69_Zamestnanci'],
  ['/payroll/deduction-agreements', '70_Dohody_o_srazkach'],
  ['/payroll/enforcement', '71_Srazky_a_exekuce'],
  ['/payroll/insolvency', '71_Srazky_a_exekuce'],
  ['/payroll/benefit-baskets', '72_Kose_benefitu'],
  ['/payroll/settings', '73_Nastaveni_mezd'],
  ['/payroll/components', '74_Mzdove_slozky_a_vstupy'],
  ['/payroll/rulesets', '75_Legislativni_pravidla_mezd'],
  ['/payroll/retention', '76_Retencni_lhuty'],
  ['/payroll/erasure', '77_Vymaz_osobnich_udaju'],
])

describe('payroll contextual manual chapters', () => {
  it('maps every payroll workspace route to its dedicated chapter', () => {
    const payrollPaths = createWorkspaceRoutes()
      .map(route => String(route.path))
      .filter(path => path === 'payroll' || path.startsWith('payroll/'))
      .map(path => `/${path}`)

    expect(payrollPaths).toHaveLength(21)
    expect([...payrollPaths].sort()).toEqual([...EXPECTED_CHAPTERS.keys()].sort())
    for (const path of payrollPaths) {
      expect(payrollManualChapter(path), path).toBe(EXPECTED_CHAPTERS.get(path))
    }
  })

  it('keeps every specific payroll rule before the catch-all', () => {
    const catchAllIndex = PAYROLL_MANUAL_CHAPTERS.findIndex(
      ([pattern, chapter]) => chapter === '58_Uplne_mzdy'
        && pattern.test('/payroll')
        && pattern.test('/payroll/runs'),
    )

    expect(catchAllIndex).toBe(PAYROLL_MANUAL_CHAPTERS.length - 1)
    for (const [path, chapter] of EXPECTED_CHAPTERS) {
      if (path === '/payroll') continue
      const exactIndex = PAYROLL_MANUAL_CHAPTERS.findIndex(([, value]) => value === chapter)
      expect(exactIndex, path).toBeGreaterThanOrEqual(0)
      expect(exactIndex, path).toBeLessThan(catchAllIndex)
      expect(payrollManualChapter(path), path).toBe(chapter)
    }
  })

  it('targets existing Markdown chapters', () => {
    const manualDir = resolve(process.cwd(), '..', 'manual')

    for (const chapter of EXPECTED_CHAPTERS.values()) {
      expect(existsSync(join(manualDir, `${chapter}.md`)), chapter).toBe(true)
    }
  })
})
