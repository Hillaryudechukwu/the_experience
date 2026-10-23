# Security risk acceptances

## Expo SDK 57 transitive npm advisories

- **Accepted:** 23 October 2026
- **Owner:** Engineering
- **Review by:** 31 January 2027, or sooner when Expo SDK 58 is adopted or a
  compatible upstream patch is published
- **Scope:** production dependency graph in `mobile/package-lock.json`

The production graph reports 21 affected packages, but they resolve to three
upstream advisories. No critical advisory is accepted:

| Advisory | Severity | Dependency path | Decision |
|---|---:|---|---|
| `GHSA-vfj7-8cjw-p6xm` | high | Metro → `micromatch` → `braces` | Temporarily accepted. Metro supplies the glob patterns during local/CI bundle construction; travellers do not supply them. No patched `braces` release is available in the compatible Expo SDK 57 graph. |
| `GHSA-86w9-cpqp-85rv` | high | Expo CLI → code-signing certificates → `node-forge` | Temporarily accepted. This path is build/signing tooling and does not verify attacker-controlled certificates in the shipped application. No compatible patched release is available. |
| `GHSA-vcc3-ghjq-m6fr` | moderate | Expo Router → `query-string` → `decode-uri-component` | Temporarily accepted. The parser is runtime-reachable through web/deep-link query strings, but the compatible SDK 57 Router release still carries it. Upgrade at the review trigger above. |

Controls and evidence:

- `shell-quote` is locked to 1.12.0, removing the previous critical advisory.
- Expo, Expo Router, Location, Notifications and Metro runtime are on the
  latest compatible SDK 57 patch set (`npx expo install --check`).
- `npm run audit:production` fails CI on any critical or any advisory outside
  this exact three-advisory baseline. It also reports when an accepted advisory
  disappears so the baseline can be reduced.
- `npm audit fix --force` is prohibited for this acceptance: its proposed
  versions cross the supported Expo/React Native compatibility boundary.
