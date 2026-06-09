<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUserCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        // self::getContainer() liefert den Test-Container, der auch private
        // Services (wie EntityManagerInterface) zugaenglich macht. Der echte
        // $kernel->getContainer() hat sie wegremoved/inlined.
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $app = new Application($kernel);
        $cmd = $app->find('app:create-user');
        $this->tester = new CommandTester($cmd);
    }

    public function testCreatesUserWithBcryptHash(): void
    {
        $this->tester->setInputs([
            'correct-horse-battery-staple',
            'correct-horse-battery-staple',
        ]);
        $exit = $this->tester->execute(['username' => 'robin']);

        self::assertSame(0, $exit);

        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'robin']);
        self::assertNotNull($user);
        self::assertNotSame('correct-horse-battery-staple', $user->getPassword());
        self::assertStringStartsWith('$2', $user->getPassword(), 'bcrypt prefix erwartet');

        // Hash verifizieren
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($user, 'correct-horse-battery-staple'));
    }

    public function testRejectsTooShortPassword(): void
    {
        $this->tester->setInputs(['short', 'short']);
        $exit = $this->tester->execute(['username' => 'robin']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('mindestens 12 Zeichen', $this->tester->getDisplay());
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['username' => 'robin']));
    }

    public function testRejectsMismatchedPasswords(): void
    {
        $this->tester->setInputs([
            'correct-horse-battery-staple',
            'different-password-12chars',
        ]);
        $exit = $this->tester->execute(['username' => 'robin']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('stimmen nicht ueberein', $this->tester->getDisplay());
    }

    public function testRejectsDuplicateUsername(): void
    {
        $this->tester->setInputs([
            'correct-horse-battery-staple',
            'correct-horse-battery-staple',
        ]);
        $this->tester->execute(['username' => 'robin']);

        $this->tester = new CommandTester(
            (new Application(self::$kernel))->find('app:create-user'),
        );
        $this->tester->setInputs([
            'another-strong-password',
            'another-strong-password',
        ]);
        $exit = $this->tester->execute(['username' => 'robin']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('existiert bereits', $this->tester->getDisplay());
    }
}
