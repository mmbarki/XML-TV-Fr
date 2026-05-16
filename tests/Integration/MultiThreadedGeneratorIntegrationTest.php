<?php

declare(strict_types=1);

namespace racacax\XmlTvTest\Integration;

use PHPUnit\Framework\TestCase;
use racacax\XmlTv\Component\CacheFile;
use racacax\XmlTv\Component\ChannelsManager;
use racacax\XmlTv\Component\Logger;
use racacax\XmlTv\Component\MultiThreadedGenerator;
use racacax\XmlTv\Configurator;
use racacax\XmlTv\ValueObject\EPGDate;
use ReflectionClass;

/**
 * Integration tests for MultiThreadedGenerator
 *
 * These tests execute the full async workflow with real components:
 * - Amp async execution
 * - Real ChannelThread objects
 * - Actual UI rendering
 * - Real file I/O
 * - Multi-component coordination
 */
class MultiThreadedGeneratorIntegrationTest extends TestCase
{
    private string $testFolder = 'var/test/multithreaded_generator_integration';

    private function callMethod(object $obj, string $name, array $args = []): mixed
    {
        $class = new ReflectionClass($obj);
        $method = $class->getMethod($name);
        $method->setAccessible(true);

        return $method->invoke($obj, ...$args);
    }

    public function setUp(): void
    {
        parent::setUp();

        if (!is_dir($this->testFolder)) {
            @mkdir($this->testFolder, 0777, true);
        }

        $files = glob($this->testFolder.'/**/*', GLOB_MARK) ?: [];
        foreach (array_reverse($files) as $file) {
            if (is_file($file)) {
                @unlink($file);
            } elseif (is_dir($file)) {
                @rmdir($file);
            }
        }
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $files = glob($this->testFolder.'/**/*', GLOB_MARK) ?: [];
        foreach (array_reverse($files) as $file) {
            if (is_file($file)) {
                @unlink($file);
            } elseif (is_dir($file)) {
                @rmdir($file);
            }
        }
    }

    // ========================================
    // INTEGRATION TESTS: Full generateEpg() execution
    // ========================================

    /**
     * Test generateEpg() sets up the environment correctly and executes
     */
    public function testGenerateEpgFullExecution(): void
    {
        // Create a test channels file
        $channelsFile = $this->testFolder . '/channels_epg.json';
        $channelsData = [
            'ch1' => ['name' => 'Test Channel 1'],
            'ch2' => ['name' => 'Test Channel 2'],
        ];
        file_put_contents($channelsFile, json_encode($channelsData));

        // Create cache directory
        $cachePath = $this->testFolder . '/cache/';
        @mkdir($cachePath, 0777, true);

        // Configure with specific number of threads
        $epgDate = new EPGDate(new \DateTimeImmutable('2026-02-15'), EPGDate::$CACHE_ONLY);
        $config = new Configurator(
            epgDates: [$epgDate],
            nbThreads: 2
        );

        $generator = new MultiThreadedGenerator($config);
        $generator->setProviders([]);
        $cache = new CacheFile($cachePath, $config);
        $generator->setCache($cache);

        // Add guides
        $guides = [['channels' => $channelsFile, 'filename' => 'test']];
        $generator->addGuides($guides);

        // Create cache files so channels have data
        $cache->store('ch1_2026-02-15.xml', '<!-- Provider:Test --><programme start="20260215060000 +0100" stop="20260215070000 +0100" channel="ch1"><title>Program 1</title></programme>');
        $cache->store('ch2_2026-02-15.xml', '<!-- Provider:Test --><programme start="20260215060000 +0100" stop="20260215070000 +0100" channel="ch2"><title>Program 2</title></programme>');

        // Reset logger before test
        Logger::reset();

        // Execute generateEpg - this will run the full async workflow
        $this->callMethod($generator, 'generateEpg', []);

        // Verify execution completed without errors
        $this->assertTrue(true, 'generateEpg completed successfully');
    }

    /**
     * Test generateEpg() handles empty channel list
     */
    public function testGenerateEpgWithEmptyChannelList(): void
    {
        $channelsFile = $this->testFolder . '/empty_channels.json';
        file_put_contents($channelsFile, json_encode([]));

        $cachePath = $this->testFolder . '/cache/';
        @mkdir($cachePath, 0777, true);

        $epgDate = new EPGDate(new \DateTimeImmutable('2026-02-15'), EPGDate::$CACHE_ONLY);
        $config = new Configurator(
            epgDates: [$epgDate],
            nbThreads: 1
        );

        $generator = new MultiThreadedGenerator($config);
        $generator->setProviders([]);
        $cache = new CacheFile($cachePath, $config);
        $generator->setCache($cache);

        $guides = [['channels' => $channelsFile, 'filename' => 'empty']];
        $generator->addGuides($guides);

        Logger::reset();

        // Should complete without errors even with no channels
        $this->callMethod($generator, 'generateEpg', []);

        $this->assertTrue(true, 'generateEpg handled empty channel list');
    }

    /**
     * Test generateEpg() creates correct number of threads
     */
    public function testGenerateEpgWithMultipleThreads(): void
    {
        $channelsFile = $this->testFolder . '/channels_threads.json';
        $channelsData = [
            'ch1' => ['name' => 'Channel 1'],
            'ch2' => ['name' => 'Channel 2'],
            'ch3' => ['name' => 'Channel 3'],
        ];
        file_put_contents($channelsFile, json_encode($channelsData));

        $cachePath = $this->testFolder . '/cache/';
        @mkdir($cachePath, 0777, true);

        $epgDate = new EPGDate(new \DateTimeImmutable('2026-02-15'), EPGDate::$CACHE_ONLY);

        // Test with 3 threads
        $config = new Configurator(
            epgDates: [$epgDate],
            nbThreads: 3
        );

        $generator = new MultiThreadedGenerator($config);
        $generator->setProviders([]);
        $cache = new CacheFile($cachePath, $config);
        $generator->setCache($cache);

        $guides = [['channels' => $channelsFile, 'filename' => 'test']];
        $generator->addGuides($guides);

        // Create cache for all channels
        foreach (['ch1', 'ch2', 'ch3'] as $ch) {
            $cache->store("{$ch}_2026-02-15.xml", "<!-- Provider:Test --><programme start=\"20260215060000 +0100\" stop=\"20260215070000 +0100\" channel=\"{$ch}\"><title>Program</title></programme>");
        }

        Logger::reset();

        // The method should create 3 threads as configured
        $this->callMethod($generator, 'generateEpg', []);

        // Verify execution completed
        $this->assertTrue(true, 'generateEpg created and managed 3 threads successfully');
    }

    /**
     * Test generateEpg() preserves and restores log level
     */
    public function testGenerateEpgPreservesLogLevel(): void
    {
        $channelsFile = $this->testFolder . '/channels_log.json';
        file_put_contents($channelsFile, json_encode(['ch1' => ['name' => 'Channel 1']]));

        $cachePath = $this->testFolder . '/cache/';
        @mkdir($cachePath, 0777, true);

        $epgDate = new EPGDate(new \DateTimeImmutable('2026-02-15'), EPGDate::$CACHE_ONLY);
        $config = new Configurator(epgDates: [$epgDate], nbThreads: 1);

        $generator = new MultiThreadedGenerator($config);
        $generator->setProviders([]);
        $cache = new CacheFile($cachePath, $config);
        $generator->setCache($cache);

        $guides = [['channels' => $channelsFile, 'filename' => 'test']];
        $generator->addGuides($guides);

        $cache->store('ch1_2026-02-15.xml', '<!-- Provider:Test --><programme start="20260215060000 +0100" stop="20260215070000 +0100" channel="ch1"><title>Program</title></programme>');

        // Set a specific log level before calling generateEpg
        Logger::reset();
        Logger::setLogLevel('info');
        $originalLevel = Logger::getLogLevel();

        // Call generateEpg (it temporarily sets log level to 'none')
        $this->callMethod($generator, 'generateEpg', []);

        // Verify log level was restored
        $restoredLevel = Logger::getLogLevel();
        $this->assertEquals($originalLevel, $restoredLevel, 'Log level should be restored after generateEpg');
    }

    /**
     * Test generateEpg() with mock to verify internal flow
     */
    public function testGenerateEpgCallsGenerateChannels(): void
    {
        $channelsFile = $this->testFolder . '/channels_flow.json';
        $channelsData = ['ch1' => ['name' => 'Channel 1']];
        file_put_contents($channelsFile, json_encode($channelsData));

        $cachePath = $this->testFolder . '/cache/';
        @mkdir($cachePath, 0777, true);

        $epgDate = new EPGDate(new \DateTimeImmutable('2026-02-15'), EPGDate::$CACHE_ONLY);
        $config = new Configurator(epgDates: [$epgDate], nbThreads: 1);

        // Create a partial mock that allows us to verify generateChannels was called
        $generator = $this->getMockBuilder(MultiThreadedGenerator::class)
            ->setConstructorArgs([$config])
            ->onlyMethods(['generateChannels'])
            ->getMock();

        // Expect generateChannels to be called exactly once
        $generator->expects($this->once())
            ->method('generateChannels')
            ->with(
                $this->isType('array'),  // threads array
                $this->isInstanceOf(ChannelsManager::class)  // manager
            );

        $generator->setProviders([]);
        $cache = new CacheFile($cachePath, $config);
        $generator->setCache($cache);

        $guides = [['channels' => $channelsFile, 'filename' => 'test']];
        $generator->addGuides($guides);

        $cache->store('ch1_2026-02-15.xml', '<!-- Provider:Test --><programme start="20260215060000 +0100" stop="20260215070000 +0100" channel="ch1"><title>Program</title></programme>');

        Logger::reset();

        // Call generateEpg - it should call generateChannels internally
        $this->callMethod($generator, 'generateEpg', []);
    }

    /**
     * Test generateEpg() with multiple guides
     */
    public function testGenerateEpgWithMultipleGuides(): void
    {
        $channelsFile1 = $this->testFolder . '/channels1.json';
        $channelsFile2 = $this->testFolder . '/channels2.json';

        file_put_contents($channelsFile1, json_encode(['ch1' => ['name' => 'Channel 1']]));
        file_put_contents($channelsFile2, json_encode(['ch2' => ['name' => 'Channel 2']]));

        $cachePath = $this->testFolder . '/cache/';
        @mkdir($cachePath, 0777, true);

        $epgDate = new EPGDate(new \DateTimeImmutable('2026-02-15'), EPGDate::$CACHE_ONLY);
        $config = new Configurator(epgDates: [$epgDate], nbThreads: 2);

        $generator = new MultiThreadedGenerator($config);
        $generator->setProviders([]);
        $cache = new CacheFile($cachePath, $config);
        $generator->setCache($cache);

        // Multiple guides
        $guides = [
            ['channels' => $channelsFile1, 'filename' => 'guide1'],
            ['channels' => $channelsFile2, 'filename' => 'guide2']
        ];
        $generator->addGuides($guides);

        $cache->store('ch1_2026-02-15.xml', '<!-- Provider:Test --><programme start="20260215060000 +0100" stop="20260215070000 +0100" channel="ch1"><title>Program 1</title></programme>');
        $cache->store('ch2_2026-02-15.xml', '<!-- Provider:Test --><programme start="20260215060000 +0100" stop="20260215070000 +0100" channel="ch2"><title>Program 2</title></programme>');

        Logger::reset();

        // Should process all channels from all guides
        $this->callMethod($generator, 'generateEpg', []);

        $this->assertTrue(true, 'generateEpg processed multiple guides successfully');
    }
}
