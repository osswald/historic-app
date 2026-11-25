import { afterEach, describe, expect, it, vi } from 'vitest'
import { endSession, sessionToken, startDevSession } from '../auth/session'
import { api, ApiError } from './client'

afterEach(() => {
  endSession()
  vi.unstubAllGlobals()
  sessionStorage.clear()
})

describe('api', () => {
  it('sends the session token and returns the JSON body', async () => {
    startDevSession()
    const fetchMock = vi.fn<typeof fetch>(async () => Response.json({ status: 'ok' }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(api('/api/v1/health')).resolves.toEqual({ status: 'ok' })

    const headers = new Headers(fetchMock.mock.calls[0]?.[1]?.headers)
    expect(headers.get('Authorization')).toBe('Bearer dev')
    expect(headers.get('Content-Type')).toBeNull()
  })

  it('sends JSON when the request has a body', async () => {
    const fetchMock = vi.fn<typeof fetch>(async () => Response.json({ id: '1' }, { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)

    await api('/api/v1/events', { method: 'POST', body: '{"title":"Fahrt"}' })

    const headers = new Headers(fetchMock.mock.calls[0]?.[1]?.headers)
    expect(headers.get('Content-Type')).toBe('application/json')
    expect(headers.has('Authorization')).toBe(false)
  })

  it('reports that the platform cannot be reached', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>(async () => {
      throw new TypeError('offline')
    }))

    await expect(api('/api/v1/health')).rejects.toMatchObject({
      message: 'Die Plattform ist gerade nicht erreichbar.',
      status: 0,
    })
  })

  it('ends the session when the API requires a login', async () => {
    startDevSession()
    vi.stubGlobal('fetch', vi.fn<typeof fetch>(async () => Response.json({ error: 'Anmeldung erforderlich.' }, { status: 401 })))

    const error = await api('/api/v1/me').then(() => null, (reason: unknown) => reason)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 401, message: 'Anmeldung erforderlich.' })
    expect(sessionToken()).toBeNull()
  })

  it('keeps field errors from a rejected request', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>(async () => Response.json({
      error: 'Die Eingaben sind unvollständig.',
      errors: { title: 'Bitte einen Titel mit höchstens 200 Zeichen angeben.' },
    }, { status: 422 })))

    const error = await api('/api/v1/events', { method: 'POST', body: '{}' }).then(() => null, (reason: unknown) => reason)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({
      status: 422,
      fields: { title: 'Bitte einen Titel mit höchstens 200 Zeichen angeben.' },
    })
  })

  it('uses a fallback message when the error body is not JSON', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>(async () => new Response('nope', { status: 500 })))

    await expect(api('/api/v1/health')).rejects.toMatchObject({
      message: 'Die Anfrage ist fehlgeschlagen.',
      status: 500,
    })
  })
})
