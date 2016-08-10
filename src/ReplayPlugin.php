<?php
namespace Http\Client\Common\Plugin;

use Http\Client\Common\Plugin;
use Http\Message\StreamFactory;
use Http\Promise\FulfilledPromise;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ReplayPlugin implements Plugin
{
    /**
     * @var CacheItemPoolInterface
     */
    private $pool;

    /**
     * @var StreamFactory
     */
    private $streamFactory;

    /**
     * Specify a replay bucket
     *
     * @var string
     */
    private $bucket;

    /**
     * Record mode is disabled by default, so we can prevent dumb mistake
     *
     * @var bool
     */
    private $recordModeEnabled = false;

    public function __construct(CacheItemPoolInterface $pool, StreamFactory $streamFactory)
    {
        $this->pool = $pool;
        $this->streamFactory = $streamFactory;
    }

    public function setBucket($name)
    {
        $this->bucket = $name;
    }

    public function enableRecordMode()
    {
        $this->recordModeEnabled = true;
    }

    public function handleRequest(RequestInterface $request, callable $next, callable $first)
    {
        $cacheKey = $this->createCacheKey($request);
        $cacheItem = $this->pool->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return new FulfilledPromise($this->createResponseFromCacheItem($cacheItem));
        }

        if (! $this->isRecordModeEnabled()) {
            throw new \RuntimeException(sprintf(
                'Cannot replay request "%s" because record mode is disable',
                $request->getMethod().' '.$request->getUri()
            ));
        }

        return $next($request)->then(function (ResponseInterface $response) use ($cacheItem) {

            $bodyStream = $response->getBody();
            $body = $bodyStream->__toString();
            if ($bodyStream->isSeekable()) {
                $bodyStream->rewind();
            }

            $cacheItem->set(['response' => $response, 'body' => $body]);
            $this->pool->save($cacheItem);

            return $response;
        });
    }

    /**
     * Create a cache key from one request method, uri, headers and body
     *
     * @param RequestInterface $request
     *
     * @return string The created cache key
     */
    private function createCacheKey(RequestInterface $request)
    {
        if ($this->bucket === null) {
            throw new \LogicException('You need to specify a replay bucket');
        }

        $parts = [
            $request->getMethod(),
            $request->getUri(),
            implode(
                ' ',
                array_map(
                    function ($key, array $values) {
                        return $key.':'.implode(',', $values);
                    },
                    array_keys($request->getHeaders()),
                    $request->getHeaders()
                )
            ),
            $request->getBody()
        ];

        return $this->bucket.'-'.hash('sha1', implode(' ', $parts));
    }

    /**
     * @param CacheItemInterface $cacheItem
     *
     * @return ResponseInterface
     */
    private function createResponseFromCacheItem(CacheItemInterface $cacheItem)
    {
        $data = $cacheItem->get();

        return $data['response']->withBody(
            $this->streamFactory->createStream($data['body'])
        );
    }

    /**
     * @return boolean
     */
    public function isRecordModeEnabled()
    {
        return $this->recordModeEnabled;
    }

}