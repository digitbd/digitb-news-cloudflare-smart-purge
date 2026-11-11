# Security Policy

## Reporting a vulnerability

Please do not publish sensitive security details in a public GitHub issue.

For suspected security vulnerabilities, contact the project maintainer privately through the contact information published on the project's official website.

Do not include Cloudflare API tokens, passwords, `wp-config.php` contents, or other secrets in public issues or pull requests.

## Secrets

Cloudflare credentials must be supplied through `wp-config.php` or another secure server-side configuration mechanism. Never commit live credentials to this repository.
