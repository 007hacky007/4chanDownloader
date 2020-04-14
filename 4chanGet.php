#!/usr/bin/env php
<?php

try {
    $chanGet = new chanGet();
    $chanGet->get();
} catch (Throwable $exception) {
    die($exception->getMessage());
}

// TODO: track total post count
// TODO: convert webm to mp4
// TODO: possibility to watch more threads at once (list of active threads to watch)
class chanGet {
    private $board;
    private $thread;
    private $updateTime = 10; // in seconds
    private $postCount = 0;
    private $semantic_url = "";
    private $files = [];

    const YEAR_SECONDS=31556926;

    public function __construct()
    {
        $this->getInput();
    }

    private function getInput()
    {
        global $argv;
        if(!isset($argv[1]))
            throw new Exception('Usage: ' . $argv[0] .' <4chan thread url>');

        $t = explode('/', $argv[1]);
        if(!isset($t[3]) || !isset($t[5]))
            throw new Exception('Malformed URL. Example URL: https://boards.4chan.org/wg/thread/123456789');

        $this->board = $t[3];
        $this->thread = $t[5];
    }

    private static function createDlDir(string $dir)
    {
        if($dir === "")
            throw new Exception('Could not get download dir name');
        if(!is_dir("./$dir")) mkdir("./$dir");
    }

    private function fetchAllPosts(array $posts)
    {
        foreach ($posts as $post) {
            if(!isset($post->filename) || isset($this->files[$post->tim . $post->ext]))
                continue;

            $this->postCount++;
            $this->files[$post->tim . $post->ext] = true;
            if(!isset($cr)) {
                echo "\r";
                $cr = true;
            }
            printf('[%s] Downloading %s... ', $this->postCount, $post->tim . $post->ext);
            try {
                downloader::fetchFile($this->board, $post->tim . $post->ext, $this->semantic_url);
                echo "OK\n";
            } catch (Throwable $e){
                echo $e->getMessage() . PHP_EOL;
            }
        }
    }

    public function get()
    {
        while(true) {
            list($result, $http_code, $ts) = downloader::fetch($this->board, $this->thread, (isset($ts) ? $ts : time() - chanGet::YEAR_SECONDS));
            if($http_code === 200) {
                if($this->semantic_url === "") {
                    $this->semantic_url = $result->posts[0]->semantic_url;
                    chanGet::createDlDir($this->semantic_url);
                }
                $this->fetchAllPosts($result->posts);
            } elseif ($http_code === 304) {
                echo '.';
            } else {
                echo 'Unknown HTTP response! (' . $http_code . ')' . PHP_EOL;
            }
            sleep($this->updateTime);
        }
    }
}

class downloader {
    public static function fetch(string $board, string $thread, int $timestamp = 0): array
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, sprintf('https://a.4cdn.org/%s/thread/%s.json', $board, $thread));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');

        $headers = array();
        $headers[] = 'Authority: a.4cdn.org';
        $headers[] = 'Cache-Control: max-age=0';
        $headers[] = 'Sec-Fetch-Dest: empty';
        $headers[] = 'If-Modified-Since: ' . gmdate(DATE_RFC2822, ($timestamp ? $timestamp : time()));
        $headers[] = 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.87 Safari/537.36';
        $headers[] = 'Dnt: 1';
        $headers[] = 'Accept: */*';
        $headers[] = 'Origin: https://boards.4chan.org';
        $headers[] = 'Sec-Fetch-Site: cross-site';
        $headers[] = 'Sec-Fetch-Mode: cors';
        $headers[] = 'Referer: https://boards.4chan.org/';
        $headers[] = 'Accept-Language: en-US,cs;q=0.9,en;q=0.8';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, 1);

        $response = curl_exec($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        if (curl_errno($ch)) {
            throw new Exception('Error:' . curl_error($ch));
        }
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);
        if($http_code === 200) {
            if(($result = json_decode($body)) === false)
                throw new Exception('Failed to json_decode curl result: ' . $result);
        } elseif ($http_code === 404) {
            throw new Exception('Thread has been deleted');
        }


        return [isset($result) ? $result : [], $http_code, strtotime(downloader::getDate($header))];
    }

    private static function getDate(string $header): string
    {
	preg_match('/^[lL]ast-modified: (.*)$/m', $header, $matches);

        return $matches[1];
    }

    public static function fetchFile(string $board, string $filename, string $dlDir)
    {
        if(file_exists($dlDir . '/' . $filename))
            throw new Exception('file already exists');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf('https://i.4cdn.org/%s/%s', $board, $filename));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $headers[] = 'Authority: i.4cdn.org';
        $headers[] = 'Cache-Control: max-age=0';
        $headers[] = 'Dnt: 1';
        $headers[] = 'Upgrade-Insecure-Requests: 1';
        $headers[] = 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.87 Safari/537.36';
        $headers[] = 'Sec-Fetch-Dest: document';
        $headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9';
        $headers[] = 'Sec-Fetch-Site: none';
        $headers[] = 'Sec-Fetch-Mode: navigate';
        $headers[] = 'Sec-Fetch-User: ?1';
        $headers[] = 'Referer: https://boards.4chan.org/';
        $headers[] = 'Accept-Language: en-US,cs;q=0.9,en;q=0.8';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $st = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('Error:' . curl_error($ch));
        }
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($http_code === 404) {
            throw new Exception('File has been deleted');
        }

        $fd = fopen($dlDir . '/' . $filename, 'w');
        fwrite($fd, $st);
        fclose($fd);
    }
}
