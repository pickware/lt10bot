<?php

namespace AppBundle\Controller;

use AppBundle\Scraper\MenuScraper;
use AppBundle\Telegram\MenuPublisher;
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
        $logger = $this->get('logger');
        $scraper = new MenuScraper($this->get('logger'));
        $dishes = $scraper->scrape();

        $logger->info('Crawl result:', [
            'dishes' => $dishes
        ]);

        if (!empty($dishes)) {
            $menuPublisher = new MenuPublisher($logger);
            $menuPublisher->publishMenu($dishes);
        }

        return new Response(Response::HTTP_NO_CONTENT);
    }
}
