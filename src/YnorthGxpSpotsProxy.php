<?php

namespace Drupal\ynorth_gxp_spots_proxy;

use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Proxy for Groupex spots.
 */
class YnorthGxpSpotsProxy {

  const GXP_ENDPOINT = 'https://www.groupexpro.com/schedule/embed/json.php';
  const CACHE_NAME = 'ynorth_gxp_spots_proxy_week';

  /**
   * Mapping for groupex fields.
   */
  const GXP_DATE = 0;
  const GXP_TIME = 1;
  const GXP_HTML = 9;
  const GXP_TITLE = 2;
  const GXP_LOCATION = 8;

  /**
   * Cache ttl.
   */
  const CACHE_TTL = 60 * 5;

  /**
   * The http client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * Cache.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Construct Class.
   *
   * @param \GuzzleHttp\Client $client
   *   Client.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   CacheBackendInterface.
   * @param \Psr\Log\LoggerInterface $logger
   *   LoggerInterface.
   */
  public function __construct(Client $client, CacheBackendInterface $cacheBackend, LoggerInterface $logger) {
    $this->client = $client;
    $this->cache = $cacheBackend;
    $this->logger = $logger;
  }

  /**
   * Get spots data for week by timestamp.
   *
   * @param int $timestamp
   *   Timestamp.
   *
   * @return array
   *   Return Array of spots.
   */
  public function getWeekData($timestamp, $force = FALSE) {
    $initialDate = new \DateTime('now');
    $initialDate->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $initialDate->setTimestamp($timestamp);
    $monday = new \DateTime($initialDate->format('Y-m-d') . ' Monday this week');
    if ($this->cache->get(self::CACHE_NAME . ':' . $monday->format('Y-m-d')) && !$force) {
      return $this->cache->get(self::CACHE_NAME . ':' . $monday->format('Y-m-d'))->data;
    }
    $startTime = clone $monday;
    $startTime->setTimezone(new \DateTimeZone('UTC'));
    $startTime->setTime(18, 00);
    $startTimeStamp = $startTime->getTimestamp();
    $endTime = clone $startTime;
    $endTime->modify('+6 day');
    $endTime->setTime(23, 59);
    $endTimeStamp = $endTime->getTimestamp();

    // Convert raw data to usable array and parse field.
    $rawData = $this->getSchedules($startTimeStamp, $endTimeStamp);
    $enrichedData = [];
    foreach ($rawData as $schedule) {
      if (!isset($schedule[self::GXP_HTML]) || !isset($schedule[self::GXP_DATE]) || !isset($schedule[self::GXP_TIME]) || !isset($schedule[self::GXP_TITLE]) || !isset($schedule[self::GXP_LOCATION])) {
        $this->logger->info('Skiped reservation from sync due to unxpected data: %data', [
          '%data' => serialize($schedule),
        ]);
        continue;
      }
      $parsedData = $this->parseSchedule((string) $schedule[self::GXP_HTML]);
      if (empty($parsedData)) {
        // If parse data is empty it means reservations is turned off.
        continue;
      }
      $scheduleTime = explode('-', (string) $schedule[self::GXP_TIME])[0];
      $scheduleDateTime = \DateTime::createFromFormat('l, F j, Y ga', (string) $schedule[self::GXP_DATE] . ' ' . $scheduleTime);
      if (!$scheduleDateTime) {
        $msg = 'Unexpected date or time format. ';
        $msg .= 'Reservation sync has been skiped. ';
        $msg .= 'Please check "%class_title" for "%day" at ';
        $msg .= 'location "%location" in groupex admin interface.';
        $this->logger->warning($msg, [
          '%class_title' => $schedule[self::GXP_TITLE],
          '%day' => $schedule[self::GXP_DATE],
          '%location' => $schedule[self::GXP_LOCATION],
        ]);
        continue;
      }
      $enrichedData[$scheduleDateTime->format('Y-m-d')][] = $parsedData;
    }

    // Set cache.
    $currentTime = new \DateTime('now');
    $currentTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $currentTimeStamp = $currentTime->getTimestamp();
    $cacheTtl = self::CACHE_TTL;
    $this->cache->set(
      self::CACHE_NAME . ':' . $monday->format('Y-m-d'),
      $enrichedData,
      $currentTimeStamp + $cacheTtl
    );

    $msg = 'Get data from gxp for ';
    $msg .= $startTime->format('Y-m-d') . ':';
    $msg .= $endTime->format('Y-m-d');
    $msg .= ' week';
    $this->logger->info($msg);

    return $enrichedData;
  }

  /**
   * Parse html form groupex to data array.
   *
   * @param string $htmlText
   *   Parameter string.
   *
   * @return array
   *   Spots data, Spot text and reservation_id.
   */
  private function parseSchedule(string $htmlText) {
    $data = [];
    /* Variable $htmlText must be have html content like this:
    <a data-date="11/20/2020" class="descGXP" alt="11938371" href= "javascript://"11938371">Description</a><br><a class="signUpGXP" textmsg="10 SPOTS LEFT" alt="11938371" href="https://www.groupexpro.com/gxp/reservations/start/index/11938371/11/20/2020">Sign Up</a>.*/
    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML(mb_convert_encoding($htmlText, 'HTML-ENTITIES', 'UTF-8'));
    foreach (libxml_get_errors() as $error) {
      /* Clear unexpected warnings for attribute `data-date`:
      DOMDocument::loadHTML(): error parsing attribute name in Entity.*/
      if ($error->code != 68) {
        $msg = 'Can`t load html from gxp schedule: ';
        $msg .= $error->message;
        $msg .= ' HTML: ';
        $msg .= $htmlText;
        $this->logger->warning($msg);
        return [];
      }
    }
    libxml_clear_errors();
    libxml_use_internal_errors(FALSE);

    $elements = $dom->getElementsByTagName('a');
    /** @var \DOMElement $el */
    foreach ($elements as $el) {
      if ($el->hasAttribute('textmsg') && $el->hasAttribute('alt')) {
        $data = [
          'textmsg' => $el->getAttribute('textmsg'),
          'productid' => $el->getAttribute('alt'),
        ];
      }
    }
    return $data;
  }

  /**
   * Fetch schedules from groupex.
   *
   * @param int $startTime
   *   Timestamp.
   * @param int $endTime
   *   Timestamp.
   *
   * @return array
   *   Raw Data from gropex embed api.
   */
  private function getSchedules($startTime, $endTime) {
    $queryParams = [
      'schedule' => '',
      'a' => 3,
      'start' => $startTime,
      'end' => $endTime,
    ];
    $queryStr = http_build_query($queryParams);
    $url = self::GXP_ENDPOINT . '?' . $queryStr;
    try {
      $response = $this->client->get($url, ['connect_timeout' => 120, 'timeout' => 120]);
      $body = $response->getBody();
      $content = $body->getContents();
    }
    catch (\Exception $e) {
      $this->logger->warning('Gxp endpoint not available: %msg', ['%msg' => $e]);
      return [];
    }
    // Fix for jsonp format.
    $jsonp = trim($content);
    $jsonp = trim($jsonp, '()');

    // Remove bad symbols from json.
    $jsonp = str_replace('	', '  ', $jsonp);
    $jsonp = str_replace("\'", "'", $jsonp);

    /* @TODO return error if can`t parse. Can be done after upgrade to php 7.3
    by JSON_THROW_ON_ERROR flag.
    See https://www.php.net/manual/en/function.json-decode.php */
    $json = json_decode($jsonp, TRUE);
    if (empty($json)) {
      $this->wrapper->logger->warning('$json empty or broken that meens we have bad data from groupex.');
      return [];
    }
    $gxpData = $json["aaData"];
    return $gxpData;
  }

  /**
   * Prepare chace with spots for site
   */
  public function sync() {
    $weeksLimit = 2;
    $date = new \DateTime('now');
    $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    for ($i = 0; $i < $weeksLimit; $i++) {
      $this->getWeekData($date->getTimestamp(), TRUE);
      $date->modify('+1 week');
    }
  }

}
