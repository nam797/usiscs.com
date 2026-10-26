<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Entity\Contact\ContactMessage;
use App\Form\Contact\ContactRequestType;
use App\Lead\LeadNotifier;
use App\RateLimiting\FormSubmissionThrottle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public "Get In Touch" form — usiscs.com's /contact page. It replaces
 * Sylius's own contact feature, since the live site's form has more fields
 * than that one supports. A submission is saved as a ContactMessage (listed
 * in /backoffice) and then emailed to the team, so a mail failure never
 * loses it.
 */
final class ContactController extends AbstractController
{
    // Fixed window: a handful of legitimate contact requests from one
    // visitor in 10 minutes is normal; dozens is not.
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeadNotifier $notifier,
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
                $message = $this->createMessage($form);
                $this->entityManager->persist($message);
                $this->entityManager->flush();

                $topicLabel = ContactRequestType::TOPIC_LABELS[$message->getTopic()] ?? $message->getTopic();
                $this->notifier->notify(
                    sprintf('Contact message #%d', $message->getId() ?? 0),
                    sprintf('New contact request from %s: %s', $message->getFullName(), $topicLabel),
                    'emails/contact_request.txt.twig',
                    ['message' => $message, 'topicLabel' => $topicLabel],
                    new Address($message->getEmail(), $message->getFullName()),
                );

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
     * The form has no data_class, so read each (already validated) field
     * rather than trusting the untyped array from $form->getData().
     */
    private function createMessage(FormInterface $form): ContactMessage
    {
        $value = static function (string $name) use ($form): ?string {
            $data = $form->get($name)->getData();

            return is_string($data) && '' !== $data ? $data : null;
        };

        return new ContactMessage(
            fullName: $value('fullName') ?? '',
            email: $value('email') ?? '',
            topic: $value('howCanWeHelp') ?? '',
            message: $value('message') ?? '',
            company: $value('company'),
            phone: $value('phone'),
        );
    }
}
