import source from '@/pages/bank/StatementDetail.vue?raw'
import { describe, expect, it } from 'vitest'

// #272: Schválit / Zamítnout návrh zaúčtování nebo spárování a ruční zaúčtování
// mění jediný pohyb. Plný load() nastaví loading, odstraní DOM výpisu a načte jen
// 1. stránku – uživatel přijde o pozici. Tyto akce musí jít přes šetrné
// refreshTransactions(), které dotáhne všechny načtené stránky a loading nemění.
describe('StatementDetail – obnovení po akci nad pohybem (#272)', () => {
  it('změna řádku pohybu obnovuje šetrně, ne plným load()', () => {
    expect(source).not.toMatch(/@changed="load"/)
    expect(source.match(/@changed="refreshTransactions"/g)?.length).toBe(2)
  })

  it('akce návrhů spárování obnovují šetrně', () => {
    expect(source).toContain(
      'useBankTransactionActions({ reload: () => refreshTransactions(), refresh: () => refreshTransactions() })',
    )
  })

  it('ruční zaúčtování obnovuje šetrně', () => {
    const start = source.indexOf('function onTxPosted(')
    const body = source.slice(start, source.indexOf('\nfunction closeHint', start))
    expect(body).toContain('refreshTransactions()')
    expect(body).not.toMatch(/\bload\(\)/)
  })
})
