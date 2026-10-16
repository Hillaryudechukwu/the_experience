/**
 * Presentation name for WAYOA.
 *
 * Screens and metadata read this instead of repeating the wordmark. Store
 * identifiers, URL schemes and API hosts stay where they are.
 */
export const BRAND = {
  name: 'WAYOA',
  tagline: 'Know where to go.',
  description: 'WAYOA helps travellers discover, plan and book experiences that fit their journey.',
} as const;

/** Document title. The tagline's closing stop is omitted so the title does not end with two marks. */
export const brandDocumentTitle = `${BRAND.name} — ${BRAND.tagline.replace(/\.$/, '')}`;
