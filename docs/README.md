# Ekklesia documentation

Ekklesia is a production church application in use by Christlikeness Church,
and free software that other churches can install. Start with the product guide
for capabilities, the in-app guide for everyday work, the installation guide
for a new host, or the contributing guide to help improve it.

| Document | Purpose |
| --- | --- |
| [README](../README.md) | Product overview, architecture and verification commands |
| [Feature guides](features/README.md) | Every area in detail: people, ministries and serving, events and calendar, publications, visitors, accounts, administration |
| [Feature summary](features.md) | A one-page summary of the guides |
| In-app `/docs` · [source sections](../resources/views/docs/sections/) | Screen-by-screen help for members, leaders and administrators |
| [Installation and deployment](deploy/installation.md) | New installations, development setup, Church Portal migration and upgrades |
| [Release and routing](deploy/README.md) | Deployment script, server rewrites, maintenance gate and recovery |
| [Production configuration](deploy/production-config.md) | Debugging, logging, Google and email sign-in configuration |
| [Database guide](../database/README.md) | Active member/visitor schemas and legacy-data migration |
| [Migration rules](../database/members/migrations/README.md) | Schema history and forward-only changes |
| [Maintenance](ops-maintenance.md) | Private backups, staged roster import and exports |
| [Sign-up module](../people_signup/README.md) | Guest registration installation and configuration |
| [RSVP module](../events_rsvp/README.md) | Event response installation and configuration |
| [Site gate](../tools/site-gate/README.md) | Whole-site password protection for private installations |
| [Regression harness](regression-harness.md) | PHP, source-contract and browser verification |
| [Local nginx](nginx_christlikeness_local.md) | Local web-server configuration |
| [Church help page](help.md) | Christlikeness Church's internal notes on reporting problems and requesting access |

## Licensing and collaboration

| Document | Purpose |
| --- | --- |
| [LICENSE](../LICENSE) | GNU Affero General Public License v3.0 (`AGPL-3.0-only`) |
| [Licensing guide](licensing.md) | What the license allows and requires, and how an installation offers its source |
| [Third-party notices](../THIRD_PARTY_NOTICES.md) | Bundled material with its own terms, and church branding |
| [Contributing](../CONTRIBUTING.md) | Ways to help, development setup, checks, data rules |
| [Governance](../GOVERNANCE.md) | Who maintains Ekklesia and how decisions are made |
| [Releases and workflow](releasing.md) | Pull requests, versions, release notes, labels, contributor opportunities |
| [Code of conduct](../CODE_OF_CONDUCT.md) | Expected behavior and private reporting |
| [Security policy](../SECURITY.md) | Reporting vulnerabilities privately |
| [Getting help](../SUPPORT.md) | Where each kind of question goes, including paid assistance |

## Engineering records

The numbered [design records](design/) and [reports](../reports/README.md) retain
dated analysis, decisions and enhancement plans. They are historical context,
not the current product catalogue or a launch-status assessment. Check the
current implementation before relying on an old capability status.

The [migration 013 caveat](design/34-migration-013-caveat.md) remains relevant to
schema maintenance: do not edit an already-applied migration.
