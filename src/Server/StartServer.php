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

//php bin/hyperf.php tmg:start -p 9501    //指定端口 默认查询name=http server port
//php bin/hyperf.php tmg:start -a 0.0.0.0 //监听地址 默认查找name=http server host
//php bin/hyperf.php tmg:start -d //启动服务并进入后台模式
//php bin/hyperf.php tmg:start -c //启动服务并清除 runtime/container 目录
//php bin/hyperf.php tmg:start -w //启动服务并监控 app、config目录以及 .env 变化自动重启
//php bin/hyperf.php tmg:start -w -i /bin/php //启动 watch 服务，参数 i 指定 php 安装目录
//php bin/hyperf.php tmg:start -w -t 10  //启动 watch 服务，参数 t 指定 watch 时间间隔，单位秒
//php bin/hyperf.php tmg:stop //停止服务
//php bin/hyperf.php tmg:restart //重启服务
//php bin/hyperf.php tmg:restart -c //重启服务并清除 runtime/container 目录

#[HyperfCommand]
class StartServer extends Command
{
    private SymfonyStyle $io;

    private int $interval;

    private bool $clear;

    private bool $daemonize;

    private string $interpreter;

    private int $port;

    private string $address;

    public function __construct(private ContainerInterface $container)
    {
        parent::__construct('tmg:start');
    }

    protected function configure()
    {
        $this
            ->setDescription('Start hyperf servers.')
            ->addOption('daemonize', 'd', InputOption::VALUE_OPTIONAL, 'swoole server daemonize', false)
            ->addOption('clear', 'c', InputOption::VALUE_OPTIONAL, 'clear runtime container', false)
            ->addOption('watch', 'w', InputOption::VALUE_OPTIONAL, 'watch swoole server', false)
            ->addOption('interval', 't', InputOption::VALUE_OPTIONAL, 'interval time ( 1-15 seconds)', 3)
            ->addOption('interpreter', 'i', InputOption::VALUE_OPTIONAL, 'which php interpreter path')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'bind port', 9501)
            ->addOption('address', 'a', InputOption::VALUE_OPTIONAL, 'bind address', '0.0.0.0');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);

        if (Composer::hasPackage('hyperf/polyfill-coroutine')) {
            $this->checkEnvironment($output);
        }

        $this->stopServer();

        $this->clear = ($input->getOption('clear') !== false);

        $this->port = (int) $input->getOption('port');
        if ($this->port == 0) {
            $this->port = 9501;
        }

        $this->address = $input->getOption('address');

        $this->daemonize = ($input->getOption('daemonize') !== false);

        if ($input->getOption('watch') !== false) {

            $this->interval = (int)$input->getOption('interval');
            if ($this->interval < 0 || $this->interval > 15) {
                $this->interval = 3;
            }
            if (!$this->interpreter = $input->getOption('interpreter')) {
                if (!$this->interpreter = exec('which php')) {
                    $this->interpreter = 'php';
                }
            }
            $this->watchServer();
        } else {
            if ($this->clear) {
                $this->clearRuntimeContainer();
            }

            $this->startServer();
        }
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


    private function clearRuntimeContainer()
    {
        exec('rm -rf ' . BASE_PATH . '/runtime/container');
    }

    private function startServer()
    {
        $serverFactory = $this->container->get(ServerFactory::class)
            ->setEventDispatcher($this->container->get(EventDispatcherInterface::class))
            ->setLogger($this->container->get(StdoutLoggerInterface::class));

        $serverConfig = $this->container->get(ConfigInterface::class)->get('server', []);
        if (!$serverConfig) {
            throw new InvalidArgumentException('At least one server should be defined.');
        }

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

        if ($this->daemonize) {
            $serverConfig['settings']['daemonize'] = 1;
            $this->io->success('swoole server start success.');
        }

        Runtime::enableCoroutine(swoole_hook_flags());

        $serverFactory->configure($serverConfig);

        $serverFactory->start();
    }

    private function stopServer()
    {
        $serverConfig = $this->container->get(ConfigInterface::class)->get('server', []);
        if (!$serverConfig) {
            throw new InvalidArgumentException('At least one server should be defined.');
        }
        $pidFile = $serverConfig['settings']['pid_file'];
        $pid = file_exists($pidFile) ? intval(file_get_contents($pidFile)) : false;
        if ($pid && Process::kill($pid, SIG_DFL)) {
            if (!Process::kill($pid, SIGTERM)) {
                $this->io->error('old swoole server stop error.');
                die();
            }

            while (Process::kill($pid, SIG_DFL)) {
                sleep(1);
            }
        } else {

        }
    }

    private function watchServer()
    {
        $this->io->note('start new swoole server ...');
        $pid = $this->startProcess();

        while ($pid > 0) {

            $this->watch();

            $this->io->note('restart swoole server ...');

            $this->stopProcess($pid);

            $pid = $this->startProcess();

            sleep(1);
        }
    }

    private function startProcess()
    {
        $this->clearRuntimeContainer();

        $process = new Process(function (Process $process) {
            $args = [BASE_PATH . '/bin/hyperf.php', 'start'];
            if ($this->daemonize) {
                $args[] = '-d';
            }
            $process->exec($this->interpreter, $args);
        });
        return $process->start();
    }

    private function stopProcess(int $pid): bool
    {
        $this->io->text('stop old swoole server. pid:' . $pid);

        $timeout = 15;
        $startTime = time();

        while (true) {
            $ret = Process::wait(false);
            if ($ret && $ret['pid'] == $pid) {
                return true;
            }
            if (!Process::kill($pid, SIG_DFL)) {
                return true;
            }
            if ((time() - $startTime) >= $timeout) {
                $this->io->error('stop old swoole server timeout.');
                return false;
            }
            Process::kill($pid, SIGTERM);
            sleep(1);
        }
        return false;
    }

    private function monitorDirs(bool $recursive = false)
    {
        $dirs[] = BASE_PATH . '/app';
        $dirs[] = BASE_PATH . '/config';

        if ($recursive) {
            foreach ($dirs as $dir) {
                $dirIterator = new \RecursiveDirectoryIterator($dir);
                $iterator = new \RecursiveIteratorIterator($dirIterator, \RecursiveIteratorIterator::SELF_FIRST);
                /** @var \SplFileInfo $file */
                foreach ($iterator as $file) {
                    if ($file->isDir() && $file->getFilename() != '.' && $file->getFilename() != '..') {
                        $dirs[] = $file->getPathname();
                    }
                }
            }
        }

        return $dirs;
    }

    private function monitorFiles()
    {
        $files[] = BASE_PATH . '/.env';
        return $files;
    }

    private function watch()
    {
        if (extension_loaded('inotify')) {
            return $this->inotifyWatch();
        } else {
            return $this->fileWatch();
        }
    }

    private function inotifyWatch()
    {
        $fd = inotify_init();
        stream_set_blocking($fd, 0);

        $dirs = $this->monitorDirs(true);
        foreach ($dirs as $dir) {
            inotify_add_watch($dir, IN_CLOSE_WRITE | IN_CREATE | IN_DELETE);
        }
        $files = $this->monitorFiles();
        foreach ($files as $file) {
            inotify_add_watch($file, IN_CLOSE_WRITE | IN_CREATE | IN_DELETE);
        }

        while (true) {
            sleep($this->interval);
            if (inotify_read($fd)) {
                break;
            }
        }

        fclose($fd);
    }

    private function fileWatch()
    {
        $dirs = $this->monitorDirs();
        $files = $this->monitorFiles();
        $inodeListOld = [];
        $inodeListNew = [];
        $isFrist = true;
        while (true) {
            foreach ($dirs as $dir) {
                $dirIterator = new \RecursiveDirectoryIterator($dir);
                $iterator = new \RecursiveIteratorIterator($dirIterator, \RecursiveIteratorIterator::LEAVES_ONLY);
                /** @var \SplFileInfo $file */
                foreach ($iterator as $file) {
                    if ($file->isFile() && in_array(strtolower($file->getExtension()), ['env', 'php'])) {
                        $inode = $file->getInode();
                        $sign = $file->getFilename() . $file->getMTime();
                        if ($isFrist) {
                            $inodeListOld[$inode] = $sign;
                        } else {
                            // add new file || file changed
                            if (!isset($inodeListOld[$inode]) || $inodeListOld[$inode] != $sign) {
                                return true;
                            } else {
                                $inodeListNew[$inode] = $sign;
                            }
                        }
                    }
                }
            }

            foreach ($files as $key => $file) {
                if (file_exists($file)) {
                    $file = new \SplFileInfo($file);
                    $inode = $file->getInode();
                    $sign = $file->getFilename() . $file->getMTime();
                    if ($isFrist) {
                        $inodeListOld[$inode] = $sign;
                    } else {
                        // add new file || file changed
                        if (!isset($inodeListOld[$inode]) || $inodeListOld[$inode] != $sign) {
                            return true;
                        } else {
                            $inodeListNew[$inode] = $sign;
                        }
                    }
                }
            }

            if ($isFrist) {
                $isFrist = false;
            } else {
                // file remove
                if (!empty(array_diff($inodeListOld, $inodeListNew))) {
                    return true;
                }
            }

            sleep($this->interval);
        }
    }
}
