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
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use JsonException;
use Monolog\Logger;

/**
 * Class WallabagCollector
 */
class WallabagCollector implements CollectorInterface
{
    private string    $cacheFile;
    private array     $collection;
    private array     $configuration = [];
    private Logger    $logger;
    private ?PinBoard $pinBoard;
    private array     $token;

    /**
     * @inheritDoc
     */
    public function collect(bool $skipCache = false): void
    {
        $this->logger->debug('WallabagCollector is going to collect.');
        $useCache = true;

        if (true === $skipCache) {
            $this->logger->debug('WallabagCollector will skip the cache.');
            $useCache = false;
        }
        if (false === $skipCache && $this->cacheOutOfDate()) {
            $this->logger->debug('WallabagCollector cache is out of date.');
            $useCache = false;
        }

        if (false === $useCache) {
            $this->logger->debug('WallabagCollector will not use the cache.');
            $this->getAccessToken();
            $this->makePublicArticles();
            $this->collectArchivedArticles();
            $this->saveToCache();
        }
        if (true === $useCache) {
            $this->logger->debug('WallabagCollector will use the cache.');
            $this->collectCache();
        }
    }

    /**
     * @return bool
     */
    private function cacheOutOfDate(): bool
    {
        if (!file_exists($this->cacheFile)) {
            $this->logger->debug('WallabagCollector found no cache file, so it\'s out of date.');
            return true;
        }
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 128);
        if (false === $json) {
            return true;
        }
        // diff is over 12hrs
        if (time() - $json['moment'] > (12 * 60 * 60)) {
            $this->logger->debug('WallabagCollector cache is outdated.');
            return true;
        }
        $this->logger->debug('WallabagCollector cache is fresh!');

        return false;
    }

    /**
     *
     */
    private function getAccessToken(): void
    {
        $this->logger->debug('WallabagCollector will now get an access token.');
        $client = new Client;
        $opts   = [
            'form_params' => [
                'grant_type'    => 'password',
                'client_id'     => $this->configuration['client_id'],
                'client_secret' => $this->configuration['client_secret'],
                'username'      => $this->configuration['username'],
                'password'      => $this->configuration['password'],
            ],
        ];
        $url    = sprintf('%s/oauth/v2/token', $this->configuration['host']);
        try {
            $response = $client->post($url, $opts);
        } catch (ServerException $e) {
            $this->logger->error(sprintf('The Wallabag server is down: %s', $e->getMessage()));
            exit(1);
        }
        $body = (string)$response->getBody();
        try {
            $this->token = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error(sprintf('The Wallabag token is unexpectedly not JSON: %s', $e->getMessage()));
            exit(1);
        }
        $this->logger->debug(sprintf('WallabagCollector has collected access token %s.', $this->token['access_token']));
    }

    /**
     *
     */
    private function makePublicArticles(): void
    {
        $this->logger->debug('WallabagCollector will make all archived articles public.');
        $client      = new Client;
        $page        = 1;
        $hasMore     = true;
        $articlesUrl = '%s/api/entries.json?archive=1&sort=archived&perPage=5&page=%d&public=0';
        $opts        = [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->token['access_token']),
            ],
        ];

        while (true === $hasMore) {
            $this->logger->debug(sprintf('WallabagCollector is now working on page #%d.', $page));
            $url = sprintf($articlesUrl, $this->configuration['host'], $page);
            try {
                $response = $client->get($url, $opts);
            } catch (ClientException $e) {
                $this->logger->error(sprintf('Page "%s" resulted in a %d-code. Continue.', $url, $e->getResponse()->getStatusCode()));
                $hasMore = false;
                //$page++;
                continue;
            }
            $body = (string)$response->getBody();
            try {
                $results = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->logger->error(sprintf('Page "%s" resulted in a JSON error ("%s"). Continue.', $url, $e->getMessage()));
                $hasMore = false;
                //$page++;
                continue;
            }

            $this->logger->addRecord(
                $results['total'] > 0 ? 200 : 100,
                sprintf('WallabagCollector found %d new article(s) to make public.', $results['total'])
            );

            if ($results['pages'] <= $page) {
                $this->logger->debug('WallabagCollector has no more pages to process.');
                $hasMore = false;
            }
            // loop articles
            foreach ($results['_embedded']['items'] as $item) {
                $patchClient = new Client;
                $patchUrl    = sprintf('%s/api/entries/%d.json', $_ENV['WALLABAG_HOST'], $item['id']);
                $patchOpts   = [
                    'headers'     => [
                        'Authorization' => sprintf('Bearer %s', $this->token['access_token']),
                    ],
                    'form_params' => [
                        'public' => 1,
                    ],
                ];
                $patchClient->patch($patchUrl, $patchOpts);
                $this->logger->debug(sprintf('WallabagCollector made article #%d public.', $item['id']));
                sleep(2);
            }
            //$page++;
        }
        $this->logger->debug('WallabagCollector is done making articles public.');
    }

    /**
     *
     */
    private function collectArchivedArticles(): void
    {
        $this->logger->debug('WallabagCollector will now collect public + archived articles.');
        $client      = new Client;
        $page        = 1;
        $hasMore     = true;
        $articles    = [];
        $articlesUrl = '%s/api/entries.json?archive=1&sort=archived&perPage=50&page=%d&public=1&detail=metadata';
        $opts        = [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->token['access_token']),
            ],
        ];

        while (true === $hasMore) {
            $this->logger->debug(sprintf('WallabagCollector is now working on page #%d.', $page));
            $url = sprintf($articlesUrl, $this->configuration['host'], $page);
            try {
                $response = $client->get($url, $opts);
            } catch (ServerException $e) {
                $this->logger->error(sprintf('The Wallabag server is down: %s', $e->getMessage()));
                exit(1);
            }
            $body = (string)$response->getBody();
            try {
                $results = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->logger->error(sprintf('Page #%d has a JSON error: %s', $page, $e->getMessage()));
                $page++;
                continue;
            }

            if (1 === $page) {
                $this->logger->debug(sprintf('Found %d article(s) to share.', $results['total']));
            }
            $this->logger->debug(sprintf('Working on page %d of %d...', $page, $results['pages']));

            if ($results['pages'] <= $page) {
                // no more pages
                $hasMore = false;
                $this->logger->debug('WallabagCollector found the last page!');
            }
            // loop articles and save them:
            foreach ($results['_embedded']['items'] as $item) {
                $articles[] = $this->processArticle($item);
            }
            sleep(2);
            $page++;
        }
        $this->logger->debug('WallabagCollector is done collecting articles.');
        $this->collection = $articles;
    }

    /**
     * @param  array  $item
     * @return array
     */
    private function processArticle(array $item): array
    {
        $this->logger->debug(sprintf('Now processing article %s', $item['url']));

        // parse original host name
        $host = parse_url($item['url'], PHP_URL_HOST);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        $tags    = [];
        $article = [
            'type'         => 'wallabag',
            'title'        => $item['title'],
            'url'          => $item['url'],
            'host'         => $host,
            'reading_time' => $item['reading_time'],
            'date'         => new Carbon($item['created_at'], $_ENV['TZ']), // mag ook "archived at" maar liever deze.
            'wallabag_url' => sprintf('%s/share/%s', $this->configuration['host'], $item['uid']),
            'annotations'  => [],
        ];

        /** @var array $tag */
        foreach ($item['tags'] as $tag) {
            $tags[] = $tag['label'];
        }
        $this->logger->debug(sprintf('Original tag-set is: %s', join(', ', $tags)));

        // if pinboard, expand list of tags with what we found online:
        if (null !== $this->pinBoard) {
            $extraTags = $this->pinBoard->getTagsForUrl($item['url']);
            $this->logger->debug(sprintf('Pinboard found tags: %s', join(', ', $extraTags)));

            $extraTags = array_map('strtolower', $extraTags);
            $tags      = array_unique(array_merge($extraTags, $tags));
            $tags      = $this->pinBoard->filterTags($tags, $item['url']);
            sort($tags);

            $this->logger->debug(sprintf('Final set of tags is: %s', join(', ', $tags)));

            $article['tags'] = $tags;
        }

        /** @var array $annotation */
        foreach ($item['annotations'] as $annotation) {
            $article['annotations'][] = [
                'quote' => $annotation['quote'],
                'text'  => $annotation['text'],
            ];
        }

        return $article;
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
        $this->logger->debug('WallabagCollector has saved the results to the cache.');
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
        $this->logger->debug('WallabagCollector has collected from the cache.');
        foreach ($this->collection as $index => $entry) {
            $this->logger->debug(sprintf('Now processing %s', $entry['url']));
            $entry['date']            = new Carbon($entry['date'], $_ENV['TZ']);
            $entry['tags']            = $this->pinBoard->filterTags($entry['tags'], $entry['url']);
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
     * @param  PinBoard|null  $pinBoard
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
        $this->cacheFile     = sprintf('%s/wallabag.json', CACHE);
    }

    /**
     * @inheritDoc
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->logger->debug('WallabagCollector has a logger!');
    }
}
