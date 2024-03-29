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
use Monolog\Logger;
use SimplePie;
use SimplePie_Category;
use SimplePie_Item;

class RSSCollector implements CollectorInterface
{
    private array     $collection;
    private array     $configuration;
    private Logger    $logger;
    private ?PinBoard $pinBoard;

    /**
     * @inheritDoc
     */
    public function collect(bool $skipCache = false): void
    {
        $this->logger->debug('Now collecting from RSS...');
        $link             = $this->configuration['feed'];
        $this->collection = [];
        $feed             = new SimplePie;
        $index            = 0;
        $feed->set_feed_url($link);
        $feed->init();
        $feed->handle_content_type();
        $total = count($feed->get_items());
        /** @var SimplePie_Item $item */
        foreach ($feed->get_items() as $item) {
            $index++;
            $this->logger->debug(sprintf('[%d/%d] Processing feed item.', $index, $total));
            // parse original host name
            $host = parse_url($item->get_permalink(), PHP_URL_HOST);
            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }

            $current = [
                'url'     => $item->get_permalink(),
                'date'    => Carbon::createFromFormat('Y-m-d H:i:s', $item->get_date('Y-m-d H:i:s'), $_ENV['TZ']),
                'title'   => $item->get_title(),
                'host'    => $host,
                'content' => strip_tags($item->get_description(true)),
            ];

            // items collected from RSS may have html-escaped characters in their titles. They should be replaced:
            $search           = ['&#039;'];
            $replace          = ["'"];
            $current['title'] = str_replace($search, $replace, $current['title']);

            $tags = [];
            /** @var SimplePie_Category $cat */
            if (null !== $item->get_categories()) {
                foreach ($item->get_categories() as $cat) {
                    $tags[] = $cat->get_label();
                }
            }
            $this->logger->debug(sprintf('Original tags are: %s', join(', ', $tags)));

            // get tags from Pinboard:
            if (null !== $this->pinBoard) {
                $tags = array_unique(array_merge($tags, $this->pinBoard->getTagsForUrl($current['url'])));
                $tags = $this->pinBoard->filterTags($tags, $current['url']);
                $this->logger->debug(sprintf('With PinBoard tags are: %s', join(', ', $tags)));
            }
            sort($tags);
            $current['tags'] = $tags;

            $this->collection[] = $current;
        }
        $this->logger->debug(sprintf('Done collecting from RSS, found %d item(s).', count($this->collection)));
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
     * @param null|PinBoard $pinBoard
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
        $this->logger->debug('RSSCollector now has a logger!');
    }
}
