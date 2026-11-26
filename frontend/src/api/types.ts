export type Member = {
  sub: string
  displayName: string
  groups: string[]
  clubs: Array<'historic' | 'supporter'>
  canOrganize: boolean
  canPlanRides: boolean
  canEditFleet: boolean
  picture?: string
}

export type DayOffset = -1 | 0 | 1

export type StationChoice = {
  didokCode: string
  name: string
  abbreviation?: string | null
}

export type StationHit = StationChoice & {
  id: string | null
  abbreviation: string | null
}

export type RideVehicleKind = 'own' | 'external'

export type RideVehicle = {
  id: string
  kind: RideVehicleKind
  vehicleId: string | null
  name: string | null
  number: string | null
  label: string
  external: boolean
}

export type OwnVehicleOption = {
  id: string
  name: string
  number: string | null
  label: string
  status: VehicleStatus
  statusLabel: string
}

export type Ride = {
  id: string
  eventDayId: string
  departureTime: string | null
  departureOffset: DayOffset | null
  arrivalTime: string | null
  arrivalOffset: DayOffset | null
  trainNumber: string
  origin: StationChoice
  destination: StationChoice
  vehicles: RideVehicle[]
  internalNote: string | null
}

export type EventVehicle = {
  vehicleId: string
  name: string
  number: string | null
  label: string
  external: boolean
  status: VehicleStatus
  statusLabel: string
  rentalStatus: string | null
}

export type FleetOption = {
  id: string
  name: string
  number: string | null
  label: string
  external: boolean
  status: VehicleStatus
  statusLabel: string
}

export type RidePlan = {
  canEdit: boolean
  warnings: string[]
  rides: Ride[]
  eventVehicles: EventVehicle[]
  fleet: FleetOption[]
}

export type GastroDish = {
  id: string
  name: string
  portions: number
}

export type GastroResponsible = {
  sub: string
  name: string
}

export type GastroPlan = {
  id: string
  eventId?: string
  guestCount: number | null
  notes: string | null
  dishes: GastroDish[]
  responsible: GastroResponsible[]
}

export type GastroOverviewRow = {
  eventId: string
  title: string
  status: string
  statusLabel: string
  hasPlan: boolean
  planId: string | null
  guestCount: number | null
  dishes: string[]
}

export type AuditEntry = {
  id: string
  occurredAt: string
  actor: string
  action: string
  actionLabel: string
  summary: string
}

export type GastroView = {
  canEdit: boolean
  plan: GastroPlan | null
}

export type VehicleStatus = 'ready' | 'limited' | 'blocked'

export type BatteryCharge = {
  id: string
  chargedOn: string
  chargedAt: string
  recordedBy: string
}

export type MeterReading = {
  id: string
  kind: 'motor_hours' | 'kilometres'
  kindLabel: string
  value: string
  readOn: string
  recordedBy: string
}

export type FleetVehicle = {
  id: string
  name: string
  number: string | null
  label: string
  status: VehicleStatus
  statusLabel: string
  external: boolean
  technicalNote?: string | null
  tracksMotorHours?: boolean
  owner?: string | null
  nvr?: string | null
  p1On?: string | null
  p5On?: string | null
  oilOn?: string | null
  nextMaintenanceOn?: string | null
  batteryCharges?: BatteryCharge[]
  meterReadings?: MeterReading[]
}

export type FleetList = {
  canEdit: boolean
  canViewTechnical: boolean
  canRecordBattery: boolean
  vehicles: FleetVehicle[]
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

export type MeetingPoint = {
  id: string
  placeId: string
  placeName: string
  meetsAt: string | null
  description: string | null
}

export type EventDay = {
  id: string
  date: string
  startsAt: string | null
  endsAt: string | null
  location: string | null
  meetingPoints: MeetingPoint[]
  line1: string
  myResponse: Answer | null
  myComment: string | null
  summary: { yes: number; no: number; pending: number }
  pending: Array<{ name: string }>
  responses: DayResponse[]
}

export type InvitationPerson = {
  sub: string
  name: string
}

export type EventInvitations = {
  groups: string[]
  people: InvitationPerson[]
  excluded: InvitationPerson[]
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
  invitations: EventInvitations
  vehicles: EventVehicle[]
  eyebrow: string
  card: EventCard | null
  days: EventDay[]
}

export type TaskProgress = { done: number | null; total: number | null; percent: number | null }

export type TaskItem = {
  id: string
  status: string | null
  statusLabel: string | null
  addressable: boolean
}

export type TaskListSummary = {
  id: string
  name: string | null
  progress: TaskProgress | null
  addressable: boolean
}

export type TaskListDetail = {
  id: string | null
  name: string | null
  description: string | null
  categoryId: string | null
  orgGroupId: string | null
  isPersonal: boolean | null
  templateId: string | null
  progress: TaskProgress | null
  addressable: boolean
  tasks: TaskItem[]
}

export type TaskTemplate = {
  id: string
  taskCount: number | null
  addressable: boolean
}

export type TasksRead<T> = {
  configured: boolean
  missing: string[]
  notice: string | null
} & T

export type DashboardData = {
  counts: { openResponses: number; polls: number; shifts: number; tasks: number | null }
  upcoming: EventCard[]
  nextSevenDays: Array<{ date: string; weekday: string; day: string; hasEvent: boolean }>
  tasks: TaskItem[]
  tasksNotice: string | null
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
  noNames: string[]
  yesAnswers: Array<{ name: string; comment: string | null }>
  noAnswers: Array<{ name: string; comment: string | null }>
  adoptedEventId: string | null
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
