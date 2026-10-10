import fs from 'node:fs';
import path from 'node:path';

export type Finding = {
  kind: string;
  severity: 'info' | 'warning' | 'failure';
  route?: string;
  viewport?: string;
  message: string;
  detail?: unknown;
};

const findings: Finding[] = [];

function jsonlPath(): string {
  return path.join(process.cwd(), 'reports', 'generated', 'e2e-findings.jsonl');
}

export function recordFinding(finding: Finding): void {
  findings.push(finding);
  const dir = path.dirname(jsonlPath());
  fs.mkdirSync(dir, { recursive: true });
  fs.appendFileSync(jsonlPath(), JSON.stringify(finding) + '\n');
}

export function allFindings(): Finding[] {
  return [...findings];
}

export function isStrict(): boolean {
  return process.env.REGRESSION_STRICT === '1';
}

/** Soft-assert: record evidence now; only throw when REGRESSION_STRICT=1. */
export function expectUnlessKnown(ok: boolean, finding: Finding): void {
  if (ok) return;
  recordFinding(finding);
  if (isStrict()) {
    throw new Error(`${finding.kind}: ${finding.message}`);
  }
}

export function writeFindingsReport(): string {
  const dir = path.join(process.cwd(), 'reports', 'generated');
  fs.mkdirSync(dir, { recursive: true });
  const file = path.join(dir, 'e2e-findings.json');
  const jsonl = jsonlPath();
  let merged: Finding[] = findings;
  if (fs.existsSync(jsonl)) {
    merged = fs
      .readFileSync(jsonl, 'utf8')
      .split('\n')
      .filter(Boolean)
      .map((line) => JSON.parse(line) as Finding);
  }
  const byKind: Record<string, number> = {};
  for (const finding of merged) {
    byKind[finding.kind] = (byKind[finding.kind] ?? 0) + 1;
  }
  fs.writeFileSync(
    file,
    JSON.stringify(
      {
        generatedAt: new Date().toISOString(),
        strict: isStrict(),
        count: merged.length,
        byKind,
        findings: merged,
      },
      null,
      2,
    ),
  );
  return file;
}
