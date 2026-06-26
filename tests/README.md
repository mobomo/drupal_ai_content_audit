# Running tests

PHPUnit tests live under `tests/` and submodule `tests/` directories. Run them
with this module's configuration so the bootstrap can locate Drupal core from a
contrib checkout or a standalone `ddev-drupal-contrib` workspace.

## From a full Drupal site (e.g. mobomo2025 / DDEV)

```bash
vendor/bin/phpunit -c web/modules/contrib/ai_content_audit/phpunit.xml.dist
```

Adjust the path if your docroot is named `docroot/` instead of `web/`.

Optional group filter:

```bash
vendor/bin/phpunit -c web/modules/contrib/ai_content_audit/phpunit.xml.dist --group ai_content_audit
```

## From ddev-drupal-contrib (module repo checkout)

```bash
ddev poser
ddev symlink-project
ddev phpunit -c phpunit.xml.dist
```

Unit tests mock `AiProviderRegistryInterface`, our own facade over Drupal AI's
final plugin manager, so no extra test-only Composer packages are required.
