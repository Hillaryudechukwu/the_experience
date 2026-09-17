import React from 'react';
import { Linking, Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useDestination } from '../src/api/hooks';
import { Card, Loading, Note, Row, Screen, SectionHeader, T } from '../src/components/primitives';
import { useSession } from '../src/store/session';
import { space, useTheme } from '../src/theme';

/** Spec s12.1 — City Essentials. Every line is sourced and timestamped. */
export default function Essentials() {
  const colors = useTheme();
  const router = useRouter();
  const slug = useSession((s) => s.destinationSlug);
  const { data, isLoading } = useDestination(slug);

  if (isLoading || !data) {
    return (
      <Screen>
        <Loading />
      </Screen>
    );
  }

  return (
    <Screen>
      <Row style={{ justifyContent: 'space-between' }}>
        <T variant="display">{data.name}</T>
        <Pressable onPress={() => router.back()} hitSlop={10}>
          <T variant="bodyStrong" color={colors.accent}>
            Done
          </T>
        </Pressable>
      </Row>

      {data.summary && (
        <T variant="body" color={colors.inkMuted} style={{ marginTop: space.sm }}>
          {data.summary}
        </T>
      )}

      <SectionHeader title="Essentials" />
      <Note>
        These are practical facts with a named source and the date we last checked them. We do not publish
        safety, legal or medical claims beyond what the source states.
      </Note>

      <View style={{ marginTop: space.md, gap: space.sm }}>
        {data.city_essentials.map((item) => (
          <Card key={item.category}>
            <View style={{ padding: space.lg, gap: 4 }}>
              <T variant="bodyStrong">{item.title}</T>
              <T variant="small" color={colors.inkMuted}>
                {item.body}
              </T>
              <Pressable
                disabled={!item.source.url}
                onPress={() => item.source.url && Linking.openURL(item.source.url)}
              >
                <T variant="small" color={item.source.url ? colors.accent : colors.inkFaint}>
                  {item.source.name} · checked {new Date(item.verified_at).toLocaleDateString()}
                </T>
              </Pressable>
            </View>
          </Card>
        ))}
      </View>

      <SectionHeader title="Neighbourhoods" />
      {data.neighbourhoods.map((neighbourhood) => (
        <Card key={neighbourhood.id} style={{ marginBottom: space.sm }}>
          <View style={{ padding: space.lg, gap: 4 }}>
            <Row style={{ justifyContent: 'space-between' }}>
              <T variant="bodyStrong">{neighbourhood.name}</T>
              <T variant="small" color={colors.inkFaint}>
                {Math.round(neighbourhood.ideal_duration_minutes / 60)} hr
              </T>
            </Row>
            {neighbourhood.character && (
              <T variant="small" color={colors.inkMuted}>
                {neighbourhood.character}
              </T>
            )}
            {neighbourhood.best_for.length > 0 && (
              <T variant="small" color={colors.inkFaint}>
                Best for {neighbourhood.best_for.join(', ')}
              </T>
            )}
          </View>
        </Card>
      ))}

      <SectionHeader title={`Don't leave ${data.name} without`} />
      <Card>
        <View style={{ padding: space.lg, gap: space.lg }}>
          {data.dont_leave_without.map((item) => (
            <View key={item.title} style={{ gap: 2 }}>
              <T variant="bodyStrong">{item.title}</T>
              <T variant="small" color={colors.inkMuted}>
                {item.description}
              </T>
            </View>
          ))}
        </View>
      </Card>
    </Screen>
  );
}
