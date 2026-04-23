# AI Content Auditor

Score and improve your Drupal node content's quality and AI-readiness — powered by the [Drupal AI module](https://www.drupal.org/project/ai).

---

> **⚠ Pre-release notice — `0.0.1` / not yet on drupal.org**
>
> This module is under active development. The `0.0.1` release is a **pre-release**
> version. Public APIs, hook signatures, configuration schema, and entity
> structures **may change without a deprecation period** until `1.0.0` is tagged.
> Pin to a specific git tag in production and review the changelog before
> upgrading.

---

## Overview

AI Content Auditor assesses Drupal node content against a configurable rubric
and returns a quality score (0–100) plus structured improvement suggestions. It
integrates with the [Drupal AI module](https://www.drupal.org/project/ai)
abstraction layer so it works with any compatible LLM backend (OpenAI, Ollama,
Anthropic, etc.) without vendor lock-in. Results are stored as a custom content
entity so score history is preserved per node over time.

---

## Requirements

| Requirement | Version |
|---|---|
| Drupal core | `^10.3 \|\| ^11` |
| PHP | `^8.1` |
| Composer | `^2.0` |
| [drupal/ai](https://www.drupal.org/project/ai) | `^1.0` (must be configured with at least one provider) |

An AI provider sub-module must also be enabled and configured — for example
`drupal/ai_provider_openai`, `drupal/ai_provider_ollama`, or another
`drupal/ai`-compatible integration.

---

## Installation — pre-drupal.org

Because this module is not yet listed on drupal.org, the standard
`composer require drupal/ai_content_audit` workflow is **not** available yet.
Use one of the three methods below.

### Method A — Composer VCS (git tag) — recommended

**Step 1.** Add a `repositories` entry to your site's root `composer.json`.
Replace the placeholder URL with your actual git repository URL.

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/ednark/drupal_ai_content_audit.git"
    }
  ],
  "require": {
    "drupal/ai_content_audit": "0.0.1"
  }
}
```

> **Replace** `https://github.com/ednark/drupal_ai_content_audit.git` with the
> real repository URL before running Composer.

**Step 2.** Install:

```bash
composer require drupal/ai_content_audit:0.0.1
```

Composer will resolve the `0.0.1` git tag, download the module into
`web/modules/contrib/ai_content_audit/` (or wherever `drupal-module` type
packages are mapped in your `composer.json` `extra.installer-paths`), and add
it to `composer.lock`.

---

### Method B — Composer VCS (dev branch / bleeding edge)

Use the same `repositories` entry as Method A, then require the development
branch:

```bash
composer require drupal/ai_content_audit:dev-main
```

Or, if your site already has `minimum-stability: stable`, append the `@dev`
flag to bypass the stability gate without changing your global stability:

```bash
composer require "drupal/ai_content_audit:dev-main@dev"
```

If you track `1.0.x-dev` instead of `main`:

```bash
composer require "drupal/ai_content_audit:1.0.x-dev@dev"
```

> **Note:** Dev-branch installs do not get a pinned version in `composer.lock`.
> Every `composer update drupal/ai_content_audit` will pull the latest commit
> from that branch. Use this only in development or staging environments.

---

### Method C — Manual download (no Composer)

1. Clone or download the repository:

   ```bash
   git clone https://github.com/ednark/drupal_ai_content_audit.git \
     web/modules/custom/ai_content_audit
   ```

   Or download a ZIP/tarball and extract it to
   `web/modules/custom/ai_content_audit/`.

2. Ensure `drupal/ai` and its dependencies are already installed via Composer
   (they **will not** be pulled automatically without Composer):

   ```bash
   composer require drupal/ai
   ```

3. Enable the module (see [Enabling the Module](#enabling-the-module) below).

> **Caveat:** Manual installs bypass Composer's autoloader registration and
> dependency resolution. If `drupal/ai` or other dependencies are missing,
> Drupal will throw class-not-found errors. Prefer Method A or B in any
> environment where Composer is available.

---

## Enabling the Module

Via Drush (recommended):

```bash
drush en ai_content_audit
drush cr
```

Via the admin UI: navigate to `/admin/modules`, search for **AI Content
Auditor**, tick the checkbox, and click **Install**.

---

## Configuration

1. **Configure an AI provider** at `/admin/config/ai/providers`. At least one
   provider (e.g. OpenAI) must be set up and tested before the auditor can
   generate assessments.

2. **Configure the module** at `/admin/config/ai/content-audit`
   (`ai_content_audit.settings` route). Options include selecting which node
   types to audit, default provider/model, on-save assessment behaviour, and
   score display settings.

### Permissions

| Permission | Intended for |
|---|---|
| `view ai content assessment` | Editors — read-only access to results |
| `run ai content assessment` | Editors — trigger assessments |
| `administer ai content audit` | Admins — full settings access |

---

## Usage

### Manual assessment (UI)

1. Open any existing node's edit form.
2. Click the **"AI Assessment"** sidebar tab (AIRO panel).
3. Click **"Assess Now"**.
4. View the score and suggestions live.
5. See full history at `/node/{nid}/ai-assessment`.

### Drush

```bash
# Assess a single node synchronously
drush ai_content_audit:assess --nid=42

# Enqueue all configured node types for background assessment
drush ai_content_audit:assess --all

# Enqueue only article nodes
drush ai_content_audit:assess --all --type=article

# Process the assessment queue
drush queue:run ai_content_audit_assessment
```

> **Note:** The deprecated `drush ai_content_audit:site-audit` command has been
> removed. Use `drush ai_content_audit:assess --all` to enqueue bulk
> assessments.

---

## Uninstallation

```bash
drush pmu ai_content_audit
drush cr
```

Or navigate to `/admin/modules/uninstall` and uninstall **AI Content Auditor**.

After uninstalling, clean up your `composer.json` if you used Method A or B:

1. Remove the `repositories` entry pointing at the git URL.
2. Remove `"drupal/ai_content_audit"` from the `require` block.
3. Run `composer remove drupal/ai_content_audit` if it wasn't removed
   automatically, then `composer update --lock`.

> **Data note:** Uninstalling the module will **delete all stored
> `ai_content_assessment` entities** and drop the associated database tables.
> Export or back up any assessment data you want to keep before uninstalling.

---

## Features

- **AI Readiness Score (0–100)** for any node
- **Structured assessment** covering readability, SEO signals, content completeness, tone, and improvement suggestions
- **Assessment history** per node accessible via a dedicated tab (`/node/{nid}/ai-assessment`)
- **AIRO panel** — AJAX-driven sidebar panel on node edit forms with score, action items, technical audit, and preview tabs
- **Inline score widget** — lightweight score badge embeddable on node view pages
- **Block plugin** for displaying the latest score on node view pages
- **On-save background assessment** via Drupal Queue API (optional, disabled by default)
- **Drush command** for bulk and targeted assessment
- **Provider-agnostic** — works with any `drupal/ai`-compatible LLM backend
- **Sub-module included:** `ai_site_audit` — site-wide content type rollup dashboard

---

## Architecture

```
FieldExtractor          → reads all displayable text fields from a node
AiAssessmentService     → calls ai.provider chat(), parses JSON response, saves entity
AiContentAssessment     → custom content entity storing scores, JSON result, raw output
AiResponseSubscriber    → subscribes to ai.post_generate_response for logging
AiAssessmentBlock       → displays latest score on node view pages
AiAssessmentController  → history tab on node canonical
AiroPanelController     → AIRO sidebar panel (open, assess, status, widget-refresh)
AiAssessmentQueueWorker → background cron processing
SettingsForm            → /admin/config/ai/content-audit configuration
AiContentAuditCommands  → drush aca command
AuditCheckManager       → plugin manager for technical and filesystem audit checks
TechnicalAuditService   → runs Technical AuditCheck plugins against a node's site context
FilesystemAuditService  → runs Filesystem AuditCheck plugins (site-wide checks)
```

---

## Developer Notes

- All AI calls go through `drupal/ai`'s `ProviderProxy` — pre/post events fire automatically.
- JSON recovery: 3-stage parse (direct → strip markdown fences → regex extract).
- Cache invalidation: `ai_content_assessment_list:node:{nid}` custom cache tag.
- On-save queueing is off by default to avoid unexpected API costs.
- Technical and filesystem checks are implemented as `AuditCheck` annotated plugins under `src/Plugin/AuditCheck/`.

---

## Versioning & Releases

This module follows [Semantic Versioning](https://semver.org/). Versions
**below `1.0.0`** are pre-release and may include breaking changes to APIs,
configuration schema, or entity structure without a formal deprecation cycle.
Once the module stabilises, tagged stable releases will be made on the
`1.0.x` branch. Breaking changes after `1.0.0` will be accompanied by a major
version bump and a migration path.

---

## Roadmap to drupal.org

Once this module is reviewed, approved, and published on drupal.org, the VCS
`repositories` entry in your `composer.json` can be removed and installation
will switch to the standard Composer workflow:

```bash
composer require drupal/ai_content_audit
```

At that point the git-based `repositories` entry is no longer needed and can
be deleted from your site's `composer.json`.

---

## Contributing / Issues / License

**Issues & contributions:** Please open issues and pull requests on the project
repository at `https://github.com/ednark/drupal_ai_content_audit/issues`.

**License:** [GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
