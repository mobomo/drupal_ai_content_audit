# AIRO Preview

AIRO Preview adds a Drupal node edit panel where editors can ask an AI chat
provider how a page may appear or respond in AI-assisted discovery experiences.
It is powered by the [Drupal AI module](https://www.drupal.org/project/ai) and
uses the configured chat provider/model for responses.

> Pre-release notice: this module is under active development. Public APIs,
> configuration schema, and internal services may change until a stable `1.0.0`
> release is tagged.

## Current Scope

This parent module currently ships the AIRO Preview experience only:

- AIRO Preview panel on supported node edit screens.
- Drupal AI provider/model selection for chat.
- Prompt configuration for preview system and user prompts.
- Node/revision access checks for panel routes.
- CSRF-protected AJAX requests.

Scoring, saved assessments, action items, technical audit tabs, score widgets,
assessment history, queue processing, and bulk Drush assessment workflows live
in the optional `ai_content_audit_scoring` submodule.

## Requirements

| Requirement | Version |
|---|---|
| Drupal core | `^11.1` |
| PHP | `>=8.3` |
| Composer | `^2.0` |
| [drupal/ai](https://www.drupal.org/project/ai) | `^1.0` with at least one chat provider configured |

An AI provider submodule must also be enabled and configured, such as
`drupal/ai_provider_openai`, `drupal/ai_provider_ollama`, or another
Drupal AI-compatible chat provider.

This pre-release branch uses Drupal's OOP hook discovery with `#[Hook]`
attributes, so Drupal 10 is not supported.

## Installation

Until the project is published on drupal.org, install from the repository used
by your project.

```bash
composer require drupal/ai_content_audit:0.0.1
drush en ai_content_audit -y
drush cr
```

Then configure a Drupal AI chat provider and visit:

```text
/admin/config/ai/content-audit
```

To enable the optional scoring surface:

```bash
drush en ai_content_audit_scoring -y
drush cr
```

## Permissions

| Permission | Intended role | Notes |
|---|---|---|
| `administer ai content audit` | Site administrators | Configure AIRO Preview provider defaults and prompt settings. |
| `access airo preview` | Editors and reviewers | Use AIRO Preview on nodes they can access. |
| `manage content audit prompts` | Site builders or AI maintainers | Manage the AIRO Preview prompt configuration. |
| `use any ai provider in airo` | Trusted editors | Select and compare available provider/model combinations in the panel. |
| `view ai content assessment` | Optional scoring users | Provided by `ai_content_audit_scoring`. |
| `run ai content assessment` | Optional scoring users | Provided by `ai_content_audit_scoring`. |

Node access still applies. Users must be able to view the node or revision that
the panel is loading.

## Development Checks

Run coding standards from the Drupal project that has this module symlinked or
installed. In DDEV, use container paths when checking a symlinked module:

```bash
ddev php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  /var/www/html/docroot/modules/contrib/ai_content_audit
```

Useful smoke checks:

```bash
ddev drush cr
ddev drush ev '$node = \Drupal::entityTypeManager()->getStorage("node")->load(8055); $manager = \Drupal::service("ai_content_audit.airo_panel_tab_manager"); foreach ($manager->buildTabDefinitions($node, FALSE) as $tab) { echo $tab["id"] . " | " . $tab["label"] . PHP_EOL; }'
```

The tab manager should return only:

```text
preview-tab | AI Preview
```

Maintainer-oriented contrib readiness notes live in
`docs/CONTRIB_READINESS.md`.
