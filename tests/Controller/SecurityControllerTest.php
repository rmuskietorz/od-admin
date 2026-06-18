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
        $client->request('GET', '/login');

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
        // Firewall-Name 'admin' ist zwingend: ohne ihn nutzt loginUser() den
        // Default 'main', der hier nicht existiert -> Token wird nicht erkannt.
        $client->loginUser($user, 'admin');

        $client->request('GET', '/_auth_check');

        self::assertResponseIsSuccessful();
    }

    /**
     * Reverse-Proxy-Gate: nginx fragt vor jedem OD-Request `/_auth_check`.
     * Dieser Test fuehrt den ECHTEN Form-Login durch (setzt das reale
     * Session-Cookie) und ruft danach `/_auth_check` mit demselben Client auf.
     * Faengt damit beide realen Bugs, die loginUser() umgeht:
     *   1. cookie_path=/admin -> Cookie wird fuer /_auth_check nicht gesendet
     *   2. separate auth_check-Firewall (security:false) -> Session unsichtbar
     */
    public function testAuthCheckSucceedsAfterRealFormLogin(): void
    {
        $client = static::createClient();
        $this->createTestUser($client->getContainer(), 'robin', 'correct-horse-battery-staple');

        $client->request('GET', '/login');
        $client->submitForm('Anmelden', [
            '_username' => 'robin',
            '_password' => 'correct-horse-battery-staple',
        ]);
        self::assertResponseRedirects('/');

        $client->request('GET', '/_auth_check');
        self::assertResponseIsSuccessful();
    }

    public function testLoginWithCorrectCredentialsRedirectsToDashboard(): void
    {
        $client = static::createClient();
        $this->createTestUser($client->getContainer(), 'robin', 'correct-horse-battery-staple');

        $client->request('GET', '/login');
        $client->submitForm('Anmelden', [
            '_username' => 'robin',
            '_password' => 'correct-horse-battery-staple',
        ]);

        self::assertResponseRedirects('/');
    }

    public function testLoginWithWrongPasswordShowsError(): void
    {
        $client = static::createClient();
        $this->createTestUser($client->getContainer(), 'robin', 'correct-horse-battery-staple');

        $client->request('GET', '/login');
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
        // Firewall-Name 'admin' ist zwingend: ohne ihn nutzt loginUser() den
        // Default 'main', der hier nicht existiert -> Token wird nicht erkannt.
        $client->loginUser($user, 'admin');

        $client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    private function createTestUser(\Psr\Container\ContainerInterface $container, string $username, string $password): User
    {
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        // Frisches Schema pro Test: dropDatabase() + createSchema() statt
        // updateSchema(), damit die File-DB nicht ueber Testmethoden hinweg
        // Zeilen akkumuliert (sonst UNIQUE-Constraint-Kollisionen auf username).
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropDatabase();
        $tool->createSchema($meta);

        $user = new User($username, 'placeholder');
        $user->setPassword($hasher->hashPassword($user, $password));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
