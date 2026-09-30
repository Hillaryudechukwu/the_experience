import { useEffect, useState } from 'react';

/**
 * A value that settles before anything acts on it.
 *
 * Search ran only when the traveller pressed the return key, so typing a place
 * name showed nothing at all and the one control that would have helped is a
 * key on a soft keyboard that is easy to miss entirely. Firing on every
 * keystroke instead would put a request — and a scoring pass over every
 * candidate — behind every letter.
 *
 * Three hundred milliseconds is about the gap between words when someone is
 * typing normally, so it waits for a thought rather than a character.
 */
export function useDebounced<T>(value: T, delay = 300): T {
  const [settled, setSettled] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setSettled(value), delay);

    return () => clearTimeout(timer);
  }, [value, delay]);

  return settled;
}
