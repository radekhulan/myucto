import { existsSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { manualChapter } from '@/config/manualChapters'

function menuPaths(): string[] {
  const source = readFileSync(
    resolve(process.cwd(), 'src', 'components', 'layout', 'AppLayout.vue'),
    'utf8',
  )
  const paths = [...source.matchAll(/\b(?:to|newTo):\s*['"](\/[^'"]*)['"]/g)]
    .map(match => match[1]!.split(/[?#]/, 1)[0]!)
    .filter(path => path !== '/manual')

  return [...new Set(paths)].sort()
}

describe('contextual manual for application menu', () => {
  it('maps every literal menu and create-action path to an existing chapter', () => {
    const manualDir = resolve(process.cwd(), '..', 'manual')
    const missing = menuPaths().filter(path => manualChapter(path) === undefined)
    expect(missing).toEqual([])

    for (const path of menuPaths()) {
      const chapter = manualChapter(path)!
      expect(existsSync(join(manualDir, `${chapter}.md`)), `${path} -> ${chapter}`).toBe(true)
    }
  })

  it.each([
    ['/reports/cnb-rate-audit', '79_Ucetni_kontroly_a_inventarizace'],
    ['/reports/invoice-series-completeness', '79_Ucetni_kontroly_a_inventarizace'],
    ['/reports/vat-coefficient', '36_Vykazy_DPH'],
    ['/reports/s46', '36_Vykazy_DPH'],
    ['/hosting', '100_Licence_a_aktivace'],
    ['/admin/databox', '93_Datova_schranka'],
    ['/admin/isds-gateway', '94_Odesilaci_brana_ISDS'],
    ['/isds-gateway/callback', '94_Odesilaci_brana_ISDS'],
    ['/admin/diagnostics', '999_Reseni_problemu'],
    ['/admin/support', '999_Reseni_problemu'],
  ])('uses the subject-specific chapter for %s', (path, chapter) => {
    expect(manualChapter(path)).toBe(chapter)
  })
})
