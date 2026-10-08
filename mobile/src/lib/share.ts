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
