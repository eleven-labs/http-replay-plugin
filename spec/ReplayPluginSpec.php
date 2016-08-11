<?php
namespace spec\Http\Client\Common\Plugin;

use Http\Client\Common\Plugin;
use Http\Message\StreamFactory;
use Http\Promise\FulfilledPromise;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
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


    public function it_record_an_http_response(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, ResponseInterface $response, StreamInterface $requestStream, StreamInterface $responseStream)
    {
        $requestHttpBody = 'body';
        $requestStream->__toString()->willReturn($requestHttpBody);

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn('/');
        $request->getHeaders()->willReturn(['foo' => ['bar']]);
        $request->getBody()->willReturn($requestStream);

        $responseHttpBody = '{"baz": "bat"}';
        $responseStream->__toString()->willReturn($responseHttpBody);
        $responseStream->isSeekable()->willReturn(true);
        $responseStream->rewind()->shouldBeCalled();

        $response->getStatusCode()->willReturn(200);
        $response->getReasonPhrase()->willReturn('Ok');
        $response->getHeaders()->willReturn(['foo' => ['bar']]);
        $response->getBody()->willReturn($responseStream);

        $pool->getItem(Argument::any())->willReturn($item)->shouldBeCalled();
        $pool->save($item)->shouldBeCalled();

        $item->isHit()->willReturn(false)->shouldBeCalled();
        $item->set([
            'response' => $response->getWrappedObject(),
            'body' => $responseHttpBody
        ])->shouldBeCalled();


        $next = function () use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->enableRecorder();
        $this->setBucket('test-bucket');
        $this->handleRequest($request, $next, function (){});
    }


}
