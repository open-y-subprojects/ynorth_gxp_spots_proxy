<?php

namespace Drupal\ynorth_gxp_spots_proxy\Plugin\rest\resource;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\rest\Plugin\ResourceBase;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Represents GXP spots proxy records as resources.
 *
 * @RestResource (
 *   id = "ynorth_gxp_spots_proxy",
 *   label = @Translation("GXP spots proxy"),
 *   uri_paths = {
 *     "canonical" = "/api/ynorth-gxp-spots-proxy/{date}",
 *     "https://www.drupal.org/link-relations/create" = "/api/ynorth-gxp-spots-proxy"
 *   }
 * )
 *
 * @deprecated in ynorth_gxp_spots_proxy:1.1.0 and is removed from ynorth_gxp_spots_proxy:1.2.0.
 * Use \Drupal\ynorth_gxp_spots_proxy\YnorthGxpSpotsProxy::getWeekData().
 */
class GxpSpotsProxyResource extends ResourceBase {

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
   * Constructs a Drupal\rest\Plugin\rest\resource\EntityResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \GuzzleHttp\Client $client
   *   The Http Client.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, Client $client, CacheBackendInterface $cache) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->client = $client;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('http_client'),
      $container->get('cache.data')
    );
  }

  const GXP_END_POINT = 'https://www.groupexpro.com/schedule/embed/json.php';

  /**
   * Cache tag name.
   */
  const CACHE_NAME = 'ynorth_gxp_spots_proxy';

  /**
   * Cache ttl.
   */
  const CACHE_TTL = 30;

  /**
   * Responds to GET requests.
   *
   * @param string $date
   *   The ID of the record.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The response containing the record.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   */
  public function get($date) {
    if ($this->cache->get(self::CACHE_NAME . ':' . $date)) {
      $response = new JsonResponse($this->cache->get(self::CACHE_NAME . ':' . $date)->data, 200);
      return $response;
    }

    $timeZone = new \DateTimeZone('UTC');
    $startTime = new \DateTime($date, $timeZone);
    $startTime->setTime(18, 00);
    $endTime = new \DateTime($date, $timeZone);
    $endTime->setTime(23, 59);

    $queryParams = [
      'schedule' => '',
      'a' => 3,
      'start' => $startTime->getTimestamp(),
      'end' => $endTime->getTimestamp(),
    ];
    $queryStr = http_build_query($queryParams);
    $url = self::GXP_END_POINT . '?' . $queryStr;

    $response = $this->client->get($url);
    $body = $response->getBody();
    $content = $body->getContents();

    // Fix for jsonp format.
    $jsonp = trim($content);
    $jsonp = trim($jsonp, '()');

    // Remove bad symbols from json.
    $jsonp = str_replace('	', '  ', $jsonp);
    $jsonp = str_replace("\'", "'", $jsonp);
    $json = json_decode($jsonp, TRUE);
    if (empty($json)) {
      // @todo Log this case. If $json empty that meens we have bad data from groupex.
      $response = new JsonResponse([], 200);
      return $response;
    }
    $gxpData = $json["aaData"];
    $data = [];
    foreach ($gxpData as $schedule) {
      // Variable $schedule[9] must be have html content like this:
      // <a data-date="11/20/2020" class="descGXP" alt="11938371" href= "javascript://"11938371">Description</a><br><a class="signUpGXP" textmsg="10 SPOTS LEFT" alt="11938371" href="https://www.groupexpro.com/gxp/reservations/start/index/11938371/11/20/2020">Sign Up</a>.
      if (!isset($schedule[9])) {
        continue;
      }
      $htmlText = $schedule[9];
      $dom = new \DOMDocument();
      $dom->loadHTML(mb_convert_encoding($htmlText, 'HTML-ENTITIES', 'UTF-8'));
      $elements = $dom->getElementsByTagName('a');
      /** @var \DOMElement $el */
      foreach ($elements as $el) {
        if ($el->hasAttribute('textmsg') && $el->hasAttribute('alt')) {
          $data[$el->getAttribute('alt')] = $el->getAttribute('textmsg');
        }
      }

    }

    $response = new JsonResponse($data, 200);

    // Set cache.
    $requestTime = (int) $response->getDate()->getTimestamp();
    $cacheTtl = self::CACHE_TTL;
    $this->cache->set(self::CACHE_NAME . ':' . $date, $data, $requestTime + $cacheTtl);

    return $response;
  }

}
