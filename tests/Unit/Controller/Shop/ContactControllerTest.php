<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Shop;

use App\Controller\Shop\ContactController;
use App\Form\Contact\ContactRequestType;
use App\RateLimiting\FormSubmissionThrottle;
use App\Tests\Unit\Controller\ControllerTestCase;
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

final class ContactControllerTest extends ControllerTestCase
{
    /** @var list<RawMessage> */
    private array $sent = [];

    /** @var list<array{string, string}> */
    private array $logs = [];

    private FormSubmissionThrottle $throttle;

    private ?\Throwable $mailerFailure = null;

    protected function setUp(): void
    {
        $this->sent = [];
        $this->logs = [];
        $this->mailerFailure = null;
        $this->throttle = new FormSubmissionThrottle(new ArrayAdapter());
    }

    public function testItShowsTheEmptyForm(): void
    {
        $response = $this->handle(Request::create('/contact'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['shop/contact/index.html.twig'], $this->renderedTemplates());
        self::assertInstanceOf(FormView::class, $this->rendered[0][1]['form']);
        self::assertSame([], $this->sent);
    }

    public function testAValidSubmissionEmailsTheChannelAndRedirects(): void
    {
        $response = $this->handle($this->post(self::validSubmission()));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/route/app_shop_contact', $response->getTargetUrl());
        self::assertCount(1, $this->flashes('success'));

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('sales@usiscs.example', $email->getTo()[0]->getAddress());
        self::assertSame('sales@usiscs.example', $email->getFrom()[0]->getAddress());
        self::assertSame('USISCS', $email->getFrom()[0]->getName());
        self::assertSame(
            'New contact request from Jane Doe: Supply Problem / Emergency Sourcing',
            $email->getSubject(),
        );
        self::assertSame('rendered:emails/contact_request.txt.twig', $email->getTextBody());
    }

    public function testTheEmailTemplateGetsTheSubmittedFields(): void
    {
        $this->handle($this->post(self::validSubmission()));

        $emailRender = $this->rendered[0];
        self::assertSame('emails/contact_request.txt.twig', $emailRender[0]);
        self::assertSame('Supply Problem / Emergency Sourcing', $emailRender[1]['topicLabel']);

        $data = $emailRender[1]['data'];
        self::assertIsArray($data);
        self::assertSame('Jane Doe', $data['fullName']);
        self::assertSame('jane@acme.example', $data['email']);
        self::assertSame('Need hard hats.', $data['message']);
        self::assertNull($data['company'], 'Blank optional fields reach the template as null.');
    }

    public function testAnInvalidSubmissionRedisplaysTheFormWithoutSending(): void
    {
        $data = self::validSubmission();
        $data['email'] = 'not-an-email';

        $response = $this->handle($this->post($data));

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(['shop/contact/index.html.twig'], $this->renderedTemplates());
        self::assertSame([], $this->sent);
        self::assertSame([], $this->flashes('success'));
    }

    public function testInvalidSubmissionsStillCountTowardsTheThrottle(): void
    {
        $data = self::validSubmission();
        $data['email'] = 'not-an-email';

        $this->handle($this->post($data));

        self::assertFalse($this->throttle->tooManyAttempts('contact', '203.0.113.7', 2, 600));
        self::assertTrue($this->throttle->tooManyAttempts('contact', '203.0.113.7', 1, 600));
    }

    public function testViewingTheFormDoesNotCountTowardsTheThrottle(): void
    {
        $this->handle(Request::create('/contact', server: ['REMOTE_ADDR' => '203.0.113.7']));

        self::assertFalse($this->throttle->tooManyAttempts('contact', '203.0.113.7', 1, 600));
    }

    public function testAThrottledClientIsTurnedAwayBeforeAnythingIsSent(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->throttle->hit('contact', '203.0.113.7', 600);
        }

        $response = $this->handle($this->post(self::validSubmission()));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/route/app_shop_contact', $response->getTargetUrl());
        self::assertCount(1, $this->flashes('error'));
        self::assertSame([], $this->flashes('success'));
        self::assertSame([], $this->sent);
    }

    public function testTheFifthSubmissionIsAllowedAndTheSixthIsNot(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->throttle->hit('contact', '203.0.113.7', 600);
        }

        $this->handle($this->post(self::validSubmission()));
        self::assertCount(1, $this->sent);

        $this->handle($this->post(self::validSubmission()));
        self::assertCount(1, $this->sent);
    }

    public function testWithoutAChannelContactEmailItLogsAndStillThanksTheVisitor(): void
    {
        $response = $this->handle($this->post(self::validSubmission()), contactEmail: null);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertCount(1, $this->flashes('success'));
        self::assertSame([], $this->sent);
        self::assertCount(1, $this->logs);
        self::assertSame('warning', $this->logs[0][0]);
        self::assertStringContainsString('Jane Doe <jane@acme.example>', $this->logs[0][1]);
        self::assertStringContainsString('"default"', $this->logs[0][1]);
    }

    public function testAMailerFailureIsLoggedAndTheVisitorStillSeesSuccess(): void
    {
        $this->mailerFailure = new TransportException('SMTP down');

        $response = $this->handle($this->post(self::validSubmission()));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertCount(1, $this->flashes('success'));
        self::assertCount(1, $this->logs);
        self::assertSame('error', $this->logs[0][0]);
        self::assertStringContainsString('SMTP down', $this->logs[0][1]);
    }

    /** @return array<string, string> */
    private static function validSubmission(): array
    {
        return [
            'fullName' => 'Jane Doe',
            'company' => '',
            'email' => 'jane@acme.example',
            'phone' => '',
            'howCanWeHelp' => ContactRequestType::TOPIC_SUPPLY_PROBLEM,
            'message' => 'Need hard hats.',
        ];
    }

    /** @param array<string, string> $data */
    private function post(array $data): Request
    {
        return Request::create('/contact', 'POST', ['contact_request' => $data], server: ['REMOTE_ADDR' => '203.0.113.7']);
    }

    private function handle(Request $request, ?string $contactEmail = 'sales@usiscs.example'): Response
    {
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

        $controller = new ContactController($mailer, $channelContext, $logger, $this->throttle);
        $this->wire($controller, $request);

        return $controller->index($request);
    }
}
