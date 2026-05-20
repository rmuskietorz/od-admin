<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Legt einen Admin-User mit bcrypt-Hash an',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Username');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');

        $existing = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
        if (null !== $existing) {
            $io->error("User '{$username}' existiert bereits.");

            return Command::FAILURE;
        }

        $q1 = new Question('Passwort: ');
        $q1->setHidden(true);
        $q2 = new Question('Passwort wiederholen: ');
        $q2->setHidden(true);

        $helper = $this->getHelper('question');
        $pw1 = $helper->ask($input, $output, $q1);
        $pw2 = $helper->ask($input, $output, $q2);

        if (!is_string($pw1) || $pw1 !== $pw2) {
            $io->error('Passwoerter stimmen nicht ueberein.');

            return Command::FAILURE;
        }

        if (strlen($pw1) < 12) {
            $io->error('Passwort muss mindestens 12 Zeichen haben.');

            return Command::FAILURE;
        }

        $user = new User($username, 'placeholder');
        $hashed = $this->hasher->hashPassword($user, $pw1);
        $user->setPassword($hashed);
        $user->setRoles(['ROLE_USER']);

        $this->em->persist($user);
        $this->em->flush();

        $io->success("User '{$username}' angelegt.");

        return Command::SUCCESS;
    }
}
