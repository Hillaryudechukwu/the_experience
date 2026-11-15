import React, { useState } from 'react';
import { Platform, Pressable, TextInput, View } from 'react-native';

import { Icon } from './Icon';
import { T } from './primitives';
import { radius, space, useTheme } from '../theme';

type Props = {
  label: string;
  value: string;
  onChange: (value: string) => void;
  autoComplete?: 'password' | 'new-password' | 'current-password';
  textContentType?: 'password' | 'newPassword';
  placeholder?: string;
};

/**
 * Password entry with a show/hide control.
 *
 * The eye sits inside the field so the label stays a single tap target for
 * VoiceOver, and the toggle is its own button with an explicit label.
 */
export function PasswordField({
  label,
  value,
  onChange,
  autoComplete = 'password',
  textContentType = 'password',
  placeholder,
}: Props) {
  const colors = useTheme();
  const [visible, setVisible] = useState(false);

  return (
    <View style={{ gap: 6 }}>
      <T variant="label" color={colors.text.tertiary}>
        {label}
      </T>
      <View
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          borderWidth: 1,
          borderColor: colors.border.strong,
          borderRadius: radius.control,
          backgroundColor: colors.background.elevated,
          minHeight: 48,
          paddingRight: space.xs,
        }}
      >
        <TextInput
          accessibilityLabel={label}
          value={value}
          onChangeText={onChange}
          secureTextEntry={!visible}
          autoComplete={autoComplete}
          textContentType={textContentType}
          autoCapitalize="none"
          autoCorrect={false}
          spellCheck={false}
          importantForAutofill="yes"
          placeholder={placeholder}
          placeholderTextColor={colors.text.tertiary}
          style={{
            flex: 1,
            paddingHorizontal: space.md,
            paddingVertical: 12,
            color: colors.text.primary,
            fontSize: Platform.OS === 'web' ? 16 : 17,
          }}
        />
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={visible ? 'Hide password' : 'Show password'}
          onPress={() => setVisible((current) => !current)}
          hitSlop={8}
          style={({ pressed }) => ({
            padding: space.sm,
            opacity: pressed ? 0.7 : 1,
          })}
        >
          <Icon name={visible ? 'eye-off' : 'eye'} size={20} color={colors.text.secondary} />
        </Pressable>
      </View>
    </View>
  );
}
