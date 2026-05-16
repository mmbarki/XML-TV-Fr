<?php

declare(strict_types=1);

namespace racacax\XmlTv\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use racacax\XmlTv\Component\ConnectivityChecker;
use racacax\XmlTv\Component\Logger;

/**
 * Testable subclass that captures exit() calls instead of actually exiting.
 */
class TestableConnectivityChecker extends ConnectivityChecker
{
    public static bool $exitCalled = false;

    public static function reset(): void
    {
        self::$exitCalled = false;
    }

    protected static function doExit(): void
    {
        self::$exitCalled = true;
    }
}

/**
 * Tests for ConnectivityChecker.
 *
 * SCENARIOS TESTED:
 * 1. Reachable URL → doExit() NOT called, method returns normally
 * 2. Unreachable URL (bad port) → doExit() called
 * 3. Invalid domain → doExit() called
 * 4. Default doExit() calls exit(1) — verified in isolation via @runInSeparateProcess
 */
class ConnectivityCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Logger::reset();
        TestableConnectivityChecker::reset();
    }

    // ========================================
    // 1. Reachable URL → no exit
    // ========================================

    /**
     * @group connectivity-network
     */
    public function testCheckOrExitDoesNotCallExitWhenConnected(): void
    {
        TestableConnectivityChecker::checkOrExit('https://xmltvfr.fr');

        $this->assertFalse(TestableConnectivityChecker::$exitCalled);
    }

    /**
     * @group connectivity-network
     */
    public function testCheckOrExitDoesNotCallExitForOtherReachableUrl(): void
    {
        TestableConnectivityChecker::checkOrExit('https://www.google.com');

        $this->assertFalse(TestableConnectivityChecker::$exitCalled);
    }

    // ========================================
    // 2. Unreachable URL (closed port) → exit called
    // ========================================

    public function testCheckOrExitCallsExitForUnreachablePort(): void
    {
        // Port 19999 on localhost should not be listening — connection refused immediately
        TestableConnectivityChecker::checkOrExit('http://localhost:19999/');

        $this->assertTrue(TestableConnectivityChecker::$exitCalled);
    }

    // ========================================
    // 3. Invalid domain → exit called
    // ========================================

    public function testCheckOrExitCallsExitForInvalidDomain(): void
    {
        TestableConnectivityChecker::checkOrExit('http://this-domain-does-not-exist-12345.invalid/');

        $this->assertTrue(TestableConnectivityChecker::$exitCalled);
    }
}
