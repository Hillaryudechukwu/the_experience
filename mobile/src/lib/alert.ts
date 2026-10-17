import { Alert, Platform } from 'react-native';

/**
 * react-native-web ships `Alert.alert` as a no-op. Confirmation dialogs that
 * put the real work in a button `onPress` therefore never run on web — delete
 * account, sign out, and similar flows look dead and never navigate away.
 *
 * Native keeps the system alert; web uses `window.confirm` / `window.alert`.
 */

export function showAlert(title: string, message?: string): void {
  if (Platform.OS === 'web') {
    const text = message ? `${title}\n\n${message}` : title;
    if (typeof window !== 'undefined') window.alert(text);
    return;
  }

  Alert.alert(title, message);
}

export function confirmAction(
  title: string,
  message: string,
  options: {
    confirmLabel: string;
    cancelLabel?: string;
    destructive?: boolean;
    onConfirm: () => void | Promise<void>;
    /* Declining is a decision too, and the caller sometimes has to act on it
       — a screen that asks on the way out still has to leave either way. */
    onCancel?: () => void;
  },
): void {
  const cancelLabel = options.cancelLabel ?? 'Cancel';

  if (Platform.OS === 'web') {
    const text = `${title}\n\n${message}`;
    if (typeof window !== 'undefined' && window.confirm(text)) {
      void options.onConfirm();
    } else {
      options.onCancel?.();
    }
    return;
  }

  Alert.alert(title, message, [
    { text: cancelLabel, style: 'cancel', onPress: () => options.onCancel?.() },
    {
      text: options.confirmLabel,
      style: options.destructive ? 'destructive' : 'default',
      onPress: () => {
        void options.onConfirm();
      },
    },
  ]);
}
