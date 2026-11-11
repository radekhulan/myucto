import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/*
 * Panel s vlastní tichou obnovou, který se nehlásí jako částečný, vypne obnovu
 * celé stránky okna: stránka se pak po změně jinde ani neobnoví, ani neukáže
 * značku. Tak zůstala v Mzdových bězích stará kontrola před během, protože
 * obnovu „převzal" vložený přehled měsíce. Panely mezd proto obnovují jen sebe
 * (`partial: true`) a o zbytku stránky rozhoduje stránka sama.
 */
describe('obnova panelů mezd v okně', () => {
  const dir = join(process.cwd(), 'src/pages/payroll')
  const routes = ['src/router/workspaceRoutes.ts', 'src/router/index.ts']
    .map(file => readFileSync(join(process.cwd(), file), 'utf8'))
    .join('\n')

  const refreshing = readdirSync(dir)
    .filter(file => file.endsWith('.vue'))
    .map(file => ({ file, source: readFileSync(join(dir, file), 'utf8') }))
    .filter(({ source }) => source.includes('useWindowRefresh('))

  it('najde stránky i panely s vlastní obnovou', () => {
    expect(refreshing.map(({ file }) => file)).toEqual(expect.arrayContaining(['PayrollRuns.vue', 'PayrollMonthlyChecklistPanel.vue']))
  })

  it('panel, který není samostatnou stránkou, obnovuje jen sebe', () => {
    const panels = refreshing.filter(({ file }) => !routes.includes(`@/pages/payroll/${file}`))
    expect(panels.length).toBeGreaterThan(0)
    const wholePage = panels
      .filter(({ source }) => !/useWindowRefresh\([\s\S]*?\{\s*partial:\s*true\s*\}\s*\)/.test(source))
      .map(({ file }) => file)
    expect(wholePage).toEqual([])
  })
})
