# Contributing

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# create the database named in .env, then:
php artisan migrate
npm run build
php artisan serve
```

`.env.example` defaults to MySQL. The test suite uses its own in-memory SQLite
database (`phpunit.xml`), so it needs no database of yours.

## Checks

| Command | What it covers |
| --- | --- |
| `php artisan test` | The suite. Run it on **PHP 8.4** — 8.5 turns some reflection into deprecations. |
| `composer lint` | Pint (formatting) and PHPStan (level 5). |
| `npm run lint` | ESLint over `resources/js`. |
| `npm run format:check` | Prettier over `resources/js` and `resources/css`. |
| `npm run build` | The production bundle. |

CI runs all of it on every push and pull request, on PHP 8.4 and 8.5.

### The PHPStan baseline

`phpstan-baseline.neon` holds the findings that existed when static analysis was
introduced, each one analyser blindness rather than a defect, with the causes
documented at the top of the file.

**A pull request may not grow it.** Fix the finding, or annotate the type the
analyser is missing — adding `@property` blocks to the models is what took the
count from 40 to 31.

## Definition of done

1. The behaviour is verified, not assumed: a test that fails without the change,
   or a measurement in the pull request body.
2. Every check above passes.
3. A defect fix names the wrong behaviour it replaces, so the next person can
   tell whether a future change reintroduces it.
4. A user-visible change has a `CHANGELOG.md` entry under `[Unreleased]`.
5. Anything that needs a server-side change to take effect — a new worker, a
   scheduler entry, an environment variable — says so in the pull request.
   Production runs one queue worker on connection `database`, queue `default`:
   a job dispatched with `onQueue(...)` or to another connection is silently
   never processed, so keep jobs there or ask for a worker first.

## Commit messages

Imperative mood, and say **why**. A commit that only says what the diff already
shows costs the next reader a bisect. No attribution trailers.

## Releases

Versioning is semver, recorded in `CHANGELOG.md` and the README badge.
Releases 1.7.0 through 1.11.1 were never tagged, so a `git pull`-based deploy
has nothing to roll back to — tag before relying on rollback.
