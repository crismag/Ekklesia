# Production acceptance probe

`tools/acceptance-audit.mjs` measures portal claims against a live base URL.

```bash
PORTAL_AUDIT_EMAIL=… PORTAL_AUDIT_PASSWORD=… node tools/acceptance-audit.mjs
```

- Default base: `https://christlikeness.crishub.com/church_portal`
- Login is the only POST. No other mutations.
- `raw.json` is gitignored (may contain page titles). `summary.json` is the shareable rollup.

Re-run this tool for a current production probe.
