<?php

declare(strict_types=1);

namespace Noirapi\Lib\Crawl;

use Curl\Curl;

use function array_shift;
use function count;
use function getenv;
use function in_array;
use function is_array;
use function is_string;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function microtime;
use function parse_url;
use function preg_match;
use function round;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * Walks a site over HTTP and attributes every Tracy log entry to the URL that caused it.
 *
 * The server under test must run with NOIRAPI_ERROR_CAPTURE=<error_dir> (see Kernel::initTracy()),
 * otherwise nothing is logged and the report says so.
 */
final class Crawler
{
    private Curl $http;

    /** @var array<string, array{url: string, status: int, ms: int, errors: int}> */
    private array $pages = [];

    /** @var array<string, array<string, mixed>> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $config
     * @param ErrorLog $log
     */
    public function __construct(private readonly array $config, private readonly ErrorLog $log)
    {
        $this->http = new Curl();
        $this->http->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $this->http->setOpt(CURLOPT_MAXREDIRS, 5);
        $this->http->setOpt(CURLOPT_TIMEOUT, (int) ($config['timeout'] ?? 30));
        $this->http->setCookieJar(sys_get_temp_dir() . '/noirapi-crawl-' . getmypid() . '.cookies');
        $this->http->setHeader('User-Agent', 'noirapi-crawl');
    }

    /**
     * @return array<string, mixed> the report
     */
    public function run(): array
    {
        $login = $this->config['login'];

        if ($login['required'] && ! $this->login($login)) {
            return $this->report(['login failed: check login.url, username, password and field names']);
        }

        $this->crawl();

        return $this->report($this->notes());
    }

    /**
     * @return void
     */
    private function crawl(): void
    {
        $base = $this->config['base_url'];
        $queue = [];

        foreach ($this->config['urls'] as $url) {
            $queue[] = [Links::resolve((string) $url, $base . '/', $base), 0];
        }

        $seen = [];

        while ($queue !== [] && count($seen) < $this->config['max_pages']) {
            [$url, $depth] = array_shift($queue);

            if ($url === null || isset($seen[$url]) || $this->isExcluded($url)) {
                continue;
            }

            $seen[$url] = true;
            $html = $this->visit($url);

            if ($html === null || ! $this->mayFollow($depth)) {
                continue;
            }

            foreach (Links::extract($html, $url, $base) as $link) {
                $queue[] = [$link, $depth + 1];
            }
        }
    }

    /**
     * @param int $depth
     * @return bool
     */
    private function mayFollow(int $depth): bool
    {
        return $this->config['follow_links'] && $depth < $this->config['max_depth'];
    }

    /**
     * @return list<string>
     */
    private function notes(): array
    {
        if ($this->errors !== []) {
            return [];
        }

        return ['no log entries; if that is unexpected, confirm the server runs with NOIRAPI_ERROR_CAPTURE='
            . $this->config['error_dir'] . ' and that the client address is in dev_ips (or dev: true)'];
    }

    /**
     * POSTs the credentials without following redirects (a 307 would replay the POST), then judges the
     * outcome: it fails when the server sends the client back to the login page.
     *
     * @param array<string, mixed> $login
     * @return bool
     */
    private function login(array $login): bool
    {
        $fields = [
            $login['username_field'] => $login['username'],
            $login['password_field'] => $login['password'],
        ] + $login['extra'];

        $this->http->setOpt(CURLOPT_FOLLOWLOCATION, false);
        $this->http->post($this->absolute($login['url']), $fields);
        $this->http->setOpt(CURLOPT_FOLLOWLOCATION, true);

        $status = (int) $this->http->httpStatusCode;
        $location = (string) ($this->http->responseHeaders['location'] ?? '');

        if ($this->http->curlError || $status >= 400) {
            return false;
        }

        return ! str_contains($location, CrawlConfig::loginMarker($login['url']));
    }

    /**
     * @param string $url
     * @return string|null the HTML body when the response is an HTML page
     */
    private function visit(string $url): ?string
    {
        $before = $this->log->snapshot();
        $started = microtime(true);
        $this->http->get($url);
        $millis = (int) round((microtime(true) - $started) * 1000);

        $new = $this->log->since($before);

        foreach ($new as $entry) {
            $key = $entry['key'];
            $this->errors[$key] ??= $entry + ['count' => 0, 'urls' => []];
            $this->errors[$key]['count']++;

            if (! in_array($url, $this->errors[$key]['urls'], true)) {
                $this->errors[$key]['urls'][] = $url;
            }
        }

        $status = (int) $this->http->httpStatusCode;
        $this->pages[$url] = ['url' => $url, 'status' => $status, 'ms' => $millis, 'errors' => count($new)];

        $type = strtolower((string) ($this->http->responseHeaders['content-type'] ?? ''));

        return ! $this->http->error && str_contains($type, 'html') ? (string) $this->http->response : null;
    }

    /**
     * @param string $url
     * @return bool
     */
    private function isExcluded(string $url): bool
    {
        foreach ($this->config['exclude'] as $needle) {
            if (str_contains($url, (string) $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $path
     * @return string
     */
    private function absolute(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : $this->config['base_url'] . '/' . ltrim($path, '/');
    }

    /**
     * @param list<string> $notes
     * @return array<string, mixed>
     */
    private function report(array $notes): array
    {
        $failed = array_filter($this->pages, static fn(array $page): bool => $page['status'] >= 500 || $page['status'] === 0);

        return [
            'summary' => [
                'pages' => count($this->pages),
                'distinct_errors' => count($this->errors),
                'server_errors' => count($failed),
            ],
            'notes' => $notes,
            'errors' => array_values($this->errors),
            'pages' => array_values($this->pages),
        ];
    }
}
