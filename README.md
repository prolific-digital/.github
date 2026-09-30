# prolific-digital/.github

Shared GitHub configuration for Prolific Digital: the org profile (`profile/`) and the **reusable CI/CD for our WordPress plugins**.

Every plugin follows the Prolific plugin standard (the normalization contract of 2026-09-29): one slug for the repo, folder, main file, text domain and zip; a fixed main-file header; versions that agree everywhere; and a fixed set of required files. The workflows here enforce that standard and build releases.

## Contents

| Path | What it is |
|---|---|
| `.github/workflows/wp-plugin-ci.yml` | Reusable CI (`workflow_call`) |
| `.github/workflows/wp-plugin-release.yml` | Reusable release on `v*` tags (`workflow_call`) |
| `bin/check-plugin.php` | Dependency-free PHP 8.4 checker for the plugin standard |
| `templates/ci.yml`, `templates/release.yml` | Thin caller workflows to copy into each plugin |
| `templates/phpcs.xml.dist` | Default phpcs ruleset (WordPress-Extra + PHPCompatibilityWP, `testVersion 8.4-`) |
| `templates/.distignore` | Default release-zip exclusions |
| `tests/` | Fixture plugin and `tests/run.sh` |

## Using it in a plugin

Copy the two caller templates into the plugin and replace `{slug}`:

```sh
mkdir -p .github/workflows
for f in ci release; do
  curl -fsSL "https://raw.githubusercontent.com/prolific-digital/.github/main/templates/$f.yml" \
    | sed 's/{slug}/my-plugin/' > ".github/workflows/$f.yml"
done
```

`.github/workflows/ci.yml`:

```yaml
name: CI
on: { pull_request: {}, push: { branches: [main] } }
jobs:
  ci:
    uses: prolific-digital/.github/.github/workflows/wp-plugin-ci.yml@main
    with: { slug: my-plugin }
```

`.github/workflows/release.yml`:

```yaml
name: Release
on: { push: { tags: ['v*'] } }
permissions: { contents: write }
jobs:
  release:
    uses: prolific-digital/.github/.github/workflows/wp-plugin-release.yml@main
    with: { slug: my-plugin }
```

### Inputs (both workflows)

| Input | Type | Default | Meaning |
|---|---|---|---|
| `slug` | string | required | The plugin slug. The main file must be `<slug>.php` at the repo root. |
| `phpcs-strict` | boolean | `false` | CI: when `false`, phpcs runs but does not fail the build (legacy code will not pass yet). Release: when `true`, phpcs must pass before a zip is built; when `false` phpcs is skipped. |
| `ci-ref` | string | `main` | Ref of this repo to load `bin/` and `templates/` from. If you pin `uses: ...@v1`, set `ci-ref: v1` too. |

## What CI runs

On PHP 8.4 (`shivammathur/setup-php@v2`), in this order:

1. `php -l` on every PHP file except `vendor/`, `node_modules/` and the tooling checkout.
2. `bin/check-plugin.php . <slug>`: the plugin standard check (below).
3. If `composer.json` exists: `composer validate --no-check-publish`, then `composer install`.
4. phpcs with WPCS 3 and PHPCompatibilityWP 2, using the plugin's own `phpcs.xml(.dist)` if it has one, else `templates/phpcs.xml.dist`. It passes `text_domain=<slug>` and `testVersion=8.4-` at runtime. The step uses `continue-on-error` unless `phpcs-strict: true`.
5. If `composer.json` defines `scripts.test`: `composer test`.
6. If `package.json` exists: Node from `.nvmrc`, or Node 20 if there is none. Then `npm ci` (or `npm install` with a warning if there is no lockfile), `npm run build --if-present` and `npm run test --if-present`.

## What a release does

When a `v*` tag is pushed:

1. `bin/check-plugin.php . <slug> --tag=<tag>`. The tag must equal `v<header Version>`, the rest of the standard must pass, and `CHANGELOG.md` must have a `## [x.y.z]` section.
2. Optionally runs phpcs as a gate (`phpcs-strict: true`).
3. `composer install --no-dev --optimize-autoloader` if `composer.json` exists, and `npm ci && npm run build` if `package.json` exists.
4. Copies the plugin into `<slug>/` with rsync, honoring the plugin's `.distignore` (or `templates/.distignore` if it has none; `.git` and `.prolific-ci` are always excluded). It then zips that into `<slug>.zip`. The step fails if the main file was excluded, and warns if `vendor/` was.
5. Creates the GitHub Release for the tag, or updates it if one exists. The body is that version's `CHANGELOG.md` section and `<slug>.zip` is attached.

## `bin/check-plugin.php`

```sh
php bin/check-plugin.php <plugin-dir> <slug> [--tag=vX.Y.Z]
```

It prints a PASS/FAIL/WARN report grouped by section, and emits `::error`/`::warning` annotations under GitHub Actions. It exits 0 on success (warnings allowed), 1 on any failure and 2 on a usage error. It has no dependencies and needs PHP 8.4+.

**Failures:**

- `<plugin-dir>/<slug>.php` must exist. The slug must be lowercase-hyphen.
- Header: every contract field must be present, with no duplicates and in the contract's order. The fields with fixed values must match them exactly:
  - `Plugin URI` must be `https://prolificdigital.com/plugins/<slug>`.
  - `Requires at least` must be `6.5` and `Requires PHP` must be `8.4`.
  - `Author` must be `Prolific Digital` and `Author URI` must be `https://prolificdigital.com`.
  - `License` must be `GPL-2.0-or-later` and `License URI` must be `https://www.gnu.org/licenses/gpl-2.0.html`.
  - `Text Domain` must be `<slug>` and `Domain Path` must be `/languages`.
  - `Update URI` must be `https://api.prolificdigital.io/api/update?plugin=<slug>`.
  - `Plugin Name` and `Description` must be non-empty.
  - `Version` must be `x.y.z`.
  - `Requires Plugins` is optional. If present, it must be a comma list of slugs.
- `defined( 'ABSPATH' ) || exit;` must be the first statement after the header. Every other PHP file must have an ABSPATH guard. `uninstall.php` may use `WP_UNINSTALL_PLUGIN` instead. `vendor`, `node_modules`, `build`, `dist`, `tests`, `test` and `bin` are skipped.
- The `<PREFIX>_VERSION` constant must equal the header Version. When there are several `*_VERSION` constants, the checker uses the one whose prefix also has a `_PLUGIN_FILE`, `_FILE`, `_PLUGIN_DIR`, `_PATH` or `_DIR` constant.
- `package.json` `version` must equal the header Version, if `package.json` exists.
- `--tag` must equal `v<Version>`, if given.
- `readme.txt` must exist, with:
  - `Stable tag` equal to the Version
  - `Tested up to` present
  - `Requires PHP: 8.4` and `Requires at least: 6.5`
  - `License` present
  - `== Description ==` and `== Changelog ==` sections
- `CHANGELOG.md`, `LICENSE` and `.distignore` must exist, and `LICENSE` must contain the GPL-2.0 text.
- With `--tag`, `CHANGELOG.md` must have a section for the version.

**Warnings only:**

- The folder name differs from the slug.
- There is no CHANGELOG section for the current version yet, and no `--tag` was given.
- Any of these are missing: `README.md`, `composer.json`, `phpcs.xml.dist`, `languages/.gitkeep`, `includes/update-checker.php`, or the caller workflows.
- A pasted-in copy of `plugin-update-checker` is present.

## Org settings / access

This repo is **public**, so any repo in the org can call these reusable workflows, including private ones. No "Access" setting is needed; that setting only applies when the called repo is private or internal. Org Actions policy is currently "all repositories, all actions allowed", and the default `GITHUB_TOKEN` is read/write. The release caller asks for `contents: write` explicitly, so it keeps working if the org default is tightened to read-only.

If this repo is ever made private, go to **Settings → Actions → General → Access** and choose "Accessible from repositories in the 'prolific-digital' organization".

## Development

```sh
tests/run.sh
```

This uses local PHP 8.4+ if it is installed, otherwise the `php:8.4-cli` Docker image. It runs the following:

- The checker against `tests/fixtures/good-plugin`, which must pass.
- About 20 single-defect mutations of that fixture, each of which must fail with the expected message.
- The legacy `main` of `syspro-order-fields`, which must fail. Set `SYSPRO_DIR` and `SYSPRO_LEGACY_REF` to override the path and ref.
- `actionlint` over the workflows and the rendered caller templates, plus a YAML parse.

Changes on `main` go live for every plugin immediately, because the callers use `@main`. Run `tests/run.sh` before merging.
