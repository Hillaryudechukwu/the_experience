import React from 'react';
import { Linking, Pressable, View } from 'react-native';

import { useDestination } from '../src/api/hooks';
import { Icon, type IconName } from '../src/components/Icon';
import { Card, Gutter, LoadFailure, Loading, Note, Row, Screen, SectionHeader, T } from '../src/components/primitives';
import { useSession } from '../src/store/session';
import { radius, space, useTheme } from '../src/theme';
import { useBackTo } from '../src/lib/navigation';

const CATEGORY_ICON: Record<string, IconName> = {
  currency: 'money',
  language: 'person',
  emergency: 'info',
  transport: 'transit',
  tipping: 'money',
  power: 'info',
  driving: 'directions',
  customs: 'person',
  meal_times: 'food',
  late_night_transport: 'transit',
  mistakes: 'info',
  scams: 'info',
  laws: 'info',
  health: 'accessibility',
};

/**
 * City essentials (Figma: 33).
 *
 * Magazine-like rather than a settings list, but every card carries its source
 * and the date it was checked. Practical facts people act on abroad have to be
 * attributable — this is the one screen where "trust us" is not good enough.
 */
export default function Essentials() {
  const colors = useTheme();
  const goBack = useBackTo('/(tabs)');
  const slug = useSession((s) => s.destinationSlug);
  const { data, isLoading, isError, refetch } = useDestination(slug);

  /* Drawn in every state, including the ones that fail. A screen whose only
     way out sits below the content is a screen you cannot leave when the
     content does not arrive. */
  const backControl = (
    <Pressable
      accessibilityRole="button"
      onPress={goBack}
      hitSlop={12}
      accessibilityLabel="Back"
    >
      <Icon name="back" size={24} color={colors.text.primary} />
    </Pressable>
  );

  if (isLoading) {
    return (
      <Screen>
        <Gutter style={{ paddingTop: space.sm }}>{backControl}</Gutter>
        <Loading />
      </Screen>
    );
  }

  /*
   * "Did not load" is not "still loading".
   *
   * The guard here was `isLoading || !data`, and a failed request leaves
   * isLoading false with data undefined — so the condition still held and the
   * spinner turned forever. No error, no retry, and nothing on screen but a
   * progress indicator.
   */
  if (isError || !data) {
    return (
      <Screen>
        <Gutter style={{ paddingTop: space.sm }}>{backControl}</Gutter>
        <Gutter>
          <LoadFailure title="City essentials did not load" onRetry={() => refetch()} />
        </Gutter>
      </Screen>
    );
  }

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.sm }}>
        <Row gap={space.xs}>
          {backControl}
        </Row>
        <T variant="displayL" style={{ marginTop: space.sm }}>
          Need to know
        </T>
        <T variant="bodyL" color={colors.text.secondary}>
          {data.name}
        </T>
      </Gutter>

      <Gutter style={{ marginTop: space.lg }}>
        <Note icon={<Icon name="info" size={16} color={colors.text.secondary} />}>
          Practical facts with a named source and the date we last checked them. We do not publish safety,
          legal or medical claims beyond what the source states.
        </Note>
      </Gutter>

      <Gutter style={{ marginTop: space.md, gap: space.sm }}>
        {data.city_essentials.map((item) => (
          <Card key={item.category}>
            <View style={{ padding: space.md, gap: space.xs }}>
              <Row gap={space.xs}>
                <View
                  style={{
                    width: 32,
                    height: 32,
                    borderRadius: radius.compact,
                    backgroundColor: colors.background.sunken,
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  <Icon name={CATEGORY_ICON[item.category] ?? 'info'} size={17} color={colors.text.secondary} />
                </View>
                <T variant="h3" style={{ flex: 1 }}>
                  {item.title}
                </T>
              </Row>

              <T variant="body" color={colors.text.secondary}>
                {item.body}
              </T>

              <Pressable disabled={!item.source.url} onPress={() => item.source.url && Linking.openURL(item.source.url)}>
                <T variant="caption" color={item.source.url ? colors.action.primary : colors.text.tertiary}>
                  {item.source.name} · checked {new Date(item.verified_at).toLocaleDateString()}
                </T>
              </Pressable>
            </View>
          </Card>
        ))}
      </Gutter>

      <Gutter>
        <SectionHeader title="Neighbourhoods" caption="Places worth an afternoon in their own right" />
        <View style={{ gap: space.sm }}>
          {data.neighbourhoods.map((area) => (
            <Card key={area.id}>
              <View style={{ padding: space.md, gap: 4 }}>
                <Row justify="space-between">
                  <T variant="h3">{area.name}</T>
                  <T variant="caption" color={colors.text.tertiary}>
                    {Math.round(area.ideal_duration_minutes / 60)}h
                  </T>
                </Row>
                {area.character ? (
                  <T variant="small" color={colors.text.secondary}>
                    {area.character}
                  </T>
                ) : null}
                {area.best_for.length > 0 ? (
                  <T variant="caption" color={colors.text.tertiary}>
                    Best for {area.best_for.join(', ')}
                  </T>
                ) : null}
              </View>
            </Card>
          ))}
        </View>
      </Gutter>

      <Gutter>
        <SectionHeader title={`Don't leave ${data.name} without`} />
        <Card tone="warm">
          <View style={{ padding: space.md, gap: space.md }}>
            {data.dont_leave_without.map((item) => (
              <View key={item.title} style={{ gap: 2 }}>
                <T variant="bodyStrong">{item.title}</T>
                <T variant="small" color={colors.text.secondary}>
                  {item.description}
                </T>
              </View>
            ))}
          </View>
        </Card>
      </Gutter>
    </Screen>
  );
}
