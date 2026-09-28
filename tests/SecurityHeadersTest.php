<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use FastRoute\RouteCollector;
use Noirapi\Config;
use Noirapi\Lib\Response;
use Noirapi\Lib\Route;
use Noirapi\Lib\Security\SecurityHeaders;
use Noirapi\Tests\Support\FakeAppRoute;
use Noirapi\Tests\Support\TestController;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    #[Before]
    #[After]
    public function reset(): void
    {
        Config::set('security_headers', null);
        SecurityHeaders::reset();
        FakeAppRoute::$routes = null;
    }

    public function testDisabledByDefault(): void
    {
        self::assertFalse(SecurityHeaders::enabled());
        self::assertSame('', SecurityHeaders::csp());
    }

    public function testDefaults(): void
    {
        Config::set('security_headers', true);

        self::assertSame([
            'X-Content-Type-Options'     => 'nosniff',
            'X-Frame-Options'            => 'SAMEORIGIN',
            'Referrer-Policy'            => 'strict-origin-when-cross-origin',
            'Permissions-Policy'         => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ], SecurityHeaders::headers(false));

        self::assertSame(
            'max-age=31536000; includeSubDomains',
            SecurityHeaders::headers(true)['Strict-Transport-Security'],
        );
    }

    public function testOverridesAndExtraHeaders(): void
    {
        Config::set('security_headers', [
            'frame_options' => 'DENY',
            'hsts' => false,
            'permissions_policy' => false,
            'headers' => ['X-Robots-Tag' => 'noindex'],
        ]);

        $headers = SecurityHeaders::headers(true);

        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertArrayNotHasKey('Strict-Transport-Security', $headers);
        self::assertArrayNotHasKey('Permissions-Policy', $headers);
        self::assertSame('noindex', $headers['X-Robots-Tag']);
    }

    public function testDefaultCspUsesOneNoncePerRequest(): void
    {
        Config::set('security_headers', ['csp' => 'default']);

        $nonce = SecurityHeaders::nonce();
        $csp = SecurityHeaders::headers(false)['Content-Security-Policy'];

        self::assertTrue(SecurityHeaders::usesNonce());
        self::assertStringStartsWith("default-src 'self'; script-src 'self' 'nonce-" . $nonce . "'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertSame($nonce, SecurityHeaders::nonce());

        SecurityHeaders::reset();
        self::assertNotSame($nonce, SecurityHeaders::nonce());
    }

    public function testCustomCspAndReportOnly(): void
    {
        Config::set('security_headers', [
            'csp' => [
                'default-src' => ['self'],
                'script-src' => ["'self'", 'https://cdn.test', 'strict-dynamic'],
                'upgrade-insecure-requests' => [],
            ],
            'csp_report_only' => true,
        ]);

        $headers = SecurityHeaders::headers(false);

        self::assertArrayNotHasKey('Content-Security-Policy', $headers);
        self::assertSame(
            "default-src 'self'; script-src 'self' https://cdn.test 'strict-dynamic'; upgrade-insecure-requests",
            $headers['Content-Security-Policy-Report-Only'],
        );
        self::assertFalse(SecurityHeaders::usesNonce());
    }

    public function testApplyKeepsHeadersTheAppSet(): void
    {
        Config::set('security_headers', true);
        $response = new Response()->addHeader('x-frame-options', 'DENY');

        SecurityHeaders::apply($response, false);

        self::assertSame('DENY', $response->getHeaders()['x-frame-options']);
        self::assertArrayNotHasKey('X-Frame-Options', $response->getHeaders());
        self::assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
    }

    public function testRouteAddsHeadersToEveryResponse(): void
    {
        Config::set('security_headers', true);
        FakeAppRoute::$routes = static function (RouteCollector $routes): void {
            $routes->get('/items/{id}', [TestController::class, 'show']);
        };

        $server = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_HOST' => 'app.test', 'HTTPS' => 'on'];

        $found = Route::fromGlobals(['REQUEST_URI' => '/items/1'] + $server, [], [], [], [])->serve();
        $missing = Route::fromGlobals(['REQUEST_URI' => '/nowhere'] + $server, [], [], [], [])->serve();

        self::assertSame('nosniff', $found->getHeaders()['X-Content-Type-Options']);
        self::assertArrayHasKey('Strict-Transport-Security', $found->getHeaders());
        self::assertSame(404, $missing->getStatus());
        self::assertSame('nosniff', $missing->getHeaders()['X-Content-Type-Options']);
    }
}
