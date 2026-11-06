import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Pokladna je bezplatná (§ 104 manuálu) a v menu nesmí záviset na přepínači
 * „Vést účetnictví" ani na licenci (#230). Podmínka položky musí odpovídat
 * route guardu `requiresCashMode`, jinak stránka jde otevřít jen přes URL.
 *
 * Čte se zdroj, ne vykreslená komponenta, ze stejného důvodu jako u hostingNavGating.spec.ts.
 */

const appLayout = readFileSync(resolve(process.cwd(), 'src/components/layout/AppLayout.vue'), 'utf8')
const router = readFileSync(resolve(process.cwd(), 'src/router/index.ts'), 'utf8')

describe('položka Pokladna v menu', () => {
  it('je podmíněná jen účetním režimem firmy, ne modulem účetnictví', () => {
    const index = appLayout.indexOf("to: '/accounting/cash'")
    expect(index).toBeGreaterThan(-1)
    const before = appLayout.slice(Math.max(0, index - 60), index)
    expect(before).toContain('cashAvailable ?')

    const definition = appLayout.split('\n').find(line => line.includes('const cashAvailable ='))
    expect(definition).toBeDefined()
    expect(definition).not.toContain('accountingEnabled')
    expect(definition).not.toContain('hasCommercialFeatures')
    expect(definition).not.toContain('isDoubleEntry')
    expect(definition).toContain("'double_entry'")
    expect(definition).toContain("'tax_evidence'")
  })

  it('route guard pokladny pouští stejné režimy', () => {
    const guard = router.slice(router.indexOf('if (requiresCashMode)'), router.indexOf('if (requiresCashMode)') + 250)
    expect(guard).toContain("'double_entry'")
    expect(guard).toContain("'tax_evidence'")
    expect(guard).not.toContain('accounting_enabled')
  })
})
