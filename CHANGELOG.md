# Changelog

## [Unreleased]

### Fixed

- A 2xx answer whose body is not JSON (a proxy's or a maintenance page) is a `ProviderException`
  (`An answer that is not JSON (HTTP 200).`), where it was read as an empty answer.
- With `webhook_secret` set and no `webhook_key`, every webhook is checked by its
  `X-JET-Connect-Hash`: a cancellation, driver, offline or backup-flow body without it was accepted
  unchecked; it is now an `InvalidSignatureException`. Configure `webhook_key` to receive those.
- A `timezone` PHP cannot read is an `InvalidConfigException` (an `\Exception` still), where PHP's
  own exception escaped the factory.
