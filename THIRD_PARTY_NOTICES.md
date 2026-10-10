# Third-party notices

Ekklesia's own code is licensed under `AGPL-3.0-only` (see [LICENSE](LICENSE)
and the [licensing guide](docs/licensing.md)). The material below comes from
others, or belongs to the church, and keeps its own terms. This list records
what was identified when the repository was prepared for public contribution;
it is not a complete legal audit of every dependency. If you find something
missing or wrong, please open an issue.

## Bundled in this repository

| Material | Where | Source and terms |
| --- | --- | --- |
| Canadian postal-area index | `config/ca-postal-areas.json` (built by `tools/build-postal-index.php`) | Postal code data from [GeoNames](https://www.geonames.org/), licensed under [Creative Commons Attribution 4.0](https://creativecommons.org/licenses/by/4.0/). Credit: GeoNames. |
| Public-holiday cache | `config/holidays.json` (refreshed by `tools/fetch-holidays.php`) | Holiday dates retrieved from the [Nager.Date](https://date.nager.at) public holiday API. Use of the API is subject to its own terms. |
| "Settings" navigation icon | `resources/views/_portal-shell.php` (the `settings` path) | The gear shape matches the "settings" icon from Google's [Material Icons](https://github.com/google/material-design-icons), licensed under the [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0). |
| PowerPoint starter themes and skeleton | `resources/print/starters/*.pptx`, `resources/print/pptx/skeleton.pptx`, `tests/fixtures/print-theme-example.pptx` | Generated with [python-pptx](https://github.com/scanny/python-pptx) (MIT License) from its default template, which supplies the slide master, layouts and theme. Ekklesia's placeholders and text are original. |
| Code of conduct | `CODE_OF_CONDUCT.md` | Adapted from the [Contributor Covenant](https://www.contributor-covenant.org), version 2.1, licensed under [Creative Commons Attribution 4.0](https://creativecommons.org/licenses/by/4.0/). |
| License text | `LICENSE` | The GNU Affero General Public License v3, © Free Software Foundation, Inc. Copied verbatim, as its terms require. |

## Church branding (not covered by the AGPL)

The Christlikeness Church name and logo are the church's own and are **not**
licensed for reuse by this repository's license:

- `images/christlikeness_colored.jpg`, `public/images/christlikeness_colored.jpg`
  and `images/christlkeness.avif` (the church logo);
- church-specific wording and settings in `config/` (for example the church
  name in `config/church-info.json`, `people_signup/config/signup.config.json`
  and `events_rsvp/config/rsvp.config.json`, and the access-code prefix).

Another organization installing Ekklesia should replace these with its own name,
logo and settings.

## Used at run time, not bundled

These services are contacted by an installation while it runs. Their data and
use are governed by their own terms; operators are responsible for following
them.

| Service | Used for | Terms |
| --- | --- | --- |
| [OpenStreetMap Nominatim](https://nominatim.openstreetmap.org) and [Photon](https://photon.komoot.io) | Turning addresses into coordinates | Results are OpenStreetMap data, © OpenStreetMap contributors, available under the [Open Database License](https://www.openstreetmap.org/copyright). Nominatim's [usage policy](https://operations.osmfoundation.org/policies/nominatim/) requires an identifying User-Agent and limits request rates. |
| [Nager.Date](https://date.nager.at) | Refreshing public holidays | The service's own terms. |
| [Google Fonts](https://fonts.google.com) (Archivo) | The ministry-schedule print preview | Archivo is licensed under the [SIL Open Font License 1.1](https://openfontlicense.org). |
| Google sign-in (optional) | Signing in with Google, when configured | Google's terms for OAuth clients. |

## Packages declared but not used at run time

`composer.json` and `package.json` still declare packages from the project's
original scaffold (Laravel, Inertia, React and Vite) and development tools
(PHPUnit and Playwright). The running application does not load them, and the
repository does not include their code; anyone who installs them receives them
under their own licenses.
