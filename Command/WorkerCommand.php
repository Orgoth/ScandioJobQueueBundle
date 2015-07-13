<?php
namespace Scandio\JobQueueBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Filesystem\Filesystem;

class WorkerCommand extends ContainerAwareCommand
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    private $em;

    /**
     * @var array
     */
    private $locales;

    protected function configure()
    {
        $this
            ->setName('scandio:job-queue:worker')
            ->setDescription('Worker which checks for new jobs')
            ->addArgument('workerName', null, 'Worker Name to for multiple workers', 'default')
            ->addOption('maxJobs', null, InputArgument::OPTIONAL,'Maximum Number of Jobs to process', 0)
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $timeStart = microtime(true);
        $workerName = $input->getArgument('workerName');

        $em = $this->getContainer()->get('doctrine.orm.entity_manager');
        $em->getConfiguration()->setSQLLogger(null);
        $lockRepository = $em->getRepository('Scandio\JobQueueBundle\Entity\Lock');
        $jobRepository = $em->getRepository('Scandio\JobQueueBundle\Entity\Job');
        $maxCount = $input->getOption('maxJobs');

        $fl = new Filesystem();
        $_lock   = '/tmp/worker_lock_'.$workerName;
        $_unlock = '/tmp/worker_unlock_'.$workerName;
        if( !$fl->exists( $_lock ) && !$fl->exists( $_unlock ) ){
            touch( $_unlock );
        }

        $count = 0;

        $deadlockMessage = '';
        $isLocked = $fl->exists( $_lock );
        if ($isLocked && $lockRepository->isDead($workerName)) {
            $pid = $lockRepository->getPid($workerName);
            $fl->rename( $_lock, $_unlock );
            $deadlockMessage = "<error>$pid</error>";
        }


        if (!$isLocked && $maxCount > 0) {
            $fl->rename( $_unlock, $_lock );

            do {
                if ($maxCount > 0 && $count >= $maxCount) {
                    break;
                }
                $job = $jobRepository->getNextJob($workerName);

                if ($job instanceof \Scandio\JobQueueBundle\Entity\Job) {
                    $jobRepository->start($job);

                    $return = shell_exec($job->getCommand());
                    $jobRepository->finish($job, $return);
                }
                $count++;
            } while($job instanceof \Scandio\JobQueueBundle\Entity\Job);

            $fl->rename( $_lock, $_unlock );
        }

        $message  = date('Y-m-d H:i:s').';';
        $message .= '<info>'.round(microtime(true)-$timeStart, 2).'</info>'.';';
        $message .= $deadlockMessage;
        $output->writeln($message);
    }
}