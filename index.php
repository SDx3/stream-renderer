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

declare(strict_types=1);

use App\Collector\FirefoxCollector;
use App\Collector\MastodonCollector;
use App\Collector\RSSCollector;
use App\Collector\SpotifyCollector;
use App\Collector\TwitterCollector;
use App\Collector\WallabagCollector;
use App\Data\PinBoard;
use App\Filter\PostFilter;
use App\Processor\FirefoxProcessor;
use App\Processor\MastodonProcessor;
use App\Processor\RSSProcessor;
use App\Processor\SpotifyProcessor;
use App\Processor\TwitterProcessor;
use App\Processor\WallabagProcessor;
use Dotenv\Dotenv;
use Monolog\Logger;

/** @var Logger $log */
/** @var Dotenv $dotenv */

require 'init.php';
$dotenv->safeLoad();

if (false === realpath($_ENV['BLOG_PATH'])) {
    die("BLOG_PATH resolves false.\n");
}

// delete all MD files (the great reset):
$log->debug('Deleting old posts...');
$path = scandir(realpath($_ENV['BLOG_PATH']));
foreach ($path as $file) {
    if ('.' !== $file && '..' !== $file && '_index.md' !== $file) {
        if (str_ends_with($file, '.md')) {
            $path = realpath(sprintf("%s/%s", $_ENV['BLOG_PATH'], $file));
            unlink($path);
        }
    }
}
$log->debug('Done!');

// all collected items
$bookmarkedTweets = [];
$bookmarkedToots  = [];
$articles         = [];
$bookmarks        = [];
$feedArticles     = [];
$songs            = [];

// create pinboard collection object
$pinBoard = null;
if ('true' === $_ENV['RUN_PINBOARD']) {
    $pinBoard = new PinBoard;
    $pinBoard->setLogger($log);
    $pinBoard->setUser($_ENV['PINBOARD_USER']);
    $pinBoard->setToken($_ENV['PINBOARD_TOKEN']);
}

// collect Twitter
if ('true' === $_ENV['RUN_TWITTER']) {
    $collector = new TwitterCollector;
    $collector->setLogger($log);
    $collector->setPinBoard($pinBoard);
    $collector->setConfiguration(
        [
            'client_id'     => $_ENV['TWITTER_CLIENT_ID'],
            'client_secret' => $_ENV['TWITTER_CLIENT_SECRET'],
            'user_id'       => $_ENV['TWITTER_USER_ID'],
        ]
    );
    $collector->collect();
    $bookmarkedTweets = $collector->getCollection();

    // grab PinBoard instance from the Twitter collector. it will contain all the tags it found.
    $pinBoard = $collector->getPinBoard();
}
$pinBoard?->saveCache();

// collect Wallabag
if ('true' === $_ENV['RUN_WALLABAG']) {
    $collector     = new WallabagCollector;
    $configuration = [
        'client_id'     => $_ENV['WALLABAG_CLIENT_ID'],
        'client_secret' => $_ENV['WALLABAG_CLIENT_SECRET'],
        'username'      => $_ENV['WALLABAG_USERNAME'],
        'password'      => $_ENV['WALLABAG_PASSWORD'],
        'host'          => $_ENV['WALLABAG_HOST'],
    ];
    $collector->setConfiguration($configuration);
    $collector->setLogger($log);
    $collector->setPinBoard($pinBoard);
    $collector->collect();
    $articles = $collector->getCollection();

    // grab PinBoard instance from the Wallabag collector. it will contain all the tags it found.
    $pinBoard = $collector->getPinBoard();
}

// make pinboard save its list of blocked tags:
$pinBoard?->saveCache();


// collect bookmarks
if ('true' === $_ENV['RUN_BOOKMARKS']) {
    $collector = new FirefoxCollector();
    $collector->setLogger($log);
    $collector->setPinBoard($pinBoard);
    $collector->setConfiguration(
        [
            'exclude_hosts' => explode(',', $_ENV['EXCLUDE_HOSTS']),
        ]
    );
    $collector->collect();
    $bookmarks = $collector->getCollection();
    $pinBoard  = $collector->getPinBoard();
}

// make pinboard save its list of blocked tags:
$pinBoard?->saveCache();

// collect RSS
if ('true' === $_ENV['RUN_RSS']) {
    $collector = new RSSCollector;
    $collector->setLogger($log);
    $collector->setPinBoard($pinBoard);
    $collector->setConfiguration(['feed' => $_ENV['PUBLISHED_ARTICLES_FEED']]);
    $collector->collect();
    $feedArticles = $collector->getCollection();
    $pinBoard     = $collector->getPinBoard();
}

// collect Mastodon
if ('true' === $_ENV['RUN_MASTODON']) {
    $collector = new MastodonCollector;
    $collector->setLogger($log);
    $collector->setPinBoard($pinBoard);
    $collector->setConfiguration(
        [
            'host'     => $_ENV['MASTODON_HOST'],
            'user'     => $_ENV['MASTODON_USER'],
            'redirect' => $_ENV['MASTODON_REDIRECT'],
            'key'      => $_ENV['MASTODON_KEY'],
            'secret'   => $_ENV['MASTODON_SECRET'],
        ]
    );
    $collector->collect();
    $bookmarkedToots = $collector->getCollection();

    // grab PinBoard instance from the Twitter collector. it will contain all the tags it found.
    $pinBoard = $collector->getPinBoard();
}

// collect Spotify
if ('true' === $_ENV['RUN_SPOTIFY']) {
    $collector = new SpotifyCollector;
    $collector->setLogger($log);
    $collector->setPinBoard($pinBoard);
    $collector->setConfiguration(
        [
            'client_id'     => $_ENV['SPOTIFY_CLIENT_ID'],
            'client_secret' => $_ENV['SPOTIFY_CLIENT_SECRET'],
            'redirect'      => $_ENV['SPOTIFY_REDIRECT'],
            'username'      => $_ENV['SPOTIFY_USERNAME'],
        ]
    );
    $collector->collect();
    $songs = $collector->getCollection();

    // grab PinBoard instance from the Twitter collector. it will contain all the tags it found.
    $pinBoard = $collector->getPinBoard();
}


$pinBoard?->saveCache();

// make pinboard save its list of blocked tags:
$pinBoard?->saveCache();

// filter content:
$filter = new PostFilter();
$filter->setLogger($log);
$filter->setIgnoreHosts(explode(',', $_ENV['EXCLUDE_HOSTS']));
$filter->setWallabag($articles);
$filter->setRss($feedArticles);
$filter->setTweets($bookmarkedTweets);
$filter->setBookmarks($bookmarks);
$filter->setToots($bookmarkedToots);
$filter->setSongs($songs);

$articles         = $filter->getFilteredWallabag();
$feedArticles     = $filter->getFilteredRss();
$bookmarkedTweets = $filter->getFilteredTweets();
$bookmarks        = $filter->getFilteredBookmarks();
$bookmarkedToots  = $filter->getFilteredToots();
$songs            = $filter->getFilteredSongs();


// now process the result of the wallabag collection
if ('true' === $_ENV['RUN_WALLABAG']) {
    $processor = new WallabagProcessor;
    $processor->setLogger($log);
    $processor->setDestination(realpath($_ENV['BLOG_PATH']));
    $processor->setTitleLength((int)$_ENV['TITLE_LENGTH']);
    $processor->process($articles);
}

// now process the result of the Mastodon collection
if ('true' === $_ENV['RUN_MASTODON']) {
    $processor = new MastodonProcessor;
    $processor->setLogger($log);
    $processor->setDestination(realpath($_ENV['BLOG_PATH']));
    $processor->setTitleLength((int)$_ENV['TITLE_LENGTH']);
    $processor->process($bookmarkedToots);
}

// now process RSS
if ('true' === $_ENV['RUN_RSS']) {
    $processor = new RSSProcessor;
    $processor->setLogger($log);
    $processor->setDestination(realpath($_ENV['BLOG_PATH']));
    $processor->setTitleLength((int)$_ENV['TITLE_LENGTH']);
    $processor->process($feedArticles);
}

// now process tweets
if ('true' === $_ENV['RUN_TWITTER']) {
    $processor = new TwitterProcessor;
    $processor->setLogger($log);
    $processor->setDestination(realpath($_ENV['BLOG_PATH']));
    $processor->setTitleLength((int)$_ENV['TITLE_LENGTH']);
    $processor->process($bookmarkedTweets);
}

// now process songs
if ('true' === $_ENV['RUN_SPOTIFY']) {
    $processor = new SpotifyProcessor;
    $processor->setLogger($log);
    $processor->setDestination(realpath($_ENV['BLOG_PATH']));
    $processor->setTitleLength((int)$_ENV['TITLE_LENGTH']);
    $processor->process($songs);
}

// now process bookmarks
if ('true' === $_ENV['RUN_BOOKMARKS']) {


    $processor = new FirefoxProcessor;
    $processor->setLogger($log);
    $processor->setDestination(realpath($_ENV['BLOG_PATH']));
    $processor->setTitleLength((int)$_ENV['TITLE_LENGTH']);
    $processor->process($bookmarks);
}
