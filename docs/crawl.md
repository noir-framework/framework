# Crawling for errors

`vendor/bin/noirapi crawl crawl.json [--start] [--port N] [--host H] [--config NAME] [--no-clean]`

The crawler requests pages and reports every notice, warning, deprecation and exception Tracy logged
while serving them. The server being crawled must run with `NOIRAPI_ERROR_CAPTURE=<error_dir>` (see
below); `--start` does that for you with the dev server. The client address must count as a dev
client (`dev: true`, or `127.0.0.1` in `dev_ips`), otherwise the variable is ignored.

```json
{
    "base_url": "http://localhost:8000",
    "urls": ["/", "/about"],
    "follow_links": true,
    "max_pages": 200,
    "max_depth": 3,
    "exclude": ["/logout"],
    "error_dir": "temp/crawl-errors",
    "report": "temp/crawl-report.json",
    "login": {
        "required": true,
        "url": "/users/login",
        "username": "crawler",
        "password": "env:CRAWL_PASSWORD",
        "username_field": "user",
        "password_field": "pass",
        "extra": {},
        "verify_url": "/admin"
    }
}
```

- Only `base_url` is required. Paths in `urls` are relative to it; `error_dir` and `report` to the project root.
- `login.url` is POSTed with the two fields (plus `extra`) and the session cookie is kept. Login counts as
  failed when the final URL is still the login URL, or `verify_url` redirects back to it.
  A value of `env:NAME` is read from the environment, so the password need not be in the file.
- The error directory is emptied first (`--no-clean` keeps it). Only GET requests are sent after login.
- The report lists distinct errors (file, line, message, count, URLs) and every page with its HTTP status.
  Exit code: 0 clean, 1 errors or 5xx found, 2 bad config or server start failure.

## NOIRAPI_ERROR_CAPTURE

When set to a directory and the request is a dev request, `Kernel` enables Tracy in production mode with that
log directory, `error_reporting(E_ALL)`, `Debugger::$logSeverity = E_ALL` and no strict mode. Nothing is shown
on the page and the request continues after a warning, so one request reports all of its problems.
