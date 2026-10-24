<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\SetupCategoriesCommand;
use App\Entity\Channel\Channel;
use App\Entity\Taxonomy\Taxon;
use App\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCategoriesCommandTest extends FunctionalTestCase
{
    public function testItLeavesTheFullTreeInPlaceAndAttachedToTheMenu(): void
    {
        $tester = $this->runCommand();

        $tester->assertCommandIsSuccessful();

        $root = $this->taxon(SetupCategoriesCommand::ROOT_CODE);
        self::assertInstanceOf(TaxonInterface::class, $root);

        $children = [];
        foreach ($root->getChildren() as $child) {
            $children[(string) $child->getCode()] = (string) $child->getName();
        }
        self::assertSame(SetupCategoriesCommand::CATEGORIES, $children);

        $channel = $this->entityManager()->getRepository(Channel::class)->findOneBy(['code' => 'default']);
        self::assertInstanceOf(ChannelInterface::class, $channel);
        self::assertSame(SetupCategoriesCommand::ROOT_CODE, $channel->getMenuTaxon()?->getCode());
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $this->runCommand();
        $tester = $this->runCommand();

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('nothing to do', $tester->getDisplay());
    }

    public function testItRecreatesAMissingCategory(): void
    {
        $this->runCommand();
        $entityManager = $this->entityManager();
        $missing = $this->taxon('specialty_hard_to_find');
        self::assertNotNull($missing);
        $entityManager->remove($missing);
        $entityManager->flush();

        $tester = $this->runCommand();

        self::assertStringContainsString('Specialty & Hard-to-Find', $tester->getDisplay());
        self::assertStringContainsString('Created 1 category.', $tester->getDisplay());
        self::assertSame('specialty-hard-to-find', $this->taxon('specialty_hard_to_find')?->getSlug());
    }

    public function testItNeverOverwritesAnAdminsChanges(): void
    {
        $this->runCommand();
        $entityManager = $this->entityManager();
        $renamed = $this->taxon('fasteners_hardware');
        self::assertNotNull($renamed);
        $renamed->setCurrentLocale('en_US');
        $renamed->setName('Fasteners, Anchors & Hardware');
        $entityManager->flush();

        try {
            $this->runCommand();

            $taxon = $this->taxon('fasteners_hardware');
            self::assertNotNull($taxon);
            $taxon->setCurrentLocale('en_US');
            self::assertSame('Fasteners, Anchors & Hardware', $taxon->getName());
        } finally {
            $taxon = $this->taxon('fasteners_hardware');
            self::assertNotNull($taxon);
            $taxon->setCurrentLocale('en_US');
            $taxon->setName(SetupCategoriesCommand::CATEGORIES['fasteners_hardware']);
            $this->entityManager()->flush();
        }
    }

    private function runCommand(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('app:catalog:setup-categories'));
        $tester->execute([]);
        $this->entityManager()->clear();

        return $tester;
    }

    private function taxon(string $code): ?TaxonInterface
    {
        $taxon = $this->entityManager()->getRepository(Taxon::class)->findOneBy(['code' => $code]);
        if ($taxon instanceof TaxonInterface) {
            $taxon->setCurrentLocale('en_US');
        }

        return $taxon instanceof TaxonInterface ? $taxon : null;
    }
}
