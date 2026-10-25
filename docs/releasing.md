# Releasing to Packagist

This checklist covers the first release of `skarner2016/rbac`.

## 1. Preconditions

- The GitHub repository is public: <https://github.com/skarner2016/rbac>
- A Packagist account exists and owns the `skarner2016` vendor namespace.
- If your Packagist vendor is different, change `name` in `composer.json` and
  the badge/package URLs in both README files before releasing.

## 2. Verify the package

```bash
composer validate --no-check-publish
composer install
composer test
```

Expected result:

```text
OK (120 tests, 286 assertions)
```

The GitHub Actions workflow runs the same suite on PHP 8.2, 8.3, and 8.4
against SQLite.

## 3. Commit and tag

```bash
git add .
git commit -m "Initial release"
git tag -a v0.1.0 -m "v0.1.0"
git push origin master
git push origin v0.1.0
```

Packagist reads the tag from GitHub. Use semantic version tags (`v0.1.0`,
`v1.0.0`, ...).

## 4. Submit to Packagist

1. Sign in at <https://packagist.org>.
2. Click **Submit**.
3. Enter `https://github.com/skarner2016/rbac`.
4. Confirm the detected package name `skarner2016/rbac`.
5. Submit.

Packagist creates the package and starts reading tags and branches.

## 5. Enable automatic updates

On the package page, add the GitHub webhook (Packagist provides the exact URL
and secret), or use the **Update** button after each push. A GitHub webhook is
recommended so new tags appear automatically.

## 6. Verify the release

```bash
composer require skarner2016/rbac:^0.1 --dry-run
```

Or in a scratch directory:

```bash
mkdir /tmp/rbac-check && cd /tmp/rbac-check
composer init --no-interaction --name=test/check
composer require skarner2016/rbac:^0.1
```

Check that:

- `vendor/skarner2016/rbac` exists
- the package installs without runtime dependencies
- the README and LICENSE are included
- tests and docs are excluded from the dist archive (`.gitattributes`)

## 7. Release checklist

- [ ] `composer validate` passes
- [ ] `composer test` passes
- [ ] `LICENSE` is present and matches `composer.json`
- [ ] `README.md` quick start is accurate
- [ ] GitHub Actions is green on PHP 8.2/8.3/8.4
- [ ] Tag `v0.1.0` is pushed
- [ ] Packagist package page shows the tag and README
- [ ] `composer require` works in a scratch project

## Notes

- `composer.lock` is intentionally ignored: this is a library, not an
  application.
- `vendor/` is never committed.
- The package name in `composer.json` must stay `skarner2016/rbac` for the
  badges and install command in the README to work.
