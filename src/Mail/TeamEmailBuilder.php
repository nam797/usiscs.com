<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Staff emails (new RFQ, contact message, purchase order alerts) from their
 * plain-text template (templates/emails/*.txt.twig): the text as written,
 * plus the same text in the branded HTML frame with its links clickable.
 * Tagged with the template's name (rfq_request) for the sent-email log.
 *
 * Callers set from, to and reply-to.
 */
final class TeamEmailBuilder
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function build(string $template, array $context, string $subject): Email
    {
        $text = $this->twig->render($template, $context);

        $email = (new Email())
            ->subject($subject)
            ->text($text)
            ->html($this->twig->render('emails/team.html.twig', [
                'heading' => $subject,
                'body_html' => self::textToHtml($text),
            ]));
        $email->getHeaders()->add(new TagHeader(basename($template, '.txt.twig')));

        return $email;
    }

    /** Escaped, with line breaks kept and web addresses made into links. */
    private static function textToHtml(string $text): string
    {
        $html = htmlspecialchars(trim($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $html = preg_replace('{https?://[^\s<]+}', '<a href="$0" style="color: #8B1A1A;">$0</a>', $html) ?? $html;

        return nl2br($html);
    }
}
