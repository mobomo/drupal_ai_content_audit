# Running tests

Unit and kernel tests for this module require **PHPUnit 11** (from `drupal/core-dev` or
`ddev-drupal-contrib`) and **`dg/bypass-finals`**, because several Drupal AI classes
used in mocks are declared `final`.

## From a full Drupal site (e.g. mobomo2025 / DDEV)

1. Install dev dependencies at the **project root** (not inside this module):

   ```bash
   composer require --dev dg/bypass-finals:^1.9
   ```

2. Run PHPUnit with **this module's** config (do not use core's default bootstrap only):

   ```bash
   vendor/bin/phpunit -c web/modules/contrib/ai_content_audit/phpunit.xml.dist
   ```

   Adjust the path if your docroot is named `docroot/` instead of `web/`.

3. Optional: run only this group's tests:

   ```bash
   vendor/bin/phpunit -c web/modules/contrib/ai_content_audit/phpunit.xml.dist --group ai_content_audit
   ```

## From ddev-drupal-contrib (module repo checkout)

```bash
ddev poser
ddev symlink-project
ddev phpunit -c phpunit.xml.dist --group ai_content_audit
```

`composer.json` in this repo already lists `dg/bypass-finals` under `require-dev`.
