<?php

namespace Codeages\Biz\Framework\Scheduler\Service\Impl;

use Codeages\Biz\Framework\Scheduler\Checker\JobChecker;
use Codeages\Biz\Framework\Scheduler\Service\SchedulerService;
use Codeages\Biz\Framework\Service\BaseService;
use Codeages\Biz\Framework\Service\Exception\InvalidArgumentException;
use Codeages\Biz\Framework\Service\Exception\ServiceException;
use Codeages\Biz\Framework\Util\ArrayToolkit;
use Codeages\Biz\Framework\Util\Lock;
use Cron\CronExpression;

class SchedulerServiceImpl extends BaseService implements SchedulerService
{
    public function schedule($jobDetail)
    {
        if (empty($jobDetail['expression']) && empty($jobDetail['nextFireTime'])) {
            throw new InvalidArgumentException('args is invalid.');
        }

        if (!empty($jobDetail['expression']) && !CronExpression::isValidExpression($jobDetail['expression'])) {
            throw new InvalidArgumentException('cron expression is invalid.');
        }

        if (!empty($jobDetail['expression'])) {
            $jobDetail['nextFireTime'] = $this->getNextRunTime($jobDetail['expression']);
        }

        $default = array(
            'misfireThreshold' => 300,
            'misfirePolicy' => 'missed',
            'priority' => 100,
            'pool' => 'default',
            'source' => 'MAIN'
        );
        $jobDetail = array_merge($default, $jobDetail);

        $jobDetail = $this->getJobDetailDao()->create($jobDetail);
        $this->dispatch('job.created', $jobDetail);

        $jobFired['jobDetail'] = $jobDetail;

        $this->createJobLog($jobFired, 'created');

        return $jobDetail;
    }

    public function execute()
    {
        $this->updateWaitingJobsToAcquired();
        $jobFired = $this->triggerJob();
        if (empty($jobFired)) {
            return;
        }

        $jobInstance = $this->createJobInstance($jobFired);
        $result = $this->getJobPool()->execute($jobInstance);

        $this->jobExecuted($jobFired, $result);
    }

    public function deleteJobDetail($id)
    {
        $this->getJobDetailDao()->update($id, array(
            'deleted' => 1,
            'deletedTime' => time()
        ));
    }

    public function clearJobDetails()
    {
        $jobs = $this->getJobDetailDao()->search(array(
            'deleted' => 1,
            'lessThanDeletedTime' => time() - 24*60*60
        ), array(), 0, 100);

        foreach ($jobs as $job) {
            $this->getJobDetailDao()->delete($job['id']);
        }
    }

    public function deleteJobDetailByPoolAndName($pool, $name)
    {
        $jobDetail = $this->getJobDetailDao()->getByPoolAndName($pool, $name);
        $this->deleteJobDetail($jobDetail['id']);
    }

    protected function jobExecuted($jobFired, $result)
    {
        if ($result != 'success') {
            $this->createJobLog($jobFired, $result);
            $this->getJobFiredDao()->update($jobFired['id'], array(
                'firedTime' => time(),
                'status' => 'acquired'
            ));
            $this->createJobLog($jobFired, 'acquired');
        } else {
            $this->getJobFiredDao()->update($jobFired['id'], array(
                'status' => 'success'
            ));
            $this->createJobLog($jobFired, 'success');
        }

        $this->dispatch('job.executed', $jobFired, array('result' => $result));
    }

    protected function getNextRunTime($expression)
    {
        $cron = CronExpression::factory($expression);
        return strtotime($cron->getNextRunDate()->format('Y-m-d H:i:s'));
    }

    protected function triggerJob()
    {
        $lock = new Lock($this->biz);
        $lockName = 'scheduler.job.trigger';
        try {
            $lock->get($lockName, 20);
            $this->biz['db']->beginTransaction();

            $jobFired = $this->getAcquiredJob();

            $this->biz['db']->commit();
            $lock->release($lockName);

            return $jobFired;
        } catch (\Exception $e) {
            $this->biz['db']->rollback();
            $lock->release($lockName);
            throw new ServiceException($e);
        }
    }

    protected function getAcquiredJob()
    {
        $createdJobFired = $this->getJobFiredDao()->getByStatus('acquired');
        if (empty($createdJobFired)) {
            return;
        }

        $jobDetail = $this->getJobDetailDao()->get($createdJobFired['jobDetailId']);
        $createdJobFired['jobDetail'] = $jobDetail;
        $result =  $this->getCheckerChain()->check($createdJobFired);

        $jobFired = $this->getJobFiredDao()->update($createdJobFired['id'], array('status' => $result));

        $jobFired['jobDetail'] = $jobDetail;
        $this->updateNextFireTime($jobFired);

        $this->createJobLog($jobFired, $result);

        if ($result == JobChecker::EXECUTING) {
            $this->dispatch('job.executing', $jobFired);
            return $jobFired;
        }

        return $this->getAcquiredJob();
    }

    protected function updateNextFireTime($jobFired)
    {
        $jobDetail = $jobFired['jobDetail'];

        $nextFireTime = $jobDetail['nextFireTime'];
        if (!empty($jobDetail['expression'])) {
            $nextFireTime = $this->getNextRunTime($jobDetail['expression']);
        }

        $fields = array(
            'status' => 'waiting',
            'preFireTime' => $jobDetail['nextFireTime'],
            'nextFireTime' => $nextFireTime
        );

        $this->getJobDetailDao()->update($jobDetail['id'], $fields);
    }

    protected function updateWaitingJobsToAcquired()
    {
        $lock = new Lock($this->biz);
        $lockName = 'scheduler.job.acquire_jobs';

        try {
            $lock->get($lockName, 20);
            $this->biz['db']->beginTransaction();

            $jobDetails = $this->getJobDetailDao()->findWaitingJobsByLessThanFireTime(strtotime('+1 minutes'));

            foreach ($jobDetails as $jobDetail) {
                $this->updateJobToAcquired($jobDetail);
            }

            $this->biz['db']->commit();
            $lock->release($lockName);


        } catch (\Exception $e) {
            $this->biz['db']->rollback();
            $lock->release($lockName);
            throw new ServiceException($e);
        }
    }

    protected function updateJobToAcquired($jobDetail)
    {
        $jobDetail = $this->getJobDetailDao()->update($jobDetail['id'], array('status' => 'acquired'));

        $jobFired = array(
            'jobDetailId' => $jobDetail['id'],
            'firedTime' => $jobDetail['nextFireTime'],
            'status' => 'acquired'
        );
        $jobFired = $this->getJobFiredDao()->create($jobFired);
        $jobFired['jobDetail'] = $jobDetail;

        $this->dispatch('job.acquired', $jobFired);

        $this->createJobLog($jobFired, 'acquired');
    }

    protected function createJobLog($jobFired, $status)
    {
        $jobDetail = $jobFired['jobDetail'];
        $log = ArrayToolkit::parts($jobDetail, array(
            'name',
            'pool',
            'source',
            'class',
            'args',
            'priority',
            'status',
        ));

        if (!empty($jobFired['id'])) {
            $log['jobFiredId'] = $jobFired['id'];
        }
        $log['status'] = $status;
        $log['jobDetailId'] = $jobDetail['id'];
        $log['hostname'] = getHostName();

        $this->biz->service('Scheduler:JobLogService')->create($log);
    }

    protected function createJobInstance($jobFired)
    {
        $jobDetail = $jobFired['jobDetail'];
        $class = $jobFired['jobDetail']['class'];
        return new $class($jobDetail, $this->biz);
    }

    protected function getCheckerChain()
    {
        return $this->biz['scheduler.job.checker_chain'];
    }

    protected function getJobFiredDao()
    {
        return $this->biz->dao('Scheduler:JobFiredDao');
    }

    protected function getJobDetailDao()
    {
        return $this->biz->dao('Scheduler:JobDetailDao');
    }

    protected function getJobPool()
    {
        return $this->biz['scheduler.job.pool'];
    }
}