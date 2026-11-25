import { endSession, sessionToken } from '../auth/session'

export class ApiError extends Error {
  status: number
  fields?: Record<string, string>

  constructor(message: string, status: number, fields?: Record<string, string>) {
    super(message)
    this.status = status
    this.fields = fields
  }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token = sessionToken()
  const headers = new Headers(init.headers)
  if (init.body) {
    headers.set('Content-Type', 'application/json')
  }
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  let response: Response
  try {
    response = await fetch(path, { ...init, headers })
  } catch {
    throw new ApiError('Die Plattform ist gerade nicht erreichbar.', 0)
  }

  const body = await response.json().catch(() => ({})) as { error?: string; errors?: Record<string, string> }
  if (response.status === 401) {
    endSession()
    throw new ApiError(body.error ?? 'Anmeldung erforderlich.', 401)
  }
  if (!response.ok) {
    throw new ApiError(body.error ?? 'Die Anfrage ist fehlgeschlagen.', response.status, body.errors)
  }
  return body as T
}
