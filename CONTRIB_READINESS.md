# Contrib readiness checklist (maintainers)

Internal backlog from a contrib-readiness review. **Not** aimed at end users.
Track items as issues and close them before or shortly after a Drupal.org
submission.

---

## Must fix before Drupal.org / strict review

1. **README completeness (`README.md`)**  
   - [ ] Keep updated when permissions, routes, or environment expectations change.
   - [ ] Add project and issue queue URLs once the Drupal.org project exists.
   - [ ] Point `SECURITY.md` at the official security advisory policy when published.

2. **Config schema vs. runtime config**  
   - [ ] `AuditCheckManager::getEnabledCheckIds()` reads **`disabled_checks`**
     from `ai_content_audit.settings`, but that key is **not** in
     `config/install/ai_content_audit.settings.yml` nor in
     `config/schema/ai_content_audit.schema.yml`.  
     **Action:** Add schema + default (empty array), export config, and optionally
     expose the list in `SettingsForm` if product owners need to disable checks.

3. **Route permission model (`+` = AND)**  
   - [ ] Several routes require **two** permissions with `+` (e.g. `view ai content
     assessment+administer ai content audit`). Confirm this is intentional; if
     editors should open the panel with only `view` or run with only `run`,
     switch to comma-separated **OR** or split routes.  
   - [ ] Document the intended role matrix in `README.md` or in `permissions.yml`
     descriptions.

4. **Node (and revision) access**  
   - [ ] Panel/assessment routes validate module permissions but should also
     enforce **`$node->access('view', $account)`** (and revision access where
     applicable) before returning HTML, JSON, or extracted content.  
   - [ ] Review `AiroPanelController::resolveNodeRevisionFromRequestBody()` for
     draft revisions: ensure the current user may view or update that revision.

5. **Twig `|raw` on tab panes**  
   - [ ] `templates/ai-airo-panel.html.twig` outputs `{{ pane_html|raw }}`.  
     **Action:** Document that all pane HTML must come from trusted render arrays;
     or refactor to avoid `raw` if any pane can include user- or provider-controlled
     markup in the future.

6. **`PATCHES.txt` in the module directory**  
   - [ ] If present from Composer Patches on a consumer site, do not ship it in the
     Drupal.org tarball. Site-specific patch metadata should not appear as part of
     upstream sources.

7. **`composer.json` vs `info.yml` dependencies**  
   - [ ] `ai_content_audit.info.yml` requires `drupal:layout_builder`; ensure
     `composer.json` `require` reflects every runtime dependency you expect
     Packagist installers to resolve.

---

## Should fix soon

1. **CSRF on custom `fetch()` POST**  
   - [ ] `js/ai-airo-panel.js` POSTs to assess URLs with `credentials: 'same-origin'`
     but without `X-CSRF-Token`. Consider reading `drupalSettings` / meta
     `csrf-token` and sending the header, or routing through Form API / `drupal.ajax`.

2. **`\Drupal::request()` in `AiroPanelController`**  
   - [ ] Replace with injected `RequestStack` or request object for easier testing
     and DrupalPractice compliance.

3. **`ai_content_audit.airo_panel_controller` service factory**  
   - [ ] Uses `@service_container`. Prefer explicit constructor arguments unless
     there is a documented reason not to.

4. **Internal ticket jargon in repo**  
   - [ ] Trim “G5 / G10 / Phase-2” style comments from `routing.yml`, `libraries.yml`,
     and JS file headers where they do not help external contributors.

5. **Unused or stub routes**  
   - [ ] `ai_content_audit.panel.available_models` is documented as a stub in
     routing comments — implement, wire from UI, or remove before stable release.

6. **Automated tests**  
   - [ ] Expand PHPUnit coverage: permissions, route access, config schema import,
     and at least one assessment happy-path (kernel or functional as appropriate).

7. **`SettingsForm` `#markup`**  
   - [ ] Audit that all interpolated values are safe placeholders; prefer render
     arrays over raw HTML strings where practical.

---

## Nice to have

- [ ] Align marketing name (“AI Content Auditor” in `info.yml`) with repository
  naming if desired.
- [ ] Omit `version:` from `info.yml` in VCS if you follow Drupal.org packaging
  conventions.
- [ ] Resolve TODO in `templates/ai-airo-panel.html.twig` (per-node latest report
  route).
- [ ] Add `LICENSE.txt` at module root if required by your packaging pipeline
  (composer already declares GPL-2.0-or-later).
- [ ] CI: PHPCS, PHPStan (if adopted), and `composer validate` on merge requests.

---

## Do not change lightly (high regression risk)

- Large prompt strings and scoring rubric in `AiAssessmentService` — wording
  changes alter assessment output.
- `FilesystemAuditService` / `TechnicalAuditService` and individual
  `AuditCheck` plugins — security-sensitive behaviour and large surface area;
  require dedicated review + tests before refactors.
- Deep changes to the **AI** module integration (provider operations, chat
  payloads) without compatibility testing across `drupal/ai` releases.
