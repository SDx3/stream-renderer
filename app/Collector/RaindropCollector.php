<?php

namespace App\Collector;

use App\Data\PinBoard;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use JsonException;
use Monolog\Logger;

class RaindropCollector implements CollectorInterface
{
    private ?Pinboard $pinBoard      = null;
    private Logger    $logger;
    private string    $cacheFile;
    private array     $configuration = [];
    private array     $token;
    private array     $collection    = [];

    public function collect(bool $skipCache = false): void
    {
// hard coded age cut off for new liked songs.
        $this->logger->debug('RaindropCollector is going to collect.');
        $useCache = true;

        if (true === $skipCache) {
            $this->logger->debug('RaindropCollector will skip the cache.');
            $useCache = false;
        }
        if (false === $skipCache && $this->cacheOutOfDate()) {
            $this->logger->debug('RaindropCollector cache is out of date.');
            $useCache = false;
        }

        if (false === $useCache) {
            $this->logger->debug('RaindropCollector will not use the cache.');

            if (!$this->hasToken()) {
                $this->logger->debug('RaindropCollector has no token.');
                $this->getNewTokens();
            }
            $this->logger->debug('RaindropCollector has a token.');
            $this->getAllBookmarks();
            $this->saveToCache();
        }
        if (true === $useCache) {
            $this->logger->debug('RaindropCollector will use the cache.');
            $this->collectCache();
        }
    }

    public function getCollection(): array
    {
        // TODO: Implement getCollection() method.
        return $this->collection;
    }

    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
        $this->cacheFile     = sprintf('%s/raindrop-cache.json', CACHE);
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->logger->debug('RaindropCollector has a logger!');
    }

    public function getPinBoard(): ?PinBoard
    {
        return $this->pinboard;
    }

    public function setPinBoard(?PinBoard $pinBoard): void
    {
        $this->pinboard = $pinBoard;
    }

    private function cacheOutOfDate(): bool
    {
        if (!file_exists($this->cacheFile)) {
            $this->logger->debug('RaindropCollector found no cache file, so it\'s out of date.');

            return true;
        }
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 128);
        if (false === $json) {
            return true;
        }
        // diff is over 12hrs
        if (time() - $json['moment'] > (12 * 60 * 60)) {
            $this->logger->debug('RaindropCollector cache is outdated.');

            return true;
        }
        $this->logger->debug('RaindropCollector cache is fresh!');

        return false;
    }

    private function getAllBookmarks(): void
    {
        // get all collections to see what happens.
        $url    = 'https://api.raindrop.io/rest/v1/collections';
        $opts   = [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->configuration['access_token']),
            ],
        ];
        $client = new Client;
        try {
            $response = $client->get($url, $opts);
        } catch (ClientException | ServerException | GuzzleException $e) {
            $this->logger->error(sprintf('The Raindrop server is down: %s', $e->getMessage()));
            exit(1);
        }
        $body = (string)$response->getBody();
        if (!json_validate($body)) {
            $this->logger->error(sprintf('The Raindrop server delivered invalid JSON: %s', $body));
            exit(1);
        }
        $results = json_decode($body, true, 512);
        foreach ($results['items'] as $collection) {
            $collectionTitle = $collection['title'];
            $collectionId    = $collection['_id'];

            // get bookmarks for this collection.
            $url        = sprintf('https://api.raindrop.io/rest/v1/raindrops/%s', $collectionId);
            $opts       = [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->configuration['access_token']),
                ],
            ];
            $nextClient = new Client;
            try {
                $nextResponse = $nextClient->get($url, $opts);
            } catch (ClientException | ServerException | GuzzleException $e) {
                $this->logger->error(sprintf('The Raindrop server is down: %s   ', $e->getMessage()));
                exit(1);
            }
            $nextBody = (string)$nextResponse->getBody();
            if (!json_validate($nextBody)) {
                $this->logger->error(sprintf('The Raindrop server delivered invalid JSON: %s', $nextBody));
                exit(1);
            }
            $nextResults = json_decode($nextBody, true, 512);
            foreach ($nextResults['items'] as $raindrop) {
                $include = true;
                $ytHtml = '';
                $date    = new Carbon($raindrop['created']);
                $host    = parse_url($raindrop['link'], PHP_URL_HOST);
                // special thing for YouTube:
                $isYoutube = false;
                if (str_starts_with($host, 'www.')) {
                    $host = substr($host, 4);
                }
                if (str_contains($host, 'youtube.com')) {
                    $isYoutube = true;
                    $this->logger->debug('Bookmark is YouTube!');
                }

                if (true === $isYoutube) {
                    // get oEmbed for YouTube movie:
                    $params = [
                        'url'    => $raindrop['link'],
                        'format' => 'json',
                    ];
                    $url    = sprintf('https://www.youtube.com/oembed?%s', http_build_query($params));
                    $ytClient = new Client;
                    $ytBody   = '{"html": ""}';
                    try {
                        $ytRes = $ytClient->get($url);
                        $ytBody    = (string)$ytRes->getBody();
                    } catch (ClientException $e) {
                        $this->logger->debug(sprintf('Could not get oEmbed for YouTube movie "%s".', $raindrop['link']));
                        $include = false;
                    }
                    $ytJson = json_decode($ytBody, true);
                    $ytHtml = $ytJson['html'];

                    // this is a very cheap but effective way to resize the video:
                    $search  = 'width="200" height="113"';
                    $replace = 'width="600" height="339"';
                    $ytHtml    = str_replace($search, $replace, $ytHtml);
                    $this->logger->debug('Collected YouTube HTML');
                }

                if (count($raindrop['tags']) > 0) {
                    if (in_array('private', $raindrop['tags'], true)) {
                        $include = false;
                    }
                }
                if (false === $include) {
                    $this->logger->debug(sprintf('RaindropCollector has skipped "%s"', $raindrop['title']));
                }
                if (true === $include) {
                    $item = [
                        'title' => $raindrop['title'],
                        'url'   => $raindrop['link'],
                        'date'  => $date,
                        'host'  => $host,
                        'is_youtube' => $isYoutube,
                        'html'       => $ytHtml,
                    ];

                    $this->collection[] = $item;
                    $this->logger->debug(sprintf('RaindropCollector has collected "%s"', $item['title']));
                }
            }
            sleep(1);
        }
    }

    private function hasToken(): bool
    {
        $file = sprintf('%s/raindrop-auth.json', CACHE);
        if (!file_exists($file)) {
            $this->logger->debug('No cache file, so always false.');
            return false;
        }
        $content = file_get_contents($file);
        $json    = json_decode($content, true);

        if (time() > $json['expires']) {
            $this->logger->debug('Token is expired, need to get a new one.');
            $this->configuration['refresh_token'] = $json['refresh_token'];
            $this->getAccessToken();
            return true;
        }
        $this->configuration['access_token'] = $json['access_token'];
        return true;
    }

    private function getAccessToken(): void
    {
        $this->logger->debug('RaindropCollector will now get an access token.');
        $client = new Client;
        $opts   = [
            'json'    => [
                'client_id'     => $this->configuration['client_id'],
                'client_secret' => $this->configuration['client_secret'],
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->configuration['refresh_token'],
            ],
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];
        $url    = 'https://raindrop.io/oauth/access_token';
        try {
            $response = $client->post($url, $opts);
        } catch (ServerException $e) {
            $this->logger->error(sprintf('The Raindrop server is down: %s', $e->getMessage()));
            exit(1);
        }
        $body                                = (string)$response->getBody();
        $json                                = json_decode($body, true);
        $this->configuration['access_token'] = $json['access_token'];
        try {
            $this->token = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error(sprintf('The Raindrop token is unexpectedly not JSON: %s', $e->getMessage()));
            exit(1);
        }
        $this->logger->debug(sprintf('RaindropCollector has collected access token %s.', $this->token['access_token']));
    }

    private function saveToCache(): void
    {
        $content = [
            'moment' => time(),
            'data'   => $this->collection,
        ];
        $json    = json_encode($content, JSON_PRETTY_PRINT);
        file_put_contents($this->cacheFile, $json);
        $this->logger->debug('RaindropCollector has saved the results to the cache.');
    }


    /**
     * @return void
     */
    private function collectCache(): void
    {
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 128);
        if (false === $json) {
            return;
        }
        $this->collection = $json['data'];
        $this->logger->debug('RaindropCollector has collected from the cache.');
        foreach ($this->collection as $index => $entry) {
            $this->logger->debug(sprintf('Now processing %s', $entry['url']));
            $entry['date'] = new Carbon($entry['date'], $_ENV['TZ']);
            if (null !== $this->pinBoard) {
                $entry['tags'] = $this->pinBoard->filterTags($entry['tags'], $entry['url']);
            }
            $this->collection[$index] = $entry;
        }
    }

    /**
     * @return never
     * @throws Exception
     */
    private function getNewTokens(): never
    {
        $params = [
            'client_id'     => $this->configuration['client_id'],
            'response_type' => 'code',
            'redirect_uri'  => $this->configuration['redirect'],
            'state'         => (string)random_int(1, 1000),
            'scope'         => 'playlist-read-private playlist-read-collaborative user-library-read',
        ];

        $url = 'https://raindrop.io/oauth/authorize?' . http_build_query($params);
        echo "Since you have no refresh token, please visit this URL:\n";
        echo $url;
        echo "\n";
        echo "\n";
        echo "Then take the refresh token from the vagrant VM page you'll be redirected to.\n";
        echo "\n";

        exit(1);
    }

}