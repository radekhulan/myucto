import { describe, expect, it } from 'vitest'
import { safeReturnPath } from '../returnPath'

/**
 * Hodnota přichází z adresního řádku, takže ji může podstrčit kdokoli —
 * a přihlašovací obrazovka je přesně to místo, kam phishing míří. Otevřený
 * redirect by z našeho loginu udělal odrazový můstek na cizí web.
 */
describe('safeReturnPath', () => {
  it('pustí vlastní cestu i s parametry a kotvou', () => {
    expect(safeReturnPath('/payroll/runs', '/')).toBe('/payroll/runs')
    expect(safeReturnPath('/payroll/people?person=1', '/'))
      .toBe('/payroll/people?person=1')
    expect(safeReturnPath('/purchase-invoices/21320#pdf', '/'))
      .toBe('/purchase-invoices/21320#pdf')
  })

  it('odmítne cizí původ, i když vypadá jako cesta', () => {
    for (const evil of [
      '//cizi.example/prihlaseni',
      'https://cizi.example',
      'http://cizi.example',
      '/\\cizi.example',
      'javascript:alert(1)',
      'payroll/runs',
    ]) {
      expect(safeReturnPath(evil, '/')).toBe('/')
    }
  })

  it('nevrací na stránky, které samy vedou k přihlášení', () => {
    for (const loop of ['/login', '/login?x=1', '/setup', '/setup-mfa', '/setup-totp']) {
      expect(safeReturnPath(loop, '/portal')).toBe('/portal')
    }
  })

  it('u chybějící nebo nesmyslné hodnoty vrátí náhradu', () => {
    expect(safeReturnPath(undefined, '/')).toBe('/')
    expect(safeReturnPath('', '/')).toBe('/')
    expect(safeReturnPath(['/a', '/b'], '/portal')).toBe('/portal')
    expect(safeReturnPath(42, '/')).toBe('/')
  })
})
