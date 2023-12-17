<?php
declare(strict_types=1);

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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

require 'init.php';

if (array_key_exists('code', $_GET)) {
    $code = $_GET['code'];

    // do request at Spotify.

    $client = new Client;

    $opts = [
        'headers'     => [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'auth'        => [$_ENV['SPOTIFY_CLIENT_ID'], $_ENV['SPOTIFY_CLIENT_SECRET']],
        'form_params' => [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $_ENV['SPOTIFY_REDIRECT'],
        ],
    ];

    try {
        $res = $client->request('POST', 'https://accounts.spotify.com/api/token', $opts);
    } catch (ClientException $e) {
        echo ':(';
        var_dump($e->getRequest());
        var_dump((string)$e->getResponse()->getBody());
        exit;
    }
    $body                = (string)$res->getBody();
    $json                = json_decode($body, true);
    $json['expire_time'] = time() + $json['expires_in'];

    $file = sprintf('%s/spotify-auth.json', CACHE);
    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT));

    echo 'The Spotify access token is saved in the cache file!';
    echo '<br>';
    echo 'If necessary, put this file in <code>cache/spotify-auth.json</code>:';
    echo '<br><br>';
    echo '<pre>' . json_encode($json, JSON_PRETTY_PRINT) . '</pre>';
    exit;
}
