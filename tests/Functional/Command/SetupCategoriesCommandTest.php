<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Catalog\Category\CategoryTree;
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
    public function testItLeavesTheApprovedTreeInPlaceAndAttachedToTheMenu(): void
    {
        $tester = $this->runCommand();

        $tester->assertCommandIsSuccessful();

        $root = $this->taxon(SetupCategoriesCommand::ROOT_CODE);
        self::assertInstanceOf(TaxonInterface::class, $root);

        $departments = [];
        foreach ($root->getChildren() as $child) {
            if ($child->isEnabled()) {
                $departments[] = (string) $child->getCode();
            }
        }
        self::assertSame(array_keys(CategoryTree::TREE), $departments);

        foreach (CategoryTree::flat() as $code => $category) {
            $taxon = $this->taxon($code);
            self::assertNotNull($taxon, sprintf('%s is missing.', $code));
            self::assertSame($category['name'], $taxon->getName());
            self::assertSame($category['parent'] ?? SetupCategoriesCommand::ROOT_CODE, $taxon->getParent()?->getCode());
        }
        self::assertSame('safety-ppe/hand-protection/cut-resistant-gloves', $this->taxon('cut_resistant_gloves')?->getSlug());

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
        $missing = $this->taxon('footwear');
        self::assertNotNull($missing);
        $entityManager->remove($missing);
        $entityManager->flush();

        $tester = $this->runCommand();

        self::assertStringContainsString('Footwear', $tester->getDisplay());
        self::assertStringContainsString('Created 1 category.', $tester->getDisplay());
        self::assertSame('safety-ppe/footwear', $this->taxon('footwear')?->getSlug());
    }

    public function testItNeverOverwritesAnAdminsChanges(): void
    {
        $this->runCommand();
        $entityManager = $this->entityManager();
        $renamed = $this->taxon('tape');
        self::assertNotNull($renamed);
        $renamed->setCurrentLocale('en_US');
        $renamed->setName('Tape & Adhesive Tape');
        $entityManager->flush();

        try {
            $this->runCommand();

            self::assertSame('Tape & Adhesive Tape', $this->taxon('tape')?->getName());
        } finally {
            $taxon = $this->taxon('tape');
            self::assertNotNull($taxon);
            $taxon->setName('Tape');
            $this->entityManager()->flush();
        }
    }

    public function testItRemovesAnEmptyLaunchPlaceholder(): void
    {
        $this->runCommand();
        $root = $this->taxon(SetupCategoriesCommand::ROOT_CODE);
        self::assertNotNull($root);
        $placeholder = new Taxon();
        $placeholder->setCode('specialty_hard_to_find');
        $placeholder->setCurrentLocale('en_US');
        $placeholder->setFallbackLocale('en_US');
        $placeholder->setName('Specialty & Hard-to-Find');
        $placeholder->setSlug('specialty-hard-to-find');
        $root->addChild($placeholder);
        $this->entityManager()->persist($placeholder);
        $this->entityManager()->flush();

        $tester = $this->runCommand();

        self::assertStringContainsString('Removed the old categories: Specialty & Hard-to-Find.', $tester->getDisplay());
        self::assertNull($this->taxon('specialty_hard_to_find'));
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
