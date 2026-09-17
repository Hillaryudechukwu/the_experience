import React, { useState } from 'react';
import { TextInput, View } from 'react-native';

import { useDiscovery, useSaved, useSearch } from '../../src/api/hooks';
import { ExperienceCardView } from '../../src/components/ExperienceCard';
import { Chip, EmptyState, Loading, Note, Row, Screen, SectionHeader, T } from '../../src/components/primitives';
import { radius, space, useTheme } from '../../src/theme';

const FILTERS = [
  { key: 'must_experience', label: 'Must see' },
  { key: 'hidden_gem', label: 'Hidden gems' },
  { key: 'free', label: 'Free' },
  { key: 'food_experience', label: 'Food' },
  { key: 'culture', label: 'Culture' },
  { key: 'family', label: 'Kids' },
  { key: 'nightlife', label: 'Nightlife' },
  { key: 'nature', label: 'Nature' },
  { key: 'romantic', label: 'Romantic' },
  { key: 'quick_experience', label: 'Quick' },
];

const EXAMPLES = [
  'Something romantic tonight',
  'Free things with children',
  'Good rainy day activities',
  'Where can I watch the sunset',
  'Things locals do rather than tourists',
  'I have 3 hours before my flight at 7pm',
];

/** Spec s5.6 — universal natural-language search, plus the classic filters. */
export default function Explore() {
  const colors = useTheme();
  const [query, setQuery] = useState('');
  const [submitted, setSubmitted] = useState('');
  const [filters, setFilters] = useState<string[]>([]);

  const search = useSearch(submitted, submitted.length > 2);
  const browse = useDiscovery('now', { categories: filters, limit: 20 }, submitted.length === 0);
  const saved = useSaved();

  const searching = submitted.length > 2;
  const results = searching ? (search.data?.data ?? []) : (browse.data?.data ?? []);
  const setId = searching ? search.data?.recommendation_set_id : browse.data?.recommendation_set_id;
  const notice = searching ? search.data?.notice : browse.data?.notice;
  const loading = searching ? search.isLoading : browse.isLoading;

  const toggle = (key: string) =>
    setFilters((current) => (current.includes(key) ? current.filter((f) => f !== key) : [...current, key]));

  return (
    <Screen>
      <T variant="display">Explore</T>

      <TextInput
        placeholder="Ask in your own words"
        placeholderTextColor={colors.inkFaint}
        value={query}
        onChangeText={setQuery}
        onSubmitEditing={() => setSubmitted(query.trim())}
        returnKeyType="search"
        style={{
          marginTop: space.lg,
          backgroundColor: colors.surface,
          borderColor: colors.line,
          borderWidth: 1,
          borderRadius: radius.md,
          paddingHorizontal: space.lg,
          paddingVertical: 14,
          color: colors.ink,
          fontSize: 16,
        }}
      />

      {!searching && (
        <Row gap={space.sm} wrap style={{ marginTop: space.md }}>
          {EXAMPLES.map((example) => (
            <Chip
              key={example}
              label={example}
              onPress={() => {
                setQuery(example);
                setSubmitted(example);
              }}
            />
          ))}
        </Row>
      )}

      {searching && search.data?.interpreted && (
        <View style={{ marginTop: space.md, gap: space.sm }}>
          <Note>
            {search.data.interpreted.understood_as.length > 0
              ? `Read as: ${search.data.interpreted.understood_as.join(', ')}.`
              : 'Searching across everything in this city.'}
          </Note>
          <Chip label="Clear search" onPress={() => { setQuery(''); setSubmitted(''); }} />
        </View>
      )}

      {!searching && (
        <>
          <SectionHeader title="Filters" />
          <Row gap={space.sm} wrap>
            {FILTERS.map((filter) => (
              <Chip
                key={filter.key}
                label={filter.label}
                selected={filters.includes(filter.key)}
                onPress={() => toggle(filter.key)}
                tone="accent"
              />
            ))}
          </Row>
        </>
      )}

      {notice && (
        <View style={{ marginTop: space.lg }}>
          <Note>{notice}</Note>
        </View>
      )}

      <SectionHeader title={searching ? 'Results' : 'Ranked for you'} />
      {loading && <Loading />}

      {!loading && results.length === 0 && (
        <EmptyState
          title="Nothing fits all of that"
          body="Loosening the time, the budget or the distance usually opens it up."
        />
      )}

      {results.map((card) => (
        <ExperienceCardView key={card.id} card={card} recommendationSetId={setId} surface={searching ? 'search' : 'explore'} />
      ))}

      {(saved.data?.length ?? 0) > 0 && (
        <>
          <SectionHeader title="Saved" />
          {saved.data!.map((card) => (
            <ExperienceCardView key={card.id} card={card} surface="saved" compact />
          ))}
        </>
      )}
    </Screen>
  );
}
