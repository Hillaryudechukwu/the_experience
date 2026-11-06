import React, { useState } from 'react';
import { Pressable, View } from 'react-native';

import type { DateNightOptions } from '../api/types';
import { DateRangeCalendar } from './DateRangeCalendar';
import { Icon } from './Icon';
import { Sheet } from './Sheet';
import { Button, Chip, Row, T } from './primitives';
import { space, useTheme } from '../theme';

type Day = 'tonight' | 'tomorrow' | 'pick';

const BUDGETS: { key: NonNullable<DateNightOptions['budget']>; label: string }[] = [
  { key: 'low', label: 'Keep it easy' },
  { key: 'relaxed', label: 'Treat ourselves' },
  { key: 'splurge', label: 'No limit' },
];

/** Half-hour steps across the hours an evening can sensibly start. */
const EARLIEST_START = 16 * 60;
const LATEST_START = 22 * 60;

/**
 * Date night: when, and roughly how.
 *
 * Three questions and a button. Everything else — which places, in what
 * order, whether they are open — is the planner's job, so this asks nothing
 * the traveller would have to research to answer.
 */
export function DateNightSheet({
  visible,
  onClose,
  today,
  maxDate: maxDateProp,
  onPlan,
}: {
  visible: boolean;
  onClose: () => void;
  /** Today where the traveller is, as YYYY-MM-DD. */
  today: string;
  /** The last day of the trip, if it has one. */
  maxDate?: string;
  onPlan: (options: DateNightOptions) => void;
}) {
  const colors = useTheme();
  const [day, setDay] = useState<Day>('tonight');
  const [picked, setPicked] = useState<string | null>(null);
  const [start, setStart] = useState(19 * 60);
  const [budget, setBudget] = useState<NonNullable<DateNightOptions['budget']>>('relaxed');
  const [nightcap, setNightcap] = useState(true);

  /* A trip that has already ended is no limit: without this every day,
     tomorrow included, was greyed out for someone back home. */
  const maxDate = maxDateProp && maxDateProp >= today ? maxDateProp : undefined;
  const tomorrow = addDays(today, 1);
  const tomorrowAvailable = !maxDate || tomorrow <= maxDate;
  const date = day === 'tonight' ? today : day === 'tomorrow' ? tomorrow : picked;

  return (
    <Sheet visible={visible} onClose={onClose} title="Date night">
      <View style={{ padding: space.lg, gap: space.lg }}>
        <View style={{ gap: space.xs }}>
          <T variant="label" color={colors.text.tertiary}>
            When
          </T>
          <Row gap={space.xs} wrap>
            <Chip label="Tonight" selected={day === 'tonight'} onPress={() => setDay('tonight')} />
            {tomorrowAvailable ? (
              <Chip label="Tomorrow" selected={day === 'tomorrow'} onPress={() => setDay('tomorrow')} />
            ) : null}
            <Chip
              label={day === 'pick' && picked ? shortDate(picked) : 'Pick a date'}
              selected={day === 'pick'}
              icon={<Icon name="calendar" size={14} color={day === 'pick' ? colors.text.inverse : colors.text.secondary} />}
              onPress={() => setDay('pick')}
            />
          </Row>
          {day === 'pick' ? (
            <DateRangeCalendar
              mode="single"
              startsOn={picked}
              endsOn={null}
              minDate={today}
              maxDate={maxDate}
              onChange={(iso) => setPicked(iso)}
            />
          ) : null}
        </View>

        <View style={{ gap: space.xs }}>
          <T variant="label" color={colors.text.tertiary}>
            Starting around
          </T>
          <Row justify="space-between" align="center">
            <Stepper
              label="Earlier"
              icon="minus"
              disabled={start <= EARLIEST_START}
              onPress={() => setStart((value) => value - 30)}
            />
            <T variant="h3">{timeLabel(start)}</T>
            <Stepper
              label="Later"
              icon="plus"
              disabled={start >= LATEST_START}
              onPress={() => setStart((value) => value + 30)}
            />
          </Row>
          {day === 'tonight' ? (
            <T variant="caption" color={colors.text.tertiary}>
              If that has already passed, we start from the next quarter hour.
            </T>
          ) : null}
        </View>

        <View style={{ gap: space.xs }}>
          <T variant="label" color={colors.text.tertiary}>
            Spend
          </T>
          <Row gap={space.xs} wrap>
            {BUDGETS.map((option) => (
              <Chip
                key={option.key}
                label={option.label}
                selected={budget === option.key}
                onPress={() => setBudget(option.key)}
              />
            ))}
          </Row>
        </View>

        <Row justify="space-between" align="center">
          <T variant="body">End with a drink nearby</T>
          <Chip label={nightcap ? 'Yes' : 'No'} selected={nightcap} onPress={() => setNightcap((value) => !value)} />
        </Row>

        <Button
          label="Plan our evening"
          disabled={!date}
          onPress={() => {
            if (!date) return;
            onPlan({ date, starts_at: timeLabel(start), budget, include_nightcap: nightcap });
          }}
        />
      </View>
    </Sheet>
  );
}

function Stepper({
  label,
  icon,
  disabled,
  onPress,
}: {
  label: string;
  icon: 'minus' | 'plus';
  disabled: boolean;
  onPress: () => void;
}) {
  const colors = useTheme();

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={onPress}
      hitSlop={10}
      style={{ padding: space.sm, opacity: disabled ? 0.4 : 1 }}
    >
      <Icon name={icon} size={20} color={colors.text.primary} />
    </Pressable>
  );
}

/** Minutes after midnight as "19:30" — the API's HH:mm. */
function timeLabel(minutes: number): string {
  return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

/** Calendar arithmetic on YYYY-MM-DD, never through the device's zone. */
export function addDays(iso: string, days: number): string {
  const [year, month, day] = iso.split('-').map(Number);
  const shifted = new Date(Date.UTC(year, month - 1, day + days));

  return shifted.toISOString().slice(0, 10);
}

function shortDate(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number);

  return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString([], {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
  });
}
