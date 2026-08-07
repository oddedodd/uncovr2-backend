<?php

namespace App\Mail\Transport;

use Exception;
use Illuminate\Mail\Transport\ResendTransport as LaravelResendTransport;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

final class ResendTransport extends LaravelResendTransport
{
    private const IDEMPOTENCY_HEADER = 'X-Uncovr-Resend-Idempotency-Key';

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();
        $headers = [];
        $idempotencyKey = $email->getHeaders()->get(self::IDEMPOTENCY_HEADER)?->getBodyAsString();
        $headersToBypass = [
            'from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'content-type',
            strtolower(self::IDEMPOTENCY_HEADER),
        ];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (! in_array($name, $headersToBypass, true)) {
                $headers[$header->getName()] = $header->getBodyAsString();
            }
        }

        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $attachmentHeaders = $attachment->getPreparedHeaders();
            $contentType = $attachmentHeaders->get('Content-Type')->getBody();
            $disposition = $attachmentHeaders->getHeaderBody('Content-Disposition');
            $filename = $attachmentHeaders->getHeaderParameter('Content-Disposition', 'filename');
            $content = $contentType === 'text/calendar'
                ? $attachment->getBody()
                : str_replace("\r\n", '', $attachment->bodyToString());
            $item = [
                'content_type' => $contentType,
                'content' => $content,
                'filename' => $filename,
            ];

            if ($disposition === 'inline') {
                $item['content_id'] = $attachment->hasContentId()
                    ? $attachment->getContentId()
                    : $filename;
            }

            $attachments[] = $item;
        }

        try {
            $result = $this->resend->emails->send([
                'from' => $envelope->getSender()->toString(),
                'to' => $this->stringifyAddresses($this->getRecipients($email, $envelope)),
                'cc' => $this->stringifyAddresses($email->getCc()),
                'bcc' => $this->stringifyAddresses($email->getBcc()),
                'reply_to' => $this->stringifyAddresses($email->getReplyTo()),
                'headers' => $headers,
                'subject' => $email->getSubject(),
                'html' => $email->getHtmlBody(),
                'text' => $email->getTextBody(),
                'attachments' => $attachments,
            ], $idempotencyKey === null ? [] : ['idempotency_key' => $idempotencyKey]);

            if (isset($result['statusCode']) && $result['statusCode'] !== Response::HTTP_OK) {
                throw new Exception($result['message']);
            }
        } catch (Throwable $exception) {
            throw new TransportException(
                sprintf('Request to Resend API failed. Reason: %s.', $exception->getMessage()),
                is_int($exception->getCode()) ? $exception->getCode() : 0,
                $exception,
            );
        }

        $email->getHeaders()->addHeader('X-Resend-Email-ID', $result->id);
    }

    /** @return array<int, Address> */
    protected function getRecipients(Email $email, Envelope $envelope): array
    {
        return array_filter(
            $envelope->getRecipients(),
            fn (Address $address): bool => ! in_array(
                $address,
                array_merge($email->getCc(), $email->getBcc()),
                true,
            ),
        );
    }
}
