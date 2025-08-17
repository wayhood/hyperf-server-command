<?php
declare(strict_types=1);

namespace Wayhood\HyperfServiceCommand\Server;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Server\ServerFactory;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Runtime;
use Swoole\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Hyperf\Command\Annotation\Command as HyperfCommand;
use Hyperf\Support\Composer;
use function Hyperf\Support\swoole_hook_flags;

#[HyperfCommand]
class RestartServer extends Command
{
    private int $port;

    private string $address;

    public function __construct(private ContainerInterface $container)
    {
        parent::__construct('tmg:restart');
    }

    protected function configure()
    {
        $this->setDescription('Restart hyperf servers.')
            ->addOption('clear', 'c', InputOption::VALUE_OPTIONAL, 'clear runtime container', false)
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'bind port', 9501)
            ->addOption('address', 'a', InputOption::VALUE_OPTIONAL, 'bind address', '0.0.0.0');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (Composer::hasPackage('hyperf/polyfill-coroutine')) {
            $this->checkEnvironment($output);
        }

        $io = new SymfonyStyle($input, $output);

        $serverConfig = $this->container->get(ConfigInterface::class)->get('server', []);
        if (!$serverConfig) {
            throw new InvalidArgumentException('At least one server should be defined.');
        }

        $pidFile = $serverConfig['settings']['pid_file'];
        
        $pid = file_exists($pidFile) ? intval(file_get_contents($pidFile)) : false;
        if (!$pid) {
            $io->note('swoole server pid is invalid.');
            return -1;
        }

        if (!Process::kill($pid, SIG_DFL)) {
            $io->note('swoole server process does not exist.');
            return -1;
        }

        if (!Process::kill($pid, SIGTERM)) {
            $io->error('swoole server stop error.');
            return -1;
        }

        while (Process::kill($pid, SIG_DFL)) {
            sleep(1);
        }

        if ($input->getOption('clear') !== false) {
            exec('rm -rf ' . BASE_PATH . '/runtime/container');
        }

        $this->port = (int) $input->getOption('port');
        if ($this->port == 0) {
            $this->port = 9501;
        }

        $this->address = $input->getOption('address');


        $serverFactory = $this->container->get(ServerFactory::class)
            ->setEventDispatcher($this->container->get(EventDispatcherInterface::class))
            ->setLogger($this->container->get(StdoutLoggerInterface::class));

        $serverConfig['settings']['daemonize'] = 1;

        if ($this->port != 9501) {
            foreach($serverConfig['servers'] as $i => $server) {
                if ($server['name'] = 'http') {
                    $serverConfig['servers'][$i]['port'] = $this->port;
                    break;
                }
            }
        }

        if ($this->address != '0.0.0.0') {
            foreach($serverConfig['servers'] as $i => $server) {
                if ($server['name'] = 'http') {
                    $serverConfig['servers'][$i]['host'] = $this->address;
                    break;
                }
            }
        }

        $serverFactory->configure($serverConfig);

        Runtime::enableCoroutine(swoole_hook_flags());

        $serverFactory->start();
        $this->io->success('swoole server restart success.');
        return 0;
    }

    private function checkEnvironment(OutputInterface $output)
    {
        /**
         * swoole.use_shortname = true       => string(1) "1"     => enabled
         * swoole.use_shortname = "true"     => string(1) "1"     => enabled
         * swoole.use_shortname = on         => string(1) "1"     => enabled
         * swoole.use_shortname = On         => string(1) "1"     => enabled
         * swoole.use_shortname = "On"       => string(2) "On"    => enabled
         * swoole.use_shortname = "on"       => string(2) "on"    => enabled
         * swoole.use_shortname = 1          => string(1) "1"     => enabled
         * swoole.use_shortname = "1"        => string(1) "1"     => enabled
         * swoole.use_shortname = 2          => string(1) "1"     => enabled
         * swoole.use_shortname = false      => string(0) ""      => disabled
         * swoole.use_shortname = "false"    => string(5) "false" => disabled
         * swoole.use_shortname = off        => string(0) ""      => disabled
         * swoole.use_shortname = Off        => string(0) ""      => disabled
         * swoole.use_shortname = "off"      => string(3) "off"   => disabled
         * swoole.use_shortname = "Off"      => string(3) "Off"   => disabled
         * swoole.use_shortname = 0          => string(1) "0"     => disabled
         * swoole.use_shortname = "0"        => string(1) "0"     => disabled
         * swoole.use_shortname = 00         => string(2) "00"    => disabled
         * swoole.use_shortname = "00"       => string(2) "00"    => disabled
         * swoole.use_shortname = ""         => string(0) ""      => disabled
         * swoole.use_shortname = " "        => string(1) " "     => disabled.
         */
        $useShortname = ini_get_all('swoole')['swoole.use_shortname']['local_value'];
        $useShortname = strtolower(trim(str_replace('0', '', $useShortname)));
        if (!in_array($useShortname, ['', 'off', 'false'], true)) {
            $output->writeln('<error>ERROR</error> Swoole short name have to disable before start server, please set swoole.use_shortname = off into your php.ini.');
            exit(0);
        }
    }
}
