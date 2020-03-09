<?php

// src/Service/CacheFileFetcher.php

namespace App\Service;

use Symfony\Component\HttpClient\CachingHttpClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\HttpCache\Store;
use Psr\Log\LoggerInterface;

class CacheFileFetcher
{
    private $logger;
    private $cacheDirectory;

    public function __construct( $cacheDirectory, LoggerInterface $logger)
    {
        $this->cacheDirectory = $cacheDirectory;
        $this->logger = $logger;
    }

    // get a file from cache
    public function getFile( $filename)
    {
	// Init cache store dir
        $store = new Store( $this->getCacheDirectory());
        $client = HttpClient::create();
        $cache_client = new CachingHttpClient($client, $store, ["debug" => true]);

	// Fetch the file from the cache
        $URL = $_ENV['SQUID_CACHE_URL'].$filename;
        $this->logger->debug('URL '.$URL);
        $response = $cache_client->request('GET', $URL);

        $statusCode = $response->getStatusCode();
        // $statusCode = 200
        $this->logger->debug('Status code '.$statusCode);
/*
        try {
*/
            $contentType = $response->getHeaders()['content-type'][0];
            $this->logger->debug('Content type '.$contentType);
/*
        } catch (FileException $e) {

            $this->logger->debug( $e->getMessage());

	    // Return an empty object
            // ... handle exception if something happens during file upload
        }
*/
        // $contentType = 'application/json'
//      $content = gzdecode( $response->getContent());
//        $content = $response->getContent();
//        $this->logger->debug('Content '.$content);
        // $content = '{"id":521583, "name":"symfony-docs", ...}'
//      $this->moves = json_decode( $content);
//        $this->moves = $response->toArray();
        // $content = ['id' => 521583, 'name' => 'symfony-docs', ...]
//        $this->logger->debug('Moves '.$this->moves);

	return $response;
    }

    public function getCacheDirectory()
    {
        return $this->cacheDirectory;
    }

}
?>
