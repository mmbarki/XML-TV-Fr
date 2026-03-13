<?php

declare(strict_types=1);

namespace racacax\XmlTv\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use racacax\XmlTv\Component\ChannelThread;
use racacax\XmlTv\Component\ChannelsManager;
use racacax\XmlTv\Component\Generator;
use racacax\XmlTv\Component\CacheFile;
use racacax\XmlTv\Component\Logger;
use racacax\XmlTv\Component\ProviderInterface;
use racacax\XmlTv\Configurator;
use racacax\XmlTv\ValueObject\EPGEnum;
use ReflectionClass;

/**
 * Tests for the connectivity check feature in ChannelThread.
 *
 * SCENARIOS TESTED:
 * 1. connection_error result → same behavior as 'false' (failedProviders, returns failure)
 * 2. connection_error increments the global counter (distinct provider set)
 * 3. Same provider failing twice only counts once in the set
 * 4. Regular failure ('false') does NOT increment the connection error counter
 * 5. Successful provider result resets the connection error counter
 * 6. Check is NOT triggered below the threshold (≤2 distinct providers)
 * 7. Check is NOT triggered when connectivity_check_url is null (feature disabled)
 * 8. Check IS triggered at threshold (>2 distinct providers) and resets the counter
 */
class ChannelThreadConnectivityTest extends TestCase
{
    /** @var ChannelsManager&\PHPUnit\Framework\MockObject\MockObject */
    private ChannelsManager $manager;
    /** @var CacheFile&\PHPUnit\Framework\MockObject\MockObject */
    private CacheFile $cache;

    protected function setUp(): void
    {
        parent::setUp();

        Logger::reset();

        $this->manager = $this->createMock(ChannelsManager::class);
        $this->cache   = $this->createMock(CacheFile::class);

        $this->resetStaticCounter();
    }

    // ========================================
    // Helpers
    // ========================================

    private function callMethod(object $obj, string $name, array $args = []): mixed
    {
        $method = (new ReflectionClass($obj))->getMethod($name);
        $method->setAccessible(true);

        return $method->invoke($obj, ...$args);
    }

    private function setProperty(object $obj, string $name, mixed $value): void
    {
        $prop = (new ReflectionClass($obj))->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }

    private function getProperty(object $obj, string $name): mixed
    {
        $prop = (new ReflectionClass($obj))->getProperty($name);
        $prop->setAccessible(true);

        return $prop->getValue($obj);
    }

    /** Reset the static connectionErrorProviders set between test cases */
    private function resetStaticCounter(): void
    {
        $prop = (new ReflectionClass(ChannelThread::class))->getProperty('connectionErrorProviders');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /** Read the current static connectionErrorProviders set */
    private function getStaticCounter(): array
    {
        $prop = (new ReflectionClass(ChannelThread::class))->getProperty('connectionErrorProviders');
        $prop->setAccessible(true);

        return $prop->getValue(null);
    }

    /**
     * Build a ChannelThread mock with a specific connectivity check URL.
     * Always uses fresh mocks to avoid setUp() mock conflicts.
     */
    private function makeThread(?string $connectivityUrl, string $providerResult): ChannelThread
    {
        $configurator = $this->createMock(Configurator::class);
        $configurator->method('getConnectivityCheckUrl')->willReturn($connectivityUrl);
        $configurator->method('getMinEndTime')->willReturn(84600);

        $generator = $this->createMock(Generator::class);
        $generator->method('getCache')->willReturn($this->cache);
        $generator->method('getConfigurator')->willReturn($configurator);

        $thread = $this->getMockBuilder(ChannelThread::class)
            ->setConstructorArgs([$this->manager, $generator])
            ->onlyMethods(['getProviderResult'])
            ->getMock();
        $thread->method('getProviderResult')->willReturn($providerResult);

        $this->setProperty($thread, 'channel', 'TF1.fr');
        $this->setProperty($thread, 'failedProviders', []);

        return $thread;
    }

    /**
     * Build a ChannelThread mock returning consecutive values.
     * @param list<string> $providerResults
     */
    private function makeThreadWithConsecutiveResults(?string $connectivityUrl, array $providerResults): ChannelThread
    {
        $configurator = $this->createMock(Configurator::class);
        $configurator->method('getConnectivityCheckUrl')->willReturn($connectivityUrl);
        $configurator->method('getMinEndTime')->willReturn(84600);

        $generator = $this->createMock(Generator::class);
        $generator->method('getCache')->willReturn($this->cache);
        $generator->method('getConfigurator')->willReturn($configurator);

        $thread = $this->getMockBuilder(ChannelThread::class)
            ->setConstructorArgs([$this->manager, $generator])
            ->onlyMethods(['getProviderResult'])
            ->getMock();
        $thread->method('getProviderResult')->willReturnOnConsecutiveCalls(...$providerResults);

        $this->setProperty($thread, 'channel', 'TF1.fr');
        $this->setProperty($thread, 'failedProviders', []);

        return $thread;
    }

    private function callGetDataFromProvider(ChannelThread $thread, string $providerName): array
    {
        return $this->callMethod($thread, 'getDataFromProvider', [
            $providerName,
            $this->createMock(ProviderInterface::class),
            '2024-01-01',
            'TF1.fr_2024-01-01.xml',
        ]);
    }

    private function makeFullXml(): string
    {
        return sprintf(
            '<?xml version="1.0"?><tv><programme start="%s" stop="%s" channel="TF1.fr"><title>T</title></programme></tv>',
            date('YmdHis O', strtotime('2024-01-01 06:00:00')),
            date('YmdHis O', strtotime('2024-01-02 00:00:00'))
        );
    }

    private function makePartialXml(): string
    {
        return sprintf(
            '<?xml version="1.0"?><tv><programme start="%s" stop="%s" channel="TF1.fr"><title>T</title></programme></tv>',
            date('YmdHis O', strtotime('2024-01-01 06:00:00')),
            date('YmdHis O', strtotime('2024-01-01 12:00:00'))
        );
    }

    // ========================================
    // 1. connection_error behaves like a regular failure
    // ========================================

    public function testConnectionErrorReturnsFalse(): void
    {
        $thread = $this->makeThread(null, 'connection_error');
        $result = $this->callGetDataFromProvider($thread, 'ProviderA');

        $this->assertFalse($result['success']);
    }

    public function testConnectionErrorAddsToFailedProviders(): void
    {
        $thread = $this->makeThread(null, 'connection_error');
        $this->callGetDataFromProvider($thread, 'ProviderA');

        $this->assertContains('ProviderA', $this->getProperty($thread, 'failedProviders'));
    }

    public function testRegularFailureAlsoAddsToFailedProviders(): void
    {
        $thread = $this->makeThread(null, 'false');
        $this->callGetDataFromProvider($thread, 'ProviderA');

        $this->assertContains('ProviderA', $this->getProperty($thread, 'failedProviders'));
    }

    // ========================================
    // 2. connection_error increments the global counter
    // ========================================

    public function testConnectionErrorIncrementsGlobalCounter(): void
    {
        $thread = $this->makeThread(null, 'connection_error');

        $this->assertCount(0, $this->getStaticCounter());

        $this->callGetDataFromProvider($thread, 'ProviderA');

        $this->assertArrayHasKey('ProviderA', $this->getStaticCounter());
        $this->assertCount(1, $this->getStaticCounter());
    }

    public function testMultipleDistinctProvidersAllAddedToCounter(): void
    {
        $thread = $this->makeThread(null, 'connection_error');

        $this->callGetDataFromProvider($thread, 'ProviderA');
        $this->callGetDataFromProvider($thread, 'ProviderB');

        $counter = $this->getStaticCounter();
        $this->assertArrayHasKey('ProviderA', $counter);
        $this->assertArrayHasKey('ProviderB', $counter);
        $this->assertCount(2, $counter);
    }

    // ========================================
    // 3. Same provider failing multiple times counts only once
    // ========================================

    public function testSameProviderConnectionErrorCountedOnlyOnce(): void
    {
        $thread = $this->makeThread(null, 'connection_error');

        $this->callGetDataFromProvider($thread, 'ProviderA');
        $this->callGetDataFromProvider($thread, 'ProviderA');
        $this->callGetDataFromProvider($thread, 'ProviderA');

        $this->assertCount(1, $this->getStaticCounter());
    }

    // ========================================
    // 4. Regular failure does NOT increment the connection error counter
    // ========================================

    public function testRegularFailureDoesNotIncrementConnectionErrorCounter(): void
    {
        $thread = $this->makeThread(null, 'false');

        $this->callGetDataFromProvider($thread, 'ProviderA');
        $this->callGetDataFromProvider($thread, 'ProviderB');
        $this->callGetDataFromProvider($thread, 'ProviderC');

        $this->assertCount(0, $this->getStaticCounter());
    }

    // ========================================
    // 5. Successful provider result resets the counter
    // ========================================

    public function testSuccessfulProviderResetsConnectionErrorCounter(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getChannelStateFromTimes')->willReturn(EPGEnum::$FULL_CACHE);

        $thread = $this->makeThreadWithConsecutiveResults(null, [
            'connection_error',     // ProviderA fails
            'connection_error',     // ProviderB fails
            $this->makeFullXml(),   // ProviderC succeeds
        ]);

        $this->callGetDataFromProvider($thread, 'ProviderA');
        $this->callGetDataFromProvider($thread, 'ProviderB');
        $this->assertCount(2, $this->getStaticCounter(), 'Counter should have 2 entries before success');

        // Override the provider mock to return FULL_CACHE state for the success call
        $configurator = $this->createMock(Configurator::class);
        $configurator->method('getConnectivityCheckUrl')->willReturn(null);
        $configurator->method('getMinEndTime')->willReturn(84600);

        $successProvider = $this->createMock(ProviderInterface::class);
        $successProvider->method('getChannelStateFromTimes')->willReturn(EPGEnum::$FULL_CACHE);

        $result = $this->callMethod($thread, 'getDataFromProvider', [
            'ProviderC', $successProvider, '2024-01-01', 'TF1.fr_2024-01-01.xml',
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $this->getStaticCounter(), 'Counter must be reset after a successful fetch');
    }

    public function testSuccessWithPartialDataAlsoResetsCounter(): void
    {
        $this->cache->method('getState')->willReturn(EPGEnum::$NO_CACHE);

        $thread = $this->makeThreadWithConsecutiveResults(null, [
            'connection_error',
            $this->makePartialXml(),
        ]);

        $this->callGetDataFromProvider($thread, 'ProviderA');
        $this->assertCount(1, $this->getStaticCounter());

        $partialProvider = $this->createMock(ProviderInterface::class);
        $partialProvider->method('getChannelStateFromTimes')->willReturn(EPGEnum::$PARTIAL_CACHE);

        $this->callMethod($thread, 'getDataFromProvider', [
            'ProviderB', $partialProvider, '2024-01-01', 'TF1.fr_2024-01-01.xml',
        ]);

        $this->assertCount(0, $this->getStaticCounter(), 'Even partial success should reset the counter');
    }

    // ========================================
    // 6. Check NOT triggered below the threshold (≤2 distinct providers)
    // ========================================

    public function testCheckNotTriggeredWithOneConnectionError(): void
    {
        // URL is set to a real value to confirm the threshold (not null) is the guard
        $thread = $this->makeThread('https://xmltvfr.fr', 'connection_error');

        // If exit(1) were called, the process would terminate and this assertion would never run
        $result = $this->callGetDataFromProvider($thread, 'ProviderA');

        $this->assertFalse($result['success']);
        $this->assertCount(1, $this->getStaticCounter());
    }

    public function testCheckNotTriggeredWithTwoConnectionErrors(): void
    {
        $thread = $this->makeThread('https://xmltvfr.fr', 'connection_error');

        $this->callGetDataFromProvider($thread, 'ProviderA');
        $this->callGetDataFromProvider($thread, 'ProviderB');

        // >2 requires at least 3 — two is still below threshold
        $this->assertCount(2, $this->getStaticCounter());
    }

    // ========================================
    // 7. Check NOT triggered when URL is null (feature disabled)
    // ========================================

    public function testCheckNotTriggeredWhenUrlIsNull(): void
    {
        $thread = $this->makeThread(null, 'connection_error');

        foreach (['ProviderA', 'ProviderB', 'ProviderC', 'ProviderD', 'ProviderE'] as $name) {
            // If exit(1) were called, the test process would terminate
            $result = $this->callGetDataFromProvider($thread, $name);
            $this->assertFalse($result['success']);
        }

        // All 5 unique providers in counter — no reset because no check was triggered
        $this->assertCount(5, $this->getStaticCounter());
    }

    // ========================================
    // 8. Check IS triggered at threshold and counter is reset
    // ========================================

    /**
     * With >2 distinct providers and a reachable URL, checkOrExit() succeeds and the
     * counter is reset. The counter reset is the observable proof that the check ran.
     *
     * @group connectivity-network
     */
    public function testCheckTriggeredAtThresholdAndResetsCounter(): void
    {
        // Prime the counter with 2 entries (null URL → no check yet)
        $setupThread = $this->makeThread(null, 'connection_error');
        $this->callGetDataFromProvider($setupThread, 'ProviderA');
        $this->callGetDataFromProvider($setupThread, 'ProviderB');
        $this->assertCount(2, $this->getStaticCounter(), 'Precondition: 2 providers in counter');

        // Third provider failure with a real URL → triggers the check → counter resets
        $thread = $this->makeThread('https://xmltvfr.fr', 'connection_error');
        $result = $this->callGetDataFromProvider($thread, 'ProviderC');

        $this->assertFalse($result['success']);
        $this->assertCount(0, $this->getStaticCounter(), 'Counter must be reset after the connectivity check passes');
    }

    // Note: the behavior "exit(1) is called when connectivity fails" is tested in
    // ConnectivityCheckerTest::testCheckOrExitCallsExitForUnreachablePort()
    // using TestableConnectivityChecker (which overrides doExit() to avoid actually exiting).
}
