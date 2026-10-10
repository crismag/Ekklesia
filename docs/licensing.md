# Licensing

Ekklesia's own code is licensed under the **GNU Affero General Public License,
version 3 only** (SPDX: `AGPL-3.0-only`). The full text is in
[LICENSE](../LICENSE). This page explains what that means in practice. It is a
plain-language summary, not legal advice; the license text is what applies.

Copyright © 2026 Cris Magalang.

## What you may do

- **Run your own installation.** A church, ministry or other organization can
  install Ekklesia on its own website, server or intranet, with its own settings,
  infrastructure and data.
- **Use it commercially.** The license does not restrict commercial use. You may
  charge for hosting, installation, configuration or support.
- **Change it.** Study the code and adapt it to your needs.
- **Share it.** Give copies to others, changed or unchanged, under the same
  license.

There are no additional conditions: no "non-commercial" rule, no "churches
only" rule, no required donation and no obligation to contribute back.

## What you must do

**When you distribute Ekklesia** (give or sell a copy, changed or not), you must
pass on the same license and make the corresponding source code available to
the people who receive it, as the license describes.

**When you run a modified version that people use over a network**, you must
offer those people the corresponding source code of the version you are actually
running (section 13 of the license). For Ekklesia, that means the people who
sign in to or otherwise interact with your installation.

- **An intranet is not automatically exempt.** If people interact with your
  modified installation over a network, the obligation applies to them, whether
  the network is the internet or your organization's own.
- **The source must match what runs.** A link to the upstream repository's
  latest branch is not enough if your installation is modified, or runs a
  version that differs from that branch. Point to the exact source you deploy:
  a release tag, a release archive, or your own fork.
- **An unmodified installation** that runs a published release can point to
  that release.

Corresponding source means the source code of the program as you run it,
including your changes and the scripts needed to install it, as the license
defines.

## What source availability does not require

Publishing source code never requires publishing your installation's data.
These are **not** part of the application source and must stay private:

- the church's database, member records and other personal information;
- passwords, tokens, API keys and the `.env` file;
- private configuration, backups, logs and uploaded files.

Keep them out of any repository or archive you publish.

## How an installation offers its source

Every Ekklesia installation has a **Source code** page at `/source`, linked
from the footer of every page and from **About**. It shows the link the operator
sets in `.env`:

```ini
EKKLESIA_SOURCE_URL=https://github.com/your-church/ekklesia/tree/v1.2.0
EKKLESIA_SOURCE_VERSION=v1.2.0
```

Set `EKKLESIA_SOURCE_URL` to the source of the version you run: a release tag,
a release archive, or the matching commit or tag in your fork if you changed
anything. Update it whenever you deploy a different version. Only `http(s)` links
without embedded credentials are shown, so never put a token in it; a private
fork needs a link your users can actually open.

Without a setting, the page links to the upstream project and labels it a
reference only. That fallback does not satisfy the obligation for a modified
installation.

## Contributing back

The license does not require you to send your changes to this repository.
We do encourage it: if you extend Ekklesia for your church, please consider
contributing reusable improvements back so other churches can benefit. See
[CONTRIBUTING.md](../CONTRIBUTING.md). Contributors keep the copyright in their
contributions and license them under `AGPL-3.0-only`; there is no copyright
assignment or contributor license agreement.

## Parts with their own terms

Some material in this repository comes from others and keeps its own license
or terms, such as the GeoNames postal data and the church's own logo. See
[THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md). The `AGPL-3.0-only` license
covers Ekklesia's own code; it does not relicense those parts.

## Authoritative guidance

- [GNU AGPL v3 text](https://www.gnu.org/licenses/agpl-3.0.html)
- [Why the Affero GPL](https://www.gnu.org/licenses/why-affero-gpl.html)
- [Frequently asked questions about the GNU licenses](https://www.gnu.org/licenses/gpl-faq.html)
