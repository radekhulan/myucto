import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Nastavení firmy „Číslovat přijaté doklady podle data“ (`purchase_numbering_date_basis`).
 * Měsíc a rok čísla počítá server (PurchaseNumberingDate, pokryto PHPUnitem); tenhle test
 * hlídá formulář v číselných řadách přijatých dokladů: volba existuje, nabízí všechna tři
 * data, opravdu se ukládá a nápověda vysvětlí dopad příkladem.
 */
const page = readFileSync(resolve(process.cwd(), 'src/pages/admin/Settings.vue'), 'utf8')
const settingsApi = readFileSync(resolve(process.cwd(), 'src/api/settings.ts'), 'utf8')
const cs = JSON.parse(readFileSync(resolve(process.cwd(), 'src/i18n/cs.json'), 'utf8'))
const en = JSON.parse(readFileSync(resolve(process.cwd(), 'src/i18n/en.json'), 'utf8'))

describe('číslování přijatých dokladů podle data', () => {
  it('volba je v sekci číselných řad přijatých dokladů a nabízí vystavení, přijetí i DUZP', () => {
    const section = page.slice(page.indexOf('data-test="purchase-numbering"'), page.indexOf('</section>', page.indexOf('data-test="purchase-numbering"')))
    expect(section).toContain('v-model="supplier.purchase_numbering_date_basis"')
    expect(section).toContain("t('settings.purchase_numbering.date_basis')")
    expect(section).toContain("t('settings.purchase_numbering.date_basis_hint')")
    expect(page).toContain("const PURCHASE_NUMBERING_BASES = ['issue', 'received', 'tax'] as const")
    expect(settingsApi).toContain("purchase_numbering_date_basis?: 'issue' | 'received' | 'tax'")
  })

  it('volbu skutečně posílá do uložení nastavení', () => {
    // Payload v saveSupplier() je vypsaný ručně, zapomenuté pole by se tiše neuložilo.
    expect(page).toMatch(/purchase_numbering_date_basis:\s*supplier\.value\.purchase_numbering_date_basis/)
  })

  it('má české i anglické texty s příkladem a výchozím chováním', () => {
    for (const dict of [cs, en]) {
      const pn = dict.settings.purchase_numbering
      expect(Object.keys(pn.date_basis_option)).toEqual(['issue', 'received', 'tax'])
      expect(pn.date_basis.length).toBeGreaterThan(0)
      expect(pn.date_basis_hint).toContain('PF2611001')
      expect(pn.date_basis_hint).toContain('PF2610001')
    }
    expect(cs.settings.purchase_numbering.date_basis_hint).toContain('podle data vystavení')
    expect(cs.settings.purchase_numbering.intro).not.toContain('z data vystavení')
  })

  it('dialog přečíslování odkazuje na totéž nastavení', () => {
    expect(cs.purchase_invoice.renumber.basis_hint).toContain(cs.settings.purchase_numbering.date_basis)
    expect(en.purchase_invoice.renumber.basis_hint).toContain(en.settings.purchase_numbering.date_basis)
    for (const dict of [cs, en]) {
      for (const reason of ['document_locked', 'number_month_locked', 'month_locked', 'date_other_month']) {
        expect(typeof dict.purchase_invoice.renumber.reason[reason], reason).toBe('string')
      }
      expect(dict.purchase_invoice.renumber.reason.number_other_month).toBeUndefined()
      expect(dict.purchase_invoice.renumber.moved_from).toContain('{period}')
    }
  })
})
