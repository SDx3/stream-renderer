<?php


/*
 * Copyright (c) 2022 Sander Dorigo
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace App\Collector;

use App\Data\PinBoard;
use Carbon\Carbon;
use DateTimeInterface;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use JsonException;
use Monolog\Logger;

/**
 * Class SpotifyCollector
 */
class SpotifyCollector implements CollectorInterface
{
    private string    $cacheFile;
    private array     $collection;
    private array     $configuration = [];
    private Logger    $logger;
    private ?PinBoard $pinBoard;

    private Carbon $oldestDate;

    /**
     * @inheritDoc
     */
    public function collect(bool $skipCache = false): void
    {
        // hard coded age cut off for new liked songs.
        $this->oldestDate = new Carbon('2023-11-01');
        $this->logger->debug('SpotifyCollector is going to collect.');
        $useCache = true;

        if (true === $skipCache) {
            $this->logger->debug('SpotifyCollector will skip the cache.');
            $useCache = false;
        }
        if (false === $skipCache && $this->cacheOutOfDate()) {
            $this->logger->debug('SpotifyCollector cache is out of date.');
            $useCache = false;
        }

        if (false === $useCache) {
            $this->logger->debug('SpotifyCollector will not use the cache.');

            if (!$this->hasToken()) {
                $this->logger->debug('SpotifyCollector has no token.');
                $this->getNewTokens();
            }
            $this->logger->debug('SpotifyCollector has a token.');
            $this->getLastLikedSongs();
            $this->saveToCache();
        }
        if (true === $useCache) {
            $this->logger->debug('SpotifyCollector will use the cache.');
            $this->collectCache();
        }
    }

    /**
     * @return bool
     */
    private function cacheOutOfDate(): bool
    {
        if (!file_exists($this->cacheFile)) {
            $this->logger->debug('SpotifyCollector found no cache file, so it\'s out of date.');

            return true;
        }
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 128);
        if (false === $json) {
            return true;
        }
        // diff is over 12hrs
        if (time() - $json['moment'] > (12 * 60 * 60)) {
            $this->logger->debug('SpotifyCollector cache is outdated.');

            return true;
        }
        $this->logger->debug('SpotifyCollector cache is fresh!');

        return false;
    }

    private function hasToken(): bool
    {
        $file = sprintf('%s/spotify-auth.json', CACHE);
        if (!file_exists($file)) {
            $this->logger->debug('No cache file, so always false.');
            return false;
        }
        $content = file_get_contents($file);
        $json    = json_decode($content, true);

        if (time() > $json['expire_time']) {
            $this->logger->debug('Token is expired, need to get a new one.');
            $this->configuration['refresh_token'] = $json['refresh_token'];
            $this->getAccessToken();
            return true;
        }
        $this->configuration['access_token'] = $json['access_token'];
        return true;
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

        $url = 'https://accounts.spotify.com/authorize?' . http_build_query($params);
        echo "Since you have no refresh token, please visit this URL:\n";
        echo $url;
        echo "\n";
        echo "\n";
        echo "Then take the refresh token from the vagrant VM page you'll be redirected to.\n";
        echo "\n";

        exit(1);
    }

    /**
     *
     */
    private function saveToCache(): void
    {
        $content = [
            'moment' => time(),
            'data'   => $this->collection,
        ];
        $json    = json_encode($content, JSON_PRETTY_PRINT);
        file_put_contents($this->cacheFile, $json);
        $this->logger->debug('SpotifyCollector has saved the results to the cache.');
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
        $this->logger->debug('SpotifyCollector has collected from the cache.');
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
     * @inheritDoc
     */
    public function getCollection(): array
    {
        return $this->collection;
    }

    /**
     * @return PinBoard|null
     */
    public function getPinBoard(): ?PinBoard
    {
        return $this->pinBoard;
    }

    /**
     * @param PinBoard|null $pinBoard
     */
    public function setPinBoard(?PinBoard $pinBoard): void
    {
        $this->pinBoard = $pinBoard;
    }

    /**
     * @inheritDoc
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
        $this->cacheFile     = sprintf('%s/spotify-cache.json', CACHE);
    }

    /**
     * @inheritDoc
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->logger->debug('SpotifyCollector has a logger!');
    }

    /**
     *
     */
    private function getAccessToken(): void
    {
        $this->logger->debug('SpotifyCollector will now get an access token.');
        $client = new Client;
        $opts   = [
            'form_params' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->configuration['refresh_token'],
                'client_id'     => $this->configuration['client_id'],
            ],
            'auth'        => [$this->configuration['client_id'], $this->configuration['client_secret']],
            'headers'     => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ];
        $url    = 'https://accounts.spotify.com/api/token';
        try {
            $response = $client->post($url, $opts);
        } catch (ServerException $e) {
            $this->logger->error(sprintf('The Spotify server is down: %s', $e->getMessage()));
            exit(1);
        }
        $body                                = (string)$response->getBody();
        $json                                = json_decode($body, true);
        $this->configuration['access_token'] = $json['access_token'];
        try {
            $this->token = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error(sprintf('The Spotify token is unexpectedly not JSON: %s', $e->getMessage()));
            exit(1);
        }
        $this->logger->debug(sprintf('SpotifyCollector has collected access token %s.', $this->token['access_token']));
    }

    /**
     */
    private function getLastLikedSongs(): void
    {
        $more            = true;
        $max             = 50;
        $limit           = 50;
        $collection      = [];
        $loops           = 0;
        $collectionCount = 0;
        $url             = sprintf('https://api.spotify.com/v1/me/tracks', $this->configuration['username']);
        $opts            = [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->configuration['access_token']),
            ],
        ];
        while ($more && $loops < $max) {

            if($collectionCount === count($collection) && $loops > 1) {
                $this->logger->debug(sprintf('SpotifyCollector has collected %d songs, and the last  loop was empty, so we\'re done.', count($collection)));
                break;
            }

            $offset     = $loops * $limit;
            $params     = ['limit' => $limit, 'market' => 'NL', 'offset' => $offset];
            $currentUrl = $url . '?' . http_build_query($params);
            $this->logger->debug(sprintf('The Spotify URL is now %s', $currentUrl));

            $client = new Client;
            try {
                $response = $client->get($currentUrl, $opts);
            } catch (ClientException|ServerException|GuzzleException $e) {
                $this->logger->error(sprintf('The Spotify server is down: %s', $e->getMessage()));
                exit(1);
            }
            $body    = (string)$response->getBody();
            if(!json_validate($body)) {
                $this->logger->error(sprintf('The Spotify server delivered invalid JSON: %s', $body));
                exit(1);
            }
            $results = json_decode($body, true, 512);
            // no more songs to download?
            if (null === $results['next']) {
                $more = false;
            }
            // how many songs in the collection BEFORE we start?
            $collectionCount = count($collection);
            // process songs:
            /** @var array $song */
            foreach ($results['items'] as $song) {
                // collect artist
                $artist = [];
                /** @var array $artistArray */
                foreach ($song['track']['artists'] as $artistArray) {
                    $artist[] = $artistArray['name'];
                }

                // other meta data:
                $date  = Carbon::createFromFormat(DateTimeInterface::ATOM, $song['added_at'], $_ENV['TZ']);
                $title = implode(', ', $artist) . ' - ' . $song['track']['name'];
                $songURL   = $song['track']['external_urls']['spotify'];

                // skip over song if before the cut-off date
                if ($date->lt($this->oldestDate)) {
                    $this->logger->debug(sprintf('Skipping song %s because %s is before %s', $title, $date->format('Y-m-d'), $this->oldestDate->format('Y-m-d')));
                    continue;
                }

                $item = [
                    'date'  => $date,
                    'url'   => $songURL,
                    'title' => $title,
                    'tags'  => [],
                    'html'  => $this->getEmbedURL($songURL),
                ];

                // get tags for song:
                $tags = ['music'];
                if (null !== $this->pinBoard) {
                    $extraTags = $this->pinBoard->getTagsForUrl($songURL);
                    $this->logger->debug(sprintf('Pinboard found tags: %s', join(', ', $extraTags)));

                    $extraTags = array_map('strtolower', $extraTags);
                    $tags      = array_unique(array_merge($extraTags, $tags));
                    $tags      = $this->pinBoard->filterTags($tags, $songURL);
                    sort($tags);

                    $this->logger->debug(sprintf('Final set of tags is: %s', join(', ', $tags)));

                    $item['tags'] = $tags;
                }

                $this->logger->debug(sprintf('SpotifyCollector has collected "%s"', $item['title']));

                $collection[] = $item;
            }

            sleep(2);
            $loops++;
        }
        $this->collection = $collection;
    }

    private function getEmbedURL(string $url): string
    {
        sleep(1);
        // get oembed
        $client   = new Client;
        $embedURL = sprintf('https://open.spotify.com/oembed?%s', http_build_query(['url' => $url]));
        $opts     = [];
        try {
            $response = $client->get($embedURL, $opts);
        } catch (ClientException|ServerException|GuzzleException $e) {
            $this->logger->error(sprintf('The Spotify server is down: %s', $e->getMessage()));
            exit(1);
        }
        $body = (string)$response->getBody();
        $json = json_decode($body, true, 16);
        return (string)($json['html'] ?? '');
    }

}
