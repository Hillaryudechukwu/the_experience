import React, { useMemo, useState } from 'react';
import { Pressable, View } from 'react-native';

import { Icon } from './Icon';
import { Row, T } from './primitives';
import { radius, space, TOUCH_TARGET, useTheme } from '../theme';

/**
 * Trip dates (Figma: 14.6).
 *
 * Written by hand rather than pulled from a library. A native picker would be
 * a native dependency — a fresh store build rather than an update — it has no
 * concept of a range, and it degrades in the web export. The alternative
 * already in the codebase is the unvalidated free-text box in anchors.tsx,
 * which is not a pattern worth spreading to the screen every trip passes
 * through.
 *
 * Everything here is a plain YYYY-MM-DD calendar string. A trip that starts on
 * the 9th starts on the 9th in the city it is in, so these must never be
 * round-tripped through a Date in the device's zone — the same reasoning as
 * calendarDate() in lib/format.ts.
 */

const WEEKDAYS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

/** Calendar parts, never a timestamp. */
function parts(iso: string): { year: number; month: number; day: number } {
  const [year, month, day] = iso.split('-').map(Number);

  return { year, month, day };
}

function toIso(year: number, month: number, day: number): string {
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function daysInMonth(year: number, month: number): number {
  return new Date(year, month, 0).getDate();
}

/** Monday-first index of the 1st, so the grid lines up under the headings. */
function leadingBlanks(year: number, month: number): number {
  return (new Date(year, month - 1, 1).getDay() + 6) % 7;
}

/** Whole days between two calendar dates, sign included. Midday avoids DST. */
export function daysBetween(fromIso: string, toIso_: string): number {
  const a = parts(fromIso);
  const b = parts(toIso_);
  const from = Date.UTC(a.year, a.month - 1, a.day);
  const to = Date.UTC(b.year, b.month - 1, b.day);

  return Math.round((to - from) / 86_400_000);
}

function addMonths(year: number, month: number, delta: number): { year: number; month: number } {
  const index = year * 12 + (month - 1) + delta;

  return { year: Math.floor(index / 12), month: (index % 12) + 1 };
}

export function DateRangeCalendar({
  startsOn,
  endsOn,
  onChange,
  minDate,
}: {
  startsOn: string | null;
  endsOn: string | null;
  onChange: (startsOn: string | null, endsOn: string | null) => void;
  /** Today where the traveller is going, so their own today is never "past". */
  minDate: string;
}) {
  const colors = useTheme();

  /* Open on the trip, not on today — editing a trip in March should not start
     in October. */
  const [cursor, setCursor] = useState(() => {
    const anchor = startsOn ?? minDate;

    return { year: parts(anchor).year, month: parts(anchor).month };
  });

  /* One row of seven equal columns, same as the weekday headings. A single
     wrapping row of `100/7 %` cells does not survive iPhone layout rounding:
     the seventh cell wraps, and every date after it sits under the wrong day. */
  const weeks = useMemo(() => {
    const total = daysInMonth(cursor.year, cursor.month);
    const blanks = leadingBlanks(cursor.year, cursor.month);
    const cells: (string | null)[] = Array.from({ length: blanks }, () => null);

    for (let day = 1; day <= total; day++) cells.push(toIso(cursor.year, cursor.month, day));
    while (cells.length % 7 !== 0) cells.push(null);

    const rows: (string | null)[][] = [];
    for (let index = 0; index < cells.length; index += 7) rows.push(cells.slice(index, index + 7));

    return rows;
  }, [cursor]);

  const select = (iso: string) => {
    /* First tap sets the start. Second extends it. A third starts again, which
       is how every booking calendar behaves and saves an explicit "clear". */
    if (!startsOn || (startsOn && endsOn)) {
      onChange(iso, null);
      return;
    }

    if (iso < startsOn) {
      onChange(iso, null);
      return;
    }

    onChange(startsOn, iso);
  };

  return (
    <View style={{ gap: space.sm }}>
      <Row justify="space-between" align="center">
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Previous month"
          onPress={() => setCursor(addMonths(cursor.year, cursor.month, -1))}
          hitSlop={10}
          style={{ padding: space.xs }}
        >
          <View style={{ transform: [{ rotate: '180deg' }] }}>
            <Icon name="chevron" size={18} color={colors.text.secondary} />
          </View>
        </Pressable>
        <T variant="bodyStrong">
          {MONTHS[cursor.month - 1]} {cursor.year}
        </T>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Next month"
          onPress={() => setCursor(addMonths(cursor.year, cursor.month, 1))}
          hitSlop={10}
          style={{ padding: space.xs }}
        >
          <Icon name="chevron" size={18} color={colors.text.secondary} />
        </Pressable>
      </Row>

      <Row gap={0}>
        {WEEKDAYS.map((label, index) => (
          <View key={`${label}-${index}`} style={{ flex: 1, alignItems: 'center' }}>
            <T variant="caption" color={colors.text.tertiary}>
              {label}
            </T>
          </View>
        ))}
      </Row>

      <View>
        {weeks.map((week, weekIndex) => (
          <View key={weekIndex} style={{ flexDirection: 'row' }}>
            {week.map((iso, index) => {
              if (iso === null) {
                return <View key={`blank-${weekIndex}-${index}`} style={{ flex: 1, height: TOUCH_TARGET }} />;
              }

              const past = iso < minDate;
              const isStart = iso === startsOn;
              const isEnd = iso === endsOn;
              const within = !!startsOn && !!endsOn && iso > startsOn && iso < endsOn;
              const edge = isStart || isEnd;

              return (
                <View
                  key={iso}
                  style={{
                    flex: 1,
                    height: TOUCH_TARGET,
                    alignItems: 'center',
                    justifyContent: 'center',
                    backgroundColor: within ? colors.background.sunken : 'transparent',
                  }}
                >
                  <Pressable
                    accessibilityRole="button"
                    accessibilityState={{ selected: edge || within, disabled: past }}
                    accessibilityLabel={`${parts(iso).day} ${MONTHS[parts(iso).month - 1]}`}
                    disabled={past}
                    onPress={() => select(iso)}
                    style={{
                      width: 36,
                      height: 36,
                      borderRadius: radius.pill,
                      alignItems: 'center',
                      justifyContent: 'center',
                      backgroundColor: edge ? colors.action.primary : 'transparent',
                    }}
                  >
                    <T
                      variant={edge ? 'smallStrong' : 'small'}
                      color={
                        past
                          ? colors.text.tertiary
                          : edge
                            ? colors.text.onAccent
                            : colors.text.primary
                      }
                    >
                      {parts(iso).day}
                    </T>
                  </Pressable>
                </View>
              );
            })}
          </View>
        ))}
      </View>

      {startsOn && !endsOn ? (
        <T variant="caption" color={colors.text.tertiary}>
          Now pick the day you leave.
        </T>
      ) : null}
    </View>
  );
}
