import WidgetContent from '@/components/dashboard/board/WidgetContent.vue'
import { widgetTypes, type WidgetType } from './types'
/** Sdílený typovaný renderer má explicitní větev pro každý typ registru. */
export const widgetComponents = Object.fromEntries(widgetTypes.map(type => [type, WidgetContent])) as Record<WidgetType, typeof WidgetContent>
