import { describe, expect, it } from 'vitest'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import { scheduleTitleErrorText } from '../otherItemScheduleTitleError'

/** Kódy, které vrátí založení opakování a náhled názvu (OtherItemScheduleService::create, previewTitle). */
const DIALOG_CODES = [
  'invalid_status', 'already_scheduled', 'created_by_schedule', 'schedule_source_inactive', 'schedule_finished',
  'invalid_frequency', 'invalid_end', 'invalid_date', 'invalid_title_template', 'title_template_too_long',
  'unknown_title_placeholder', 'occurrence_title_too_long', 'invalid_title_sync_period',
]

function translator(messages: Record<string, unknown>) {
  const lookup = (key: string): unknown => key.split('.').reduce<unknown>((node, part) =>
    node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined, messages)
  return {
    t: (key: string) => String(lookup(key)),
    te: (key: string) => typeof lookup(key) === 'string',
  }
}

describe('scheduleTitleErrorText', () => {
  it.each([['cs', cs], ['en', en]] as const)('každý kód dialogu opakování má překlad (%s), hláška serveru se neukáže', (_locale, messages) => {
    const { t, te } = translator(messages as Record<string, unknown>)
    for (const code of DIALOG_CODES) {
      expect(scheduleTitleErrorText({ code: `other_items.error.${code}`, message: 'SERVER' }, t, te), code).not.toBe('SERVER')
    }
  })
})
