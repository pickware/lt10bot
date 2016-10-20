<?php

namespace AppBundle\Scraper;


use Exception;
use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class MenuScraper
 *
 * Scrapes tomorrow's dishes from the LT10 menu (http://kantine.lt10.de/menu).
 *
 * @package AppBundle\Scraper
 */
class MenuScraper
{
    const ENDPOINT = 'http://kantine.lt10.de/menu';
    const REQUESTED_DATE = 'morgen';
    private $client;
    private $userName;
    private $password;

    function __construct($logger)
    {
        $this->logger = $logger;
        $this->userName = getenv('LT10_USER');
        $this->password = getenv('LT10_PASSWORD');
        if (!$this->userName) {
            throw new Exception('LT10_USER must be set');
        }
        if (!$this->password) {
            throw new Exception('LT10_PASSWORD must be set');
        }
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
            'u' => $this->userName,
            'p' => $this->password
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
            ->each(function(Crawler $dish) {
                return $this->parseDish($dish);
            });
    }

    /**
     * Parse description, cost, cooks and additional tags from a dish (i.e. div.menu html element)
     * @param Crawler $dish
     * @return array|bool
     */
    private function parseDish(Crawler $dish)
    {
        $dishChildNodes = $dish->getNode(0)->childNodes;
        if ($dishChildNodes->length <= 2) {
            return false;
        }

        $result = [
            'description' => trim($dishChildNodes->item(1)->textContent),
            'tags' => []
        ];

        for ($i = 3; $i < $dishChildNodes->length - 1; $i++) {
            $text = trim($dishChildNodes->item($i)->textContent);
            if ($text) {
                if (mb_substr($text, 0, 1) === '€') {
                    $result['price'] = (float)mb_substr($text, 1);
                } elseif (mb_substr($text, 0, 3) === 'by ') {
                    $result['cook'] = mb_substr($text, 3);
                } else {
                    $result['tags'][] = trim($dishChildNodes->item($i)->textContent);
                }
            }
        }
        return $result;
    }
}