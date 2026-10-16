import { Platform, Share } from 'react-native';

/** Production web origin used for experience deep links (see docs/RELEASE.md). */
export const EXPERIENCE_WEB_ORIGIN =
  process.env.EXPO_PUBLIC_WEB_ORIGIN?.replace(/\/$/, '') ?? 'https://experience.synteric.co.uk';

export function experienceShareUrl(id: string): string {
  return `${EXPERIENCE_WEB_ORIGIN}/experience/${id}`;
}

export async function shareExperience(id: string, title: string): Promise<void> {
  const url = experienceShareUrl(id);

  if (Platform.OS === 'ios') {
    await Share.share({ url, message: title });
    return;
  }

  await Share.share({ message: `${title}\n${url}`, title });
}

export type SharedPlanItem = {
  time: string;
  title: string;
  experienceId?: string | null;
};

export type SharedPlanDay = {
  label: string;
  items: SharedPlanItem[];
};

/**
 * A plan has many links, so both platforms put them in the message.
 * The single `url` field is for one experience only — do not "fix" this
 * back to that shape.
 */
export function formatItineraryShare(city: string, days: SharedPlanDay[]): string {
  const lines = [`${city} plan`];

  for (const day of days) {
    lines.push(day.label);
    for (const item of day.items) {
      lines.push(`${item.time} ${item.title}`);
      if (item.experienceId) {
        lines.push(experienceShareUrl(item.experienceId));
      }
    }
  }

  return lines.join('\n');
}

export async function shareItinerary(city: string, days: SharedPlanDay[]): Promise<void> {
  await Share.share({ message: formatItineraryShare(city, days) });
}
