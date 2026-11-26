<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Spond\ImportSpondAttendance;
use App\Application\Spond\ImportSpondCalendar;
use App\Application\Spond\RecordSpondRead;
use App\Application\Spond\SpondCredentials;
use App\Application\Spond\SpondExportException;
use App\Domain\Spond\SpondReply;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:spond:read', description: 'Liest die Termine von Historic Team Winterthur aus Spond in den Vereinskalender. Es wird nichts zurückgeschrieben.')]
final class ReadSpondCommand extends Command
{
    public function __construct(
        private SpondCredentials $credentials,
        private RecordSpondRead $record,
        private ImportSpondCalendar $calendar,
        private ImportSpondAttendance $attendance,
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $secrets = $this->credentials->resolve($this->environmentValues());
        } catch (SpondExportException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }

        $env = $this->environmentValues();
        $env['SPOND_USERNAME'] = $secrets['username'];
        $env['SPOND_PASSWORD'] = $secrets['password'];
        $result = $this->runPython($this->python(), $this->projectDir.'/bin/spond_read.py', $env);
        if ($result === null) {
            $output->writeln('Spond-Leseabfrage fehlgeschlagen.');

            return Command::FAILURE;
        }
        if ($result['code'] !== 0) {
            $message = trim($this->redact($result['stderr'].$result['stdout'], $secrets));
            $output->writeln($message !== '' ? $message : 'Spond-Leseabfrage fehlgeschlagen.');

            return Command::FAILURE;
        }

        try {
            $export = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $output->writeln('Die Spond-Antwort ist kein gültiges JSON.');

            return Command::FAILURE;
        }
        if (!is_array($export)) {
            $output->writeln('Die Spond-Antwort ist unvollständig.');

            return Command::FAILURE;
        }

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich'));
            $read = ($this->record)($export);
            $events = $this->calendar->import($export, $now);
            $finish = ($this->attendance)($read, $events, $now);
            $this->entityManager->flush();
            foreach ($finish as $apply) {
                $apply();
            }
            $this->entityManager->flush();
            $imported = count($events);
        } catch (SpondExportException $exception) {
            $output->writeln($this->redact($exception->getMessage(), $secrets));

            return Command::FAILURE;
        }

        $output->writeln('Spond gelesen und gespeichert.');
        $output->writeln('Gruppe: '.$read->groupName());
        $output->writeln('Mitglieder: '.count($read->people()));
        $output->writeln('Termine: '.count($read->events()));
        $earliest = $read->earliestOn();
        $latest = $read->latestOn();
        if ($earliest instanceof \DateTimeImmutable && $latest instanceof \DateTimeImmutable) {
            $output->writeln('Von: '.$earliest->format('Y-m-d'));
            $output->writeln('Bis: '.$latest->format('Y-m-d'));
        }
        $output->writeln('Zugesagt: '.$read->replyCount(SpondReply::Accepted));
        $output->writeln('Abgesagt: '.$read->replyCount(SpondReply::Declined));
        $output->writeln('Unbeantwortet: '.$read->replyCount(SpondReply::Unanswered));
        $output->writeln('Optionen: '.$read->optionCount());
        $output->writeln('Antwortkommentare: '.$read->answerCommentCount());
        $output->writeln('Spond-IDs gespeichert: '.$read->storedIdCount());
        $output->writeln('Zugeordnet: '.$read->matchedCount());
        $output->writeln('Im Kalender: '.$imported);

        return Command::SUCCESS;
    }

    /** @return array<string, string> */
    private function environmentValues(): array
    {
        $values = [];
        foreach (getenv() ?: [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }
        foreach (['SPOND_USERNAME', 'SPOND_PASSWORD', 'SPOND_PYTHON'] as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private function python(): string
    {
        $candidates = [];
        $configured = $this->environmentValues()['SPOND_PYTHON'] ?? '';
        if ($configured !== '') {
            $candidates[] = $configured;
        }
        $candidates[] = dirname($this->projectDir).'/.local/spond-venv/bin/python';
        $candidates[] = 'python3';
        foreach ($candidates as $candidate) {
            if ($candidate === 'python3' || (is_file($candidate) && is_executable($candidate))) {
                return $candidate;
            }
        }

        return 'python3';
    }

    /**
     * @param array<string, string> $env
     * @return array{code: int, stdout: string, stderr: string}|null
     */
    private function runPython(string $python, string $script, array $env): ?array
    {
        $pipes = [];
        $process = proc_open(
            [$python, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectDir,
            $env,
        );
        if (!is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        // Etwa 350 Termine werden einzeln gelesen. Zehn Minuten brechen den Lauf ab.
        $deadline = microtime(true) + 3600;
        $status = ['running' => true];
        while (microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }
        if ($status['running']) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return ['code' => 1, 'stdout' => '', 'stderr' => 'Spond-Leseabfrage fehlgeschlagen.'];
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param array{username: string, password: string} $secrets */
    private function redact(string $text, array $secrets): string
    {
        $needles = [];
        foreach ([$secrets['password'], $secrets['username']] as $secret) {
            if (strlen($secret) >= 3) {
                $needles[] = $secret;
            }
        }
        if ($needles === []) {
            return $text;
        }

        return str_replace($needles, '●', $text);
    }
}
