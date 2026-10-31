<?php

declare(strict_types=1);

namespace App\Mail;

use Sylius\Component\Mailer\Event\EmailSendEvent;
use Sylius\Component\Mailer\SyliusMailerEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Email;

/**
 * Sylius's own emails (order confirmation, shipped, password reset, ...)
 * leave Sylius as HTML only. Just before they're sent, this:
 *  - tags them with their Sylius email code (order_confirmation), which
 *    the sent-email log shows and Postmark files them under;
 *  - adds a plain-text version.
 */
#[AsEventListener(event: SyliusMailerEvents::EMAIL_PRE_SEND, method: 'onPreSend')]
final class SyliusEmailListener
{
    public function onPreSend(EmailSendEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof Email) {
            return;
        }

        $headers = $message->getHeaders();
        $code = $event->getEmail()->getCode();
        if (null !== $code && !$headers->has('X-Tag')) {
            $headers->add(new TagHeader($code));
        }

        $html = $message->getHtmlBody();
        if (null === $message->getTextBody() && is_string($html)) {
            $message->text(HtmlToText::convert($html));
        }
    }
}
