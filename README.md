# AI Content Audit

Drupal module **`ai_content_audit`** evaluates Drupal node content quality and
AI-readiness using the [Drupal AI](https://www.drupal.org/project/ai) module.

Assessments can run from the administration UI, Drush, or the optional queue.
Results are stored as **AI Content Assessment** entities, allowing editors and
site administrators to review score history and improvement suggestions over
time.

**Machine name:** `ai_content_audit`
**Composer package:** `drupal/ai_content_audit`
**Core compatibility:** Drupal 10 and 11
**License:** GPL-2.0-or-later

---

## Features

- Content quality and AI-readiness assessments for Drupal nodes.
- Configurable provider and model selection through the Drupal AI ecosystem.
- Assessment history stored as Drupal content entities.
- AIRO panel integration for running and reviewing assessments from node edit
  workflows.
- Optional queue-based processing for background assessments.
- Drush commands for targeted, bulk, and maintenance operations.
- Technical and filesystem audit checks through plugin-based services.
- Optional **AI Site Audit** submodule for sitewide reporting.

---

## Requirements

| Requirement | Notes |
|-------------|-------|
| **Drupal core** | `^10 \|\| ^11`, as declared in `ai_content_audit.info.yml`. |
| **PHP** | A PHP version supported by the installed Drupal core version. See this module's `composer.json` for package requirements. |
| **Drupal AI** | `^1.0`. At least one chat-capable provider and model must be configured before running assessments. |
| **Node** | Required core module. |
| **Layout Builder** | Required core module. Used by HTML-oriented extraction workflows. |

Provider integrations, such as OpenAI, Anthropic, Ollama, or other Drupal
AI-compatible providers, are installed separately. This module does not include
API credentials.

---

## Installation

### Composer installation

After the project is available from Drupal.org / Packagist:

```bash
composer require drupal/ai_content_audit
drush en ai_content_audit -y
drush cr
```

The module can also be enabled from the Drupal administration UI at:

```text
/admin/modules
```

Search for **AI Content Audit** and enable it.

---

### Pre-release installation

Before the module is published on Drupal.org, install it from the project Git
repository or from a local development copy.

#### Composer VCS installation

Add a `repositories` entry to the site's root `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/YOUR-ORG/ai_content_audit.git"
    }
  ]
}
```

Then require the module:

```bash
composer require drupal/ai_content_audit:0.0.1
```

For development branches, use a branch constraint only when you are comfortable
with updates changing on each `composer update`:

```bash
composer require "drupal/ai_content_audit:dev-main@dev"
```

#### Manual installation

For local development, clone or copy the module into:

```text
web/modules/custom/ai_content_audit
```

Then ensure Drupal AI is installed through Composer:

```bash
composer require drupal/ai
```

Enable the module:

```bash
drush en ai_content_audit -y
drush cr
```

Composer installation is recommended whenever possible because it manages
dependencies and autoloading consistently.

---

## Configuration

### Configure Drupal AI

Install and configure at least one Drupal AI provider module before running
assessments.

Provider configuration is managed by the Drupal AI module and its provider
integrations. Review the Drupal AI project documentation for the current
configuration paths and provider-specific settings.

### Configure AI Content Audit

Configure this module at:

```text
/admin/config/ai/content-audit
```

The module stores settings in:

```text
ai_content_audit.settings
```

Default configuration and schema are provided in:

```text
config/install/ai_content_audit.settings.yml
config/schema/ai_content_audit.schema.yml
```

Common settings include:

- node types included in assessments;
- default provider and model;
- maximum characters per assessment request;
- content extraction render mode;
- optional assessment on node save;
- assessment history limits.

After changing configuration, rebuild caches if needed:

```bash
drush cr
```

---

## Permissions

Permissions are defined in `ai_content_audit.permissions.yml`.

| Permission | Use |
|------------|-----|
| `administer ai content audit` | Manage module settings and administrative assessment workflows. |
| `view ai content assessment` | View assessment records and related UI where this permission is required. |
| `run ai content assessment` | Trigger new assessments from supported UI routes or tools. |
| `use any ai provider in airo` | Select alternate provider and model combinations in the AIRO experience. |

Grant only the permissions required for each role. Some routes may combine
permissions or include additional access checks. Review
`ai_content_audit.routing.yml` for exact route requirements.

---

## Usage

### Administration UI

On supported node edit workflows, the AIRO panel allows users with the required
permissions to run assessments and review results.

Depending on the integration point, the panel may expose:

- score summary;
- content improvement suggestions;
- action items;
- technical audit signals;
- content preview;
- provider and model selection.

### Assessment history

Assessment history is available per node through the module's assessment history
route, when the current user has the required permissions.

Common path:

```text
/node/{node}/ai-assessment
```

### Assessment collection

Assessment entities can be reviewed from the administration UI when the current
user has the required permissions.

Common path:

```text
/admin/content/ai-assessments
```

### Block

The module provides an assessment summary block for displaying the latest
assessment information where a node context is available.

Place blocks through:

```text
/admin/structure/block
```

### Optional submodule: AI Site Audit

The optional **AI Site Audit** submodule provides sitewide audit and reporting
features.

Enable it only when site-level reporting is needed:

```bash
drush en ai_site_audit -y
drush cr
```

---

## Drush

List available commands:

```bash
drush list ai_content_audit
```

If the optional site audit submodule is enabled:

```bash
drush list ai_site_audit
```

Common commands include:

| Command | Purpose |
|---------|---------|
| `ai_content_audit:assess` | Assess a single node or enqueue multiple nodes for assessment. |
| `ai-content-audit:providers` | List configured chat providers and available models. |
| `ai_content_audit:purge` | Delete assessment entities. |
| `ai_content_audit:reinstall` | Development helper for reinstalling the module. |
| `aica:filesystem-audit` | Run filesystem-oriented audit checks. |

Example: assess a single node.

```bash
drush ai_content_audit:assess --nid=42
```

Example: enqueue all supported published nodes.

```bash
drush ai_content_audit:assess --all
```

Example: enqueue only one content type.

```bash
drush ai_content_audit:assess --all --type=article
```

Process queued assessments:

```bash
drush queue:run ai_content_audit_assessment
```

For complete options, review the command classes in:

```text
src/Commands/
modules/ai_site_audit/src/Commands/
```

---

## Uninstallation

Before uninstalling, export or purge assessment data if you need to preserve it.

To purge assessment data through Drush, when available:

```bash
drush ai_content_audit:purge
```

Uninstall the module:

```bash
drush pm:uninstall ai_content_audit -y
drush cr
```

The module can also be uninstalled from:

```text
/admin/modules/uninstall
```

If the package was installed through Composer, remove it from the project when
it is no longer needed:

```bash
composer remove drupal/ai_content_audit
```

Uninstalling the module may delete stored assessment entities and related data.
Back up or export data before uninstalling in production environments.

---

## Architecture

### Content extraction

Content extractor plugins build assessment payloads from nodes. Extraction can
support field-based and rendered-content workflows, including Layout Builder
content where applicable.

### Assessment service

The assessment service sends content to the configured Drupal AI provider,
processes the provider response, and stores the result as an
`AiContentAssessment` entity.

### AIRO panel

The AIRO integration provides the administrative panel experience for running
assessments and reviewing results from node workflows.

### Audit checks

Technical and filesystem audit checks are implemented through audit check
plugins and supporting services.

### Hooks

Drupal hook implementations are organized as services where appropriate,
including Drupal 11-style hook classes under `src/Hook/`.

---

## Development

Development dependencies and tooling may vary by project setup. Common checks
include PHP syntax validation, Drupal coding standards, DrupalPractice checks,
and the module's automated tests.

Example PHPCS command:

```bash
vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/contrib/ai_content_audit
```

Adjust the path to match the site's docroot and module location.

For contribution guidelines, coding standards, and local development notes, see:

```text
CONTRIBUTING.md
```

---

## Security

Assessments may send node content to external AI providers. Review provider
data handling policies before enabling assessments in production.

Recommended practices:

- restrict module permissions to trusted roles;
- protect API keys and provider credentials;
- use HTTPS in production;
- avoid sending sensitive content to providers that are not approved for that
  data;
- review logs and stored assessment output according to the site's data policy.

For reporting security issues, see:

```text
SECURITY.md
```

---

## Contributing

Issues, feature requests, and merge requests should be opened in the project's
official issue queue or repository.

Before contributing, review:

```text
CONTRIBUTING.md
```
---

## License

GPL-2.0-or-later. See `composer.json` for package license metadata.
