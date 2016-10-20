<?php

namespace AppBundle\Controller;

use AppBundle\Scraper\LT10Service;
use AppBundle\Telegram\TelegramService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

class CheckMenuController extends Controller
{
    /**
     * Scrapes tomorrow's menu from the LT10 website and sends it to Telegram.
     * This route should be called once a day at 18:00.
     *
     * TODO add security token
     * @Route("/checkmenu")
     */
    public function checkMenu()
    {
        static::fetchAndShowMenu($this->get('logger'));
        return new Response(Response::HTTP_NO_CONTENT);
    }

    public static function fetchAndShowMenu($logger) {
        $lt10service = new LT10Service($logger);
        $dishes = $lt10service->getDishesForTomorrow();

        $logger->info('Crawl result:', [
            'dishes' => $dishes
        ]);

        if (!empty($dishes)) {
            $telegramService = new TelegramService($logger);
            $telegramService->publishMenu($dishes);
        }
    }
}
