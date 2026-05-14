<?php

declare(strict_types=1);

namespace racacax\XmlTv\Component;

class ChannelsManager
{
    private array $channels;
    private array $channelsInfo;
    private Generator $generator;
    private array $providersUsed;
    private array $providersFailedByChannel;
    private array $datesGatheredByChannel;
    private int $channelsCount;
    private int $channelsDone;
    private array $events;
    private array $providerLimits;

    public function __construct(array $channels, Generator $generator, array $providerLimits = ['SFR' => 5])
    {
        $this->channelsCount = count($channels);
        $this->channelsDone = 0;
        $this->channelsInfo = $channels;
        $this->generator = $generator;
        $this->channels = array_keys($channels);
        $this->providersUsed = [];
        $this->providersFailedByChannel = [];
        $this->events = [];
        $this->providerLimits = $providerLimits;
    }

    public function addEvent(string $event): void
    {
        $this->events[] = $event;
    }

    public function getLatestEvents(int $number): array
    {
        $slice = min(count($this->events), $number);

        return array_slice($this->events, -$slice, $number);
    }

    public function incrChannelsDone(): void
    {
        $this->channelsDone++;
    }

    public function getStatus(): string
    {
        return $this->channelsDone.' / '.$this->channelsCount;
    }

    public function removeChannelFromProvider(string $provider, string $channel): void
    {
        if (isset($this->providersUsed[$provider])) {
            if (($key = array_search($channel, $this->providersUsed[$provider])) !== false) {
                unset($this->providersUsed[$provider][$key]);
            }
        }
    }

    public function hasRemainingChannels(): bool
    {
        return count($this->channels) > 0;
    }

    public function canUseProvider(string $provider): bool
    {
        $limit = $this->providerLimits[$provider] ?? 1;

        return !isset($this->providersUsed[$provider]) || count($this->providersUsed[$provider]) < $limit;
    }

    public function addChannelToProvider(string $provider, string $channel): void
    {
        if (!isset($this->providersUsed[$provider])) {
            $this->providersUsed[$provider] = [];
        }
        $this->providersUsed[$provider][] = $channel;
    }

    public function hasAnyRemainingChannel(): bool
    {
        return count($this->channels) > 0;
    }

    public function addChannel(string $channel, array $providersFailed, array $datesGathered): void
    {
        $this->channels[] = $channel;
        $this->providersFailedByChannel[$channel] = $providersFailed;
        $this->datesGatheredByChannel[$channel] = $datesGathered;
    }

    /**
     * A channel is available if at least one provider at the top priority level
     * (that supports this channel and hasn't failed) is currently free.
     * Same-priority busy providers are skipped; a lower-priority tier is only
     * reached when all providers at the top tier have actually failed.
     */
    private function isChannelAvailable(string $key): bool
    {
        $info = $this->channelsInfo[$key] ?? [];
        $providers = $this->generator->getProviders($info['priority'] ?? []);
        $f = $this->providersFailedByChannel[$key] ?? [];
        if (count($f) > 0) {
            $failedProviders = $this->generator->getProviders($f);
        } else {
            $failedProviders = [];
        }
        $topPriority = null;
        foreach ($providers as $provider) {
            if (in_array($provider, $failedProviders)) {
                continue;
            }
            if (!$provider->channelExists($key)) {
                continue;
            }
            $priority = $provider->getInstancePriority();
            if ($topPriority === null) {
                $topPriority = $priority;
            } elseif ($priority < $topPriority) {
                // All same-priority providers at the top tier were busy
                return false;
            }
            $providerClass = Utils::extractProviderName($provider);
            if ($this->canUseProvider($providerClass)) {
                return true;
            }
        }

        // No applicable providers found → let it proceed; all top-tier busy → block
        return $topPriority === null;
    }

    public function shiftChannel(): array
    {
        $maxLoop = count($this->channels);
        $key = null;
        for ($i = 0; $i < $maxLoop; $i++) {
            $tmpKey = array_shift($this->channels);
            if ($this->isChannelAvailable($tmpKey)) {
                $key = $tmpKey;

                break;
            } else {
                $this->addChannel(
                    $tmpKey,
                    $this->providersFailedByChannel[$tmpKey] ?? [],
                    $this->datesGatheredByChannel[$tmpKey] ?? []
                );
            }
        }
        if (!isset($key)) {
            return [];
        }

        return [
            'key' => $key, 'info' => $this->channelsInfo[$key],
            'failedProviders' => $this->providersFailedByChannel[$key] ?? [],
            'datesGathered' => $this->datesGatheredByChannel[$key] ?? [],
            'extraParams' => $this->generator->getConfigurator()->getExtraParams()
        ];
    }
}
