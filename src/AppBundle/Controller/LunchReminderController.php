<?php

namespace AppBundle\Controller;

use DateTime;
use AppBundle\Scraper\LT10Service;
use AppBundle\Telegram\TelegramService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Date;

class LunchReminderController extends Controller
{
    /**
     * Reminds everybody who reserved lunch to actually go and eat it.
     *
     * @Route("/lunchreminder")
     */
    public function remindLunch()
    {
        $logger = $this->get('logger');
        $dateString = (new DateTime('today'))->format('Y-m-d');
        $reservations = $this->getDoctrine()->getRepository('AppBundle:Reservation')->findBy([
            'menuDate' => $dateString
        ]);
        $logger->info("Sending reminders for reservations on ${dateString}.", ['reservations' => $reservations]);
        $telegramService = new TelegramService($logger, $this->getDoctrine()->getRepository('AppBundle:Reservation'));
        foreach ($reservations as $reservation) {
            $telegramService->sendLunchReminder($reservation);
        }
        return new Response(Response::HTTP_NO_CONTENT);
    }
}
