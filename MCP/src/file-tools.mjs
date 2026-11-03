/**
 * Nástroje pro soubory: PDF a ISDOC vydaných faktur, jejich přílohy, PDF
 * přijatých faktur, import přijaté faktury ze souboru, Dokumenty a média zboží.
 *
 * Soubor jde celý přes asistenta. Stažení vrací obsah jako obrázek, vložený
 * prostředek (`resource`) nebo na přání base64 v JSON; nahrání bere base64
 * v `content_base64`. Strop drží klient (`maxFileBytes`): 10 MB lokálně
 * (MYUCTO_MAX_FILE_MB), 5 MB v serverovém MCP, kde soubor putuje JSON zprávami
 * mezi Node a PHP.
 *
 * Povolené typy u nahrání odpovídají tomu, co přijímá cílová akce API. Server
 * je ověřuje znovu z obsahu souboru; tady jde o to, aby model dostal
 * srozumitelnou chybu dřív, než pošle megabajty, které API stejně odmítne.
 * Sjednocení všech typů musí být podmnožinou `McpFileLimits::ALLOWED_UPLOAD_TYPES`
 * v PHP, jinak by soubor zastavil most (hlídá test).
 */

import { NOT_IN_CLIENT_CATALOG } from './api-contracts.mjs';
import {
  contentTypeForFilename, extensionOf, normalizeContentType, sanitizeFilename,
} from './client.mjs';
import { fileResult } from './tool-result.mjs';
import { DOCUMENT_ENTITY_TYPES, seg } from './tool-shared.mjs';

const str = (description, extra = {}) => ({ type: 'string', description, ...extra });
const int = (description, extra = {}) => ({ type: 'integer', description, ...extra });
const bool = (description) => ({ type: 'boolean', description });
const id = (description) => int(description, { minimum: 1 });
const schema = (properties = {}, required = []) => ({
  type: 'object', properties, required, additionalProperties: false,
});

const SIZE_NOTE = 'Soubor jde celý přes asistenta: strop je 10 MB u lokálního serveru '
  + '(MYUCTO_MAX_FILE_MB) a 5 MB u serverového MCP. Base64 je o třetinu delší než soubor '
  + 'a zabírá místo v konverzaci, proto stahuj jen soubory, které opravdu potřebuješ.';

const FORMAT = str(
  'Jak soubor vrátit. `resource` (výchozí) = obrázek nebo vložený soubor, který klient '
  + 'ukáže nebo předá modelu, XML a text rovnou jako text. `base64` = obsah jako '
  + '`content_base64` v JSON, třeba pro předání do nahrávacího nástroje.',
  { enum: ['resource', 'base64'] },
);

const CONFIRM = bool(
  'Potvrzení nevratné operace. Bez `true` se NIC nezmění, nástroj jen vrátí, '
  + 'čeho by se změna týkala. Ten výpis ukaž uživateli a zavolej nástroj znovu '
  + 's `confirm: true` teprve po jeho souhlasu.',
);

function requireConfirm(a, action, label) {
  if (a.confirm === true) return;
  throw new Error(
    `NEPROVEDENO, chybí potvrzení. ${action}: ${label}.\n`
    + 'Ukaž to uživateli a teprve po jeho souhlasu zavolej nástroj znovu s `confirm: true`.',
  );
}

// ────────────────────────────────────────────────────────────────────────────
// Povolené typy podle cílové akce (přípona → typy obsahu)
// ────────────────────────────────────────────────────────────────────────────

const PDF = { pdf: ['application/pdf'] };
const PHOTOS = {
  jpg: ['image/jpeg'], jpeg: ['image/jpeg'], png: ['image/png'],
};
const IMAGES = {
  ...PHOTOS, gif: ['image/gif'], webp: ['image/webp'], heic: ['image/heic'], heif: ['image/heif'],
};
const XML = { isdoc: ['application/xml', 'text/xml'], xml: ['application/xml', 'text/xml'] };
const OFFICE = {
  doc: ['application/msword'],
  docx: ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
  xls: ['application/vnd.ms-excel'],
  xlsx: ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
  ppt: ['application/vnd.ms-powerpoint'],
  pptx: ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
  odt: ['application/vnd.oasis.opendocument.text'],
  ods: ['application/vnd.oasis.opendocument.spreadsheet'],
  odp: ['application/vnd.oasis.opendocument.presentation'],
};
const TEXT = { txt: ['text/plain'], csv: ['text/csv', 'text/plain'] };
const ZIP = { zip: ['application/zip'] };

/** Zrcadlí `UploadAttachmentAction::ALLOWED_MIME`. */
export const INVOICE_ATTACHMENT_TYPES = { ...PDF, ...OFFICE, ...TEXT, ...IMAGES, ...ZIP };
/** PDF, nebo fotka, kterou `UploadPurchaseInvoicePdfAction` převede na PDF. */
export const PURCHASE_PDF_TYPES = { ...PDF, ...PHOTOS };
/** `ImportStructuredPurchaseInvoiceAction::ALLOWED_EXTENSIONS`. */
export const STRUCTURED_IMPORT_TYPES = {
  ...PDF, isdoc: XML.isdoc, isdocx: ['application/zip'],
};
/** Dokumenty přijmou téměř cokoli; přes MCP jen běžné kancelářské typy, žádné HTML ani skripty. */
export const DOCUMENT_TYPES = { ...PDF, ...XML, ...IMAGES, ...OFFICE, ...TEXT, ...ZIP };
export const PRODUCT_MEDIA_TYPES = {
  ...PHOTOS, gif: ['image/gif'], webp: ['image/webp'], ...PDF,
};

const UPLOAD_INPUT = (what) => ({
  content_base64: str(`Obsah souboru v base64 (${what}). Prefix "data:…;base64," se toleruje.`),
  filename: str('Název souboru včetně přípony, např. "faktura.pdf". Cesta se z názvu odstraní.'),
  content_type: str('Typ obsahu, např. "application/pdf". Bez zadání se odvodí z přípony; musí jí odpovídat.'),
});

/**
 * Ověří vstup nahrání proti povoleným typům. Název se očistí od cesty,
 * typ obsahu musí odpovídat příponě (jinak by ho server stejně odmítl jako
 * „extension_mismatch", ale až po přenosu celého souboru).
 */
export function fileInput(a, allowed, label) {
  const filename = sanitizeFilename(a.filename);
  const ext = extensionOf(filename);
  const accepted = allowed[ext];
  const list = Object.keys(allowed).map((e) => `.${e}`).join(', ');
  if (!accepted) {
    throw new Error(`${label} přijímá jen soubory ${list}; "${filename}" ${ext ? `má příponu .${ext}` : 'nemá příponu'}.`);
  }
  const contentType = normalizeContentType(a.content_type ?? contentTypeForFilename(filename) ?? accepted[0]);
  if (!accepted.includes(contentType)) {
    throw new Error(`content_type "${contentType}" neodpovídá příponě .${ext} (čekám ${accepted.join(' nebo ')}).`);
  }
  return { filename, content_type: contentType, content_base64: a.content_base64 };
}

const kb = (bytes) => (Number.isFinite(Number(bytes)) ? `${Math.round(Number(bytes) / 1024)} kB` : '? kB');

function download(name, title, description, properties, required, path, { query, uri, meta, api = false }) {
  return {
    name,
    api,
    ...(api === false ? { apiReason: NOT_IN_CLIENT_CATALOG } : {}),
    title,
    description: `${description} ${SIZE_NOTE}`,
    inputSchema: schema({ ...properties, format: FORMAT }, required),
    write: false,
    run: async (c, a, tool) => fileResult(
      await c.download(path(a), query?.(a) ?? null, tool),
      { uri: uri(a), format: a.format ?? 'resource', meta: meta(a) },
    ),
  };
}

export const FILE_TOOLS = [
  // ──────────────────────────────────────────────────────────────────────────
  // Vydané faktury
  // ──────────────────────────────────────────────────────────────────────────
  download(
    'download_invoice_pdf', 'Stáhnout PDF vydané faktury',
    'PDF vydané faktury tak, jak ho dostane odběratel. Když PDF ještě nebylo vytvořené, aplikace ho vyrenderuje.',
    { id: id('ID vydané faktury.') }, ['id'],
    (a) => `/invoices/${seg(a.id)}/pdf`,
    { api: [['GET', '/api/invoices/{id}/pdf']], query: () => ({ download: 1 }), uri: (a) => `myucto://invoices/${a.id}/pdf`, meta: (a) => ({ invoice_id: a.id }) },
  ),
  download(
    'download_invoice_isdoc', 'Stáhnout ISDOC vydané faktury',
    'Strukturovaná faktura ve formátu ISDOC (XML). Jen u vystavené faktury, ne u konceptu ani stornované.',
    { id: id('ID vydané faktury.') }, ['id'],
    (a) => `/invoices/${seg(a.id)}/isdoc`,
    { api: [['GET', '/api/invoices/{id}/isdoc']], uri: (a) => `myucto://invoices/${a.id}/isdoc`, meta: (a) => ({ invoice_id: a.id }) },
  ),
  {
    name: 'list_invoice_attachments',
    api: [
      ['GET', '/api/invoices/{id}/attachments'],
    ],
    title: 'Přílohy vydané faktury',
    description: 'Seznam souborů přiložených k vydané faktuře (id, název, velikost, typ). Obsah nevrací.',
    inputSchema: schema({ id: id('ID vydané faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${seg(a.id)}/attachments`, null, tool),
  },
  download(
    'download_invoice_attachment', 'Stáhnout přílohu vydané faktury',
    'Obsah jedné přílohy vydané faktury. Id přílohy vrátí `list_invoice_attachments`.',
    { id: id('ID vydané faktury.'), attachment_id: id('ID přílohy.') }, ['id', 'attachment_id'],
    (a) => `/invoices/${seg(a.id)}/attachments/${seg(a.attachment_id)}`,
    {
      api: [['GET', '/api/invoices/{id}/attachments/{item_id}']],
      query: () => ({ download: 1 }),
      uri: (a) => `myucto://invoices/${a.id}/attachments/${a.attachment_id}`,
      meta: (a) => ({ invoice_id: a.id, attachment_id: a.attachment_id }),
    },
  ),
  {
    name: 'upload_invoice_attachment',
    api: [
      ['POST', '/api/invoices/{id}/attachments'],
    ],
    title: 'Přiložit soubor k vydané faktuře',
    description:
      'Nahraje přílohu k vydané faktuře (posílá se odběrateli spolu s PDF). Povolené typy: '
      + `${Object.keys(INVOICE_ATTACHMENT_TYPES).map((e) => `.${e}`).join(', ')}. `
      + 'Server navíc drží 10 MB na soubor a 20 MB na všechny přílohy faktury. K internímu '
      + `stornu přílohu přidat nejde. ${SIZE_NOTE}`,
    inputSchema: schema({ id: id('ID vydané faktury.'), ...UPLOAD_INPUT('příloha') },
      ['id', 'content_base64', 'filename']),
    write: true,
    run: async (c, a, tool) => c.upload(
      `/invoices/${seg(a.id)}/attachments`,
      fileInput(a, INVOICE_ATTACHMENT_TYPES, 'Příloha faktury'),
      tool,
    ),
  },
  {
    name: 'delete_invoice_attachment',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Smazat přílohu vydané faktury',
    description:
      'Smaže přílohu vydané faktury včetně souboru. Bez `confirm: true` jen vypíše, '
      + 'kterou přílohu by smazal. U faktury v uzavřeném období ji aplikace nemusí dovolit smazat.',
    inputSchema: schema({
      id: id('ID vydané faktury.'),
      attachment_id: id('ID přílohy z `list_invoice_attachments`.'),
      confirm: CONFIRM,
    }, ['id', 'attachment_id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const list = await c.get(`/invoices/${seg(a.id)}/attachments`, null, tool);
      const attachment = (list?.items ?? []).find((row) => Number(row.id) === a.attachment_id);
      if (!attachment) {
        throw new Error(`Příloha #${a.attachment_id} u faktury #${a.id} neexistuje.`);
      }
      requireConfirm(a, 'Smazat se má příloha faktury',
        `${attachment.original_name ?? `#${a.attachment_id}`} (${kb(attachment.size_bytes)})`);
      return {
        deleted: attachment,
        result: await c.del(`/invoices/${seg(a.id)}/attachments/${seg(a.attachment_id)}`, tool),
      };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Přijaté faktury
  // ──────────────────────────────────────────────────────────────────────────
  download(
    'download_purchase_invoice_pdf', 'Stáhnout PDF přijaté faktury',
    'Originál dokladu od dodavatele, archivovaný u přijaté faktury. Když doklad PDF nemá, vrátí chybu `no_pdf`.',
    { id: id('ID přijaté faktury.') }, ['id'],
    (a) => `/purchase-invoices/${seg(a.id)}/pdf`,
    { api: [['GET', '/api/purchase-invoices/{id}/pdf']], uri: (a) => `myucto://purchase-invoices/${a.id}/pdf`, meta: (a) => ({ purchase_invoice_id: a.id }) },
  ),
  {
    name: 'upload_purchase_invoice_pdf',
    api: [
      ['GET', '/api/purchase-invoices/{id}'],
      ['POST', '/api/purchase-invoices/{id}/pdf'],
    ],
    title: 'Nahrát PDF k přijaté faktuře',
    description:
      'Archivuje originál dokladu (PDF) u přijaté faktury. Fotku .jpg nebo .png aplikace '
      + 'převede na PDF. Stejné PDF archivované u jiné faktury vrátí chybu 409 s jejím id. '
      + 'Když faktura už PDF má, nahrání ho nahradí, a proto vyžaduje `confirm: true`. '
      + `U dokladu v uzavřeném období aplikace změnu odmítne. ${SIZE_NOTE}`,
    inputSchema: schema({
      id: id('ID přijaté faktury.'),
      ...UPLOAD_INPUT('PDF nebo fotka dokladu'),
      confirm: bool('Potvrzení náhrady už archivovaného PDF. Bez něj se existující PDF nepřepíše.'),
    }, ['id', 'content_base64', 'filename']),
    write: true,
    run: async (c, a, tool) => {
      const file = fileInput(a, PURCHASE_PDF_TYPES, 'PDF přijaté faktury');
      const invoice = await c.get(`/purchase-invoices/${seg(a.id)}`, null, tool);
      const current = invoice?.pdf_path ? (invoice.pdf_original_name || invoice.pdf_path) : null;
      if (current) {
        requireConfirm(a, 'Faktura už má archivované PDF a nahrání ho nahradí', current);
      }
      return c.upload(`/purchase-invoices/${seg(a.id)}/pdf`, file, tool);
    },
  },
  {
    name: 'delete_purchase_invoice_pdf',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Smazat PDF přijaté faktury',
    description:
      'Odebere archivovaný originál dokladu od přijaté faktury. Samotná faktura zůstane. '
      + 'Bez `confirm: true` jen vypíše, které PDF by smazal.',
    inputSchema: schema({ id: id('ID přijaté faktury.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const invoice = await c.get(`/purchase-invoices/${seg(a.id)}`, null, tool);
      if (!invoice?.pdf_path) {
        throw new Error(`Přijatá faktura #${a.id} nemá archivované PDF.`);
      }
      const label = `${invoice.pdf_original_name || invoice.pdf_path} u faktury `
        + `${invoice.vendor_invoice_number ?? invoice.doc_number ?? `#${a.id}`}`;
      requireConfirm(a, 'Smazat se má PDF přijaté faktury', label);
      return { deleted: { id: a.id, pdf: invoice.pdf_original_name ?? invoice.pdf_path }, result: await c.del(`/purchase-invoices/${seg(a.id)}/pdf`, tool) };
    },
  },
  {
    name: 'import_purchase_invoice_file',
    api: [
      ['POST', '/api/purchase-invoices/import-structured'],
    ],
    title: 'Založit přijatou fakturu ze souboru',
    description:
      'Založí přijatou fakturu ze strukturovaného souboru: ISDOC (.isdoc), balíčku ISDOCX '
      + '(.isdocx) nebo PDF s vloženým ISDOC. Import je deterministický a NEPOUŽÍVÁ AI vytěžení. '
      + 'Obyčejné PDF bez ISDOC vrátí chybu `no_embedded_isdoc`: takový doklad založ ručně '
      + 'a PDF k němu nahraj nástrojem `upload_purchase_invoice_pdf`. Vrací `purchase_invoice_id`, '
      + '`source` a `duplicate: true`, když stejný doklad už v evidenci je (nic nového se nezaloží). '
      + `Doklad s datem v uzavřeném období aplikace odmítne. ${SIZE_NOTE}`,
    inputSchema: schema(UPLOAD_INPUT('ISDOC, ISDOCX nebo PDF s ISDOC'), ['content_base64', 'filename']),
    write: true,
    run: async (c, a, tool) => c.upload(
      '/purchase-invoices/import-structured',
      fileInput(a, STRUCTURED_IMPORT_TYPES, 'Import přijaté faktury'),
      tool,
    ),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Dokumenty
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'upload_document',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Nahrát soubor do Dokumentů',
    description:
      'Uloží soubor do sekce Dokumenty (volitelně do složky), doplní název, popis a tagy '
      + 'a připojí ho k záznamu. Aplikace z dokumentu vytěží text pro fulltext. Povolené typy: '
      + `${Object.keys(DOCUMENT_TYPES).map((e) => `.${e}`).join(', ')}. ZIP se uloží jako jeden `
      + `soubor, pokud nezvolíš \`zip_mode: "explode"\`. ${SIZE_NOTE}`,
    inputSchema: schema({
      ...UPLOAD_INPUT('dokument'),
      folder_id: id('ID cílové složky; bez zadání kořen.'),
      zip_mode: str('ZIP uložit vcelku (`keep`, výchozí), nebo rozbalit (`explode`).', { enum: ['keep', 'explode'] }),
      title: str('Název dokumentu; bez zadání název souboru.'),
      description: str('Popis dokumentu.'),
      tags: { type: 'array', items: { type: 'string' }, description: 'Tagy dokumentu.' },
      entity_type: str('Připojit k záznamu tohoto typu; smlouvu k ostatní pohledávce či závazku přes `other_item`.', {
        enum: DOCUMENT_ENTITY_TYPES,
      }),
      entity_id: id('ID záznamu, ke kterému se dokument připojí.'),
    }, ['content_base64', 'filename']),
    write: true,
    run: async (c, a, tool) => {
      if ((a.entity_type === undefined) !== (a.entity_id === undefined)) {
        throw new Error('Pro připojení k záznamu zadej `entity_type` i `entity_id` současně.');
      }
      const file = fileInput(a, DOCUMENT_TYPES, 'Dokumenty');
      const upload = await c.upload('/documents', file, tool, {
        fields: { folder_id: a.folder_id, zip_mode: a.zip_mode },
      });
      const roots = Array.isArray(upload?.root_ids) ? upload.root_ids : [];
      const result = { upload, document_id: roots.length === 1 ? roots[0] : null };

      const meta = Object.fromEntries(
        ['title', 'description', 'tags'].filter((k) => a[k] !== undefined).map((k) => [k, a[k]]),
      );
      const wantsMeta = Object.keys(meta).length > 0;
      if ((wantsMeta || a.entity_type) && result.document_id === null) {
        result.warning = `Nahrání vytvořilo ${roots.length} dokumentů nejvyšší úrovně, `
          + 'metadata ani vazbu proto nelze přiřadit jednoznačně. Doplň je přes '
          + '`update_document` a `link_document`.';
        return result;
      }
      if (wantsMeta) {
        result.document = await c.patch(`/documents/${seg(result.document_id)}`, meta, tool);
      }
      if (a.entity_type) {
        result.link = await c.post(`/documents/${seg(result.document_id)}/links`, {
          entity_type: a.entity_type,
          entity_id: a.entity_id,
        }, tool);
      }
      return result;
    },
  },
  download(
    'download_document', 'Stáhnout originál dokumentu',
    'Originální soubor dokumentu ze sekce Dokumenty. S `file_id` stáhne jeden z dalších souborů '
      + 'dokladu (seznam vrací `get_document`). Pro práci s textem stačí levnější `get_document` '
      + 's `include_text: true`.',
    { id: id('ID dokumentu.'), file_id: id('ID dalšího souboru dokumentu; bez zadání hlavní soubor.') }, ['id'],
    (a) => (a.file_id ? `/documents/${seg(a.id)}/files/${seg(a.file_id)}/download` : `/documents/${seg(a.id)}/download`),
    {
      uri: (a) => `myucto://documents/${a.id}${a.file_id ? `/files/${seg(a.file_id)}` : ''}`,
      meta: (a) => ({ document_id: a.id, ...(a.file_id ? { file_id: a.file_id } : {}) }),
    },
  ),

  // ──────────────────────────────────────────────────────────────────────────
  // E-shop — média zboží
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'upload_product_media',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Nahrát obrázek ke zboží',
    description:
      'Přidá obrázek (.jpg, .png, .webp, .gif) nebo PDF přílohu ke kartě zboží. Popisky, pořadí '
      + `a hlavní obrázek pak nastavíš nástrojem \`update_product_media\`. ${SIZE_NOTE}`,
    inputSchema: schema({ id: id('ID zboží.'), ...UPLOAD_INPUT('obrázek nebo PDF') },
      ['id', 'content_base64', 'filename']),
    write: true,
    run: async (c, a, tool) => c.upload(
      `/eshop/products/${seg(a.id)}/media`,
      fileInput(a, PRODUCT_MEDIA_TYPES, 'Média zboží'),
      tool,
    ),
  },
];
