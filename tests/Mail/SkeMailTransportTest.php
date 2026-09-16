<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Ske\Bridge\Laravel\Mail\SkeMailTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

function bootTestApp(): Application
{
    $app = new Application(sys_get_temp_dir());
    $app->instance('config', new Repository);
    $app->instance('router', new Router(new Dispatcher($app), $app));

    $app->singleton('http', fn () => new HttpFactory);

    Facade::setFacadeApplication($app);
    Facade::clearResolvedInstances();

    return $app;
}

function makeTransport(
    string $endpoint = 'https://api.ske.test/api/v1/services/ingest/mail',
    string $serviceId = '12345',
    string $token = 'test-token',
): SkeMailTransport {
    return new SkeMailTransport($endpoint, $serviceId, $token);
}

function makeEmail(array $overrides = []): Email
{
    $email = new Email;
    $email->from($overrides['from'] ?? new Address('noreply@app.test', 'App'));
    $email->to($overrides['to'] ?? new Address('user@example.com', 'User'));
    $email->subject($overrides['subject'] ?? 'Test Subject');
    $email->text($overrides['text'] ?? 'Plain text body');
    $email->html($overrides['html'] ?? '<p>HTML body</p>');

    if (isset($overrides['cc'])) {
        $email->cc($overrides['cc']);
    }

    if (isset($overrides['attachments'])) {
        foreach ($overrides['attachments'] as $attachment) {
            $email->attach($attachment['content'], $attachment['name'], $attachment['type']);
        }
    }

    return $email;
}

function sendViaTransport(SkeMailTransport $transport, Email $email): void
{
    $envelope = new Envelope(
        $email->getFrom()[0],
        array_map(fn (Address $a) => $a, $email->getTo()),
    );
    $sentMessage = new SentMessage($email, $envelope);

    $ref = new ReflectionMethod($transport, 'doSend');
    $ref->invoke($transport, $sentMessage);
}

beforeEach(function () {
    $this->app = bootTestApp();
});

afterEach(function () {
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    Container::setInstance(new Container);
});

it('sends email payload to the ingest endpoint', function () {
    Http::fake([
        'api.ske.test/*' => Http::response(['data' => ['id' => '1']], 201),
    ]);

    sendViaTransport(makeTransport(), makeEmail());

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ske.test/api/v1/services/ingest/mail'
            && $request->method() === 'POST'
            && $request['subject'] === 'Test Subject'
            && $request['from'][0]['email'] === 'noreply@app.test'
            && $request['to'][0]['email'] === 'user@example.com'
            && $request['text_body'] === 'Plain text body'
            && str_contains($request['html_body'], 'HTML body');
    });
});

it('sends auth headers with every request', function () {
    Http::fake([
        'api.ske.test/*' => Http::response(['data' => ['id' => '1']], 201),
    ]);

    sendViaTransport(makeTransport(serviceId: '99999', token: 'my-secret-token'), makeEmail());

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer my-secret-token')
            && $request->hasHeader('X-SKE-Service', '99999')
            && $request->hasHeader('X-SKE-Service-Type', 'mail');
    });
});

it('includes cc addresses when present', function () {
    Http::fake([
        'api.ske.test/*' => Http::response(['data' => ['id' => '1']], 201),
    ]);

    sendViaTransport(makeTransport(), makeEmail(['cc' => new Address('cc@example.com', 'CC User')]));

    Http::assertSent(function (Request $request) {
        return isset($request['cc'])
            && $request['cc'][0]['email'] === 'cc@example.com';
    });
});

it('uploads attachments via pre-signed URLs then includes keys in payload', function () {
    Http::fake([
        'api.ske.test/api/v1/services/ingest/mail/uploads' => Http::response([
            'data' => [
                ['key' => 'services/mail/tmp/123/batch/abc', 'upload_url' => 'https://s3.example.com/upload1', 'headers' => []],
            ],
        ]),
        's3.example.com/*' => Http::response(null, 200),
        'api.ske.test/api/v1/services/ingest/mail' => Http::response(['data' => ['id' => '1']], 201),
    ]);

    sendViaTransport(makeTransport(), makeEmail([
        'attachments' => [
            ['content' => 'PDF content here', 'name' => 'report.pdf', 'type' => 'application/pdf'],
        ],
    ]));

    Http::assertSentInOrder([
        fn (Request $r) => str_contains($r->url(), '/uploads')
            && $r['attachments'][0]['filename'] === 'report.pdf',
        fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'https://s3.example.com/upload1',
        fn (Request $r) => str_ends_with($r->url(), '/ingest/mail')
            && $r['attachments'][0]['key'] === 'services/mail/tmp/123/batch/abc',
    ]);
});

it('sends email without attachments field when there are none', function () {
    Http::fake([
        'api.ske.test/*' => Http::response(['data' => ['id' => '1']], 201),
    ]);

    sendViaTransport(makeTransport(), makeEmail());

    Http::assertSent(function (Request $request) {
        return ! isset($request['attachments']) || empty($request['attachments']);
    });
});

it('does not throw on API failure', function () {
    Http::fake([
        'api.ske.test/*' => Http::response(null, 500),
    ]);

    sendViaTransport(makeTransport(), makeEmail());

    expect(true)->toBeTrue();
});

it('renders as ske string', function () {
    expect((string) makeTransport())->toBe('ske');
});
