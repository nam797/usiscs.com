<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Contact\ContactMessage;
use App\Entity\Rfq\RfqLineItem;
use App\Entity\Rfq\RfqRequest;
use App\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\User\Repository\UserRepositoryInterface;

final class AdminTest extends FunctionalTestCase
{
    public function testTheLoginPageShowsTheBrandNotSylius(): void
    {
        $this->client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('img[alt="US Industrial Supply"]');
        self::assertSelectorNotExists('svg[aria-label="Sylius"]');
        self::assertSelectorExists('link[rel="icon"][href="/favicon.ico"]');
    }

    public function testAnAdminCanLogInAndSeesTheBrandedDashboard(): void
    {
        $crawler = $this->client->request('GET', '/admin/login');
        $this->client->submit($crawler->selectButton('Login')->form([
            '_username' => 'admin',
            '_password' => 'e2e-admin-password',
        ]));

        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertRouteSame('sylius_admin_dashboard');
        self::assertSelectorExists('.navbar-brand img[alt="US Industrial Supply"]');
    }

    public function testAWrongPasswordIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/admin/login');
        $this->client->submit($crawler->selectButton('Login')->form([
            '_username' => 'admin',
            '_password' => 'wrong',
        ]));

        $this->client->followRedirect();
        self::assertRouteSame('sylius_admin_login');
    }

    public function testTheBackofficeRequiresAnAdminLogin(): void
    {
        $this->client->request('GET', '/backoffice');

        self::assertResponseRedirects('http://localhost/admin/login');
    }

    public function testTheRfqQueueListsSubmittedRequests(): void
    {
        $this->createRfq('Acme Construction', 'Jane Doe');
        $this->loginAsAdmin();

        $this->client->request('GET', '/backoffice/rfq-request');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'RFQ Requests');
        self::assertSelectorTextContains('table', 'Acme Construction');
        self::assertSelectorTextContains('table', 'Jane Doe');
    }

    public function testAnAdminCanOpenARequestWithItsLineItems(): void
    {
        $id = $this->createRfq('Acme Construction', 'Jane Doe');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/backoffice/rfq-request/%d', $id));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'jane@acme.example');
        self::assertSelectorTextContains('body', 'Hex bolt');
    }

    public function testAnAdminCanMoveARequestToQuoted(): void
    {
        $id = $this->createRfq('Acme Construction', 'Jane Doe');
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', sprintf('/backoffice/rfq-request/%d/edit', $id));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="RfqRequest"]')->form();
        $form['RfqRequest[status]'] = RfqRequest::STATUS_QUOTED;
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(RfqRequest::STATUS_QUOTED, $this->savedRfqs()[0]->getStatus());
    }

    public function testAnAdminCanReadAContactMessageAndMarkItHandled(): void
    {
        $message = new ContactMessage('Jane Doe', 'jane@acme.example', 'supply_problem', 'Need 200 hard hats by Friday.');
        $entityManager = $this->entityManager();
        $entityManager->persist($message);
        $entityManager->flush();
        $this->loginAsAdmin();

        $this->client->request('GET', '/backoffice/contact-message');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Jane Doe');

        $this->client->request('GET', sprintf('/backoffice/contact-message/%d', $message->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Need 200 hard hats by Friday.');

        $crawler = $this->client->request('GET', sprintf('/backoffice/contact-message/%d/edit', $message->getId()));
        $form = $crawler->filter('form[name="ContactMessage"]')->form();
        $form['ContactMessage[status]'] = ContactMessage::STATUS_HANDLED;
        $this->client->submit($form);

        self::assertResponseRedirects();
        $saved = $this->savedContactMessages();
        self::assertSame(ContactMessage::STATUS_HANDLED, $saved[0]->getStatus());
        self::assertSame('Need 200 hard hats by Friday.', $saved[0]->getMessage());
    }

    public function testStripeIsTheOnlyCardProcessorOnOffer(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/admin/payment-methods/');
        self::assertResponseIsSuccessful();
        $offered = $this->client->getCrawler()->filter('a[href*="/admin/payment-methods/new/"]')->each(
            static fn ($link): string => basename((string) $link->attr('href')),
        );
        // The page can list the "Create" options more than once.
        $offered = array_values(array_unique($offered));
        sort($offered);
        self::assertSame(['offline', 'stripe_checkout', 'stripe_web_elements'], $offered);

        $this->client->request('GET', '/admin/payment-methods/new/stripe_checkout');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name$="[gatewayConfig][config][publishable_key]"]');
        self::assertSelectorExists('input[name$="[gatewayConfig][config][secret_key]"]');
    }

    private function loginAsAdmin(): void
    {
        $repository = static::getContainer()->get('sylius.repository.admin_user');
        self::assertInstanceOf(UserRepositoryInterface::class, $repository);
        $admin = $repository->findOneBy(['username' => 'admin']);
        self::assertInstanceOf(AdminUserInterface::class, $admin, 'Load the "e2e" fixture suite first.');

        $this->client->loginUser($admin, 'admin');
    }

    private function createRfq(string $company, string $name): int
    {
        $rfq = new RfqRequest();
        $rfq->setCompany($company);
        $rfq->setFullName($name);
        $rfq->setEmail('jane@acme.example');
        $rfq->setPhone('912-665-7257');
        $item = new RfqLineItem();
        $item->setDescription('Hex bolt');
        $rfq->addLineItem($item);

        $entityManager = $this->entityManager();
        $entityManager->persist($rfq);
        $entityManager->flush();

        $id = $rfq->getId();
        self::assertNotNull($id);

        return $id;
    }
}
