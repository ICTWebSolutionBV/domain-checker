## What and why

<!-- What changes, and what breaks without it. If it fixes a defect, say what
     the wrong behaviour was -- the next person reads this before the diff. -->

## Verification

<!-- How you know. A test name, a command and its output, a measurement.
     "Tested locally" is not verification. -->

## Checklist

- [ ] `php artisan test` passes (PHP 8.4)
- [ ] `composer lint` passes (Pint + PHPStan; the baseline did not grow)
- [ ] `npm run lint && npm run format:check && npm run build` pass if the frontend changed
- [ ] Behaviour changes have a test that fails without the fix
- [ ] CHANGELOG.md updated under `[Unreleased]` if a user would notice
