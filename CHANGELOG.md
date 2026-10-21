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
- An RSA key pair or SSL certificate whose files exist was offered for generation again when one of its variables was missing from `.env`; accepting replaced the files, and `.env` could keep a pass phrase that no longer opened the key. Such a variable is now asked like any other, and the pass phrase of an existing key pair is asked without a default, so a run without interaction stops there.
- `#+rsa-pair` and `#+ssl-certificate` looked for their files at the `.env.dist` paths only: a key pair or certificate at the paths set in `.env` was reported as missing configuration on every run, and accepting generated new files at the `.env.dist` paths and wrote those paths to `.env`. The files are now looked for and generated at the paths set in `.env`, or at the `.env.dist` paths when `.env` sets none, and a path set in `.env` is kept.
- `#+rsa-pair`, `#+ssl-certificate` and `#+file-to-propagate` read a `.env.dist` path as Dotenv reads it: quotes and an inline comment are not part of it, and a `${VAR}` in it takes its value from `.env` or from an earlier `.env.dist` default.
- A `#+file-to-propagate` file that existed was reported as missing configuration on every run.
- Without interaction, a question with no default (the RSA pass phrase, the SSL domain name) failed with a `TypeError` in the Composer script and was asked again without end in the console command. It now fails with a `Companienv\IO\UnansweredQuestionException`, a `RuntimeException` that names the question.
- Without interaction, a missing `#+file-to-propagate` file whose variable was not set failed with a `TypeError` in the Composer script and was asked for without end in the console command. Its path is now written and the file stays missing.
- Without interaction, the console command asked again without end for a variable whose default is empty. It now writes the empty value, as the Composer script does.
- In the Composer script, an empty answer to a question with no default failed with a `TypeError`. The question is now asked again, as the console command does.
- A value containing whitespace, `#`, a quote, a backslash or `$` was written without quotes, so Dotenv could not read the `.env` file or read a different value. Such a value is now written in single quotes, or in double quotes when its only quote is a `'` and it has no `\`, `"` or `$`, so Dotenv reads back the value as it was given. A value equal to its `.env.dist` default is written as the file has it when Dotenv can read that default.
- PHP 8.1+ deprecation notices from `jackiedo/dotenv-editor` 1.2.0 and earlier when writing a variable.
- PHP 8.4 deprecations for implicitly nullable parameters.

### Security
- Key paths, the pass phrase and the domain name are passed to openssl as arguments instead of a shell command line. A `$(…)` in a `.env.dist` path that `.env` does not set is still run, now by Dotenv, as in any `.env.dist` default. So is one in a `.env.dist` default that `.env` does not set and that the path uses, directly or through another such default: a path without `$(…)` uses the variables Dotenv reads in it, and one with a `$(…)` uses those written in it as `$NAME` or `${NAME}`, also in quotes or a comment. The commands of the other defaults do not run.
- The domain name is escaped for openssl's subject syntax, so `/`, `+` and `\` in it no longer add subject fields or make openssl fail.
- The RSA pass phrase is passed to openssl on standard input instead of the command line. When key generation fails, the exception no longer wraps Symfony's process exception, whose message named the pass phrase; it carries openssl's error itself.
- A value written to `.env` that contains `$(…)` is no longer run as a shell command when Dotenv reads the file, unless it is the variable's `.env.dist` default.
- `symfony/process` releases with the argument escaping of CVE-2026-24739 (MSYS2 or Git Bash on Windows) are refused, also when `symfony/symfony` provides the package: 5.x before 5.4.51, 6.x before 6.4.33 and 7.4 before 7.4.5. The advisory lists 6.4 only, but 6.0 to 6.3 escape the same way and have no fix. Symfony 3.4 and 4.4 have no fixed release, and their affected releases are still allowed.

[Unreleased]: https://github.com/paysera/fork-sroze-companienv/compare/0.0.12...HEAD
