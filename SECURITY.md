# Security policy

## Supported versions

The package is in its `0.x` series. Security fixes go into a new release of the latest `0.x` minor version only, so
upgrade to it; `CHANGELOG.md` lists what changes between minor versions.

| Version | Security fixes |
|---|---|
| latest `0.x` minor | yes |
| older `0.x` minors | no |

## Reporting a vulnerability

Report vulnerabilities privately through a GitHub security advisory of this repository:
[Report a vulnerability](https://github.com/sushi-market/horizon-observability/security/advisories/new) (Security tab,
"Report a vulnerability"). Never open a public issue, pull request or discussion for a vulnerability.

Please include:

- the package version, your PHP, Laravel and Horizon versions and your Redis client (phpredis or predis);
- the configuration involved (`config/horizon-observability.php`, the `HORIZON_OBSERVABILITY_*` variables, any
  `HorizonObservability::auth()` callback), with every secret removed;
- the steps or a proof of concept that reproduce the problem, and what an attacker gains.

Never paste a real token. If you have pasted one anywhere, rotate it: `HORIZON_OBSERVABILITY_TOKEN` takes a
comma-separated list, so the new token can be added before the old one is removed.

The maintainers answer in the advisory, agree on a fix and a disclosure date with you, and publish the advisory with
the release that fixes it. You are credited unless you ask not to be.

## What the package exposes

The package adds these endpoints to the application, under `{horizon.path}/observability` (default
`/horizon/observability`; `HORIZON_OBSERVABILITY_PATH` changes the base path and `HORIZON_OBSERVABILITY_DOMAIN`
restricts the host):

| Endpoint | Content |
|---|---|
| `GET .../metrics` | The Prometheus exposition: queue, connection and supervisor names, job class names, batch names and ids, counts, durations and waits, the Horizon URL and the text-to-speech URL (`horizon_info`), and the versions of the package, PHP, Laravel, Horizon and the Redis client (`exporter_build_info`) |
| `GET .../jobs/{pending,completed,failed,delayed}` | JSON job lists for Grafana: job ids, names, queues, times, attempts and runtimes, and for failed jobs an exception summary (see `exceptions` below) |
| `GET .../live` | The live snapshot JSON for Grafana (when `HORIZON_OBSERVABILITY_LIVE_ENABLED` is true, the default): Horizon status, supervisors and queues with their counts, recent throughput |

Job payloads are never exposed. Exception summaries can still contain data: `full` (the default) shows the short class
and the first line of the message, for example `QueryException: SQLSTATE[...] ...`. Set
`HORIZON_OBSERVABILITY_EXCEPTIONS=class` (class only) or `none` when messages can carry personal data or secrets.

### Access control

Every request is decided before any cache, Redis or database work: a denied request costs no queries.

1. **Callback.** When the application calls `HorizonObservability::auth(fn (Request $request): bool => ...)`, the
   callback alone decides: `true` allows, anything else, including an exception, answers 403.
2. **Built-in policy** otherwise, failing closed:
   - **Tokens.** `HORIZON_OBSERVABILITY_TOKEN` is a comma-separated list (for rotation) accepted on every endpoint.
     `HORIZON_OBSERVABILITY_API_TOKEN`, when set, replaces it on the job lists and `/live`, so Grafana and Prometheus
     can hold different secrets. Tokens are read from the `Authorization: Bearer` header only, never from the query
     string, and compared in constant time with every configured token. Tokens shorter than 16 characters are ignored
     and reported by `php artisan horizon-observability:check`; a setting whose every entry is invalid lets nothing
     pass. A missing or wrong token answers 401 with `WWW-Authenticate: Bearer realm="horizon-observability"`.
   - **IP allow-list.** `HORIZON_OBSERVABILITY_ALLOWED_IPS` (IPs and CIDRs, comma-separated) is checked against
     Laravel's `$request->ip()`, so it depends on a correct TrustProxies configuration. A mismatch answers 403. With
     tokens and IPs configured, both must pass.
   - **Nothing configured:** the endpoints answer only when the application environment is `local`
     (`APP_ENV=local`), like Horizon's default gate, and 403 everywhere else. Never run a reachable deployment with
     `APP_ENV=local`: `check` warns that the endpoints are open only because of it.

Horizon's `viewHorizon` gate, sessions and users are not involved: protecting the Horizon dashboard does not protect
these endpoints. Error bodies are `Unauthorized.` or `Forbidden.` (plain text, or JSON on the job lists and `/live`)
and never name the reason; the reason goes to one throttled info line in the log (endpoint, reason, client IP and
path). There is no default rate limit; add one with
`'middleware' => ['throttle:600,1']` in `config/horizon-observability.php`, which runs after the access decision. The
endpoints stay reachable during `php artisan down` while `available_during_maintenance` is true (the default).

### Secrets

- Tokens are never logged and never part of the output of `check` or `about`, which report them as set or missing.
  The configuration object redacts its secrets when it is dumped.
- `php artisan horizon-observability:install` writes a new token to `.env` (never over an existing value) and, when
  `.env.example` exists, an empty `HORIZON_OBSERVABILITY_TOKEN=` line to it. It prints the token only when it does not
  write it: with `--no-env`, without a `.env` file, or when writing fails.
- `php artisan horizon-observability:stream` sends the Grafana service account token
  (`HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN`) only as the bearer header of its pushes to Grafana Live; the token and
  any `user:password@` of a URL are removed from the error texts it logs and prints.
- Grafana calls the job lists and `/live` server-side through the Infinity datasource, with the token in its
  `secureJsonData`, so the token never reaches a browser. The scrape job that `install` prints reads the token from a
  `credentials_file`.
- The stack of `install --docker` contains no secret: Docker Compose reads the tokens from the application's `.env`.
  Its ports listen on 127.0.0.1 only and its Grafana allows anonymous viewers and `admin`/`admin`: it is for local use,
  never for a server.

### Recommendations

- Set a token before an environment is reachable by anyone else, and give Grafana its own
  `HORIZON_OBSERVABILITY_API_TOKEN`.
- Serve the endpoints on an internal address or domain (`HORIZON_OBSERVABILITY_DOMAIN`) and over TLS: a bearer token
  is sent in clear over plain HTTP.
- Use the IP allow-list in addition to a token, not instead of one, unless TrustProxies trusts only your own proxies.
- Choose `HORIZON_OBSERVABILITY_EXCEPTIONS=class` or `none` when exception messages may hold personal data.
- Spoken alerts (`HORIZON_OBSERVABILITY_TTS_URL` set to a Piper server) send the titles of firing alert rules from
  the viewers' browsers to that server; use one you control.

## Scope

In scope: the package as Composer installs it (`src`, `config`, `routes`, `resources`: the endpoints, the commands,
the Lua scripts, the rendered dashboard and alert rules with their alert sound script) and the files
`install --docker` writes.

Out of scope: the development stack of this repository (`docker-compose.yml`, `workbench/`, which run without
passwords on 127.0.0.1 by design); vulnerabilities of Laravel, Horizon, Redis, Prometheus, Grafana or its plugins,
which belong to their projects; and setups the documentation warns against, such as `APP_ENV=local` on a reachable
server or an IP allow-list behind an untrusted proxy.
