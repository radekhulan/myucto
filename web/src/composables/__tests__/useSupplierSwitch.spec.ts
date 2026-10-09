import { describe, expect, it } from 'vitest'
import { matchSwitchableSuppliers, supplierSwitchDestination } from '../useSupplierSwitch'
import type { SupplierBrief } from '@/api/auth'

const firm = (id: number, company_name: string, ic: string | null) => ({ id, company_name, ic }) as SupplierBrief

const LIST = [
  firm(1, 'Alfa Stavby s.r.o.', '11111111'),
  firm(2, 'Beta Účetní s.r.o.', '22222222'),
  firm(3, 'Gama Obchod a.s.', null),
]

describe('matchSwitchableSuppliers', () => {
  it('najde firmu podle názvu bez ohledu na diakritiku a velikost písmen', () => {
    expect(matchSwitchableSuppliers(LIST, 1, 'ucetni', true).map(s => s.id)).toEqual([2])
    expect(matchSwitchableSuppliers(LIST, 1, 'GAMA', true).map(s => s.id)).toEqual([3])
  })

  it('najde firmu podle IČ a víc slov musí sedět všechna', () => {
    expect(matchSwitchableSuppliers(LIST, 1, '2222', true).map(s => s.id)).toEqual([2])
    expect(matchSwitchableSuppliers(LIST, 1, 'beta s.r.o.', true).map(s => s.id)).toEqual([2])
    expect(matchSwitchableSuppliers(LIST, 1, 'beta gama', true)).toEqual([])
  })

  it('aktuální firmu nenabízí', () => {
    expect(matchSwitchableSuppliers(LIST, 1, 'alfa', true)).toEqual([])
  })

  it('bez víc firem (nebo u uzamčené domény) ani bez dotazu nic nenabízí', () => {
    expect(matchSwitchableSuppliers(LIST, 1, 'beta', false)).toEqual([])
    expect(matchSwitchableSuppliers(LIST, 1, '   ', true)).toEqual([])
  })
})

describe('supplierSwitchDestination', () => {
  it('z přehledu všech firem otevře domovskou stránku zvolené firmy', () => {
    expect(supplierSwitchDestination('/portfolio')).toBe('/')
  })

  it('z detailu přejde na seznam a na běžné stránce ponechá adresu', () => {
    expect(supplierSwitchDestination('/invoices/42')).toBe('/invoices')
    expect(supplierSwitchDestination('/bank')).toBeNull()
  })

  it('z detailu libovolného dokladu přejde o úroveň výš, ať ho odkaz nepřepne zpátky', () => {
    expect(supplierSwitchDestination('/purchase-invoices/674')).toBe('/purchase-invoices')
    expect(supplierSwitchDestination('/purchase-invoices/674/edit')).toBe('/purchase-invoices')
    expect(supplierSwitchDestination('/stock/documents/12')).toBe('/stock/documents')
    expect(supplierSwitchDestination('/accounting/journal', '?entry_id=5')).toBe('/accounting/journal')
    expect(supplierSwitchDestination('/accounting/journal', '?year=2026')).toBeNull()
  })
})
