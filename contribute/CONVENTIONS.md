# CitOmni Coding & Documentation Conventions

This page is a stable entry point for CitOmni's shared coding and documentation rules. The maintained, organization-wide source of truth is:

**[CitOmni Coding & Documentation Conventions](https://github.com/citomni/.github/blob/main/CONVENTIONS.md)**

To avoid conflicting instructions, this page intentionally does not duplicate the complete convention set. Make changes to organization-wide rules in the canonical document rather than maintaining a second version here.

## Quick orientation

- Current maintained CitOmni framework packages target **PHP 8.5 or later**. Check the target package's `composer.json` for its exact requirements.
- Follow **PSR-1/PSR-4**, use `declare(strict_types=1);`, indent PHP with tabs, and place opening braces on the same line (K&R style).
- Treat **`final` as an intentional design choice**, not something universally required or discouraged. Use the canonical guidance on extensibility and public contracts.
- Follow **the target repository's license and copyright requirements**. Do not copy a GPL SPDX header or other license notice from an unrelated example.
- Prefer explicit, deterministic behavior, clear responsibility boundaries, meaningful documentation, and low runtime overhead.

This summary is only an orientation. The [canonical conventions](https://github.com/citomni/.github/blob/main/CONVENTIONS.md) contain the full requirements and explanations.

## Source of truth and repository-specific rules

- The target repository's current source, tests, `composer.json`, and public contracts establish its actual behavior and supported runtime requirements.
- The canonical conventions define shared engineering and documentation expectations across CitOmni.
- Repository-specific contribution instructions may add requirements for testing, release processes, security, and public APIs.
- When an older example contradicts current implementation, verify the source and correct the documentation rather than treating the example as authoritative.

## Related documents

- [Organization-wide contribution guide](https://github.com/citomni/.github/blob/main/CONTRIBUTING.md)
- [CitOmni Testing Guide](./testing-guide.md)
- [CitOmni Code Documentation Guide](./code-documentation-guide.md)
- [CitOmni Architecture](../concepts/architecture.md)
- [Licensing Overview](https://github.com/citomni/.github/blob/main/LICENSING.md)
- [Pull Request Template](https://github.com/citomni/.github/blob/main/PULL_REQUEST_TEMPLATE.md)
