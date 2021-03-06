<?php

namespace Pumukit\TimedPubDecisionsBundle\Controller;

use Pumukit\NewAdminBundle\Controller\NewAdminControllerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Pumukit\SchemaBundle\Document\MultimediaObject;

class BackofficeTimeframesController extends Controller implements NewAdminControllerInterface
{
    public static $tags = array('PUDERADIO', 'PUDETV');

    public static $colors = array(
        'PUDERADIO' => '#0000FF',
        'PUDETV' => '#50AA50',
    );

    /**
     * @Route("/admin/timeframes")
     * @Route("/admin/timeframes/default", name="pumukit_newadmin_timeframes_index_default")
     * @Template
     */
    public function indexAction(Request $request)
    {
        $session = $request->getSession();

        if ($request->query->has('status')) {
            if ('' === trim($request->query->get('status'))) {
                $session->remove('pumukit_timed_pub_decisions.status');
            } else {
                $session->set('pumukit_timed_pub_decisions.status', $request->query->get('status'));
            }
        }

        if ($request->query->has('tags')) {
            if ('' === trim($request->query->get('tags'))) {
                $session->remove('pumukit_timed_pub_decisions.tags');
            } else {
                $session->set('pumukit_timed_pub_decisions.tags', $request->query->get('tags'));
            }
        }

        return array(
            'colors' => self::$colors,
        );
    }

    /**
     * @Route("/admin/timeframes/series/timeline.xml", name="pumukit_newadmin_timeframes_xml")
     */
    public function seriesTimelineAction(Request $request)
    {
        $session = $request->getSession();

        $twoMonthsBefore = date('Y-m-d H:i:s', strtotime('-2 month'));
        $twoMonthsAfter = date('Y-m-d H:i:s', strtotime('+2 month'));
        $twoHoursBefore = date('Y-m-d H:i:s', strtotime('-2 hour'));
        $twoHoursAfter = date('Y-m-d H:i:s', strtotime('+2 hour'));

        $status = array(MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_BLOCKED, MultimediaObject::STATUS_HIDDEN);
        if ('0' == $session->get('pumukit_timed_pub_decisions.status')) {
            $status = array(MultimediaObject::STATUS_PUBLISHED);
        } elseif ('1' == $session->get('pumukit_timed_pub_decisions.status')) {
            $status = array(MultimediaObject::STATUS_BLOCKED, MultimediaObject::STATUS_HIDDEN);
        }

        if ($session->has('pumukit_timed_pub_decisions.tags')) {
            $targetTags = (array) $session->get('pumukit_timed_pub_decisions.tags');
        } else {
            $targetTags = self::$tags;
        }

        $qb = $this->get('doctrine_mongodb')
            ->getManager()
            ->getRepository('PumukitSchemaBundle:MultimediaObject')
            ->createQueryBuilder();

        $qb->field('status')->in($status)->field('tags.cod')->in($targetTags);

        if ('-1' != $session->get('pumukit_timed_pub_decisions.status')) {
            $qb->addAnd(
                $qb->expr()->field('tags.cod')->equals('PUCHWEBTV')
            )->field('tracks')->elemMatch(
                $qb->expr()->field('tags')->equals('display')->field('hide')->equals(false)
            );
        }
        $mms = $qb->getQuery()->execute();

        $XML = new \SimpleXMLElement('<data></data>');
        $XML->addAttribute('wiki-url', $request->getUri());
        $XML->addAttribute('wiki-section', 'Pumukit time-line Feed');

        foreach ($mms as $mm) {
            foreach ($targetTags as $tag) {
                if (!$mm->containsTagWithCod($tag)) {
                    continue;
                }

                $XMLMms = $XML->addChild('event', htmlspecialchars($mm->getTitle()));
                $XMLMms->addAttribute('durationEvent', 'true');

                if ($mm->getProperty('temporized_'.$tag)) {
                    $start = date('Y-m-d H:i:s', strtotime($mm->getProperty('temporized_from_'.$tag)));
                    $end = date('Y-m-d H:i:s', strtotime($mm->getProperty('temporized_to_'.$tag)));
                    $XMLMms->addAttribute('start', $start);
                    $XMLMms->addAttribute('end', $end);
                } else {
                    $XMLMms->addAttribute('start', $twoMonthsBefore);
                    $XMLMms->addAttribute('end', $twoMonthsAfter);
                    $XMLMms->addAttribute('latestStart', $twoHoursBefore);
                    $XMLMms->addAttribute('earliestEnd', $twoHoursAfter);
                }

                $XMLMms->addAttribute('color', isset(self::$colors[$tag]) ? self::$colors[$tag] : '#666666');
                $XMLMms->addAttribute('textColor', '#000000');
                $XMLMms->addAttribute('title', $mm->getTitle());
                $XMLMms->addAttribute('link', $this->get('router')->generate('pumukitnewadmin_mms_shortener', array('id' => $mm->getId()), true));
            }
        }

        return new Response($XML->asXML(), 200, array('Content-Type' => 'text/xml'));
    }
}
