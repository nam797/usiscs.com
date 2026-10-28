<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Entity\User\ShopUser;
use App\Tests\Functional\FunctionalTestCase;

/**
 * Customers can't set a weak password: not when they sign up, and not when
 * they change it (App\Validator\StrongPassword via config/validator/password.yaml).
 */
final class PasswordStrengthTest extends FunctionalTestCase
{
    private const EMAIL = 'password-test@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->entityManager()->getRepository(ShopUser::class)->findBy(['usernameCanonical' => self::EMAIL]) as $user) {
            if (!$user instanceof ShopUser) {
                continue;
            }
            $customer = $user->getCustomer();
            $this->entityManager()->remove($user);
            if (null !== $customer) {
                $this->entityManager()->remove($customer);
            }
        }
        $this->entityManager()->flush();
    }

    public function testSignUpShowsTheRuleAndRefusesAWeakPassword(): void
    {
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#password-hint', 'At least 12 characters');

        $this->client->submit($crawler->filter('form[name="sylius_shop_customer_registration"]')->form(), $this->registration('Password1234'));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'This password is too easy to guess');
        self::assertNull($this->user());

        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->filter('form[name="sylius_shop_customer_registration"]')->form(), $this->registration('short1!'));
        self::assertSelectorTextContains('body', 'Use at least 12 characters.');
        self::assertNull($this->user());
    }

    public function testSignUpWithAStrongPasswordWorks(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->filter('form[name="sylius_shop_customer_registration"]')->form(), $this->registration('correct horse battery staple'));

        self::assertResponseRedirects();
        self::assertInstanceOf(ShopUser::class, $this->user());
    }

    public function testChangingToAWeakPasswordIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->filter('form[name="sylius_shop_customer_registration"]')->form(), $this->registration('correct horse battery staple'));
        $user = $this->user();
        self::assertInstanceOf(ShopUser::class, $user);
        $user->setEnabled(true);
        $this->entityManager()->flush();
        $this->client->loginUser($user, 'shop');

        $crawler = $this->client->request('GET', '/account/change-password');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#password-hint', 'At least 12 characters');
        $form = $crawler->filter('form')->reduce(static fn ($f): bool => str_contains((string) $f->attr('name'), 'change_password'))->form();
        $values = [];
        foreach (array_keys($form->getValues()) as $name) {
            $values[$name] = match (true) {
                str_contains($name, 'currentPassword') => 'correct horse battery staple',
                str_contains($name, 'newPassword') => 'Password1234',
                default => $form->getValues()[$name],
            };
        }
        $this->client->submit($form, $values);

        self::assertSelectorTextContains('body', 'This password is too easy to guess');
    }

    /** @return array<string, string> */
    private function registration(string $password): array
    {
        return [
            'sylius_shop_customer_registration[firstName]' => 'Pat',
            'sylius_shop_customer_registration[lastName]' => 'Tester',
            'sylius_shop_customer_registration[email]' => self::EMAIL,
            'sylius_shop_customer_registration[user][plainPassword][first]' => $password,
            'sylius_shop_customer_registration[user][plainPassword][second]' => $password,
        ];
    }

    private function user(): ?ShopUser
    {
        $this->entityManager()->clear();

        $user = $this->entityManager()->getRepository(ShopUser::class)->findOneBy(['usernameCanonical' => self::EMAIL]);

        return $user instanceof ShopUser ? $user : null;
    }
}
