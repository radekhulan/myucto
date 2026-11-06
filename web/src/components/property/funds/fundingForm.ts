import { APP_TIME_ZONE } from '@/utils/date'
export function fundingCents(input: string): string | null {
 const text = input.replace(/\s/g, '').replace(',', '.')
 if (!/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/.test(text)) return null
 const [whole = '0', fraction = ''] = text.split('.')
 const amount = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'))
 return amount > 0n && amount.toString().length <= 15 ? amount.toString() : null
}
export function fundingDayEnd(date: string): string {
 if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return ''
 const parts = new Intl.DateTimeFormat('en', { timeZone: APP_TIME_ZONE, timeZoneName: 'longOffset' }).formatToParts(new Date(`${date}T12:00:00Z`))
 const offset = parts.find(part => part.type === 'timeZoneName')?.value.replace('GMT', '') || 'Z'
 return `${date}T23:59:59${offset}`
}
