<?php

namespace Magephi\Component;

use Error;
use Magephi\Entity\Environment;
use Magephi\Exception\EnvironmentException;
use Magephi\Exception\ProcessException;
use Psr\Log\LoggerInterface;

class DockerCompose
{
    private ProcessFactory $processFactory;

    private Environment $environment;

    private LoggerInterface $logger;

    public function __construct(ProcessFactory $processFactory, Environment $environment, LoggerInterface $logger)
    {
        $this->processFactory = $processFactory;
        $this->environment = $environment;
        $this->logger = $logger;
    }

    /**
     * Open a TTY terminal to the given container.
     *
     * @param string $container Container name
     * @param string $arguments
     *
     * @throws EnvironmentException
     * @throws ProcessException
     */
    public function openTerminal(string $container, string $arguments): void
    {
        if (!$this->isContainerUp($container)) {
            throw new EnvironmentException(sprintf('The container %s is not started.', $container));
        }
        if (!\Symfony\Component\Process\Process::isTtySupported()) {
            throw new ProcessException(
                "TTY is not supported, ensure you're running the application from the command line."
            );
        }
        $commands = ['docker-compose', 'exec'];
        if ($arguments !== '') {
            $commands = array_merge($commands, ['-u', $arguments]);
        }
        $this->processFactory->runInteractiveProcess(
            array_merge($commands, [$container, 'sh', '-l']),
            null,
            $this->environment->getDockerRequiredVariables()
        );
    }

    /**
     * Test if the given container is up or not.
     *
     * @param string $container
     *
     * @return bool
     */
    public function isContainerUp(string $container): bool
    {
        $command = "docker ps -q --no-trunc | grep $(docker-compose ps -q {$container})";
        $commands = explode(' ', $command);

        try {
            $process =
                $this->processFactory->runProcess(
                    $commands,
                    10,
                    $this->environment->getDockerRequiredVariables(),
                    true
                );
        } catch (Error $e) {
            $this->logger->error($e->getMessage());

            throw new EnvironmentException('Environment is not defined, install the environment first.');
        }

        return $process->getProcess()->isSuccessful() && !empty($process->getProcess()->getOutput());
    }

    /**
     * Execute a command in the specified container.
     *
     * @param string $container
     * @param string $command
     * @param bool   $createOnly If provided, return an instance of Process without execution
     *
     * @return Process
     */
    public function executeCommand(
        string $container,
        string $command,
        bool $createOnly = false
    ): Process {
        if (!$this->isContainerUp($container)) {
            throw new EnvironmentException(sprintf('The container %s is not started.', $container));
        }

        $arguments = [];
        if ($container === 'php') {
            $arguments = ['-u', 'www-data:www-data'];
        }

        $finalCommand =
            array_merge(
                ['docker-compose', 'exec'],
                $arguments,
                ['-T', $container, 'sh', '-c', sprintf('"%s"', escapeshellcmd($command))]
            );

        if ($createOnly) {
            /** @var Process $process */
            $process = $this->processFactory->createProcess(
                $finalCommand,
                600,
                $this->environment->getDockerRequiredVariables(),
                true
            );
        } else {
            $process = $this->processFactory->runProcess(
                $finalCommand,
                600,
                $this->environment->getDockerRequiredVariables(),
                true
            );
        }

        return $process;
    }

    /**
     * Restart the given container.
     *
     * @param string $container
     *
     * @return bool
     */
    public function restartContainer(string $container): bool
    {
        $process = $this->processFactory->runProcess(['docker-compose', 'restart', $container], 60, $this->environment->getDockerRequiredVariables());

        return $process->getProcess()->isSuccessful();
    }
}
