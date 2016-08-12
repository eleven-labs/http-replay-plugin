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

        $this->shouldThrow(
            new \LogicException('You need to specify a replay bucket')
        )->duringHandleRequest($request, $next, function(){});
    }

    public function it_need_the_user_to_enable_record_mode(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, ResponseInterface $response)
    {
        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn('/');
        $request->getHeaders()->willReturn(['foo' => ['bar']]);
        $request->getBody()->willReturn('body');

        $item->isHit()->willReturn(false)->shouldBeCalled();

        $pool->getItem('test-7a3defce4e5e9074e4bb6868a6875fddba6b1670')->willReturn($item);

        $next = function () use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->setBucket('test');

        $this->shouldThrow(
            new \RuntimeException('Cannot replay request "GET /" because record mode is disable')
        )->duringHandleRequest($request, $next, function(){});
    }


    public function it_record_an_http_response(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, ResponseInterface $response, StreamInterface $stream)
    {
        $httpBody = 'body';

        $request->getMethod()->willReturn('POST');
        $request->getUri()->willReturn('/');
        $request->getHeaders()->willReturn(['foo' => ['bar']]);
        $request->getBody()->willReturn('body');

        $stream->__toString()->willReturn($httpBody);
        $stream->isSeekable()->willReturn(true);
        $stream->rewind()->shouldBeCalled();

        $response->getStatusCode()->willReturn(200);
        $response->getReasonPhrase()->willReturn('OK');
        $response->getHeaders()->willReturn(['bar' => ['baz']]);
        $response->getBody()->willReturn($stream);

        $pool->getItem('test-bucket-590d21bf0f0be63ba8c0cc67fbde8b2cc02c4314')->willReturn($item)->shouldBeCalled();
        $pool->save($item)->shouldBeCalled();

        $item->isHit()->willReturn(false);
        $item->set([
            'response' => $response->getWrappedObject(),
            'body' => $httpBody
        ])->shouldBeCalled();

        $next = function () use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->enableRecorder();
        $this->setBucket('test-bucket');

        $this->handleRequest($request, $next, function (){});
    }

    function it_replay_a_recorded_response(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, ResponseInterface $response, StreamInterface $stream, StreamFactory $streamFactory)
    {
        $httpBody = 'body';

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn('/');
        $request->getHeaders()->willReturn(['foo' => ['bar']]);
        $request->getBody()->willReturn('');

        $pool->getItem('test-bucket-0052ccfb7378a0478e9013720c1ba606bcf36131')->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(true);
        $item->get()->willReturn([
            'response' => $response,
            'body' => $httpBody
        ])->shouldBeCalled();

        // Make sure we add back the body
        $response->withBody($stream)->willReturn($response)->shouldBeCalled();
        $streamFactory->createStream($httpBody)->shouldBeCalled()->willReturn($stream);

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->enableRecorder();
        $this->setBucket('test-bucket');

        $this->handleRequest($request, $next, function () {});
    }

}
