<?php

namespace Omnifood\JustEat\Tests;

use Omnifood\Exception\RateLimitedException;
use Omnifood\Exception\UsageLimitReachedException;
use Omnifood\JustEat\JustEatPlatform;
use Omnifood\JustEat\JustEatPlatformFactory;
use Omnifood\Registry;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Just Eat (JET Connect)'s usage policy, declared as data (contract-only, its limits unknown), and a 429 to
 * an order taken in: sent once, surfaced, the next calls held unsent for the wait.
 */
final class JustEatPolicyTest extends TestCase
{
    /** @var list<array{string, string}> method and URL of each call but the token's */
    private array $calls = [];

    /** @var list<MockResponse> */
    private array $answers = [];

    public function testThePolicyIsDeclaredAsData(): void
    {
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();
        self::assertCount(1, $declared, 'composer.json\'s extra.omnisense.policy names it');
        self::assertSame(['omnifood/justeat', 'policy.json'], [$declared[0]['package'], $declared[0]['file']]);
        self::assertArrayNotHasKey('error', $declared[0], 'it reads as the schema says');
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('justeat', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame('contract', $policy->rights->commercial->value, 'contract-only');
        self::assertNull($policy->rateLimits, 'not published: unknown');
        self::assertFalse($policy->knowsLimits());
        $registry = new Registry([new JustEatPlatformFactory($this->http())], ['justeat' => ['factory' => 'justeat', 'options' => ['api_key' => 'flyt-key', 'restaurant' => 'AKZ12', 'currency' => 'GBP', 'timezone' => 'Europe/London']]]);
        self::assertEquals($policy, $registry->policy('justeat'));
    }

    public function testA429ToAWriteIsSurfacedNeverRetriedAndHoldsTheNextCalls(): void
    {
        $this->answers = [new MockResponse('{"message": "Too many requests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '60']])];
        $platform = (new JustEatPlatformFactory($this->http()))->create(['api_key' => 'flyt-key', 'restaurant' => 'AKZ12', 'currency' => 'GBP', 'timezone' => 'Europe/London']);
        self::assertInstanceOf(JustEatPlatform::class, $platform);

        try {
            $platform->accept('38bb');
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertSame(60, $e->retryAfter);
            self::assertSame(429, $e->status);
            self::assertSame('justeat', $e->platform);
        }
        self::assertCount(1, $this->calls, 'sent once, not retried');

        $this->expectException(UsageLimitReachedException::class);
        try {
            $platform->accept('38bb');
        } finally {
            self::assertCount(1, $this->calls, 'held, not sent');
        }
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/no-token/')) {
                return new MockResponse('{"access_token": "recorded", "expires_in": 86400, "token_type": "Bearer"}');
            }
            $this->calls[] = [$method, $url];

            return array_shift($this->answers) ?? new MockResponse('{}');
        });
    }
}
