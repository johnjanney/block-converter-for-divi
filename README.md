# Block Converter for Divi

WordPress plugin that converts pages built with the Divi Builder into native
Gutenberg blocks — with preview, batch conversion, and one-click restore.

Requires WordPress 6.1+ and PHP 7.4+.

- [Installation and usage](INSTRUCTIONS.md)
- [Release history and versioning policy](CHANGELOG.md)
- [Project brief](BRIEF.md)
- [Open questions](OPENQUESTIONS.md)
- [External review](CODEX-REVIEW.md) and [response](CODEX-REVIEW-RESPONSE.md)

Previously released as *Divi to Gutenberg Converter* (`divi2gutenberg`).

## Tests

```bash
php tests/run.php        # no WordPress install needed
```

`bin/build-zip.sh` runs the suite and refuses to package if it fails.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
