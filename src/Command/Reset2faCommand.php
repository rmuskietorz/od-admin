<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Notausgang: 2FA fuer einen User deaktivieren (z.B. Authenticator verloren UND
 * Recovery-Codes weg). Per SSH:  php bin/console app:2fa-reset <username>
 */
#[AsCommand(name: 'app:2fa-reset', description: '2FA fuer einen User deaktivieren (Notfall-Reset)')]
final class Reset2faCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
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

        $user = $this->users->findOneBy(['username' => $username]);
        if (null === $user) {
            $io->error(sprintf('User "%s" nicht gefunden.', $username));

            return Command::FAILURE;
        }

        if (!$user->isTwoFactorEnabled()) {
            $io->note(sprintf('2FA fuer "%s" war bereits aus.', $username));

            return Command::SUCCESS;
        }

        $user->setTotpSecret(null);
        $user->setRecoveryCodes([]);
        $this->em->flush();

        $io->success(sprintf('2FA fuer "%s" deaktiviert. Naechstes Login ohne 2FA-Schritt.', $username));

        return Command::SUCCESS;
    }
}
