import { createI18n } from 'vue-i18n'
import { vi } from 'vitest'

export const ops = {
  list: vi.fn(), detail: vi.fn(), parties: vi.fn(), correctionOptions: vi.fn(), card: vi.fn(), bankAccounts: vi.fn(),
  registerDebt: vi.fn(), reserveCredit: vi.fn(), cancelReservation: vi.fn(), cancelSubmission: vi.fn(), aboPreview: vi.fn(), aboConfirm: vi.fn(),
  accessoryCandidates: vi.fn(), bindAccessory: vi.fn(), aboDownload: vi.fn(),
  setoffPreview: vi.fn(), setoffConfirm: vi.fn(), correctionPreview: vi.fn(), correctionConfirm: vi.fn(), reverseOperation: vi.fn(), reverseCorrection: vi.fn(),
}

export const i18nPlugin = () => createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })
const field = (tag: string) => ({ props: ['modelValue'], emits: ['update:modelValue'], template: `<input ${tag} :value="modelValue" @input="$emit('update:modelValue', $event.target.value)" />` })
export const stubs = {
  ActionBar: { props: ['actions'], template: '<div><button v-for="a in actions" :key="a.key" type="button" :data-action="a.key" :disabled="a.disabled" @click="a.run">{{ a.label }}</button></div>' },
  DateInput: field('data-date'),
  PropertyInstantInput: field('data-instant'),
  PropertyPartyPicker: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input data-party :value="modelValue" @input="$emit(\'update:modelValue\', Number($event.target.value))" />' },
}

export const page = <T>(items: T[], next: number | null = null) => ({ items, next_cursor: next, coverage: { scope: 'recorded_s3_s4', complete: false } })
export const token = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/
