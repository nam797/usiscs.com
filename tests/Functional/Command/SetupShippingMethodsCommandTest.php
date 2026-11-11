<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\SetupShippingMethodsCommand;
use App\Entity\Shipping\ShippingMethod;
use App\Shipping\Carrier;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupShippingMethodsCommandTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->removeMethods();
    }

    protected function tearDown(): void
    {
        // Checkout tests expect only their own shipping method.
        $this->removeMethods();
        parent::tearDown();
    }

    private function removeMethods(): void
    {
        foreach ($this->methods() as $method) {
            $this->entityManager()->remove($method);
        }
        $this->entityManager()->flush();
    }

    public function testItCreatesUpsEnabledAndFedexDisabledWithAFlatFee(): void
    {
        $tester = $this->runCommand();

        $tester->assertCommandIsSuccessful();
        $methods = $this->methods();
        self::assertSame(array_keys(SetupShippingMethodsCommand::METHODS), array_keys($methods));

        $ground = $methods['ups_ground'];
        self::assertSame('UPS Ground', $ground->getName());
        self::assertSame(Carrier::Ups, $ground->getCarrier());
        self::assertTrue($ground->isEnabled());
        self::assertSame('flat_rate', $ground->getCalculator());
        self::assertSame(['default' => ['amount' => 1500]], $ground->getConfiguration());
        self::assertSame('US', $ground->getZone()?->getCode());
        self::assertSame(['default'], array_values($ground->getChannels()->map(static fn ($c): ?string => $c->getCode())->toArray()));

        self::assertSame(Carrier::Fedex, $methods['fedex_ground']->getCarrier());
        self::assertFalse($methods['fedex_ground']->isEnabled());
    }

    public function testRunningItAgainNeverUndoesAnAdminsChanges(): void
    {
        $this->runCommand();
        $methods = $this->methods();
        $methods['ups_ground']->setConfiguration(['default' => ['amount' => 999]]);
        $methods['fedex_ground']->enable();
        $this->entityManager()->flush();

        $tester = $this->runCommand();

        self::assertStringContainsString('nothing to do', $tester->getDisplay());
        $methods = $this->methods();
        self::assertSame(['default' => ['amount' => 999]], $methods['ups_ground']->getConfiguration());
        self::assertTrue($methods['fedex_ground']->isEnabled());
    }

    private function runCommand(): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:shipping:setup-methods'));
        $tester->execute([]);

        return $tester;
    }

    /** @return array<string, ShippingMethod> by code, in setup order */
    private function methods(): array
    {
        $this->entityManager()->clear();
        $methods = [];
        foreach (array_keys(SetupShippingMethodsCommand::METHODS) as $code) {
            $method = $this->entityManager()->getRepository(ShippingMethod::class)->findOneBy(['code' => $code]);
            if ($method instanceof ShippingMethod) {
                $methods[$code] = $method;
            }
        }

        return $methods;
    }
}
