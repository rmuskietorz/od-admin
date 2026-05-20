<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityControllerTest extends WebTestCase
{
    public function testLoginPageRendersForAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('button[type=submit]', 'Anmelden');
    }

    public function testAuthCheckReturns401WhenAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_auth_check');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthCheckReturns200WhenLoggedIn(): void
    {
        $client = static::createClient();
        $user = $this->createTestUser($client->getContainer(), 'tester', 'correct-horse-battery-staple');
        $client->loginUser($user);

        $client->request('GET', '/_auth_check');

        self::assertResponseIsSuccessful();
    }

    public function testLoginWithCorrectCredentialsRedirectsToDashboard(): void
    {
        $client = static::createClient();
        $this->createTestUser($client->getContainer(), 'robin', 'correct-horse-battery-staple');

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => 'robin',
            '_password' => 'correct-horse-battery-staple',
        ]);

        self::assertResponseRedirects('/admin');
    }

    public function testLoginWithWrongPasswordShowsError(): void
    {
        $client = static::createClient();
        $this->createTestUser($client->getContainer(), 'robin', 'correct-horse-battery-staple');

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => 'robin',
            '_password' => 'wrong-password',
        ]);

        $client->followRedirect();
        self::assertSelectorExists('.error');
    }

    public function testLoginWhileAlreadyLoggedInRedirectsToDashboard(): void
    {
        $client = static::createClient();
        $user = $this->createTestUser($client->getContainer(), 'tester', 'correct-horse-battery-staple');
        $client->loginUser($user);

        $client->request('GET', '/admin/login');

        self::assertResponseRedirects('/admin');
    }

    private function createTestUser(\Psr\Container\ContainerInterface $container, string $username, string $password): User
    {
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        // Schema sicherstellen (in-memory SQLite per Test-Env)
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->updateSchema($meta);

        $user = new User($username, 'placeholder');
        $user->setPassword($hasher->hashPassword($user, $password));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
