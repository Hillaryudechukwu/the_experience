import React, { useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAssistant } from '../../src/api/hooks';
import { Card, Chip, Loading, Note, Row, T } from '../../src/components/primitives';
import { radius, space, useTheme } from '../../src/theme';

type Bubble = {
  role: 'user' | 'assistant';
  content: string;
  suggestions?: string[];
  experiences?: { experience_id: string; title: string; score: number }[];
  grounded?: boolean;
  removed?: string[];
  tools?: string[];
};

const OPENERS = [
  'What should I do right now?',
  'I have two hours before dinner',
  'Something a local would do, not another museum',
  'How do I pay for transport, and should I tip?',
];

/** Spec s15 — the guide reasons over the same services the rest of the app uses. */
export default function Guide() {
  const colors = useTheme();
  const router = useRouter();
  const scroller = useRef<ScrollView>(null);

  const [input, setInput] = useState('');
  const [conversationId, setConversationId] = useState<string | null>(null);
  const [bubbles, setBubbles] = useState<Bubble[]>([]);
  const assistant = useAssistant();

  const send = async (text: string) => {
    const message = text.trim();
    if (!message) return;

    setInput('');
    setBubbles((current) => [...current, { role: 'user', content: message }]);

    try {
      const reply = await assistant.mutateAsync({ message, conversationId });
      setConversationId(reply.conversation_id);
      setBubbles((current) => [
        ...current,
        {
          role: 'assistant',
          content: reply.message.content,
          suggestions: reply.message.suggestions,
          experiences: reply.message.experiences,
          grounded: reply.message.grounded,
          removed: reply.message.removed_claims,
          tools: reply.message.tool_calls,
        },
      ]);
    } catch (cause) {
      setBubbles((current) => [
        ...current,
        {
          role: 'assistant',
          content:
            cause instanceof Error
              ? `I could not reach the travel services just now (${cause.message}). Rather than guess, I would rather say nothing.`
              : 'Something went wrong.',
        },
      ]);
    } finally {
      setTimeout(() => scroller.current?.scrollToEnd({ animated: true }), 80);
    }
  };

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.bg }} edges={['top', 'left', 'right']}>
      <KeyboardAvoidingView
        style={{ flex: 1 }}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        keyboardVerticalOffset={90}
      >
        <View style={{ paddingHorizontal: space.lg, paddingTop: space.sm }}>
          <T variant="title">Guide</T>
          <T variant="small" color={colors.inkMuted} style={{ marginTop: 4 }}>
            Every fact here comes from the same live data the rest of the app uses. If it does not have it, it
            will say so.
          </T>
        </View>

        <ScrollView
          ref={scroller}
          contentContainerStyle={{ padding: space.lg, gap: space.md, paddingBottom: space.xxl }}
          keyboardShouldPersistTaps="handled"
        >
          {bubbles.length === 0 && (
            <View style={{ gap: space.md }}>
              <Note>Ask in your own words. Try one of these.</Note>
              <Row gap={space.sm} wrap>
                {OPENERS.map((opener) => (
                  <Chip key={opener} label={opener} onPress={() => send(opener)} />
                ))}
              </Row>
            </View>
          )}

          {bubbles.map((bubble, index) => (
            <View key={index} style={{ alignItems: bubble.role === 'user' ? 'flex-end' : 'flex-start' }}>
              <View
                style={{
                  maxWidth: '92%',
                  backgroundColor: bubble.role === 'user' ? colors.accent : colors.surface,
                  borderColor: colors.line,
                  borderWidth: bubble.role === 'user' ? 0 : 1,
                  borderRadius: radius.lg,
                  padding: space.lg,
                  gap: space.sm,
                }}
              >
                <T variant="body" color={bubble.role === 'user' ? '#FFFFFF' : colors.ink}>
                  {bubble.content}
                </T>

                {bubble.role === 'assistant' && bubble.experiences && bubble.experiences.length > 0 && (
                  <Row gap={space.sm} wrap>
                    {bubble.experiences.map((experience) => (
                      <Chip
                        key={experience.experience_id}
                        label={`${experience.title} · ${experience.score}`}
                        onPress={() => router.push(`/experience/${experience.experience_id}`)}
                        tone="accent"
                      />
                    ))}
                  </Row>
                )}

                {bubble.role === 'assistant' && bubble.removed && bubble.removed.length > 0 && (
                  <Note tone="warn">
                    I removed {bubble.removed.length} claim{bubble.removed.length === 1 ? '' : 's'} I could not
                    verify against live data.
                  </Note>
                )}

                {bubble.role === 'assistant' && bubble.tools && bubble.tools.length > 0 && (
                  <T variant="small" color={colors.inkFaint}>
                    Checked: {bubble.tools.join(', ')}
                  </T>
                )}
              </View>

              {bubble.role === 'assistant' && bubble.suggestions && bubble.suggestions.length > 0 && (
                <Row gap={space.sm} wrap style={{ marginTop: space.sm }}>
                  {bubble.suggestions.map((suggestion) => (
                    <Chip key={suggestion} label={suggestion} onPress={() => send(suggestion)} />
                  ))}
                </Row>
              )}
            </View>
          ))}

          {assistant.isPending && <Loading label="Checking live conditions" />}
        </ScrollView>

        <View
          style={{
            flexDirection: 'row',
            gap: space.sm,
            padding: space.lg,
            paddingTop: space.sm,
            borderTopWidth: 1,
            borderTopColor: colors.line,
            backgroundColor: colors.surface,
          }}
        >
          <TextInput
            placeholder="Ask anything about this city"
            placeholderTextColor={colors.inkFaint}
            value={input}
            onChangeText={setInput}
            onSubmitEditing={() => send(input)}
            returnKeyType="send"
            style={{
              flex: 1,
              backgroundColor: colors.bg,
              borderRadius: radius.pill,
              paddingHorizontal: space.lg,
              paddingVertical: 12,
              color: colors.ink,
              fontSize: 15,
            }}
          />
          <Pressable
            onPress={() => send(input)}
            style={({ pressed }) => ({
              width: 44,
              height: 44,
              borderRadius: 22,
              alignItems: 'center',
              justifyContent: 'center',
              backgroundColor: colors.accent,
              opacity: pressed || !input.trim() ? 0.6 : 1,
            })}
          >
            <T variant="bodyStrong" color="#FFFFFF">
              ↑
            </T>
          </Pressable>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
