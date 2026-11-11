# Security policy

## Reporting a vulnerability

If you believe you have found a security vulnerability in MyAPES Account (`APESCIC/MyAPES-Account`), report it privately so repository administrators can review it before any public disclosure.

**Use GitHub private vulnerability reporting:**

[Report a vulnerability](https://github.com/APESCIC/MyAPES-Account/security/advisories/new)

That form creates a private security advisory visible only to repository administrators. You can also open **Security → Advisories → Report a vulnerability** on this repository.

Do not open a public GitHub issue, discussion, or pull request for a suspected vulnerability. Do not describe suspected vulnerabilities in the public Change Log Hub or in public release notes.

This repository does not publish a separate security-contact email. GitHub private vulnerability reporting is the supported path to repository administrators.

## What to include

Please include:

- A short description of the issue and its impact
- Steps to reproduce, or a proof of concept, if you have one
- Affected versions or environments, if known
- Any suggested mitigation

Do not include credentials, personal data, or production secrets in the report.

## Supported versions

Security reports are accepted for the current released MyAPES Account version on `main` (see [`VERSION`](VERSION)). Older beta versions may not receive a separate patch.

## Public issues and change log

Use [public issues](https://github.com/APESCIC/MyAPES-Account/issues) only for non-security bugs and feature requests. The Change Log Hub records released, public-safe changes. It is not a channel for vulnerability reports.

## Triage (automated scans)

Pull requests run GitHub's free security checks: [CodeQL](.github/workflows/codeql.yml) (Actions and JavaScript; CodeQL has no PHP support), [dependency review](.github/workflows/dependency-review.yml), and Dependabot alerts and security updates. PHP is covered by PHPUnit, the architecture tests and Pint.

When a finding lands:

1. **Confirm** it is real in this codebase (not a committed test fixture, not a rule mismatch).
2. **Fix** on the current milestone branch when it is actionable, or open a tracked issue in the next security/hotfix milestone.
3. **False positives** (for example a test-only `APP_KEY` that matches a Telegram-key rule): dismiss it as a false positive in the code scanning alert with a short reason, and still harden the fixture in git when that is cheap.
4. **Severity / response**: treat High/Critical production exposure as a hotfix candidate (Bambie's written approval on the hotfix issue). Medium/Low ride the next planned milestone unless they block a release.

Do not paste secrets, production `APP_KEY` values, or personal data into issues, pull requests or alert comments.
