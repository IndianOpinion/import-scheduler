# Security policy

## Reporting a vulnerability

Please email hello@indianopinion.org with a description, the affected
version and, if you can, steps to reproduce. Do not open a public issue for a
security problem.

We acknowledge reports within 3 working days and aim to ship a fix, or a
clear plan, within 30 days. We credit reporters in the release notes unless
you ask us not to.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Scope notes

- The plugin exposes no REST route and no AJAX action that runs a rule.
- Screen actions need the `manage_options` capability (filterable) and a nonce.
- Run messages and logs are treated as untrusted text and escaped.
- The reference feed adapter fetches only URLs a developer registered, through core's safe HTTP layer.
