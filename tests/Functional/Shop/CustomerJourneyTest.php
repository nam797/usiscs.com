<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Entity\Addressing\Zone;
use App\Entity\Channel\Channel;
use App\Entity\Order\Order;
use App\Entity\Payment\PaymentMethod;
use App\Entity\Shipping\ShippingMethod;
use App\Entity\User\ShopUser;
use App\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;

/**
 * Every journey a customer takes through the shop, at the real URLs, the
 * way a browser does (the add-to-cart button is a live component: posted
 * to like the page's JavaScript would). Written after the short URLs had
 * silently broken checkout and the account login: each journey runs end
 * to end, so a broken step fails here first.
 */
final class CustomerJourneyTest extends FunctionalTestCase
{
    private const EMAIL = 'journey@example.test';
    private const PASSWORD = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = $this->entityManager();
        foreach ($entityManager->getRepository(ShopUser::class)->findBy(['usernameCanonical' => [self::EMAIL, 'guest-journey@example.test']]) as $user) {
            if ($user instanceof ShopUser) {
                $entityManager->remove($user);
            }
        }
        $entityManager->flush();
        $connection = $entityManager->getConnection();
        foreach ([self::EMAIL, 'guest-journey@example.test'] as $email) {
            $connection->executeStatement('DELETE o FROM sylius_order o JOIN sylius_customer c ON c.id = o.customer_id WHERE c.email = ?', [$email]);
            $connection->executeStatement('DELETE FROM sylius_customer WHERE email = ?', [$email]);
        }
        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');
        $this->ensureShippingMethod();
        $this->ensureOfflinePaymentMethod();
    }

    public function testAGuestBuysFromAProductPageToTheThankYouPage(): void
    {
        $this->addToCart('safety-glasses', 2);

        $this->client->request('GET', '/cart/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Safety Glasses');

        $this->client->request('GET', '/checkout/');
        self::assertResponseRedirects('/checkout/address');
        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('form[name="sylius_shop_checkout_address"]')->form();
        $this->client->submit($form, ['sylius_shop_checkout_address[customer][email]' => 'guest-journey@example.test'] + self::address('billingAddress'));
        self::assertResponseRedirects('/checkout/select-shipping');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Standard');
        $this->client->submit($crawler->filter('form[name="sylius_shop_checkout_select_shipping"]')->form());
        self::assertResponseRedirects('/checkout/select-payment');

        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('form[name="sylius_shop_checkout_select_payment"]')->form();
        $form['sylius_shop_checkout_select_payment[payments][0][method]'] = 'e2e_offline';
        $this->client->submit($form);
        self::assertResponseRedirects('/checkout/complete');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Safety Glasses');
        self::assertSelectorTextContains('body', '1 Main St');
        $this->client->submit($crawler->filter('form[name="sylius_checkout_complete"]')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        while ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/order/thank-you', (string) $this->client->getRequest()->getUri());
        self::assertSelectorTextContains('body', 'Thank you');

        $order = $this->latestOrderFor('guest-journey@example.test');
        self::assertSame('completed', $order->getCheckoutState());
        self::assertSame(2, $order->getItemUnits()->count());
        self::assertSame('Springfield', $order->getShippingAddress()?->getCity());

        // The order page linked from the confirmation email works for the guest.
        $this->client->request('GET', sprintf('/order/%s', $order->getTokenValue()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $order->getNumber());
    }

    public function testALoggedInCustomerChecksOutAndSeesTheOrderInTheirAccount(): void
    {
        $this->client->loginUser($this->customer(), 'shop');
        $this->addToCart('safety-glasses', 1);

        $crawler = $this->client->request('GET', '/checkout/address');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="sylius_shop_checkout_address[customer][email]"]', 'a logged-in customer isn\'t asked for an email');
        $this->client->submit($crawler->filter('form[name="sylius_shop_checkout_address"]')->form(), self::address('billingAddress'));
        self::assertResponseRedirects('/checkout/select-shipping');
        $this->client->submit($this->client->followRedirect()->filter('form[name="sylius_shop_checkout_select_shipping"]')->form());
        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('form[name="sylius_shop_checkout_select_payment"]')->form();
        $form['sylius_shop_checkout_select_payment[payments][0][method]'] = 'e2e_offline';
        $this->client->submit($form);
        $this->client->submit($this->client->followRedirect()->filter('form[name="sylius_checkout_complete"]')->form());
        while ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }
        self::assertSelectorTextContains('body', 'Thank you');

        $order = $this->latestOrderFor(self::EMAIL);
        $this->client->request('GET', '/account/orders/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $order->getNumber());
        $this->client->request('GET', sprintf('/account/orders/%s', $order->getNumber()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Safety Glasses');

        // The checkout address is saved to the address book for next time.
        $this->client->request('GET', '/account/address-book/');
        self::assertSelectorTextContains('body', '1 Main St');
    }

    public function testTheCartCanBeChangedAndEmptied(): void
    {
        $this->addToCart('safety-glasses', 1);

        // Change the quantity and go on to checkout (the cart form's own submit).
        $crawler = $this->client->request('GET', '/cart/');
        $form = $crawler->filter('form[name="sylius_shop_cart"]')->form();
        $form['sylius_shop_cart[items][0][quantity]'] = '3';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/cart/');
        self::assertSame('3', $crawler->filter('input[name="sylius_shop_cart[items][0][quantity]"]')->attr('value'));

        // Remove it (a live component action, as the cart page's button does).
        $component = $crawler->filter('[data-live-name-value="sylius_shop:cart:form"]');
        $this->client->request('POST', $component->attr('data-live-url-value').'/removeItem', [
            'data' => json_encode(['props' => json_decode((string) $component->attr('data-live-props-value'), true), 'updated' => [], 'args' => ['index' => '0']]),
        ], [], ['HTTP_ACCEPT' => 'application/vnd.live-component+html']);
        self::assertLessThan(400, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/cart/');
        self::assertSelectorTextContains('body', 'Your cart is empty');
    }

    public function testSignUpVerifyLogInAndOut(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->filter('form[name="sylius_shop_customer_registration"]')->form(), [
            'sylius_shop_customer_registration[firstName]' => 'Jo',
            'sylius_shop_customer_registration[lastName]' => 'Journey',
            'sylius_shop_customer_registration[email]' => self::EMAIL,
            'sylius_shop_customer_registration[user][plainPassword][first]' => self::PASSWORD,
            'sylius_shop_customer_registration[user][plainPassword][second]' => self::PASSWORD,
        ]);
        self::assertResponseRedirects();
        $verification = $this->linkInEmail('/verify/');

        $this->client->request('GET', $verification);
        self::assertResponseRedirects();
        $user = $this->entityManager()->getRepository(ShopUser::class)->findOneBy(['usernameCanonical' => self::EMAIL]);
        self::assertInstanceOf(ShopUser::class, $user);
        $this->entityManager()->refresh($user);
        self::assertTrue($user->isVerified());

        $this->logIn(self::PASSWORD);
        $this->client->request('GET', '/account/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Jo');

        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/account/dashboard');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testAForgottenPasswordCanBeReset(): void
    {
        $this->customer();

        $crawler = $this->client->request('GET', '/forgotten-password');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[name="sylius_user_request_password_reset"]')->form(), ['sylius_user_request_password_reset[email]' => self::EMAIL]);
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', $this->linkInEmail('/forgotten-password/'));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="sylius_user_reset_password"]')->form();
        $values = [];
        foreach (array_keys($form->getValues()) as $name) {
            if (str_contains($name, '[password]')) {
                $values[$name] = 'a brand new passphrase here';
            }
        }
        $this->client->submit($form, $values);
        self::assertResponseRedirects();

        $this->logIn('a brand new passphrase here');
        $this->client->request('GET', '/account/dashboard');
        self::assertResponseIsSuccessful();
    }

    public function testTheAccountPagesWork(): void
    {
        $user = $this->customer();
        $this->client->loginUser($user, 'shop');

        foreach (['/account/dashboard', '/account/profile/edit', '/account/address-book/', '/account/address-book/add', '/account/orders/', '/account/change-password'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }

        // Edit the profile.
        $crawler = $this->client->request('GET', '/account/profile/edit');
        $form = $crawler->filter('form')->reduce(static fn (Crawler $f): bool => str_contains((string) $f->attr('name'), 'customer_profile'))->form();
        $form[$form->getName().'[firstName]'] = 'Joanna';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->request('GET', '/account/dashboard');
        self::assertSelectorTextContains('body', 'Joanna');

        // Add an address to the book.
        $crawler = $this->client->request('GET', '/account/address-book/add');
        $form = $crawler->filter('form')->reduce(static fn (Crawler $f): bool => str_contains((string) $f->attr('name'), 'address'))->form();
        $values = [];
        foreach (self::address('') as $field => $value) {
            $values[$form->getName().substr($field, strlen('sylius_shop_checkout_address[]'))] = $value;
        }
        $this->client->submit($form, $values);
        self::assertResponseRedirects();
        $this->client->request('GET', '/account/address-book/');
        self::assertSelectorTextContains('body', '1 Main St');
    }

    public function testAnExpiredFormSaysSoInsteadOfComingBackBlank(): void
    {
        $this->customer();
        $forms = [
            ['/forgotten-password', 'sylius_user_request_password_reset', ['sylius_user_request_password_reset[email]' => self::EMAIL]],
        ];
        foreach ($forms as [$path, $name, $values]) {
            $crawler = $this->client->request('GET', $path);
            $this->client->submit($crawler->filter(sprintf('form[name="%s"]', $name))->form(), [$name.'[_token]' => 'expired'] + $values);
            self::assertSelectorExists('[data-test-form-errors]', $path);
        }

        $user = $this->entityManager()->getRepository(ShopUser::class)->findOneBy(['usernameCanonical' => self::EMAIL]);
        self::assertInstanceOf(ShopUser::class, $user);
        $this->client->loginUser($user, 'shop');
        $crawler = $this->client->request('GET', '/account/change-password');
        $form = $crawler->filter('form')->reduce(static fn (Crawler $f): bool => str_contains((string) $f->attr('name'), 'change_password'))->form();
        $values = [];
        foreach (array_keys($form->getValues()) as $field) {
            $values[$field] = str_ends_with($field, '[_token]') ? 'expired' : (str_contains($field, 'currentPassword') ? self::PASSWORD : 'another strong passphrase');
        }
        $this->client->submit($form, $values);
        self::assertSelectorExists('[data-test-form-errors]', 'change password');
    }

    public function testErrorPagesStayInTheShop(): void
    {
        $this->client->request('GET', '/no-such-page-at-all');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('a[href="/"]');
        self::assertSelectorExists('a[href="/contact"]');
    }

    private function addToCart(string $slug, int $quantity): void
    {
        $crawler = $this->client->request('GET', '/products/'.$slug);
        $component = $crawler->filter('[data-live-name-value="sylius_shop:product:add_to_cart_form"]');
        self::assertCount(1, $component, 'add to cart form');
        $props = json_decode((string) $component->attr('data-live-props-value'), true);
        $this->client->request('POST', $component->attr('data-live-url-value').'/addToCart', [
            'data' => json_encode(['props' => $props, 'updated' => ['sylius_shop_add_to_cart.cartItem.quantity' => (string) $quantity], 'args' => []]),
        ], [], ['HTTP_ACCEPT' => 'application/vnd.live-component+html']);
        self::assertSame('/cart/', $this->client->getResponse()->headers->get('Location'), 'added, and sent to the cart');
    }

    /** @return array<string, string> */
    private static function address(string $which): array
    {
        $values = [];
        foreach (['firstName' => 'Pat', 'lastName' => 'Tester', 'street' => '1 Main St', 'city' => 'Springfield', 'postcode' => '62701', 'countryCode' => 'US'] as $field => $value) {
            $values[sprintf('sylius_shop_checkout_address[%s][%s]', $which, $field)] = $value;
        }

        return $values;
    }

    private function logIn(string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form')->reduce(static fn (Crawler $f): bool => null !== $f->filter('input[type="password"]')->getNode(0))->form();
        $values = [];
        foreach (array_keys($form->getValues()) as $name) {
            if (str_contains($name, 'username') || str_contains($name, 'email')) {
                $values[$name] = self::EMAIL;
            } elseif (str_contains($name, 'password')) {
                $values[$name] = $password;
            }
        }
        $this->client->submit($form, $values);
        self::assertResponseRedirects();
    }

    /** The first link to $path in an email sent during the last request. */
    private function linkInEmail(string $path): string
    {
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && preg_match('#https?://[^\s"<]+'.preg_quote($path, '#').'[^\s"<]+#', (string) ($message->getHtmlBody() ?? $message->getTextBody()), $match)) {
                return html_entity_decode($match[0]);
            }
        }
        self::fail(sprintf('No email with a %s link.', $path));
    }

    private function customer(): ShopUser
    {
        $container = static::getContainer();
        $factory = $container->get('sylius.factory.shop_user');
        self::assertInstanceOf(FactoryInterface::class, $factory);
        $user = $factory->createNew();
        self::assertInstanceOf(ShopUser::class, $user);
        $customerFactory = $container->get('sylius.factory.customer');
        self::assertInstanceOf(FactoryInterface::class, $customerFactory);
        $customer = $customerFactory->createNew();
        self::assertInstanceOf(\Sylius\Component\Core\Model\CustomerInterface::class, $customer);
        $customer->setEmail(self::EMAIL);
        $customer->setFirstName('Jo');
        $customer->setLastName('Journey');
        $user->setCustomer($customer);
        $user->setUsername(self::EMAIL);
        $user->setPlainPassword(self::PASSWORD);
        $user->setEnabled(true);
        $user->setVerifiedAt(new \DateTime());
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function latestOrderFor(string $email): Order
    {
        $this->entityManager()->clear();
        $order = $this->entityManager()->getRepository(Order::class)->createQueryBuilder('o')
            ->join('o.customer', 'c')->where('c.email = :email')->setParameter('email', $email)
            ->orderBy('o.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }

    private function ensureShippingMethod(): void
    {
        $entityManager = $this->entityManager();
        if (null !== $entityManager->getRepository(ShippingMethod::class)->findOneBy(['code' => 'e2e_standard'])) {
            return;
        }
        $zone = $entityManager->getRepository(Zone::class)->findOneBy(['code' => 'US']);
        self::assertInstanceOf(Zone::class, $zone, 'Load the "e2e" fixture suite first.');
        $channel = $entityManager->getRepository(Channel::class)->findOneBy(['code' => 'default']);
        self::assertInstanceOf(Channel::class, $channel);
        $factory = static::getContainer()->get('sylius.factory.shipping_method');
        self::assertInstanceOf(FactoryInterface::class, $factory);
        $method = $factory->createNew();
        self::assertInstanceOf(ShippingMethodInterface::class, $method);
        $method->setCode('e2e_standard');
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Standard');
        $method->setZone($zone);
        $method->setCalculator('flat_rate');
        $method->setConfiguration(['default' => ['amount' => 1500]]);
        $method->addChannel($channel);
        $entityManager->persist($method);
        $entityManager->flush();
    }

    private function ensureOfflinePaymentMethod(): void
    {
        $entityManager = $this->entityManager();
        if (null !== $entityManager->getRepository(PaymentMethod::class)->findOneBy(['code' => 'e2e_offline'])) {
            return;
        }
        $channel = $entityManager->getRepository(Channel::class)->findOneBy(['code' => 'default']);
        self::assertInstanceOf(Channel::class, $channel);
        $factory = static::getContainer()->get('sylius.factory.payment_method');
        self::assertInstanceOf(PaymentMethodFactoryInterface::class, $factory);
        $method = $factory->createWithGateway('offline');
        self::assertInstanceOf(PaymentMethod::class, $method);
        $method->setCode('e2e_offline');
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Offline');
        $method->getGatewayConfig()?->setGatewayName('offline');
        $method->addChannel($channel);
        $entityManager->persist($method);
        $entityManager->flush();
    }
}
