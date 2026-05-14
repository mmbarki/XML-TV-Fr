<?php

declare(strict_types=1);

namespace racacax\XmlTvTest\Ressources\Provider;

use racacax\XmlTv\Component\ProviderInterface;
use racacax\XmlTv\Configurator;
use racacax\XmlTv\ValueObject\Channel;

class StubLowPriorityProvider implements ProviderInterface
{
    public static function getPriority(): float
    {
        return 0.6;
    }
    public function getInstancePriority(): float
    {
        return 0.6;
    }
    public function channelExists(string $channel): bool
    {
        return true;
    }
    public function constructEPG(string $channel, string $date): Channel|bool
    {
        return false;
    }
    public function getChannelsList(): array
    {
        return [];
    }
    public function getChannelStateFromTimes(array $s, array $e, Configurator $c): int
    {
        return 0;
    }
    public static function getMinMaxDate(string $date): array
    {
        return [$date, $date];
    }
}
