<?php
namespace spec\Http\Client\Common\Plugin;

use Http\Client\Common\Plugin;
use Http\Message\StreamFactory;
use Http\Promise\FulfilledPromise;
use PhpSpec\ObjectBehavior;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

class ReplayPluginSpec extends ObjectBehavior
{
    function let(CacheItemPoolInterface $pool, StreamFactory $streamFactory)
    {
        $this->beConstructedWith($pool, $streamFactory);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(Plugin\ReplayPlugin::class);
    }

    function it_is_an_http_plugin()
    {
        $this->shouldImplement(Plugin::class);
    }

    public function it_need_the_user_to_pass_a_replay_bucket(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $next = function () use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->shouldThrow(new \LogicException('You need to specify a replay bucket'))
            ->duringHandleRequest($request, $next, function(){});
    }

    public function it_need_the_user_to_enable_record_mode(
        CacheItemPoolInterface $pool,
        CacheItemInterface $cacheItem,
        RequestInterface $request,
        ResponseInterface $response,
        UriInterface $uri,
        StreamInterface $stream
    ) {

        $uri->__toString()->willReturn('http://domain.tld');
        $stream->__toString()->willReturn('hello world');

        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('GET');
        $request->getHeaders()->willReturn(['foo' => ['bar']]);
        $request->getBody()->willReturn($stream);

        $pool->getItem('testing-ddc150b492f4823e4903d7abf15fcdfb8c7e6aeb')->willReturn($cacheItem);
        $next = function () use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->setBucket('testing');
        $this->shouldThrow(new \RuntimeException('Cannot replay request "GET http://domain.tld" because record mode is disable'))
            ->duringHandleRequest($request, $next, function(){});

    }
}