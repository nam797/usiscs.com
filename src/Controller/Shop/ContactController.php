<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Form\Contact\ContactRequestType;
use App\RateLimiting\FormSubmissionThrottle;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Webmozart\Assert\Assert;

/**
 * The public "Get In Touch" form — usiscs.com's /contact page. Unlike
 * the RFQ flow, there's no admin queue here: a submission is validated
 * and emailed, same as Sylius's own built-in contact feature (which
 * this replaces, since the live site's form has more fields than that
 * one supports). A failed/unconfigured mailer still shows the success
 * message rather than losing the submission silently with no record at
 * all — see the open question in the project doc re: where Contact
 * submissions should really land (inbox/CRM) once that's decided.
 */
final class ContactController extends AbstractController
{
    // Fixed window: a handful of legitimate contact requests from one
    // visitor in 10 minutes is normal; dozens is not.
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 600;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ChannelContextInterface $channelContext,
        private readonly LoggerInterface $logger,
        private readonly FormSubmissionThrottle $throttle,
    ) {
    }

    #[Route('/contact', name: 'app_shop_contact')]
    public function index(Request $request): Response
    {
        $form = $this->createForm(ContactRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $clientIp = $request->getClientIp() ?? 'unknown';

            if ($this->throttle->tooManyAttempts('contact', $clientIp, self::MAX_ATTEMPTS, self::WINDOW_SECONDS)) {
                $this->addFlash(
                    'error',
                    'Too many submissions from this connection — please try again in a few minutes.',
                );

                return $this->redirectToRoute('app_shop_contact');
            }

            $this->throttle->hit('contact', $clientIp, self::WINDOW_SECONDS);

            if ($form->isValid()) {
                $this->sendNotification($this->submittedData($form));

                $this->addFlash(
                    'success',
                    "Thanks for reaching out — we'll get back to you within 2 business hours.",
                );

                // Redirect after POST so a page refresh doesn't resubmit the form.
                return $this->redirectToRoute('app_shop_contact');
            }
        }

        return $this->render('shop/contact/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * The form has no data_class, so read each field's value directly
     * rather than trusting the untyped array from $form->getData().
     *
     * @return array<string, string|null>
     */
    private function submittedData(FormInterface $form): array
    {
        $data = [];

        foreach ($form->all() as $name => $field) {
            $value = $field->getData();
            $data[$name] = is_string($value) ? $value : null;
        }

        return $data;
    }

    /** @param array<string, string|null> $data */
    private function sendNotification(array $data): void
    {
        $channel = $this->channelContext->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);

        $contactEmail = $channel->getContactEmail();

        if (null === $contactEmail) {
            $this->logger->warning(sprintf(
                'Contact request from %s <%s> not emailed: channel "%s" has no contact email configured.',
                $data['fullName'] ?? '(no name)',
                $data['email'] ?? '(no email)',
                $channel->getCode(),
            ));

            return;
        }

        $topic = $data['howCanWeHelp'] ?? null;
        $topicLabel = null !== $topic
            ? (ContactRequestType::TOPIC_LABELS[$topic] ?? $topic)
            : 'Unknown';

        // Self-notify from/to the channel's own contact address, same
        // reasoning as the RFQ notification: no dedicated transactional
        // email provider is chosen yet, so this just needs a valid From
        // header until that decision lands.
        $email = (new Email())
            ->from(new Address($contactEmail, $channel->getName() ?? 'USISCS Website'))
            ->to(new Address($contactEmail))
            ->subject(sprintf('New contact request from %s: %s', $data['fullName'] ?? 'Unknown', $topicLabel))
            ->text($this->renderView('emails/contact_request.txt.twig', [
                'data' => $data,
                'topicLabel' => $topicLabel,
            ]));

        try {
            $this->mailer->send($email);
        } catch (\Throwable $exception) {
            // Broad catch deliberate — see the matching comment in
            // RfqController::sendNotification() for why.
            $this->logger->error(sprintf(
                'Failed to send contact request notification email: %s',
                $exception->getMessage(),
            ));
        }
    }
}
