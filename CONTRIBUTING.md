# Contributing to AI Content Auditor (`ai_content_audit`)

Thank you for helping improve this module. The following guidelines keep
reviews predictable for a Drupal contrib project.

---

## Where to start

1. Open an issue (or internal ticket) describing the bug or feature **before**
   large refactors.
2. Keep merge requests focused: one concern per branch when possible.
3. Follow **Drupal coding standards** and **DrupalPractice** for PHP, YAML, and
   JavaScript touched by your change.

---

## Local development

- Install the module on a Drupal 10 or 11 site with **Drupal AI** and at least
  one chat provider configured.
- Enable **Layout Builder** if you work on HTML extraction or LB-related code
  paths (`layout_builder` is a declared dependency).

---

## Coding standards

Run PHPCS with the Drupal standard on the paths you change (adjust the path to
your docroot):

```bash
vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/contrib/ai_content_audit
```

Fix new violations in files you touch; avoid unrelated clean-up in the same
commit unless agreed with maintainers.

---

## Tests

PHPUnit tests live under `tests/`. Run them using the same configuration as
your host project (for example `phpunit.xml` at the repository root or inside
`core/`). Add or extend tests when you fix behaviour that can regress.

---

## Documentation

- **End users and site builders** — `README.md` stays free of internal backlog
  and alarmist language.
- **Maintainers / release candidates** — see `docs/CONTRIB_READINESS.md` for a
  checklist of items to close before or after Drupal.org submission.

---

## Security reports

Do **not** open public issues for security-sensitive findings. Follow
`SECURITY.md`.

---

## License

Contributions are accepted under the same license as the project: **GPL-2.0-or-later**
(see `composer.json`).
