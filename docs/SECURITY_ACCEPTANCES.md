# Security risk acceptances

## Expo SDK 57 transitive npm advisories

- **Accepted:** 23 October 2026
- **Owner:** Engineering
- **Review by:** 31 January 2027, or sooner when Expo SDK 58 is adopted or a
  compatible upstream patch is published
- **Scope:** production dependency graph in `mobile/package-lock.json`

As of 17 November 2026 the production graph resolves to four upstream advisories
(2 high, 2 moderate). No critical advisory is accepted.
`GHSA-vfj7-8cjw-p6xm` (`braces`), accepted on 23 October, is no longer reported
against the locked `braces@3.0.3` and has left the baseline. `GHSA-86w9-cpqp-85rv`
(`node-forge`) left the baseline on 1 November 2026, when a compatible patched
`node-forge` was locked with `npm audit fix --omit=dev`. `GHSA-mrhx-c7xc-m3w5`
(`zod`) and `GHSA-gcqx-w48m-w2h4` (`deepmerge`) left it with the SDK 57 patch
update on 5 November 2026, and `GHSA-3g3m-2fjr-6pw4` (`glob`) is no longer
reported as of 17 November 2026:

| Advisory | Severity | Dependency path | Decision |
|---|---:|---|---|
| `GHSA-vcc3-ghjq-m6fr` | moderate | Expo Router → `query-string` → `decode-uri-component` | Temporarily accepted. The parser is runtime-reachable through web/deep-link query strings, but the compatible SDK 57 Router release still carries it. Upgrade at the review trigger above. |
| `GHSA-qr28-fpjm-wq7f` | high | Expo Router → `query-string@7` → `decode-uri-component@0.2.2` | Temporarily accepted (added 1 November 2026). Quadratic-time decoding of long percent-encoded runs; the same runtime path as `GHSA-vcc3-ghjq-m6fr`, reachable only through query strings on web and deep links, so the worst case is a slow parse on the traveller's own device. The fix is in `decode-uri-component` 0.5.1, which `query-string@7` (pinned by Expo Router 57) does not accept; Expo Router 58 belongs to SDK 58. |
| `GHSA-g3hv-75wg-m249` | moderate | Expo → `@expo/fingerprint` / `glob` → `minimatch@10` | Temporarily accepted (added 1 November 2026). Pattern-amplification denial of service; patterns come from Expo's own build tooling, not traveller input, and the shipped app does not bundle it. The fix is in minimatch 11.0.1+, outside the `^10` ranges SDK 57 tooling requires. |
| `GHSA-fc5q-39xc-vjpf` | high | Expo → `@expo/cli`, `@expo/metro-config`, Metro `micromatch`, `jest-util`; React Native → `tinyglobby` → `picomatch` 2.x and 4.x | Temporarily accepted (added 17 November 2026). ReDoS through single-branch repeated extglobs such as `+(*)`; the globs come from Expo, Metro and React Native build tooling, not traveller input, and the shipped app does not bundle them. No patched release exists: every picomatch through 4.0.7, the latest, is affected. |

Controls and evidence:

- `shell-quote` is locked to 1.12.0, removing the previous critical advisory.
- Expo, Expo Router, Location, Notifications and Metro runtime are on the
  latest compatible SDK 57 patch set (`npx expo install --check`).
- `npm run audit:production` fails CI on any critical or any advisory outside
  this exact four-advisory baseline. It also reports when an accepted advisory
  disappears so the baseline can be reduced.
- `npm audit fix --force` is prohibited for this acceptance: its proposed
  versions cross the supported Expo/React Native compatibility boundary.
