import React from 'react';
import { Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useJourneyRecap, usePassport } from '../src/api/hooks';
import { Icon } from '../src/components/Icon';
import { Card, CardPress, Divider, EmptyState, Gutter, LoadFailure, Loading, Note, Row, Screen, SectionHeader, T } from '../src/components/primitives';
import { titleCase } from '../src/lib/format';
import { useSession } from '../src/store/session';
import { radius, space, useTheme } from '../src/theme';
import { useBackTo } from '../src/lib/navigation';

/**
 * Experience Passport (Figma: 37).
 *
 * An identity surface, not a fitness dashboard. The numbers are large and few,
 * the cities read like stamps, and nothing here is framed as a target to hit.
 */
export default function PassportScreen() {
  const colors = useTheme();
  const router = useRouter();
  const goBack = useBackTo('/(tabs)/you');
  const journeyId = useSession((s) => s.journeyId);
  const { data, isLoading, isError, refetch } = usePassport();
  const { data: recap } = useJourneyRecap(journeyId);

  /* Drawn in every state, including the ones that fail. A screen whose only
     way out sits below the content is a screen you cannot leave when the
     content does not arrive. */
  const header = (
    <Gutter style={{ paddingTop: space.sm }}>
      <Pressable
        accessibilityRole="button"
        onPress={goBack}
        hitSlop={12}
        accessibilityLabel="Back"
      >
        <Icon name="back" size={24} color={colors.text.primary} />
      </Pressable>
      <T variant="displayL" style={{ marginTop: space.sm }}>
        Passport
      </T>
    </Gutter>
  );

  if (isLoading) {
    return (
      <Screen>
        {header}
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
        {header}
        <Gutter>
          <LoadFailure title="Your passport did not load" onRetry={() => refetch()} />
        </Gutter>
      </Screen>
    );
  }

  return (
    <Screen>
      {header}

      {data.totals.experiences === 0 ? (
        <Gutter>
          <EmptyState
            title="Your passport starts with your first experience"
            body="Mark something as done and it lands here, with the city and the date."
          />
        </Gutter>
      ) : (
        <>
          <Gutter style={{ marginTop: space.lg }}>
            <Card tone="warm">
              <Row justify="space-around" style={{ padding: space.lg }}>
                <Stat label="Experiences" value={data.totals.experiences} />
                <Stat label="Cities" value={data.totals.cities} />
                <Stat label="Countries" value={data.totals.countries} />
              </Row>
            </Card>
          </Gutter>

          {recap ? (
            <Gutter>
              <SectionHeader title="This trip" caption={recap.destination} />
              <Card>
                <View style={{ padding: space.md, gap: space.sm }}>
                  <Row justify="space-between">
                    <T variant="small" color={colors.text.secondary}>
                      Experiences done
                    </T>
                    <T variant="bodyStrong">{recap.experiences}</T>
                  </Row>
                  {recap.days ? (
                    <Row justify="space-between">
                      <T variant="small" color={colors.text.secondary}>
                        Days
                      </T>
                      <T variant="bodyStrong">{recap.days}</T>
                    </Row>
                  ) : null}
                  {recap.average_rating ? (
                    <Row justify="space-between">
                      <T variant="small" color={colors.text.secondary}>
                        Average rating
                      </T>
                      <T variant="bodyStrong">{recap.average_rating}</T>
                    </Row>
                  ) : null}
                  {recap.highlights.length > 0 ? (
                    <Note>
                      Highlight: {recap.highlights[0]?.best_part ?? 'Your highest-rated stop this trip.'}
                    </Note>
                  ) : null}
                </View>
              </Card>
            </Gutter>
          ) : null}

          <Gutter>
            <SectionHeader title="Cities" />
            <Card>
              <View style={{ padding: space.md }}>
                {data.cities.map((city, index) => (
                  <React.Fragment key={city.id}>
                    {index > 0 ? <Divider style={{ marginVertical: space.sm }} /> : null}
                    <Row justify="space-between">
                      <Row gap={space.sm}>
                        <View
                          style={{
                            width: 36,
                            height: 36,
                            borderRadius: 18,
                            borderWidth: 1.5,
                            borderStyle: 'dashed',
                            borderColor: colors.border.strong,
                            alignItems: 'center',
                            justifyContent: 'center',
                          }}
                        >
                          <Icon name="location" size={16} color={colors.text.secondary} />
                        </View>
                        <View>
                          <T variant="bodyStrong">{city.name}</T>
                          <T variant="caption" color={colors.text.tertiary}>
                            {city.country}
                          </T>
                        </View>
                      </Row>
                      <T variant="h3" color={colors.action.primary}>
                        {city.experiences}
                      </T>
                    </Row>
                  </React.Fragment>
                ))}
              </View>
            </Card>
          </Gutter>

          <Gutter>
            <SectionHeader title="What you go for" />
            <Row gap={space.xs} wrap>
              {data.categories.map((category) => (
                <View
                  key={category.key}
                  style={{
                    paddingVertical: 8,
                    paddingHorizontal: space.sm,
                    borderRadius: radius.pill,
                    backgroundColor: colors.background.sunken,
                  }}
                >
                  <T variant="small" color={colors.text.secondary}>
                    {titleCase(category.key)} · {category.count}
                  </T>
                </View>
              ))}
            </Row>
          </Gutter>

          <Gutter>
            <SectionHeader title="Milestones" />
            <Card>
              <View style={{ padding: space.md, gap: space.sm }}>
                {data.milestones.map((milestone) => (
                  <Row key={milestone.key} justify="space-between">
                    <Row gap={space.xs}>
                      <Icon
                        name={milestone.reached ? 'check' : 'clock'}
                        size={16}
                        color={milestone.reached ? colors.status.open : colors.text.tertiary}
                      />
                      <T variant="body" color={milestone.reached ? colors.text.primary : colors.text.tertiary}>
                        {milestone.label}
                      </T>
                    </Row>
                    <T variant="caption" color={milestone.reached ? colors.status.open : colors.text.tertiary}>
                      {milestone.reached ? 'Earned' : 'Not yet'}
                    </T>
                  </Row>
                ))}
              </View>
            </Card>
          </Gutter>

          <Gutter>
            <SectionHeader title="Recently" />
            <View style={{ gap: space.sm }}>
              {data.recent.map((entry) => (
                <Card key={entry.experience_id + entry.completed_at}>
                  <CardPress
                    onPress={() => router.push(`/experience/${entry.experience_id}`)}
                    accessibilityLabel={`Open ${entry.title}`}
                  >
                    <Row justify="space-between" style={{ padding: space.md }}>
                      <View style={{ flex: 1 }}>
                        <T variant="bodyStrong" numberOfLines={1}>
                          {entry.title}
                        </T>
                        <T variant="small" color={colors.text.secondary}>
                          {entry.destination} · {new Date(entry.completed_at).toLocaleDateString()}
                        </T>
                      </View>
                      <Icon name="chevron" size={16} color={colors.text.tertiary} />
                    </Row>
                  </CardPress>
                </Card>
              ))}
            </View>
          </Gutter>
        </>
      )}
    </Screen>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  const colors = useTheme();

  return (
    <View style={{ alignItems: 'center', gap: 2 }}>
      <T variant="displayL">{value}</T>
      <T variant="caption" color={colors.text.secondary}>
        {label}
      </T>
    </View>
  );
}
