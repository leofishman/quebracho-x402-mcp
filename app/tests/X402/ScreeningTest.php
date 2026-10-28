<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\Screening;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Quebracho\X402\AddressCheck;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ScreeningTest extends TestCase
{
    private const OURS = '0x00000000000000000000000000000000000000aa';
    private const BAD = '0x00000000000000000000000000000000000000bb';
    private const OFAC = '0x00000000000000000000000000000000000000cc';
    private const CLEAN = '0x00000000000000000000000000000000000000dd';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/screening-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($this->dir.'/ofac.txt', self::OFAC."\n".self::OURS."\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    /** @param list<MockResponse>|MockResponse $api */
    private function screening(array|MockResponse $api = [], string $unavailable = 'reject', string $key = 'k'): Screening
    {
        return new Screening($this->dir, $key, 60, 0.5, $unavailable, new MockHttpClient($api), new ArrayAdapter(), new NullLogger());
    }

    /** What the seller would read: the listener's answer, deferred or not. */
    private static function verdict(Screening $screening, string $address): ?string
    {
        $check = new AddressCheck(AddressCheck::PAYER, 'eip155:84532', $address, []);
        $screening($check);

        return $check->verdict();
    }

    public function testFirstMatchWins(): void
    {
        $screening = $this->screening(key: '');
        $screening->set(self::OURS, 'allow');
        $screening->set(self::BAD, 'deny');

        self::assertNull($screening->reason(self::OURS), 'our allowlist beats OFAC');
        self::assertSame('address_denied', $screening->reason(self::BAD));
        self::assertSame('address_sanctioned', $screening->reason(strtoupper(self::OFAC)), 'any case');
        self::assertNull($screening->reason(self::CLEAN));
    }

    public function testACorruptListStopsPayments(): void
    {
        (new Filesystem())->dumpFile($this->dir.'/own.json', '{"allow":"x"}');

        $this->expectException(\UnexpectedValueException::class);
        $this->screening(key: '')->reason(self::CLEAN);
    }

    public function testTheApiAnswersAndIsCached(): void
    {
        $calls = 0;
        $api = function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"identifications":[{"category":"sanctions"}]}');
        };
        $screening = new Screening($this->dir, 'k', 60, 0.5, 'reject', new MockHttpClient($api), new ArrayAdapter(), new NullLogger());

        self::assertSame('address_sanctioned', self::verdict($screening, self::CLEAN));
        self::assertSame('address_sanctioned', $screening->check(self::CLEAN));
        self::assertSame(1, $calls, 'the second answer came from the cache');
    }

    public function testOurAllowlistNeverReachesTheApi(): void
    {
        $screening = $this->screening(new MockResponse('', ['http_code' => 500]));
        $screening->set(self::CLEAN, 'allow');

        self::assertNull(self::verdict($screening, self::CLEAN));
    }

    public function testNoAnswerFollowsThePolicy(): void
    {
        $down = static fn () => new MockResponse('', ['http_code' => 503]);

        self::assertSame('address_check_failed', self::verdict($this->screening($down()), self::CLEAN));
        self::assertNull(self::verdict($this->screening($down(), 'allow'), self::CLEAN));
    }

    public function testAnyOtherErrorRejectsWhateverThePolicy(): void
    {
        foreach (['{"no":"identifications"}' => 200, '' => 429] as $body => $status) {
            $screening = $this->screening(new MockResponse((string) $body, ['http_code' => $status]), 'allow');
            self::assertSame('address_check_failed', self::verdict($screening, self::CLEAN), "HTTP $status");
        }
    }
}
