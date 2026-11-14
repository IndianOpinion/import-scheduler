# Contributing

Thanks for helping. Bug reports, fixes and small focused features are welcome.

## Set up

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/import-scheduler.git
cd import-scheduler
composer install
```

No WordPress install is needed for the tests: a small stub layer in
`tests/stubs/` stands in for the WordPress functions the plugin calls, and an
in-memory double stands in for the database.

## Tests and checks

```sh
composer test      # PHPUnit
composer analyse   # PHPStan, level 8 on src/
composer lint      # PHPCS, PSR-12
```

All three must pass before a pull request is merged. CI runs them on PHP 8.1,
8.2 and 8.3.

The MySQL lock race test is skipped unless you point it at a throwaway
database:

```sh
IMPORT_SCHEDULER_TEST_DSN='mysql:host=127.0.0.1;dbname=test' \
IMPORT_SCHEDULER_TEST_USER=root IMPORT_SCHEDULER_TEST_PASSWORD=secret \
composer test
```

It creates and drops tables with the prefix `istest_`.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file, no PHP 8.2+ syntax.
- Typed exceptions, never silent failure, no `@` error suppression, no `eval`, no shell.
- Escape every output on the screen; nonce and capability checks on every action.
- Every behaviour change comes with a test that names the PRD case it covers.
- Prose: short sentences, plain words, British spelling.

## Pull requests

- One change per pull request, with a clear description of what and why.
- Update `CHANGELOG.md` under "Unreleased".
- Update `README.md` and `docs/ARCHITECTURE.md` when behaviour or a decision changes.
- A DCO-style sign-off is not required.

By contributing you agree that your contribution is licensed under the MIT
licence of this project.
