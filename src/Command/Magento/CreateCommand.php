<?php

namespace Magephi\Command\Magento;

use Magephi\Command\AbstractCommand;
use Magephi\Component\DockerCompose;
use Magephi\Component\ProcessFactory;
use Nadar\PhpComposerReader\ComposerReader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;

class CreateCommand extends AbstractMagentoCommand
{
    protected $command = 'create';

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ProcessFactory $processFactory,
        DockerCompose $dockerCompose,
        LoggerInterface $logger,
        string $name = null
    ) {
        parent::__construct($processFactory, $dockerCompose, $name);
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'enterprise',
                null,
                InputOption::VALUE_NONE,
                'Specify this option if you want to create a Magento Commerce project.'
            )
            ->addOption(
                'patch',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Specify this option if you want to install a version or specific patch. E.g: 2.3.3, 2.3.3-p1...'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!($currentDir = getcwd()) || !($scan = scandir($currentDir))) {
            throw new DirectoryNotFoundException('Your current directory is not readable', AbstractCommand::CODE_ERROR);
        }

        $projectName = basename($currentDir);
        if (\count($scan) > 2) {
            $question = new Question('Enter your project name');
            $question->setValidator(
                function ($answer) {
                    if (empty($answer)) {
                        throw new \RuntimeException(
                            'The name cannot be empty'
                        );
                    }

                    return $answer;
                }
            );
            $question->setMaxAttempts(2);

            $projectName = $this->interactive->askQuestion($question);

            try {
                mkdir($currentDir . '/' . $projectName);
                chdir($projectName);
            } catch (\ErrorException $e) {
                $this->interactive->error(
                    'A directory with that name already exist, try again with another name or try somewhere else.'
                );

                return AbstractCommand::CODE_ERROR;
            }
        }

        $package = $input->getOption(
            'enterprise'
        ) ? 'magento/project-enterprise-edition' : 'magento/project-community-edition';
        /** @var string $patch */
        $patch = $input->getOption('patch');
        if ($patch) {
            $package .= "={$patch}";
        }

        $this->processFactory->runInteractiveProcess(
            [
                'composer',
                'create-project',
                '--ignore-platform-reqs',
                '--repository=https://repo.magento.com/',
                $package,
                '.',
            ],
            900
        );
        $this->logger->info('Based project created');

        $composer = new ComposerReader('composer.json');
        if (!$composer->canRead()) {
            $this->logger->error('The composer.json cannot be read.');

            return AbstractCommand::CODE_ERROR;
        }

        $requireDev = [
            'bitexpert/phpstan-magento'   => 'dev-master',
            'emakinafr/docker-magento2'   => '^3.0',
            'friendsofphp/php-cs-fixer'   => '3.0.x-dev',
            'roave/security-advisories'   => 'dev-master',
            'sensiolabs/security-checker' => '^5.0',
        ];
        $composer->updateSection('require-dev', $requireDev);
        $composer->save();

        $this->processFactory->runProcess(
            ['composer', 'update', '--ignore-platform-reqs', '--optimize-autoloader'],
            90
        );

        $this->processFactory->runProcess(['composer', 'exec', 'docker-local-install']);

        $gitignore = <<<'EOD'
/app/*
!/app/code/
!/app/design/
!/app/etc
!/app/etc/config.php
/dev/*
/dev/tools/*
/dev/tools/grunt/*
/dev/tools/grunt/configs/*
!/dev/tools
!/dev/tools/grunt
!/dev/tools/grunt/configs
!/dev/tools/grunt/configs/babel.*
!/dev/tools/grunt/configs/local-themes.js
!/dev/tools/grunt/configs/postcss.*
!/docker/local
/.github
/.htaccess
/.htaccess.sample
/.magento.env.yaml.dist
/.php_cs.cache
/.php_cs.dist
/.travis.yml
/.travis.yml.sample
/.user.ini
/app/design/*/Magento
/app/etc/*
/auth.json.sample
/backup.tar
/bin/.htaccess
/bin/magento
/CHANGELOG.md
/COPYING.txt
/docker/*
/docker/local/.env
/generated/
/grunt-config.json.sample
/Gruntfile.js.sample
/index.php
/lib/
/LICENSE*
/nginx.conf.sample
/node_modules
/package.json.sample
/php.ini.sample
/phpserver/
/pub/
/SECURITY.md
/setup/
/update/*
/var/
/vendor/
!/yarn.lock
/yarn-error.log
EOD;

        file_put_contents('.gitignore', $gitignore);

        mkdir('app/code');
        fopen('app/code/.gitkeep', 'w');
        fopen('app/design/.gitkeep', 'w');
        fopen('app/etc/.gitkeep', 'w');

        $this->interactive->success('Your project has been created, you can now use the install command.');

        return AbstractCommand::CODE_SUCCESS;
    }
}
