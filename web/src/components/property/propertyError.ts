export function propertyError(error: unknown, t: (key: string, values?: Record<string, unknown>) => string): string {
  const data = (error as { response?: { data?: { error?: { message?: string; details?: { field?: string } } } } }).response?.data?.error
  const message = data?.message ?? t('propertyRegistry.requestFailed')
  const field = data?.details?.field?.split('.')[0]
  const labels: Record<string, string> = { code: 'code', parent_id: 'chooseHouse', house_id: 'chooseHouse', expected_revision: 'history', effective_from: 'effectiveFrom', effective_to: 'effectiveTo', status: 'status', name: 'name', unit_no: 'unitNumber', kind: 'unitKind', attributes: 'attributes', ownerships: 'ownerships', roles: 'roles', identifiers: 'identifiers', services: 'kinds.service', reason: 'reason', title_ref: 'titleRef', address: 'address.street', share_scope: 'shareScope', declaration_ref: 'declaration', registry_complete: 'declaredComplete', measure: 'measure', measure_label: 'measureLabel' }
  return field && labels[field] ? `${message} ${t('propertyRegistry.fieldError', { field: t(`propertyRegistry.${labels[field]}`) })}` : message
}
