<?php

namespace AppBundle\Controller;

use AppBundle\Scraper\MenuScraper;
use AppBundle\Telegram\MenuPublisher;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMenuController extends Controller
{
    /**
     * @Route("/checkmenu")
     */
    public function checkMenu(Request $request)
    {
        $logger = $this->get('logger');
        $scraper = new MenuScraper($this->get('logger'));
        $plates = $scraper->scrape();

        $logger->info('Crawl result:', [
            'plates' => $plates
        ]);

        $menuPublisher = new MenuPublisher();
        $menuPublisher->publishMenu($plates);

        return new Response(Response::HTTP_NO_CONTENT);
    }
}
