<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Entity\Rfq\RfqRequest;
use App\Form\Rfq\RfqRequestType;
use App\RateLimiting\FormSubmissionThrottle;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Webmozart\Assert\Assert;

/**
 * The public "can't find what you need" RFQ form — usiscs.com's /quote
 * page. See the RFQ Flow Spec doc for the source field list. Submissions
 * are persisted as an RfqRequest (visible immediately in /backoffice) and
 * a notification email is attempted to the channel's contact address; a
 * failed or unconfigured mailer never loses the already-saved RFQ.
 *
 * Kept as a plain Symfony controller rather than a Sylius resource
 * controller, since RfqRequest isn't a Sylius resource.
 */
final class RfqController extends AbstractController
{
    // RFQs are a heavier, more deliberate submission than a contact
    // message (customer fills in line items, delivery details, etc.), so
    // the window is a bit more generous than the Contact form's.
    private const MAX_ATTEMPTS = 8;
    private const WINDOW_SECONDS = 600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly ChannelContextInterface $channelContext,
        private readonly LoggerInterface $logger,
        private readonly FormSubmissionThrottle $throttle,
    ) {
    }

    #[Route('/quote', name: 'app_shop_rfq')]
    public function index(Request $request): Response
    {
        $rfqRequest = new RfqRequest();

        $form = $this->createForm(RfqRequestType::class, $rfqRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $clientIp = $request->getClientIp() ?? 'unknown';

            if ($this->throttle->tooManyAttempts('rfq', $clientIp, self::MAX_ATTEMPTS, self::WINDOW_SECONDS)) {
                $this->addFlash(
                    'error',
                    'Too many submissions from this connection — please try again in a few minutes.',
                );

                return $this->redirectToRoute('app_shop_rfq');
            }

            $this->throttle->hit('rfq', $clientIp, self::WINDOW_SECONDS);

            if ($form->isValid()) {
                foreach ($rfqRequest->getLineItems() as $lineItem) {
                    $lineItem->setRfqRequest($rfqRequest);
                }

                $this->entityManager->persist($rfqRequest);
                $this->entityManager->flush();

                $this->sendNotification($rfqRequest);

                $this->addFlash(
                    'success',
                    'Thanks — your request has been submitted. We aim to follow up within 24 hours.',
                );

                // Redirect after POST so a page refresh doesn't resubmit the form.
                return $this->redirectToRoute('app_shop_rfq');
            }
        }

        return $this->render('shop/rfq/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    private function sendNotification(RfqRequest $rfqRequest): void
    {
        $channel = $this->channelContext->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);

        $contactEmail = $channel->getContactEmail();

        if (null === $contactEmail) {
            // Mirrors Sylius's own ContactController: no destination
            // configured on the channel, so there's nowhere to send it.
            // The RFQ is already saved and visible in /backoffice either way.
            $this->logger->warning(sprintf(
                'RFQ #%d saved but no notification sent: channel "%s" has no contact email configured.',
                $rfqRequest->getId() ?? 0,
                $channel->getCode(),
            ));

            return;
        }

        // Symfony's Mailer requires a From (or Sender) header on every
        // message, and no default is configured anywhere in this project
        // yet (no MAILER_FROM env var, no framework.mailer envelope in
        // config/packages/mailer.yaml). Until a real sending domain/address
        // is decided (see the open question in the project doc re:
        // SES/Postmark/Resend), self-notify: send the RFQ notification
        // from the channel's own contact address to itself.
        $email = (new Email())
            ->from(new Address($contactEmail, $channel->getName() ?? 'USISCS Website'))
            ->to(new Address($contactEmail))
            ->subject(sprintf('New RFQ from %s (%s)', $rfqRequest->getCompany(), $rfqRequest->getFullName()))
            ->text($this->renderView('emails/rfq_request.txt.twig', ['rfq' => $rfqRequest]));

        try {
            $this->mailer->send($email);
        } catch (\Throwable $exception) {
            // Broad catch is deliberate: mailer failures include both
            // transport errors (TransportExceptionInterface) and email
            // *construction* errors (e.g. Symfony\Component\Mime\Exception\
            // RfcComplianceException for a missing header), neither of
            // which should ever roll back or hide an already-persisted RFQ.
            $this->logger->error(sprintf(
                'Failed to send RFQ #%d notification email: %s',
                $rfqRequest->getId() ?? 0,
                $exception->getMessage(),
            ));
        }
    }
}
