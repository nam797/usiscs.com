<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Entity\Rfq\RfqRequest;
use App\Form\Rfq\RfqRequestType;
use App\Lead\LeadNotifier;
use App\RateLimiting\FormSubmissionThrottle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public "can't find what you need" RFQ form — usiscs.com's /quote
 * page. See the RFQ Flow Spec doc for the source field list. Submissions
 * are persisted as an RfqRequest (visible immediately in /backoffice) and
 * then emailed to the team (LeadNotifier); a failed or unconfigured mailer
 * never loses the already-saved RFQ.
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
        private readonly LeadNotifier $notifier,
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

                $this->notifier->notify(
                    sprintf('RFQ #%d', $rfqRequest->getId() ?? 0),
                    sprintf('New RFQ from %s (%s)', $rfqRequest->getCompany(), $rfqRequest->getFullName()),
                    'emails/rfq_request.txt.twig',
                    ['rfq' => $rfqRequest],
                    new Address($rfqRequest->getEmail(), $rfqRequest->getFullName()),
                );

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
}
