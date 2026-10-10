# Ekklesia documentation

Ekklesia is a production church application in use by Christlikeness Church.
Start with the product guide for capabilities, the in-app guide for everyday
work, or the installation guide for a new host.

| Document | Purpose |
| --- | --- |
| [README](../README.md) | Product overview, architecture and verification commands |
| [Feature guides](features/README.md) | Every area in detail: people, ministries and serving, events and calendar, publications, visitors, accounts, administration |
| [Feature summary](features.md) | A one-page summary of the guides |
| In-app `/docs` · [source sections](../resources/views/docs/sections/) | Screen-by-screen help for members, leaders and administrators |
| [Installation and deployment](deploy/installation.md) | Runtime, configuration, database setup and release checklist |
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
| [Help and support](help.md) | Reporting problems and requesting access |

## Engineering records

The numbered [design records](design/) and [reports](../reports/README.md) retain
dated analysis, decisions and enhancement plans. They are historical context,
not the current product catalogue or a launch-status assessment. Check the
current implementation before relying on an old capability status.

The [migration 013 caveat](design/34-migration-013-caveat.md) remains relevant to
schema maintenance: do not edit an already-applied migration.
