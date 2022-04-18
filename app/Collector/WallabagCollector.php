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

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Monolog\Logger;

class WallabagCollector implements CollectorInterface
{
    private array  $configuration = [];
    private Logger $logger;
    private bool   $skipCache;
    private array  $collection;
    private string $cacheFile;
    private array  $token;
    private array  $twitterCache;

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
    public function collect(bool $skipCache = false): void
    {
        $this->logger->debug('WallabagCollector is going to collect.');
        $this->skipCache = $skipCache;
        $useCache        = true;

        if (true === $this->skipCache) {
            $useCache = false;
        }
        if (false === $this->skipCache && $this->cacheOutOfDate()) {
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
     * @inheritDoc
     */
    public function getCollection(): array
    {
        return $this->collection;
    }

    /**
     * @return bool
     */
    private function cacheOutOfDate(): bool
    {
        if (!file_exists($this->cacheFile)) {
            $this->logger->debug('WallabagCollector found no cache file, so its out of date.');
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
                $page++;
                continue;
            }
            $body    = (string) $response->getBody();
            $results = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            $this->logger->addRecord($results['total'] > 0 ? 200 : 100, sprintf('WallabagCollector found %d new article(s) to make public.', $results['total']));

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
            $page++;
        }
        $this->logger->debug('WallabagCollector is done making articles public.');
    }

    /**
     *
     */
    private function getAccessToken(): void
    {
        $this->logger->debug('WallabagCollector will now get an access token.');
        $client      = new Client;
        $opts        = [
            'form_params' => [
                'grant_type'    => 'password',
                'client_id'     => $this->configuration['client_id'],
                'client_secret' => $this->configuration['client_secret'],
                'username'      => $this->configuration['username'],
                'password'      => $this->configuration['password'],
            ],
        ];
        $url         = sprintf('%s/oauth/v2/token', $this->configuration['host']);
        $response    = $client->post($url, $opts);
        $body        = (string) $response->getBody();
        $this->token = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        $this->logger->debug(sprintf('WallabagCollector has collected access token %s.', $this->token['access_token']));
    }

    /**
     *
     */
    private function collectArchivedArticles(): void
    {
        $this->collectTwitterCache();

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
            $url      = sprintf($articlesUrl, $this->configuration['host'], $page);
            $response = $client->get($url, $opts);
            $body     = (string) $response->getBody();
            $results  = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

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
                $result = $this->processArticle($item);
                if (true === $result['include']) {
                    $articles[] = $result;
                }
            }
            sleep(2);
            $page++;
        }
        $this->logger->debug('WallabagCollector is done collecting articles.');
        $this->collection = $articles;
    }

    /**
     * @param array $item
     * @return array
     */
    private function processArticle(array $item): array
    {
        // parse original host name
        $host = parse_url($item['url'], PHP_URL_HOST);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        $include = true;
        if ($this->inTwitterCache($item['url'])) {
            $include = false;
            $this->logger->debug(sprintf('Will not include "%s", is already a bookmarked tweet.', $item['url']));
        }

        $article = [
            'type'         => 'wallabag',
            'title'        => $item['title'],
            'original_url' => $item['url'],
            'host'         => $host,
            'include'      => $include,
            'date'         => new Carbon($item['created_at']), // mag ook "archived at" maar liever deze.
            'wallabag_url' => sprintf('%s/share/%s', $this->configuration['host'], $item['uid']),
            'tags'         => [],
            'annotations'  => [],
        ];

        /** @var array $tag */
        foreach ($item['tags'] as $tag) {
            $article['tags'][] = $tag['label'];
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
     * @inheritDoc
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->logger->debug('WallabagCollector has a logger!');
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
            $entry['date'] = new Carbon($entry['date']);
            //$entry['created_at']  = new Carbon($entry['created_at']);

            $this->collection[$index] = $entry;
        }

    }

    /**
     * @return void
     */
    private function collectTwitterCache(): void
    {
        $this->twitterCache = [];
        $cacheFile          = sprintf('%s/%s', CACHE, 'twitter-cache.json');
        if (file_exists($cacheFile)) {
            $text               = file_get_contents($cacheFile);
            $json               = json_decode($text, true, 24);
            $this->twitterCache = $json['data'];
        }
    }

    /**
     * @param string $url
     * @return bool
     */
    private function inTwitterCache(string $url): bool
    {
        if (0 === count($this->twitterCache)) {
            return false;
        }
        foreach ($this->twitterCache as $entry) {
            if ($entry['url'] === $url) {
                return true;
            }
        }
        return false;
    }
}