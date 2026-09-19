import React, { useState } from 'react';
import { Pressable, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useSearch } from '../src/api/hooks';
import { ExperienceCardView } from '../src/components/ExperienceCard';
import { Icon } from '../src/components/Icon';
import {
  Chip,
  EmptyState,
  Gutter,
  Loading,
  Note,
  Row,
  Screen,
  SectionHeader,
  T,
} from '../src/components/primitives';
import { radius, space, useTheme } from '../src/theme';

const EXAMPLES = [
  'Something romantic tonight',
  'A good place for my 6-year-old',
  'History within 20 minutes',
  'What can I do before my flight?',
  'Free things near me',
  'Good rainy-day picks',
];

/**
 * Natural-language search (Figma: 18).
 *
 * The interpretation is shown as chips *before* the results, so the traveller
 * can see how their sentence was read and correct it. That separation matters:
 * the chips are our inference, the cards below are place and availability data,
 * and blurring the two is how an app quietly loses trust.
 */
export default function Search() {
  const colors = useTheme();
  const router = useRouter();
  const [query, setQuery] = useState('');
  const [submitted, setSubmitted] = useState('');

  const search = useSearch(submitted, submitted.length > 2);
  const results = search.data?.data ?? [];
  const interpreted = search.data?.interpreted;

  const run = (text: string) => {
    setQuery(text);
    setSubmitted(text.trim());
  };

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.sm }}>
        <Row gap={space.xs}>
          <Pressable onPress={() => router.back()} hitSlop={12} accessibilityLabel="Back">
            <Icon name="back" size={24} color={colors.text.primary} />
          </Pressable>
          <T variant="h2" style={{ flex: 1 }}>
            Search
          </T>
        </Row>

        <View
          style={{
            marginTop: space.md,
            flexDirection: 'row',
            alignItems: 'center',
            gap: space.xs,
            backgroundColor: colors.background.elevated,
            borderWidth: 1,
            borderColor: colors.border.subtle,
            borderRadius: radius.pill,
            paddingHorizontal: space.md,
          }}
        >
          <Icon name="search" size={18} color={colors.text.tertiary} />
          <TextInput
            placeholder="What do you feel like doing?"
            placeholderTextColor={colors.text.tertiary}
            value={query}
            onChangeText={setQuery}
            onSubmitEditing={() => setSubmitted(query.trim())}
            returnKeyType="search"
            autoFocus
            style={{
              flex: 1,
              paddingVertical: 14,
              color: colors.text.primary,
              fontFamily: 'Inter_400Regular',
              fontSize: 16,
            }}
          />
          {query.length > 0 ? (
            <Pressable onPress={() => run('')} hitSlop={10} accessibilityLabel="Clear">
              <Icon name="close" size={18} color={colors.text.tertiary} />
            </Pressable>
          ) : null}
        </View>
      </Gutter>

      {submitted.length <= 2 ? (
        <Gutter>
          <SectionHeader title="Ask in your own words" caption="These all work" />
          <Row gap={space.xs} wrap>
            {EXAMPLES.map((example) => (
              <Chip key={example} label={example} onPress={() => run(example)} />
            ))}
          </Row>
        </Gutter>
      ) : null}

      {search.isLoading ? <Loading label="Reading your request" /> : null}

      {interpreted ? (
        <Gutter style={{ marginTop: space.lg }}>
          <View
            style={{
              backgroundColor: colors.action.soft,
              borderRadius: radius.card,
              padding: space.md,
              gap: space.xs,
            }}
          >
            <Row gap={6}>
              <Icon name="sparkle" size={15} color={colors.action.onSoft} />
              <T variant="label" color={colors.action.onSoft}>
                Read as
              </T>
            </Row>

            {interpreted.understood_as.length > 0 ? (
              <Row gap={6} wrap>
                {interpreted.understood_as.map((token) => (
                  <View
                    key={token}
                    style={{
                      paddingVertical: 5,
                      paddingHorizontal: 10,
                      borderRadius: radius.pill,
                      backgroundColor: colors.background.elevated,
                    }}
                  >
                    <T variant="small" color={colors.text.primary}>
                      {token}
                    </T>
                  </View>
                ))}
              </Row>
            ) : (
              <T variant="small" color={colors.action.onSoft}>
                Searching everything in this city.
              </T>
            )}

            <T variant="caption" color={colors.action.onSoft}>
              This is our interpretation. The results below come from place and availability data.
            </T>
          </View>
        </Gutter>
      ) : null}

      {search.data?.notice ? (
        <Gutter style={{ marginTop: space.sm }}>
          <Note tone="info">{search.data.notice}</Note>
        </Gutter>
      ) : null}

      {results.length > 0 ? (
        <Gutter>
          <SectionHeader
            title={`${results.length} experience${results.length === 1 ? '' : 's'} fit this`}
            caption="Ranked for you, closest fit first"
          />
          {results.map((card, index) => (
            <ExperienceCardView
              key={card.id}
              card={card}
              variant={index === 0 ? 'recommendation' : 'compact'}
              recommendationSetId={search.data?.recommendation_set_id}
              surface="search"
            />
          ))}
        </Gutter>
      ) : null}

      {submitted.length > 2 && !search.isLoading && results.length === 0 ? (
        <Gutter>
          <EmptyState
            title="Nothing strong fits all your filters"
            body="Try expanding the distance, the budget or the time you have."
          />
        </Gutter>
      ) : null}
    </Screen>
  );
}
