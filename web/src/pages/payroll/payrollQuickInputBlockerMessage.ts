export function payrollQuickInputBlockerMessage(
  t: (key: string) => string,
  te: (key: string) => boolean,
  code: string,
  fallback?: string,
): string {
  const own = `payroll.quick_inputs.blockers.${code}`
  if (te(own)) return t(own)
  const hourly = `payroll.hourly_preview.blockers.${code}`
  if (te(hourly)) return t(hourly)
  return fallback || t('payroll.quick_inputs.blocker_unknown')
}
