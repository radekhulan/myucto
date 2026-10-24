import type { MoneyS3Step } from '@/api/moneyS3'

export type StereoNxProtocolMessage = MoneyS3Step['messages'][number] & { step: string }
export type StereoNxMessageLevel = StereoNxProtocolMessage['level']
export interface StereoNxMessageGroup {
  key: string
  level: StereoNxMessageLevel
  code: string
  count: number
  detailOccurrences: number
  summaries: StereoNxProtocolMessage[]
  details: StereoNxProtocolMessage[]
}
export interface StereoNxMessageGroupMatch {
  group: StereoNxMessageGroup
  details: StereoNxProtocolMessage[]
}

const severityOrder: Record<StereoNxMessageLevel, number> = { error: 0, warning: 1, info: 2 }

export function stereoNxMessageOccurrences(message: StereoNxProtocolMessage): number {
  const count = message.context.count
  return typeof count === 'number' && Number.isFinite(count) && Number.isInteger(count) && count > 0 ? count : 1
}

/** Souhrnný počet není další detail ani počet unikátních dokladů. */
export function groupStereoNxMessages(messages: readonly StereoNxProtocolMessage[]): StereoNxMessageGroup[] {
  const groups = new Map<string, StereoNxMessageGroup>()
  for (const message of messages) {
    const key = JSON.stringify([message.level, message.code])
    let group = groups.get(key)
    if (!group) {
      group = { key, level: message.level, code: message.code, count: 0, detailOccurrences: 0, summaries: [], details: [] }
      groups.set(key, group)
    }
    if (message.context.summary === true) {
      group.summaries.push(message)
      const count = message.context.count
      if (typeof count === 'number' && Number.isFinite(count) && count >= 0) group.count = Math.max(group.count, Math.floor(count))
    } else {
      group.details.push(message)
      group.detailOccurrences += stereoNxMessageOccurrences(message)
    }
  }
  const result = [...groups.values()]
  for (const group of result) group.count = Math.max(group.count, group.detailOccurrences)
  return result.sort((a, b) => severityOrder[a.level] - severityOrder[b.level])
}

export function stereoNxMessageGroupTitle(group: StereoNxMessageGroup, translate: (key: string) => string, hasTranslation: (key: string) => boolean): string {
  for (const key of [`stereo_nx.messages.causes.${group.code}`, `stereo_nx.review_reasons.${group.code}`]) {
    if (hasTranslation(key)) return translate(key)
  }
  const fallback = group.summaries.find(message => message.text.trim())?.text
    || group.details.find(message => message.text.trim())?.text
  if (!fallback) return translate('stereo_nx.messages.untitled')
  return fallback.replace(/\s+/gu, ' ').trim()
}

export function stereoNxMessageDocument(message: StereoNxProtocolMessage): string {
  return typeof message.context.document_no === 'string' ? message.context.document_no.trim() : ''
}

function fold(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}

/** Hledání neprochází interní identifikátory ani jen aktuálně vykreslenou stránku. */
export function filterStereoNxMessageGroup(group: StereoNxMessageGroup, query: string, title = ''): StereoNxMessageGroupMatch | null {
  const search = fold(query.trim())
  if (!search) return { group, details: group.details }
  const summaryMatches = group.summaries.some(message => fold(message.text).includes(search))
  if (summaryMatches || fold(title).includes(search)) return { group, details: group.details }
  const details = group.details.filter(message => fold(message.text).includes(search) || fold(stereoNxMessageDocument(message)).includes(search))
  return details.length ? { group, details } : null
}
