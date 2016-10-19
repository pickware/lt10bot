<?php

namespace AppBundle\Scraper;


use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

class MenuScraper
{
    const ENDPOINT = 'http://kantine.lt10.de/menu';
    const REQUESTED_DATE = 'morgen';

    function __construct($logger)
    {
        $this->logger = $logger;
    }

    function scrape()
    {

        $client = new Client();
        $crawler = $client->request('GET', static::ENDPOINT);

        $loginButton = $crawler->selectButton('login');
        $needsLogin = $loginButton->count() !== 0;

        if ($needsLogin) {
            $loginForm = $loginButton->form();
            $loginForm->setValues([
                'u' => getenv('LT10_USER'),
                'p' => getenv('LT10_PASSWORD')
            ]);
            $menuCrawler = $client->submit($loginForm);
            $this->logger->info('logged in.');
        } else {
            $this->logger->info('using previous session');
            $menuCrawler = $crawler;
        }

        $self = $this;

        return $menuCrawler
            ->filter('.day')
            ->reduce(function (Crawler $day) use (&$self) {
                $date = $day->filter('p.date')->text();
                return $date === static::REQUESTED_DATE;
            })
            ->filter('.menu')
            ->each(function (Crawler $plate, $plateIndex) use (&$self) {
                $plateChildNodes = $plate->getNode(0)->childNodes;
                if ($plateChildNodes->length <= 2) {
                    return false;
                }
                $plateResult = [
                    'description' => trim($plateChildNodes->item(1)->textContent),
                    'tags' => []
                ];

                $tags = [];
                for ($i = 3; $i < $plateChildNodes->length - 1; $i++) {
                    $text = trim($plateChildNodes->item($i)->textContent);
                    if ($text) {
                        $first = mb_substr($text, 0, 1);
                        if (mb_substr($text, 0, 1) === '€') {
                            $plateResult['price'] = (float)mb_substr($text, 1);
                        } elseif (mb_substr($text, 0, 3) === 'by ') {
                            $plateResult['cook'] = mb_substr($text, 3);
                        } else {
                            $plateResult['tags'][] = trim($plateChildNodes->item($i)->textContent);
                        }
                    }
                }
                return $plateResult;
            });
    }

}