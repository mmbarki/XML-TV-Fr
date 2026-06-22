<?php

declare(strict_types=1);

namespace racacax\XmlTv\Component\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use racacax\XmlTv\Component\ChannelFactory;
use racacax\XmlTv\Component\ProviderInterface;
use racacax\XmlTv\Component\ProviderCache;
use racacax\XmlTv\Component\ResourcePath;
use racacax\XmlTv\Component\Utils;
use racacax\XmlTv\ValueObject\Channel;
use racacax\XmlTv\ValueObject\Program;

class ElCinema extends AbstractProvider implements ProviderInterface
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0';
    private const GUIDE_URL  = 'https://elcinema.com/tvguide/';
    private const AJAX_URL   = 'https://elcinema.com/tvguide/ajax_tvgrid';
    private const TIMEZONE   = 'Africa/Cairo';

    // 12 tranches × 2h = couverture complète de 00h00 à 23h59
    private const TIME_SLOTS = [
        '00:00', '02:00', '04:00', '06:00', '08:00', '10:00',
        '12:00', '14:00', '16:00', '18:00', '20:00', '22:00',
    ];

    // Partagé entre toutes les instances (session unique par process)
    private static ?CookieJar $cookieJar = null;
    private static ?string $csrfToken = null;

    /**
     * Cache parsé en mémoire : date → channelId → key → Program
     * Évite de re-parser le HTML pour chaque chaîne (le grid contient toutes les chaînes)
     *
     * @var array<string, array<string, array<string, Program>>>
     */
    private static array $dayCache = [];

    public function __construct(Client $client, ?float $priority = null)
    {
        parent::__construct(
            $client,
            ResourcePath::getInstance()->getChannelPath('channels_elcinema.json'),
            $priority ?? 0.3
        );
    }

    public function constructEPG(string $channel, string $date): Channel|bool
    {
        $channelObj = ChannelFactory::createChannel($channel);
        if (!$this->channelExists($channel)) {
            return false;
        }

        $channelId = (string) $this->channelsList[$channel];

        $this->ensureDayLoaded($date);

        if (empty(self::$dayCache[$date][$channelId])) {
            return false;
        }

        [$minDate, $maxDate] = $this->getMinMaxDate($date);
        $added = 0;

        foreach (self::$dayCache[$date][$channelId] as $program) {
            if ($program->getStart() >= $minDate && $program->getStart() <= $maxDate) {
                $channelObj->addProgram($program);
                $added++;
            }
        }

        return $added > 0 ? $channelObj : false;
    }

    public function getLogo(string $channel): ?string
    {
        if (!$this->channelExists($channel)) {
            throw new \Exception("Channel $channel does not exist in ElCinema provider");
        }

        return 'https://media0103.elcinema.com/tvguide/' . $this->channelsList[$channel] . '_2.png';
    }

    private function initSession(): void
    {
        if (self::$cookieJar !== null && self::$csrfToken !== null) {
            return;
        }

        self::$cookieJar = new CookieJar();

        $response = $this->client->get(self::GUIDE_URL, [
            'cookies' => self::$cookieJar,
            'headers' => ['User-Agent' => self::USER_AGENT],
            'connect_timeout' => 5,
            'timeout' => 30,
        ]);

        $html = (string) $response->getBody();

        if (!preg_match('/<meta name="csrf-token"\s+content="([^"]+)"/', $html, $matches)) {
            throw new \RuntimeException('ElCinema: impossible de récupérer le token CSRF');
        }

        self::$csrfToken = html_entity_decode($matches[1], ENT_QUOTES);
    }

    private function ensureDayLoaded(string $date): void
    {
        if (isset(self::$dayCache[$date])) {
            return;
        }

        self::$dayCache[$date] = [];
        $this->initSession();

        foreach (self::TIME_SLOTS as $slot) {
            $html = $this->fetchSlotHtml($date, $slot);
            if (!empty($html)) {
                $this->parseSlotIntoCache($html, $date);
            }
        }
    }

    private function fetchSlotHtml(string $date, string $timeSlot): string
    {
        $cacheKey = md5("elcinema_slot_{$date}_{$timeSlot}");
        $cache    = new ProviderCache($cacheKey);

        $cached = $cache->getContent();
        if ($cached !== null) {
            return $cached;
        }

        // Verrou pour éviter les requêtes doublons entre threads
        @mkdir(dirname($cache->getLockPath()), 0777, true);
        $fp = fopen($cache->getLockPath(), 'c');
        if ($fp !== false) {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                $this->setStatus(Utils::colorize('ElCinema en attente...', 'yellow'));
                flock($fp, LOCK_EX);
            }
            $cached = $cache->getContent();
            if ($cached !== null) {
                flock($fp, LOCK_UN);
                fclose($fp);

                return $cached;
            }
        }

        // Délai anti-ban entre chaque requête réseau
        usleep(800000);

        $content = '';
        try {
            $response = $this->client->post(self::AJAX_URL, [
                'cookies' => self::$cookieJar,
                'headers' => [
                    'User-Agent'       => self::USER_AGENT,
                    'X-CSRF-Token'     => self::$csrfToken,
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Origin'           => 'https://elcinema.com',
                    'Referer'          => self::GUIDE_URL,
                    'Accept'           => 'text/html, */*; q=0.01',
                ],
                'form_params' => [
                    'data[only_date]' => $date . ' ' . $timeSlot,
                    'data[only_time]' => $timeSlot,
                    'data[direction]' => 'refresh',
                ],
                'connect_timeout' => 5,
                'timeout'         => 30,
            ]);
            $content = (string) $response->getBody();
        } catch (\Throwable) {
        }

        if (!empty($content) && str_contains($content, 'tv-line')) {
            $cache->setContent($content);
        }

        if ($fp !== false) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return $content;
    }

    private function parseSlotIntoCache(string $html, string $requestedDate): void
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        // La date réelle peut différer (ex: tranche 22h→00h retourne la date suivante)
        $slotDate   = $requestedDate;
        $dateInputs = $xpath->query('//input[@name="only_date"]');
        if ($dateInputs !== false && $dateInputs->length > 0) {
            $slotDate = trim($dateInputs->item(0)->getAttribute('value'));
        }

        $tvLines = $xpath->query('//div[contains(@class,"tv-line")]');
        if ($tvLines === false) {
            return;
        }

        foreach ($tvLines as $line) {
            $channelLinks = $xpath->query('.//a[contains(@data-key,"favourite.channel.")]', $line);
            if ($channelLinks === false || $channelLinks->length === 0) {
                continue;
            }

            $dataKey = $channelLinks->item(0)->getAttribute('data-key');
            if (!preg_match('/favourite\.channel\.(\d+)/', $dataKey, $m)) {
                continue;
            }
            $channelId = $m[1];

            if (!isset(self::$dayCache[$requestedDate][$channelId])) {
                self::$dayCache[$requestedDate][$channelId] = [];
            }

            $slotNodes = $xpath->query('.//div[contains(@class,"tv-slot")]', $line);
            if ($slotNodes === false) {
                continue;
            }

            foreach ($slotNodes as $slotNode) {
                if ($slotNode->getAttribute('data-work-id') === '0') {
                    continue;
                }

                $liNodes = $xpath->query('.//li', $slotNode);
                if ($liNodes === false || $liNodes->length < 3) {
                    continue;
                }

                $title        = trim($liNodes->item(0)->textContent);
                $categoryLine = trim($liNodes->item(1)->textContent);
                $timeLine     = trim($liNodes->item(2)->textContent);

                $times = $this->parseTimeRange($timeLine, $slotDate);
                if ($times === null) {
                    continue;
                }

                [$startDt, $endDt] = $times;

                // Clé d'unicité pour éviter les doublons entre tranches
                $key = $startDt->getTimestamp() . '_' . $endDt->getTimestamp();
                if (isset(self::$dayCache[$requestedDate][$channelId][$key])) {
                    continue;
                }

                $program = new Program($startDt, $endDt);
                $program->addTitle($title, $this->detectLang($title));

                $category = $this->extractCategory($categoryLine);
                if ($category !== '') {
                    $program->addCategory($category, 'ar');
                }

                if (preg_match('/\((\d{4})\)/', $categoryLine, $ym)) {
                    $program->setDate($ym[1]);
                }

                self::$dayCache[$requestedDate][$channelId][$key] = $program;
            }
        }
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}|null
     */
    private function parseTimeRange(string $timeRange, string $date): ?array
    {
        // Format : "05:00  صباحًا - 07:00  مساءً"
        $ap = '(صباحًا|صباحاً|مساءً|مساءا)';
        if (!preg_match("/(\d{1,2}:\d{2})\s+{$ap}\s*-\s*(\d{1,2}:\d{2})\s+{$ap}/u", $timeRange, $m)) {
            return null;
        }

        $startDt = $this->toDateTime($m[1], $m[2], $date);
        $endDt   = $this->toDateTime($m[3], $m[4], $date);

        // Programme qui franchit minuit
        if ($endDt <= $startDt) {
            $endDt = $endDt->modify('+1 day');
        }

        return [$startDt, $endDt];
    }

    private function toDateTime(string $time, string $ampm, string $date): \DateTimeImmutable
    {
        [$h, $min] = explode(':', $time);
        $h   = (int) $h;
        $min = (int) $min;

        if (str_contains($ampm, 'صباح')) {
            // AM : 12h AM → 00h
            if ($h === 12) {
                $h = 0;
            }
        } else {
            // PM : 12h PM reste 12h, les autres +12
            if ($h !== 12) {
                $h += 12;
            }
        }

        return new \DateTimeImmutable(
            sprintf('%s %02d:%02d:00', $date, $h, $min),
            new \DateTimeZone(self::TIMEZONE)
        );
    }

    private function extractCategory(string $text): string
    {
        $cat = preg_replace('/\s*\(\d{4}\)\s*/', '', $text);

        return trim($cat ?? '');
    }

    private function detectLang(string $text): string
    {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';
    }
}
