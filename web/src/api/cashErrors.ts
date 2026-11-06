/**
 * Překlad chybových kódů a varování pokladny na text pro uživatele.
 *
 * Proč sdíleně: mapování žilo ve třech kopiích (editor, seznam dokladů, správa
 * pokladen) a každá se rozešla jinak — jedna měla mrtvou větev `accounting.error.`,
 * druhá neuměla varování, třetí kontrolovala prefix `cash.error.` místo `cash.`.
 *
 * Dvě pravidla, která se z těch kopií nedala vyčíst:
 *
 *  1. `validation` je na backendu CATCH-ALL. `CashDocumentService` ho používá
 *     pro ~20 velmi konkrétních hlášek („Vazba na fakturu je povolena jen
 *     u úhrady FV/PF.", „Částka v cizí měně musí být větší než 0.", …).
 *     Protože `cash.error.validation` přeložený JE („Zkontrolujte zadané údaje."),
 *     naivní mapování ho vždy upřednostní a skutečný důvod se ztratí. Proto má
 *     u tohohle jediného kódu přednost `error.message` ze serveru.
 *  2. Kód přichází UŽ s prefixem (`cash.error.period_not_open`). Fallback tedy
 *     musí stavět `accounting.error.<zkrácený kód>`, ne `accounting.error.<celý kód>` —
 *     jinak je větev mrtvá a nikdy nic nenajde.
 *  3. Když server pošle `error.params` (strojová data hlášky), sáhne se nejdřív po
 *     variantě klíče `…_detail`, která je umí dosadit. Bez toho by konkrétní číslo
 *     ze serverové zprávy zmizelo — překlad má přednost u všech kódů kromě
 *     `validation`, takže „…v plné zbývající výši (12 100,00 Kč)" spadlo na obecné
 *     „…jen v plné výši." a uživatel se nedozvěděl, kolik má zadat.
 */

import { formatMoney } from '@/composables/useFormat'

type Translate = (key: string, params?: Record<string, unknown>) => string

const ERROR_PREFIX = 'cash.error.'
const WARNING_PREFIX = 'cash.warning.'

interface ApiErrorShape {
  response?: { data?: { error?: { code?: unknown; message?: unknown; params?: unknown } } }
}

/**
 * Peněžní hodnoty ze serveru chodí jako číslo — do textu hlášky ale patří ve
 * formátu aplikace (oddělovač tisíců, měna), ne jako `12100`.
 */
const MONEY_PARAMS = ['remaining', 'amount', 'limit'] as const

function readError(e: unknown): { code: string; message: string; params: Record<string, unknown> | null } {
  const err = (e as ApiErrorShape)?.response?.data?.error
  const raw = (err?.params && typeof err.params === 'object' && !Array.isArray(err.params))
    ? err.params as Record<string, unknown>
    : null
  let params: Record<string, unknown> | null = null
  if (raw !== null) {
    const currency = typeof raw.currency_code === 'string' ? raw.currency_code : 'CZK'
    params = { ...raw }
    for (const key of MONEY_PARAMS) {
      if (typeof raw[key] === 'number') params[key] = formatMoney(raw[key] as number, currency)
    }
  }
  return {
    code: typeof err?.code === 'string' ? err.code : '',
    message: typeof err?.message === 'string' ? err.message.trim() : '',
    params,
  }
}

/** Zkrátí `cash.error.x` na `x`; kód bez prefixu vrátí beze změny. */
export function shortCashErrorCode(code: string): string {
  return code.startsWith(ERROR_PREFIX) ? code.slice(ERROR_PREFIX.length) : code
}

/** Vytáhne holý chybový kód z axios chyby (prázdný řetězec, když žádný není). */
export function cashErrorCode(e: unknown): string {
  return shortCashErrorCode(readError(e).code)
}

/** Text chyby pro uživatele — viz pravidla v hlavičce souboru. */
export function cashErrorMessage(e: unknown, t: Translate): string {
  const { code, message, params } = readError(e)
  if (code === '') return message || t('common.error')

  const short = shortCashErrorCode(code)
  if (short === 'validation' && message !== '') return message
  // EET chyby (zařízení, kontext platby, nepodporovaný scénář) nesou přesný důvod v hlášce
  // serveru; obecný překlad `cash.error.eet_*` slouží jen jako záloha bez ní.
  if (short.startsWith('eet_') && message !== '') return message

  if (params !== null) {
    const detailKey = ERROR_PREFIX + short + '_detail'
    const detail = t(detailKey, params)
    if (detail !== detailKey) return detail
  }

  const cashKey = ERROR_PREFIX + short
  const localized = t(cashKey)
  if (localized !== cashKey) return localized

  const accountingKey = 'accounting.error.' + short
  const accounting = t(accountingKey)
  if (accounting !== accountingKey) return accounting

  return message || t('common.error')
}

/** Text varování (backend posílá buď plný klíč `cash.warning.x`, nebo holé `x`). */
export function cashWarningMessage(warning: string, t: Translate): string {
  const key = warning.startsWith('cash.') ? warning : WARNING_PREFIX + warning
  const localized = t(key)
  return localized !== key ? localized : warning
}
