# Contributing

Thanks for considering a contribution.

## Setup

```bash
git clone https://github.com/mattleads/agent-publishing-bundle.git
cd agent-publishing-bundle
composer install
composer check
```

## Guidelines

- Open an issue first for anything beyond a small fix, so we can agree on the approach.
- Keep pull requests focused; one change per PR.
- Add or update tests for behaviour changes (`tests/Unit`, `tests/Functional`).
- Run `composer check` (CS, PHPStan level 8, PHPUnit) before pushing — CI runs the same on PHP 8.2–8.4 and Symfony 6.4–8.x.
- Add an entry to `CHANGELOG.md` under *Unreleased*.
- Changes to authentication, recovery codes or storage must come with unit tests and, where wiring changes, a kernel test.

## Reporting bugs

Please include PHP and Symfony versions, the webauthn-symfony-bundle version, your `mattleads_passkeys` and firewall configuration, and browser + authenticator type for ceremony issues.
