<?php
/**
 * This file is part of Phiremock.
 *
 * Phiremock is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Phiremock is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Phiremock.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Mcustiel\Phiremock\Server\Cli\Commands;

use ErrorException;
use Exception;
use Mcustiel\Phiremock\Server\Factory\Factory;
use Mcustiel\Phiremock\Server\Http\ServerInterface;
use Mcustiel\Phiremock\Server\Utils\Config\Config;
use Mcustiel\Phiremock\Server\Utils\Config\ConfigBuilder;
use Mcustiel\Phiremock\Server\Utils\Config\Directory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PhiremockServerCommand extends Command
{
    const IP_HELP_MESSAGE = 'IP address of the interface where Phiremock must list for connections.';
    const PORT_HELP_MESSAGE = 'Port where Phiremock must list for connections.';
    const EXPECTATIONS_DIR_HELP_MESSAGE = 'Directory in which to search for expectation definition files.';
    const DEBUG_HELP_MESSAGE = 'Sets debug mode.';
    const CONFIG_PATH_HELP_MESSAGE = 'Directory in which to search for configuration files. Default: current directory.';
    const FACTORY_CLASS_HELP_MESSAGE = 'Factory class to use. It must inherit from: ' . Factory::class;
    const CERTIFICATE_HELP_MESSAGE = 'Path to the local certificate for secure connection';
    const CERTIFICATE_KEY_HELP_MESSAGE = 'Path to the local certificate key for secure connection';
    const PASSPHRASE_HELP_MESSAGE = 'Passphrase if the local certificate is encrypted';

    /** @var Factory */
    private $factory;
    /** @var LoggerInterface */
    private $logger;
    /** @var ServerInterface */
    private $httpServer;

    public function __construct()
    {
        parent::__construct('run');
    }

    protected function configure(): void
    {
        $this->setDescription('Runs Phiremock server')
            ->setHelp('This is the main command to run Phiremock as a HTTP server.')
            ->addOption(
                'ip',
                'i',
                InputOption::VALUE_REQUIRED,
                self::IP_HELP_MESSAGE
            )
            ->addOption(
                'port',
                'p',
                InputOption::VALUE_REQUIRED,
                self::PORT_HELP_MESSAGE
            )
            ->addOption(
                'expectations-dir',
                'e',
                InputOption::VALUE_REQUIRED,
                self::EXPECTATIONS_DIR_HELP_MESSAGE
            )
            ->addOption(
                'debug',
                'd',
                InputOption::VALUE_NONE,
                self::DEBUG_HELP_MESSAGE
            )
            ->addOption(
                'config-path',
                'c',
                InputOption::VALUE_REQUIRED,
                self::CONFIG_PATH_HELP_MESSAGE
            )
            ->addOption(
                'factory-class',
                'f',
                InputOption::VALUE_REQUIRED,
                self::FACTORY_CLASS_HELP_MESSAGE
            )
            ->addOption(
                'certificate',
                't',
                InputOption::VALUE_REQUIRED,
                self::CERTIFICATE_HELP_MESSAGE
            )
            ->addOption(
                'certificate-key',
                'k',
                InputOption::VALUE_REQUIRED,
                self::CERTIFICATE_KEY_HELP_MESSAGE
            )
            ->addOption(
                'cert-passphrase',
                's',
                InputOption::VALUE_REQUIRED,
                self::PASSPHRASE_HELP_MESSAGE
            );
    }

    /** @throws Exception */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->createPhiremockPathIfNotExists();

        $configPath = $this->getConfigPath($input);
        $cliConfig = $this->getCommandLineOptions($input);

        $config = (new ConfigBuilder($configPath))->build($cliConfig);

        $this->factory = $config->getFactoryClassName()->asInstance($config);
        $this->initializeLogger($config);
        $this->processFileExpectations($config);
        $this->startHttpServer($config);

        return 0;
    }

    protected function getConfigPath(InputInterface $input): ?Directory
    {
        $configPath = null;
        $configPathOptionValue = $input->getOption('config-path');
        if ($configPathOptionValue) {
            $configPath = new Directory($configPathOptionValue);
        }

        return $configPath;
    }

    /** @throws Exception */
    private function createPhiremockPathIfNotExists(): void
    {
        $defaultExpectationsPath = ConfigBuilder::getDefaultExpectationsDir();
        if (!$defaultExpectationsPath->exists()) {
            $defaultExpectationsPath->create();
        } elseif (!$defaultExpectationsPath->isDirectory()) {
            throw new Exception('Expectations path must be a directory');
        }
    }

    /** @throws Exception */
    private function startHttpServer(Config $config): void
    {
        $this->httpServer = $this->factory->createHttpServer();
        $this->setUpHandlers();
        $this->httpServer->listen($config->getInterfaceIp(), $config->getPort(), $config->getSecureOptions());
    }

    private function initializeLogger(Config $config): void
    {
        $this->logger = $this->factory->createLogger();
        $this->logger->info(
            sprintf(
                '[%s] Starting Phiremock%s...',
                date('Y-m-d H:i:s'),
                ($config->isDebugMode() ? ' in debug mode' : '')
            )
        );
    }

    /** @throws Exception */
    private function processFileExpectations(Config $config): void
    {
        $expectationsDir = $config->getExpectationsPath()->asString();
        $this->logger->debug(
            sprintf(
                'Phiremock\'s expectation dir is set to: %s',
                $this->factory->createFileSystemService()->getRealPath($expectationsDir))
        );
        $this->factory
            ->createFileExpectationsLoader()
            ->loadExpectationsFromDirectory($expectationsDir);
    }

    /** @throws ErrorException */
    private function setUpHandlers(): void
    {
        $handleTermination = function () {
            $this->logger->info('Stopping Phiremock...');
            $this->httpServer->shutdown();
            $this->logger->info('Bye bye');
        };

        $this->logger->debug('Registering shutdown function');
        register_shutdown_function($handleTermination);

        if (\function_exists('pcntl_signal')) {
            $this->logger->debug('PCNTL present: Installing signal handlers');
            pcntl_signal(\SIGTERM, function () { exit(0); });
        }

        $errorHandler = function ($severity, $message, $file, $line) {
            $errorInformation = sprintf('%s:%s (%s)', $file, $line, $message);
            if ($this->isError($severity)) {
                $this->logger->error($errorInformation);
                throw new ErrorException($message, 0, $severity, $file, $line);
            }
            $this->logger->warning($errorInformation);

            return false;
        };
        set_error_handler($errorHandler);
    }

    private function isError(int $severity): bool
    {
        return \in_array(
            $severity,
            [
                \E_COMPILE_ERROR,
                \E_CORE_ERROR,
                \E_USER_ERROR,
                \E_PARSE,
                \E_ERROR,
            ],
            true
        );
    }

    /** @return string[] */
    private function getCommandLineOptions(InputInterface $input): array
    {
        $cliConfig = [];
        foreach (Config::CONFIG_OPTIONS as $configOption) {
            $optionValue = $input->getOption($configOption);
            if ($optionValue) {
                $cliConfig[$configOption] = (string) $optionValue;
            }
        }

        return $cliConfig;
    }
}
