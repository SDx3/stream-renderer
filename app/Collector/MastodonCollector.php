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
use Monolog\Logger;

/**
 * Class MastodonCollector
 */
class MastodonCollector implements CollectorInterface
{
    private array     $collection = [];
    private array     $configuration;
    private Logger    $logger;
    private ?PinBoard $pinBoard;

    /**
     * @inheritDoc
     */
    public function collect(bool $skipCache = false): void
    {
        $this->logger->debug('Start of Mastodon collection.');
        $this->collection = [];

        if ($this->hasCache()) {
            $this->logger->debug('Mastodon collection is in cache, return that instead.');
            $this->getCache();
            return;
        }

        if (!$this->hasToken()) {
            $this->logger->debug('Mastodon collector has no token.');
            $this->getNewTokens();
        }
        $this->logger->debug('Mastodon collector has a token.');

        $url    = sprintf('https://%s/api/v1/bookmarks', $this->configuration['host']);
        $client = new Client;
        $opts   = [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->configuration['access_token']),
            ],
        ];
        try {
            $res = $client->request('GET', $url, $opts);
        } catch (ClientException $e) {
            $this->logger->error('Could not get bookmarks from Mastodon.');
            if ($e->hasResponse()) {
                $this->logger->error($e->getResponse()->getBody()->getContents());
            }
            return;
        }
        $toots = json_decode($res->getBody(), true);
        $total = count($toots);
        $this->logger->debug(sprintf('Now collecting %d toots...', $total));
        $index = 0;
        /** @var array $toot */
        foreach ($toots as $toot) {
            $index++;
            $this->logger->debug(sprintf('[%d/%d] Processing toot...', $index, $total));
            $this->collection[] = $this->getToot($toot);
        }
        $this->saveToCache();
        $this->logger->debug('Done!');
    }

    private function hasCache(): bool
    {
        $cacheFile = sprintf('%s/%s', CACHE, 'mastodon-cache.json');
        if (!file_exists($cacheFile)) {
            $this->logger->debug('No cache file, return false.');
            return false;
        }
        $text = file_get_contents($cacheFile);
        $json = json_decode($text, true, 24);
        if (time() - $json['moment'] > 3600) {
            $this->logger->debug('Cache is expired, return false.');
            return false;
        }
        $this->logger->debug('Cache is valid, return true.');
        return true;
    }

    /**
     * @return void
     */
    private function getCache(): void
    {
        $cacheFile        = sprintf('%s/%s', CACHE, 'mastodon-cache.json');
        $text             = file_get_contents($cacheFile);
        $json             = json_decode($text, true, 24);
        $objects          = $json['data'];
        $this->collection = [];
        foreach ($objects as $object) {
            $object['date']     = new Carbon($object['date'], $_ENV['TZ']);
            $this->collection[] = $object;
        }
    }

    private function hasToken(): bool
    {
        $file = sprintf('%s/mastodon-auth.json', CACHE);
        if (!file_exists($file)) {
            $this->logger->debug('No cache file, so always false.');
            return false;
        }
        $content = file_get_contents($file);
        $json    = json_decode($content, true);

//        if (time() > $json['expire_time']) {
//            $this->logger->debug('Token is expired, need to get a new one.');
//            $this->configuration['refresh_token'] = $json['refresh_token'];
//            $this->getAccessToken();
//            return true;
//        }
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
            'response_type' => 'code',
            'client_id'     => $this->configuration['key'],
            'redirect_uri'  => $this->configuration['redirect'],
            'scope'         => 'read',
            'state'         => (string)random_int(1, 1000),
        ];

        $url = sprintf('https://%s/oauth/authorize?', $this->configuration['host']) . http_build_query($params);
        echo "Since you have no refresh token, please visit this URL:\n";
        echo $url;
        echo "\n";
        echo "\n";
        echo "Then take the refresh token from the vagrant VM page you'll be redirected to.\n";
        echo "\n";

        exit(1);
    }

    /**
     * @param array $data
     *
     * @return array
     * @throws GuzzleException
     * @throws GuzzleException
     */
    private function getToot(array $data): array
    {

        // get oembed code.

        $parts  = parse_url($data['url']);
        $url    = sprintf('https://%s/api/oembed', $parts['host']);
        $client = new Client;
        try {
            $res = $client->get(sprintf($url . '?url=' . $data['url'], $this->configuration['host']));
        } catch (ClientException $e) {
            $this->logger->debug(sprintf('Could not get oembed for toot %s', $data['url']));
            $this->logger->debug($e->getMessage());
            return [];
        }
        $body = (string)$res->getBody();
        $json = json_decode($body, true);

        $tags = [];
        if (null !== $this->pinBoard) {
            $tags = $this->pinBoard->filterTags($this->pinBoard->getTagsForUrl($data['url']), $data['url']);
            sort($tags);
            $this->logger->debug(sprintf('Set of tags for toot is now: %s', join(', ', $tags)));
        }

        return [
            'id'         => $data['id'],
            'date'       => Carbon::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $data['created_at'], $_ENV['TZ']),
            'title'      => strip_tags($data['content']),
            'author'     => $data['account']['display_name'],
            'categories' => $tags,
            'url'        => $data['url'],
            'html'       => $json['html'],
        ];
    }

    /**
     * @return void
     */
    private function saveToCache(): void
    {
        $cacheFile = sprintf('%s/%s', CACHE, 'mastodon-cache.json');
        $json      = [
            'moment' => time(),
            'data'   => $this->collection,
        ];
        file_put_contents($cacheFile, json_encode($json, JSON_PRETTY_PRINT));
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
    }

    /**
     * @inheritDoc
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->logger->debug('MastodonCollector now has a logger!');
    }

    /**
     * @return void
     * @throws GuzzleException
     */
    private function getAccessToken(): void
    {
        die('E');
        $this->logger->debug('Need a new access token.');
        $client = new Client;
        $opts   = [
            'headers'     => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'auth'        => [$this->configuration['client_id'], $this->configuration['client_secret']],
            'form_params' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->configuration['refresh_token'],
            ],
        ];

        $res  = $client->request('POST', 'https://api.twitter.com/2/oauth2/token', $opts);
        $body = (string)$res->getBody();
        $json = json_decode($body, true);

        // add time:
        $json['expire_time'] = time() + $json['expires_in'];

        // save to file:
        $file = sprintf('%s/twitter.json', CACHE);
        file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT));
        $this->configuration['access_token']  = $json['access_token'];
        $this->configuration['refresh_token'] = $json['refresh_token'];
    }
}
