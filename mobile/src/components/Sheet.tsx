import React from 'react';
import { Modal, Pressable, ScrollView, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { elevation, radius, space, useTheme } from '../theme';
import { Icon } from './Icon';
import { Row, T } from './primitives';

/**
 * Bottom sheet (Figma: 56 Overlays).
 *
 * Used for the score explanation, map selection and replan proposals — places
 * where the traveller needs more detail without losing the screen underneath.
 * The scrim is tappable because a sheet you cannot dismiss by looking away is a
 * trap.
 */
export function Sheet({
  visible,
  onClose,
  title,
  children,
  maxHeight = '85%',
}: {
  visible: boolean;
  onClose: () => void;
  title?: string;
  children: React.ReactNode;
  maxHeight?: number | `${number}%`;
}) {
  const colors = useTheme();

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <View style={{ flex: 1, backgroundColor: colors.scrim, justifyContent: 'flex-end' }}>
        <Pressable
          accessibilityRole="button"
          style={{ flex: 1 }}
          onPress={onClose}
          accessibilityLabel="Close"
        />

        <SafeAreaView edges={['bottom']} style={{ backgroundColor: colors.background.elevated }}>
          <View
            style={{
              backgroundColor: colors.background.elevated,
              borderTopLeftRadius: radius.sheet,
              borderTopRightRadius: radius.sheet,
              maxHeight,
              ...elevation.sheet,
            }}
          >
            <View style={{ alignItems: 'center', paddingTop: space.sm }}>
              <View style={{ width: 40, height: 4, borderRadius: 2, backgroundColor: colors.border.strong }} />
            </View>

            {/* The close control is unconditional. There is no swipe-to-dismiss on
                web, so a titleless sheet previously left tapping the scrim as the
                only way out — discoverable to nobody. */}
            <Row justify="space-between" style={{ paddingHorizontal: space.lg, paddingTop: space.md }}>
              {title ? <T variant="h3">{title}</T> : <View />}
              <Pressable
                onPress={onClose}
                hitSlop={14}
                accessibilityRole="button"
                accessibilityLabel="Close"
                style={{ width: 32, height: 32, alignItems: 'flex-end', justifyContent: 'center' }}
              >
                <Icon name="close" size={22} color={colors.text.secondary} />
              </Pressable>
            </Row>

            <ScrollView
              contentContainerStyle={{ padding: space.lg, paddingBottom: space.xxl }}
              showsVerticalScrollIndicator={false}
            >
              {children}
            </ScrollView>
          </View>
        </SafeAreaView>
      </View>
    </Modal>
  );
}
