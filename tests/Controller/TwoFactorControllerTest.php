<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TwoFactorControllerTest extends WebTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP'; // gueltiges Base32-Testsecret

    public function testLoginWith2faGatesUntilCodeEntered(): void
    {
        $client = static::createClient();
        $this->createUserWith2fa($client->getContainer(), 'robin', 'correct-horse-battery-staple');

        // Passwort-Login
        $client->request('GET', '/login');
        $client->submitForm('Anmelden', [
            '_username' => 'robin',
            '_password' => 'correct-horse-battery-staple',
        ]);

        // Gate: OD-Auth-Check bleibt 401, bis 2FA erledigt
        $client->request('GET', '/_auth_check');
        self::assertResponseStatusCodeSame(401);

        // Verify-Formular ist erreichbar
        $client->request('GET', '/2fa');
        self::assertResponseIsSuccessful();

        // Korrekter Code -> durch
        $client->submitForm('Bestätigen', ['code' => TOTP::createFromSecret(self::SECRET)->now()]);
        self::assertResponseRedirects('/');

        $client->request('GET', '/_auth_check');
        self::assertResponseIsSuccessful();
    }

    public function testWrong2faCodeKeepsGateClosed(): void
    {
        $client = static::createClient();
        $this->createUserWith2fa($client->getContainer(), 'robin', 'correct-horse-battery-staple');

        $client->request('GET', '/login');
        $client->submitForm('Anmelden', [
            '_username' => 'robin',
            '_password' => 'correct-horse-battery-staple',
        ]);

        $client->request('GET', '/2fa');
        $client->submitForm('Bestätigen', ['code' => '000000']);
        self::assertSelectorExists('.error');

        $client->request('GET', '/_auth_check');
        self::assertResponseStatusCodeSame(401);
    }

    private function createUserWith2fa(\Psr\Container\ContainerInterface $container, string $username, string $password): User
    {
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropDatabase();
        $tool->createSchema($meta);

        $user = new User($username, 'placeholder');
        $user->setPassword($hasher->hashPassword($user, $password));
        $user->setTotpSecret(self::SECRET);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
