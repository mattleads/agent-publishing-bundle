# Security Policy

## Supported versions

The latest minor release receives security fixes.

## Reporting a vulnerability

Please do **not** open a public issue for security problems. Email matthew.mochalkin@gmail.com with a description, reproduction steps and, if possible, a suggested fix. You will receive an acknowledgement within 72 hours and a fix or mitigation plan within 14 days for confirmed issues. Credit is given in the release notes unless you prefer otherwise.

## Scope notes

- WebAuthn ceremonies themselves are implemented by `web-auth/webauthn-lib`; report issues in the ceremony validation there.
- Recovery codes: bcrypt-hashed with a peppered HMAC lookup key; single use; CSRF-protected regeneration. Online guessing must be limited with Symfony's `login_throttling`.
- The login-flow endpoint is designed not to confirm account existence (`unknown_user_flow: password`).
