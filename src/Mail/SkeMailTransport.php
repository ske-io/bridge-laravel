<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Mail;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

final class SkeMailTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $serviceId,
        private readonly string $token,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'ske';
    }

    protected function doSend(SentMessage $message): void
    {
        Log::info('SKE mail transport: sending', [
            'endpoint' => $this->endpoint,
            'service_id' => $this->serviceId,
            'has_token' => $this->token !== '',
        ]);

        if ($this->endpoint === '' || $this->token === '') {
            Log::error('SKE mail transport: no endpoint or token configured, mail dropped.', [
                'endpoint' => $this->endpoint,
                'service_id' => $this->serviceId,
            ]);

            return;
        }

        try {
            $email = MessageConverter::toEmail($message->getOriginalMessage());
            $rawAttachments = $this->collectAttachments($email);

            $uploadedKeys = [];

            if (! empty($rawAttachments)) {
                $uploadedKeys = $this->uploadAttachments($rawAttachments);
            }

            $payload = [
                'message_id' => $email->getHeaders()->get('message-id')?->getBodyAsString(),
                'subject' => $email->getSubject(),
                'from' => $this->mapAddresses($email->getFrom()),
                'to' => $this->mapAddresses($email->getTo()),
                'cc' => $this->mapAddresses($email->getCc()) ?: null,
                'bcc' => $this->mapAddresses($email->getBcc()) ?: null,
                'reply_to' => $this->mapAddresses($email->getReplyTo()) ?: null,
                'text_body' => $email->getTextBody(),
                'html_body' => $email->getHtmlBody(),
                'headers' => $this->extractHeaders($email),
            ];

            if (! empty($uploadedKeys)) {
                $payload['attachments'] = array_map(fn (array $a, int $i) => [
                    'key' => $uploadedKeys[$i] ?? '',
                    'filename' => $a['filename'],
                    'content_type' => $a['content_type'],
                    'size' => strlen($a['content']),
                    'content_id' => $a['content_id'],
                ], $rawAttachments, array_keys($rawAttachments));

                $payload['attachments'] = array_filter(
                    $payload['attachments'],
                    fn (array $a) => $a['key'] !== '',
                );
                $payload['attachments'] = array_values($payload['attachments']);
            }

            $response = $this->apiRequest()->post($this->endpoint, $payload);

            if ($response->failed()) {
                Log::error('SKE mail transport: API returned '.$response->status(), [
                    'endpoint' => $this->endpoint,
                    'body' => $response->body(),
                ]);
            } else {
                Log::info('SKE mail transport: delivered', ['id' => $response->json('data.id')]);
            }
        } catch (\Throwable $e) {
            Log::warning('SKE mail transport failed: '.$e->getMessage());
        }
    }

    /**
     * Upload attachments to S3 via pre-signed URLs, return the claimed keys.
     *
     * @param  list<array{filename: string, content_type: string, content: string, content_id: string|null}>  $rawAttachments
     * @return list<string>
     */
    private function uploadAttachments(array $rawAttachments): array
    {
        $uploadMeta = array_map(fn (array $a) => [
            'filename' => $a['filename'],
            'content_type' => $a['content_type'],
            'size' => strlen($a['content']),
        ], $rawAttachments);

        $response = $this->apiRequest()
            ->timeout(10)
            ->post(rtrim($this->endpoint, '/').'/uploads', ['attachments' => $uploadMeta]);

        if (! $response->successful()) {
            return [];
        }

        $slots = $response->json('data', []);
        $keys = [];

        foreach ($slots as $i => $slot) {
            if (! isset($rawAttachments[$i])) {
                continue;
            }

            try {
                Http::withHeaders(array_merge(
                    $slot['headers'] ?? [],
                    ['Content-Type' => $rawAttachments[$i]['content_type']],
                ))
                    ->timeout(30)
                    ->withBody($rawAttachments[$i]['content'], $rawAttachments[$i]['content_type'])
                    ->put($slot['upload_url']);

                $keys[] = $slot['key'];
            } catch (\Throwable $e) {
                Log::warning("SKE mail attachment upload failed ({$rawAttachments[$i]['filename']}): ".$e->getMessage());
            }
        }

        return $keys;
    }

    private function apiRequest(): PendingRequest
    {
        return Http::withToken($this->token)
            ->withHeaders([
                'X-SKE-Service' => $this->serviceId,
                'X-SKE-Service-Type' => 'mail',
            ]);
    }

    /** @return list<array{email: string, name: string|null}> */
    private function mapAddresses(array $addresses): array
    {
        return array_values(array_map(
            fn (Address $a) => ['email' => $a->getAddress(), 'name' => $a->getName() ?: null],
            $addresses,
        ));
    }

    /** @return array<string, string> */
    private function extractHeaders(Email $email): array
    {
        $headers = [];
        foreach ($email->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());
            if (! in_array($name, ['from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'content-type', 'mime-version'])) {
                $headers[$header->getName()] = $header->getBodyAsString();
            }
        }

        return $headers;
    }

    /** @return list<array{filename: string, content_type: string, content: string, content_id: string|null}> */
    private function collectAttachments(Email $email): array
    {
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            if (! $attachment instanceof DataPart) {
                continue;
            }

            $attachments[] = [
                'filename' => $attachment->getFilename() ?? 'attachment',
                'content_type' => $attachment->getContentType(),
                'content' => $attachment->getBody(),
                'content_id' => $attachment->getContentId(),
            ];
        }

        return $attachments;
    }
}
