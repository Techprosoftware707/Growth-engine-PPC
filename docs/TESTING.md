# Testing

## Unit tests (no WordPress needed)

```bash
bash bin/lint.sh
bash bin/test.sh          # or: php bin/phpunit.phar
```

`tests/bootstrap.php` provides WordPress function stubs, an in-memory SQLite-backed `wpdb` replacement (`tests/Support/FakeWpdb.php`) that creates the plugin schema from the real dbDelta statements, and an HTTP fixture registry (`tests/Support/HttpFixtures.php`) that serves `tests/fixtures/sites/*` for `https://{domain}/…` URLs. Fixture sites: a Canadian laboratory directory with a listing form, a university resource page with broken links, a blog with linked/unlinked brand mentions, and a spam/PBN site.

## End-to-end run against a real WordPress

1. Create a throwaway WordPress install (MySQL or the SQLite Database Integration plugin), install the plugin (symlink `ppc-link-growth-engine/` into `wp-content/plugins/`).
2. Add a must-use plugin that routes `*.test` hosts to the fixture server (see `tests/integration/mu-plugin-example.php`).
3. Start the fixture server:
   ```bash
   PPCLG_FIXTURE_LOG=/tmp/ppclg-fixture-submissions.log php -S 127.0.0.1:8788 tests/fixtures/router.php
   ```
4. Run:
   ```bash
   PPCLG_WP_PATH=/path/to/wordpress PPCLG_FIXTURE_LOG=/tmp/ppclg-fixture-submissions.log php tests/integration/run.php
   ```

The run activates the plugin, discovers the fixture sites through an offline search provider, evaluates and scores them, packages and (after explicit authorisation) submits a listing to the fixture form, verifies the listing, monitors backlinks, drafts/approves/sends outreach (captured, not delivered), processes a reply through the inbound webhook and an opt-out, exercises the REST API as admin and anonymous, ticks the scheduler and creates a backup. It exits non-zero on any failed check.
