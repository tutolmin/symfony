<?php

// src/Service/CacheFileFetcher.php

namespace App\Service;

use Symfony\Component\HttpClient\CachingHttpClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\HttpCache\Store;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class CacheFileFetcher
{
    private $logger;
    private $cacheDirectory;

    public function __construct( $cacheDirectory, LoggerInterface $logger)
    {
        $this->cacheDirectory = $cacheDirectory;
        $this->logger = $logger;
    }

    // invalidate local cache file
    public function invalidateLocalCache( $filename)
    {
      // Init cache store dir
      $store = new Store( $this->getCacheDirectory());
      
      $URL = $_ENV['SQUID_CACHE_URL'].$filename;
      $this->logger->debug('URL '.$URL);

      // Make sure local copy of the file is invalidated locally
      $store->invalidate( Request::create( $URL));
    }

    // get a file from cache
    public function getFile( $filename, $reload = false)
    {
	      // Init cache store dir
        $store = new Store( $this->getCacheDirectory());
        $client = HttpClient::create();
        $cache_client = new CachingHttpClient($client, $store,
        ["debug" => true,
//		 "allow_reload" => true,
        ]);

	      // Fetch the file from the cache
        $URL = $_ENV['SQUID_CACHE_URL'].$filename;
        $this->logger->debug('URL '.$URL);

      	// Invalidate local cache entry to make sure
        // The URL is requested from remote cache
        if( $reload)
          $store->invalidate( Request::create( $URL));

        $response = $cache_client->request('GET', $URL, []);

        $statusCode = $response->getStatusCode();
        // $statusCode = 200
        $this->logger->debug('Status code '.$statusCode);

        if( $statusCode != 200) return null;
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
