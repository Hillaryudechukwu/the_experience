import { useRouter } from 'expo-router';
import { useEffect } from 'react';
import { View } from 'react-native';

/**
 * Fallback if a Google return still reaches the router. The auth session
 * reads the code from the URL itself; this screen only gets out of the way.
 */
export default function OAuthReturn() {
  const router = useRouter();

  useEffect(() => {
    if (router.canGoBack()) router.back();
    else router.replace('/');
  }, [router]);

  return <View style={{ flex: 1 }} />;
}
