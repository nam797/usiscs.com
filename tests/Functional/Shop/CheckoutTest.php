<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Entity\Channel\Channel;
use App\Entity\Order\Order;
use App\Entity\Order\OrderItem;
use App\Entity\Order\OrderItemUnit;
use App\Entity\Product\ProductVariant;
use App\Entity\User\ShopUser;
use App\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Checkout and account pages work at the short URLs (no /en_US prefix):
 * each checkout step moves on to the next, and the account needs a login.
 * Both rely on sylius.security.shop_regex (config/packages/sylius_shop.yaml).
 */
final class CheckoutTest extends FunctionalTestCase
{
    private const EMAIL = 'checkout-test@example.test';

    public function testAccountPagesNeedALogin(): void
    {
        foreach (['/account/dashboard', '/account/orders/', '/account/profile/edit'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('http://localhost/login', null, $path);
        }
    }

    public function testCheckoutWithAnEmptyCartGoesBackToTheCart(): void
    {
        $this->client->request('GET', '/checkout/address');

        self::assertResponseRedirects('/cart/');
    }

    public function testTheAddressStepMovesOnToShipping(): void
    {
        $user = $this->customerWithACart();
        $this->client->loginUser($user, 'shop');

        $crawler = $this->client->request('GET', '/checkout/address');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="sylius_shop_checkout_address"]')->form();
        $address = [
            'firstName' => 'Pat',
            'lastName' => 'Tester',
            'street' => '1 Main St',
            'city' => 'Springfield',
            'postcode' => '62701',
            'countryCode' => 'US',
        ];
        foreach ($address as $field => $value) {
            $form[sprintf('sylius_shop_checkout_address[billingAddress][%s]', $field)] = $value;
        }
        $this->client->submit($form);

        self::assertResponseRedirects('/checkout/select-shipping');
    }

    private function customerWithACart(): ShopUser
    {
        $entityManager = $this->entityManager();
        $container = static::getContainer();

        $existing = $entityManager->getRepository(ShopUser::class)->findOneBy(['usernameCanonical' => self::EMAIL]);
        if ($existing instanceof ShopUser) {
            foreach ($entityManager->getRepository(Order::class)->findBy(['customer' => $existing->getCustomer()]) as $order) {
                $entityManager->remove($order);
            }
            $entityManager->remove($existing);
            if (null !== $existing->getCustomer()) {
                $entityManager->remove($existing->getCustomer());
            }
            $entityManager->flush();
        }

        $customerFactory = $container->get('sylius.factory.customer');
        self::assertInstanceOf(FactoryInterface::class, $customerFactory);
        $customer = $customerFactory->createNew();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $customer->setEmail(self::EMAIL);
        $customer->setFirstName('Pat');
        $customer->setLastName('Tester');

        $user = new ShopUser();
        $user->setCustomer($customer);
        $user->setUsername(self::EMAIL);
        $user->setPlainPassword('correct horse battery staple');
        $user->setEnabled(true);
        $entityManager->persist($user);

        $product = $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariant::class, $variant);
        $channel = $entityManager->getRepository(Channel::class)->findOneBy(['code' => 'default']);
        self::assertInstanceOf(Channel::class, $channel);

        $cart = new Order();
        $cart->setChannel($channel);
        // As Sylius does for a logged-in customer's cart (so the cart context finds it).
        $cart->setCustomerWithAuthorization($customer);
        $cart->setCurrencyCode('USD');
        $cart->setLocaleCode('en_US');
        $cart->setState(OrderInterface::STATE_CART);
        $item = new OrderItem();
        $item->setVariant($variant);
        $item->setUnitPrice(1299);
        new OrderItemUnit($item);
        $cart->addItem($item);
        $entityManager->persist($cart);
        $entityManager->flush();

        return $user;
    }
}
