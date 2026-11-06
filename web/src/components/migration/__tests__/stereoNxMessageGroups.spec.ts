import { describe, expect, it } from 'vitest'
import { filterStereoNxMessageGroup, groupStereoNxMessages, stereoNxMessageGroupTitle, type StereoNxProtocolMessage } from '../stereoNxMessageGroups'

function message(code: string, text: string, level: StereoNxProtocolMessage['level'] = 'warning', context: Record<string, unknown> = {}): StereoNxProtocolMessage {
  return { code, text, level, context, step: 'source_summary' }
}

describe('Stereo NX message groups', () => {
  it('groups by severity and code, orders severities and retains stable order and every duplicate detail', () => {
    const duplicate = message('same', 'A repeated detail')
    const groups = groupStereoNxMessages([
      message('info-first', 'Information', 'info'), duplicate, message('warning-next', 'Next warning'),
      message('same', 'Error with the same code', 'error'), duplicate,
      { ...duplicate, step: 'review' }, message('error-next', 'Next error', 'error'),
    ])
    expect(groups.map(group => `${group.level}:${group.code}`)).toEqual(['error:same', 'error:error-next', 'warning:same', 'warning:warning-next', 'info:info-first'])
    expect(groups[2]?.details).toEqual([duplicate, duplicate, { ...duplicate, step: 'review' }])
    expect(groups[2]?.count).toBe(3)
  })

  it('uses the largest summary count or the number of details without counting summaries as details', () => {
    const groups = groupStereoNxMessages([
      message('cause', 'Summary five', 'warning', { summary: true, count: 5 }),
      message('cause', 'Summary seven', 'warning', { summary: true, count: 7 }),
      message('cause', 'First detail'), message('cause', 'Second detail'),
      message('other', 'Summary one', 'info', { summary: true, count: 1 }),
      message('other', 'Detail one', 'info'), message('other', 'Detail two', 'info'),
    ])
    expect(groups.map(group => ({ count: group.count, details: group.details.length, summaries: group.summaries.length }))).toEqual([
      { count: 7, details: 2, summaries: 2 }, { count: 2, details: 2, summaries: 1 },
    ])
  })

  it('sums valid occurrence weights of aggregated details and falls back to one for invalid counts', () => {
    const [group] = groupStereoNxMessages([
      message('cause', 'Seven occurrences', 'warning', { count: 7 }),
      message('cause', 'One occurrence'),
    ])
    expect(group?.count).toBe(8)
    expect(group?.detailOccurrences).toBe(8)
    expect(group?.details).toHaveLength(2)
    const [invalid] = groupStereoNxMessages([-1, Number.NaN, Number.POSITIVE_INFINITY, 0, 1.5, '7'].map(count => message('invalid', 'Single occurrence', 'warning', { count })))
    expect(invalid?.count).toBe(6)
    expect(invalid?.detailOccurrences).toBe(6)
  })

  it('resolves a known cause, then a review reason, then summary or detail text without exposing the raw code', () => {
    const [group] = groupStereoNxMessages([message('cause', 'Detail fallback'), message('cause', 'Summary fallback', 'warning', { summary: true, count: 2 })])
    const t = (key: string) => `Translated ${key}`
    expect(stereoNxMessageGroupTitle(group!, t, () => true)).toBe('Translated stereo_nx.messages.causes.cause')
    expect(stereoNxMessageGroupTitle(group!, t, key => key.startsWith('stereo_nx.review_reasons.'))).toBe('Translated stereo_nx.review_reasons.cause')
    expect(stereoNxMessageGroupTitle(group!, t, () => false)).toBe('Summary fallback')
    const [detailOnly] = groupStereoNxMessages([message('unknown_internal_code', 'Readable unknown cause')])
    expect(stereoNxMessageGroupTitle(detailOnly!, t, () => false)).toBe('Readable unknown cause')
    const [empty] = groupStereoNxMessages([message('unknown_internal_code', '')])
    expect(stereoNxMessageGroupTitle(empty!, t, () => false)).toBe('Translated stereo_nx.messages.untitled')
  })

  it('normalizes fallback whitespace while preserving the full Unicode title for the tooltip', () => {
    const text = `  A long\n\n  fallback ${'😀'.repeat(170)}`
    const source = message('unknown', text)
    const [group] = groupStereoNxMessages([source])
    const title = stereoNxMessageGroupTitle(group!, key => key, () => false)
    expect(title).toBe(`A long fallback ${'😀'.repeat(170)}`)
    expect(group?.details[0]?.text).toBe(text)
    const translated = 'Known translated cause '.repeat(20)
    expect(stereoNxMessageGroupTitle(group!, () => translated, () => true)).toBe(translated)
    const [summary] = groupStereoNxMessages([message('unknown', text, 'warning', { summary: true, count: 3 })])
    expect(stereoNxMessageGroupTitle(summary!, key => key, () => false)).toBe(title)
  })

  it('searches all document numbers and texts without case or accents and ignores internal source keys', () => {
    const messages = Array.from({ length: 120 }, (_, index) => message('cause', `Synthetic detail ${index}`, 'warning', { document_no: `SYN-${index}`, source_key: 'private-json-reference' }))
    messages[119] = message('cause', 'České zaokrouhlení', 'warning', { document_no: 'SYN-LAST', source_key: 'private-json-reference' })
    const [group] = groupStereoNxMessages(messages)
    expect(filterStereoNxMessageGroup(group!, 'syn-last')?.details).toEqual([messages[119]])
    expect(filterStereoNxMessageGroup(group!, 'CESKE ZAOKROUHLENI')?.details).toEqual([messages[119]])
    expect(filterStereoNxMessageGroup(group!, 'private-json-reference')).toBeNull()
    expect(filterStereoNxMessageGroup(group!, '')?.details).toHaveLength(120)
  })

  it('keeps searchable summary-only groups and safely ignores invalid summary counts', () => {
    const [group] = groupStereoNxMessages([
      message('summary-only', 'Readable summary', 'error', { summary: true, count: 8 }),
      message('summary-only', 'Invalid count', 'error', { summary: true, count: Number.POSITIVE_INFINITY }),
    ])
    expect(group?.count).toBe(8)
    expect(group?.details).toHaveLength(0)
    expect(filterStereoNxMessageGroup(group!, 'readable')?.details).toEqual([])
  })

  it('returns all details when the query matches a summary or the translated cause title', () => {
    const [group] = groupStereoNxMessages([
      message('cause', 'A general summary', 'warning', { summary: true, count: 2 }),
      message('cause', 'First specific entry'), message('cause', 'Second specific entry'),
    ])
    expect(filterStereoNxMessageGroup(group!, 'GENERAL SUMMARY')?.details).toHaveLength(2)
    expect(filterStereoNxMessageGroup(group!, 'ZAOKROUHLENI', 'Účet zaokrouhlení vyžaduje kontrolu')?.details).toHaveLength(2)
    expect(filterStereoNxMessageGroup(group!, 'First specific')?.details).toHaveLength(1)
  })
})
