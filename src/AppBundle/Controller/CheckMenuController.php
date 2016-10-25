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

class CheckMenuController extends Controller
{
    /**
     * Scrapes tomorrow's menu from the LT10 website and sends it to Telegram.
     * This route should be called once a day at 18:00.
     *
     * @Route("/checkmenu")
     */
    public function checkMenu(Request $request)
    {
        $logger = $this->get('logger');
        $dateString = $request->query->get('date') ?: null;
        $logger->info("date string passed in: ${dateString}.");
        static::fetchAndShowMenu($logger, $this->getDoctrine()->getRepository('AppBundle:Reservation'), $dateString);
        return new Response(Response::HTTP_NO_CONTENT);
    }

    /**
     * Scrapes tomorrow's menu from the LT10 website and sends it to Telegram.
     * @param Logger $logger a logger to use
     * @param null|string $dateString a string represenation of the date to fetch the menu for
     */
    public static function fetchAndShowMenu($logger, $reservationRepository, $dateString = null) {
        if (!$dateString) {
            $dateString = 'today +1 Weekday'; // skip over weekends
        }
        $date = new DateTime($dateString);
        $lt10Service = new LT10Service($logger);
        $dishes = $lt10Service->getDishesForDate($date);

        $logger->info('Crawl result:', [
            'dishes' => $dishes
        ]);

        $telegramService = new TelegramService($logger, $reservationRepository);
        $telegramService->publishMenu($dishes, $date);
    }
}
