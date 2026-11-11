import { existsSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { createWorkspaceRoutes } from '@/router/workspaceRoutes'
import {
  PAYROLL_MANUAL_CHAPTERS,
  payrollManualChapter,
} from '@/config/payrollManualChapters'

const EXPECTED_CHAPTERS = new Map<string, string>([
  ['/payroll', '75_Uplne_mzdy'],
  ['/payroll/absences', '76_Absence_a_dovolena'],
  ['/payroll/time', '77_Dochazka_a_smeny'],
  ['/payroll/travel', '78_Cestovni_nahrady'],
  ['/payroll/quick-inputs', '79_Rychly_mesicni_vstup'],
  ['/payroll/runs', '80_Mzdove_behy'],
  ['/payroll/posting-reconciliation', '81_Shoda_uctovani_mezd'],
  ['/payroll/posting-detail', '81_Shoda_uctovani_mezd'],
  ['/payroll/payments', '82_Platby_a_uhrady'],
  ['/payroll/documents', '83_Dokumenty_a_vystupy'],
  ['/payroll/annual-settlement', '84_Rocni_zuctovani'],
  ['/payroll/submissions', '85_Podani_a_hlaseni'],
  // Záložka podání a karta člověka mají vlastní adresu, aby na ně šlo odkázat.
  // Kapitolu dědí po rodiči — je to tatáž agenda, ne nová.
  ['/payroll/submissions/:tab([a-z0-9_]+)', '85_Podani_a_hlaseni'],
  ['/payroll/people', '86_Zamestnanci'],
  ['/payroll/personnel-file', '86_Zamestnanci'],
  ['/payroll/people/:id(\\d+)', '86_Zamestnanci'],
  ['/payroll/deduction-agreements', '87_Dohody_o_srazkach'],
  ['/payroll/enforcement', '88_Srazky_a_exekuce'],
  ['/payroll/enforcement/cooperation', '88_Srazky_a_exekuce'],
  ['/payroll/insolvency', '88_Srazky_a_exekuce'],
  ['/payroll/benefit-baskets', '89_Kose_benefitu'],
  ['/payroll/settings', '90_Nastaveni_mezd'],
  ['/payroll/imports', '90_Nastaveni_mezd'],
  ['/payroll/migration-reconciliation', '108_Prechod_z_PAMICA'],
  ['/payroll/posting-map', '108_Prechod_z_PAMICA'],
  ['/payroll/components', '91_Mzdove_slozky_a_vstupy'],
  ['/payroll/rulesets', '92_Legislativni_pravidla_mezd'],
  ['/payroll/retention', '93_Retencni_lhuty'],
  ['/payroll/erasure', '94_Vymaz_osobnich_udaju'],
])

describe('payroll contextual manual chapters', () => {
  it('maps every payroll workspace route to its dedicated chapter', () => {
    const payrollPaths = createWorkspaceRoutes()
      .map(route => String(route.path))
      .filter(path => path === 'payroll' || path.startsWith('payroll/'))
      .map(path => `/${path}`)

    expect([...payrollPaths].sort()).toEqual([...EXPECTED_CHAPTERS.keys()].sort())
    for (const path of payrollPaths) {
      expect(payrollManualChapter(path), path).toBe(EXPECTED_CHAPTERS.get(path))
    }
  })

  it('keeps every specific payroll rule before the catch-all', () => {
    const catchAllIndex = PAYROLL_MANUAL_CHAPTERS.findIndex(
      ([pattern, chapter]) => chapter === '75_Uplne_mzdy'
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
