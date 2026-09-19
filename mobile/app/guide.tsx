import React, { useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAssistant } from '../src/api/hooks';
import { Icon } from '../src/components/Icon';
import { Chip, Loading, Note, Row, T } from '../src/components/primitives';
import { radius, space, useTheme } from '../src/theme';

type Bubble = {
  role: 'user' | 'assistant';
  content: string;
  suggestions?: string[];
  experiences?: { experience_id: string; title: string; score: number }[];
  grounded?: boolean;
  removed?: string[];
  tools?: string[];
};

/** Quick actions from the brief (Figma: 49). */
const QUICK_ACTIONS = [
  'Build my afternoon',
  'Replan because of rain',
  'Something romantic tonight',
  'Find something for the kids',
  'What should I skip?',
];

/**
 * AI Experience Guide (Figma: 49, 50).
 *
 * Presented as a sheet over the trip rather than a tab, because it is a tool
 * you reach for in context, not a destination. Trust markers are part of the
 * reply, not a footnote: which services were consulted, and what was removed
 * for being unverifiable.
 */
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
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.background.base }} edges={['top', 'left', 'right']}>
      <KeyboardAvoidingView
        style={{ flex: 1 }}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        keyboardVerticalOffset={12}
      >
        <Row justify="space-between" style={{ paddingHorizontal: space.lg, paddingTop: space.xs }}>
          <Row gap={space.xs}>
            <Icon name="sparkle" size={22} color={colors.action.primary} />
            <View>
              <T variant="h3">Guide</T>
              <T variant="caption" color={colors.text.tertiary}>
                Your journey, understood
              </T>
            </View>
          </Row>
          <Pressable onPress={() => router.back()} hitSlop={12} accessibilityLabel="Close">
            <Icon name="close" size={24} color={colors.text.secondary} />
          </Pressable>
        </Row>

        <ScrollView
          ref={scroller}
          contentContainerStyle={{ padding: space.lg, gap: space.md, paddingBottom: space.xxl }}
          keyboardShouldPersistTaps="handled"
        >
          {bubbles.length === 0 ? (
            <View style={{ gap: space.md }}>
              <Note tone="info" icon={<Icon name="info" size={16} color={colors.action.onSoft} />}>
                Every fact here comes from the same live data the rest of the app uses. If it does not have
                something, it will say so rather than guess.
              </Note>
              <T variant="label" color={colors.text.tertiary}>
                Try
              </T>
              <Row gap={space.xs} wrap>
                {QUICK_ACTIONS.map((action) => (
                  <Chip key={action} label={action} onPress={() => send(action)} tone="accent" />
                ))}
              </Row>
            </View>
          ) : null}

          {bubbles.map((bubble, index) => (
            <View key={index} style={{ alignItems: bubble.role === 'user' ? 'flex-end' : 'flex-start' }}>
              <View
                style={{
                  maxWidth: '94%',
                  backgroundColor: bubble.role === 'user' ? colors.action.primary : colors.background.elevated,
                  borderColor: colors.border.subtle,
                  borderWidth: bubble.role === 'user' ? 0 : 1,
                  borderRadius: radius.card,
                  borderBottomRightRadius: bubble.role === 'user' ? 4 : radius.card,
                  borderBottomLeftRadius: bubble.role === 'user' ? radius.card : 4,
                  padding: space.md,
                  gap: space.sm,
                }}
              >
                <T variant="body" color={bubble.role === 'user' ? colors.text.onAccent : colors.text.primary}>
                  {bubble.content}
                </T>

                {bubble.role === 'assistant' && bubble.experiences && bubble.experiences.length > 0 ? (
                  <Row gap={space.xs} wrap>
                    {bubble.experiences.map((experience) => (
                      <Chip
                        key={experience.experience_id}
                        label={`${experience.title} · ${experience.score}`}
                        onPress={() => router.push(`/experience/${experience.experience_id}`)}
                        tone="accent"
                        size="small"
                      />
                    ))}
                  </Row>
                ) : null}

                {bubble.role === 'assistant' && bubble.removed && bubble.removed.length > 0 ? (
                  <Note tone="warning">
                    I removed {bubble.removed.length} claim{bubble.removed.length === 1 ? '' : 's'} I could not
                    verify against live data.
                  </Note>
                ) : null}

                {bubble.role === 'assistant' && bubble.tools && bubble.tools.length > 0 ? (
                  <Row gap={5}>
                    <Icon name="check" size={13} color={colors.text.tertiary} />
                    <T variant="caption" color={colors.text.tertiary}>
                      Checked {bubble.tools.join(', ').replace(/_/g, ' ')}
                    </T>
                  </Row>
                ) : null}
              </View>

              {bubble.role === 'assistant' && bubble.suggestions && bubble.suggestions.length > 0 ? (
                <Row gap={space.xs} wrap style={{ marginTop: space.xs }}>
                  {bubble.suggestions.map((suggestion) => (
                    <Chip key={suggestion} label={suggestion} onPress={() => send(suggestion)} size="small" />
                  ))}
                </Row>
              ) : null}
            </View>
          ))}

          {assistant.isPending ? <Loading label="Checking live conditions" /> : null}
        </ScrollView>

        <SafeAreaView edges={['bottom']} style={{ backgroundColor: colors.background.elevated }}>
          <Row
            gap={space.xs}
            style={{
              padding: space.md,
              borderTopWidth: 1,
              borderTopColor: colors.border.subtle,
            }}
          >
            <TextInput
              placeholder="Ask anything about this city"
              placeholderTextColor={colors.text.tertiary}
              value={input}
              onChangeText={setInput}
              onSubmitEditing={() => send(input)}
              returnKeyType="send"
              style={{
                flex: 1,
                backgroundColor: colors.background.base,
                borderRadius: radius.pill,
                paddingHorizontal: space.md,
                paddingVertical: 12,
                color: colors.text.primary,
                fontFamily: 'Inter_400Regular',
                fontSize: 15,
              }}
            />
            <Pressable
              onPress={() => send(input)}
              accessibilityLabel="Send"
              style={({ pressed }) => ({
                width: 44,
                height: 44,
                borderRadius: 22,
                alignItems: 'center',
                justifyContent: 'center',
                backgroundColor: input.trim() ? colors.action.primary : colors.border.strong,
                opacity: pressed ? 0.8 : 1,
              })}
            >
              <Icon name="chevron" size={20} color="#FFFFFF" strokeWidth={2.2} />
            </Pressable>
          </Row>
        </SafeAreaView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
