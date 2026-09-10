import type { AxiosError } from 'axios'

type ValidationResponse = {
  message?: string
  errors?: Record<string, string[]>
}

export function apiErrorMessage(error: unknown, fallback = '操作失败，请稍后重试') {
  const response = (error as AxiosError<ValidationResponse>)?.response
  const data = response?.data
  const firstValidationError = data?.errors && Object.values(data.errors)[0]?.[0]

  if (response?.status === 403) return '当前账号没有执行此操作的权限'
  if (firstValidationError) return firstValidationError
  if (data?.message) return data.message
  if (response?.status && response.status >= 500) return '服务器处理失败，请查看服务日志'
  return fallback
}

export const bytesToGb = (bytes: number | string | null | undefined) =>
  (Math.max(0, Number(bytes) || 0) / 1073741824).toFixed(2)

export const gbToBytes = (gb: number) => Math.round(gb * 1073741824)

export function formatDate(value?: string | null) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('zh-CN', { hour12: false })
}
