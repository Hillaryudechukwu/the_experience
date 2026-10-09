export function timeOfDayGreeting(date = new Date()): string {
  const hour = date.getHours();
  if (hour < 12) return 'Good morning';
  if (hour < 18) return 'Good afternoon';

  return 'Good evening';
}

export function clock(iso: string, timeZone?: string): string {
  return new Date(iso).toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    ...(timeZone ? { timeZone } : {}),
  });
}

export function weekdayAndTime(iso: string, timeZone?: string): string {
  const date = new Date(iso);
  const options: Intl.DateTimeFormatOptions = timeZone ? { timeZone } : {};

  return `${date.toLocaleDateString([], { weekday: 'long', ...options })}, ${clock(iso, timeZone)}`;
}

export function dayLabel(iso: string, timeZone?: string): string {
  const options: Intl.DateTimeFormatOptions = timeZone ? { timeZone } : {};
  const key = (value: Date) => value.toLocaleDateString('en-CA', options);

  const date = new Date(iso);
  const today = new Date();
  const tomorrow = new Date(today.getTime() + 86_400_000);

  if (key(date) === key(today)) return 'Today';
  if (key(date) === key(tomorrow)) return 'Tomorrow';

  return date.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'short', ...options });
}

export function conditionLabel(condition: string): string {
  return condition.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

/** "3h 40m" — the shape the hero card needs. */
export function windowLabel(minutes: number): string {
  if (minutes < 60) return `${minutes}m`;
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;

  return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

export function minutesLabel(minutes: number): string {
  if (minutes < 60) return `${minutes} minutes`;
  const hours = minutes / 60;

  return `${Number.isInteger(hours) ? hours : hours.toFixed(1)} hours`;
}

export function titleCase(value: string): string {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Local calendar date as YYYY-MM-DD (API journey date fields). */
export function isoDate(date = new Date()): string {
  return date.toLocaleDateString('en-CA');
}

/** Add whole calendar days to a YYYY-MM-DD string without UTC drift. */
export function addCalendarDays(iso: string, days: number): string {
  const [year, month, day] = iso.split('-').map(Number);
  const date = new Date(year, month - 1, day);
  date.setDate(date.getDate() + days);

  return isoDate(date);
}

/** Inclusive stay bounds for onboarding presets (1 = today only). */
export function stayBounds(stayDays: number, from = new Date()): { starts_on: string; ends_on: string } {
  const starts_on = isoDate(from);
  const ends_on = addCalendarDays(starts_on, Math.max(1, stayDays) - 1);

  return { starts_on, ends_on };
}

/** Parse a YYYY-MM-DD journey date as calendar components (no timezone shift). */
function calendarDate(iso: string): Date {
  const [year, month, day] = iso.split('-').map(Number);

  return new Date(year, month - 1, day);
}

/** Inclusive day count from journey dates, or null when dates are missing. */
export function stayDayCount(startsOn: string | null | undefined, endsOn: string | null | undefined): number | null {
  if (!startsOn || !endsOn) return null;

  const start = calendarDate(startsOn);
  const end = calendarDate(endsOn);
  const days = Math.round((end.getTime() - start.getTime()) / 86_400_000) + 1;

  return days > 0 ? days : null;
}

/**
 * Compact stay range, e.g. "9–11 Oct" or "9 Oct – 2 Nov".
 * Journey dates are calendar days, so format the Y-M-D as written — never
 * reinterpret through a destination timezone (that can shift the day).
 */
export function stayRangeLabel(startsOn: string, endsOn: string): string {
  const options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short' };
  const start = calendarDate(startsOn);
  const end = calendarDate(endsOn);

  if (startsOn === endsOn) {
    return start.toLocaleDateString([], options);
  }

  const sameMonth =
    start.toLocaleDateString([], { month: 'short' }) === end.toLocaleDateString([], { month: 'short' }) &&
    start.getFullYear() === end.getFullYear();

  if (sameMonth) {
    return `${start.toLocaleDateString([], { day: 'numeric' })}–${end.toLocaleDateString([], options)}`;
  }

  return `${start.toLocaleDateString([], options)} – ${end.toLocaleDateString([], options)}`;
}

/** Trip empty-state CTA: single day vs multi-day stay. */
export function buildPlanCtaLabel(stayDays: number | null | undefined, pending = false): string {
  if (pending) return 'Building…';

  return stayDays !== null && stayDays !== undefined && stayDays > 1 ? 'Build my itinerary' : 'Build my day';
}
