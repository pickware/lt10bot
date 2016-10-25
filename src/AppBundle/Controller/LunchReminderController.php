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
//        FIXME change tomorrow -> today once this works
        $dateString = (new DateTime('tomorrow'))->format('Y-m-d');
        $reservations = $this->getDoctrine()->getRepository('AppBundle:Reservation')->findBy([
            'menuDate' => $dateString
        ]);
        $logger->info("Sending reminders for reservations on ${dateString}.", ['reservations' => $reservations]);
        $telegramService = new TelegramService($logger);
        foreach ($reservations as $reservation) {
            $telegramService->sendLunchReminder($reservation);
        }
        return new Response(Response::HTTP_NO_CONTENT);
    }

    /**
     * Scrapes tomorrow's menu from the LT10 website and sends it to Telegram.
     * @param Logger $logger a logger to use
     * @param null|string $dateString a string represenation of the date to fetch the menu for
     */
    public static function fetchAndShowMenu($logger, $dateString = null)
    {
        if (!$dateString) {
            $dateString = 'today +1 Weekday'; // skip over weekends
        }
        $date = new DateTime($dateString);
        $lt10Service = new LT10Service($logger);
        $dishes = $lt10Service->getDishesForDate($date);

        $logger->info('Crawl result:', [
            'dishes' => $dishes
        ]);

        $telegramService = new TelegramService($logger);
        $telegramService->publishMenu($dishes, $date);
    }
}
