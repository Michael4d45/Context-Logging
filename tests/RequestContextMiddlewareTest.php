<?php

namespace Michael4d45\ContextLogging\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Middleware\RequestContextMiddleware;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

class RequestContextMiddlewareTest extends TestCase
{
    #[Test]
    public function it_promotes_buffered_pre_middleware_events_into_the_request_context(): void
    {
        // Subclass simulates HTTP (pre-lifecycle buffering); PHPUnit runs in the console so the default store would emit standalone.
        $contextStore = new class extends ContextStore {
            protected function shouldBufferPreLifecycleEvents(): bool
            {
                return true;
            }
        };

        $contextStore->addEvent('info', 'Broadcasting channels loaded');

        $this->assertCount(1, $contextStore->getBufferedEvents());

        $middleware = new RequestContextMiddleware($contextStore);
        $request = Request::create('https://example.test/broadcasting/auth?socket_id=123', 'POST');

        $response = $middleware->handle($request, static fn () => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame('POST', $contextStore->getContext('method'));
        $this->assertSame('broadcasting/auth', $contextStore->getContext('path'));
        $this->assertSame('https://example.test/broadcasting/auth?socket_id=123', $contextStore->getContext('full_url'));
        $this->assertCount(1, $contextStore->getEvents());
        $this->assertSame('Broadcasting channels loaded', $contextStore->getEvents()[0]['message']);
        $this->assertSame([], $contextStore->getBufferedEvents());
    }

    #[Test]
    public function it_adds_incoming_request_for_empty_get_when_request_logging_enabled(): void
    {
        config()->set('context-logging.log.request', true);

        $contextStore = new ContextStore;
        $middleware = new RequestContextMiddleware($contextStore);
        $request = Request::create('https://example.test/ingredient-parser-flow', 'GET');

        $middleware->handle($request, static fn () => new Response('ok'));

        $events = $contextStore->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('Incoming Request', $events[0]['message']);
        $this->assertArrayHasKey('headers', $events[0]['context']);
        $this->assertArrayHasKey('cookies', $events[0]['context']);
        $this->assertArrayNotHasKey('body', $events[0]['context']);
        $this->assertArrayNotHasKey('query_params', $events[0]['context']);
    }

    #[Test]
    public function it_includes_body_and_query_on_incoming_request_when_present(): void
    {
        config()->set('context-logging.log.request', true);

        $contextStore = new ContextStore;
        $middleware = new RequestContextMiddleware($contextStore);
        $request = Request::create(
            'https://example.test/items?page=2',
            'POST',
            ['name' => 'flour']
        );

        $middleware->handle($request, static fn () => new Response('ok'));

        $event = $contextStore->getEvents()[0];
        $this->assertSame('Incoming Request', $event['message']);
        $this->assertSame('flour', $event['context']['body']['name']);
        $this->assertSame('2', $event['context']['query_params']['page']);
    }

    #[Test]
    public function it_preserves_a_shallow_livewire_action_summary(): void
    {
        config()->set('context-logging.log.request', true);

        $contextStore = new ContextStore;
        $middleware = new RequestContextMiddleware($contextStore);
        $snapshot = json_encode([
            'data' => [],
            'memo' => [
                'id' => 'component-123',
                'name' => 'App\\Livewire\\EditOrder',
            ],
        ], JSON_THROW_ON_ERROR);
        $request = Request::create(
            'https://example.test/livewire-abc123/update',
            'POST',
            [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [[
                        'method' => 'mountAction',
                        'params' => ['Approve Order'],
                    ]],
                ]],
            ],
        );
        $request->headers->set('X-Livewire', '1');

        $middleware->handle($request, static fn () => new Response('ok'));

        $event = $contextStore->getEvents()[0];
        $this->assertSame([[
            'component' => 'App\\Livewire\\EditOrder',
            'component_id' => 'component-123',
            'method' => 'mountAction',
            'action' => 'Approve Order',
        ]], $event['context']['livewire_actions']);
    }

    #[Test]
    public function it_summarizes_filament_lazy_load_payloads(): void
    {
        config()->set('context-logging.log.request', true);

        $contextStore = new ContextStore;
        $middleware = new RequestContextMiddleware($contextStore);
        $lazyPayload = base64_encode(json_encode([
            'data' => [
                'forMount' => [[
                    'ownerRecord' => [null, [
                        'class' => 'App\\Models\\User',
                        'key' => 5,
                        's' => 'mdl',
                    ]],
                    'pageClass' => 'App\\Filament\\Resources\\UserResource\\Pages\\EditUser',
                ]],
            ],
        ], JSON_THROW_ON_ERROR));
        $snapshot = json_encode([
            'data' => [],
            'memo' => [
                'id' => 'component-456',
                'name' => 'App\\Admin\\Resources\\UserResource\\RelationManagers\\ShipmentOrdersRelationManager',
            ],
        ], JSON_THROW_ON_ERROR);
        $request = Request::create(
            'https://example.test/livewire/update',
            'POST',
            [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [[
                        'method' => '__lazyLoad',
                        'params' => [$lazyPayload],
                    ]],
                ]],
            ],
        );
        $request->headers->set('X-Livewire', '1');

        $middleware->handle($request, static fn () => new Response('ok'));

        $event = $contextStore->getEvents()[0];
        $this->assertSame([[
            'component' => 'App\\Admin\\Resources\\UserResource\\RelationManagers\\ShipmentOrdersRelationManager',
            'component_id' => 'component-456',
            'method' => '__lazyLoad',
            'action' => 'User#5',
        ]], $event['context']['livewire_actions']);
    }

    #[Test]
    public function it_drops_opaque_livewire_params_that_cannot_be_summarized(): void
    {
        config()->set('context-logging.log.request', true);

        $contextStore = new ContextStore;
        $middleware = new RequestContextMiddleware($contextStore);
        $snapshot = json_encode([
            'data' => [],
            'memo' => [
                'id' => 'component-789',
                'name' => 'App\\Livewire\\Widget',
            ],
        ], JSON_THROW_ON_ERROR);
        $request = Request::create(
            'https://example.test/livewire/update',
            'POST',
            [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [[
                        'method' => '__dispatch',
                        'params' => [base64_encode(str_repeat('x', 64))],
                    ]],
                ]],
            ],
        );
        $request->headers->set('X-Livewire', '1');

        $middleware->handle($request, static fn () => new Response('ok'));

        $event = $contextStore->getEvents()[0];
        $this->assertSame([[
            'component' => 'App\\Livewire\\Widget',
            'component_id' => 'component-789',
            'method' => '__dispatch',
            'action' => null,
        ]], $event['context']['livewire_actions']);
    }

    #[Test]
    public function it_skips_incoming_request_when_request_logging_disabled(): void
    {
        config()->set('context-logging.log.request', false);

        $contextStore = new ContextStore;
        $middleware = new RequestContextMiddleware($contextStore);
        $request = Request::create('https://example.test/items', 'POST', ['name' => 'flour']);

        $middleware->handle($request, static fn () => new Response('ok'));

        $this->assertSame([], $contextStore->getEvents());
    }

    #[Test]
    public function it_records_route_name_and_action_after_the_request_is_handled(): void
    {
        $contextStore = new ContextStore;
        $middleware = new RequestContextMiddleware($contextStore);
        $request = Request::create('https://example.test/admin', 'GET');
        $request->setRouteResolver(static fn () => new Route(['GET'], 'admin', [
            'as' => 'filament.admin.pages.account-resolution-outreach-import',
            'controller' => 'App\\Admin\\Pages\\AccountResolutionOutreachImportPage',
        ]));

        $middleware->handle($request, static fn () => new Response('ok'));

        $this->assertSame(
            'filament.admin.pages.account-resolution-outreach-import',
            $contextStore->getContext('route_name'),
        );
        $this->assertSame(
            'App\\Admin\\Pages\\AccountResolutionOutreachImportPage',
            $contextStore->getContext('action'),
        );
    }

    #[Test]
    public function it_prefers_the_livewire_component_as_the_code_path(): void
    {
        $contextStore = new ContextStore;
        $middleware = new RequestContextMiddleware($contextStore);
        $snapshot = json_encode([
            'data' => [],
            'memo' => [
                'id' => 'component-123',
                'name' => 'App\\Livewire\\EditOrder',
            ],
        ], JSON_THROW_ON_ERROR);
        $request = Request::create(
            'https://example.test/livewire/update',
            'POST',
            [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [[
                        'method' => 'save',
                        'params' => [],
                    ]],
                ]],
            ],
        );
        $request->headers->set('X-Livewire', '1');
        $request->setRouteResolver(static fn () => new Route(['POST'], 'livewire/update', [
            'as' => 'livewire.update',
            'controller' => 'Livewire\\Mechanisms\\HandleRequests\\HandleRequests@handleUpdate',
        ]));

        $middleware->handle($request, static fn () => new Response('ok'));

        $this->assertSame('livewire.update', $contextStore->getContext('route_name'));
        $this->assertSame('App\\Livewire\\EditOrder@save', $contextStore->getContext('action'));
    }
}
