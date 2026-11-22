<?php

namespace Tests\Unit\Push\Concerns;

use PHPUnit\Framework\Assert;

trait AssertsPrivatePushPayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertPrivatePushPayload(array $payload): void
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        Assert::assertLessThanOrEqual(
            (int) config('push-payloads.max_bytes', 4096),
            strlen($encoded),
            'Push payload must stay under the 4,096 byte limit.',
        );

        Assert::assertSame('MyAPES Account', $payload['title'] ?? null);
        Assert::assertSame(8030, $payload['web_push'] ?? null);
        Assert::assertIsArray($payload['notification'] ?? null);
        Assert::assertSame('MyAPES Account', $payload['notification']['title'] ?? null);
        Assert::assertSame('en-GB', $payload['notification']['lang'] ?? null);

        $url = $payload['url'] ?? null;
        Assert::assertIsString($url);
        Assert::assertStringStartsWith('/', $url);
        Assert::assertStringNotContainsString('://', $url);

        Assert::assertSame($url, $payload['data']['url'] ?? null);

        foreach ($this->forbiddenPayloadKeys() as $forbiddenKey) {
            Assert::assertArrayNotHasKey($forbiddenKey, $payload, "Top-level key {$forbiddenKey} must not appear on push payloads.");
            Assert::assertArrayNotHasKey($forbiddenKey, $payload['notification'], "notification.{$forbiddenKey} must not appear on push payloads.");
        }

        foreach ($this->forbiddenPayloadPatterns() as $pattern => $label) {
            Assert::assertDoesNotMatchRegularExpression(
                $pattern,
                $encoded,
                "Push payload must not include {$label}.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function forbiddenPayloadKeys(): array
    {
        return [
            'name',
            'email',
            'subject',
            'message',
            'pet',
            'case_title',
            'client_name',
            'body_html',
            'ip',
            'device',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function forbiddenPayloadPatterns(): array
    {
        return [
            '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i' => 'email addresses',
            '/\bJane Doe\b/' => 'personal names',
            '/\bFluffy\b/' => 'pet names',
            '/\bSecret subject\b/' => 'message subjects',
            '/\bwelfare concern detail\b/i' => 'case details',
        ];
    }
}