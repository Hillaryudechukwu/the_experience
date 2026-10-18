import React, { useEffect } from 'react';
import { AppState, View } from 'react-native';
import { focusManager, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Stack } from 'expo-router';
import Head from 'expo-router/head';
import { StatusBar } from 'expo-status-bar';
import { useFonts } from 'expo-font';
import * as SplashScreen from 'expo-splash-screen';
import { Sora_600SemiBold, Sora_700Bold } from '@expo-google-fonts/sora';
import {
  Inter_400Regular,
  Inter_500Medium,
  Inter_600SemiBold,
  Inter_700Bold,
} from '@expo-google-fonts/inter';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { useColorScheme } from 'react-native';

import { BRAND, brandDocumentTitle } from '../src/brand';
import { WayoaLogo } from '../src/components/WayoaLogo';
import { colorModes } from '../src/design/tokens';
import { usePaywallOn402 } from '../src/lib/usePaywallOn402';
import { useSession } from '../src/store/session';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false, staleTime: 30_000 },
  },
});

/* Keep the native splash up until the wordmark can paint in the right type. */
SplashScreen.preventAutoHideAsync().catch(() => {});

export default function RootLayout() {
  const hydrate = useSession((s) => s.hydrate);
  const refreshLocation = useSession((s) => s.refreshLocation);
  const scheme = useColorScheme();
  const colors = colorModes[scheme === 'dark' ? 'dark' : 'light'];

  /* Sora carries the display voice, Inter the interface. Holding the first
     paint until both are ready avoids the jarring reflow of a system-font
     flash on a screen whose whole character is typographic. */
  const [fontsLoaded, fontError] = useFonts({
    Sora_600SemiBold,
    Sora_700Bold,
    Inter_400Regular,
    Inter_500Medium,
    Inter_600SemiBold,
    Inter_700Bold,
  });

  useEffect(() => {
    hydrate();
  }, [hydrate]);

  /* Settled, not loaded. useFonts leaves fontsLoaded false forever when an
     asset fails, and the splash is hidden by nothing else — so a corrupt
     bundle or an OTA update whose fonts did not fetch left the app on the
     native splash with no spinner, no error and no way out but force-quit.
     Falling back to system fonts is a worse-looking app; staying on the
     splash is no app at all. */
  const fontsSettled = fontsLoaded || fontError !== null;

  useEffect(() => {
    if (fontsSettled) SplashScreen.hideAsync().catch(() => {});
  }, [fontsSettled]);

  /*
   * Re-read the position whenever the app comes back to the foreground.
   *
   * Refreshing only when Discover mounts left a traveller who keeps the app
   * open — the normal way to use it while walking — on the coordinate they
   * arrived with. This is the app-wide answer because location belongs to the
   * session rather than to a screen, and it is cheap: the store declines to
   * take a fix taken in the last couple of minutes, and never prompts.
   */
  useEffect(() => {
    const subscription = AppState.addEventListener('change', (next) => {
      const active = next === 'active';
      focusManager.setFocused(active);
      if (active) refreshLocation();
    });

    return () => subscription.remove();
  }, [refreshLocation]);

  if (!fontsSettled) {
    return (
      <View
        style={{
          flex: 1,
          alignItems: 'center',
          justifyContent: 'center',
          backgroundColor: colorModes.light.brand.midnight,
        }}
      >
        <WayoaLogo variant="full" size="lg" />
      </View>
    );
  }

  return (
    <QueryClientProvider client={queryClient}>
      <SafeAreaProvider>
        <Head>
          <title>{brandDocumentTitle}</title>
          <meta name="description" content={BRAND.description} />
          <meta property="og:title" content={brandDocumentTitle} />
          <meta property="og:description" content={BRAND.description} />
          <meta property="og:type" content="website" />
          <meta name="theme-color" content={colorModes.light.brand.midnight} />
        </Head>
        <SubscriptionWatch />
        <StatusBar style={scheme === 'dark' ? 'light' : 'dark'} />
        <Stack
          screenOptions={{
            headerShown: false,
            animation: 'slide_from_right',
            contentStyle: { backgroundColor: colors.background.base },
          }}
        >
          <Stack.Screen name="index" />
          <Stack.Screen name="onboarding" />
          <Stack.Screen name="(tabs)" />
          <Stack.Screen name="experience/[id]" />
          <Stack.Screen name="guide" options={{ animation: 'slide_from_bottom' }} />
          <Stack.Screen name="search" options={{ animation: 'fade' }} />
        </Stack>
      </SafeAreaProvider>
    </QueryClientProvider>
  );
}

/**
 * Inside the provider, because it watches the query cache.
 *
 * Renders nothing: it is here so that one refused request anywhere in the app
 * lands on the paywall rather than on a screen full of empty states.
 */
function SubscriptionWatch() {
  usePaywallOn402();

  return null;
}
