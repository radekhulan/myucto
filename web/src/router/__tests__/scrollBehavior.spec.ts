import { describe, expect, it } from 'vitest'
import { router } from '@/router'

/**
 * Jednorázové povely v adrese (`?panel=` na kartě osoby, `?new=` u zakládacích
 * formulářů) si stránka po použití uklidí `router.replace`em. Je to plnohodnotná
 * navigace, takže by `scrollBehavior` jinak vrátil `{ top: 0 }` a SMÁZL scroll
 * nebo rozbalení, které obsluha povelu právě udělala — navenek to vypadá, že
 * proklik nedělá nic.
 *
 * Zmizení téhle výjimky je jednořádková regrese, kterou v kódu nikdo nezahlédne,
 * dokud si nestěžuje uživatel, že mu „odkaz nefunguje".
 */
const behavior = router.options.scrollBehavior!

function route(path: string, query: Record<string, string>) {
  return { path, query, hash: '', fullPath: path } as never
}

describe('scrollBehavior', () => {
  it('nepřepisuje scroll, když se ze stejné adresy odebírá povel ?new=', () => {
    expect(behavior(
      route('/payroll/people', {}),
      route('/payroll/people', { new: '1' }),
      null,
    )).toBe(false)
  })

  it('nepřepisuje scroll, když se ze stejné adresy odebírá povel ?panel=', () => {
    expect(behavior(
      route('/payroll/people', { person: '1' }),
      route('/payroll/people', { person: '1', panel: 'dependants' }),
      null,
    )).toBe(false)
  })

  it('u běžné navigace na stejné cestě (stránkování) skáče dál na začátek', () => {
    expect(behavior(
      route('/payroll/people', { page: '2' }),
      route('/payroll/people', { page: '1' }),
      null,
    )).toEqual({ top: 0, left: 0 })
  })

  it('při PŘIDÁNÍ povelu scroll nechává na routeru — výjimka platí jen na úklid', () => {
    expect(behavior(
      route('/payroll/people', { new: '1' }),
      route('/payroll/people', {}),
      null,
    )).toEqual({ top: 0, left: 0 })
  })

  it('respektuje uloženou pozici při tlačítku Zpět', () => {
    expect(behavior(
      route('/payroll/people', {}),
      route('/payroll/people', { new: '1' }),
      { top: 120, left: 0 },
    )).toEqual({ top: 120, left: 0 })
  })
})
