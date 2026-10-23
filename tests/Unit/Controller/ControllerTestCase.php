<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

/**
 * Wires an AbstractController up without booting the kernel: real form
 * factory (with validation and request handling), a real session for flash
 * messages, and a Twig stub that records what was rendered.
 */
abstract class ControllerTestCase extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    protected array $rendered = [];

    protected Session $session;

    protected function wire(AbstractController $controller, Request $request): void
    {
        $this->rendered = [];
        $this->session = new Session(new MockArraySessionStorage());
        $request->setSession($this->session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $name, array $context = []): string {
            /** @var array<string, mixed> $context */
            $this->rendered[] = [$name, $context];

            return 'rendered:'.$name;
        });

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name): string => '/route/'.$name);

        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()))
            ->getFormFactory();

        $container = new Container();
        $container->set('twig', $twig);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);
        $container->set('form.factory', $formFactory);

        $controller->setContainer($container);
    }

    /** @return list<string> */
    protected function flashes(string $type): array
    {
        $messages = [];
        foreach ($this->session->getFlashBag()->peek($type) as $message) {
            self::assertIsString($message);
            $messages[] = $message;
        }

        return $messages;
    }

    /** @return list<string> */
    protected function renderedTemplates(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->rendered);
    }
}
