export type Member = {
  sub: string
  displayName: string
  groups: string[]
  canOrganize: boolean
  canPlanRides: boolean
}

export type DayOffset = -1 | 0 | 1

export type Ride = {
  id: string
  eventDayId: string
  departureTime: string
  departureOffset: DayOffset
  arrivalTime: string
  arrivalOffset: DayOffset
  vehicleLabel: string
  clubVehicle: boolean
  internalNote: string | null
}

export type RidePlan = {
  canEdit: boolean
  warnings: string[]
  rides: Ride[]
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
  openPoll: { id: string; title: string; prompt: string; state: PollState } | null
}

export type PollState = 'unanswered' | 'supplement' | 'complete'
export type Daypart = 'morning' | 'afternoon' | 'evening'

export type PollOption = {
  id: string
  date: string
  dateLabel: string
  daypart: Daypart | null
  daypartLabel: string | null
  startsAt: string | null
  endsAt: string | null
  answer: Answer | null
  needsSupplement: boolean
  yesCount: number
  noCount: number
  yesNames: string[]
}

export type PollDay = {
  date: string
  label: string
  options: PollOption[]
}

export type PollDetail = {
  id: string
  title: string
  kind: 'normal' | 'depot'
  kindLabel: string
  status: 'open' | 'closed'
  statusLabel: string
  description: string | null
  location: string | null
  clubs: Array<'historic' | 'supporter'>
  clubLabels: string[]
  deadline: string | null
  deadlinePassed: boolean
  acceptsAnswers: boolean
  eventId: string | null
  myState: PollState
  myStateLabel: string
  comment: string | null
  options: PollOption[]
  days: PollDay[]
}

export type PollCard = {
  id: string
  title: string
  kind: 'normal' | 'depot'
  kindLabel: string
  status: 'open' | 'closed'
  statusLabel: string
  deadline: string | null
  optionCount: number
  myState: PollState
  myStateLabel: string
  eventId: string | null
  acceptsAnswers: boolean
}
