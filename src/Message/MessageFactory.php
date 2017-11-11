<?php
declare(strict_types=1);

namespace rpkamp\Mailhog\Message;

class MessageFactory
{
    public static function fromMailhogResponse(array $mailhogResponse): Message
    {
        $recipients = [];
        foreach ($mailhogResponse['To'] as $recipient) {
            $recipients[] = sprintf('%s@%s', $recipient['Mailbox'], $recipient['Domain']);
        }

        $parts = [];
        if (isset($mailhogResponse['MIME']['Parts'])) {
            $parts = static::flattenParts($mailhogResponse['MIME']['Parts']);
        }

        $sender = sprintf('%s@%s', $mailhogResponse['From']['Mailbox'], $mailhogResponse['From']['Domain']);

        return new Message(
            $mailhogResponse['ID'],
            $sender,
            $recipients,
            $mailhogResponse['Content']['Headers']['Subject'][0],
            count($parts)
                ? static::findBodyMime($parts)
                : $mailhogResponse['Content']['Body'],
            count($parts) ? self::getAttachments($parts) : []
        );
    }

    private static function findBodyMime(array $parts): string
    {
        $textBody = '';
        foreach ($parts as $part) {
            if (isset($part['Headers']['Content-Type'])) {
                $contentType = $part['Headers']['Content-Type'][0];
                if (stripos($contentType, 'text/html') === 0) {
                    return $part['Body'];
                }
                if (stripos($contentType, 'text/plain') === 0 && stripos($contentType, 'name=') === false) {
                    $textBody = $part['Body'];
                }
            }
        }

        return $textBody;
    }

    private static function flattenParts(array $parts): array
    {
        $flattenedParts = [];
        foreach ($parts as $part) {
            if (!isset($part['MIME']['Parts'])) {
                $flattenedParts[] = $part;
                continue;
            }

            $flattenedParts = array_merge($flattenedParts, self::flattenParts($part['MIME']['Parts']));
        }

        return $flattenedParts;
    }

    private static function getAttachments(array $parts): array
    {
        $attachments = [];
        foreach ($parts as $part) {
            if (!isset($part['Headers']['Content-Disposition'])) {
                continue;
            }

            if (stripos($part['Headers']['Content-Disposition'][0], 'attachment') === 0) {
                preg_match('~filename=(?P<filename>.*?)(;|$)~i', $part['Headers']['Content-Disposition'][0], $matches);

                $mimeType = 'application/octet-stream';
                if (isset($part['Headers']['Content-Type'][0])) {
                    $mimeType = explode(';', $part['Headers']['Content-Type'][0])[0];
                }

                $body = $part['Body'];
                if (isset($part['Headers']['Content-Transfer-Encoding'][0])
                    && $part['Headers']['Content-Transfer-Encoding'][0] === 'base64'
                ) {
                    $body = base64_decode($part['Body']);
                }

                $attachments[] = new Attachment(
                    $matches['filename'] ?? 'unknown',
                    $mimeType,
                    $body
                );
            }
        }

        return $attachments;
    }
}