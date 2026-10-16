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

    // do request at Mastodon.

    // curl -X POST \
    //	-F 'client_id=your_client_id_here' \
    //	-F 'client_secret=your_client_secret_here' \
    //	-F 'redirect_uri=urn:ietf:wg:oauth:2.0:oob' \
    //	-F 'grant_type=authorization_code' \
    //	-F 'code=user_authzcode_here' \
    //	-F 'scope=read write push' \
    //	https://mastodon.example/oauth/token

    $client = new Client;

    $opts = [
        'headers'     => [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
            'client_id'     => $_ENV['RAINDROP_CLIENT_ID'],
            'client_secret' => $_ENV['RAINDROP_CLIENT_SECRET'],
            'redirect_uri'  => $_ENV['RAINDROP_REDIRECT'],
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'scope'         => 'read',
        ],
    ];

    try {
        $res = $client->request('POST', 'https://raindrop.io/oauth/access_token', $opts);
    } catch (ClientException $e) {
        echo ':(';
        var_dump($e->getRequest());
        var_dump((string)$e->getResponse()->getBody());
        exit;
    }
    $body                = (string)$res->getBody();
    $json                = json_decode($body, true);

    $file = sprintf('%s/raindrop-auth.json', CACHE);
    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT));

    echo 'The Raindrop access token is saved in the cache file!';
    echo '<br>';
    echo 'If necessary, put this file in <code>cache/raindrop-auth.json</code>:';
    echo '<br><br>';
    echo '<pre>' . json_encode($json, JSON_PRETTY_PRINT) . '</pre>';
    exit;
}
