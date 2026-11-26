import { endpoint } from './endpoint-tool.mjs';
import { pick } from './tool-schema.mjs';
import { seg } from './tool-shared.mjs';

export const API_INVOICES_TOOLS = [
  endpoint('clone_invoice_drafts_bulk', 'Zkopíruje vybrané faktury do nových konceptů',
    'Zkopíruje vybrané faktury do nových konceptů. Každá z nejvýše 200 vybraných faktur se zkopíruje do samostatného konceptu. Pro provedení je potřeba confirm: true.',
    '/invoices/bulk-reissue', {
    method: 'POST',
    confirm: true,
    preview: async (c, a, tool) => {
      const items = await Promise.all([...new Set(a.invoice_ids)].map(async (id) => {
        const invoice = await c.get(`/invoices/${seg(id)}`, null, tool);
        return pick(invoice, ['id', 'invoice_number', 'number', 'client_name', 'issue_date', 'total_with_vat', 'currency']);
      }));
      return { count: items.length, items };
    },
    body: {
      invoice_ids: {
        type: 'array',
        description: 'ID zdrojových faktur.',
        minItems: 1,
        maxItems: 200,
        items: { type: 'integer', description: 'ID vydané faktury.', minimum: 1 },
      },
      issue_date: { type: ['string', 'null'], description: 'Datum vystavení nových konceptů. Výchozí je dnešek.', format: 'date' },
      increment_month_in_descriptions: { type: 'boolean', description: 'Posune měsíc v popisech položek.', default: true },
    },
    required: ['invoice_ids'],
  }),
  endpoint('search_invoice_tags', 'Našeptávání interních štítků firmy (nejvýše 50 shod)',
    'Našeptávání interních štítků firmy (nejvýše 50 shod).',
    '/invoices/tags', {
    query: {
      q: { type: 'string', description: 'Začátek názvu', maxLength: 64 },
      ids: { type: 'string', description: 'Až 64 konkrétních ID oddělených čárkou pro uložený filtr.' },
    },
  }),
  endpoint('list_invoice_activity', 'Audit log faktury',
    'Audit log faktury. Vrátí chronologický log všech akcí, které faktura prošla (created, updated, issued, sent, paid, reminded, approval_requested, approval_decided, cancelled, ...).',
    '/invoices/{id}/activity', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('download_invoice_imported_pdf', 'Originál PDF vydané faktury',
    'Originál PDF vydané faktury. Vrátí archivovaný originál PDF vydané faktury (z importu iDoklad/Fakturoid nebo ručně nahraný). nezaměňovat s naším vlastním rendered PDF (`GET /api/v1/invoices/{id}/pdf`).',
    '/invoices/{id}/imported-pdf', {
    file: true,
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('list_invoice_items', 'Položky faktury bez načtení celého detailu',
    'Položky faktury bez načtení celého detailu.',
    '/invoices/{id}/items', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('get_invoice_opening_balance', 'Je vydaný doklad převzatý z počátečních stavů?',
    'Je vydaný doklad převzatý z počátečních stavů? Vazba dokladu na živý otevírací zápis (`journal_entries.source_type = opening`).',
    '/invoices/{id}/opening-balance', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('list_invoice_other_item_candidates', 'Ostatní pohledávky, na které lze fakturu navázat',
    'Ostatní pohledávky, na které lze fakturu navázat. Zaúčtované nenavázané ostatní pohledávky firmy ve stejné měně.',
    '/invoices/{id}/other-item-candidates', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
    query: { q: { type: 'string', description: 'Hledání v čísle, partnerovi, VS a názvu pohledávky.' } },
  }),
  endpoint('list_invoice_archived_pdfs', 'Archiv vystavených/odeslaných verzí PDF',
    'Archiv vystavených/odeslaných verzí PDF. Pokaždé, když se faktura vystaví, znovu-vystaví nebo pošle e-mailem, se PDF archivuje.',
    '/invoices/{id}/pdfs', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('download_invoice_archived_pdf', 'Stažení archivované verze PDF',
    'Stažení archivované verze PDF.',
    '/invoices/{id}/pdfs/{archive_id}', {
    file: true,
    params: {
      id: { type: 'integer', description: 'ID záznamu.', minimum: 1 },
      archive_id: { type: 'integer', description: 'ID archivované verze.', minimum: 1 },
    },
  }),
  endpoint('list_invoice_source_receipts', 'Příjemky, ze kterých faktura vznikla',
    'Příjemky, ze kterých faktura vznikla.',
    '/invoices/{id}/source-receipts', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('list_invoice_stock_documents', 'Skladové doklady vázané na vydanou fakturu',
    'Skladové doklady vázané na vydanou fakturu. Vrací výdejky/doklady vytvořené k této faktuře (např. výdej ze skladu při fakturaci).',
    '/invoices/{id}/stock-documents', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('list_invoice_tax_document_advance_candidates', 'Nabídne protějšky k navázání daňového dokladu k přijaté platbě na zálohu',
    'Nabídne protějšky k navázání daňového dokladu k přijaté platbě na zálohu. Vyžaduje čtení vydaných faktur.',
    '/invoices/{id}/tax-document-advance-candidates', {
    params: { id: { type: 'integer', description: 'ID daňového dokladu k přijaté platbě nebo zálohové faktury.', minimum: 1 } },
  }),
  endpoint('delete_invoice_work_report', 'Smaže výkaz',
    'Smaže výkaz. Výkaz lze smazat jen u konceptu faktury. Pro provedení je potřeba confirm: true.',
    '/invoices/{id}/work-report', {
    method: 'DELETE',
    confirm: true,
    preview: '/invoices/{id}/work-report',
    params: { id: { type: 'integer', description: 'ID faktury', minimum: 1 } },
  }),
  endpoint('search_recurring_invoice_tags', 'Našeptávání interních štítků firmy (nejvýše 50 shod)',
    'Našeptávání interních štítků firmy (nejvýše 50 shod).',
    '/recurring/tags', {
    query: {
      q: { type: 'string', description: 'Začátek názvu', maxLength: 64 },
      ids: { type: 'string', description: 'Až 64 konkrétních ID oddělených čárkou pro uložený filtr.' },
    },
  }),
  endpoint('list_work_report_billing_templates', 'Seznam šablon účtování výkazů práce',
    'Seznam šablon účtování výkazů práce. Aktivní šablony aktuální firmy včetně paušálu a pásem hodin, seřazené podle názvu a ID, nejvýše 500 záznamů.',
    '/work-report-billing-templates'),
];
