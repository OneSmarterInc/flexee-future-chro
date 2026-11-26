<?php

namespace App\Ashby\Mail;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends a message through the Mailjet Send API (v3.1) over HTTPS (D50). It is a mail driver, so every
 * `Mail::to(...)->send(...)` in The Future CHRO uses it when `MAIL_MAILER=mailjet`.
 */
class MailjetTransport extends AbstractTransport
{
    public const ENDPOINT = 'https://api.mailjet.com/v3.1/send';

    public function __construct(private readonly string $key, private readonly string $secret)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->key === '' || $this->secret === '') {
            throw new TransportException('Mailjet has no API key or secret. Set MAILJET_API_KEY and MAILJET_API_SECRET in .env.');
        }

        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            throw new TransportException('Mailjet can only send a complete message.');
        }
        $email = MessageConverter::toEmail($original);
        $from = $email->getFrom()[0] ?? null;
        if ($from === null) {
            throw new TransportException('The message has no From address.');
        }

        $body = [
            'From' => $this->address($from),
            'To' => array_map($this->address(...), $email->getTo()),
            'Subject' => (string) $email->getSubject(),
        ];
        if ($email->getCc() !== []) {
            $body['Cc'] = array_map($this->address(...), $email->getCc());
        }
        if ($email->getBcc() !== []) {
            $body['Bcc'] = array_map($this->address(...), $email->getBcc());
        }
        if (($email->getReplyTo()[0] ?? null) !== null) {
            $body['ReplyTo'] = $this->address($email->getReplyTo()[0]);
        }
        if ($email->getTextBody() !== null) {
            $body['TextPart'] = (string) $email->getTextBody();
        }
        if ($email->getHtmlBody() !== null) {
            $body['HTMLPart'] = (string) $email->getHtmlBody();
        }

        $response = Http::withBasicAuth($this->key, $this->secret)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post(self::ENDPOINT, ['Messages' => [$body]]);

        if ($response->failed()) {
            throw new TransportException('Mailjet answered '.$response->status().': '.$this->reason($response));
        }
        if ((string) $response->json('Messages.0.Status') !== 'success') {
            throw new TransportException('Mailjet did not accept the message: '.$this->reason($response));
        }
    }

    /** @return array{Email: string, Name?: string} */
    private function address(Address $address): array
    {
        $entry = ['Email' => $address->getAddress()];
        if ($address->getName() !== '') {
            $entry['Name'] = $address->getName();
        }

        return $entry;
    }

    private function reason(Response $response): string
    {
        $reason = $response->json('ErrorMessage') ?? $response->json('Messages.0.Errors.0.ErrorMessage') ?? $response->body();

        return mb_substr((string) $reason, 0, 300);
    }

    public function __toString(): string
    {
        return 'mailjet';
    }
}
