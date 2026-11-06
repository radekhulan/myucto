import type { JournalSide, LinkableDocType } from '@/api/accounting'
import type { InternalDocumentKind } from '@/api/internalDocuments'

/**
 * Šablony nejčastějších interních dokladů pro rychlé zadání. Předvyplní druh,
 * popis a účty podle směrné účtové osnovy (vyhláška 500/2002 Sb.), částky doplní
 * účetní. Účty jdou v řádcích přepsat, analytiku si účetní vybere našeptávačem.
 *
 * `linkTypes` = doklady, které má smysl k případu navázat (zápočet vydané
 * a přijaté faktury); vazba se při zaúčtování přenese do vazeb zápisu deníku
 * a saldokonto podle ní doklady vyrovná.
 */
export interface InternalDocumentTemplate {
  key: string
  kind: InternalDocumentKind
  lines: Array<{ account_code: string; side: JournalSide }>
  linkTypes?: LinkableDocType[]
}

export const INTERNAL_DOCUMENT_TEMPLATES: InternalDocumentTemplate[] = [
  { key: 'offset', kind: 'offset', linkTypes: ['invoice', 'purchase_invoice'],
    lines: [{ account_code: '321', side: 'debit' }, { account_code: '311', side: 'credit' }] },
  { key: 'reclassification', kind: 'reclassification',
    lines: [{ account_code: '', side: 'debit' }, { account_code: '', side: 'credit' }] },
  { key: 'write_off', kind: 'write_off', linkTypes: ['invoice'],
    lines: [{ account_code: '546', side: 'debit' }, { account_code: '311', side: 'credit' }] },
  { key: 'fx_loss', kind: 'fx_difference', linkTypes: ['invoice', 'purchase_invoice'],
    lines: [{ account_code: '563', side: 'debit' }, { account_code: '311', side: 'credit' }] },
  { key: 'fx_gain', kind: 'fx_difference', linkTypes: ['invoice', 'purchase_invoice'],
    lines: [{ account_code: '311', side: 'debit' }, { account_code: '663', side: 'credit' }] },
  { key: 'accrual', kind: 'accrual',
    lines: [{ account_code: '518', side: 'debit' }, { account_code: '389', side: 'credit' }] },
]
