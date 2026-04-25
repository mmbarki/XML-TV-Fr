<?php

declare(strict_types=1);

namespace racacax\XmlTv\Component\Provider;

use GuzzleHttp\Client;
use racacax\XmlTv\Component\ChannelFactory;
use racacax\XmlTv\Component\ProviderInterface;
use racacax\XmlTv\Component\ResourcePath;
use racacax\XmlTv\ValueObject\Channel;
use racacax\XmlTv\ValueObject\Program;

class StarTimesTV extends AbstractProvider implements ProviderInterface
{
    private static ?string $token = null;

    public function __construct(Client $client, ?float $priority = null)
    {
        parent::__construct($client, ResourcePath::getInstance()->getChannelPath('channels_startimestv.json'), $priority ?? 0.35);
    }

    private function getToken(): string
    {
        if (self::$token === null) {
            $content = $this->getContentFromURL('https://m.startimestv.com/browser/guide');
            preg_match('/"(eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+)"/', $content, $matches);
            if (empty($matches[1])) {
                throw new \Exception('Unable to retrieve StarTimesTV token');
            }
            self::$token = $matches[1];
        }

        return self::$token;
    }

    public function constructEPG(string $channel, string $date): Channel|bool
    {
        $channelObj = ChannelFactory::createChannel($channel);
        if (!$this->channelExists($channel)) {
            return false;
        }

        [$minDate, $maxDate] = $this->getMinMaxDate($date);
        $tz = new \DateTimeZone('Europe/Paris');
        $startDate = (new \DateTimeImmutable($date, $tz))->getTimestamp() * 1000;
        $endDate = $startDate + 86400000 - 1;

        $channelId = (int) $this->channelsList[$channel];
        $url = $this->generateUrl($channelId, $startDate, $endDate);

        $token = $this->getToken();
        $content = $this->getContentFromURL($url, ['token' => $token, 'appVersion' => '51300']);
        $programs = @json_decode($content, true);

        if (!is_array($programs)) {
            return false;
        }

        foreach ($programs as $item) {
            try {
                $startTime = new \DateTimeImmutable('@' . intdiv((int) $item['startDate'], 1000));
                $endTime = new \DateTimeImmutable('@' . intdiv((int) $item['endDate'], 1000));
            } catch (\Throwable) {
                continue;
            }

            if ($startTime < $minDate) {
                continue;
            }
            if ($startTime > $maxDate) {
                break;
            }

            $program = new Program($startTime, $endTime);
            $program->addTitle($item['name'] ?? 'Aucun titre');

            if (!empty($item['description'])) {
                $program->addDesc($item['description']);
            }

            if (!empty($item['subhead']) && $item['subhead'] !== 'NA') {
                $program->addSubTitle($item['subhead']);
            }

            $channelObj->addProgram($program);
        }

        return $channelObj;
    }

    public function generateUrl(int $channelId, int $startDate, int $endDate): string
    {
        return 'https://upms.startimestv.com/cpage/programs?' . http_build_query([
            'channelID' => $channelId,
            'startDate' => $startDate,
            'endDate'   => $endDate,
            'count'     => 1000,
        ]);
    }
}
