import { describe, it, expect } from 'vitest'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'

/**
 * Patička aplikace (`AppLayout.vue`) je `sticky bottom-0 z-20` a leží nad obsahem
 * stránky. Lišta stránky přilepená na `bottom-0` se pod ni schová celá, klik
 * na „Uložit vše“ pak propadne do patičky (tlačítko Podpora). Stránkové lišty
 * proto sedí na `bottom-[var(--app-footer-height,0px)]`, kterou AppLayout měří.
 *
 * Výjimky jsou jen místa, kde lišta nežije ve scrollu stránky (modální okno
 * s vlastním posuvníkem) nebo jde o samotnou patičku.
 */

const root = resolve(process.cwd(), 'src')
const allowed = new Set([
  'components/layout/AppLayout.vue',
  'components/layout/InstanceAdminLayout.vue',
  'components/cash/CashRegisterManager.vue',
  'pages/payroll/PayrollQuickInputs.vue',
])

function vueFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return name === 'node_modules' ? [] : vueFiles(path)
    return name.endsWith('.vue') ? [path] : []
  })
}

describe('sticky lišty stránek nad patičkou', () => {
  it('žádná stránková lišta se nelepí na bottom-0/bottom-4 pod patičku', () => {
    const offenders: string[] = []
    for (const file of vueFiles(root)) {
      const rel = relative(root, file).replace(/\\/g, '/')
      if (allowed.has(rel)) continue
      readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
        if (/\bsticky\b[^"'`]*\bbottom-(0|1|2|3|4)\b/.test(line)) offenders.push(`${rel}:${i + 1}`)
      })
    }
    expect(offenders).toEqual([])
  })

  it('karta osoby drží společnou lištu Uložit nad patičkou', () => {
    const src = readFileSync(resolve(root, 'pages/payroll/PeopleList.vue'), 'utf8')
    const bar = src.slice(src.indexOf('data-test="person-card-save-bar"') - 400, src.indexOf('data-test="person-card-save-bar"'))
    expect(bar).toContain('bottom-[var(--app-footer-height,0px)]')
  })
})
