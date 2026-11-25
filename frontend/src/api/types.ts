export type Member = {
  sub: string
  displayName: string
  groups: string[]
  canOrganize: boolean
}

export type Answer = 'yes' | 'no'

export type EventCard = {
  id: string
  dayId: string
  title: string
  status: string
  statusLabel: string
  day: string
  month: string
  line1: string
  line2: string
  response: Answer | null
  responseLabel: string
}

export type DayResponse = {
  name: string
  answer: Answer
  answerLabel: string
  comment: string | null
}

export type EventDay = {
  id: string
  date: string
  startsAt: string | null
  endsAt: string | null
  location: string | null
  meetingPointName: string | null
  meetingPointDescription: string | null
  line1: string
  myResponse: Answer | null
  myComment: string | null
  summary: { yes: number; no: number }
  responses: DayResponse[]
}

export type ClubEvent = {
  id: string
  title: string
  clubs: Array<'historic' | 'supporter'>
  clubLabels: string[]
  categories: string[]
  status: 'draft' | 'published' | 'cancelled' | 'archived'
  statusLabel: string
  internalDescription: string | null
  publicDescription: string | null
  location: string | null
  publishToWebsite: boolean
  eyebrow: string
  card: EventCard | null
  days: EventDay[]
}

export type DashboardData = {
  counts: { openResponses: number; polls: number; shifts: number; tasks: number }
  upcoming: EventCard[]
  nextSevenDays: Array<{ date: string; weekday: string; day: string; hasEvent: boolean }>
  tasks: unknown[]
  shifts: unknown[]
  openPoll: null
}
