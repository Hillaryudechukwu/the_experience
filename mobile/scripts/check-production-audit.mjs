import { spawnSync } from 'node:child_process';

/*
 * Expo SDK 57 has three unresolved upstream advisories in its production
 * dependency graph. They are accepted until the review date recorded in
 * docs/SECURITY_ACCEPTANCES.md. Pinning the exact advisory URLs makes this a
 * baseline, not an instruction to ignore every future high finding.
 *
 * The acceptance lapses on its review date: after it the gate fails until the
 * advisories are fixed or the acceptance is renewed in both places.
 */
const reviewBy = '2027-01-31';

const accepted = new Set([
  'https://github.com/advisories/GHSA-86w9-cpqp-85rv',
  'https://github.com/advisories/GHSA-mrhx-c7xc-m3w5',
  'https://github.com/advisories/GHSA-vcc3-ghjq-m6fr',
]);

const audit = spawnSync('npm', ['audit', '--omit=dev', '--json'], {
  encoding: 'utf8',
  shell: process.platform === 'win32',
  maxBuffer: 64 * 1024 * 1024,
});

if (audit.error) {
  process.stderr.write(`Could not run npm audit: ${audit.error.message}\n`);
  process.exit(1);
}

if (!audit.stdout?.trim()) {
  process.stderr.write(audit.stderr || 'npm audit returned no report.\n');
  process.exit(1);
}

let report;

try {
  report = JSON.parse(audit.stdout);
} catch {
  process.stderr.write(`npm audit returned invalid JSON:\n${audit.stdout}\n`);
  process.exit(1);
}

if (report.error) {
  process.stderr.write(`npm audit failed: ${report.error.summary ?? JSON.stringify(report.error)}\n`);
  process.exit(1);
}

const reported = new Set();

for (const vulnerability of Object.values(report.vulnerabilities ?? {})) {
  for (const cause of vulnerability.via ?? []) {
    if (typeof cause === 'object' && cause !== null && typeof cause.url === 'string') {
      reported.add(cause.url);
    }
  }
}

const unexpected = [...reported].filter((url) => !accepted.has(url));
const missing = [...accepted].filter((url) => !reported.has(url));
const critical = report.metadata?.vulnerabilities?.critical ?? 0;
const today = new Date().toISOString().slice(0, 10);
const acceptanceLapsed = today > reviewBy && accepted.size > missing.length;

if (critical > 0 || unexpected.length > 0 || acceptanceLapsed) {
  if (critical > 0) process.stderr.write(`${critical} critical production advisories found.\n`);
  if (unexpected.length > 0) {
    process.stderr.write(`Unaccepted production advisories:\n${unexpected.join('\n')}\n`);
  }
  if (acceptanceLapsed) {
    process.stderr.write(
      `The accepted advisories were due for review by ${reviewBy}. Fix them or renew the ` +
      'acceptance in docs/SECURITY_ACCEPTANCES.md and this script.\n',
    );
  }
  process.exit(1);
}

if (missing.length > 0) {
  process.stdout.write(`Resolved accepted advisories (remove them from the baseline):\n${missing.join('\n')}\n`);
}

const totals = report.metadata?.vulnerabilities ?? {};
process.stdout.write(
  `Production audit matches the accepted baseline: ${totals.high ?? 0} high, ` +
  `${totals.moderate ?? 0} moderate, ${critical} critical.\n`,
);
