<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Shop;

use App\Controller\Shop\RfqController;
use App\Entity\Rfq\RfqRequest;
use App\Lead\LeadNotifier;
use App\Mail\TeamEmailBuilder;
use App\RateLimiting\FormSubmissionThrottle;
use App\Tests\Unit\Controller\ControllerTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class RfqControllerTest extends ControllerTestCase
{
    /** @var list<object> */
    private array $persisted = [];

    private int $flushes = 0;

    /** @var list<RawMessage> */
    private array $sent = [];

    /** @var list<array{string, string}> */
    private array $logs = [];

    private FormSubmissionThrottle $throttle;

    private ?\Throwable $mailerFailure = null;

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->flushes = 0;
        $this->sent = [];
        $this->logs = [];
        $this->mailerFailure = null;
        $this->throttle = new FormSubmissionThrottle(new ArrayAdapter());
    }

    public function testItShowsTheEmptyForm(): void
    {
        $response = $this->handle(Request::create('/quote'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['shop/rfq/index.html.twig'], $this->renderedTemplates());
        self::assertInstanceOf(FormView::class, $this->rendered[0][1]['form']);
        self::assertSame([], $this->persisted);
    }

    public function testAValidSubmissionIsSavedWithItsLineItemsAndRedirects(): void
    {
        $response = $this->handle($this->post(self::validSubmission()));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/route/app_shop_rfq', $response->getTargetUrl());
        self::assertCount(1, $this->flashes('success'));

        self::assertCount(1, $this->persisted);
        self::assertSame(1, $this->flushes);
        $rfq = $this->persisted[0];
        self::assertInstanceOf(RfqRequest::class, $rfq);
        self::assertSame('Acme Construction', $rfq->getCompany());
        self::assertSame(RfqRequest::STATUS_NEW, $rfq->getStatus());
        self::assertCount(2, $rfq->getLineItems());
        foreach ($rfq->getLineItems() as $item) {
            self::assertSame($rfq, $item->getRfqRequest());
        }
    }

    public function testASavedRequestIsEmailedToTheChannel(): void
    {
        $this->handle($this->post(self::validSubmission()));

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('sales@usiscs.example', $email->getTo()[0]->getAddress());
        self::assertSame('no-reply@usiscs.example', $email->getFrom()[0]->getAddress());
        self::assertSame('jane@acme.example', $email->getReplyTo()[0]->getAddress());
        self::assertSame('Jane Doe', $email->getReplyTo()[0]->getName());
        self::assertSame('New RFQ from Acme Construction (Jane Doe)', $email->getSubject());
        self::assertSame('rendered:emails/rfq_request.txt.twig', $email->getTextBody());
        self::assertSame($this->persisted[0], $this->rendered[0][1]['rfq']);
    }

    public function testAnInvalidSubmissionIsNotSaved(): void
    {
        $data = self::validSubmission();
        $data['lineItems'] = [];

        $response = $this->handle($this->post($data));

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(['shop/rfq/index.html.twig'], $this->renderedTemplates());
        self::assertSame([], $this->persisted);
        self::assertSame(0, $this->flushes);
        self::assertSame([], $this->sent);
    }

    public function testInvalidSubmissionsStillCountTowardsTheThrottle(): void
    {
        $data = self::validSubmission();
        $data['email'] = '';

        $this->handle($this->post($data));

        self::assertTrue($this->throttle->tooManyAttempts('rfq', '203.0.113.7', 1, 600));
    }

    public function testAThrottledClientIsTurnedAwayBeforeAnythingIsSaved(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $this->throttle->hit('rfq', '203.0.113.7', 600);
        }

        $response = $this->handle($this->post(self::validSubmission()));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertCount(1, $this->flashes('error'));
        self::assertSame([], $this->persisted);
        self::assertSame([], $this->sent);
    }

    public function testTheEighthSubmissionIsAllowedAndTheNinthIsNot(): void
    {
        for ($i = 0; $i < 7; ++$i) {
            $this->throttle->hit('rfq', '203.0.113.7', 600);
        }

        $this->handle($this->post(self::validSubmission()));
        self::assertCount(1, $this->persisted);

        $this->handle($this->post(self::validSubmission()));
        self::assertCount(1, $this->persisted);
    }

    public function testWithoutAChannelContactEmailTheRequestIsStillSaved(): void
    {
        $response = $this->handle($this->post(self::validSubmission()), contactEmail: null);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertCount(1, $this->persisted);
        self::assertSame([], $this->sent);
        self::assertCount(1, $this->logs);
        self::assertSame('warning', $this->logs[0][0]);
        self::assertStringContainsString('no contact email', $this->logs[0][1]);
    }

    public function testAMailerFailureNeverLosesTheSavedRequest(): void
    {
        $this->mailerFailure = new TransportException('SMTP down');

        $response = $this->handle($this->post(self::validSubmission()));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertCount(1, $this->persisted);
        self::assertSame(1, $this->flushes);
        self::assertCount(1, $this->flashes('success'));
        self::assertCount(1, $this->logs);
        self::assertSame('error', $this->logs[0][0]);
        self::assertStringContainsString('SMTP down', $this->logs[0][1]);
    }

    /** @return array<string, mixed> */
    private static function validSubmission(): array
    {
        return [
            'customerType' => RfqRequest::CUSTOMER_TYPE_NEW,
            'fullName' => 'Jane Doe',
            'company' => 'Acme Construction',
            'email' => 'jane@acme.example',
            'phone' => '912-665-7257',
            'urgencyLevel' => RfqRequest::URGENCY_STANDARD,
            'lineItems' => [
                ['quantity' => '10', 'description' => 'Hex bolt'],
                ['quantity' => '1', 'description' => 'Pallet jack'],
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function post(array $data): Request
    {
        return Request::create('/quote', 'POST', ['rfq_request' => $data], server: ['REMOTE_ADDR' => '203.0.113.7']);
    }

    private function handle(Request $request, ?string $contactEmail = 'sales@usiscs.example'): Response
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getContactEmail')->willReturn($contactEmail);
        $channel->method('getName')->willReturn('USISCS');
        $channel->method('getCode')->willReturn('default');

        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            if (null !== $this->mailerFailure) {
                throw $this->mailerFailure;
            }
            $this->sent[] = $message;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string|\Stringable $message): void {
            $this->logs[] = ['warning', (string) $message];
        });
        $logger->method('error')->willReturnCallback(function (string|\Stringable $message): void {
            $this->logs[] = ['error', (string) $message];
        });

        $notifier = new LeadNotifier($mailer, $channelContext, new TeamEmailBuilder($this->recordingTwig()), $logger, 'no-reply@usiscs.example');
        $controller = new RfqController($entityManager, $notifier, $this->throttle);
        $this->wire($controller, $request);

        return $controller->index($request);
    }
}
