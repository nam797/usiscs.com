<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Entity\Product\ProductVariant;
use App\Entity\Rfq\RfqLineItem;
use App\Entity\Rfq\RfqRequest;
use App\Form\Rfq\RfqRequestType;
use App\Lead\LeadNotifier;
use App\RateLimiting\FormSubmissionThrottle;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 *
 * Product pages link here with ?item=<variant code> ("bulk quote"): the
 * form then starts with that item as its first line.
 */
final class RfqController extends AbstractController
{
    // RFQs are a heavier, more deliberate submission than a contact
    // message (customer fills in line items, delivery details, etc.), so
    // the window is a bit more generous than the Contact form's.
    private const MAX_ATTEMPTS = 8;
    private const WINDOW_SECONDS = 600;

    /** @param ProductVariantRepositoryInterface<ProductVariantInterface> $variants */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeadNotifier $notifier,
        private readonly FormSubmissionThrottle $throttle,
        #[Autowire(service: 'sylius.repository.product_variant')]
        private readonly ProductVariantRepositoryInterface $variants,
    ) {
    }

    #[Route('/quote', name: 'app_shop_rfq')]
    public function index(Request $request): Response
    {
        $rfqRequest = new RfqRequest();
        if (!$request->isMethod('POST')) {
            $this->prefillFromProduct($rfqRequest, $request->query->getString('item'));
        }

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

    /** A first line for the product a "bulk quote" link came from; unknown or disabled items are ignored. */
    private function prefillFromProduct(RfqRequest $rfqRequest, string $code): void
    {
        if ('' === $code) {
            return;
        }
        $variant = $this->variants->findOneBy(['code' => $code]);
        $product = $variant?->getProduct();
        if (!$variant instanceof ProductVariantInterface || null === $product || !$variant->isEnabled() || !$product->isEnabled()) {
            return;
        }

        $line = new RfqLineItem();
        $line->setQuantity(1);
        $line->setPartNumber($code);
        $name = (string) $product->getName();
        $line->setDescription(1 < count($product->getEnabledVariants()) && null !== $variant->getName() ? sprintf('%s, %s', $name, $variant->getName()) : $name);
        if ($variant instanceof ProductVariant && null !== $variant->getPackUnit()) {
            $line->setUnitOfMeasure($variant->getPackUnit()->label());
        }
        $rfqRequest->addLineItem($line);
    }
}
