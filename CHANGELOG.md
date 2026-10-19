# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Support for Symfony 6 and 7.4.
- A PHPUnit test suite.
- A GitHub Actions workflow that runs the tests on every PHP and Symfony combination it lists, with the lowest and the highest dependencies.

### Changed
- PHP 7.1 or later is required. `symfony/process` 6 is required at 6.4.33 or later, which needs PHP 8.1.
- `symfony/console`, `symfony/dotenv` and `symfony/process` 4.4 or later are required on the 4.x line.
- `jackiedo/dotenv-editor` 1.1.1 or later is required.
- The development dependencies install on every PHP and Symfony combination the workflow runs.
- The console command accepts an empty answer to a question whose default is empty, as the Composer script does, instead of asking again.
- `#+file-to-propagate` looks for the file at the path set in `.env`, or at the `.env.dist` path, as Dotenv reads it, when `.env` sets none. A file that is there is kept, also when `.env` does not set the variable. A missing one is asked for, also when the variable is set, and copied to that path; without interaction, it stays missing, a path set in `.env` is left as it is and an empty one gets the `.env.dist` path. A variable with no path in either file is asked like any other variable.
- A typed value that starts and ends with a quote keeps its quotes: `'abc'` now reads back as `'abc'`, where it read back as `abc`. A quoted `.env.dist` default, kept with Enter or typed again exactly, is still written as the file has it when Dotenv can read it, and so reads back without its quotes.
- The RSA pass phrase is asked again, with the reason, unless it is 4 to 1,023 bytes long. openssl 1.1.1d refuses a shorter one, which made key generation fail, and openssl reads at most 1,023 bytes of a pass phrase on standard input, where it now goes; on OpenSSL 3, 0.0.12 took a longer one on the command line.

### Fixed
- The `companienv` console command failed on Symfony 5 and later.
- RSA key and SSL certificate generation failed with `symfony/process` 5 and later.
- Declining RSA key or SSL certificate generation asked the same question again for the other variables of the pair.
- A `#+file-to-propagate` file that existed was reported as missing configuration on every run.
- Without interaction, a question with no default (the RSA pass phrase, the SSL domain name) failed with a `TypeError` in the Composer script and was asked again without end in the console command. It now fails with a `Companienv\IO\UnansweredQuestionException`, a `RuntimeException` that names the question.
- Without interaction, a missing `#+file-to-propagate` file whose variable was not set failed with a `TypeError` in the Composer script and was asked for without end in the console command. Its path is now written and the file stays missing.
- Without interaction, the console command asked again without end for a variable whose default is empty. It now writes the empty value, as the Composer script does.
- In the Composer script, an empty answer to a question with no default failed with a `TypeError`. The question is now asked again, as the console command does.
- A value containing whitespace, `#`, a quote, a backslash or `$` was written without quotes, so Dotenv could not read the `.env` file or read a different value. Such a value is now written in single quotes, or in double quotes when its only quote is a `'` and it has no `\`, `"` or `$`, so Dotenv reads back the value as it was given. A value equal to its `.env.dist` default is written as the file has it when Dotenv can read that default.
- PHP 8.1+ deprecation notices from `jackiedo/dotenv-editor` 1.2.0 and earlier when writing a variable.
- PHP 8.4 deprecations for implicitly nullable parameters.

### Security
- Key paths, the pass phrase and the domain name are no longer interpreted by a shell.
- The domain name is escaped for openssl's subject syntax, so `/`, `+` and `\` in it no longer add subject fields or make openssl fail.
- The RSA pass phrase is passed to openssl on standard input instead of the command line. When key generation fails, the exception no longer wraps Symfony's process exception, whose message named the pass phrase; it carries openssl's error itself.
- A value written to `.env` that contains `$(…)` is no longer run as a shell command when Dotenv reads the file, unless it is the variable's `.env.dist` default.
- `symfony/process` releases with the argument escaping of CVE-2026-24739 (MSYS2 or Git Bash on Windows) are refused, also when `symfony/symfony` provides the package: 5.x before 5.4.51, 6.x before 6.4.33 and 7.4 before 7.4.5. The advisory lists 6.4 only, but 6.0 to 6.3 escape the same way and have no fix. Symfony 3.4 and 4.4 have no fixed release, and their affected releases are still allowed.

[Unreleased]: https://github.com/paysera/fork-sroze-companienv/compare/0.0.12...HEAD
