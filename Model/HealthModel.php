<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticHealthBundle\Model;

use Doctrine\ORM\EntityManager;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class HealthModel.
 */
class HealthModel
{
    /** @var EntityManager */
    protected $em;

    /** @var array */
    protected $campaigns;

    /** @var array */
    protected $incidents;

    /** @var IntegrationHelper */
    protected $integrationHelper;

    /** @var array */
    protected $settings;

    /** @var AbstractIntegration */
    protected $integration;

    /**
     * HealthModel constructor.
     *
     * @param EntityManager     $em
     * @param IntegrationHelper $integrationHelper
     */
    public function __construct(
        EntityManager $em,
        IntegrationHelper $integrationHelper
    ) {
        $this->em                = $em;
        $this->integrationHelper = $integrationHelper;

        /** @var \Mautic\PluginBundle\Integration\AbstractIntegration $integration */
        $integration = $this->integrationHelper->getIntegrationObject('Health');
        if ($integration) {
            $this->integration = $integration;
            $this->settings    = $integration->getIntegrationSettings()->getFeatureSettings();
        }
    }

    /**
     * @param $settings
     */
    public function setSettings($settings)
    {
        $this->settings = array_merge($this->settings, $settings);
    }

    /**
     * Discern the number of leads waiting on mautic:campaign:rebuild.
     * This typically means a large segment has been given a campaign.
     *
     * @param OutputInterface $output
     * @param bool            $verbose
     */
    public function campaignRebuildCheck(OutputInterface $output = null, $verbose = false)
    {
        $threshold = !empty($this->settings['campaign_rebuild_threshold']) ? (int) $this->settings['campaign_rebuild_threshold'] : 10000;
        $query     = $this->em->getConnection()->createQueryBuilder();
        $query->select('cl.campaign_id as campaign_id, count(DISTINCT(cl.lead_id)) as contact_count');
        $query->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl');
        $query->where('cl.manually_removed IS NOT NULL AND cl.manually_removed = 0');
        $query->andWhere(
            'NOT EXISTS (SELECT null FROM '.MAUTIC_TABLE_PREFIX.'campaign_lead_event_log e WHERE (cl.lead_id = e.lead_id) AND (e.campaign_id = cl.campaign_id))'
        );
        $query->groupBy('cl.campaign_id');
        $campaigns = $query->execute()->fetchAll();
        foreach ($campaigns as $campaign) {
            $id = $campaign['campaign_id'];
            if (!isset($this->campaigns[$id])) {
                $this->campaigns[$id] = [];
            }
            $this->campaigns[$id]['rebuilds'] = $campaign['contact_count'];
            if ($output) {
                if ($campaign['contact_count'] > $threshold) {
                    $this->incidents[$id]['rebuilds'] = $campaign['contact_count'];
                    $status                           = 'error';
                } else {
                    $status = 'info';
                    if (!$verbose) {
                        continue;
                    }
                }
                $output->writeln(
                    '<'.$status.'>'.
                    'Campaign '.$id.' has '.$campaign['contact_count'].' leads queued to enter the campaign from a segment.'
                    .'</'.$status.'>'
                );
            }
        }
    }

    /**
     * Discern the number of leads waiting on mautic:campaign:trigger.
     * This will happen if it takes longer to execute triggers than for new contacts to be consumed.
     *
     * @param OutputInterface $output
     * @param bool            $verbose
     */
    public function campaignTriggerCheck(OutputInterface $output = null, $verbose = false)
    {
        $threshold = !empty($this->settings['campaign_trigger_threshold']) ? (int) $this->settings['campaign_trigger_threshold'] : 1000;
        $query     = $this->em->getConnection()->createQueryBuilder();
        $query->select('el.campaign_id as campaign_id, COUNT(DISTINCT(el.lead_id)) as contact_count');
        $query->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'el');
        $query->where('el.is_scheduled = 1');
        $query->andWhere('el.trigger_date <= NOW()');
        $query->groupBy('el.campaign_id');
        $campaigns = $query->execute()->fetchAll();
        foreach ($campaigns as $campaign) {
            $id = $campaign['campaign_id'];
            if (!isset($this->campaigns[$id])) {
                $this->campaigns[$id] = [];
            }
            $this->campaigns[$id]['triggers'] = $campaign['contact_count'];
            if ($output) {
                if ($campaign['contact_count'] > $threshold) {
                    $this->incidents[$id]['triggers'] = $campaign['contact_count'];
                    $status                           = 'error';
                } else {
                    $status = 'info';
                    if (!$verbose) {
                        continue;
                    }
                }
                $output->writeln(
                    '<'.$status.'>'.
                    'Campaign '.$id.' has '.$campaign['contact_count'].' leads queued for events to be triggered.'
                    .'</'.$status.'>'
                );
            }
        }
    }

    /**
     * Gets all current incidents where we are over the limit.
     */
    public function getIncidents()
    {
        return $this->incidents;
    }

    /**
     * If Statuspage is enabled and configured, report incidents.
     */
    public function reportIncidents(OutputInterface $output = null)
    {
        if ($this->integration && $this->incidents && !empty($this->settings['statuspage_component_id'])) {
            $message = 'hi';
            $this->integration->setComponentStatus($this->settings['statuspage_component_id'], $message);

            if ($output && $message) {
                $output->writeln(
                    '<info>'.
                    'Notifying Statuspage.io: '.$message
                    .'</info>'
                );
            }

        }
    }
}