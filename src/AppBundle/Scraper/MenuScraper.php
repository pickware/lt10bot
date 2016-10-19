<?php

namespace AppBundle\Scraper;


use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class MenuScraper
 *
 * Scrapes tomorrow's plates from the LT10 menu (http://kantine.lt10.de/menu).
 *
 * @package AppBundle\Scraper
 */
class MenuScraper
{
    const ENDPOINT = 'http://kantine.lt10.de/menu';
    const REQUESTED_DATE = 'morgen';

    function __construct($logger)
    {
        $this->logger = $logger;
    }

    /**
     * Do the scraping
     * @return array
     */
    function scrape()
    {
        $this->client = new Client();
        $this->login();
        return $this->parseDay();
    }

    /**
     * Login using the login form.
     */
    private function login()
    {
        $this->crawler = $this->client->request('GET', static::ENDPOINT);
        $loginButton = $this->crawler->selectButton('login');

        $loginForm = $loginButton->form();
        $loginForm->setValues([
            'u' => getenv('LT10_USER'),
            'p' => getenv('LT10_PASSWORD')
        ]);
        $this->crawler = $this->client->submit($loginForm);
    }

    /**
     * Finds and parses the correct day (i.e. tomorrow)
     * @return array
     */
    private function parseDay()
    {
        return $this->crawler
            ->filter('.day')
            ->reduce(function (Crawler $day) {
                $date = $day->filter('p.date')->text();
                return $date === static::REQUESTED_DATE;
            })
            ->filter('.menu')
            ->each(function(Crawler $plate) {
                return $this->parsePlate($plate);
            });
    }

    /**
     * Parse description, cost, cooks and additional tags from a plate (i.e. div.menu html element)
     * @param Crawler $plate
     * @return array|bool
     */
    private function parsePlate(Crawler $plate)
    {
        $plateChildNodes = $plate->getNode(0)->childNodes;
        if ($plateChildNodes->length <= 2) {
            return false;
        }

        $result = [
            'description' => trim($plateChildNodes->item(1)->textContent),
            'tags' => []
        ];

        for ($i = 3; $i < $plateChildNodes->length - 1; $i++) {
            $text = trim($plateChildNodes->item($i)->textContent);
            if ($text) {
                if (mb_substr($text, 0, 1) === '€') {
                    $result['price'] = (float)mb_substr($text, 1);
                } elseif (mb_substr($text, 0, 3) === 'by ') {
                    $result['cook'] = mb_substr($text, 3);
                } else {
                    $result['tags'][] = trim($plateChildNodes->item($i)->textContent);
                }
            }
        }
        return $result;
    }
}